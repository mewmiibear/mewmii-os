<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_login();
app_require_permission('products.manage');
app_require_csrf();

require_once __DIR__ . '/../../includes/wc_client.php';
require_once __DIR__ . '/../../includes/sync_log.php';

$updatedCount = 0;
$skippedCount = 0;
$failedCount = 0;
$errors = [];

try {
    $stmt = app_db()->prepare("SELECT id, sku, name, short_description, description, catalog_type, selling_price, product_type, status, availability_override, preorder_closing_date, preorder_reopened_at, estimated_arrival_date, estimated_release_month, sale_enabled, sale_price, sale_start_date, woocommerce_product_id, woocommerce_sync_hash FROM products WHERE sku IS NOT NULL AND TRIM(sku) <> '' ORDER BY id ASC");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $product) {
        try {
            $result = wc_client_sync_if_changed(app_db(), $product);

            if ($result['action'] === 'skipped') {
                // Nothing WooCommerce-relevant changed since the last successful sync (see
                // wc_client_product_sync_fingerprint()) - not logged to sync_logs, same
                // "skipped means no log row" convention as the WooCommerce -> Mewmii importer
                // (includes/wc_product_import.php), only the summary count below reflects it.
                $skippedCount++;
            } else {
                sync_log_success(app_db(), 'woocommerce_product_sync', (int) ($product['id'] ?? 0));
                $updatedCount++;
            }
        } catch (Throwable $e) {
            $failedCount++;
            $errors[] = $e->getMessage();
            sync_log_failure(app_db(), 'woocommerce_product_sync', $e->getMessage(), (int) ($product['id'] ?? 0));
        }
    }
} catch (Throwable $e) {
    sync_log_failure(app_db(), 'woocommerce_product_sync', $e->getMessage());
    $errors[] = $e->getMessage();
}

app_redirect('/modules/products/index.php?sync=1&updated=' . $updatedCount . '&skipped=' . $skippedCount . '&failed=' . $failedCount);
