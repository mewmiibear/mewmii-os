<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.manage');

$appTitle = 'Add Expense';
$error = '';
$pdo = app_db();

$form = [
    'category_id' => '',
    'supplier_id' => '',
    'expense_date' => date('Y-m-d'),
    'description' => '',
    'amount' => '',
    'currency' => 'MYR',
    'exchange_rate' => '',
    'payment_method' => '',
    'reference_number' => '',
    'tax_deductible' => '1',
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
        $pdo->beginTransaction();

        try {
            $expenseId = expense_create($pdo, $validated['data'], $_SESSION['user_id'] ?? null);

            if (isset($_FILES['receipt']) && (int) ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                expense_attachment_store($pdo, $expenseId, $_FILES['receipt'], $_SESSION['user_id'] ?? null);
            }

            $pdo->commit();

            app_redirect('/modules/finance/index.php?created=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create expense: ' . $exception->getMessage();
        }
    }
}

$categories = expense_categories_flat($pdo);
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1">Add Expense</h2>
        <p class="page-description">Record a business expense.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/finance/index.php">Back to Expenses</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" data-validate="1" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Expense Details</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Category</label>
                <select class="form-select" name="category_id" required>
                    <option value="">Select a category&hellip;</option>
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
                <input type="number" step="0.000001" min="0" class="form-control" name="exchange_rate" value="<?php echo app_escape($form['exchange_rate']); ?>" placeholder="Leave blank if already MYR">
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
                <input type="text" class="form-control" name="payment_method" value="<?php echo app_escape($form['payment_method']); ?>" maxlength="50" placeholder="e.g. Cash, Bank Transfer, Card">
            </div>
            <div class="col-md-6">
                <label class="form-label">Reference Number</label>
                <input type="text" class="form-control" name="reference_number" value="<?php echo app_escape($form['reference_number']); ?>" maxlength="100">
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Receipt (optional)</h5>
        <input type="file" class="form-control" name="receipt" accept="image/jpeg,image/png,application/pdf">
        <p class="text-muted small mt-2 mb-0">JPG, PNG, or PDF - up to 8 MB. More can be attached later from the expense's own page.</p>
    </div>

    <div class="d-flex gap-2 py-3" style="position: sticky; bottom: 0; background: #fff; z-index: 1020; border-top: 1px solid #dee2e6;">
        <button class="btn btn-primary" type="submit">Save Expense</button>
        <a class="btn btn-outline-secondary" href="/modules/finance/index.php">Cancel</a>
    </div>
</form>
<?php
$entryFormJsPath = __DIR__ . '/../../assets/js/entry-form-validation.js';
$entryFormJsVersion = is_file($entryFormJsPath) ? filemtime($entryFormJsPath) : time();
?>
<script src="/assets/js/entry-form-validation.js?v=<?php echo (int) $entryFormJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
