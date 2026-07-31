<?php
/**
 * WooCommerce delete-webhook support - adds customers.archived_at (see database/schema.sql).
 * Products already have a reusable archive path (products.status = 'archived', see
 * includes/catalog.php's product_deactivate()) and orders already have one (mewmii_orders.
 * order_status = 'cancelled', the same status the existing "Cancel Order" action in
 * modules/orders/view.php sets) - neither needed a schema change. Customers had no equivalent
 * at all before this: no status/archived/deleted column of any kind on the `customers` table.
 * This is the one, minimal column needed to represent "WooCommerce told us this customer was
 * deleted" without a new parallel delete system - NULL means active, a set timestamp means
 * archived, matching product_variations.archived_at's existing convention exactly.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_customer_delete_lifecycle.php) or CLI
 * (`php database/migrate_customer_delete_lifecycle.php`) against an EXISTING Mewmii OS
 * database. Brand-new installs don't need this - database/schema.sql already creates this
 * column via install.php. Safe to run multiple times: checks INFORMATION_SCHEMA first.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_customer_delete_lifecycle(PDO $pdo): array
{
    echo 'Mewmii OS customer delete-lifecycle migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_column_exists($pdo, 'customers', 'archived_at')) {
        migrate_run($pdo, 'customers.archived_at', 'ALTER TABLE customers ADD COLUMN archived_at TIMESTAMP NULL AFTER notes', $applied);
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

    echo 'Done. No existing order, inventory, product, or supplier order logic was touched.' . PHP_EOL;

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
    migrate_customer_delete_lifecycle(app_db());
}
