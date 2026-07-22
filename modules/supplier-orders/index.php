<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('supplier-orders.view');

$appTitle = 'Supplier Orders';
$pdo = app_db();

$stmt = $pdo->query('
    SELECT so.id, so.purchase_number, so.status, so.estimated_cost, so.actual_cost, so.shipping_fee, so.order_date, s.name AS supplier_name
    FROM supplier_orders so
    INNER JOIN suppliers s ON s.id = so.supplier_id
    ORDER BY so.id DESC
    LIMIT 20
');
$supplierOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = app_has_permission('supplier-orders.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Supplier Orders</h2>
        <p class="text-muted mb-0">Purchase orders sent to suppliers and inventory receiving.</p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-primary" href="/modules/supplier-orders/create.php">New Supplier Order</a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Supplier order created.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Supplier order deleted.</div>
<?php endif; ?>

<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Purchase #</th>
                <th>Supplier</th>
                <th>Status</th>
                <th>Total Purchase Amount</th>
                <th>Order Date</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($supplierOrders as $order): ?>
                <tr>
                    <td><?php echo app_escape($order['purchase_number']); ?></td>
                    <td><?php echo app_escape($order['supplier_name']); ?></td>
                    <td><?php echo supplier_order_status_badge($order['status']); ?></td>
                    <td>RM <?php echo app_escape(number_format((float) $order['estimated_cost'] + (float) $order['shipping_fee'], 2)); ?></td>
                    <td><?php echo app_escape($order['order_date'] ?? '-'); ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-primary" href="/modules/supplier-orders/view.php?id=<?php echo (int) $order['id']; ?>">View</a>
                            <?php if ($canManage): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/supplier-orders/edit.php?id=<?php echo (int) $order['id']; ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($canManage): ?>
                                <form method="post" action="/modules/supplier-orders/delete.php" class="d-inline" onsubmit="return confirm('Delete this supplier order? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                    <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($supplierOrders === []): ?>
                <tr><td colspan="6" class="text-muted">No supplier orders yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
