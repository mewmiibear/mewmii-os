<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_images.php';

// Read-only GET, lazy-loaded when the "Manage Images" modal opens (see assets/js/product-form.js) -
// no CSRF check needed, matching modules/inventory/ajax/history.php's convention.
ajax_require_permission('products.view');

$pdo = app_db();
$variationId = (int) ($_GET['variation_id'] ?? 0);

if ($variationId < 1) {
    ajax_json(['error' => 'Invalid variation.'], 400);
}

$main = product_image_get_variation($pdo, $variationId);
$gallery = variation_image_list_gallery($pdo, $variationId);

ajax_json([
    'main_image' => $main !== null ? ['image_path' => $main['image_path']] : null,
    'gallery' => array_map(static function (array $image): array {
        return ['id' => (int) $image['id'], 'image_path' => $image['image_path'], 'sort_order' => (int) $image['sort_order']];
    }, $gallery),
]);
