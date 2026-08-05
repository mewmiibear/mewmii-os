<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/csv_import.php';
require_once __DIR__ . '/../../includes/product_import.php';
app_require_permission('products.manage');

/**
 * Product CSV Import - three steps in one page, same all-or-nothing-per-file shape as
 * modules/customers/import.php used to inspire this, but PER-ROW rather than per-file (see
 * includes/product_import.php's docblock for why the original all-or-nothing design was
 * itself part of a real incident):
 *   1. GET: upload form + template download.
 *   2. POST action=preview: parse + validate (product_import_validate_rows()), never
 *      writes. Shows every row that's ready to import AND every row that would fail, with
 *      its reason and a suggested fix - a Confirm button is available whenever at least
 *      one row is valid.
 *   3. POST action=confirm: the raw parsed rows travel from step 2 to step 3 as a JSON
 *      blob in a hidden field (no file re-upload, no session state) - re-validated fresh
 *      against the database right before inserting anything (something could have changed
 *      since the preview - a SKU taken by another import, a supplier deleted), then every
 *      row that still validates is created independently (product_import_commit()) - a
 *      failure on one row never blocks or rolls back any other row.
 *
 * WooCommerce sync is deliberately NOT part of this flow - imported products are created as
 * local drafts only. Use the existing "Sync to WooCommerce" button/bulk action on the
 * Products list once you've reviewed them.
 */

$appTitle = 'Import Products';
$error = '';
$rowErrors = [];
$commitFailures = [];
$createdCount = 0;
$previewRows = null; // non-null only once at least one row has validated
$previewErrors = [];
$rawRowsJson = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $pdo = app_db();
    $action = (string) ($_POST['action'] ?? 'preview');

    if ($error === '' && $action === 'preview') {
        if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Choose a CSV file to upload.';
        }

        if ($error === '') {
            try {
                $parsed = csv_import_read_rows($_FILES['csv_file']['tmp_name']);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }

        if ($error === '' && $parsed['rows'] === []) {
            $error = 'The CSV file has no data rows.';
        }

        if ($error === '') {
            $result = product_import_validate_rows($pdo, $parsed['rows']);
            $previewErrors = $result['errors'];
            $previewRows = $result['validated'];
            $rawRowsJson = json_encode($parsed['rows']);

            if ($previewRows === [] && $previewErrors === []) {
                $error = 'Nothing to import.';
                $previewRows = null;
            }
        }
    } elseif ($error === '' && $action === 'confirm') {
        $rawRows = json_decode((string) ($_POST['raw_rows_json'] ?? ''), true);

        if (!is_array($rawRows) || $rawRows === []) {
            $error = 'Your preview expired or the file data was lost - please re-upload the CSV.';
        } else {
            // Re-validated fresh, never trusting the client-side round-trip - see this
            // module's own docblock above for why (another import/deletion could have
            // happened in between).
            $result = product_import_validate_rows($pdo, $rawRows);
            $rowErrors = $result['errors'];

            if ($result['validated'] === []) {
                $error = 'Nothing was imported - every row failed validation. Review the errors below and re-upload.';
            } else {
                $commitResult = product_import_commit($pdo, $result['validated']);
                $createdCount = $commitResult['created'];
                $commitFailures = $commitResult['failed'];
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';

/**
 * Renders one "row / product / reason / suggestion" failure table - shared between the
 * preview step's validation errors and the confirm step's commit failures so both look and
 * behave identically.
 */
function product_import_render_error_table(array $errors): void
{
    ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th>Row</th>
                    <th>Product</th>
                    <th>Error</th>
                    <th>Suggested Fix</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($errors as $rowError): ?>
                    <tr>
                        <td><?php echo (int) $rowError['row_num']; ?></td>
                        <td><?php echo app_escape($rowError['name']); ?></td>
                        <td><?php echo app_escape($rowError['reason']); ?></td>
                        <td class="text-muted"><?php echo $rowError['suggestion'] !== null ? app_escape($rowError['suggestion']) : '&mdash;'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">Import Products</h1>
        <p class="text-muted mb-0">CSV only. Every row is validated before anything is created - nothing is imported until you confirm the preview.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
</div>

<?php if ($createdCount > 0): ?>
    <div class="alert <?php echo $commitFailures !== [] ? 'alert-warning' : 'alert-success'; ?>">
        <?php echo (int) $createdCount; ?> product(s) imported as drafts<?php echo $commitFailures !== [] ? ', ' . count($commitFailures) . ' failed during creation (see below).' : '.'; ?>
        Products are <strong>not</strong> synced to WooCommerce automatically - review them on the
        <a href="/modules/products/index.php?status=draft">Products list</a>, then use "Sync to WooCommerce" when you're ready.
    </div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if ($commitFailures !== []): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">Failed during creation - <?php echo count($commitFailures); ?> row(s)</h5>
        <?php product_import_render_error_table($commitFailures); ?>
    </div>
<?php endif; ?>

<?php if ($rowErrors !== [] && $commitFailures === [] && $createdCount === 0): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">Rejected rows - <?php echo count($rowErrors); ?></h5>
        <?php product_import_render_error_table($rowErrors); ?>
    </div>
<?php endif; ?>

<?php if ($previewRows !== null): ?>
    <?php if ($previewErrors !== []): ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Will be skipped - <?php echo count($previewErrors); ?> row(s) with errors</h5>
            <p class="text-muted small">These rows will NOT be imported unless you fix them and re-upload. The rows below that passed validation will still be created either way.</p>
            <?php product_import_render_error_table($previewErrors); ?>
        </div>
    <?php endif; ?>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Preview - <?php echo count($previewRows); ?> product(s) ready to import</h5>
        <p class="text-muted small">Nothing has been created yet - review below, then confirm.</p>
        <?php if ($previewRows !== []): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Name</th>
                        <th>SKU</th>
                        <th>Brand</th>
                        <th>Collection</th>
                        <th>Tags</th>
                        <th>Supplier</th>
                        <th>Cost</th>
                        <th>Selling Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Attributes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewRows as $row): ?>
                        <tr>
                            <td><?php echo (int) $row['row_num']; ?></td>
                            <td><?php echo app_escape($row['name']); ?></td>
                            <td><?php echo app_escape($row['sku']); ?></td>
                            <td><?php echo $row['brand'] !== '' ? app_escape($row['brand']) : '&mdash;'; ?></td>
                            <td><?php echo $row['collection'] !== '' ? app_escape($row['collection']) : '&mdash;'; ?></td>
                            <td><?php echo $row['tags'] !== [] ? app_escape(implode(', ', $row['tags'])) : '&mdash;'; ?></td>
                            <td><?php echo $row['supplier'] !== '' ? app_escape($row['supplier']) : '&mdash;'; ?></td>
                            <td>
                                <?php echo app_escape(number_format($row['cost'], 2)); ?>
                                <?php if ($row['currency'] !== null): ?><span class="text-muted small"><?php echo app_escape($row['currency']); ?></span><?php endif; ?>
                            </td>
                            <td>RM <?php echo app_escape(number_format($row['selling_price'], 2)); ?></td>
                            <td><?php echo $row['stock'] !== null ? (int) $row['stock'] : '&mdash;'; ?></td>
                            <td><?php echo app_escape(ucfirst($row['status'])); ?></td>
                            <td>
                                <?php if ($row['attributes'] !== []): ?>
                                    <?php echo app_escape(implode(', ', array_map(static fn (array $a): string => $a['name'] . ': ' . $a['value'], $row['attributes']))); ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="raw_rows_json" value="<?php echo app_escape($rawRowsJson); ?>">
            <button type="submit" class="btn btn-primary">Confirm Import (<?php echo count($previewRows); ?>)</button>
            <a class="btn btn-outline-secondary" href="/modules/products/import.php">Cancel</a>
        </form>
        <?php else: ?>
            <p class="text-muted mb-0">No rows passed validation - fix the errors above and re-upload.</p>
            <a class="btn btn-outline-secondary btn-sm mt-2" href="/modules/products/import.php">Start Over</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card p-4">
        <h5 class="mb-3">Upload CSV</h5>
        <p class="text-muted small mb-2">Header row required, columns in any order (common variants like "Product Name" or "Cost Price" are recognized automatically):</p>
        <ul class="text-muted small">
            <li><code>name</code>, <code>sku</code> - required</li>
            <li><code>brand</code>, <code>collection</code> - matched by name, created automatically if new</li>
            <li><code>tags</code> - comma-separated (e.g. <code>plush,new-arrival</code>), created automatically if new</li>
            <li><code>supplier</code> - must match an existing supplier's name exactly (see <a href="/modules/suppliers/index.php" target="_blank" rel="noopener">Suppliers</a>) - not auto-created</li>
            <li><code>cost</code>, <code>selling_price</code> - required, plain numbers</li>
            <li><code>currency</code> - optional, the currency <code>cost</code> was quoted in (e.g. USD)</li>
            <li><code>stock</code> - optional, initial available quantity (whole number)</li>
            <li><code>status</code> - optional, one of draft/active/hidden/archived (defaults to draft)</li>
            <li><code>attributes</code> - optional, <code>Name:Value|Name2:Value2</code> (e.g. <code>Character:Hello Kitty|Size:M</code>) - descriptive only, does not generate variations</li>
        </ul>
        <p class="text-muted small">Every imported product is created as a <strong>simple, ready-stock</strong> product, not synced to WooCommerce. Variable products with real variations still go through <a href="/modules/products/create.php">Add Product</a>'s Attribute Builder.</p>
        <a class="btn btn-outline-secondary btn-sm mb-3" href="/modules/products/import_template.php">Download CSV Template</a>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="preview">
            <div class="mb-3">
                <input type="file" class="form-control" name="csv_file" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary">Validate &amp; Preview</button>
        </form>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
