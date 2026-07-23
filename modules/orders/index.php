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

// Optional ?product_id= filter - same read-only, additive pattern as ?status= above. Lets the
// product Control Center link to "orders containing this product" without touching order
// logic. DISTINCT guards against a product appearing via more than one variation on the same
// order producing duplicate rows.
$filterProductId = isset($_GET['product_id']) && ctype_digit((string) $_GET['product_id']) && (int) $_GET['product_id'] > 0
    ? (int) $_GET['product_id']
    : null;
$filterProductLabel = null;
if ($filterProductId !== null) {
    $productLookupStmt = app_db()->prepare('SELECT name, sku FROM products WHERE id = ?');
    $productLookupStmt->execute([$filterProductId]);
    $productLookupRow = $productLookupStmt->fetch(PDO::FETCH_ASSOC);
    $filterProductLabel = $productLookupRow !== false ? ($productLookupRow['sku'] . ' - ' . $productLookupRow['name']) : null;
}

$sql = 'SELECT DISTINCT o.id, o.order_number, o.payment_status, o.order_status, o.is_historical, o.tracking_number, c.name AS customer_name FROM mewmii_orders o LEFT JOIN customers c ON c.id = o.customer_id';
$conditions = [];
$params = [];
if ($filterProductId !== null) {
    $sql .= ' INNER JOIN mewmii_order_items oi ON oi.order_id = o.id';
    $conditions[] = 'oi.product_id = ?';
    $params[] = $filterProductId;
}
if ($filterStatus !== null) {
    $conditions[] = 'o.order_status = ?';
    $params[] = $filterStatus;
}
if ($conditions !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
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
            <?php if ($filterProductId !== null): ?>
                &middot; Containing product: <strong><?php echo app_escape($filterProductLabel ?? ('#' . $filterProductId)); ?></strong>
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