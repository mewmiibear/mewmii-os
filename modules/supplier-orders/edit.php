<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('supplier-orders.manage');

$appTitle = 'Edit Supplier Order';
$error = '';
$pdo = app_db();

$orderId = (int) ($_GET['id'] ?? 0);

if ($orderId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$orderStmt = $pdo->prepare('SELECT * FROM supplier_orders WHERE id = ? LIMIT 1');
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Completed locks products/quantities/cost entirely (notes only) - every other status
// stays editable, since receiving-history safety is enforced per-line inside
// supplier_order_apply_edit() (a line with received quantity can't be removed or reduced
// below what's already received), not by blocking the whole order.
$isCompleted = $order['status'] === 'completed';
$showSentWarning = !$isCompleted && $order['status'] !== 'draft';

$itemsStmt = $pdo->prepare('
    SELECT soi.id, soi.product_id, soi.variation_id, soi.total_quantity, soi.supplier_price,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name
    FROM supplier_order_items soi
    INNER JOIN products p ON p.id = soi.product_id
    LEFT JOIN product_variations pv ON pv.id = soi.variation_id
    WHERE soi.supplier_order_id = ?
    ORDER BY soi.id ASC
');
$itemsStmt->execute([$orderId]);
$existingDbItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$existingItems = array_map(static function (array $item) use ($pdo): array {
    $variationLabel = $item['variation_id'] !== null ? variation_build_label($pdo, (int) $item['variation_id']) : '';

    return [
        'unit_key' => $item['product_id'] . ':' . (int) ($item['variation_id'] ?? 0),
        'label' => $item['product_name'] . ($variationLabel !== '' ? (' - ' . $variationLabel) : ''),
        'sku' => $item['sku'],
        'quantity' => (string) (int) $item['total_quantity'],
        'supplier_price' => (string) $item['supplier_price'],
        'received_quantity' => supplier_order_item_received_quantity($pdo, (int) $item['id']),
    ];
}, $existingDbItems);

$form = [
    'supplier_id' => (string) $order['supplier_id'],
    'purchase_number' => $order['purchase_number'],
    'notes' => (string) ($order['notes'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));

    if ($isCompleted) {
        // Completed: notes only, nothing else can be posted from this form at all.
        if ($error === '') {
            $pdo->prepare('UPDATE supplier_orders SET notes = ? WHERE id = ?')
                ->execute([$form['notes'] !== '' ? $form['notes'] : null, $orderId]);

            app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
        }
    } else {
        $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));

        $postedUnitKeys = $_POST['unit_key'] ?? [];
        $postedQuantities = $_POST['quantity'] ?? [];
        $postedPrices = $_POST['supplier_price'] ?? [];

        $sellableUnits = catalog_sellable_units($pdo);
        $unitsByKey = array_column($sellableUnits, null, 'key');

        $validItems = [];
        $existingItems = [];

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

        if ($error === '' && $validItems === []) {
            $error = 'Add at least one product with a quantity.';
        }

        if ($error === '') {
            $pdo->beginTransaction();

            try {
                supplier_order_apply_edit($pdo, $orderId, $supplierId, $form['notes'], $validItems);

                $pdo->commit();

                app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to update supplier order.';
            }
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
        <h2 class="mb-1">Edit Supplier Order <?php echo app_escape($order['purchase_number']); ?></h2>
        <p class="text-muted mb-0"><?php echo supplier_order_status_badge($order['status']); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/view.php?id=<?php echo (int) $orderId; ?>">Back to Order</a>
</div>

<?php if ($showSentWarning): ?>
    <div class="alert alert-warning">This supplier order has already been sent. Changes may require supplier confirmation.</div>
<?php endif; ?>
<?php if ($isCompleted): ?>
    <div class="alert alert-info">This order is Completed - products, quantities, and cost are locked. Only notes can be edited.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if ($isCompleted): ?>
    <div class="card p-4">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="3"><?php echo app_escape($form['notes']); ?></textarea>
            <button class="btn btn-primary mt-3" type="submit">Save Notes</button>
        </form>
    </div>
<?php else: ?>
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
                    <input type="text" class="form-control" value="<?php echo app_escape($form['purchase_number']); ?>" readonly disabled>
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
            <p class="text-muted small">A line that already has received quantity can be increased but not removed or reduced below what's already been received.</p>
            <div class="table-responsive">
                <table class="table align-middle" id="supplier-order-items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Ordered Quantity</th>
                            <th>Unit Cost (RM)</th>
                            <th>Subtotal (RM)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end fw-semibold">Total</td>
                            <td class="fw-semibold" id="supplier-order-total">0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <button class="btn btn-primary mt-3" type="submit">Save Changes</button>
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
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
