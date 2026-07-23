<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/csv_import.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('inventory.manage');

/**
 * CSV import for Inventory Opening Balance - the historical-data-migration entry point for
 * "how much stock did I already have when I started using Mewmii OS". Writes through
 * inventory_import_opening_stock() (includes/inventory.php), which refuses per-unit if that
 * unit already has ANY inventory_transactions history, so this can never double-count stock
 * that's already been tracked through normal use. Same all-or-nothing shape as the other
 * import tools.
 */

$appTitle = 'Import Opening Stock';
$error = '';
$rowErrors = [];
$imported = 0;

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

            $prepared = [];
            $seenSkus = [];
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $sku = trim((string) ($row['sku'] ?? ''));
                $quantity = (int) ($row['quantity'] ?? 0);

                if ($sku === '' || !isset($unitsBySku[strtoupper($sku)])) {
                    $rowErrors[] = "Row {$rowNum}: sku \"{$sku}\" was not found among sellable products/variations.";
                    continue;
                }
                if ($quantity < 1) {
                    $rowErrors[] = "Row {$rowNum}: quantity must be at least 1.";
                    continue;
                }
                if (isset($seenSkus[strtoupper($sku)])) {
                    $rowErrors[] = "Row {$rowNum}: sku \"{$sku}\" is duplicated elsewhere in this file.";
                    continue;
                }
                $seenSkus[strtoupper($sku)] = true;

                $unit = $unitsBySku[strtoupper($sku)];
                $historyStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE product_id = ? AND variation_id <=> ?');
                $historyStmt->execute([$unit['product_id'], $unit['variation_id']]);
                if ((int) $historyStmt->fetchColumn() > 0) {
                    $rowErrors[] = "Row {$rowNum}: \"{$sku}\" already has inventory history - Opening Stock can only be set once, before any other activity.";
                    continue;
                }

                $prepared[] = [
                    'product_id' => $unit['product_id'],
                    'variation_id' => $unit['variation_id'],
                    'quantity' => $quantity,
                    'notes' => trim((string) ($row['notes'] ?? '')),
                ];
            }

            if ($rowErrors === [] && $prepared !== []) {
                $pdo->beginTransaction();

                try {
                    foreach ($prepared as $line) {
                        inventory_import_opening_stock($pdo, $line['product_id'], $line['variation_id'], $line['quantity'], $line['notes'] !== '' ? $line['notes'] : null);
                        $imported++;
                    }

                    $pdo->commit();
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = 'Import failed: ' . $exception->getMessage();
                    $imported = 0;
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Import failed.';
                    $imported = 0;
                }
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Import Opening Stock</h2>
        <p class="text-muted mb-0">CSV only. Sets the starting Available quantity for units that have never had any inventory activity - a one-time historical baseline.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Back to Inventory</a>
</div>

<?php if ($imported > 0): ?>
    <div class="alert alert-success">Opening stock set for <?php echo $imported; ?> unit(s).</div>
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
    <p class="text-muted small">Columns (header row required): <code>sku</code> (product or variation SKU, required), <code>quantity</code> (required), <code>notes</code> (optional).</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <div class="mb-3">
            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" class="btn btn-primary">Validate &amp; Import</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
