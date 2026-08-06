<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/pricing_engine.php';
app_require_permission('settings.manage');

/**
 * Phase 9D (Pricing Engine) - manages shipping_rate_countries, the configurable RM/gram
 * international shipping rate per origin country used by includes/pricing_engine.php's
 * Estimated Shipping Cost formula (weight_grams x rate_per_gram). Same WordPress-admin-style
 * Add/Edit-in-place pattern as modules/catalog/tabs/tags.php, minus Merge (a country has no
 * "reassign then delete" story - deleting one just clears any product's shipping_origin_country_id
 * back to unset via the FK's ON DELETE SET NULL, nothing blocks the delete).
 */

$appTitle = 'Shipping Rates';
$pdo = app_db();
$error = '';
$editId = (int) ($_GET['edit'] ?? 0);
$editFormOverride = null;

function shipping_rates_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shipping_rate_countries WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');
        $countryName = trim((string) ($_POST['country_name'] ?? ''));
        $ratePerGram = trim((string) ($_POST['rate_per_gram'] ?? ''));

        if ($action === 'add' || $action === 'update') {
            $countryId = $action === 'update' ? (int) ($_POST['country_id'] ?? 0) : 0;
            $editFormOverride = ['country_name' => $countryName, 'rate_per_gram' => $ratePerGram];

            if ($countryName === '' || strlen($countryName) > 100) {
                $error = 'Country name is required and must be 100 characters or fewer.';
            } elseif (!is_numeric($ratePerGram) || (float) $ratePerGram <= 0) {
                $error = 'Enter a valid rate per gram (RM), greater than 0.';
            } elseif ($action === 'update') {
                $existing = $countryId > 0 ? shipping_rates_find($pdo, $countryId) : null;
                if ($existing === null) {
                    $error = 'Shipping rate country not found.';
                }
            }

            if ($error === '') {
                $dupCheck = $action === 'update'
                    ? $pdo->prepare('SELECT COUNT(*) FROM shipping_rate_countries WHERE LOWER(country_name) = LOWER(?) AND id != ?')
                    : $pdo->prepare('SELECT COUNT(*) FROM shipping_rate_countries WHERE LOWER(country_name) = LOWER(?)');
                $action === 'update' ? $dupCheck->execute([$countryName, $countryId]) : $dupCheck->execute([$countryName]);
                if ((int) $dupCheck->fetchColumn() > 0) {
                    $error = 'A shipping rate for this country already exists.';
                }
            }

            if ($error === '' && $action === 'add') {
                $stmt = $pdo->prepare('INSERT INTO shipping_rate_countries (country_name, rate_per_gram) VALUES (?, ?)');
                $stmt->execute([$countryName, round((float) $ratePerGram, 4)]);
                app_redirect('/modules/settings/shipping_rates.php?created=1');
            } elseif ($error === '' && $action === 'update') {
                $stmt = $pdo->prepare('UPDATE shipping_rate_countries SET country_name = ?, rate_per_gram = ? WHERE id = ?');
                $stmt->execute([$countryName, round((float) $ratePerGram, 4), $countryId]);
                app_redirect('/modules/settings/shipping_rates.php?edit=' . $countryId . '&updated=1');
            }
        } elseif ($action === 'delete') {
            $countryId = (int) ($_POST['country_id'] ?? 0);
            if ($countryId < 1) {
                $error = 'Invalid shipping rate country.';
            } else {
                // ON DELETE SET NULL on products.shipping_origin_country_id - any product using
                // this country just has its shipping origin cleared, nothing blocks the delete.
                $pdo->prepare('DELETE FROM shipping_rate_countries WHERE id = ?')->execute([$countryId]);
                app_redirect('/modules/settings/shipping_rates.php?deleted=1');
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$countries = pricing_list_shipping_rate_countries($pdo);

$editCountry = null;
if ($editId > 0) {
    $editCountry = shipping_rates_find($pdo, $editId);
    if ($editCountry === null) {
        http_response_code(404);
        require_once __DIR__ . '/../../includes/header.php';
        echo '<div class="alert alert-danger">Shipping rate country not found.</div>';
        require_once __DIR__ . '/../../includes/footer.php';
        exit;
    }
    if ($editFormOverride !== null) {
        $editCountry['country_name'] = $editFormOverride['country_name'];
        $editCountry['rate_per_gram'] = $editFormOverride['rate_per_gram'];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1">Shipping Rates</h1>
        <p class="page-description">Configurable RM/gram international shipping rate per origin country, used by the Pricing Intelligence estimate on products.</p>
    </div>
    <div class="action-bar">
        <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/export.php">Data Export</a>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/system_health.php">System Health</a>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Shipping rate country created.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Shipping rate country updated.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Shipping rate country deleted.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><?php echo $editCountry !== null ? 'Edit Shipping Rate' : 'Add Shipping Rate'; ?></h5>
        <?php if ($editCountry !== null): ?>
            <a class="btn btn-sm btn-outline-secondary" href="/modules/settings/shipping_rates.php">Cancel</a>
        <?php endif; ?>
    </div>
    <form method="post" class="row g-2 align-items-end" action="/modules/settings/shipping_rates.php<?php echo $editCountry !== null ? '?edit=' . (int) $editCountry['id'] : ''; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo $editCountry !== null ? 'update' : 'add'; ?>">
        <?php if ($editCountry !== null): ?>
            <input type="hidden" name="country_id" value="<?php echo (int) $editCountry['id']; ?>">
        <?php endif; ?>
        <div class="col-md-6">
            <label class="form-label">Country</label>
            <input type="text" class="form-control" name="country_name" maxlength="100" required placeholder="e.g. Japan"
                   value="<?php echo $editCountry !== null ? app_escape($editCountry['country_name']) : ''; ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Rate per Gram (RM)</label>
            <input type="number" step="0.0001" min="0.0001" class="form-control" name="rate_per_gram" required placeholder="0.2000"
                   value="<?php echo $editCountry !== null ? app_escape((string) $editCountry['rate_per_gram']) : ''; ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><?php echo $editCountry !== null ? 'Save' : 'Add'; ?></button>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Country</th>
                    <th>Rate per Gram (RM)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($countries as $country): ?>
                    <tr>
                        <td><?php echo app_escape($country['country_name']); ?></td>
                        <td>RM <?php echo app_escape(number_format((float) $country['rate_per_gram'], 4)); ?></td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/settings/shipping_rates.php?edit=<?php echo (int) $country['id']; ?>">Edit</a>
                                <form method="post" class="d-inline" action="/modules/settings/shipping_rates.php" data-confirm="This cannot be undone. Products using it will have their shipping origin cleared." data-confirm-title="Delete shipping rate for <?php echo app_escape($country['country_name']); ?>?" data-confirm-label="Delete rate" data-confirm-tone="danger">
                                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="country_id" value="<?php echo (int) $country['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($countries === []): ?>
                    <tr>
                        <td colspan="3">
                            <div class="empty-state">
                                <div class="empty-state-title">No Shipping Rates Yet</div>
                                <p class="empty-state-text">Add a country and its RM/gram international shipping rate - e.g. Japan at RM0.20/g.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
