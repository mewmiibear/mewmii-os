<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('customers.manage');

$appTitle = 'Add Customer';
$error = '';

$pdo = app_db();

$form = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'instagram_username' => '',
    'birthday' => '',
    'address' => '',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $form['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $form['instagram_username'] = trim((string) ($_POST['instagram_username'] ?? ''));
    $form['birthday'] = trim((string) ($_POST['birthday'] ?? ''));
    $form['address'] = trim((string) ($_POST['address'] ?? ''));
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));

    if ($error === '') {
        if ($form['name'] === '' || strlen($form['name']) > 120) {
            $error = 'Name is required and must be 120 characters or fewer.';
        } elseif ($form['email'] !== '' && (strlen($form['email']) > 190 || !app_validate_email($form['email']))) {
            $error = 'Email must be a valid address of 190 characters or fewer.';
        } elseif (strlen($form['phone']) > 30) {
            $error = 'Phone must be 30 characters or fewer.';
        } elseif (strlen($form['instagram_username']) > 100) {
            $error = 'Instagram username must be 100 characters or fewer.';
        } elseif ($form['birthday'] !== '' && !DateTime::createFromFormat('Y-m-d', $form['birthday'])) {
            $error = 'Birthday must be a valid date.';
        }
    }

    if ($error === '' && $form['email'] !== '') {
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE LOWER(email) = LOWER(?)');
        $dupCheck->execute([$form['email']]);
        if ((int) $dupCheck->fetchColumn() > 0) {
            $error = 'A customer with this email already exists.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('
                INSERT INTO customers (name, email, phone, instagram_username, birthday, address, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $form['name'],
                $form['email'] !== '' ? $form['email'] : null,
                $form['phone'] !== '' ? $form['phone'] : null,
                $form['instagram_username'] !== '' ? $form['instagram_username'] : null,
                $form['birthday'] !== '' ? $form['birthday'] : null,
                $form['address'] !== '' ? $form['address'] : null,
                $form['notes'] !== '' ? $form['notes'] : null,
            ]);

            $pdo->commit();

            app_redirect('/modules/customers/index.php?created=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create customer.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Add Customer</h2>
        <p class="text-muted mb-0">Create a new customer profile.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/customers/index.php">Back to Customers</a>
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
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" value="<?php echo app_escape($form['email']); ?>" maxlength="190">
            </div>

            <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" name="phone" value="<?php echo app_escape($form['phone']); ?>" maxlength="30">
            </div>

            <div class="col-md-4">
                <label class="form-label">Instagram Username</label>
                <input type="text" class="form-control" name="instagram_username" value="<?php echo app_escape($form['instagram_username']); ?>" maxlength="100">
            </div>

            <div class="col-md-4">
                <label class="form-label">Birthday</label>
                <input type="date" class="form-control" name="birthday" value="<?php echo app_escape($form['birthday']); ?>">
            </div>

            <div class="col-12">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="2"><?php echo app_escape($form['address']); ?></textarea>
            </div>

            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="3"><?php echo app_escape($form['notes']); ?></textarea>
            </div>
        </div>

        <button class="btn btn-primary mt-4" type="submit">Create Customer</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
