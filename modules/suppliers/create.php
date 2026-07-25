<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('suppliers.manage');

$appTitle = 'Add Supplier';
$error = '';

$pdo = app_db();

$form = [
    'name' => '',
    'contact' => '',
    'country' => '',
    'contact_person' => '',
    'phone' => '',
    'email' => '',
    'currency' => '',
    'payment_terms' => '',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['contact'] = trim((string) ($_POST['contact'] ?? ''));
    $form['country'] = trim((string) ($_POST['country'] ?? ''));
    $form['contact_person'] = trim((string) ($_POST['contact_person'] ?? ''));
    $form['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $form['currency'] = trim((string) ($_POST['currency'] ?? ''));
    $form['payment_terms'] = trim((string) ($_POST['payment_terms'] ?? ''));
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));

    if ($error === '') {
        if ($form['name'] === '' || strlen($form['name']) > 120) {
            $error = 'Name is required and must be 120 characters or fewer.';
        } elseif (strlen($form['contact']) > 120) {
            $error = 'Contact must be 120 characters or fewer.';
        } elseif (strlen($form['country']) > 100) {
            $error = 'Country must be 100 characters or fewer.';
        } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address, or leave it blank.';
        }
    }

    if ($error === '') {
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE LOWER(name) = LOWER(?)');
        $dupCheck->execute([$form['name']]);
        if ((int) $dupCheck->fetchColumn() > 0) {
            $error = 'A supplier with this name already exists.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('
                INSERT INTO suppliers (name, contact, country, contact_person, phone, email, currency, payment_terms, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $form['name'],
                $form['contact'] !== '' ? $form['contact'] : null,
                $form['country'] !== '' ? $form['country'] : null,
                $form['contact_person'] !== '' ? $form['contact_person'] : null,
                $form['phone'] !== '' ? $form['phone'] : null,
                $form['email'] !== '' ? $form['email'] : null,
                $form['currency'] !== '' ? $form['currency'] : null,
                $form['payment_terms'] !== '' ? $form['payment_terms'] : null,
                $form['notes'] !== '' ? $form['notes'] : null,
            ]);

            $pdo->commit();

            app_redirect('/modules/suppliers/index.php?created=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create supplier.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1">Add Supplier</h2>
        <p class="page-description">Register a new supplier for purchase planning.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/suppliers/index.php">Back to Suppliers</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<form method="post" data-validate="1" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Company Info</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Supplier Name</label>
                <input type="text" class="form-control" name="name" value="<?php echo app_escape($form['name']); ?>" maxlength="120" required>
                <div class="invalid-feedback">Supplier name is required.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Contact</label>
                <input type="text" class="form-control" name="contact" value="<?php echo app_escape($form['contact']); ?>" maxlength="120">
            </div>
            <div class="col-md-4">
                <label class="form-label">Country</label>
                <input type="text" class="form-control" name="country" value="<?php echo app_escape($form['country']); ?>" maxlength="100">
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Contact Person</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="contact_person" value="<?php echo app_escape($form['contact_person']); ?>" maxlength="120">
            </div>
            <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" name="phone" value="<?php echo app_escape($form['phone']); ?>" maxlength="50">
            </div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <div class="input-group has-validation">
                    <span class="input-group-text">@</span>
                    <input type="email" class="form-control" name="email" value="<?php echo app_escape($form['email']); ?>" maxlength="190">
                    <div class="invalid-feedback">Enter a valid email address.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Financials &amp; Terms</h5>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Currency</label>
                <input type="text" class="form-control" name="currency" value="<?php echo app_escape($form['currency']); ?>" maxlength="10" placeholder="e.g. RM, USD, CNY">
            </div>
            <div class="col-md-6">
                <label class="form-label">Payment Terms</label>
                <input type="text" class="form-control" name="payment_terms" value="<?php echo app_escape($form['payment_terms']); ?>" maxlength="100" placeholder="e.g. Net 30, 50% deposit">
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Notes</h5>
        <textarea class="form-control" name="notes" rows="3"><?php echo app_escape($form['notes']); ?></textarea>
    </div>

    <div class="d-flex gap-2 py-3" style="position: sticky; bottom: 0; background: #fff; z-index: 1020; border-top: 1px solid #dee2e6;">
        <button class="btn btn-primary" type="submit">Create Supplier</button>
        <a class="btn btn-outline-secondary" href="/modules/suppliers/index.php">Cancel</a>
    </div>
</form>
<?php
$entryFormJsPath = __DIR__ . '/../../assets/js/entry-form-validation.js';
$entryFormJsVersion = is_file($entryFormJsPath) ? filemtime($entryFormJsPath) : time();
?>
<script src="/assets/js/entry-form-validation.js?v=<?php echo (int) $entryFormJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
