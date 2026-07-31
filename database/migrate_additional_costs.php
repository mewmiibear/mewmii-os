<?php
/**
 * Phase 7F (Additional Costs Framework) - adds the supplier_order_item_costs table backing
 * arbitrary named landed-cost components (Customs, Handling, Packaging, Other, or any type an
 * operator types in) allocated per supplier order line - see database/schema.sql for the full
 * rationale (same grain as Phase 7D's shipping_allocated column). Safe to run multiple times:
 * CREATE TABLE IF NOT EXISTS is a no-op if it already exists.
 *
 * Run via database/migrate.php (discovers and runs this in-process - see that file's own
 * docblock), or standalone via browser (https://yourdomain/database/migrate_additional_costs.php)
 * or CLI (`php database/migrate_additional_costs.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates this table via
 * install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_additional_costs(PDO $pdo): array
{
    echo 'Mewmii OS additional costs migration starting...' . PHP_EOL;

    $applied = [];

    // supplier_order_item_costs: one row per named cost on a specific supplier order line -
    // intentionally NOT one column per cost type, so a new cost type (beyond Customs/Handling/
    // Packaging/Other) never needs a schema change, just a new cost_type string.
    if (!migrate_column_exists($pdo, 'supplier_order_item_costs', 'id')) {
        migrate_run($pdo, 'supplier_order_item_costs.create', "
            CREATE TABLE IF NOT EXISTS supplier_order_item_costs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_order_item_id INT UNSIGNED NOT NULL,
                cost_type VARCHAR(50) NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_supplier_order_item_costs_item FOREIGN KEY (supplier_order_item_id) REFERENCES supplier_order_items(id) ON DELETE CASCADE,
                INDEX idx_supplier_order_item_costs_item (supplier_order_item_id)
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

    echo 'Done. No existing cost/pricing display, sync, or report logic was touched.' . PHP_EOL;

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
    migrate_additional_costs(app_db());
}
