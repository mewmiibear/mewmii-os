<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_images.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);

if ($productId < 1 || empty($_FILES['main_image']['name'])) {
    ajax_json(['error' => 'Select an image to upload.'], 400);
}

try {
    $pdo->beginTransaction();
    product_image_set_main($pdo, $productId, $_FILES['main_image']);
    $pdo->commit();

    $main = product_image_get_main($pdo, $productId);
    ajax_json(['ok' => true, 'image_path' => $main['image_path'] ?? null]);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ajax_json(['error' => $exception->getMessage()], 400);
} catch (Exception $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ajax_json(['error' => 'Failed to upload image.'], 500);
}
