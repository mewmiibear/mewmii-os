<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/currency_rates.php';
app_require_permission('settings.manage');

/**
 * Phase 9F.1/9F.2 (Multi-Purpose Currency Rate Settings) - the single place exchange rates
 * are ever entered anymore, grouped by currency code with Supplier/Original/Market values
 * captured together in a single transaction. Saving a Supplier rate here also immediately
 * refreshes products.exchange_rate for every product using that currency (see
 * currency_rates_bulk_refresh_products_exchange_rate()) so includes/product_cost.php's actual
 * Landed Cost engine (untouched) always reflects the latest rate. Original/Market rates are
 * looked up live by includes/pricing_engine.php - no product column mirrors them.
 */

$appTitle = 'Currency Rates';
$pdo = app_db();
$error = '';
$success = '';
$editCurrencyCode = strtoupper(trim((string) ($_GET['edit'] ?? '')));
$formData = [
    'currency_code' => '',
    'supplier_rate' => '',
    'original_rate' => '',
    'market_rate' => '',
];

currency_rates_cleanup_system_currency_rows($pdo);

function currency_rates_audit_details(string $action, string $currencyCode, array $oldValues, array $newValues): string
{
    $segments = ['currency=' . $currencyCode];
    foreach (CURRENCY_RATE_TYPES as $rateType) {
        $oldValue = $oldValues[$rateType] ?? 'not configured';
        $newValue = $newValues[$rateType] ?? 'not configured';
        $segments[] = $rateType . '=' . $oldValue . '->' . $newValue;
    }

    return $action . ' - ' . implode('; ', $segments);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $formData = [
        'currency_code' => strtoupper(trim((string) ($_POST['currency_code'] ?? ''))),
        'supplier_rate' => trim((string) ($_POST['supplier_rate'] ?? '')),
        'original_rate' => trim((string) ($_POST['original_rate'] ?? '')),
        'market_rate' => trim((string) ($_POST['market_rate'] ?? '')),
    ];

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');
        $currencyCode = $formData['currency_code'];
        $rateValues = [
            'supplier' => $formData['supplier_rate'],
            'original' => $formData['original_rate'],
            'market' => $formData['market_rate'],
        ];
        $errors = [];

        if ($currencyCode === '') {
            $errors[] = 'Currency code is required.';
        } elseif (strlen($currencyCode) !== 3) {
            $errors[] = 'Currency code must be exactly 3 characters.';
        } elseif ($currencyCode === SYSTEM_BASE_CURRENCY) {
            $errors[] = SYSTEM_BASE_CURRENCY . ' is the base currency and cannot be managed here.';
        } elseif ($currencyCode === SYSTEM_SELLING_CURRENCY) {
            $errors[] = SYSTEM_SELLING_CURRENCY . ' is not managed as a rate currency.';
        }

        foreach (CURRENCY_RATE_TYPES as $rateType) {
            if (trim((string) $rateValues[$rateType]) === '') {
                $errors[] = ucfirst($rateType) . ' rate is required.';
            } elseif (!is_numeric($rateValues[$rateType]) || (float) $rateValues[$rateType] <= 0) {
                $errors[] = ucfirst($rateType) . ' rate must be numeric and greater than zero.';
            }
        }

        if ($action === 'add') {
            if (currency_rates_currency_exists($pdo, $currencyCode)) {
                $errors[] = 'Currency code already exists.';
            }
        } elseif ($action === 'update') {
            if (!currency_rates_currency_exists($pdo, $currencyCode)) {
                $errors[] = 'Currency code not found.';
            }
        } elseif ($action === 'delete') {
            if (!currency_rates_currency_exists($pdo, $currencyCode)) {
                $errors[] = 'Currency code not found.';
            }
        } else {
            $errors[] = 'Unknown action.';
        }

        if ($errors !== []) {
            $error = implode('<br>', $errors);
        }

        if ($error === '' && $action === 'add') {
            $oldValues = [];
            $newValues = [];
            $pdo->beginTransaction();
            try {
                foreach (CURRENCY_RATE_TYPES as $rateType) {
                    $newValues[$rateType] = round((float) $rateValues[$rateType], 6);
                    $stmt = $pdo->prepare('INSERT INTO currency_rates (rate_type, currency_code, exchange_rate) VALUES (?, ?, ?)');
                    $stmt->execute([$rateType, $currencyCode, $newValues[$rateType]]);
                }

                currency_rates_bulk_refresh_products_exchange_rate($pdo, $currencyCode, $newValues['supplier']);
                $pdo->commit();
                app_log_action((int) ($_SESSION['user_id'] ?? 0), 'Currency Added', currency_rates_audit_details('Currency Added', $currencyCode, $oldValues, $newValues));
                app_redirect('/modules/settings/currency_rates.php?created=1&edit=' . rawurlencode($currencyCode));
            } catch (Throwable $exception) {
                $pdo->rollBack();
                $error = 'Could not save currency rates.';
            }
        } elseif ($error === '' && $action === 'update') {
            $existingRows = currency_rates_find_for_currency($pdo, $currencyCode);
            $oldValues = [];
            $newValues = [];
            $pdo->beginTransaction();
            try {
                foreach (CURRENCY_RATE_TYPES as $rateType) {
                    $oldValues[$rateType] = $existingRows[$rateType]['exchange_rate'] ?? null;
                    $newValues[$rateType] = round((float) $rateValues[$rateType], 6);
                    if (isset($existingRows[$rateType])) {
                        $stmt = $pdo->prepare('UPDATE currency_rates SET exchange_rate = ? WHERE rate_type = ? AND currency_code = ?');
                        $stmt->execute([$newValues[$rateType], $rateType, $currencyCode]);
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO currency_rates (rate_type, currency_code, exchange_rate) VALUES (?, ?, ?)');
                        $stmt->execute([$rateType, $currencyCode, $newValues[$rateType]]);
                    }
                }

                currency_rates_bulk_refresh_products_exchange_rate($pdo, $currencyCode, $newValues['supplier']);
                $pdo->commit();
                app_log_action((int) ($_SESSION['user_id'] ?? 0), 'Currency Updated', currency_rates_audit_details('Currency Updated', $currencyCode, $oldValues, $newValues));
                app_redirect('/modules/settings/currency_rates.php?updated=1&edit=' . rawurlencode($currencyCode));
            } catch (Throwable $exception) {
                $pdo->rollBack();
                $error = 'Could not update currency rates.';
            }
        } elseif ($error === '' && $action === 'delete') {
            $existingRows = currency_rates_find_for_currency($pdo, $currencyCode);
            $oldValues = [];
            $newValues = [];
            $pdo->beginTransaction();
            try {
                foreach (CURRENCY_RATE_TYPES as $rateType) {
                    $oldValues[$rateType] = $existingRows[$rateType]['exchange_rate'] ?? null;
                    $newValues[$rateType] = 'deleted';
                }
                $stmt = $pdo->prepare('DELETE FROM currency_rates WHERE currency_code = ?');
                $stmt->execute([$currencyCode]);

                if (isset($existingRows['supplier'])) {
                    currency_rates_bulk_refresh_products_exchange_rate($pdo, $currencyCode, null);
                }
                $pdo->commit();
                app_log_action((int) ($_SESSION['user_id'] ?? 0), 'Currency Deleted', currency_rates_audit_details('Currency Deleted', $currencyCode, $oldValues, $newValues));
                app_redirect('/modules/settings/currency_rates.php?deleted=1');
            } catch (Throwable $exception) {
                $pdo->rollBack();
                $error = 'Could not delete currency rates.';
            }
        }
    }
}

$currencyRows = currency_rates_list_by_currency($pdo);
$displayCurrencyRows = [];
$displayCurrencyRows[SYSTEM_BASE_CURRENCY] = [
    '_is_base' => true,
    'supplier' => ['exchange_rate' => 1.0],
    'original' => ['exchange_rate' => 1.0],
    'market' => ['exchange_rate' => 1.0],
];
foreach ($currencyRows as $currencyCode => $rows) {
    if ($currencyCode === SYSTEM_SELLING_CURRENCY || $currencyCode === SYSTEM_BASE_CURRENCY) {
        continue;
    }
    $displayCurrencyRows[$currencyCode] = $rows;
}
uksort($displayCurrencyRows, static function (string $a, string $b): int {
    if ($a === SYSTEM_BASE_CURRENCY) {
        return -1;
    }
    if ($b === SYSTEM_BASE_CURRENCY) {
        return 1;
    }

    return strcmp($a, $b);
});

$editRates = [];
if ($editCurrencyCode !== '' && $editCurrencyCode !== SYSTEM_BASE_CURRENCY) {
    $editRates = currency_rates_find_for_currency($pdo, $editCurrencyCode);
    if ($editRates === []) {
        http_response_code(404);
        require_once __DIR__ . '/../../includes/header.php';
        echo '<div class="alert alert-danger">Currency not found.</div>';
        require_once __DIR__ . '/../../includes/footer.php';
        exit;
    }
    $formData = [
        'currency_code' => $editCurrencyCode,
        'supplier_rate' => $editRates['supplier']['exchange_rate'] ?? '',
        'original_rate' => $editRates['original']['exchange_rate'] ?? '',
        'market_rate' => $editRates['market']['exchange_rate'] ?? '',
    ];
} else {
    $editCurrencyCode = '';
}

if (isset($_GET['created'])) {
    $success = 'Currency added.';
} elseif (isset($_GET['updated'])) {
    $success = 'Currency updated.';
} elseif (isset($_GET['deleted'])) {
    $success = 'Currency deleted.';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Currency Rates</h2>
        <p class="text-muted mb-0">Manage supplier, original, and market exchange rates for each currency from one place.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-primary" href="#currency-form">+ Add Currency</a>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/export.php">Data Export</a>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/system_health.php">System Health</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?php echo app_escape($success); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="card p-4 mb-4" id="currency-form">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><?php echo $editCurrencyCode !== '' ? 'Edit Currency Rates' : 'Add Currency'; ?></h5>
        <?php if ($editCurrencyCode !== ''): ?>
            <a class="btn btn-sm btn-outline-secondary" href="/modules/settings/currency_rates.php">Cancel</a>
        <?php endif; ?>
    </div>
    <p class="text-muted small mb-3">JPY is the system base currency and is managed automatically. Only foreign/source currencies that need conversion are entered here.</p>
    <form method="post" class="row g-3 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo $editCurrencyCode !== '' ? 'update' : 'add'; ?>">
        <div class="col-md-2">
            <label class="form-label">Currency Code</label>
            <?php if ($editCurrencyCode !== ''): ?>
                <input type="text" class="form-control" value="<?php echo app_escape($formData['currency_code']); ?>" readonly>
                <input type="hidden" name="currency_code" value="<?php echo app_escape($formData['currency_code']); ?>">
            <?php else: ?>
                <input type="text" class="form-control" name="currency_code" maxlength="3" required placeholder="USD" value="<?php echo app_escape($formData['currency_code']); ?>">
            <?php endif; ?>
        </div>
        <div class="col-md-2">
            <label class="form-label">Supplier Rate</label>
            <input type="number" step="0.000001" min="0.000001" class="form-control" name="supplier_rate" required placeholder="0.028500" value="<?php echo app_escape($formData['supplier_rate']); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Original Rate</label>
            <input type="number" step="0.000001" min="0.000001" class="form-control" name="original_rate" required placeholder="0.028700" value="<?php echo app_escape($formData['original_rate']); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Market Rate</label>
            <input type="number" step="0.000001" min="0.000001" class="form-control" name="market_rate" required placeholder="0.029100" value="<?php echo app_escape($formData['market_rate']); ?>">
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary w-100"><?php echo $editCurrencyCode !== '' ? 'Save Changes' : 'Save Currency'; ?></button>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Currency</th>
                    <th>Supplier Rate</th>
                    <th>Original Rate</th>
                    <th>Market Rate</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($displayCurrencyRows as $currencyCode => $rows): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span><?php echo app_escape($currencyCode); ?></span>
                                <?php if ($currencyCode === SYSTEM_BASE_CURRENCY): ?>
                                    <span class="badge bg-primary">Base Currency</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo app_escape(isset($rows['supplier']) ? number_format((float) $rows['supplier']['exchange_rate'], 6) : '—'); ?></td>
                        <td><?php echo app_escape(isset($rows['original']) ? number_format((float) $rows['original']['exchange_rate'], 6) : '—'); ?></td>
                        <td><?php echo app_escape(isset($rows['market']) ? number_format((float) $rows['market']['exchange_rate'], 6) : '—'); ?></td>
                        <td class="text-end">
                            <?php if ($currencyCode === SYSTEM_BASE_CURRENCY): ?>
                                <span class="text-muted small">Locked</span>
                            <?php else: ?>
                                <div class="d-flex gap-1 justify-content-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="/modules/settings/currency_rates.php?edit=<?php echo app_escape($currencyCode); ?>">Edit</a>
                                    <form method="post" class="d-inline" action="/modules/settings/currency_rates.php" onsubmit="return confirm('Delete all rates for this currency?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="currency_code" value="<?php echo app_escape($currencyCode); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($displayCurrencyRows === []): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <div class="empty-state-title">No Currency Rates Yet</div>
                                <p class="empty-state-text">Add the first currency rate set to begin managing supplier, original, and market conversions.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>