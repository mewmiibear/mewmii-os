<?php
/**
 * SO-D - index on supplier_orders.order_date.
 *
 * Purely a performance index. It adds no column, changes no value, and alters no query result -
 * an index can only affect how fast a row is found, never which rows come back.
 *
 * Why this one: order_date is the leading sort key in every batch landed-cost calculation
 * (includes/product_cost.php's reference-line lookup and SO-A1's purchase-cost lookup, both of
 * which run on the Margin Report, Inventory Report, Purchasing, and the cost-increase alert
 * generator) and in the SO-C supplier purchase-history views. supplier_orders already had
 * indexes on status, payment_status and expected_delivery_date; order_date was the one heavily
 * sorted column without one.
 *
 * Additive, idempotent, non-destructive. Safe to run at any time.
 *
 * Run via database/migrate.php, or standalone via browser or CLI
 * (`php database/migrate_supplier_order_date_index.php`) against an EXISTING database. Brand-new
 * installs don't need this - database/schema.sql already declares the index.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_supplier_order_date_index(PDO $pdo): array
{
    echo 'Mewmii OS supplier_orders.order_date index migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_index_exists($pdo, 'supplier_orders', 'idx_supplier_orders_order_date')) {
        migrate_run($pdo, 'supplier_orders.idx_order_date', '
            ALTER TABLE supplier_orders ADD INDEX idx_supplier_orders_order_date (order_date)
        ', $applied);
    }

    echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
    foreach ($applied as $item) {
        echo '  - ' . $item . PHP_EOL;
    }

    $failures = migrate_failures();
    if ($failures !== []) {
        echo PHP_EOL . count($failures) . ' migration statement(s) FAILED:' . PHP_EOL;
        foreach ($failures as $label => $message) {
            echo '  ! ' . $label . ' - ' . $message . PHP_EOL;
        }
    }

    if ($failures !== []) {
        $resultMessage = count($failures) . ' statement(s) failed';
    } elseif ($applied === []) {
        $resultMessage = 'Already up to date';
    } else {
        $resultMessage = count($applied) . ' statement(s) applied';
    }

    echo 'Done. No row, column, or query result was changed - this only adds a lookup path.' . PHP_EOL;

    return [
        'success' => $failures === [],
        'applied' => $applied,
        'failures' => $failures,
        'message' => $resultMessage,
    ];
}

if (!defined('MIGRATE_RUNNER_ACTIVE')) {
    migrate_supplier_order_date_index(app_db());
}
