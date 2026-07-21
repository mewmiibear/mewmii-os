<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_login();
app_require_permission('orders.view');

$appTitle = 'Orders';
require_once __DIR__ . '/../../includes/header.php';

$stmt = app_db()->query('SELECT o.id, o.order_number, o.payment_status, o.order_status, o.shipping_status, c.name AS customer_name FROM mewmii_orders o LEFT JOIN customers c ON c.id = o.customer_id ORDER BY o.id DESC LIMIT 20');
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Orders</h2>
        <p class="text-muted mb-0">WooCommerce and internal order tracking foundation.</p>
    </div>
</div>
<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Payment</th>
                <th>Order</th>
                <th>Shipping</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><?php echo app_escape($order['order_number']); ?></td>
                    <td><?php echo app_escape($order['customer_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo app_escape($order['payment_status']); ?></td>
                    <td><?php echo app_escape($order['order_status']); ?></td>
                    <td><?php echo app_escape($order['shipping_status']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>