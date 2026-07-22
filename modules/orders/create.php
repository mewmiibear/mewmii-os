<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('orders.manage');

$appTitle = 'New Order';
$error = '';
$pdo = app_db();

const ORDER_ITEM_ROWS = 6;

$form = [
    'customer_id' => '',
    'order_number' => 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
];
$itemRows = array_fill(0, ORDER_ITEM_ROWS, ['unit_key' => '', 'quantity' => '']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['customer_id'] = trim((string) ($_POST['customer_id'] ?? ''));
    $form['order_number'] = trim((string) ($_POST['order_number'] ?? ''));

    $postedUnitKeys = $_POST['unit_key'] ?? [];
    $postedQuantities = $_POST['quantity'] ?? [];

    $sellableUnits = catalog_sellable_units($pdo);
    $unitsByKey = array_column($sellableUnits, null, 'key');

    $itemRows = [];
    $validItems = [];

    for ($i = 0; $i < ORDER_ITEM_ROWS; $i++) {
        $rowUnitKey = trim((string) ($postedUnitKeys[$i] ?? ''));
        $rowQuantity = trim((string) ($postedQuantities[$i] ?? ''));

        $itemRows[] = ['unit_key' => $rowUnitKey, 'quantity' => $rowQuantity];

        if ($rowUnitKey === '' && $rowQuantity === '') {
            continue;
        }

        if ($error === '') {
            if ($rowUnitKey === '') {
                $error = 'Row ' . ($i + 1) . ': select a product.';
            } elseif (!ctype_digit($rowQuantity) || (int) $rowQuantity < 1) {
                $error = 'Row ' . ($i + 1) . ': quantity must be a whole number of at least 1.';
            } elseif (!isset($unitsByKey[$rowUnitKey])) {
                $error = 'Row ' . ($i + 1) . ': selected product does not exist.';
            } else {
                $validItems[] = [
                    'unit' => $unitsByKey[$rowUnitKey],
                    'quantity' => (int) $rowQuantity,
                ];
            }
        }
    }

    if ($error === '' && ($form['customer_id'] === '' || (int) $form['customer_id'] < 1)) {
        $error = 'Select a customer.';
    }

    $customerId = (int) $form['customer_id'];
    if ($error === '') {
        $customerCheck = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE id = ?');
        $customerCheck->execute([$customerId]);
        if ((int) $customerCheck->fetchColumn() === 0) {
            $error = 'Selected customer does not exist.';
        }
    }

    if ($error === '') {
        if ($form['order_number'] === '' || strlen($form['order_number']) > 100) {
            $error = 'Order number is required and must be 100 characters or fewer.';
        }
    }

    if ($error === '') {
        $orderNumberCheck = $pdo->prepare('SELECT COUNT(*) FROM mewmii_orders WHERE order_number = ?');
        $orderNumberCheck->execute([$form['order_number']]);
        if ((int) $orderNumberCheck->fetchColumn() > 0) {
            $error = 'Order number already exists.';
        }
    }

    if ($error === '' && $validItems === []) {
        $error = 'Add at least one product line with a quantity.';
    }

    // Check stock availability per sellable unit (aggregated across rows) before committing.
    // preorder/early_bird units are purchasable regardless of available_quantity - they're
    // gated by status/closing date instead (see catalog_product_is_orderable()).
    if ($error === '') {
        $demand = [];
        foreach ($validItems as $line) {
            $key = $line['unit']['key'];
            $demand[$key] = ($demand[$key] ?? 0) + $line['quantity'];
        }

        foreach ($demand as $key => $neededQty) {
            $unit = $unitsByKey[$key];
            $override = $unit['availability_override'] ?? 'auto';

            // Priority: manual override first (authoritative), then lifecycle state
            // (Preorder/Early Bird), then stock quantity (Ready Stock) - see
            // catalog_product_availability_status(). Never re-derive this ordering here.
            if ($override === 'out_of_stock') {
                $error = $unit['label'] . ' has been manually marked unavailable.';
                break;
            }

            if (in_array($unit['product_type'], ['preorder', 'early_bird'], true)) {
                if ($override !== 'available' && !catalog_product_is_orderable($unit)) {
                    $error = $unit['label'] . ' is not currently open for preorder.';
                    break;
                }
                continue;
            }

            if ($override === 'available') {
                // Ready Stock forced available by an admin - skip the quantity check.
                continue;
            }

            $invStmt = $pdo->prepare('SELECT available_quantity FROM mewmii_inventory WHERE product_id = ? AND variation_id <=> ?');
            $invStmt->execute([$unit['product_id'], $unit['variation_id']]);
            $available = (int) $invStmt->fetchColumn();

            if ($available < $neededQty) {
                $error = catalog_format_stock_error($pdo, 'Insufficient available stock.', $unit['product_id'], $unit['variation_id'], 'Available quantity', $available, $neededQty);
                break;
            }
        }
    }

    if ($error === '') {
        $subtotal = 0.00;
        foreach ($validItems as $line) {
            $subtotal += $line['quantity'] * $line['unit']['selling_price'];
        }
        $subtotal = round($subtotal, 2);

        $pdo->beginTransaction();

        try {
            $orderStmt = $pdo->prepare('
                INSERT INTO mewmii_orders (order_number, customer_id, subtotal, discount, shipping_fee, total_amount, order_date)
                VALUES (?, ?, ?, 0.00, 0.00, ?, CURDATE())
            ');
            $orderStmt->execute([$form['order_number'], $customerId, $subtotal, $subtotal]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO mewmii_order_items (order_id, product_id, variation_id, variation_label, quantity, selling_price, cost_snapshot)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            foreach ($validItems as $line) {
                $unit = $line['unit'];
                $variationLabel = $unit['variation_id'] !== null ? variation_build_label($pdo, $unit['variation_id']) : null;

                $itemStmt->execute([
                    $orderId,
                    $unit['product_id'],
                    $unit['variation_id'],
                    $variationLabel,
                    $line['quantity'],
                    $unit['selling_price'],
                    $unit['cost_price'],
                ]);
            }

            $pdo->commit();

            app_redirect('/modules/orders/view.php?id=' . $orderId . '&created=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create order.';
        }
    }
}

$customersStmt = $pdo->query('SELECT id, name, email FROM customers ORDER BY name ASC LIMIT 200');
$allCustomers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$sellableUnits = catalog_sellable_units($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">New Order</h2>
        <p class="text-muted mb-0">Create a customer order and check stock availability before saving.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/orders/index.php">Back to Orders</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label">Customer</label>
                <select class="form-select" name="customer_id" required>
                    <option value="">Select a customer&hellip;</option>
                    <?php foreach ($allCustomers as $customer): ?>
                        <option value="<?php echo (int) $customer['id']; ?>" <?php echo $form['customer_id'] === (string) $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($customer['name']); ?><?php if (!empty($customer['email'])): ?> (<?php echo app_escape($customer['email']); ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Order Number</label>
                <input type="text" class="form-control" name="order_number" value="<?php echo app_escape($form['order_number']); ?>" maxlength="100" required>
            </div>
        </div>

        <h5 class="mb-3">Products</h5>
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itemRows as $row): ?>
                    <tr>
                        <td>
                            <select class="form-select" name="unit_key[]">
                                <option value="">Select a product&hellip;</option>
                                <?php foreach ($sellableUnits as $unit): ?>
                                    <?php
                                    $lifecycleEmojis = ['early_bird' => '🟧', 'preorder' => '🟪', 'ready_stock' => '🟩', 'waiting_release' => '⚪', 'closed' => '🔴'];
                                    $unitEmoji = $lifecycleEmojis[catalog_product_lifecycle_stage($unit)] ?? '';
                                    ?>
                                    <option value="<?php echo app_escape($unit['key']); ?>" <?php echo $row['unit_key'] === $unit['key'] ? 'selected' : ''; ?>>
                                        <?php echo app_escape($unitEmoji); ?> <?php echo app_escape($unit['sku']); ?> &mdash; <?php echo app_escape($unit['label']); ?> (<?php echo app_escape((string) $unit['selling_price']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" class="form-control" name="quantity[]" min="1" value="<?php echo app_escape($row['quantity']); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted small">Prices are taken automatically from each product's current selling price.</p>

        <button class="btn btn-primary mt-3" type="submit">Create Order</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
