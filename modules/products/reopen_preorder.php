<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
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

// Manually reopening is the ONLY way ordering resumes after preorder_closing_date passes -
// never automatic, never tied to estimated_release_month arriving. Regular Price applies
// from here on; Early Bird pricing does not come back (see catalog_product_effective_price()).
$pdo->prepare("UPDATE products SET preorder_reopened_at = NOW() WHERE id = ? AND product_type IN ('preorder', 'early_bird')")
    ->execute([$productId]);

app_redirect('/modules/products/edit.php?id=' . $productId . '&reopened=1');
