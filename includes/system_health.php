<?php

/**
 * System Health (Issue 5 - Production Migration Safety). We've now hit two separate
 * incidents where code was deployed expecting a database column/table that a migration
 * script existed for, but was never actually run against production: saved_views (missing
 * table) and products.woocommerce_sync_hash (missing column). This is a lightweight,
 * read-only check - not a new migration runner or tracking table - that answers "does this
 * database actually have what the code currently expects" at a glance, so a gap like either
 * of those two is caught by looking at a page instead of by a production error.
 *
 * Each entry names one migration script and ONE representative column/table it adds - since
 * every migrate_*.php script here runs as a single all-or-nothing sitting (see each script's
 * own docblock), checking one artifact it creates is a reliable proxy for "was this whole
 * script run", without needing to duplicate every column of every migration into a second,
 * parallel manifest that could itself drift out of sync.
 */

const SYSTEM_HEALTH_MIGRATIONS = [
    ['label' => 'Catalog overhaul (attributes, variations, brands/categories/collections)', 'migration' => 'migrate_catalog.php', 'table' => 'products', 'column' => 'catalog_type'],
    ['label' => 'Catalogue Manager metadata (brand/category/collection descriptions+images)', 'migration' => 'migrate_catalog_management.php', 'table' => 'brands', 'column' => 'description'],
    ['label' => 'WooCommerce push sync (fingerprint/staleness)', 'migration' => 'migrate_woocommerce_sync.php', 'table' => 'products', 'column' => 'woocommerce_sync_hash'],
    ['label' => 'Production hardening (indexes, customer dedup)', 'migration' => 'migrate_production_hardening.php', 'table' => 'customers', 'column' => 'woocommerce_customer_id'],
    ['label' => 'Saved Views', 'migration' => 'migrate_saved_views.php', 'table' => 'saved_views', 'column' => null],
    ['label' => 'Product costing prep', 'migration' => 'migrate_product_costing.php', 'table' => 'products', 'column' => 'cost_currency'],
];

// A subset of migrate_production_hardening.php's own performance indexes - grouped as one
// health-check line ("required indexes") rather than one row each, since they were all
// added by that single migration and matter here as a set, not individually.
const SYSTEM_HEALTH_INDEXES = [
    ['table' => 'customers', 'index' => 'idx_customers_email'],
    ['table' => 'customers', 'index' => 'uq_customers_woocommerce_customer_id'],
    ['table' => 'mewmii_orders', 'index' => 'idx_mewmii_orders_order_status'],
    ['table' => 'mewmii_orders', 'index' => 'idx_mewmii_orders_order_date'],
    ['table' => 'mewmii_orders', 'index' => 'idx_mewmii_orders_created_at'],
    ['table' => 'inventory_transactions', 'index' => 'idx_inventory_transactions_reference'],
    ['table' => 'supplier_orders', 'index' => 'idx_supplier_orders_status'],
    ['table' => 'supplier_orders', 'index' => 'idx_supplier_orders_payment_status'],
    ['table' => 'supplier_orders', 'index' => 'idx_supplier_orders_expected_delivery_date'],
    ['table' => 'products', 'index' => 'idx_products_status'],
    ['table' => 'products', 'index' => 'idx_products_product_type'],
];

function system_health_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function system_health_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ');
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() > 0;
}

function system_health_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ');
    $stmt->execute([$table, $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return array{migrations: array, indexes: array{present: int, total: int, missing: array}, pending: array}
 */
function system_health_check(PDO $pdo): array
{
    $migrations = [];
    $pending = [];

    foreach (SYSTEM_HEALTH_MIGRATIONS as $check) {
        $applied = $check['column'] !== null
            ? system_health_column_exists($pdo, $check['table'], $check['column'])
            : system_health_table_exists($pdo, $check['table']);

        $migrations[] = array_merge($check, ['applied' => $applied]);

        if (!$applied) {
            $pending[] = $check['migration'];
        }
    }

    $missingIndexes = [];
    foreach (SYSTEM_HEALTH_INDEXES as $indexCheck) {
        if (!system_health_index_exists($pdo, $indexCheck['table'], $indexCheck['index'])) {
            $missingIndexes[] = $indexCheck['table'] . '.' . $indexCheck['index'];
        }
    }
    if ($missingIndexes !== [] && !in_array('migrate_production_hardening.php', $pending, true)) {
        $pending[] = 'migrate_production_hardening.php';
    }

    return [
        'migrations' => $migrations,
        'indexes' => [
            'present' => count(SYSTEM_HEALTH_INDEXES) - count($missingIndexes),
            'total' => count(SYSTEM_HEALTH_INDEXES),
            'missing' => $missingIndexes,
        ],
        'pending' => array_values(array_unique($pending)),
    ];
}
