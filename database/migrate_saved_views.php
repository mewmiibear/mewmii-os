<?php
/**
 * Sprint 10 (Operator Experience) - adds the saved_views table backing the "Save current
 * view" feature on the Products/Orders/Supplier Orders/Shipments/Customers list pages.
 * Safe to run multiple times: CREATE TABLE IF NOT EXISTS is a no-op if it already exists.
 *
 * Run once via browser (https://yourdomain/database/migrate_saved_views.php) or CLI
 * (`php database/migrate_saved_views.php`) against an EXISTING Mewmii OS database.
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

echo 'Mewmii OS saved views migration starting...' . PHP_EOL;

$applied = [];

// saved_views: one row per named filter preset. Shared/global (visible to every operator,
// not scoped per-user) - created_by is kept only to show "Saved by X" and is never used to
// restrict who can see or delete a view. query_string is the raw GET query string of the
// list page's filters at save time (never includes `page`, so loading a saved view always
// starts at page 1).
if (!migrate_column_exists($pdo, 'saved_views', 'id')) {
    migrate_run($pdo, 'saved_views.create', "
        CREATE TABLE IF NOT EXISTS saved_views (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            module VARCHAR(40) NOT NULL,
            name VARCHAR(100) NOT NULL,
            query_string VARCHAR(1000) NOT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_saved_views_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_saved_views_module (module)
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

echo 'Done.' . PHP_EOL;
