<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_images.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);
$files = image_upload_normalize_multi($_FILES['gallery_images'] ?? []);

if ($productId < 1 || $files === []) {
    ajax_json(['error' => 'Select at least one image to upload.'], 400);
}

try {
    $pdo->beginTransaction();
    product_image_add_gallery($pdo, $productId, $files);
    $pdo->commit();

    ajax_json(['ok' => true]);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ajax_json(['error' => $exception->getMessage()], 400);
} catch (Exception $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ajax_json(['error' => 'Failed to upload images.'], 500);
}
