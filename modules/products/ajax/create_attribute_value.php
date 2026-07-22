<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/catalog.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$attributeId = (int) ($_POST['attribute_id'] ?? 0);
$value = trim((string) ($_POST['value'] ?? ''));

if ($attributeId < 1 || $value === '') {
    ajax_json(['error' => 'Select an attribute and enter a value.'], 400);
}

try {
    $pdo->beginTransaction();
    $id = catalog_get_or_create_attribute_value($pdo, $attributeId, $value);
    $pdo->commit();

    ajax_json(['id' => $id, 'value' => $value, 'attribute_id' => $attributeId]);
} catch (Exception $exception) {
    $pdo->rollBack();
    ajax_json(['error' => 'Failed to create value.'], 500);
}
