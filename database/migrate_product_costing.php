<?php
/**
 * Sprint 13 (Product Import and Supplier Data Improvements) - schema prep for the future
 * "supplier currency -> exchange rate -> converted cost -> shipping allocation -> landed
 * cost" chain. Adds only the raw data columns; no calculation logic is wired up anywhere
 * yet (per the sprint's explicit "do not build complex calculation yet" scope) - existing
 * cost displays/sync/reports continue to read products.product_cost exactly as before.
 * Safe to run multiple times: every step checks INFORMATION_SCHEMA before altering anything.
 *
 * Run once via browser (https://yourdomain/database/migrate_product_costing.php) or CLI
 * (`php database/migrate_product_costing.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates these columns.
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

echo 'Mewmii OS product costing migration starting...' . PHP_EOL;

$applied = [];

// products.cost_currency: the currency product_cost was actually quoted in (e.g. from a
// CSV import's Currency column, or a supplier quote in USD/CNY/etc) - independent of
// suppliers.currency, since one product's negotiated cost can differ from that supplier's
// usual default. NULL means "same as the store's base currency" (today's implicit
// behavior - product_cost is always read as-is everywhere, unchanged).
if (!migrate_column_exists($pdo, 'products', 'cost_currency')) {
    migrate_run($pdo, 'products.cost_currency', 'ALTER TABLE products ADD COLUMN cost_currency VARCHAR(10) NULL AFTER product_cost', $applied);
}

// products.exchange_rate: the rate to convert cost_currency into the store's base currency
// - manually entered in a future phase, never auto-fetched from a live FX API (out of
// scope here). NULL means "not set / no conversion needed". Intended formula (not
// implemented yet): converted_cost = product_cost * COALESCE(exchange_rate, 1).
if (!migrate_column_exists($pdo, 'products', 'exchange_rate')) {
    migrate_run($pdo, 'products.exchange_rate', 'ALTER TABLE products ADD COLUMN exchange_rate DECIMAL(10,4) NULL AFTER cost_currency', $applied);
}

// supplier_order_items.shipping_allocated: this line's share of its supplier_orders.
// shipping_fee, entered/computed in a future phase - NULL means "not yet allocated".
// Intended formula (not implemented yet): landed_cost = (supplier_price *
// COALESCE(product.exchange_rate, 1)) + COALESCE(shipping_allocated, 0).
if (!migrate_column_exists($pdo, 'supplier_order_items', 'shipping_allocated')) {
    migrate_run($pdo, 'supplier_order_items.shipping_allocated', 'ALTER TABLE supplier_order_items ADD COLUMN shipping_allocated DECIMAL(12,2) NULL AFTER subtotal', $applied);
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

echo 'Done. No existing cost/pricing display, sync, or report logic was touched - product_cost remains authoritative.' . PHP_EOL;
