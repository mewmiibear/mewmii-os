<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_images.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$variationId = (int) ($_POST['variation_id'] ?? 0);

if ($variationId < 1) {
    ajax_json(['error' => 'Invalid variation.'], 400);
}

$sortOrders = $_POST['sort_order'] ?? [];
$deleteIds = $_POST['delete_ids'] ?? [];

try {
    $pdo->beginTransaction();
    variation_image_update_gallery($pdo, $variationId, $sortOrders, $deleteIds);
    $pdo->commit();

    ajax_json(['ok' => true]);
} catch (Exception $exception) {
    $pdo->rollBack();
    ajax_json(['error' => 'Failed to update gallery.'], 500);
}
