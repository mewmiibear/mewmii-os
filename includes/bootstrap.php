<?php
if (!defined('APP_START')) {
    define('APP_START', true);
}

session_name('mewmii_session');
if (headers_sent() === false) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $_ENV[$name] = trim($value);
        putenv("{$name}={$value}");
    }
}

require_once __DIR__ . '/../config/database.php';

function app_db(): PDO
{
    global $pdo;
    return $pdo;
}

function app_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function app_now(): string
{
    return date('Y-m-d H:i:s');
}

function app_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function app_require_login(): void
{
    if (!app_is_logged_in()) {
        app_redirect('/login.php');
    }
}

function app_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function app_require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        throw new RuntimeException('Invalid CSRF token.');
    }
}

function app_validate_required(array $data, array $fields): array
{
    $errors = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            $errors[] = $field . ' is required.';
        }
    }

    return $errors;
}

function app_validate_email(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function app_has_permission(string $permission): bool
{
    return is_array($_SESSION['user_permissions'] ?? null) && in_array($permission, $_SESSION['user_permissions'], true);
}

function app_require_permission(string $permission): void
{
    app_require_login();
    if (!app_has_permission($permission)) {
        http_response_code(403);
        echo '<div class="alert alert-danger">Access denied.</div>';
        exit;
    }
}

function app_log_action(int $userId, string $action, ?string $details = null): void
{
    $pdo = app_db();
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
}
