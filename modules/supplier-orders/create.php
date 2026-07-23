<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('supplier-orders.manage');

$appTitle = 'New Supplier Order';
$error = '';
$pdo = app_db();

$form = [
    'supplier_id' => '',
    'purchase_number' => 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
    'notes' => '',
    'shipping_fee' => '0.00',
    'payment_status' => 'unpaid',
];
$existingItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));
    $form['purchase_number'] = trim((string) ($_POST['purchase_number'] ?? ''));
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));
    $form['shipping_fee'] = trim((string) ($_POST['shipping_fee'] ?? ''));
    $form['payment_status'] = in_array($_POST['payment_status'] ?? '', SUPPLIER_ORDER_PAYMENT_STATUSES, true) ? $_POST['payment_status'] : 'unpaid';

    $postedUnitKeys = $_POST['unit_key'] ?? [];
    $postedQuantities = $_POST['quantity'] ?? [];
    $postedPrices = $_POST['supplier_price'] ?? [];

    $sellableUnits = catalog_sellable_units($pdo);
    $unitsByKey = array_column($sellableUnits, null, 'key');

    $validItems = [];

    for ($i = 0; $i < count($postedUnitKeys); $i++) {
        $rowUnitKey = trim((string) ($postedUnitKeys[$i] ?? ''));
        $rowQuantity = trim((string) ($postedQuantities[$i] ?? ''));
        $rowPrice = trim((string) ($postedPrices[$i] ?? ''));

        if ($rowUnitKey === '') {
            continue;
        }

        $existingItems[] = [
            'unit_key' => $rowUnitKey,
            'label' => isset($unitsByKey[$rowUnitKey]) ? $unitsByKey[$rowUnitKey]['label'] : $rowUnitKey,
            'sku' => isset($unitsByKey[$rowUnitKey]) ? $unitsByKey[$rowUnitKey]['sku'] : '',
            'moq' => isset($unitsByKey[$rowUnitKey]) ? $unitsByKey[$rowUnitKey]['moq'] : null,
            'quantity' => $rowQuantity,
            'supplier_price' => $rowPrice,
        ];

        if ($error === '') {
            if (!ctype_digit($rowQuantity) || (int) $rowQuantity < 1) {
                $error = 'Enter a whole number quantity of at least 1 for every line.';
            } elseif ($rowPrice !== '' && (!is_numeric($rowPrice) || (float) $rowPrice < 0)) {
                $error = 'Unit cost must be a valid non-negative number.';
            } elseif (!isset($unitsByKey[$rowUnitKey])) {
                $error = 'A selected product no longer exists.';
            } else {
                $unit = $unitsByKey[$rowUnitKey];
                $validItems[] = [
                    'product_id' => $unit['product_id'],
                    'variation_id' => $unit['variation_id'],
                    'quantity' => (int) $rowQuantity,
                    'supplier_price' => $rowPrice !== '' ? round((float) $rowPrice, 2) : 0.00,
                ];
            }
        }
    }

    if ($error === '' && ($form['supplier_id'] === '' || (int) $form['supplier_id'] < 1)) {
        $error = 'Select a supplier.';
    }

    $supplierId = (int) $form['supplier_id'];
    if ($error === '') {
        $supplierCheck = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id = ?');
        $supplierCheck->execute([$supplierId]);
        if ((int) $supplierCheck->fetchColumn() === 0) {
            $error = 'Selected supplier does not exist.';
        }
    }

    if ($error === '') {
        if ($form['purchase_number'] === '' || strlen($form['purchase_number']) > 100) {
            $error = 'Purchase number is required and must be 100 characters or fewer.';
        }
    }

    if ($error === '') {
        $poCheck = $pdo->prepare('SELECT COUNT(*) FROM supplier_orders WHERE purchase_number = ?');
        $poCheck->execute([$form['purchase_number']]);
        if ((int) $poCheck->fetchColumn() > 0) {
            $error = 'Purchase number already exists.';
        }
    }

    if ($error === '' && $validItems === []) {
        $error = 'Add at least one product with a quantity.';
    }

    $shippingFee = 0.00;
    if ($error === '') {
        if ($form['shipping_fee'] !== '' && (!is_numeric($form['shipping_fee']) || (float) $form['shipping_fee'] < 0)) {
            $error = 'Shipping fee must be a valid non-negative number.';
        } else {
            $shippingFee = $form['shipping_fee'] !== '' ? round((float) $form['shipping_fee'], 2) : 0.00;
        }
    }

    if ($error === '') {
        $estimatedCost = 0.00;
        foreach ($validItems as $line) {
            $estimatedCost += $line['quantity'] * $line['supplier_price'];
        }

        $pdo->beginTransaction();

        try {
            $orderStmt = $pdo->prepare("
                INSERT INTO supplier_orders (supplier_id, purchase_number, status, payment_status, estimated_cost, shipping_fee, order_date, notes)
                VALUES (?, ?, 'draft', ?, ?, ?, CURDATE(), ?)
            ");
            $orderStmt->execute([$supplierId, $form['purchase_number'], $form['payment_status'], round($estimatedCost, 2), $shippingFee, $form['notes'] !== '' ? $form['notes'] : null]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO supplier_order_items (supplier_order_id, product_id, variation_id, total_quantity, supplier_price, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)
            ');

            foreach ($validItems as $line) {
                $subtotal = round($line['quantity'] * $line['supplier_price'], 2);
                $itemStmt->execute([$orderId, $line['product_id'], $line['variation_id'], $line['quantity'], $line['supplier_price'], $subtotal]);
                $itemId = (int) $pdo->lastInsertId();

                supplier_order_mark_incoming($pdo, $line['product_id'], $itemId, $line['quantity'], $line['variation_id']);
            }

            $pdo->commit();

            app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&created=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create supplier order.';
        }
    }
}

$suppliersStmt = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200');
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$pickerSuppliers = $suppliers;
$pickerCategories = catalog_list_categories_tree($pdo);
$pickerProducts = supplier_order_picker_products($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">New Supplier Order</h2>
        <p class="text-muted mb-0">Create a purchase order and mark the ordered stock as incoming.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/index.php">Back to Supplier Orders</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label">Supplier</label>
                <select class="form-select" name="supplier_id" required>
                    <option value="">Select a supplier&hellip;</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Purchase Number</label>
                <input type="text" class="form-control" name="purchase_number" value="<?php echo app_escape($form['purchase_number']); ?>" maxlength="100" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Shipping Fee (RM)</label>
                <input type="number" step="0.01" min="0" class="form-control" id="supplier-order-shipping-fee" name="shipping_fee" value="<?php echo app_escape($form['shipping_fee']); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Payment Status</label>
                <select class="form-select" name="payment_status">
                    <?php foreach (SUPPLIER_ORDER_PAYMENT_STATUSES as $statusValue): ?>
                        <option value="<?php echo app_escape($statusValue); ?>" <?php echo $form['payment_status'] === $statusValue ? 'selected' : ''; ?>>
                            <?php echo app_escape(supplier_order_payment_status_label($statusValue)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"><?php echo app_escape($form['notes']); ?></textarea>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Products</h5>
            <button type="button" class="btn btn-primary btn-sm" id="add-product-btn">+ Add Product</button>
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="supplier-order-items-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU / Variation</th>
                        <th>MOQ</th>
                        <th>Quantity</th>
                        <th>Unit Cost (RM)</th>
                        <th>Subtotal (RM)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Product Subtotal</td>
                        <td class="fw-semibold" id="supplier-order-product-subtotal">0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Total Purchase Amount</td>
                        <td class="fw-semibold" id="supplier-order-total">0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <button class="btn btn-primary mt-3" type="submit">Create Supplier Order</button>
    </form>
</div>

<?php require __DIR__ . '/_item_picker_modal.php'; ?>

<script id="supplier-order-form-data" type="application/json"><?php echo json_encode([
    'products' => $pickerProducts,
    'existingItems' => $existingItems,
]); ?></script>
<?php
$supplierOrderFormJsPath = __DIR__ . '/../../assets/js/supplier-order-form.js';
$supplierOrderFormJsVersion = is_file($supplierOrderFormJsPath) ? filemtime($supplierOrderFormJsPath) : time();
?>
<script src="/assets/js/supplier-order-form.js?v=<?php echo (int) $supplierOrderFormJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
