<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_login();
app_require_permission('products.manage');
app_require_csrf();

require_once __DIR__ . '/../../includes/wc_client.php';
require_once __DIR__ . '/../../includes/sync_log.php';

// Full-automation pass - the bulk push loop itself now lives in
// wc_client_sync_all_products()/wc_client_sync_all_products_body() (includes/wc_client.php), so
// this manual "Sync to WooCommerce" button and the new cron entrypoint (cli/wc_product_sync.php)
// share one implementation instead of two. Behavior here is unchanged from before this pass.
try {
    $summary = wc_client_sync_all_products(app_db());
} catch (RuntimeException $e) {
    if ($e->getCode() === WC_CLIENT_PRODUCT_SYNC_LOCK_BUSY_CODE) {
        // Benign, expected condition - a cron-triggered sync is already running against the
        // same lock. Not a failure: nothing here was attempted, so every count is 0.
        $_SESSION['products_sync_result'] = [
            'updated' => 0, 'skipped' => 0, 'stale' => 0, 'failed' => 0,
            'failure_reasons' => [], 'missing_images' => 0,
            'lock_busy' => true,
        ];
        app_redirect('/modules/products/index.php?sync=1&updated=0&skipped=0&stale=0&failed=0');
    }

    throw $e;
}

// Session flash (same convention as modules/products/bulk_action.php ->
// modules/products/index.php) carries the reason breakdown; the query params stay for
// backward compatibility/shareable-URL purposes but the flash is what index.php actually
// renders when present.
$_SESSION['products_sync_result'] = [
    'updated' => $summary['updated'],
    'skipped' => $summary['skipped'],
    'stale' => $summary['stale'],
    'failed' => $summary['failed'],
    'failure_reasons' => $summary['failure_reasons'],
    'missing_images' => $summary['missing_images'],
];

app_redirect('/modules/products/index.php?sync=1&updated=' . $summary['updated'] . '&skipped=' . $summary['skipped'] . '&stale=' . $summary['stale'] . '&failed=' . $summary['failed']);
