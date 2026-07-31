<?php
/**
 * Phase 6D (Production Hardening audit) - sync_logs had no index beyond its primary key,
 * despite being queried by (sync_type, reference_id) on every product/order page load
 * (wc_client_get_last_sync_log()) and sorted by created_at on the Sync Logs list - both were
 * full table scans, getting slower as the table grows (it has no retention/pruning). Adds
 * only this one index; no column, no row, and no existing query is changed. Safe to run
 * multiple times: checks INFORMATION_SCHEMA before altering anything.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_sync_logs_index.php) or CLI
 * (`php database/migrate_sync_logs_index.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates this index.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_sync_logs_index(PDO $pdo): array
{
    echo 'Mewmii OS sync_logs index migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_index_exists($pdo, 'sync_logs', 'idx_sync_logs_type_reference_created')) {
        migrate_run($pdo, 'sync_logs.idx_sync_logs_type_reference_created',
            'ALTER TABLE sync_logs ADD INDEX idx_sync_logs_type_reference_created (sync_type, reference_id, created_at)', $applied);
    }

    echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
    foreach ($applied as $item) {
        echo '  - ' . $item . PHP_EOL;
    }

    if ($applied === []) {
        echo 'Database was already up to date - nothing to do.' . PHP_EOL;
    }

    // This file historically didn't call migrate_failures() at all (its own migrate_run()
    // variant didn't record into the shared registry) - now that migrate_run() is shared and
    // always records, we read it here too so the returned result is accurate. See
    // database/migrate_helpers.php's own docblock for why this is safe: it only adds
    // visibility this file never had before, never changes what it does.
    $failures = migrate_failures();

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
    migrate_sync_logs_index(app_db());
}
