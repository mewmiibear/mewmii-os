<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/product_duplicate.php';
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
$productId = (int) ($_POST['product_id'] ?? 0);

if ($productId < 1) {
    app_redirect('/modules/products/index.php');
}

$pdo->beginTransaction();

try {
    $newProductId = product_duplicate($pdo, $productId);
    $pdo->commit();

    app_redirect('/modules/products/edit.php?id=' . $newProductId . '&duplicated=1');
} catch (Exception $exception) {
    $pdo->rollBack();
    app_redirect('/modules/products/index.php?duplicate_error=1');
}
