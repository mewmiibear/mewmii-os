<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/orders.php';
app_require_login();
app_require_permission('orders.view');

$appTitle = 'Orders';
require_once __DIR__ . '/../../includes/header.php';

$stmt = app_db()->query('SELECT o.id, o.order_number, o.payment_status, o.order_status, o.tracking_number, c.name AS customer_name FROM mewmii_orders o LEFT JOIN customers c ON c.id = o.customer_id ORDER BY o.id DESC LIMIT 20');
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canManage = app_has_permission('orders.manage');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Orders</h2>
        <p class="text-muted mb-0">WooCommerce and internal order tracking foundation.</p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-primary" href="/modules/orders/create.php">New Order</a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Order created.</div>
<?php endif; ?>

<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Tracking</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><?php echo app_escape($order['order_number']); ?></td>
                    <td><?php echo app_escape($order['customer_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo app_escape($order['payment_status']); ?></td>
                    <td><?php echo order_status_badge($order['order_status']); ?></td>
                    <td><?php echo $order['tracking_number'] !== null ? app_escape($order['tracking_number']) : '&mdash;'; ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/modules/orders/view.php?id=<?php echo (int) $order['id']; ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>