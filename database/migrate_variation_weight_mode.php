<?php
/**
 * Phase 9E (Product Weight & Variation SKU Logic) - adds product_variations.weight_mode.
 * Reuses the existing product_variations.weight (DECIMAL(10,3), added long before this phase)
 * as the "custom weight" value instead of adding a second weight_grams column - weight_mode
 * just decides whether that existing column or the parent product's weight_grams (Phase 9D)
 * applies, the exact same 'inherit'/'custom' shape already used by price_mode/custom_price.
 * product_variations.supplier_sku (also pre-existing) is reused as-is for the variation-level
 * Supplier SKU override - no new column needed for that either. Safe to run multiple times:
 * every step checks INFORMATION_SCHEMA before altering anything.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_variation_weight_mode.php) or CLI
 * (`php database/migrate_variation_weight_mode.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates this column via
 * install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_variation_weight_mode(PDO $pdo): array
{
    echo 'Mewmii OS variation weight mode migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_column_exists($pdo, 'product_variations', 'weight_mode')) {
        migrate_run($pdo, 'product_variations.weight_mode', "ALTER TABLE product_variations ADD COLUMN weight_mode VARCHAR(20) NOT NULL DEFAULT 'inherit' AFTER weight", $applied);

        // Backfill only immediately after adding the column for the first time (not on every
        // idempotent re-run) - any variation that already has a real weight on file was set
        // before this "inherit from parent" concept existed, so it must keep behaving as an
        // explicit override rather than silently starting to fall back to the parent product's
        // weight_grams the moment this migration runs. A later re-run must never re-force this,
        // since an admin may have deliberately switched a variation back to 'inherit' since.
        if (in_array('product_variations.weight_mode', $applied, true)) {
            migrate_run($pdo, 'product_variations.weight_mode_backfill', "UPDATE product_variations SET weight_mode = 'custom' WHERE weight IS NOT NULL", $applied);
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

    echo 'Done. No existing inventory, orders, supplier order, purchase planning, or product costing logic was touched.' . PHP_EOL;

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
    migrate_variation_weight_mode(app_db());
}
