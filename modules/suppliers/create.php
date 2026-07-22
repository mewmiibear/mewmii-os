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
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));

    if ($error === '') {
        if ($form['name'] === '' || strlen($form['name']) > 120) {
            $error = 'Name is required and must be 120 characters or fewer.';
        } elseif (strlen($form['contact']) > 120) {
            $error = 'Contact must be 120 characters or fewer.';
        } elseif (strlen($form['country']) > 100) {
            $error = 'Country must be 100 characters or fewer.';
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
                INSERT INTO suppliers (name, contact, country, notes)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([
                $form['name'],
                $form['contact'] !== '' ? $form['contact'] : null,
                $form['country'] !== '' ? $form['country'] : null,
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
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Add Supplier</h2>
        <p class="text-muted mb-0">Register a new supplier for purchase planning.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/suppliers/index.php">Back to Suppliers</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" value="<?php echo app_escape($form['name']); ?>" maxlength="120" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Contact</label>
                <input type="text" class="form-control" name="contact" value="<?php echo app_escape($form['contact']); ?>" maxlength="120">
            </div>

            <div class="col-md-6">
                <label class="form-label">Country</label>
                <input type="text" class="form-control" name="country" value="<?php echo app_escape($form['country']); ?>" maxlength="100">
            </div>

            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="3"><?php echo app_escape($form['notes']); ?></textarea>
            </div>
        </div>

        <button class="btn btn-primary mt-4" type="submit">Create Supplier</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
