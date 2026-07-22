<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
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
    product_delete_if_unused($pdo, $productId);
    $pdo->commit();

    app_redirect('/modules/products/index.php?deleted=1');
} catch (RuntimeException $exception) {
    $pdo->rollBack();
    app_redirect('/modules/products/edit.php?id=' . $productId . '&delete_error=' . urlencode($exception->getMessage()));
} catch (Exception $exception) {
    $pdo->rollBack();
    app_redirect('/modules/products/edit.php?id=' . $productId . '&delete_error=1');
}
