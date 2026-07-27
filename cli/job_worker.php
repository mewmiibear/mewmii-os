#!/usr/bin/env php
<?php

/**
 * CLI-only Unified Outbound Job Queue worker (Phase 11A).
 *
 * Usage:
 *   php cli/job_worker.php                  Process due jobs for real (default batch size 50).
 *   php cli/job_worker.php --batch-size=100  Process at most 100 jobs this run.
 *   php cli/job_worker.php --cleanup         After processing, also delete 'completed' jobs
 *                                             older than JOB_QUEUE_CLEANUP_DAYS_DEFAULT (90).
 *                                             Never deletes pending/processing/failed rows.
 *
 * A thin wrapper, matching cli/wc_webhook_process.php's own shape exactly: this script decides
 * WHEN job_queue_process_batch()/job_cleanup() (includes/job_queue.php) run and how the result
 * is reported to cron/the terminal. Its ONLY other job is building the $handlers map below,
 * which is the single place mapping a job `type` string to the EXISTING function that actually
 * does the work - no sync/import/business logic lives in this file or in includes/job_queue.php
 * itself.
 *
 * To add a future job type (woocommerce.stock.sync, email.send, supplier.sync, ...): add one
 * entry to $handlers below calling whatever existing function already does that work - no
 * change to includes/job_queue.php, no schema change (`type` is a plain string).
 *
 * Intended to be invoked by a cron job every 1-2 minutes, same interval class as
 * cli/wc_webhook_process.php, since this is now how "Save Product" actually reaches
 * WooCommerce (see modules/products/create.php/edit.php enqueuing instead of syncing inline).
 *
 * Same CLI-only protection as every other script in this directory: the PHP_SAPI check below
 * stops this from ever reaching bootstrap.php/the database under a web request, and
 * cli/.htaccess denies web access to this whole directory at the server level.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/job_queue.php';
require_once __DIR__ . '/../includes/wc_client.php';

function job_worker_cli_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL);
}

function job_worker_cli_error(string $message): void
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $message . PHP_EOL);
}

/**
 * The only handler wired up in this phase. Calls wc_client_auto_sync_product() - the exact,
 * unmodified function every other product-sync entry point already calls (Auto Sync on save,
 * modules/products/bulk_action.php, the variation ajax endpoints) - unchanged. That function
 * never throws on its own (see its own docblock); this wrapper is what turns its 'failed'
 * outcome into a thrown exception, since job_queue_process_batch_body() decides retry-vs-
 * complete based on whether the handler throws. 'synced'/'skipped'/'stale' are all legitimate,
 * non-error outcomes (wc_client_auto_sync_product() already logs a 'stale' warning to sync_logs
 * itself) and are treated as a completed job, not a failure to retry.
 */
$handlers = [
    WC_CLIENT_PRODUCT_SYNC_JOB_TYPE => function (array $job, PDO $pdo): void {
        $productId = (int) ($job['entity_id'] ?? 0);
        if ($productId < 1) {
            throw new RuntimeException('Job #' . $job['id'] . ' has no valid product entity_id.');
        }

        $result = wc_client_auto_sync_product($pdo, $productId);

        if ($result['status'] === 'failed') {
            throw new RuntimeException($result['error'] ?? 'WooCommerce product sync failed.');
        }
    },
];

$batchSize = 50;
$runCleanup = in_array('--cleanup', $argv, true);
foreach ($argv as $arg) {
    if (strpos($arg, '--batch-size=') === 0) {
        $requested = (int) substr($arg, strlen('--batch-size='));
        if ($requested > 0) {
            $batchSize = $requested;
        }
    }
}

job_worker_cli_log("Job queue worker starting (batch size {$batchSize}).");

$pdo = app_db();

try {
    $summary = job_queue_process_batch($pdo, $handlers, $batchSize);
} catch (RuntimeException $e) {
    if ($e->getCode() === JOB_QUEUE_LOCK_BUSY_CODE) {
        // Benign, expected condition - another worker run (an overlapping cron tick) is
        // already in progress against the same lock. Not a failure: exits 0 so this doesn't
        // trip cron-failure monitoring on a routine overlap.
        job_worker_cli_log($e->getMessage());
        exit(0);
    }

    job_worker_cli_error('Unhandled exception during processing: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    job_worker_cli_error('Unhandled exception during processing: ' . $e->getMessage());
    exit(1);
}

job_worker_cli_log(sprintf(
    'Run finished - recovered=%d processed=%d completed=%d retrying=%d failed=%d',
    $summary['recovered'],
    $summary['processed'],
    $summary['completed'],
    $summary['retrying'],
    $summary['failed']
));

if ($summary['recovered'] > 0) {
    job_worker_cli_log('NOTE: ' . $summary['recovered'] . ' job(s) were recovered from an abandoned "processing" state (a prior worker likely crashed mid-job) and re-attempted this run.');
}

if ($summary['failed'] > 0) {
    job_worker_cli_log('WARNING: ' . $summary['failed'] . ' job(s) exhausted their retry attempts - see Operations > Job Queue for detail.');
}

if ($runCleanup) {
    try {
        $removed = job_cleanup($pdo);
        job_worker_cli_log("Cleanup finished - removed {$removed} old completed job(s).");
    } catch (Throwable $e) {
        job_worker_cli_error('Cleanup failed: ' . $e->getMessage());
    }
}

job_worker_cli_log('Job queue worker completed.');
exit(0);
