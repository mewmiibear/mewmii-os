<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/wc_client.php';
app_require_permission('products.manage');

/**
 * "Sync this Product" / "Retry" - pushes exactly one product to WooCommerce, forced (bypasses
 * wc_client_sync_if_changed()'s unchanged-skip gate - see its own docblock): a deliberate click
 * on this button must always actually push, never silently no-op just because nothing looked
 * different since the last sync. Same underlying sync logic (and same sync_logs sync_type,
 * 'woocommerce_product_sync') as the bulk "Sync to WooCommerce" button and Auto Sync - this is
 * purely a single-product entry point into it, not a separate implementation.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('/modules/products/index.php');
}

try {
    app_require_csrf();
} catch (RuntimeException $exception) {
    app_redirect('/modules/products/index.php');
}

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);

if ($productId < 1) {
    app_redirect('/modules/products/index.php');
}

// Bugfix pass: wc_client_load_product_for_sync() runs a real SELECT and can throw (e.g. the
// missing woocommerce_sync_hash column incident) - previously uncaught here, which would
// have surfaced as a raw fatal error instead of the same "wc_sync=failed" alert every other
// failure in this flow already shows.
try {
    $product = wc_client_load_product_for_sync($pdo, $productId);
} catch (Throwable $exception) {
    try {
        sync_log_failure($pdo, 'woocommerce_product_sync', $exception->getMessage(), $productId);
    } catch (Throwable $loggingFailure) {
    }

    $reason = wc_client_classify_sync_error($exception);

    app_redirect('/modules/products/edit.php?id=' . $productId . '&wc_sync=failed&wc_sync_reason=' . urlencode($reason));
}

if ($product === null) {
    app_redirect('/modules/products/index.php');
}

if (trim((string) $product['sku']) === '') {
    app_redirect('/modules/products/edit.php?id=' . $productId . '&wc_sync=failed');
}

try {
    $result = wc_client_sync_if_changed($pdo, $product, true);

    if ($result['action'] === 'stale') {
        // Production Hardening Phase 2: $force bypasses the unchanged-fingerprint skip, but
        // never the staleness/conflict check - a deliberate "Sync this Product" click must
        // still never overwrite a newer WooCommerce-side edit. See
        // wc_client_check_product_staleness()/wc_client_sync_if_changed()'s own docblocks.
        sync_log_write($pdo, 'woocommerce_product_sync', 'warning', $productId, $result['warning'] ?? null);

        app_redirect('/modules/products/edit.php?id=' . $productId . '&wc_sync=stale');
    }

    sync_log_success($pdo, 'woocommerce_product_sync', $productId);

    app_redirect('/modules/products/edit.php?id=' . $productId . '&wc_sync=synced');
} catch (Throwable $exception) {
    try {
        sync_log_failure($pdo, 'woocommerce_product_sync', $exception->getMessage(), $productId);
    } catch (Throwable $loggingFailure) {
    }

    $reason = wc_client_classify_sync_error($exception);

    app_redirect('/modules/products/edit.php?id=' . $productId . '&wc_sync=failed&wc_sync_reason=' . urlencode($reason));
}
