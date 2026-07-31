<?php
/**
 * One-time upgrade script for the WooCommerce push-sync improvements (ID-preferred product
 * matching, status mapping, skip-unchanged-products). Safe to run multiple times: the one
 * step here checks INFORMATION_SCHEMA before altering anything, and no existing row is ever
 * updated or deleted. Mirrors database/migrate_catalog.php's exact structure/conventions.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_woocommerce_sync.php) or CLI
 * (`php database/migrate_woocommerce_sync.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates the full final
 * table shape via install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_woocommerce_sync(PDO $pdo): array
{
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

    // This file historically didn't call migrate_failures() at all (its own migrate_run()
    // variant didn't record into the shared registry) - now that migrate_run() is shared and
    // always records, we read it here too so the returned result is accurate. See
    // database/migrate_helpers.php's own docblock for why this is safe: it only adds
    // visibility this file never had before, never changes what it does.
    $failures = migrate_failures();

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
    migrate_woocommerce_sync(app_db());
}
