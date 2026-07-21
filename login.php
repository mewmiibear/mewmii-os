<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (app_is_logged_in()) {
    app_redirect('/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '') {
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $validationErrors = app_validate_required(['email' => $email, 'password' => $password], ['email', 'password']);
        if (!app_validate_email($email)) {
            $validationErrors[] = 'Email must be valid.';
        }

        if ($validationErrors !== []) {
            $error = implode(' ', $validationErrors);
        } else {
            $stmt = app_db()->prepare('SELECT u.id, u.name, u.email, u.password_hash, u.role_id, u.status, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role_id'] = (int) $user['role_id'];
                $_SESSION['user_role'] = $user['role_name'] ?? 'User';
                $_SESSION['user_permissions'] = [];

                $permissionStmt = app_db()->prepare('SELECT p.name FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ?');
                $permissionStmt->execute([(int) $user['role_id']]);
                $_SESSION['user_permissions'] = $permissionStmt->fetchAll(PDO::FETCH_COLUMN);

                app_log_action((int) $user['id'], 'login', 'Successful login');
                $stmt = app_db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
                $stmt->execute([app_now(), $user['id']]);
                app_redirect('/index.php');
            } else {
                $error = 'Invalid login credentials.';
                app_log_action(0, 'login_failed', $email);
            }
        }
    }
}

$appTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-5 col-md-7">
        <div class="card p-4">
            <h2 class="mb-3">Welcome back</h2>
            <p class="text-muted">Sign in to Mewmii OS admin.</p>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button class="btn btn-primary w-100">Sign in</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>