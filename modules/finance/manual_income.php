<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.view');

$appTitle = 'Manual Income';
$pdo = app_db();
$canManage = app_has_permission('finance.manage');
$error = '';

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$editId = isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = $editId > 0 ? manual_income_get($pdo, $editId) : null;

$form = [
    'income_date' => $editing['income_date'] ?? date('Y-m-d'),
    'description' => $editing['description'] ?? '',
    'amount' => $editing['amount'] ?? '',
    'currency' => $editing['currency'] ?? 'MYR',
    'exchange_rate' => $editing['exchange_rate'] ?? '',
    'category' => $editing['category'] ?? 'Other',
    'bank_account_id' => $editing['bank_account_id'] !== null ? (string) $editing['bank_account_id'] : '',
    'reference_number' => $editing['reference_number'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        app_forbidden('You do not have permission to manage manual income.');
    }

    try {
        app_require_csrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save') {
            $form = array_merge($form, array_intersect_key($_POST, $form));
            $validated = manual_income_validate_form($pdo, $_POST);
            if ($validated['errors'] !== []) {
                $error = implode(' ', $validated['errors']);
            } elseif (isset($_POST['id']) && ctype_digit((string) $_POST['id']) && (int) $_POST['id'] > 0) {
                $id = (int) $_POST['id'];
                if (manual_income_get($pdo, $id) === null) {
                    $error = 'Manual income record not found.';
                } else {
                    manual_income_update($pdo, $id, $validated['data']);
                    app_redirect('/modules/finance/manual_income.php?updated=1');
                }
            } else {
                manual_income_create($pdo, $validated['data'], $_SESSION['user_id'] ?? null);
                app_redirect('/modules/finance/manual_income.php?created=1');
            }
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Exception $exception) {
        $error = 'Failed to save manual income: ' . $exception->getMessage();
    }
}

$incomes = manual_income_list($pdo, [
    'q' => $searchTerm,
    'category' => $categoryFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
]);

$totalAmount = 0.0;
foreach ($incomes as $income) {
    $totalAmount += (float) $income['amount'];
}

$bankAccounts = bank_accounts_list($pdo, false);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1">Manual Income</h2>
        <p class="page-description">Record non-order income only (asset sale, grant, or other one-off income).</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/finance/index.php">Back to Expenses</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Manual income recorded.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Manual income updated.</div>
<?php endif; ?>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Description or reference">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Category</label>
            <select class="form-select form-select-sm" name="category">
                <option value="">All categories</option>
                <?php foreach (MANUAL_INCOME_CATEGORIES as $category): ?>
                    <option value="<?php echo app_escape($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo app_escape($category); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">From</label>
            <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo app_escape($dateFrom); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">To</label>
            <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo app_escape($dateTo); ?>">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/finance/manual_income.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Income Records</h5>
                <div class="fw-semibold">Total: RM <?php echo app_escape(number_format($totalAmount, 2)); ?></div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle responsive-stack-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Bank Account</th>
                            <th>Amount</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incomes as $income): ?>
                            <tr>
                                <td data-label="Date"><?php echo app_escape($income['income_date']); ?></td>
                                <td data-label="Description"><?php echo app_escape($income['description']); ?></td>
                                <td data-label="Category"><?php echo app_escape($income['category']); ?></td>
                                <td data-label="Bank Account"><?php echo app_escape($income['bank_account_name'] ?? '-'); ?></td>
                                <td data-label="Amount"><?php echo app_escape($income['currency']); ?> <?php echo app_escape(number_format((float) $income['amount'], 2)); ?></td>
                                <td data-label="" class="text-end">
                                    <?php if ($canManage): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="/modules/finance/manual_income.php?edit=<?php echo (int) $income['id']; ?>">Edit</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($incomes === []): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <div class="empty-state-title">No Manual Income Yet</div>
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
                <h5 class="mb-3"><?php echo $editing !== null ? 'Edit Manual Income' : 'Add Manual Income'; ?></h5>
                <form method="post" data-validate="1" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save">
                    <?php if ($editing !== null): ?>
                        <input type="hidden" name="id" value="<?php echo (int) $editing['id']; ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" name="income_date" value="<?php echo app_escape((string) $form['income_date']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" maxlength="255" value="<?php echo app_escape((string) $form['description']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category" required>
                            <?php foreach (MANUAL_INCOME_CATEGORIES as $category): ?>
                                <option value="<?php echo app_escape($category); ?>" <?php echo $form['category'] === $category ? 'selected' : ''; ?>><?php echo app_escape($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="amount" value="<?php echo app_escape((string) $form['amount']); ?>" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Currency</label>
                            <input type="text" class="form-control" name="currency" maxlength="10" value="<?php echo app_escape((string) $form['currency']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Exchange Rate</label>
                            <input type="number" step="0.000001" min="0" class="form-control" name="exchange_rate" value="<?php echo app_escape((string) $form['exchange_rate']); ?>">
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
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
                    <div class="mb-3">
                        <label class="form-label">Reference Number</label>
                        <input type="text" class="form-control" name="reference_number" maxlength="100" value="<?php echo app_escape((string) $form['reference_number']); ?>">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1"><?php echo $editing !== null ? 'Save Changes' : 'Add Manual Income'; ?></button>
                        <?php if ($editing !== null): ?>
                            <a class="btn btn-outline-secondary" href="/modules/finance/manual_income.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$entryFormJsPath = __DIR__ . '/../../assets/js/entry-form-validation.js';
$entryFormJsVersion = is_file($entryFormJsPath) ? filemtime($entryFormJsPath) : time();
?>
<script src="/assets/js/entry-form-validation.js?v=<?php echo (int) $entryFormJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
