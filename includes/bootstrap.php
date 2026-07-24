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

// Security Hardening Phase 4C: loads .env (if present) into getenv()/$_ENV before config.php
// reads it below - see includes/env_loader.php. Silently no-ops if .env doesn't exist yet.
require_once __DIR__ . '/env_loader.php';

$configPath = dirname(__DIR__) . '/config.php';
if (is_file($configPath)) {
    $appConfig = require $configPath;
    if (isset($appConfig['app']['timezone'])) {
        date_default_timezone_set($appConfig['app']['timezone']);
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
    if (empty($_SESSION['role_id'])) {
        return false;
    }

    $stmt = app_db()->prepare('
        SELECT COUNT(*)
        FROM role_permissions rp
        INNER JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id = ? AND p.name = ?
    ');
    $stmt->execute([$_SESSION['role_id'], $permission]);

    return (int) $stmt->fetchColumn() > 0;
}


function app_require_permission(string $permission): void
{
    app_require_login();

    if (!app_has_permission($permission)) {
        http_response_code(403);

        echo '<div class="alert alert-danger">
                Access denied.
              </div>';

        exit;
    }
}

/**
 * Writes one row to the audit_logs table (see database/schema.sql - id, user_id, action,
 * details, ip_address, created_at). Restored in Security Hardening Phase 4C - was previously
 * a no-op stub. Signature, and every existing caller (login.php, logout.php), are unchanged -
 * this only makes the function actually write.
 *
 * Best-effort only, same convention as activity_log() (includes/activity_log.php): a logging
 * failure must never block the real action it's describing (login/logout), so every failure
 * path here is swallowed, never thrown. Callers already wrap their own calls in try/catch as
 * an extra layer, but this function must be safe to call unguarded too.
 *
 * $userId of 0/empty is normalized to NULL rather than inserted literally - audit_logs.user_id
 * has a real FOREIGN KEY to users(id), and no user with id 0 ever exists (AUTO_INCREMENT
 * starts at 1), so a failed-login attempt (which has no authenticated user yet - see
 * login.php's login_failed call, which passes 0) would otherwise violate that constraint on
 * every single call and silently never get logged at all, defeating the point of this
 * function. NULL is also the semantically correct value here: "no user", not "user zero".
 *
 * Callers must never pass a password or other secret in $details - this function does not
 * redact or inspect its input, the caller is responsible for only ever passing safe,
 * non-sensitive text (both existing callers already do: 'Successful login', the attempted
 * email on failure, 'User logged out' - none of these are secrets).
 */
function app_log_action($userId, $action, $details = '')
{
    try {
        $normalizedUserId = ((int) $userId) > 0 ? (int) $userId : null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        app_db()->prepare('
            INSERT INTO audit_logs (user_id, action, details, ip_address)
            VALUES (?, ?, ?, ?)
        ')->execute([$normalizedUserId, (string) $action, (string) $details, $ipAddress]);
    } catch (Throwable $e) {
        // Never let audit logging break the real action it's describing.
    }

    return true;
}
