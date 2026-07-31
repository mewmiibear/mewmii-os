<?php
/**
 * Phase 9B (Notification & Alert Center) - adds mewmii_notifications.reference_id and its
 * lookup index. The table itself already existed (scaffolded, never wired up anywhere in the
 * app until this phase) with everything else Phase 9B needed: type, message, read_status,
 * created_at - only reference_id (a plain nullable INT, no foreign key - see
 * database/schema.sql for why) was missing. Safe to run multiple times: every step checks
 * INFORMATION_SCHEMA before altering anything.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_notifications.php) or CLI
 * (`php database/migrate_notifications.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates this column via
 * install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_notifications(PDO $pdo): array
{
    echo 'Mewmii OS notifications migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_column_exists($pdo, 'mewmii_notifications', 'reference_id')) {
        migrate_run($pdo, 'mewmii_notifications.reference_id', 'ALTER TABLE mewmii_notifications ADD COLUMN reference_id INT UNSIGNED NULL AFTER type', $applied);
    }

    if (!migrate_index_exists($pdo, 'mewmii_notifications', 'idx_mewmii_notifications_type_reference_read')) {
        migrate_run($pdo, 'mewmii_notifications.idx_type_reference_read', 'ALTER TABLE mewmii_notifications ADD INDEX idx_mewmii_notifications_type_reference_read (type, reference_id, read_status)', $applied);
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

    if ($failures !== []) {
        $resultMessage = count($failures) . ' statement(s) failed';
    } elseif ($applied === []) {
        $resultMessage = 'Already up to date';
    } else {
        $resultMessage = count($applied) . ' statement(s) applied';
    }

    return [
        'success' => $failures === [],
        'applied' => $applied,
        'failures' => $failures,
        'message' => $resultMessage,
    ];
}

if (!defined('MIGRATE_RUNNER_ACTIVE')) {
    migrate_notifications(app_db());
}
