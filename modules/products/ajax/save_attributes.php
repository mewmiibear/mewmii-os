<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/catalog.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);
$rawSelections = json_decode((string) ($_POST['selections'] ?? '[]'), true);

if ($productId < 1 || !is_array($rawSelections)) {
    ajax_json(['error' => 'Invalid request.'], 400);
}

$selections = [];
foreach ($rawSelections as $item) {
    if (!is_array($item)) {
        continue;
    }
    $selections[] = [
        'attribute_id' => (int) ($item['attribute_id'] ?? 0),
        'is_variation_attribute' => !empty($item['is_variation_attribute']),
        'value_ids' => array_map('intval', $item['value_ids'] ?? []),
    ];
}

try {
    $pdo->beginTransaction();
    catalog_set_product_attributes($pdo, $productId, $selections);
    $pdo->commit();

    ajax_json(['ok' => true]);
} catch (Exception $exception) {
    $pdo->rollBack();
    ajax_json(['error' => 'Failed to save attributes.'], 500);
}
