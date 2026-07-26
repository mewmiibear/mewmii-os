#!/usr/bin/env php
<?php

/**
 * CLI-only WooCommerce webhook queue processor (Phase 6E).
 *
 * Usage:
 *   php cli/wc_webhook_process.php                  Process due events for real.
 *   php cli/wc_webhook_process.php --batch-size=50   Process at most 50 events this run
 *                                                     instead of the default (20).
 *
 * Intended to be invoked by a cron job every 1-2 minutes (much more frequent than
 * cli/wc_order_sync.php / cli/wc_product_import.php's poll interval, since near-real-time
 * delivery is the whole point of adding webhooks). A thin wrapper, matching those two
 * scripts' own shape exactly: this script decides WHEN
 * wc_webhook_process_pending_events() (includes/wc_webhook.php) gets called and how its
 * result is reported to cron/the terminal - it has no processing logic of its own, and does
 * not touch modules/webhooks/woocommerce.php's receiving side at all.
 *
 * Same CLI-only protection as the existing two scripts: the PHP_SAPI check below stops this
 * from ever reaching bootstrap.php/the database under a web request, and cli/.htaccess
 * denies web access to this whole directory at the server level - either alone would be
 * enough, both together mean a misconfiguration in one doesn't leave the route open.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/wc_webhook.php';

function wc_webhook_process_cli_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL);
}

function wc_webhook_process_cli_error(string $message): void
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $message . PHP_EOL);
}

$batchSize = 20;
foreach ($argv as $arg) {
    if (strpos($arg, '--batch-size=') === 0) {
        $requested = (int) substr($arg, strlen('--batch-size='));
        if ($requested > 0) {
            $batchSize = $requested;
        }
    }
}

wc_webhook_process_cli_log("WooCommerce webhook processing starting (batch size {$batchSize}).");

$pdo = app_db();

try {
    $summary = wc_webhook_process_pending_events($pdo, $batchSize);
} catch (RuntimeException $e) {
    if ($e->getCode() === WC_WEBHOOK_LOCK_BUSY_CODE) {
        // Benign, expected condition - another processing run (an overlapping cron tick) is
        // already in progress against the same lock. Not a failure: exits 0 so this doesn't
        // trip cron-failure monitoring on a routine overlap - the other, already-running
        // process will finish normally. Same convention as cli/wc_order_sync.php.
        wc_webhook_process_cli_log($e->getMessage());
        exit(0);
    }

    wc_webhook_process_cli_error('Unhandled exception during processing: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    wc_webhook_process_cli_error('Unhandled exception during processing: ' . $e->getMessage());
    exit(1);
}

wc_webhook_process_cli_log(sprintf(
    'Processing run finished - processed=%d completed=%d retrying=%d failed=%d',
    $summary['processed'],
    $summary['completed'],
    $summary['retrying'],
    $summary['failed']
));

if ($summary['failed'] > 0) {
    // Per-event failures that have exhausted their retry attempts are tolerated by design
    // (see includes/wc_webhook.php's WC_WEBHOOK_MAX_ATTEMPTS) - already visible via
    // modules/webhooks/events.php and modules/sync-logs/index.php for manual follow-up
    // (Retry button), so this is a warning, not a reason to fail the whole cron run.
    wc_webhook_process_cli_log('WARNING: ' . $summary['failed'] . ' event(s) exhausted their retry attempts - see the Webhook Events page for detail.');
}

wc_webhook_process_cli_log('WooCommerce webhook processing completed.');
exit(0);
