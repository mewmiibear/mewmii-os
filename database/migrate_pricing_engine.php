<?php
/**
 * Phase 9D (Pricing Engine) - adds shipping_rate_countries (configurable per-country
 * RM/gram shipping rate) and products.original_price/original_currency/original_exchange_rate,
 * market_price/market_currency/market_exchange_rate, selling_multiplier, weight_grams,
 * shipping_origin_country_id. Does NOT touch products.product_cost/cost_currency/exchange_rate
 * (Supplier Price, reused as-is) or products.selling_price (final price, reused as-is) - see
 * includes/pricing_engine.php for the formulas these columns feed. Safe to run multiple times:
 * every step checks INFORMATION_SCHEMA before altering anything.
 *
 * Run once via browser (https://yourdomain/database/migrate_pricing_engine.php) or CLI
 * (`php database/migrate_pricing_engine.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates these via install.php.
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

function migrate_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ');
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrate_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ');
    $stmt->execute([$table, $index]);

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

echo 'Mewmii OS pricing engine migration starting...' . PHP_EOL;

$applied = [];

// shipping_rate_countries - configurable RM/gram rate per shipping origin, never hardcoded
// countries in PHP. Created before the products.shipping_origin_country_id FK below.
if (!migrate_table_exists($pdo, 'shipping_rate_countries')) {
    migrate_run($pdo, 'shipping_rate_countries.create', "
        CREATE TABLE IF NOT EXISTS shipping_rate_countries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            country_name VARCHAR(100) NOT NULL UNIQUE,
            rate_per_gram DECIMAL(10,4) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", $applied);
}

// Original Price (brand/official retail reference - discount comparison + Recommended
// Selling Price formula) and Market Price (competitor reference) - both reference-only,
// never read by includes/product_cost.php's actual Landed Cost engine.
if (!migrate_column_exists($pdo, 'products', 'original_price')) {
    migrate_run($pdo, 'products.original_price', 'ALTER TABLE products ADD COLUMN original_price DECIMAL(12,2) NULL AFTER exchange_rate', $applied);
}
if (!migrate_column_exists($pdo, 'products', 'original_currency')) {
    migrate_run($pdo, 'products.original_currency', 'ALTER TABLE products ADD COLUMN original_currency VARCHAR(10) NULL AFTER original_price', $applied);
}
if (!migrate_column_exists($pdo, 'products', 'original_exchange_rate')) {
    migrate_run($pdo, 'products.original_exchange_rate', 'ALTER TABLE products ADD COLUMN original_exchange_rate DECIMAL(10,4) NULL AFTER original_currency', $applied);
}
if (!migrate_column_exists($pdo, 'products', 'market_price')) {
    migrate_run($pdo, 'products.market_price', 'ALTER TABLE products ADD COLUMN market_price DECIMAL(12,2) NULL AFTER original_exchange_rate', $applied);
}
if (!migrate_column_exists($pdo, 'products', 'market_currency')) {
    migrate_run($pdo, 'products.market_currency', 'ALTER TABLE products ADD COLUMN market_currency VARCHAR(10) NULL AFTER market_price', $applied);
}
if (!migrate_column_exists($pdo, 'products', 'market_exchange_rate')) {
    migrate_run($pdo, 'products.market_exchange_rate', 'ALTER TABLE products ADD COLUMN market_exchange_rate DECIMAL(10,4) NULL AFTER market_currency', $applied);
}

// Recommended Selling Price = Original Price (MYR) x selling_multiplier - a live calculated
// display only (includes/pricing_engine.php). products.selling_price remains the single
// stored/editable final price; no selling_price_override column is added.
if (!migrate_column_exists($pdo, 'products', 'selling_multiplier')) {
    migrate_run($pdo, 'products.selling_multiplier', 'ALTER TABLE products ADD COLUMN selling_multiplier DECIMAL(6,2) NULL AFTER market_exchange_rate', $applied);
}

// Weight (simple products only - variable products keep using product_variations.weight per
// variation) and Shipping Origin, for Estimated Shipping Cost = weight_grams x
// shipping_rate_countries.rate_per_gram.
if (!migrate_column_exists($pdo, 'products', 'weight_grams')) {
    migrate_run($pdo, 'products.weight_grams', 'ALTER TABLE products ADD COLUMN weight_grams DECIMAL(10,2) NULL AFTER selling_multiplier', $applied);
}
if (!migrate_column_exists($pdo, 'products', 'shipping_origin_country_id')) {
    migrate_run($pdo, 'products.shipping_origin_country_id', 'ALTER TABLE products ADD COLUMN shipping_origin_country_id INT UNSIGNED NULL AFTER weight_grams', $applied);
}

// FK checked independently of the column above (not bundled into the same if-block) - a
// partial prior run (column added, FK statement failed) must still get the FK added on a
// re-run rather than being silently skipped. MySQL names the auto-created index after the
// constraint, so migrate_index_exists() on that name doubles as "was this FK already added".
if (migrate_column_exists($pdo, 'products', 'shipping_origin_country_id')
    && !migrate_index_exists($pdo, 'products', 'fk_products_shipping_origin_country')
) {
    migrate_run($pdo, 'products.fk_shipping_origin_country', 'ALTER TABLE products ADD CONSTRAINT fk_products_shipping_origin_country FOREIGN KEY (shipping_origin_country_id) REFERENCES shipping_rate_countries(id) ON DELETE SET NULL', $applied);
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

echo 'Done. No existing product_cost/cost_currency/exchange_rate/selling_price, purchasing, inventory, supplier order, or actual Landed Cost logic was touched.' . PHP_EOL;
