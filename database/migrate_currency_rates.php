<?php
/**
 * Phase 9F.1 (Global Currency Exchange Rate Settings) - adds currency_rates, the single
 * centrally-managed table of "1 unit of this currency = ? MYR", replacing per-product manual
 * exchange rate entry. See includes/currency_rates.php for how this feeds
 * includes/pricing_engine.php and keeps products.exchange_rate (read by the actual Landed
 * Cost engine, includes/product_cost.php - deliberately untouched) in sync automatically.
 * Safe to run multiple times: every step checks INFORMATION_SCHEMA before altering anything,
 * and the seed rows are only inserted the first time the table itself is created.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_currency_rates.php) or CLI
 * (`php database/migrate_currency_rates.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates/seeds this via
 * install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_currency_rates(PDO $pdo): array
{
    echo 'Mewmii OS currency rates migration starting...' . PHP_EOL;

    $applied = [];

    $tableIsNew = !migrate_table_exists($pdo, 'currency_rates');

    if ($tableIsNew) {
        migrate_run($pdo, 'currency_rates.create', "
            CREATE TABLE IF NOT EXISTS currency_rates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                currency_code VARCHAR(10) NOT NULL UNIQUE,
                exchange_rate DECIMAL(12,6) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);

        // Seeded only on the run that actually creates the table - never re-inserted (and never
        // overwrites an admin's own edited rates) on a later re-run. MYR = 1.000000 is a fixed
        // invariant (base currency); JPY/HKD/USD use the example rates already given for this
        // catalogue - CNY is intentionally left for the admin to add via modules/settings/
        // currency_rates.php once a real rate is known, rather than guessing one here.
        if (in_array('currency_rates.create', $applied, true)) {
            migrate_run($pdo, 'currency_rates.seed', "
                INSERT INTO currency_rates (currency_code, exchange_rate) VALUES
                    ('MYR', 1.000000),
                    ('JPY', 0.026000),
                    ('HKD', 0.580000),
                    ('USD', 4.200000)
            ", $applied);
        }
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

    echo 'Done. No existing product_cost.php actual Landed Cost, cost history, supplier order costing, or inventory valuation logic was touched.' . PHP_EOL;

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
    migrate_currency_rates(app_db());
}
