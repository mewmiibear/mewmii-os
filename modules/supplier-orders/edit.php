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

// Editing is scoped to Draft orders only - once submitted (status = ordered) a PO has
// been sent to the supplier, and once anything has actually been received, the ordered
// quantities it drove into incoming_quantity can no longer be silently rewritten (see
// supplier_order_has_receiving_history()). This is deliberately the stricter of the two
// checks - not just "no receiving yet" - since re-opening an already-submitted PO for
// silent edits could contradict what the supplier was actually told.
if ($order['status'] !== 'draft') {
    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&edit_blocked=1');
}

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

    $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));

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

    if ($error === '' && supplier_order_has_receiving_history($pdo, $orderId)) {
        // Re-checked at save time too, not just on page load - in case receiving happened
        // in another tab/session between opening this form and submitting it.
        $error = 'This order has already received inventory and can no longer be edited.';
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            // Reverse every existing line's incoming contribution, then delete the lines -
            // safe because we've just confirmed nothing has ever been received against this
            // order (supplier_order_has_receiving_history() above).
            foreach ($existingDbItems as $oldItem) {
                supplier_order_reverse_incoming(
                    $pdo,
                    (int) $oldItem['product_id'],
                    $oldItem['variation_id'] !== null ? (int) $oldItem['variation_id'] : null,
                    (int) $oldItem['id'],
                    (int) $oldItem['total_quantity']
                );
            }
            $pdo->prepare('DELETE FROM supplier_order_items WHERE supplier_order_id = ?')->execute([$orderId]);

            $estimatedCost = 0.00;
            foreach ($validItems as $line) {
                $estimatedCost += $line['quantity'] * $line['supplier_price'];
            }

            $pdo->prepare('UPDATE supplier_orders SET supplier_id = ?, estimated_cost = ?, notes = ? WHERE id = ?')
                ->execute([$supplierId, round($estimatedCost, 2), $form['notes'] !== '' ? $form['notes'] : null, $orderId]);

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
        <p class="text-muted mb-0">Only Draft orders can be edited - once submitted, use Cancel/replace instead.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/view.php?id=<?php echo (int) $orderId; ?>">Back to Order</a>
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
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
