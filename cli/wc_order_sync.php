#!/usr/bin/env php
<?php

/**
 * CLI-only WooCommerce order sync runner (Sync Automation Phase 2).
 *
 * Intended to be invoked by a Hostinger cron job running the PHP CLI binary directly against
 * this file's path (see the accompanying setup instructions) - never over HTTP. This app's
 * document root is the whole Mewmii OS install (see includes/bootstrap.php), so this file is
 * technically reachable by URL unless something stops it - two independent layers do that
 * here: (1) the PHP_SAPI check immediately below, which exits before bootstrap.php or the
 * database are ever touched under a web request, and (2) cli/.htaccess, which denies all web
 * access to this whole directory at the server level. Either alone would be enough; both
 * together mean a misconfiguration in one doesn't leave this route open.
 *
 * Deliberately does not modify wc_order_import_single(), receipt verification, payment
 * workflow, or inventory/fulfillment logic - this script only decides WHEN
 * wc_order_import_run() gets called and how its result is reported to cron. It has no
 * business logic of its own.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/wc_order_import.php';

function wc_order_sync_cli_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL);
}

function wc_order_sync_cli_error(string $message): void
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $message . PHP_EOL);
}

wc_order_sync_cli_log('WooCommerce order sync starting.');

if (!wc_client_is_configured()) {
    wc_order_sync_cli_error('WooCommerce API is not configured (check config.php woocommerce.url/consumer_key/consumer_secret).');
    exit(1);
}

$pdo = app_db();

// wc_order_import_run() only advances its stored delta cursor once every page of a run has
// been fetched successfully (see its own docblock in includes/wc_order_import.php) - it
// swallows fetch-level failures internally (logs to sync_logs, returns a normal-looking
// summary) rather than throwing, so a single bad page doesn't crash the whole process the way
// an uncaught exception would. That means the reliable way to detect "did this run actually
// complete" from out here is to compare the cursor before and after, not to rely solely on a
// catch block - see the check below.
$cursorBefore = wc_order_import_get_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT);

try {
    $summary = wc_order_import_run($pdo);
} catch (RuntimeException $e) {
    if ($e->getCode() === WC_ORDER_IMPORT_LOCK_BUSY_CODE) {
        // Benign, expected condition (Sync Automation Phase 4A) - another sync (cron or the
        // manual "Import Orders Now" button) is already running against the same lock. Not a
        // failure: exits 0 so this doesn't trip cron-failure monitoring on a routine overlap -
        // the other, already-running sync will advance the cursor normally.
        wc_order_sync_cli_log($e->getMessage());
        exit(0);
    }

    wc_order_sync_cli_error('Unhandled exception during sync: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    // Reaching here means something genuinely unexpected happened outside what
    // wc_order_import_run() already handles internally (e.g. a database-level failure).
    wc_order_sync_cli_error('Unhandled exception during sync: ' . $e->getMessage());
    exit(1);
}

$cursorAfter = wc_order_import_get_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT);
$cursorAdvanced = $cursorAfter !== null && $cursorAfter !== $cursorBefore;

wc_order_sync_cli_log(sprintf(
    'Sync run finished - created=%d updated=%d skipped=%d failed=%d cursor=%s',
    $summary['created'],
    $summary['updated'],
    $summary['skipped'],
    $summary['failed'],
    $cursorAfter ?? 'unchanged'
));

if (!$cursorAdvanced) {
    wc_order_sync_cli_error('Sync did not complete - the delta cursor was not advanced. This means the WooCommerce fetch failed partway through, or hit the page-count safety limit. See Sync Logs (modules/sync-logs) or the WooCommerce Orders integration page for the underlying error.');
    exit(1);
}

if ($summary['failed'] > 0) {
    // Per-order failures (e.g. an unresolved SKU on one order) are tolerated by design - see
    // wc_order_import_single()'s docblock - so this is reported as a warning, not treated as a
    // reason to fail the whole cron run. Already-visible via the Failed Sync stat on
    // modules/integrations/woocommerce.php and the Sync Logs list, for follow-up.
    wc_order_sync_cli_log('WARNING: ' . $summary['failed'] . ' order(s) failed to import individually - see Sync Logs for detail.');
}

wc_order_sync_cli_log('WooCommerce order sync completed successfully.');
exit(0);
