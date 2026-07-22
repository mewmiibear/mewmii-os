<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/catalog.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$name = trim((string) ($_POST['name'] ?? ''));
$parentId = trim((string) ($_POST['parent_id'] ?? ''));

if ($name === '') {
    ajax_json(['error' => 'Enter a category name.'], 400);
}

$parentIdValue = null;
if ($parentId !== '') {
    $parentIdValue = (int) $parentId;
    $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
    $checkStmt->execute([$parentIdValue]);
    if ((int) $checkStmt->fetchColumn() === 0) {
        ajax_json(['error' => 'Selected parent category does not exist.'], 400);
    }
}

try {
    $pdo->beginTransaction();
    $id = catalog_get_or_create_category($pdo, $name, $parentIdValue);
    $pdo->commit();

    ajax_json(['id' => $id, 'name' => $name, 'parent_id' => $parentIdValue]);
} catch (Exception $exception) {
    $pdo->rollBack();
    ajax_json(['error' => 'Failed to create category.'], 500);
}
