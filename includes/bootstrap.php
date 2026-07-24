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
/**
 * Progressive login delay (Security Hardening Phase 4D) - reuses audit_logs (restored in
 * Phase 4C, no new table needed - see the Phase 4D audit for why: it already has ip_address,
 * action, details (the attempted email), and created_at, exactly what this needs).
 *
 * Deliberately NOT an account lock: counts recent 'login_failed' rows matching this exact
 * (ip_address, email) pair and sleeps 1 second per recent failure, capped at 10 - a real
 * slowdown against automated guessing, but the legitimate owner mistyping their password a
 * few times only ever waits a few extra seconds, never gets locked out. "Recent" means since
 * this email's last successful login (found via user_id, since a fresh success is the
 * clearest possible signal that whoever is at that IP now is legitimate), falling back to a
 * bounded 24-hour lookback for an email that has never once succeeded - so the query never
 * scans unbounded history either way. This never deletes or modifies any audit_logs row -
 * failed attempts stay logged permanently regardless of what happens after them.
 *
 * Must be called AFTER validating $email is a plausible address but BEFORE password_verify(),
 * so both a correct and incorrect password on this attempt are delayed equally (no timing
 * signal either way) - see login.php.
 */
function app_login_throttle_delay(string $ipAddress, string $email): void
{
    try {
        $pdo = app_db();

        $userStmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $userStmt->execute([$email]);
        $userId = $userStmt->fetchColumn();

        $sinceTimestamp = null;
        if ($userId !== false) {
            $lastSuccessStmt = $pdo->prepare("
                SELECT MAX(created_at) FROM audit_logs WHERE action = 'login' AND user_id = ?
            ");
            $lastSuccessStmt->execute([(int) $userId]);
            $sinceTimestamp = $lastSuccessStmt->fetchColumn() ?: null;
        }

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM audit_logs
            WHERE action = 'login_failed' AND ip_address = ? AND details = ?
              AND created_at >= COALESCE(?, DATE_SUB(NOW(), INTERVAL 24 HOUR))
        ");
        $countStmt->execute([$ipAddress, $email, $sinceTimestamp]);
        $recentFailures = (int) $countStmt->fetchColumn();

        if ($recentFailures > 0) {
            sleep(min(10, $recentFailures));
        }
    } catch (Throwable $e) {
        // Never let the throttle check itself block a login attempt - if this fails for any
        // reason (e.g. a transient DB hiccup), the login proceeds undelayed rather than
        // erroring out entirely. Same "must never block the real action" convention as
        // app_log_action() just below.
    }
}

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
