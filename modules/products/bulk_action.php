<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/wc_client.php';
require_once __DIR__ . '/../../includes/sync_log.php';
app_require_permission('products.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('/modules/products/index.php');
}

try {
    app_require_csrf();
} catch (RuntimeException $exception) {
    app_redirect('/modules/products/index.php');
}

$pdo = app_db();
$action = (string) ($_POST['bulk_action'] ?? '');
$productIds = array_values(array_unique(array_filter(array_map('intval', $_POST['product_ids'] ?? []), static fn (int $id): bool => $id > 0)));

$validActions = ['set_brand', 'set_category', 'set_collection', 'add_tags', 'archive', 'sync_woocommerce'];
if (!in_array($action, $validActions, true) || $productIds === []) {
    app_redirect('/modules/products/index.php');
}

$successCount = 0;
$failedCount = 0;
$skippedCount = 0;
$staleCount = 0;
$errors = [];
// Bugfix pass: categorized via wc_client_classify_sync_error() so the flash banner can show
// *why* products failed (e.g. "Database error") instead of a bare count.
$failureReasons = [];
// Bugfix pass: products that synced fine but had one or more images skipped because the
// stored file is missing on disk (see wc_client_find_missing_images()).
$missingImageCount = 0;

// Sprint 10 (Operator Experience): modeled directly on modules/orders/bulk_action.php - each
// product mutated independently, wrapped in its own try/catch, so one bad row never aborts
// the rest of the batch.
switch ($action) {
    case 'set_brand':
        $brandId = (int) ($_POST['bulk_brand_id'] ?? 0);
        if ($brandId < 1) {
            app_redirect('/modules/products/index.php');
        }
        $updateStmt = $pdo->prepare('UPDATE products SET brand_id = ? WHERE id = ?');
        foreach ($productIds as $productId) {
            try {
                $updateStmt->execute([$brandId, $productId]);
                wc_client_auto_sync_product($pdo, $productId);
                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = $exception->getMessage();
                $reason = wc_client_classify_sync_error($exception);
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            }
        }
        break;

    case 'set_category':
        $categoryId = (int) ($_POST['bulk_category_id'] ?? 0);
        if ($categoryId < 1) {
            app_redirect('/modules/products/index.php');
        }
        foreach ($productIds as $productId) {
            try {
                // catalog_sync_product_category() is the same function the product form
                // itself uses - a product has exactly one category, so this replaces it
                // rather than appending.
                catalog_sync_product_category($pdo, $productId, $categoryId);
                wc_client_auto_sync_product($pdo, $productId);
                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = $exception->getMessage();
                $reason = wc_client_classify_sync_error($exception);
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            }
        }
        break;

    case 'set_collection':
        $collectionId = (int) ($_POST['bulk_collection_id'] ?? 0);
        if ($collectionId < 1) {
            app_redirect('/modules/products/index.php');
        }
        foreach ($productIds as $productId) {
            try {
                catalog_sync_product_collection($pdo, $productId, $collectionId);
                wc_client_auto_sync_product($pdo, $productId);
                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = $exception->getMessage();
                $reason = wc_client_classify_sync_error($exception);
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            }
        }
        break;

    case 'add_tags':
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $_POST['bulk_tag_ids'] ?? []), static fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            app_redirect('/modules/products/index.php');
        }
        foreach ($productIds as $productId) {
            try {
                // Additive, per the brief: a product's existing tags are preserved, the
                // selected tags are merged in - never a full replace like the product form's
                // own tag checkbox list does.
                $existingTagIds = catalog_get_product_tag_ids($pdo, $productId);
                $mergedTagIds = array_values(array_unique(array_merge($existingTagIds, $tagIds)));
                catalog_sync_product_tag_ids($pdo, $productId, $mergedTagIds);
                wc_client_auto_sync_product($pdo, $productId);
                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = $exception->getMessage();
                $reason = wc_client_classify_sync_error($exception);
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            }
        }
        break;

    case 'archive':
        $archiveStmt = $pdo->prepare("UPDATE products SET status = 'archived' WHERE id = ?");
        foreach ($productIds as $productId) {
            try {
                $archiveStmt->execute([$productId]);
                wc_client_auto_sync_product($pdo, $productId);
                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = $exception->getMessage();
                $reason = wc_client_classify_sync_error($exception);
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            }
        }
        break;

    case 'sync_woocommerce':
        foreach ($productIds as $productId) {
            try {
                $product = wc_client_load_product_for_sync($pdo, $productId);
                if ($product === null || trim((string) $product['sku']) === '') {
                    $skippedCount++;
                    continue;
                }

                $result = wc_client_sync_if_changed($pdo, $product);
                if ($result['action'] === 'skipped') {
                    $skippedCount++;
                } elseif ($result['action'] === 'stale') {
                    $staleCount++;
                    sync_log_write($pdo, 'woocommerce_product_sync', 'warning', $productId, $result['warning'] ?? null);
                } else {
                    sync_log_success($pdo, 'woocommerce_product_sync', $productId);
                    $successCount++;

                    $missingImages = $result['missing_images'] ?? [];
                    if ($missingImages !== []) {
                        $missingImageCount++;
                        sync_log_write($pdo, 'woocommerce_product_sync', 'warning', $productId, 'Synced, but skipped missing image file(s) for: ' . implode(', ', $missingImages) . '. Re-upload the affected image(s).');
                    }
                }
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = $exception->getMessage();
                $reason = wc_client_classify_sync_error($exception);
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
                sync_log_failure($pdo, 'woocommerce_product_sync', $exception->getMessage(), $productId);
            }
        }
        break;
}

// One-time flash - read and cleared by modules/products/index.php on the next request, same
// convention as modules/orders/bulk_action.php -> modules/orders/index.php.
$_SESSION['products_bulk_result'] = [
    'action' => $action,
    'success_count' => $successCount,
    'skipped_count' => $skippedCount,
    'stale_count' => $staleCount,
    'failed_count' => $failedCount,
    'errors' => $errors,
    'failure_reasons' => $failureReasons,
    'missing_images' => $missingImageCount,
];

app_redirect('/modules/products/index.php?bulk_result=1');
