<?php
/**
 * One-time upgrade script for the WooCommerce push-sync improvements (ID-preferred product
 * matching, status mapping, skip-unchanged-products). Safe to run multiple times: the one
 * step here checks INFORMATION_SCHEMA before altering anything, and no existing row is ever
 * updated or deleted. Mirrors database/migrate_catalog.php's exact structure/conventions.
 *
 * Run once via browser (https://yourdomain/database/migrate_woocommerce_sync.php) or CLI
 * (`php database/migrate_woocommerce_sync.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates the full final
 * table shape via install.php.
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

function migrate_run(PDO $pdo, string $label, string $sql, array &$applied): void
{
    try {
        $pdo->exec($sql);
        $applied[] = $label;
    } catch (PDOException $exception) {
        echo '  ! FAILED: ' . $label . ' - ' . $exception->getMessage() . PHP_EOL;
    }
}

echo 'Mewmii OS WooCommerce sync migration starting...' . PHP_EOL;

$applied = [];

// products.woocommerce_sync_hash: fingerprint of the last successful "Sync to WooCommerce"
// push (see wc_client_product_sync_fingerprint() in includes/wc_client.php) - lets a bulk
// sync skip a product whose WooCommerce-relevant fields haven't changed, without an API call.
// NULL on every existing row until that product is next synced - never backfilled, since a
// NULL/mismatched hash is exactly what correctly forces the very next sync to actually run.
if (!migrate_column_exists($pdo, 'products', 'woocommerce_sync_hash')) {
    migrate_run($pdo, 'products.woocommerce_sync_hash', 'ALTER TABLE products ADD COLUMN woocommerce_sync_hash VARCHAR(64) NULL AFTER published_to_woocommerce', $applied);
}

echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
foreach ($applied as $item) {
    echo '  - ' . $item . PHP_EOL;
}

if ($applied === []) {
    echo 'Database was already up to date - nothing to do.' . PHP_EOL;
}
