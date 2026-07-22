<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('supplier-orders.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('/modules/supplier-orders/index.php');
}

try {
    app_require_csrf();
} catch (RuntimeException $exception) {
    app_redirect('/modules/supplier-orders/index.php');
}

$pdo = app_db();
$orderId = (int) ($_POST['order_id'] ?? 0);

if ($orderId < 1) {
    app_redirect('/modules/supplier-orders/index.php');
}

$pdo->beginTransaction();

try {
    supplier_order_delete_if_unreceived($pdo, $orderId);
    $pdo->commit();

    app_redirect('/modules/supplier-orders/index.php?deleted=1');
} catch (RuntimeException $exception) {
    $pdo->rollBack();
    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&delete_error=' . urlencode($exception->getMessage()));
} catch (Exception $exception) {
    $pdo->rollBack();
    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&delete_error=1');
}
