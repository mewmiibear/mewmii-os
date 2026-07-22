<?php
/**
 * One-time upgrade script for the product catalog overhaul (simple/variable products,
 * attributes, variations, variation templates). Safe to run multiple times: every step
 * checks INFORMATION_SCHEMA before altering anything, and no existing row is ever
 * updated or deleted.
 *
 * Run once via browser (https://yourdomain/database/migrate_catalog.php) or CLI
 * (`php database/migrate_catalog.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates the full
 * final table shape via install.php.
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

function migrate_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ');
    $stmt->execute([$table, $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrate_run(PDO $pdo, string $label, string $sql, array &$applied): void
{
    $pdo->exec($sql);
    $applied[] = $label;
}

echo 'Mewmii OS catalog migration starting...' . PHP_EOL;

// Step 1: make sure every brand-new catalog table exists. CREATE TABLE IF NOT EXISTS is
// a no-op against tables that already exist, so this never touches existing data.
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
echo 'Step 1: base + new catalog tables ensured (CREATE TABLE IF NOT EXISTS).' . PHP_EOL;

$applied = [];

// Step 2: add the new columns to tables that already existed before this overhaul.
// Each block is guarded by a column-existence check, so re-running this script is safe.

if (!migrate_column_exists($pdo, 'products', 'catalog_type')) {
    // NOT NULL DEFAULT 'simple' backfills every existing row to 'simple' automatically -
    // no separate UPDATE needed, and no existing product's behavior changes.
    migrate_run($pdo, 'products.catalog_type', "ALTER TABLE products ADD COLUMN catalog_type VARCHAR(20) NOT NULL DEFAULT 'simple' AFTER product_type", $applied);
    migrate_run($pdo, 'products.idx_catalog_type', 'ALTER TABLE products ADD INDEX idx_products_catalog_type (catalog_type)', $applied);
}

if (!migrate_column_exists($pdo, 'products', 'brand_id')) {
    migrate_run($pdo, 'products.brand_id', 'ALTER TABLE products ADD COLUMN brand_id INT UNSIGNED NULL AFTER catalog_type', $applied);
    migrate_run($pdo, 'products.fk_products_brand', 'ALTER TABLE products ADD CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL', $applied);
}

if (!migrate_column_exists($pdo, 'product_images', 'variation_id')) {
    migrate_run($pdo, 'product_images.variation_id', 'ALTER TABLE product_images ADD COLUMN variation_id INT UNSIGNED NULL AFTER product_id', $applied);
    migrate_run($pdo, 'product_images.fk_variation', 'ALTER TABLE product_images ADD CONSTRAINT fk_product_images_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE CASCADE', $applied);
}

if (!migrate_column_exists($pdo, 'mewmii_order_items', 'variation_id')) {
    migrate_run($pdo, 'mewmii_order_items.variation_id', 'ALTER TABLE mewmii_order_items ADD COLUMN variation_id INT UNSIGNED NULL AFTER product_id', $applied);
    migrate_run($pdo, 'mewmii_order_items.variation_label', 'ALTER TABLE mewmii_order_items ADD COLUMN variation_label VARCHAR(255) NULL AFTER variation_id', $applied);
    migrate_run($pdo, 'mewmii_order_items.fk_variation', 'ALTER TABLE mewmii_order_items ADD CONSTRAINT fk_mewmii_order_items_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id)', $applied);
}

if (!migrate_column_exists($pdo, 'supplier_order_items', 'variation_id')) {
    migrate_run($pdo, 'supplier_order_items.variation_id', 'ALTER TABLE supplier_order_items ADD COLUMN variation_id INT UNSIGNED NULL AFTER product_id', $applied);
    migrate_run($pdo, 'supplier_order_items.fk_variation', 'ALTER TABLE supplier_order_items ADD CONSTRAINT fk_supplier_order_items_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id)', $applied);
}

if (!migrate_column_exists($pdo, 'customer_storage', 'variation_id')) {
    migrate_run($pdo, 'customer_storage.variation_id', 'ALTER TABLE customer_storage ADD COLUMN variation_id INT UNSIGNED NULL AFTER product_id', $applied);
    migrate_run($pdo, 'customer_storage.fk_variation', 'ALTER TABLE customer_storage ADD CONSTRAINT fk_customer_storage_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id)', $applied);
}

if (!migrate_column_exists($pdo, 'inventory_transactions', 'variation_id')) {
    migrate_run($pdo, 'inventory_transactions.variation_id', 'ALTER TABLE inventory_transactions ADD COLUMN variation_id INT UNSIGNED NULL AFTER product_id', $applied);
    migrate_run($pdo, 'inventory_transactions.fk_variation', 'ALTER TABLE inventory_transactions ADD CONSTRAINT fk_inventory_transactions_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE SET NULL', $applied);
}

if (!migrate_column_exists($pdo, 'mewmii_inventory', 'variation_id')) {
    migrate_run($pdo, 'mewmii_inventory.variation_id', 'ALTER TABLE mewmii_inventory ADD COLUMN variation_id INT UNSIGNED NULL AFTER product_id', $applied);
    migrate_run($pdo, 'mewmii_inventory.variation_key', 'ALTER TABLE mewmii_inventory ADD COLUMN variation_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(variation_id, 0)) STORED AFTER variation_id', $applied);
    migrate_run($pdo, 'mewmii_inventory.fk_variation', 'ALTER TABLE mewmii_inventory ADD CONSTRAINT fk_mewmii_inventory_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE CASCADE', $applied);

    // Swap the old product-only unique key for the new (product_id, variation_key) key.
    // Every existing row has variation_id = NULL -> variation_key = 0, so this reproduces
    // the exact same uniqueness guarantee for simple products; nothing can violate it.
    if (migrate_index_exists($pdo, 'mewmii_inventory', 'uq_inventory_product')) {
        migrate_run($pdo, 'mewmii_inventory.drop_old_unique', 'ALTER TABLE mewmii_inventory DROP INDEX uq_inventory_product', $applied);
    }
    if (!migrate_index_exists($pdo, 'mewmii_inventory', 'uq_inventory_product_variation')) {
        migrate_run($pdo, 'mewmii_inventory.new_unique', 'ALTER TABLE mewmii_inventory ADD UNIQUE KEY uq_inventory_product_variation (product_id, variation_key)', $applied);
    }
}

echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
foreach ($applied as $item) {
    echo '  - ' . $item . PHP_EOL;
}

if ($applied === []) {
    echo 'Database was already up to date - nothing to do.' . PHP_EOL;
}

echo 'Done. Existing products, inventory, orders, and suppliers are untouched and remain catalog_type = simple.' . PHP_EOL;
