<?php
/**
 * Phase 11A (Unified Outbound Job Queue) - adds the outbound_jobs table backing
 * includes/job_queue.php. Safe to run multiple times: CREATE TABLE IF NOT EXISTS is a no-op
 * if it already exists.
 *
 * Run once via browser (https://yourdomain/database/migrate_outbound_jobs.php) or CLI
 * (`php database/migrate_outbound_jobs.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates this table via
 * install.php.
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

echo 'Mewmii OS outbound job queue migration starting...' . PHP_EOL;

$applied = [];

if (!migrate_column_exists($pdo, 'outbound_jobs', 'id')) {
    migrate_run($pdo, 'outbound_jobs.create', "
        CREATE TABLE IF NOT EXISTS outbound_jobs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(100) NOT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id INT UNSIGNED NULL,
            payload_json LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            priority INT NOT NULL DEFAULT 100,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
            run_after TIMESTAMP NULL,
            locked_at TIMESTAMP NULL,
            locked_by VARCHAR(100) NULL,
            completed_at TIMESTAMP NULL,
            failed_at TIMESTAMP NULL,
            last_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_outbound_jobs_status_priority_run_after (status, priority, run_after),
            INDEX idx_outbound_jobs_type (type),
            INDEX idx_outbound_jobs_entity (entity_type, entity_id),
            INDEX idx_outbound_jobs_locked_at (locked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", $applied);
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

echo 'Done. No existing sync/webhook/product logic was touched.' . PHP_EOL;
