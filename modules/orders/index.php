<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/orders.php';
app_require_login();
app_require_permission('orders.view');

$appTitle = 'Orders';
require_once __DIR__ . '/../../includes/header.php';

// Optional ?status= filter - same read-only, additive pattern already used by
// modules/inventory/index.php's ?stock_status=/?stage= filters. Lets the Operations
// Dashboard's Orders cards link to a specific order_status instead of always the
// unfiltered latest-20 list. Defaults to no filter (today's exact behavior) when absent.
$filterStatus = isset($_GET['status']) && in_array($_GET['status'], array_merge(ORDER_STATUS_WORKFLOW, ['cancelled']), true)
    ? $_GET['status']
    : null;

$sql = 'SELECT o.id, o.order_number, o.payment_status, o.order_status, o.is_historical, o.tracking_number, c.name AS customer_name FROM mewmii_orders o LEFT JOIN customers c ON c.id = o.customer_id';
$params = [];
if ($filterStatus !== null) {
    $sql .= ' WHERE o.order_status = ?';
    $params[] = $filterStatus;
}
$sql .= ' ORDER BY o.id DESC LIMIT 20';
$stmt = app_db()->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canManage = app_has_permission('orders.manage');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Orders</h2>
        <p class="text-muted mb-0">
            WooCommerce and internal order tracking foundation.
            <?php if ($filterStatus !== null): ?>
                &middot; Filtered: <?php echo app_escape(order_status_label($filterStatus)); ?>
                <a href="/modules/orders/index.php" class="ms-1">(clear)</a>
            <?php endif; ?>
        </p>
    </div>
    <?php if ($canManage): ?>
        <div class="d-flex gap-2">
            <a class="btn btn-primary" href="/modules/orders/create.php">New Order</a>
            <a class="btn btn-outline-secondary" href="/modules/orders/import.php">Import Historical Order</a>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Order created.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Order deleted.</div>
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
                    <td>
                        <?php echo app_escape($order['order_number']); ?>
                        <?php if (!empty($order['is_historical'])): ?>
                            <span class="badge bg-secondary">Historical</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($order['customer_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo app_escape($order['payment_status']); ?></td>
                    <td><?php echo order_status_badge($order['order_status']); ?></td>
                    <td><?php echo $order['tracking_number'] !== null ? app_escape($order['tracking_number']) : '&mdash;'; ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/modules/orders/view.php?id=<?php echo (int) $order['id']; ?>">View</a>
                        <?php if ($canManage): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="/modules/orders/edit.php?id=<?php echo (int) $order['id']; ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>