<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/catalog.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$name = trim((string) ($_POST['name'] ?? ''));

if ($name === '') {
    ajax_json(['error' => 'Enter a brand name.'], 400);
}

try {
    $pdo->beginTransaction();
    $id = catalog_get_or_create_brand($pdo, $name);
    $pdo->commit();

    ajax_json(['id' => $id, 'name' => $name]);
} catch (Exception $exception) {
    $pdo->rollBack();
    ajax_json(['error' => 'Failed to create brand.'], 500);
}
