<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.manage');

$pdo = app_db();
$expenseId = (int) ($_GET['id'] ?? 0);
$expense = $expenseId > 0 ? expense_get($pdo, $expenseId) : null;

if ($expense === null) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="empty-state"><div class="empty-state-title">Expense Not Found</div></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$appTitle = 'Edit Expense';
$error = '';

$form = [
    'category_id' => (string) $expense['category_id'],
    'supplier_id' => $expense['supplier_id'] !== null ? (string) $expense['supplier_id'] : '',
    'expense_date' => $expense['expense_date'],
    'description' => $expense['description'],
    'amount' => (string) $expense['amount'],
    'currency' => $expense['currency'],
    'exchange_rate' => $expense['exchange_rate'] !== null ? (string) $expense['exchange_rate'] : '',
    'payment_method' => (string) $expense['payment_method'],
    'reference_number' => (string) $expense['reference_number'],
    'tax_deductible' => (string) $expense['tax_deductible'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form = array_merge($form, array_intersect_key($_POST, $form));

    $validated = expense_validate_form($pdo, $_POST);

    if ($error === '' && $validated['errors'] !== []) {
        $error = implode(' ', $validated['errors']);
    }

    if ($error === '') {
        try {
            expense_update($pdo, $expenseId, $validated['data']);
            app_redirect('/modules/finance/view.php?id=' . $expenseId . '&updated=1');
        } catch (Exception $exception) {
            $error = 'Failed to update expense: ' . $exception->getMessage();
        }
    }
}

$categories = expense_categories_flat($pdo);
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1">Edit Expense</h2>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/finance/view.php?id=<?php echo (int) $expenseId; ?>">Back to Expense</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<form method="post" data-validate="1" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Expense Details</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Category</label>
                <select class="form-select" name="category_id" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo (int) $category['id']; ?>" <?php echo $form['category_id'] === (string) $category['id'] ? 'selected' : ''; ?>>
                            <?php echo str_repeat('&nbsp;&nbsp;', $category['depth']) . ($category['depth'] > 0 ? '&#8627; ' : '') . app_escape($category['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback">Choose a category.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Supplier (optional)</label>
                <select class="form-select" name="supplier_id">
                    <option value="">None</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="expense_date" value="<?php echo app_escape($form['expense_date']); ?>" required>
                <div class="invalid-feedback">Enter a valid date.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <input type="text" class="form-control" name="description" value="<?php echo app_escape($form['description']); ?>" maxlength="255" required>
                <div class="invalid-feedback">Description is required.</div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Amount</h5>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" min="0.01" class="form-control" name="amount" value="<?php echo app_escape($form['amount']); ?>" required>
                <div class="invalid-feedback">Enter a positive amount.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Currency</label>
                <input type="text" class="form-control" name="currency" value="<?php echo app_escape($form['currency']); ?>" maxlength="10">
            </div>
            <div class="col-md-3">
                <label class="form-label">Exchange Rate (optional)</label>
                <input type="number" step="0.000001" min="0" class="form-control" name="exchange_rate" value="<?php echo app_escape($form['exchange_rate']); ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="tax_deductible" name="tax_deductible" value="1" <?php echo $form['tax_deductible'] === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="tax_deductible">Tax deductible</label>
                </div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Payment &amp; Reference</h5>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Payment Method</label>
                <input type="text" class="form-control" name="payment_method" value="<?php echo app_escape($form['payment_method']); ?>" maxlength="50">
            </div>
            <div class="col-md-6">
                <label class="form-label">Reference Number</label>
                <input type="text" class="form-control" name="reference_number" value="<?php echo app_escape($form['reference_number']); ?>" maxlength="100">
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 py-3" style="position: sticky; bottom: 0; background: #fff; z-index: 1020; border-top: 1px solid #dee2e6;">
        <button class="btn btn-primary" type="submit">Save Changes</button>
        <a class="btn btn-outline-secondary" href="/modules/finance/view.php?id=<?php echo (int) $expenseId; ?>">Cancel</a>
    </div>
</form>
<?php
$entryFormJsPath = __DIR__ . '/../../assets/js/entry-form-validation.js';
$entryFormJsVersion = is_file($entryFormJsPath) ? filemtime($entryFormJsPath) : time();
?>
<script src="/assets/js/entry-form-validation.js?v=<?php echo (int) $entryFormJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
