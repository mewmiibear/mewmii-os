<?php
/**
 * Final duplicate-order protection - database/schema.sql and install.sql both declare
 * supplier_orders.purchase_number as `VARCHAR(100) NOT NULL UNIQUE`, but CREATE TABLE IF NOT
 * EXISTS never retroactively adds a constraint to a table that already existed before this
 * repo's copy of schema.sql was last touched. This script checks INFORMATION_SCHEMA first and
 * only adds the constraint if it's genuinely missing, so it's safe to run against any existing
 * Mewmii OS database regardless of when its supplier_orders table was first created. Brand-new
 * installs don't need this - database/schema.sql already creates the column UNIQUE.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_supplier_order_purchase_number_unique.php) or CLI
 * (`php database/migrate_supplier_order_purchase_number_unique.php`).
 *
 * If this reports a duplicate-key failure when adding the index, some existing rows already
 * share a purchase_number - find them first with:
 *   SELECT purchase_number, COUNT(*) AS c FROM supplier_orders GROUP BY purchase_number HAVING c > 1;
 * and resolve/rename the duplicates before re-running this script.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

/**
 * Unique to this migration - not shared with any other file, so it stays local rather than
 * moving to migrate_helpers.php.
 */
function migrate_unique_constraint_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0
    ');
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrate_supplier_order_purchase_number_unique(PDO $pdo): array
{
    echo 'Mewmii OS supplier_orders.purchase_number uniqueness check starting...' . PHP_EOL;

    // This migration's original shape doesn't use the shared $applied/$failures arrays (it's a
    // single, simple check-then-ALTER, not a list of statements) - adapted here into the same
    // result shape every other migration returns, without changing what it actually does.
    $applied = [];
    $failures = [];

    if (migrate_unique_constraint_exists($pdo, 'supplier_orders', 'purchase_number')) {
        echo 'Already has a UNIQUE constraint - nothing to do.' . PHP_EOL;
    } else {
        try {
            $pdo->exec('ALTER TABLE supplier_orders ADD UNIQUE INDEX idx_supplier_orders_purchase_number_unique (purchase_number)');
            echo 'Added UNIQUE index idx_supplier_orders_purchase_number_unique on supplier_orders.purchase_number.' . PHP_EOL;
            $applied[] = 'supplier_orders.purchase_number_unique';
        } catch (PDOException $exception) {
            echo '! FAILED: ' . $exception->getMessage() . PHP_EOL;
            echo 'If this is a duplicate-entry error, see this file\'s doc comment for the query to find and resolve the conflicting rows, then re-run this script.' . PHP_EOL;
            $failures['supplier_orders.purchase_number_unique'] = $exception->getMessage();
        }
    }

    echo 'Done.' . PHP_EOL;

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
    migrate_supplier_order_purchase_number_unique(app_db());
}
