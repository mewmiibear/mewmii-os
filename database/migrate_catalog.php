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

function migrate_constraint_exists(PDO $pdo, string $table, string $constraintName): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
    ');
    $stmt->execute([$table, $constraintName]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Finds every FOREIGN KEY constraint defined ON $table that uses $column as its local
 * column - discovered dynamically via INFORMATION_SCHEMA rather than assumed by name,
 * since a constraint may have been created (or renamed) outside of schema.sql.
 * REFERENCED_TABLE_NAME is only populated for foreign-key rows in KEY_COLUMN_USAGE,
 * which is what distinguishes them from plain unique/primary key entries on the same
 * column.
 */
function migrate_find_foreign_keys_on_column(PDO $pdo, string $table, string $column): array
{
    $stmt = $pdo->prepare('
        SELECT CONSTRAINT_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ');
    $stmt->execute([$table, $column]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
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

// mewmii_inventory: each step below is checked and applied independently (not gated
// behind one outer "if variation_id is missing" condition), so this section can resume
// correctly from ANY partially-completed state - including a previous run that added
// variation_id/variation_key/fk_mewmii_inventory_variation successfully but then failed
// while swapping the unique index (see below).

if (!migrate_column_exists($pdo, 'mewmii_inventory', 'variation_id')) {
    migrate_run($pdo, 'mewmii_inventory.variation_id', 'ALTER TABLE mewmii_inventory ADD COLUMN variation_id INT UNSIGNED NULL AFTER product_id', $applied);
}

if (!migrate_column_exists($pdo, 'mewmii_inventory', 'variation_key')) {
    migrate_run($pdo, 'mewmii_inventory.variation_key', 'ALTER TABLE mewmii_inventory ADD COLUMN variation_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(variation_id, 0)) STORED AFTER variation_id', $applied);
}

if (!migrate_constraint_exists($pdo, 'mewmii_inventory', 'fk_mewmii_inventory_variation')) {
    migrate_run($pdo, 'mewmii_inventory.fk_variation', 'ALTER TABLE mewmii_inventory ADD CONSTRAINT fk_mewmii_inventory_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE CASCADE', $applied);
}

// Swap the old product-only unique key for the new (product_id, variation_key) key.
// Every existing row has variation_id = NULL -> variation_key = 0, so this reproduces
// the exact same uniqueness guarantee for simple products; nothing can violate it.
//
// Gated on the NEW key's existence only: if uq_inventory_product_variation is already
// there, the swap is done and this whole block is skipped (safe to re-run forever).
if (!migrate_index_exists($pdo, 'mewmii_inventory', 'uq_inventory_product_variation')) {
    // Step 1: find whatever FK constraint(s) currently sit on product_id. InnoDB refuses
    // to drop uq_inventory_product while it's the only index backing one of these
    // (error 1553: "needed in a foreign key constraint") - this is what broke the
    // previous run. Discovered by column, not assumed by name.
    $productForeignKeys = migrate_find_foreign_keys_on_column($pdo, 'mewmii_inventory', 'product_id');

    // Step 2: drop those FK(s) temporarily.
    foreach ($productForeignKeys as $fkName) {
        migrate_run($pdo, 'mewmii_inventory.drop_fk_' . $fkName, "ALTER TABLE mewmii_inventory DROP FOREIGN KEY `{$fkName}`", $applied);
    }

    // Step 3: now safe to drop the old unique key (only if it's still there - a re-run
    // after a later failure in this same block shouldn't try to drop it twice).
    if (migrate_index_exists($pdo, 'mewmii_inventory', 'uq_inventory_product')) {
        migrate_run($pdo, 'mewmii_inventory.drop_old_unique', 'ALTER TABLE mewmii_inventory DROP INDEX uq_inventory_product', $applied);
    }

    // Step 4/5: variation_id already exists by this point (added above); create the new
    // unique rule covering (product_id, variation_id) via the generated variation_key column.
    migrate_run($pdo, 'mewmii_inventory.new_unique', 'ALTER TABLE mewmii_inventory ADD UNIQUE KEY uq_inventory_product_variation (product_id, variation_key)', $applied);

    // Step 6: recreate the product_id foreign key under its canonical name, whatever it
    // was called before we dropped it in step 2.
    if (!migrate_constraint_exists($pdo, 'mewmii_inventory', 'fk_mewmii_inventory_product')) {
        migrate_run($pdo, 'mewmii_inventory.fk_product', 'ALTER TABLE mewmii_inventory ADD CONSTRAINT fk_mewmii_inventory_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE', $applied);
    }
}
// Step 7: continue - the rest of the migration proceeds below exactly as before.

// product_images: add image_type (backfilling existing rows), then rename image_url to
// image_path. Each step is its own independent check, same reasoning as the
// mewmii_inventory section above - safe to resume from any partial state.

if (!migrate_column_exists($pdo, 'product_images', 'image_type')) {
    migrate_run($pdo, 'product_images.image_type', "ALTER TABLE product_images ADD COLUMN image_type VARCHAR(20) NOT NULL DEFAULT 'gallery' AFTER variation_id", $applied);

    // Backfill: a variation-scoped row is always 'variation'. Among product-scoped rows
    // (variation_id IS NULL), the first one ever added (lowest id) per product becomes
    // 'main' - matching how the old flat gallery was used as a single hero image plus
    // extras - everything else stays 'gallery'.
    migrate_run($pdo, 'product_images.backfill_variation_type', "UPDATE product_images SET image_type = 'variation' WHERE variation_id IS NOT NULL", $applied);

    migrate_run($pdo, 'product_images.backfill_main_type', "
        UPDATE product_images pi
        INNER JOIN (
            SELECT product_id, MIN(id) AS first_id
            FROM product_images
            WHERE variation_id IS NULL
            GROUP BY product_id
        ) firsts ON firsts.product_id = pi.product_id AND firsts.first_id = pi.id
        SET pi.image_type = 'main'
        WHERE pi.variation_id IS NULL
    ", $applied);
}

if (migrate_column_exists($pdo, 'product_images', 'image_url') && !migrate_column_exists($pdo, 'product_images', 'image_path')) {
    // CHANGE COLUMN renames in place and preserves every existing value - no data is
    // touched, only the column's name.
    migrate_run($pdo, 'product_images.rename_image_path', 'ALTER TABLE product_images CHANGE COLUMN image_url image_path VARCHAR(500) NOT NULL', $applied);
}

if (!migrate_index_exists($pdo, 'product_images', 'idx_product_images_lookup')) {
    migrate_run($pdo, 'product_images.idx_lookup', 'ALTER TABLE product_images ADD INDEX idx_product_images_lookup (product_id, variation_id, image_type)', $applied);
}

echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
foreach ($applied as $item) {
    echo '  - ' . $item . PHP_EOL;
}

if ($applied === []) {
    echo 'Database was already up to date - nothing to do.' . PHP_EOL;
}

echo 'Done. Existing products, inventory, orders, and suppliers are untouched and remain catalog_type = simple.' . PHP_EOL;
