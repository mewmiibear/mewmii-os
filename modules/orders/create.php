<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('orders.manage');

$appTitle = 'New Order';
$error = '';
// Set alongside $error only for the ready-stock insufficient-availability case below, so the
// template can offer a Purchase Planning link for that exact product - display/navigation
// only, the stock check itself is unchanged.
$insufficientStockUnit = null;
$pdo = app_db();

$form = [
    'customer_id' => '',
    'order_number' => 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
    'shipping_fee' => '0.00',
    'customer_note' => '',
    'internal_note' => '',
];
$existingItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['customer_id'] = trim((string) ($_POST['customer_id'] ?? ''));
    $form['order_number'] = trim((string) ($_POST['order_number'] ?? ''));
    $form['shipping_fee'] = trim((string) ($_POST['shipping_fee'] ?? ''));
    $form['customer_note'] = trim((string) ($_POST['customer_note'] ?? ''));
    $form['internal_note'] = trim((string) ($_POST['internal_note'] ?? ''));

    $postedUnitKeys = $_POST['unit_key'] ?? [];
    $postedQuantities = $_POST['quantity'] ?? [];
    $postedPrices = $_POST['unit_price'] ?? [];
    $postedDiscounts = $_POST['discount'] ?? [];

    $sellableUnits = catalog_sellable_units($pdo);
    $unitsByKey = array_column($sellableUnits, null, 'key');

    $validItems = [];

    for ($i = 0; $i < count($postedUnitKeys); $i++) {
        $rowUnitKey = trim((string) ($postedUnitKeys[$i] ?? ''));
        $rowQuantity = trim((string) ($postedQuantities[$i] ?? ''));
        $rowPrice = trim((string) ($postedPrices[$i] ?? ''));
        $rowDiscount = trim((string) ($postedDiscounts[$i] ?? ''));

        if ($rowUnitKey === '') {
            continue;
        }

        $existingItems[] = [
            'unit_key' => $rowUnitKey,
            'label' => isset($unitsByKey[$rowUnitKey]) ? $unitsByKey[$rowUnitKey]['label'] : $rowUnitKey,
            'sku' => isset($unitsByKey[$rowUnitKey]) ? $unitsByKey[$rowUnitKey]['sku'] : '',
            'quantity' => $rowQuantity,
            'unit_price' => $rowPrice,
            'discount' => $rowDiscount,
            'allocated_quantity' => 0,
        ];

        if ($error === '') {
            if (!ctype_digit($rowQuantity) || (int) $rowQuantity < 1) {
                $error = 'Enter a whole number quantity of at least 1 for every line.';
            } elseif ($rowPrice !== '' && (!is_numeric($rowPrice) || (float) $rowPrice < 0)) {
                $error = 'Unit price must be a valid non-negative number.';
            } elseif ($rowDiscount !== '' && (!is_numeric($rowDiscount) || (float) $rowDiscount < 0)) {
                $error = 'Discount must be a valid non-negative number.';
            } elseif (!isset($unitsByKey[$rowUnitKey])) {
                $error = 'A selected product no longer exists.';
            } else {
                $unit = $unitsByKey[$rowUnitKey];
                $unit['selling_price'] = $rowPrice !== '' ? round((float) $rowPrice, 2) : (float) $unit['selling_price'];

                $validItems[] = [
                    'unit' => $unit,
                    'quantity' => (int) $rowQuantity,
                    'discount' => $rowDiscount !== '' ? round((float) $rowDiscount, 2) : 0.00,
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

    $shippingFee = 0.00;
    if ($error === '') {
        if ($form['shipping_fee'] !== '' && (!is_numeric($form['shipping_fee']) || (float) $form['shipping_fee'] < 0)) {
            $error = 'Shipping fee must be a valid non-negative number.';
        } else {
            $shippingFee = $form['shipping_fee'] !== '' ? round((float) $form['shipping_fee'], 2) : 0.00;
        }
    }

    if ($error === '' && $validItems === []) {
        $error = 'Add at least one product line with a quantity.';
    }

    // Stock availability check (aggregated across rows) before committing - same override ->
    // lifecycle -> quantity priority as order_unit_is_available()/catalog_product_availability_status().
    if ($error === '') {
        $demand = [];
        foreach ($validItems as $line) {
            $key = $line['unit']['key'];
            $demand[$key] = ($demand[$key] ?? 0) + $line['quantity'];
        }

        foreach ($demand as $key => $neededQty) {
            $unit = $unitsByKey[$key];
            $override = $unit['availability_override'] ?? 'auto';

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
                continue;
            }

            $invStmt = $pdo->prepare('SELECT available_quantity FROM mewmii_inventory WHERE product_id = ? AND variation_id <=> ?');
            $invStmt->execute([$unit['product_id'], $unit['variation_id']]);
            $available = (int) $invStmt->fetchColumn();

            if ($available < $neededQty) {
                $error = catalog_format_stock_error($pdo, 'Insufficient available stock.', $unit['product_id'], $unit['variation_id'], 'Available quantity', $available, $neededQty);
                $insufficientStockUnit = $unit;
                break;
            }
        }
    }

    if ($error === '') {
        $subtotal = 0.00;
        $discountTotal = 0.00;
        foreach ($validItems as $line) {
            $subtotal += $line['quantity'] * $line['unit']['selling_price'];
            $discountTotal += $line['discount'];
        }
        $subtotal = round($subtotal, 2);
        $discountTotal = round($discountTotal, 2);
        $totalAmount = round($subtotal - $discountTotal + $shippingFee, 2);

        $pdo->beginTransaction();

        try {
            $orderStmt = $pdo->prepare('
                INSERT INTO mewmii_orders (order_number, customer_id, subtotal, discount, shipping_fee, total_amount, customer_note, internal_note, order_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
            ');
            $orderStmt->execute([$form['order_number'], $customerId, $subtotal, $discountTotal, $shippingFee, $totalAmount, $form['customer_note'] !== '' ? $form['customer_note'] : null, $form['internal_note'] !== '' ? $form['internal_note'] : null]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO mewmii_order_items (order_id, product_id, variation_id, variation_label, quantity, selling_price, discount, subtotal, cost_snapshot)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            foreach ($validItems as $line) {
                $unit = $line['unit'];
                $variationLabel = $unit['variation_id'] !== null ? variation_build_label($pdo, $unit['variation_id']) : null;
                $lineSubtotal = round(($line['quantity'] * $unit['selling_price']) - $line['discount'], 2);

                $itemStmt->execute([
                    $orderId,
                    $unit['product_id'],
                    $unit['variation_id'],
                    $variationLabel,
                    $line['quantity'],
                    $unit['selling_price'],
                    $line['discount'],
                    $lineSubtotal,
                    $unit['cost_price'],
                ]);
            }

            inventory_reserve_for_order($pdo, $orderId);

            $pdo->commit();

            app_redirect('/modules/orders/view.php?id=' . $orderId . '&created=1');
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create order.';
        }
    }
}

$customersStmt = $pdo->query('SELECT id, name, email FROM customers ORDER BY name ASC LIMIT 200');
$allCustomers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$pickerCategories = catalog_list_categories_tree($pdo);
$pickerBrands = $pdo->query('SELECT id, name FROM brands ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$pickerSuppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
$pickerProducts = order_picker_products($pdo);
$canViewPurchasePlanning = app_has_permission('supplier-orders.manage');

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
    <div class="alert alert-danger">
        <?php echo nl2br(app_escape($error)); ?>
        <?php if ($insufficientStockUnit !== null && $canViewPurchasePlanning): ?>
            <div class="mt-2">
                <a class="btn btn-sm btn-outline-danger" href="/modules/purchase-planning/generate.php#need-<?php echo app_escape(str_replace(':', '-', $insufficientStockUnit['key'])); ?>">
                    Check Purchase Planning for <?php echo app_escape($insufficientStockUnit['label']); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label">Customer</label>
                <div class="d-flex gap-2">
                    <select class="form-select" id="customer-select" name="customer_id" required>
                        <option value="">Select a customer&hellip;</option>
                        <?php foreach ($allCustomers as $customer): ?>
                            <option value="<?php echo (int) $customer['id']; ?>" <?php echo $form['customer_id'] === (string) $customer['id'] ? 'selected' : ''; ?>>
                                <?php echo app_escape($customer['name']); ?><?php if (!empty($customer['email'])): ?> (<?php echo app_escape($customer['email']); ?>)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-secondary text-nowrap" id="add-customer-btn">+ New Customer</button>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Order Number</label>
                <input type="text" class="form-control" name="order_number" value="<?php echo app_escape($form['order_number']); ?>" maxlength="100" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Shipping Fee (RM)</label>
                <input type="number" step="0.01" min="0" class="form-control" id="order-shipping-fee" name="shipping_fee" value="<?php echo app_escape($form['shipping_fee']); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Customer Note</label>
                <textarea class="form-control" name="customer_note" rows="2" placeholder="Visible to the customer."><?php echo app_escape($form['customer_note']); ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Internal Note</label>
                <textarea class="form-control" name="internal_note" rows="2" placeholder="e.g. Customer requested combine shipment. Admin/staff only."><?php echo app_escape($form['internal_note']); ?></textarea>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Products</h5>
            <button type="button" class="btn btn-primary btn-sm" id="add-product-btn">+ Add Product</button>
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="order-items-table">
                <thead>
                    <tr>
                        <th>Product / Variation</th>
                        <th>SKU</th>
                        <th>Quantity</th>
                        <th>Unit Price (RM)</th>
                        <th>Discount (RM)</th>
                        <th>Subtotal (RM)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Items Subtotal</td>
                        <td class="fw-semibold" id="order-items-subtotal">0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Total (incl. Shipping)</td>
                        <td class="fw-semibold" id="order-grand-total">0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <button class="btn btn-primary mt-3" type="submit">Create Order</button>
    </form>
</div>

<?php require __DIR__ . '/_item_picker_modal.php'; ?>

<script id="order-form-data" type="application/json"><?php echo json_encode([
    'products' => $pickerProducts,
    'existingItems' => $existingItems,
    'csrfToken' => app_csrf_token(),
    'urls' => ['createCustomer' => '/modules/customers/ajax/create_customer.php'],
]); ?></script>
<?php
$orderFormJsPath = __DIR__ . '/../../assets/js/order-form.js';
$orderFormJsVersion = is_file($orderFormJsPath) ? filemtime($orderFormJsPath) : time();
?>
<script src="/assets/js/order-form.js?v=<?php echo (int) $orderFormJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
