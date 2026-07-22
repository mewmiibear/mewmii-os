<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_login();
app_require_permission('products.manage');
app_require_csrf();

require_once __DIR__ . '/../../includes/wc_client.php';
require_once __DIR__ . '/../../includes/sync_log.php';

$successCount = 0;
$failedCount = 0;
$errors = [];

try {
    $stmt = app_db()->prepare("SELECT id, sku, name, description, catalog_type, selling_price, product_type, status, preorder_closing_date, preorder_reopened_at, estimated_arrival_date, estimated_release_month, sale_enabled, sale_price, sale_start_date FROM products WHERE sku IS NOT NULL AND TRIM(sku) <> '' ORDER BY id ASC");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $product) {
        try {
            wc_client_sync_any_product_from_mewmii(app_db(), $product);
            sync_log_success(app_db(), 'woocommerce_product_sync', (int) ($product['id'] ?? 0));
            $successCount++;
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

app_redirect('/modules/products/index.php?sync=1&success=' . $successCount . '&failed=' . $failedCount);
