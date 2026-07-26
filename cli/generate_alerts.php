#!/usr/bin/env php
<?php

/**
 * CLI-only alert generator for the Notification & Alert Center (Phase 9B; Phase 9C added
 * auto-resolution to the same run).
 *
 * Usage:
 *   php cli/generate_alerts.php   Runs notification_generate_alerts() (new alerts) followed by
 *                                  notification_auto_resolve() (closes ones whose condition no
 *                                  longer holds) - both from includes/notifications.php - once,
 *                                  and reports counts for each per type.
 *
 * Intended to be invoked by a cron job on whatever interval makes sense for how often the
 * underlying Phase 7/8 data actually changes (e.g. once daily is enough for Cost Increase/
 * Supplier Delay; Inventory Risk/Supplier Order Overdue could run more often) - this script
 * has no scheduling logic of its own, it just runs one generate+resolve pass per invocation,
 * same shape as cli/wc_webhook_process.php.
 *
 * De-duplication happens inside notification_generate_alerts() itself (one open, i.e.
 * un-resolved, notification per type+reference_id at a time) - running this script as often as
 * you like never creates duplicate rows for a still-open alert.
 *
 * Same CLI-only protection as the existing scripts in this directory: the PHP_SAPI check below
 * stops this from ever reaching bootstrap.php/the database under a web request, and
 * cli/.htaccess denies web access to this whole directory at the server level.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/notifications.php';

function generate_alerts_cli_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL);
}

function generate_alerts_cli_error(string $message): void
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $message . PHP_EOL);
}

generate_alerts_cli_log('Alert generation starting...');

$pdo = app_db();

try {
    $created = notification_generate_alerts($pdo);
} catch (Throwable $e) {
    generate_alerts_cli_error('Unhandled exception during generation: ' . $e->getMessage());
    exit(1);
}

$totalCreated = array_sum($created);
generate_alerts_cli_log(sprintf(
    'Generation finished - inventory_risk=%d cost_increase=%d supplier_delay=%d supplier_order_overdue=%d (total new: %d)',
    $created['inventory_risk'],
    $created['cost_increase'],
    $created['supplier_delay'],
    $created['supplier_order_overdue'],
    $totalCreated
));

try {
    $resolved = notification_auto_resolve($pdo);
} catch (Throwable $e) {
    // Generation above already committed successfully - a failure here is logged but doesn't
    // undo it or fail the whole run, same "don't let a secondary step sink a good primary
    // result" convention cli/wc_webhook_process.php's own --cleanup step uses.
    generate_alerts_cli_error('Unhandled exception during auto-resolution: ' . $e->getMessage());
    exit(1);
}

$totalResolved = array_sum($resolved);
generate_alerts_cli_log(sprintf(
    'Auto-resolution finished - inventory_risk=%d cost_increase=%d supplier_delay=%d supplier_order_overdue=%d (total resolved: %d)',
    $resolved['inventory_risk'],
    $resolved['cost_increase'],
    $resolved['supplier_delay'],
    $resolved['supplier_order_overdue'],
    $totalResolved
));

generate_alerts_cli_log('Alert generation completed.');
exit(0);
