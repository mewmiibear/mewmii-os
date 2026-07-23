<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/csv_import.php';
require_once __DIR__ . '/../../includes/supplier_order_import.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('supplier-orders.manage');

/**
 * CSV import for historical Supplier Orders + Order Items in one file - same one-row-per-
 * item / rows grouped by purchase_number convention as modules/orders/import.php, and the
 * same all-or-nothing validation shape.
 */

$appTitle = 'Import Historical Supplier Orders';
$error = '';
$rowErrors = [];
$importedOrders = 0;

$statusOptions = array_merge(SUPPLIER_ORDER_WORKFLOW, ['partially_received', 'cancelled']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK)) {
        $error = 'Choose a CSV file to upload.';
    }

    if ($error === '') {
        $pdo = app_db();

        try {
            $parsed = csv_import_read_rows($_FILES['csv_file']['tmp_name']);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        if ($error === '') {
            $rows = $parsed['rows'];
            if ($rows === []) {
                $error = 'The CSV file has no data rows.';
            }
        }

        if ($error === '') {
            $sellableUnits = catalog_sellable_units($pdo);
            $unitsBySku = [];
            foreach ($sellableUnits as $unit) {
                $unitsBySku[strtoupper($unit['sku'])] = $unit;
            }

            $existingPurchaseNumbersStmt = $pdo->query('SELECT UPPER(purchase_number) FROM supplier_orders');
            $existingPurchaseNumbers = array_flip($existingPurchaseNumbersStmt->fetchAll(PDO::FETCH_COLUMN));

            $groups = [];
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $purchaseNumber = trim((string) ($row['purchase_number'] ?? ''));
                if ($purchaseNumber === '') {
                    $rowErrors[] = "Row {$rowNum}: purchase_number is required.";
                    continue;
                }
                $groups[$purchaseNumber]['rows'][] = ['row_num' => $rowNum, 'data' => $row];
            }

            foreach ($groups as $purchaseNumber => $group) {
                if (isset($existingPurchaseNumbers[strtoupper($purchaseNumber)])) {
                    $rowErrors[] = "Order {$purchaseNumber}: this purchase number already exists.";
                    continue;
                }

                $first = $group['rows'][0]['data'];
                $supplierName = trim((string) ($first['supplier_name'] ?? ''));
                $supplierId = null;
                if ($supplierName !== '') {
                    $supStmt = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(name) = LOWER(?) LIMIT 1');
                    $supStmt->execute([$supplierName]);
                    $supplierId = $supStmt->fetchColumn() ?: null;
                }
                if ($supplierId === null) {
                    $rowErrors[] = "Order {$purchaseNumber}: no matching supplier found for supplier_name \"{$supplierName}\" - import the supplier first.";
                }

                $orderDate = trim((string) ($first['order_date'] ?? ''));
                if ($orderDate === '' || !DateTime::createFromFormat('Y-m-d', $orderDate)) {
                    $rowErrors[] = "Order {$purchaseNumber}: order_date is required and must be YYYY-MM-DD.";
                }

                $status = trim((string) ($first['status'] ?? ''));
                if (!in_array($status, $statusOptions, true)) {
                    $rowErrors[] = "Order {$purchaseNumber}: status must be one of: " . implode(', ', $statusOptions) . '.';
                }

                $items = [];
                foreach ($group['rows'] as $entry) {
                    $rowNum = $entry['row_num'];
                    $row = $entry['data'];
                    $sku = trim((string) ($row['sku'] ?? ''));
                    $quantity = (int) ($row['quantity'] ?? 0);
                    $unitCost = trim((string) ($row['unit_cost'] ?? ''));

                    if ($sku === '' || !isset($unitsBySku[strtoupper($sku)])) {
                        $rowErrors[] = "Row {$rowNum}: sku \"{$sku}\" was not found among sellable products/variations.";
                        continue;
                    }
                    if ($quantity < 1) {
                        $rowErrors[] = "Row {$rowNum}: quantity must be at least 1.";
                        continue;
                    }
                    if ($unitCost === '' || !is_numeric($unitCost) || (float) $unitCost < 0) {
                        $rowErrors[] = "Row {$rowNum}: unit_cost must be a valid non-negative number.";
                        continue;
                    }

                    $unit = $unitsBySku[strtoupper($sku)];
                    $items[] = [
                        'product_id' => $unit['product_id'],
                        'variation_id' => $unit['variation_id'],
                        'quantity' => $quantity,
                        'supplier_price' => (float) $unitCost,
                    ];
                }

                $groups[$purchaseNumber]['prepared'] = [
                    'data' => [
                        'purchase_number' => $purchaseNumber,
                        'supplier_id' => $supplierId,
                        'order_date' => $orderDate,
                        'expected_delivery_date' => trim((string) ($first['expected_delivery_date'] ?? '')),
                        'received_date' => trim((string) ($first['received_date'] ?? '')),
                        'status' => $status,
                        'notes' => trim((string) ($first['notes'] ?? '')),
                    ],
                    'items' => $items,
                ];
            }

            if ($rowErrors === [] && $groups !== []) {
                $pdo->beginTransaction();

                try {
                    foreach ($groups as $group) {
                        supplier_order_import_create($pdo, $group['prepared']['data'], $group['prepared']['items']);
                        $importedOrders++;
                    }

                    $pdo->commit();
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = 'Import failed: ' . $exception->getMessage();
                    $importedOrders = 0;
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Import failed.';
                    $importedOrders = 0;
                }
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Import Historical Supplier Orders</h2>
        <p class="text-muted mb-0">CSV only, one row per order item - rows sharing the same purchase_number become one order. Never touches incoming/received stock.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/index.php">Back to Supplier Orders</a>
</div>

<?php if ($importedOrders > 0): ?>
    <div class="alert alert-success"><?php echo $importedOrders; ?> historical supplier order(s) imported successfully.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if ($rowErrors !== []): ?>
    <div class="alert alert-danger">
        <p class="mb-2">The file was <strong>not</strong> imported - fix these rows and re-upload:</p>
        <ul class="mb-0">
            <?php foreach ($rowErrors as $rowError): ?>
                <li><?php echo app_escape($rowError); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card p-4">
    <h5 class="mb-3">Upload CSV</h5>
    <p class="text-muted small mb-2">One row per order item. Columns (header row required):</p>
    <ul class="text-muted small">
        <li><code>purchase_number</code>, <code>supplier_name</code> (supplier must already exist), <code>order_date</code> (YYYY-MM-DD)</li>
        <li><code>expected_delivery_date</code>, <code>received_date</code> (optional, YYYY-MM-DD)</li>
        <li><code>status</code> (<?php echo app_escape(implode('/', $statusOptions)); ?>), <code>notes</code> (order-level - only the first row per purchase_number is used)</li>
        <li><code>sku</code> (product or variation SKU), <code>quantity</code>, <code>unit_cost</code> (per line item)</li>
    </ul>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <div class="mb-3">
            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" class="btn btn-primary">Validate &amp; Import</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
