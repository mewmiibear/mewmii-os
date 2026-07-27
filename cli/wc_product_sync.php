#!/usr/bin/env php
<?php

/**
 * CLI-only WooCommerce product PUSH sync runner (Full-automation pass).
 *
 * Same shape and safety layers as cli/wc_order_sync.php/cli/wc_webhook_process.php: the
 * PHP_SAPI check below plus cli/.htaccess are what keep this off the web, and this script has
 * no business logic of its own - it only decides WHEN wc_client_sync_all_products()
 * (includes/wc_client.php) runs and how its result is reported to cron.
 *
 * This is the automatic counterpart of the existing manual "Sync to WooCommerce" button
 * (modules/products/sync.php) - both now call the exact same function, so running this on a
 * schedule (e.g. every 15-30 minutes) is what makes a product edit that was saved while
 * WooCommerce was briefly unreachable (or any other product whose last push failed or was never
 * attempted) get retried without a human having to click the button. The fingerprint gate inside
 * wc_client_sync_if_changed() means an unrelated cron tick costs nothing for products that are
 * already in sync - only changed/never-synced/previously-failed products make an API call.
 *
 * Intended to be invoked by a Hostinger cron job running the PHP CLI binary directly against
 * this file's path - never over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/wc_client.php';

function wc_product_sync_cli_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL);
}

function wc_product_sync_cli_error(string $message): void
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $message . PHP_EOL);
}

wc_product_sync_cli_log('WooCommerce product sync starting.');

if (!wc_client_is_configured()) {
    wc_product_sync_cli_error('WooCommerce API is not configured (check config.php woocommerce.url/consumer_key/consumer_secret).');
    exit(1);
}

$pdo = app_db();

try {
    $summary = wc_client_sync_all_products($pdo);
} catch (RuntimeException $e) {
    if ($e->getCode() === WC_CLIENT_PRODUCT_SYNC_LOCK_BUSY_CODE) {
        // Benign, expected condition - another sync (cron or the manual "Sync to WooCommerce"
        // button) is already running against the same lock. Not a failure.
        wc_product_sync_cli_log($e->getMessage());
        exit(0);
    }

    wc_product_sync_cli_error('Unhandled exception during sync: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    wc_product_sync_cli_error('Unhandled exception during sync: ' . $e->getMessage());
    exit(1);
}

wc_product_sync_cli_log(sprintf(
    'Sync run finished - updated=%d skipped=%d stale=%d failed=%d',
    $summary['updated'],
    $summary['skipped'],
    $summary['stale'],
    $summary['failed']
));

if ($summary['stale'] > 0) {
    wc_product_sync_cli_log('NOTE: ' . $summary['stale'] . ' product(s) were withheld because WooCommerce has a newer edit Mewmii OS hasn\'t seen yet - see Sync Logs or the WooCommerce Products integration page.');
}

if ($summary['failed'] > 0) {
    // Per-product failures are tolerated by design (matches cli/wc_order_sync.php's own
    // convention) - already visible via Sync Logs / modules/integrations/woocommerce.php for
    // follow-up, so this is a warning, not a reason to fail the whole cron run.
    wc_product_sync_cli_log('WARNING: ' . $summary['failed'] . ' product(s) failed to sync individually - see Sync Logs for detail.');
}

wc_product_sync_cli_log('WooCommerce product sync completed.');
exit(0);
