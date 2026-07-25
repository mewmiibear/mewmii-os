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

// UI/UX Phase 5B: search box - previously this page had no free-text search at all, only the
// link-only ?status=/?product_id= filters. Matches order_number or customer name, same LIKE
// pattern already used everywhere else in this app (e.g. modules/inventory/index.php's
// product search). Purely additive - doesn't change what columns are selected or how existing
// filters combine, just one more optional WHERE condition.
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$sql = 'SELECT DISTINCT o.id, o.order_number, o.payment_status, o.order_status, o.receipt_status, o.receipt_url, o.is_historical, o.tracking_number, o.customer_id, c.name AS customer_name FROM mewmii_orders o LEFT JOIN customers c ON c.id = o.customer_id';
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
if ($searchTerm !== '') {
    $conditions[] = '(o.order_number LIKE ? OR c.name LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}
if ($conditions !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY o.id DESC LIMIT 20';
$stmt = app_db()->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canManage = app_has_permission('orders.manage');
// Customer name below links to modules/customers/view.php, which requires customers.view -
// the destination controls permission, not this page's own orders.view gate.
$canViewCustomers = app_has_permission('customers.view');
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1">Orders</h2>
        <p class="page-description">
            WooCommerce and internal order tracking foundation.
            <?php if ($filterProductId !== null): ?>
                &middot; Containing product: <strong><?php echo app_escape($filterProductLabel ?? ('#' . $filterProductId)); ?></strong>
                <a href="/modules/orders/index.php" class="ms-1">(clear)</a>
            <?php endif; ?>
        </p>
    </div>
    <?php if ($canManage): ?>
        <div class="action-bar">
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

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <?php if ($filterProductId !== null): ?>
            <input type="hidden" name="product_id" value="<?php echo (int) $filterProductId; ?>">
        <?php endif; ?>
        <div class="col-md-5">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Order number or customer name">
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach (array_merge(ORDER_STATUS_WORKFLOW, ['cancelled']) as $statusOption): ?>
                    <option value="<?php echo app_escape($statusOption); ?>" <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>><?php echo app_escape(order_status_label($statusOption)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/orders/index.php<?php echo $filterProductId !== null ? ('?product_id=' . (int) $filterProductId) : ''; ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Receipt</th>
                <th>Tracking</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td data-label="Order #">
                        <?php echo app_escape(order_display_number($order['order_number'])); ?>
                        <?php if (!empty($order['is_historical'])): ?>
                            <span class="badge bg-secondary">Historical</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Customer">
                        <?php if ($order['customer_name'] === null): ?>
                            Unknown
                        <?php elseif ($canViewCustomers && $order['customer_id'] !== null): ?>
                            <a href="/modules/customers/view.php?id=<?php echo (int) $order['customer_id']; ?>"><?php echo app_escape($order['customer_name']); ?></a>
                        <?php else: ?>
                            <?php echo app_escape($order['customer_name']); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Payment"><?php echo payment_status_badge($order['payment_status']); ?></td>
                    <td data-label="Status"><?php echo order_status_badge($order['order_status']); ?></td>
                    <td data-label="Receipt">
                        <?php echo $order['receipt_status'] !== null ? order_receipt_status_badge($order) : '<span class="text-muted">&mdash;</span>'; ?>
                    </td>
                    <td data-label="Tracking"><?php echo $order['tracking_number'] !== null ? app_escape($order['tracking_number']) : '&mdash;'; ?></td>
                    <td data-label="" class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/modules/orders/view.php?id=<?php echo (int) $order['id']; ?>">View</a>
                        <?php if ($canManage): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="/modules/orders/edit.php?id=<?php echo (int) $order['id']; ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($orders === []): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="empty-state-title">No Orders Match</div>
                            <p class="empty-state-text"><?php echo ($searchTerm !== '' || $filterStatus !== null) ? 'Try adjusting or clearing your filters.' : 'Customer orders will appear here once created.'; ?></p>
                            <?php if ($canManage && $searchTerm === '' && $filterStatus === null): ?>
                                <a class="btn btn-primary btn-sm" href="/modules/orders/create.php">New Order</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>