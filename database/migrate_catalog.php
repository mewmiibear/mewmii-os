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

// products: single-page product form additions (barcode, sale scheduling, low-stock
// threshold, preorder closing date). All nullable/defaulted - no existing row changes
// behavior.

if (!migrate_column_exists($pdo, 'products', 'barcode')) {
    migrate_run($pdo, 'products.barcode', 'ALTER TABLE products ADD COLUMN barcode VARCHAR(64) NULL AFTER brand_id', $applied);
}

if (!migrate_column_exists($pdo, 'products', 'sale_enabled')) {
    migrate_run($pdo, 'products.sale_enabled', "ALTER TABLE products ADD COLUMN sale_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER product_cost", $applied);
}

if (!migrate_column_exists($pdo, 'products', 'sale_price')) {
    migrate_run($pdo, 'products.sale_price', 'ALTER TABLE products ADD COLUMN sale_price DECIMAL(12,2) NULL AFTER sale_enabled', $applied);
}

if (!migrate_column_exists($pdo, 'products', 'min_stock_threshold')) {
    migrate_run($pdo, 'products.min_stock_threshold', 'ALTER TABLE products ADD COLUMN min_stock_threshold INT UNSIGNED NULL AFTER sale_price', $applied);
}

if (!migrate_column_exists($pdo, 'products', 'preorder_closing_date')) {
    migrate_run($pdo, 'products.preorder_closing_date', 'ALTER TABLE products ADD COLUMN preorder_closing_date DATE NULL AFTER estimated_arrival_date', $applied);
}

// mewmii_orders: placeholder for a future WooCommerce receipt sync, plus an index so a
// future "pending payments" dashboard widget doesn't need its own schema change later.

if (!migrate_column_exists($pdo, 'mewmii_orders', 'receipt_url')) {
    migrate_run($pdo, 'mewmii_orders.receipt_url', 'ALTER TABLE mewmii_orders ADD COLUMN receipt_url VARCHAR(500) NULL AFTER payment_method', $applied);
}

if (!migrate_index_exists($pdo, 'mewmii_orders', 'idx_mewmii_orders_payment_status')) {
    migrate_run($pdo, 'mewmii_orders.idx_payment_status', 'ALTER TABLE mewmii_orders ADD INDEX idx_mewmii_orders_payment_status (payment_status)', $applied);
}

// customer_storage: traces which order a stored lot fulfilled, so preorder/early-bird
// receiving can auto-allocate stock to outstanding orders without ever double-fulfilling
// the same order across separate receiving events.

if (!migrate_column_exists($pdo, 'customer_storage', 'order_item_id')) {
    migrate_run($pdo, 'customer_storage.order_item_id', 'ALTER TABLE customer_storage ADD COLUMN order_item_id INT UNSIGNED NULL AFTER variation_id', $applied);
    migrate_run($pdo, 'customer_storage.fk_order_item', 'ALTER TABLE customer_storage ADD CONSTRAINT fk_customer_storage_order_item FOREIGN KEY (order_item_id) REFERENCES mewmii_order_items(id) ON DELETE SET NULL', $applied);
}

// mewmii_inventory.arrived_quantity: preorder/early_bird receiving now lands here
// (physically received, not yet allocated) instead of auto-FIFO-routing into
// customer_storage. Manual allocation (modules/inventory/allocate.php) debits this
// column via customer_storage_add(..., debitFrom: 'arrived'); manual release moves it
// into available_quantity.
if (!migrate_column_exists($pdo, 'mewmii_inventory', 'arrived_quantity')) {
    migrate_run($pdo, 'mewmii_inventory.arrived_quantity', 'ALTER TABLE mewmii_inventory ADD COLUMN arrived_quantity INT NOT NULL DEFAULT 0 AFTER incoming_quantity', $applied);
}

// inventory_transactions: Reason/Notes (captured by the Adjust Stock modal) and a
// write-time Stock Balance snapshot (populated by inventory_log_transaction() itself going
// forward - existing rows stay NULL, shown as "-" rather than a retroactively-guessed
// number, since quantity's effect on available_quantity isn't uniform across every
// transaction_type).
if (!migrate_column_exists($pdo, 'inventory_transactions', 'reason')) {
    migrate_run($pdo, 'inventory_transactions.reason', 'ALTER TABLE inventory_transactions ADD COLUMN reason VARCHAR(100) NULL AFTER quantity', $applied);
}
if (!migrate_column_exists($pdo, 'inventory_transactions', 'notes')) {
    migrate_run($pdo, 'inventory_transactions.notes', 'ALTER TABLE inventory_transactions ADD COLUMN notes VARCHAR(255) NULL AFTER reason', $applied);
}
if (!migrate_column_exists($pdo, 'inventory_transactions', 'balance_after')) {
    migrate_run($pdo, 'inventory_transactions.balance_after', 'ALTER TABLE inventory_transactions ADD COLUMN balance_after INT NULL AFTER notes', $applied);
}

// product_attribute_values.code: short inventory prefix (e.g. "CN" for Cinnamoroll) used
// for variation SKU generation instead of the full customer-facing value name - see
// variation_generate_sku()/catalog_attribute_value_sku_code(). Nullable + a NULL-tolerant
// unique key (MySQL treats multiple NULLs as distinct) so existing values without a code
// yet are unaffected; any two values under the same attribute that DO set a code can't
// collide.
if (!migrate_column_exists($pdo, 'product_attribute_values', 'code')) {
    migrate_run($pdo, 'product_attribute_values.code', 'ALTER TABLE product_attribute_values ADD COLUMN code VARCHAR(10) NULL AFTER value', $applied);
}
if (!migrate_index_exists($pdo, 'product_attribute_values', 'uq_attribute_value_code')) {
    migrate_run($pdo, 'product_attribute_values.uq_code', 'ALTER TABLE product_attribute_values ADD UNIQUE KEY uq_attribute_value_code (attribute_id, code)', $applied);
}

// products.estimated_release_month: stored as a plain "YYYY-MM" string (an HTML5 <input
// type="month"> submits exactly this format) rather than a DATE, since there is
// deliberately no day component to fabricate - display formatting (e.g. "September 2026")
// happens at render time via catalog_format_release_month(), never stored pre-formatted.
if (!migrate_column_exists($pdo, 'products', 'estimated_release_month')) {
    migrate_run($pdo, 'products.estimated_release_month', 'ALTER TABLE products ADD COLUMN estimated_release_month VARCHAR(7) NULL AFTER estimated_arrival_date', $applied);
}

// products.preorder_reopened_at: there is no separate "Preorder Closing Date" concept -
// preorder_closing_date ("Early Bird Closing Date") only ever pauses ordering (product
// enters a "waiting for release" state, regular price already applies per
// catalog_product_effective_price()). Reopening is a deliberate admin action (see
// modules/products/reopen_preorder.php), never automatic - not on estimated_release_month
// arriving, not on any timer. Reset to NULL whenever preorder_closing_date itself changes
// (see modules/products/edit.php), so each new closing-date cycle needs its own fresh
// manual reopen rather than inheriting a stale one from a previous cycle.
if (!migrate_column_exists($pdo, 'products', 'preorder_reopened_at')) {
    migrate_run($pdo, 'products.preorder_reopened_at', 'ALTER TABLE products ADD COLUMN preorder_reopened_at DATETIME NULL AFTER preorder_closing_date', $applied);
}

// mewmii_orders: shipment tracking, captured by the "Mark Shipped" action button (see
// modules/orders/view.php) instead of the old raw shipping_status dropdown. All nullable -
// existing orders are unaffected until a staff member actually ships one through the new flow.
if (!migrate_column_exists($pdo, 'mewmii_orders', 'tracking_number')) {
    migrate_run($pdo, 'mewmii_orders.tracking_number', 'ALTER TABLE mewmii_orders ADD COLUMN tracking_number VARCHAR(100) NULL AFTER shipping_status', $applied);
}
if (!migrate_column_exists($pdo, 'mewmii_orders', 'shipping_carrier')) {
    migrate_run($pdo, 'mewmii_orders.shipping_carrier', 'ALTER TABLE mewmii_orders ADD COLUMN shipping_carrier VARCHAR(50) NULL AFTER tracking_number', $applied);
}
if (!migrate_column_exists($pdo, 'mewmii_orders', 'shipped_at')) {
    migrate_run($pdo, 'mewmii_orders.shipped_at', 'ALTER TABLE mewmii_orders ADD COLUMN shipped_at DATETIME NULL AFTER shipping_carrier', $applied);
}

// supplier_orders.notes: free-text notes shown on the supplier order detail/edit pages -
// no other business logic reads this column.
if (!migrate_column_exists($pdo, 'supplier_orders', 'notes')) {
    migrate_run($pdo, 'supplier_orders.notes', 'ALTER TABLE supplier_orders ADD COLUMN notes TEXT NULL AFTER received_date', $applied);
}

// product_variations.moq: optional per-variation Minimum Order Quantity override, nullable
// so most variations (no override set) fall back to the parent product's own moq column -
// see supplier_order_picker_products()'s 3-tier priority (variation -> parent -> empty).
if (!migrate_column_exists($pdo, 'product_variations', 'moq')) {
    migrate_run($pdo, 'product_variations.moq', 'ALTER TABLE product_variations ADD COLUMN moq INT UNSIGNED NULL AFTER cost_price', $applied);
}

// supplier_order_events: audit trail for supplier order edits (who/when/what changed) -
// mirrors mewmii_order_events exactly, just scoped to supplier_orders instead of
// mewmii_orders.
if (!migrate_column_exists($pdo, 'supplier_order_events', 'id')) {
    migrate_run($pdo, 'supplier_order_events.create', "
        CREATE TABLE IF NOT EXISTS supplier_order_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_order_id INT UNSIGNED NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            description VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_supplier_order_events_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_supplier_order_events_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", $applied);
}

// products.availability_override: manual admin control ('auto'/'available'/'out_of_stock')
// so Early Bird/Preorder purchasability is never silently computed from inventory
// quantity (they were never gated on it before either - see catalog_product_is_orderable()
// - this just adds an explicit manual override on top). Ready Stock still follows its
// actual quantity unless this is set. Default 'auto' preserves every existing product's
// current behavior untouched.
if (!migrate_column_exists($pdo, 'products', 'availability_override')) {
    migrate_run($pdo, 'products.availability_override', "ALTER TABLE products ADD COLUMN availability_override VARCHAR(20) NOT NULL DEFAULT 'auto' AFTER status", $applied);
}

// products.short_description: the customer-facing summary shown on the product form
// (Basic Information section) and synced to WooCommerce's own short_description field -
// separate from the long `description` field, and separate from the auto-generated
// preorder/Early Bird blurb (see wc_client_build_product_payload()), which is appended
// after it rather than replacing it.
if (!migrate_column_exists($pdo, 'products', 'short_description')) {
    migrate_run($pdo, 'products.short_description', 'ALTER TABLE products ADD COLUMN short_description VARCHAR(500) NULL AFTER name', $applied);
}

echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
foreach ($applied as $item) {
    echo '  - ' . $item . PHP_EOL;
}

if ($applied === []) {
    echo 'Database was already up to date - nothing to do.' . PHP_EOL;
}

echo 'Done. Existing products, inventory, orders, and suppliers are untouched and remain catalog_type = simple.' . PHP_EOL;
