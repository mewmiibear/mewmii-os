<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('orders.manage');

$appTitle = 'Edit Order';
$error = '';
$pdo = app_db();

$orderId = (int) ($_GET['id'] ?? 0);

if ($orderId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$orderStmt = $pdo->prepare('SELECT * FROM mewmii_orders WHERE id = ? LIMIT 1');
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Shipped/Completed/Cancelled: notes only - product/quantity/price changes must go through
// the adjustment workflow instead (see ORDER_EDITABLE_STATUSES's doc comment in
// includes/orders.php). Safety for lines already allocated to Customer Storage is enforced
// per-line inside order_apply_edit(), not by blocking the whole order.
$isEditable = in_array($order['order_status'], ORDER_EDITABLE_STATUSES, true);

$itemsStmt = $pdo->prepare('
    SELECT oi.id, oi.product_id, oi.variation_id, oi.quantity, oi.selling_price, oi.discount,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name
    FROM mewmii_order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    LEFT JOIN product_variations pv ON pv.id = oi.variation_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
');
$itemsStmt->execute([$orderId]);
$existingDbItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$existingItems = array_map(static function (array $item) use ($pdo): array {
    $variationLabel = $item['variation_id'] !== null ? variation_build_label($pdo, (int) $item['variation_id']) : '';

    return [
        'unit_key' => $item['product_id'] . ':' . (int) ($item['variation_id'] ?? 0),
        'label' => $item['product_name'] . ($variationLabel !== '' ? (' - ' . $variationLabel) : ''),
        'sku' => $item['sku'],
        'quantity' => (string) (int) $item['quantity'],
        'unit_price' => (string) $item['selling_price'],
        'discount' => (string) $item['discount'],
        'allocated_quantity' => supplier_order_item_customer_storage_allocated($pdo, (int) $item['id']),
    ];
}, $existingDbItems);

$form = [
    'customer_id' => (string) $order['customer_id'],
    'order_number' => $order['order_number'],
    'shipping_fee' => (string) $order['shipping_fee'],
    'customer_note' => (string) ($order['customer_note'] ?? ''),
    'internal_note' => (string) ($order['internal_note'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['customer_note'] = trim((string) ($_POST['customer_note'] ?? ''));
    $form['internal_note'] = trim((string) ($_POST['internal_note'] ?? ''));

    if (!$isEditable) {
        // Locked: notes only, nothing else can be posted from this form at all.
        if ($error === '') {
            $pdo->prepare('UPDATE mewmii_orders SET customer_note = ?, internal_note = ? WHERE id = ?')
                ->execute([$form['customer_note'] !== '' ? $form['customer_note'] : null, $form['internal_note'] !== '' ? $form['internal_note'] : null, $orderId]);

            app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
        }
    } else {
        $form['customer_id'] = trim((string) ($_POST['customer_id'] ?? ''));
        $form['shipping_fee'] = trim((string) ($_POST['shipping_fee'] ?? ''));

        $postedUnitKeys = $_POST['unit_key'] ?? [];
        $postedQuantities = $_POST['quantity'] ?? [];
        $postedPrices = $_POST['unit_price'] ?? [];
        $postedDiscounts = $_POST['discount'] ?? [];

        $sellableUnits = catalog_sellable_units($pdo);
        $unitsByKey = array_column($sellableUnits, null, 'key');

        $validItems = [];
        $existingItems = [];

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

        $shippingFee = 0.00;
        if ($error === '') {
            if ($form['shipping_fee'] !== '' && (!is_numeric($form['shipping_fee']) || (float) $form['shipping_fee'] < 0)) {
                $error = 'Shipping fee must be a valid non-negative number.';
            } else {
                $shippingFee = $form['shipping_fee'] !== '' ? round((float) $form['shipping_fee'], 2) : 0.00;
            }
        }

        if ($error === '' && $validItems === []) {
            $error = 'Add at least one product with a quantity.';
        }

        if ($error === '') {
            $pdo->beginTransaction();

            try {
                order_apply_edit($pdo, $orderId, $customerId, $validItems, $shippingFee, $form['customer_note'], $form['internal_note']);

                $pdo->commit();

                app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to update order.';
            }
        }
    }
}

$customersStmt = $pdo->query('SELECT id, name, email FROM customers ORDER BY name ASC LIMIT 200');
$allCustomers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$pickerCategories = catalog_list_categories_tree($pdo);
$pickerBrands = $pdo->query('SELECT id, name FROM brands ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$pickerSuppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
$pickerProducts = order_picker_products($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Edit Order <?php echo app_escape($order['order_number']); ?></h2>
        <p class="text-muted mb-0"><?php echo order_status_badge($order['order_status']); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/orders/view.php?id=<?php echo (int) $orderId; ?>">Back to Order</a>
</div>

<?php if (!$isEditable): ?>
    <div class="alert alert-info">This order is <?php echo app_escape(order_status_label($order['order_status'])); ?> - products, quantities, and pricing are locked. Only notes can be edited. Use the adjustment workflow for further changes.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<?php if (!$isEditable): ?>
    <div class="card p-4">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <div class="mb-3">
                <label class="form-label">Customer Note</label>
                <textarea class="form-control" name="customer_note" rows="3" placeholder="Visible to the customer."><?php echo app_escape($form['customer_note']); ?></textarea>
            </div>
            <div class="mb-0">
                <label class="form-label">Internal Note</label>
                <textarea class="form-control" name="internal_note" rows="3" placeholder="Admin/staff only."><?php echo app_escape($form['internal_note']); ?></textarea>
            </div>
            <button class="btn btn-primary mt-3" type="submit">Save Notes</button>
        </form>
    </div>
<?php else: ?>
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
                    <input type="text" class="form-control" value="<?php echo app_escape($form['order_number']); ?>" readonly disabled>
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
            <p class="text-muted small">A line already allocated to Customer Storage can be increased but not removed or reduced below what's already allocated.</p>
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

            <button class="btn btn-primary mt-3" type="submit">Save Changes</button>
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
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
