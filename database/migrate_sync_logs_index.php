<?php
/**
 * Phase 6D (Production Hardening audit) - sync_logs had no index beyond its primary key,
 * despite being queried by (sync_type, reference_id) on every product/order page load
 * (wc_client_get_last_sync_log()) and sorted by created_at on the Sync Logs list - both were
 * full table scans, getting slower as the table grows (it has no retention/pruning). Adds
 * only this one index; no column, no row, and no existing query is changed. Safe to run
 * multiple times: checks INFORMATION_SCHEMA before altering anything.
 *
 * Run once via browser (https://yourdomain/database/migrate_sync_logs_index.php) or CLI
 * (`php database/migrate_sync_logs_index.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates this index.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = app_db();

function migrate_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ');
    $stmt->execute([$table, $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrate_run(PDO $pdo, string $label, string $sql, array &$applied): void
{
    try {
        $pdo->exec($sql);
        $applied[] = $label;
    } catch (PDOException $exception) {
        echo '  ! FAILED: ' . $label . ' - ' . $exception->getMessage() . PHP_EOL;
    }
}

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
