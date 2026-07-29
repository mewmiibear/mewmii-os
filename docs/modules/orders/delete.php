<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/orders.php';
app_require_permission('orders.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('/modules/orders/index.php');
}

try {
    app_require_csrf();
} catch (RuntimeException $exception) {
    app_redirect('/modules/orders/index.php');
}

$pdo = app_db();
$orderId = (int) ($_POST['order_id'] ?? 0);

if ($orderId < 1) {
    app_redirect('/modules/orders/index.php');
}

$pdo->beginTransaction();

try {
    order_delete_if_unused($pdo, $orderId);
    $pdo->commit();

    app_redirect('/modules/orders/index.php?deleted=1');
} catch (RuntimeException $exception) {
    $pdo->rollBack();
    app_redirect('/modules/orders/view.php?id=' . $orderId . '&delete_error=' . urlencode($exception->getMessage()));
} catch (Exception $exception) {
    $pdo->rollBack();
    app_redirect('/modules/orders/view.php?id=' . $orderId . '&delete_error=1');
}
