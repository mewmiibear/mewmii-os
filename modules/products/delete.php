<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/wc_client.php';
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

// Full-automation pass - read BEFORE deleting, since the row (and this id with it) is gone
// once product_delete_if_unused() succeeds; there is nothing left in Mewmii OS to look this up
// from afterward.
$wooCommerceProductIdStmt = $pdo->prepare('SELECT woocommerce_product_id FROM products WHERE id = ?');
$wooCommerceProductIdStmt->execute([$productId]);
$wooCommerceProductIdValue = $wooCommerceProductIdStmt->fetchColumn();
$wooCommerceProductId = $wooCommerceProductIdValue !== false && $wooCommerceProductIdValue !== null
    ? (int) $wooCommerceProductIdValue
    : null;

$pdo->beginTransaction();

try {
    product_delete_if_unused($pdo, $productId);
    $pdo->commit();

    // Never throws - see wc_client_delete_product()'s own docblock. Deliberately outside the
    // transaction above: the local delete is already committed and irreversible by this point
    // (product_delete_if_unused() only allows deleting a product with zero history), so a
    // WooCommerce-side failure here must never roll back or block a deletion that already
    // succeeded - only be logged for follow-up.
    wc_client_delete_product($pdo, $productId, $wooCommerceProductId);

    app_redirect('/modules/products/index.php?deleted=1');
} catch (RuntimeException $exception) {
    $pdo->rollBack();
    app_redirect('/modules/products/edit.php?id=' . $productId . '&delete_error=' . urlencode($exception->getMessage()));
} catch (Exception $exception) {
    $pdo->rollBack();
    app_redirect('/modules/products/edit.php?id=' . $productId . '&delete_error=1');
}
