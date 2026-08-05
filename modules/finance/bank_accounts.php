<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.view');

$appTitle = 'Bank Accounts';
$pdo = app_db();
$canManage = app_has_permission('finance.manage');
$error = '';

$editId = isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = $editId > 0 ? bank_account_get($pdo, $editId) : null;

$form = [
    'name' => $editing['name'] ?? '',
    'account_type' => $editing['account_type'] ?? 'bank',
    'currency' => $editing['currency'] ?? 'MYR',
    'notes' => $editing['notes'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        app_forbidden('You do not have permission to manage bank accounts.');
    }

    try {
        app_require_csrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save') {
            $form = array_merge($form, array_intersect_key($_POST, $form));
            $validated = bank_account_validate_form($_POST);
            if ($validated['errors'] !== []) {
                $error = implode(' ', $validated['errors']);
            } elseif (isset($_POST['id']) && ctype_digit((string) $_POST['id']) && (int) $_POST['id'] > 0) {
                $id = (int) $_POST['id'];
                if (bank_account_get($pdo, $id) === null) {
                    $error = 'Bank account not found.';
                } else {
                    bank_account_update($pdo, $id, $validated['data']);
                    app_redirect('/modules/finance/bank_accounts.php?updated=1');
                }
            } else {
                bank_account_create($pdo, $validated['data']);
                app_redirect('/modules/finance/bank_accounts.php?created=1');
            }
        } elseif ($action === 'toggle_active') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = $id > 0 ? bank_account_get($pdo, $id) : null;
            if ($row === null) {
                $error = 'Bank account not found.';
            } else {
                bank_account_set_active($pdo, $id, (int) $row['is_active'] !== 1);
                app_redirect('/modules/finance/bank_accounts.php?updated=1');
            }
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$accounts = bank_accounts_list($pdo, false);
$typeLabels = bank_account_type_labels();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1">Bank Accounts</h1>
        <p class="page-description">Manage accounts used to tag expense and manual income transactions.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/finance/index.php">Back to Expenses</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Bank account created.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Bank account updated.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-4">
            <h5 class="mb-3">Accounts</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle responsive-stack-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td data-label="Name">
                                    <?php echo app_escape($account['name']); ?>
                                    <?php if (!empty($account['notes'])): ?>
                                        <div class="text-muted small"><?php echo app_escape($account['notes']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Type"><?php echo app_escape($typeLabels[$account['account_type']] ?? $account['account_type']); ?></td>
                                <td data-label="Currency"><?php echo app_escape($account['currency']); ?></td>
                                <td data-label="Status">
                                    <span class="badge <?php echo (int) $account['is_active'] === 1 ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo (int) $account['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td data-label="" class="text-end">
                                    <?php if ($canManage): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="/modules/finance/bank_accounts.php?edit=<?php echo (int) $account['id']; ?>">Edit</a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?php echo (int) $account['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo (int) $account['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($accounts === []): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <div class="empty-state-title">No Bank Accounts Yet</div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php if ($canManage): ?>
        <div class="col-lg-4">
            <div class="card p-4">
                <h5 class="mb-3"><?php echo $editing !== null ? 'Edit Account' : 'Add Account'; ?></h5>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save">
                    <?php if ($editing !== null): ?>
                        <input type="hidden" name="id" value="<?php echo (int) $editing['id']; ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" maxlength="120" required value="<?php echo app_escape($form['name']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="account_type" required>
                            <?php foreach (BANK_ACCOUNT_TYPES as $type): ?>
                                <option value="<?php echo app_escape($type); ?>" <?php echo $form['account_type'] === $type ? 'selected' : ''; ?>>
                                    <?php echo app_escape($typeLabels[$type] ?? $type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Currency</label>
                        <input type="text" class="form-control" name="currency" maxlength="10" value="<?php echo app_escape($form['currency']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3" maxlength="255"><?php echo app_escape($form['notes']); ?></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1"><?php echo $editing !== null ? 'Save Changes' : 'Add Account'; ?></button>
                        <?php if ($editing !== null): ?>
                            <a class="btn btn-outline-secondary" href="/modules/finance/bank_accounts.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
