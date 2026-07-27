#!/usr/bin/env php
<?php

/**
 * CLI-only WooCommerce -> Mewmii OS product/variation import runner.
 *
 * Usage:
 *   php cli/wc_product_import.php                    Import for real.
 *   php cli/wc_product_import.php --dry-run           Hit the WooCommerce API and preview what
 *                                                      would happen, but make no database
 *                                                      writes at all.
 *   php cli/wc_product_import.php --batch-size=100    Process at most 100 products this run
 *                                                      instead of the default
 *                                                      WC_PRODUCT_IMPORT_BATCH_SIZE (see
 *                                                      includes/wc_product_import.php).
 *
 * A thin wrapper, matching cli/wc_order_sync.php exactly: this script decides WHEN
 * wc_product_import_run() (includes/wc_product_import.php) gets called and how its result is
 * reported to cron/the terminal - it has no import logic of its own. The same function is
 * called by the "Import Products Now" button on modules/integrations/woocommerce.php.
 *
 * Same CLI-only protection as cli/wc_order_sync.php: the PHP_SAPI check below stops this
 * from ever reaching bootstrap.php/the database under a web request, and cli/.htaccess
 * denies web access to this whole directory at the server level - either alone would be
 * enough, both together mean a misconfiguration in one doesn't leave the route open.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/wc_product_import.php';

function wc_product_import_cli_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL);
}

function wc_product_import_cli_error(string $message): void
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $message . PHP_EOL);
}

$dryRun = in_array('--dry-run', $argv, true);

$batchSize = WC_PRODUCT_IMPORT_BATCH_SIZE;
foreach ($argv as $arg) {
    if (strpos($arg, '--batch-size=') === 0) {
        $requested = (int) substr($arg, strlen('--batch-size='));
        if ($requested > 0) {
            $batchSize = $requested;
        }
    }
}

wc_product_import_cli_log('WooCommerce product import starting' . ($dryRun ? ' (DRY RUN - no database writes will be made)' : '') . " (batch size {$batchSize}).");

if (!wc_client_is_configured()) {
    wc_product_import_cli_error('WooCommerce API is not configured (check config.php woocommerce.url/consumer_key/consumer_secret).');
    exit(1);
}

$pdo = app_db();

try {
    $stats = wc_product_import_run($pdo, $dryRun, $batchSize);
} catch (RuntimeException $e) {
    if ($e->getCode() === WC_PRODUCT_IMPORT_LOCK_BUSY_CODE) {
        // Benign, expected condition - another sync (cron or the manual "Import Products Now"
        // button) is already running against the same lock. Not a failure: exits 0 so this
        // doesn't trip cron-failure monitoring on a routine overlap - the other, already-
        // running sync will complete normally. Same convention as cli/wc_order_sync.php.
        wc_product_import_cli_log($e->getMessage());
        exit(0);
    }

    if ($e->getCode() === WC_PRODUCT_IMPORT_MASTER_LOCAL_BLOCKED_CODE) {
        // Complete Mewmii OS master mode enforcement - expected, intentional state while
        // woocommerce.sync_mode = master_local, not a cron failure. Already logged to
        // sync_logs by wc_product_import_run() itself before it threw.
        wc_product_import_cli_log($e->getMessage());
        exit(0);
    }

    wc_product_import_cli_error('Unhandled exception during import: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    wc_product_import_cli_error('Unhandled exception during import: ' . $e->getMessage());
    exit(1);
}

wc_product_import_cli_log(sprintf(
    'Import finished%s - products: %d created, %d updated, %d skipped, %d failed | variations: %d created, %d updated | opening stock: %d set, %d already tracked | images: %d downloaded, %d failed',
    $dryRun ? ' (DRY RUN)' : '',
    $stats['products_created'], $stats['products_updated'], $stats['products_skipped'], $stats['products_failed'],
    $stats['variations_created'], $stats['variations_updated'],
    $stats['opening_stock_set'], $stats['opening_stock_skipped'],
    $stats['images_downloaded'], $stats['images_failed']
));

if ($stats['products_failed'] > 0) {
    // Per-product failures are tolerated by design, same convention as
    // cli/wc_order_sync.php's per-order failures - already visible via Recent Sync Activity
    // on modules/integrations/woocommerce.php, for follow-up.
    wc_product_import_cli_log('WARNING: ' . $stats['products_failed'] . ' product(s) failed to import individually - see Sync Logs for detail.');
}

wc_product_import_cli_log('WooCommerce product import completed' . ($dryRun ? ' (DRY RUN - no database writes were made)' : '') . '.');
exit(0);
