<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.manage');

$pdo = app_db();
$assetId = (int) ($_GET['id'] ?? 0);
$asset = $assetId > 0 ? asset_get($pdo, $assetId) : null;

if ($asset === null) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="empty-state"><div class="empty-state-title">Asset Not Found</div></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$appTitle = 'Edit Asset';
$error = '';

// A disposed asset stays editable on purpose: disposal freezes the LIFECYCLE, not the record.
// Correcting a purchase amount or supplier on an already-disposed asset is a normal thing to
// need for an asset register, and status/disposal_date are not editable from this form anyway.
$form = [
    'asset_code' => $asset['asset_code'] ?? '',
    'name' => $asset['name'],
    'category' => $asset['category'],
    'supplier_id' => $asset['supplier_id'] !== null ? (string) $asset['supplier_id'] : '',
    'bank_account_id' => $asset['bank_account_id'] !== null ? (string) $asset['bank_account_id'] : '',
    'assigned_to' => $asset['assigned_to'] !== null ? (string) $asset['assigned_to'] : '',
    'location' => $asset['location'] ?? '',
    'purchase_date' => $asset['purchase_date'],
    'purchase_amount' => $asset['purchase_amount'],
    'currency' => $asset['currency'],
    'exchange_rate' => $asset['exchange_rate'] ?? '',
    'warranty_expiry' => $asset['warranty_expiry'] ?? '',
    'description' => $asset['description'],
    'notes' => $asset['notes'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form = array_merge($form, array_intersect_key($_POST, $form));

    $validated = asset_validate_form($pdo, $_POST, $assetId);

    if ($error === '' && $validated['errors'] !== []) {
        $error = implode(' ', $validated['errors']);
    }

    if ($error === '') {
        try {
            asset_update($pdo, $assetId, $validated['data']);
            app_redirect('/modules/finance/asset_view.php?id=' . $assetId . '&updated=1');
        } catch (PDOException $exception) {
            $error = (string) $exception->getCode() === '23000'
                ? 'That asset code was just taken by another asset. Choose a different code.'
                : 'Failed to update asset: ' . $exception->getMessage();
        } catch (Exception $exception) {
            $error = 'Failed to update asset: ' . $exception->getMessage();
        }
    }
}

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$bankAccounts = bank_accounts_list($pdo, false);
$users = $pdo->query("SELECT id, name FROM users WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1">Edit Asset</h1>
        <p class="page-description"><?php echo app_escape($asset['name']); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/finance/asset_view.php?id=<?php echo (int) $assetId; ?>">Back to Asset</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<form method="post" data-validate="1" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Asset Details</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Asset Code (Optional)</label>
                <input type="text" class="form-control" name="asset_code" maxlength="30" value="<?php echo app_escape((string) $form['asset_code']); ?>" placeholder="Example: AST-MAC-001">
                <div class="form-text">Optional internal reference number.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" maxlength="120" value="<?php echo app_escape((string) $form['name']); ?>" required>
                <div class="invalid-feedback">Asset name is required.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Category</label>
                <select class="form-select" name="category" required>
                    <option value="">Select a category&hellip;</option>
                    <?php foreach (ASSET_CATEGORIES as $category): ?>
                        <option value="<?php echo app_escape($category); ?>" <?php echo $form['category'] === $category ? 'selected' : ''; ?>><?php echo app_escape($category); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback">Choose a category.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <input type="text" class="form-control" name="description" maxlength="255" value="<?php echo app_escape((string) $form['description']); ?>" required>
                <div class="invalid-feedback">Description is required.</div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Purchase</h5>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Purchase Date</label>
                <input type="date" class="form-control" name="purchase_date" value="<?php echo app_escape((string) $form['purchase_date']); ?>" required>
                <div class="invalid-feedback">Enter a valid date.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Purchase Amount</label>
                <input type="number" step="0.01" min="0.01" class="form-control" name="purchase_amount" value="<?php echo app_escape((string) $form['purchase_amount']); ?>" required>
                <div class="invalid-feedback">Enter a positive amount.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Currency</label>
                <input type="text" class="form-control" name="currency" maxlength="10" value="<?php echo app_escape((string) $form['currency']); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Exchange Rate (optional)</label>
                <input type="number" step="0.000001" min="0" class="form-control" name="exchange_rate" value="<?php echo app_escape((string) $form['exchange_rate']); ?>" placeholder="Leave blank if already MYR">
            </div>
            <div class="col-md-4">
                <label class="form-label">Supplier (optional)</label>
                <select class="form-select" name="supplier_id">
                    <option value="">None</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo (int) $supplier['id']; ?>" <?php echo (string) $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Bank Account (optional)</label>
                <select class="form-select" name="bank_account_id">
                    <option value="">None</option>
                    <?php foreach ($bankAccounts as $account): ?>
                        <option value="<?php echo (int) $account['id']; ?>" <?php echo (string) $form['bank_account_id'] === (string) $account['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($account['name']); ?> (<?php echo app_escape(strtoupper((string) $account['account_type'])); ?><?php echo !empty($account['currency']) ? ', ' . app_escape($account['currency']) : ''; ?>)<?php echo (int) $account['is_active'] === 0 ? ' [Inactive]' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Warranty Expiry (optional)</label>
                <input type="date" class="form-control" name="warranty_expiry" value="<?php echo app_escape((string) $form['warranty_expiry']); ?>">
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Custody &amp; Location</h5>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Assigned To (optional)</label>
                <select class="form-select" name="assigned_to">
                    <option value="">Unassigned</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo (int) $user['id']; ?>" <?php echo (string) $form['assigned_to'] === (string) $user['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($user['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Who currently holds or is responsible for this asset.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Location (optional)</label>
                <input type="text" class="form-control" name="location" maxlength="100" value="<?php echo app_escape((string) $form['location']); ?>" placeholder="e.g. Office, Warehouse">
            </div>
            <div class="col-12">
                <label class="form-label">Notes (optional)</label>
                <textarea class="form-control" name="notes" rows="3"><?php echo app_escape((string) $form['notes']); ?></textarea>
                <div class="form-text">Ongoing notes - repairs, relocations, condition changes.</div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 py-3" style="position: sticky; bottom: 0; background: #fff; z-index: 1020; border-top: 1px solid #dee2e6;">
        <button class="btn btn-primary" type="submit">Save Changes</button>
        <a class="btn btn-outline-secondary" href="/modules/finance/asset_view.php?id=<?php echo (int) $assetId; ?>">Cancel</a>
    </div>
</form>
<?php
$entryFormJsPath = __DIR__ . '/../../assets/js/entry-form-validation.js';
$entryFormJsVersion = is_file($entryFormJsPath) ? filemtime($entryFormJsPath) : time();
?>
<script src="/assets/js/entry-form-validation.js?v=<?php echo (int) $entryFormJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
