<?php
/**
 * Phase 6B (Supplier Order currency) - schema prep so a Supplier Order can be placed in a
 * supplier's own currency (JPY/CNY/USD/etc) while MYR stays the system's reporting currency.
 * Adds only the raw data columns; existing cost/receiving/inventory-valuation logic keeps
 * reading supplier_orders.estimated_cost/actual_cost and supplier_order_items.supplier_price
 * exactly as before - those remain the authoritative MYR figures everywhere. Safe to run
 * multiple times: every step checks INFORMATION_SCHEMA before altering anything, and no
 * existing row is ever updated or deleted.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_supplier_order_currency.php) or CLI
 * (`php database/migrate_supplier_order_currency.php`) against an EXISTING Mewmii OS
 * database. Brand-new installs don't need this - database/schema.sql already creates these
 * columns.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_supplier_order_currency(PDO $pdo): array
{
    echo 'Mewmii OS supplier order currency migration starting...' . PHP_EOL;

    $applied = [];

    // supplier_orders.currency: the supplier's own quoted currency for this PO. DEFAULT 'MYR' so
    // every existing (pre-migration) row reads as MYR - the exact same currency it was always
    // implicitly denominated in, no data backfill needed.
    if (!migrate_column_exists($pdo, 'supplier_orders', 'currency')) {
        migrate_run($pdo, 'supplier_orders.currency', "ALTER TABLE supplier_orders ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'MYR' AFTER shipping_fee", $applied);
    }

    // supplier_orders.exchange_rate: rate to convert `currency` into MYR. NULL means "not set /
    // no conversion needed" (mirrors products.exchange_rate's existing convention) - every
    // existing row is NULL, which every read site treats as COALESCE(exchange_rate, 1), i.e. an
    // effective rate of 1 for its (now-labeled) MYR currency. Never auto-fetched from a live FX
    // API - manually entered per order.
    if (!migrate_column_exists($pdo, 'supplier_orders', 'exchange_rate')) {
        migrate_run($pdo, 'supplier_orders.exchange_rate', 'ALTER TABLE supplier_orders ADD COLUMN exchange_rate DECIMAL(12,6) NULL AFTER currency', $applied);
    }

    // supplier_orders.foreign_total: SUM(quantity * unit_cost_foreign) across the order's lines,
    // in `currency` - the supplier-facing total shown alongside the existing MYR
    // estimated_cost/Total Purchase Amount, never replacing either.
    if (!migrate_column_exists($pdo, 'supplier_orders', 'foreign_total')) {
        migrate_run($pdo, 'supplier_orders.foreign_total', 'ALTER TABLE supplier_orders ADD COLUMN foreign_total DECIMAL(12,2) NULL AFTER exchange_rate', $applied);
    }

    // supplier_order_items.unit_cost_foreign / unit_cost_myr: this line's unit cost as entered
    // (in the parent order's currency) and that same cost converted to MYR. unit_cost_myr is
    // always equal to supplier_price - kept as its own column purely so the conversion is
    // visible without re-deriving it; supplier_price remains the one column every existing
    // receiving/inventory/cost-change-log calculation reads. Both NULL for a plain MYR line.
    if (!migrate_column_exists($pdo, 'supplier_order_items', 'unit_cost_foreign')) {
        migrate_run($pdo, 'supplier_order_items.unit_cost_foreign', 'ALTER TABLE supplier_order_items ADD COLUMN unit_cost_foreign DECIMAL(12,2) NULL AFTER supplier_price', $applied);
    }
    if (!migrate_column_exists($pdo, 'supplier_order_items', 'unit_cost_myr')) {
        migrate_run($pdo, 'supplier_order_items.unit_cost_myr', 'ALTER TABLE supplier_order_items ADD COLUMN unit_cost_myr DECIMAL(12,2) NULL AFTER unit_cost_foreign', $applied);
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

    echo 'Done. Every existing supplier order now reads as currency=MYR with no conversion needed - estimated_cost/actual_cost/supplier_price remain authoritative everywhere unchanged.' . PHP_EOL;

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
    migrate_supplier_order_currency(app_db());
}
