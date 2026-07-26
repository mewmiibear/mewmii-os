<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/currency_rates.php';
app_require_permission('settings.manage');

/**
 * Phase 9F.1 (Global Currency Exchange Rate Settings) - the single place exchange rates are
 * ever entered anymore. includes/pricing_engine.php looks this table up live for Original/
 * Supplier/Market Price conversion; saving a rate here also immediately refreshes
 * products.exchange_rate for every product using that currency (see
 * currency_rates_bulk_refresh_products_exchange_rate()) so includes/product_cost.php's actual
 * Landed Cost engine (untouched) always reflects the latest rate without anyone re-saving
 * each product individually. Same Add/Edit-in-place pattern as
 * modules/settings/shipping_rates.php, minus Delete for MYR (a fixed invariant - always 1.0).
 */

$appTitle = 'Currency Exchange Rates';
$pdo = app_db();
$error = '';
$editCode = strtoupper(trim((string) ($_GET['edit'] ?? '')));
$editFormOverride = null;

function currency_rates_find(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM currency_rates WHERE currency_code = ? LIMIT 1');
    $stmt->execute([$code]);
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
        $currencyCode = strtoupper(trim((string) ($_POST['currency_code'] ?? '')));
        $exchangeRate = trim((string) ($_POST['exchange_rate'] ?? ''));

        if ($action === 'add' || $action === 'update') {
            $editFormOverride = ['currency_code' => $currencyCode, 'exchange_rate' => $exchangeRate];

            if ($currencyCode === '' || strlen($currencyCode) > 10) {
                $error = 'Enter a valid currency code (up to 10 characters).';
            } elseif (!is_numeric($exchangeRate) || (float) $exchangeRate <= 0) {
                $error = 'Enter a valid exchange rate greater than 0.';
            } elseif ($currencyCode === 'MYR' && round((float) $exchangeRate, 6) !== 1.0) {
                // Invariant: MYR is the base currency - always exactly 1.0, never edited away
                // from that (every other rate is expressed as "1 unit = ? MYR").
                $error = 'MYR is the base currency and must always be exactly 1.000000.';
            } elseif ($action === 'add') {
                $existing = currency_rates_find($pdo, $currencyCode);
                if ($existing !== null) {
                    $error = 'A rate for ' . $currencyCode . ' already exists - edit it instead.';
                }
            } elseif ($action === 'update' && currency_rates_find($pdo, $currencyCode) === null) {
                $error = 'Currency rate not found.';
            }

            if ($error === '' && $action === 'add') {
                $pdo->prepare('INSERT INTO currency_rates (currency_code, exchange_rate) VALUES (?, ?)')
                    ->execute([$currencyCode, round((float) $exchangeRate, 6)]);
                currency_rates_bulk_refresh_products_exchange_rate($pdo, $currencyCode, round((float) $exchangeRate, 6));
                app_redirect('/modules/settings/currency_rates.php?created=1');
            } elseif ($error === '' && $action === 'update') {
                $pdo->prepare('UPDATE currency_rates SET exchange_rate = ? WHERE currency_code = ?')
                    ->execute([round((float) $exchangeRate, 6), $currencyCode]);
                currency_rates_bulk_refresh_products_exchange_rate($pdo, $currencyCode, round((float) $exchangeRate, 6));
                app_redirect('/modules/settings/currency_rates.php?edit=' . $currencyCode . '&updated=1');
            }
        } elseif ($action === 'delete') {
            if ($currencyCode === 'MYR') {
                $error = 'MYR is the base currency and cannot be deleted.';
            } elseif ($currencyCode === '') {
                $error = 'Invalid currency.';
            } else {
                $pdo->prepare('DELETE FROM currency_rates WHERE currency_code = ?')->execute([$currencyCode]);
                // Products using this currency simply revert to "not configured" (NULL) -
                // never silently frozen at the last known rate, and never assumed 1:1.
                currency_rates_bulk_refresh_products_exchange_rate($pdo, $currencyCode, null);
                app_redirect('/modules/settings/currency_rates.php?deleted=1');
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$rates = currency_rates_list($pdo);

$editRate = null;
if ($editCode !== '') {
    $dbRate = currency_rates_find($pdo, $editCode);
    if ($dbRate === null) {
        http_response_code(404);
        require_once __DIR__ . '/../../includes/header.php';
        echo '<div class="alert alert-danger">Currency rate not found.</div>';
        require_once __DIR__ . '/../../includes/footer.php';
        exit;
    }
    $editRate = $dbRate;
    if ($editFormOverride !== null) {
        $editRate['exchange_rate'] = $editFormOverride['exchange_rate'];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Currency Exchange Rates</h2>
        <p class="text-muted mb-0">Centrally-managed "1 unit = ? MYR" rate per currency - used automatically by Original/Supplier/Market Price conversion on every product. No rate is ever entered on a product directly.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/export.php">Data Export</a>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/system_health.php">System Health</a>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Currency rate created.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Currency rate updated - already-saved products using this currency have been refreshed.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Currency rate deleted.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><?php echo $editRate !== null ? 'Edit Currency Rate' : 'Add Currency Rate'; ?></h5>
        <?php if ($editRate !== null): ?>
            <a class="btn btn-sm btn-outline-secondary" href="/modules/settings/currency_rates.php">Cancel</a>
        <?php endif; ?>
    </div>
    <form method="post" class="row g-2 align-items-end" action="/modules/settings/currency_rates.php<?php echo $editRate !== null ? '?edit=' . app_escape($editRate['currency_code']) : ''; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo $editRate !== null ? 'update' : 'add'; ?>">
        <div class="col-md-4">
            <label class="form-label">Currency Code</label>
            <?php if ($editRate !== null): ?>
                <input type="text" class="form-control" value="<?php echo app_escape($editRate['currency_code']); ?>" readonly>
                <input type="hidden" name="currency_code" value="<?php echo app_escape($editRate['currency_code']); ?>">
            <?php else: ?>
                <select class="form-select" name="currency_code" required>
                    <option value="">Select&hellip;</option>
                    <?php foreach (CURRENCY_RATE_OPTIONS as $currencyOption): ?>
                        <?php if (currency_rates_find($pdo, $currencyOption) === null): ?>
                            <option value="<?php echo app_escape($currencyOption); ?>"><?php echo app_escape($currencyOption); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <label class="form-label">Exchange Rate (1 unit = ? MYR)</label>
            <input type="number" step="0.000001" min="0.000001" class="form-control" name="exchange_rate" required placeholder="0.026000"
                   <?php echo ($editRate !== null && $editRate['currency_code'] === 'MYR') ? 'readonly' : ''; ?>
                   value="<?php echo $editRate !== null ? app_escape((string) $editRate['exchange_rate']) : ''; ?>">
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary w-100"><?php echo $editRate !== null ? 'Save' : 'Add'; ?></button>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Currency</th>
                    <th>Exchange Rate (1 unit = ? MYR)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rates as $rate): ?>
                    <tr>
                        <td><?php echo app_escape($rate['currency_code']); ?></td>
                        <td><?php echo app_escape(number_format((float) $rate['exchange_rate'], 6)); ?></td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/settings/currency_rates.php?edit=<?php echo app_escape($rate['currency_code']); ?>">Edit</a>
                                <?php if ($rate['currency_code'] !== 'MYR'): ?>
                                    <form method="post" class="d-inline" action="/modules/settings/currency_rates.php" onsubmit="return confirm('Delete this currency rate? Products using it will show &quot;Exchange rate not configured&quot; until a new one is added.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="currency_code" value="<?php echo app_escape($rate['currency_code']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rates === []): ?>
                    <tr>
                        <td colspan="3">
                            <div class="empty-state">
                                <div class="empty-state-title">No Currency Rates Yet</div>
                                <p class="empty-state-text">Add MYR at 1.000000 first, then any foreign currency your products use (e.g. JPY at 0.026).</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
