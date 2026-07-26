<?php
/**
 * Phase 9C (Notification Actions & Lifecycle) - adds mewmii_notifications.resolved_status/
 * resolved_at and their lookup index. See database/schema.sql for the full lifecycle
 * rationale (Active/Acknowledged/Resolved, derived from the existing read_status plus these
 * two new columns - read_status itself is unchanged, old notifications are never deleted).
 * Safe to run multiple times: every step checks INFORMATION_SCHEMA before altering anything.
 *
 * Run once via browser (https://yourdomain/database/migrate_notification_lifecycle.php) or
 * CLI (`php database/migrate_notification_lifecycle.php`) against an EXISTING Mewmii OS
 * database. Brand-new installs don't need this - database/schema.sql already creates these
 * columns via install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = app_db();

function migrate_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrate_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ');
    $stmt->execute([$table, $index]);

    return (int) $stmt->fetchColumn() > 0;
}

function &migrate_failures(): array
{
    static $failures = [];

    return $failures;
}

function migrate_run(PDO $pdo, string $label, string $sql, array &$applied): void
{
    try {
        $pdo->exec($sql);
        $applied[] = $label;
    } catch (PDOException $exception) {
        echo '  ! FAILED: ' . $label . ' - ' . $exception->getMessage() . PHP_EOL;
        $failures = &migrate_failures();
        $failures[$label] = $exception->getMessage();
    }
}

echo 'Mewmii OS notification lifecycle migration starting...' . PHP_EOL;

$applied = [];

if (!migrate_column_exists($pdo, 'mewmii_notifications', 'resolved_status')) {
    migrate_run($pdo, 'mewmii_notifications.resolved_status', 'ALTER TABLE mewmii_notifications ADD COLUMN resolved_status TINYINT(1) NOT NULL DEFAULT 0 AFTER read_status', $applied);
}

if (!migrate_column_exists($pdo, 'mewmii_notifications', 'resolved_at')) {
    migrate_run($pdo, 'mewmii_notifications.resolved_at', 'ALTER TABLE mewmii_notifications ADD COLUMN resolved_at TIMESTAMP NULL AFTER resolved_status', $applied);
}

if (!migrate_index_exists($pdo, 'mewmii_notifications', 'idx_mewmii_notifications_type_reference_resolved')) {
    migrate_run($pdo, 'mewmii_notifications.idx_type_reference_resolved', 'ALTER TABLE mewmii_notifications ADD INDEX idx_mewmii_notifications_type_reference_resolved (type, reference_id, resolved_status)', $applied);
}

echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
foreach ($applied as $item) {
    echo '  - ' . $item . PHP_EOL;
}

if ($applied === []) {
    echo 'Database was already up to date - nothing to do.' . PHP_EOL;
}

$failures = migrate_failures();
if ($failures !== []) {
    echo PHP_EOL . count($failures) . ' migration statement(s) FAILED:' . PHP_EOL;
    foreach ($failures as $label => $message) {
        echo '  ! ' . $label . ' - ' . $message . PHP_EOL;
    }
}

echo 'Done. No existing forecasting, costing, purchasing, inventory, or supplier logic was touched.' . PHP_EOL;
