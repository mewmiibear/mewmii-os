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

// UI/UX Phase 5E.1: payment-status filter - same additive, whitelist-before-query pattern as
// $filterStatus above. Values match modules/orders/view.php's $statusFields['payment_status']
// list (mewmii_orders.payment_status is untouched, this is read-only).
$paymentStatusOptions = ['pending', 'paid', 'refunded', 'failed'];
$filterPaymentStatus = isset($_GET['payment_status']) && in_array($_GET['payment_status'], $paymentStatusOptions, true)
    ? $_GET['payment_status']
    : null;

$sql = 'SELECT DISTINCT o.id, o.order_number, o.order_date, o.payment_status, o.order_status, o.receipt_status, o.receipt_url, o.is_historical, o.tracking_number, o.customer_id, c.name AS customer_name FROM mewmii_orders o LEFT JOIN customers c ON c.id = o.customer_id';
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
if ($filterPaymentStatus !== null) {
    $conditions[] = 'o.payment_status = ?';
    $params[] = $filterPaymentStatus;
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

// UI/UX Phase 5E.1: one-time bulk-approve-payment result, set by modules/orders/bulk_action.php
// and read/cleared here on the very next request - keeps a potentially long per-order
// success/failure list off the URL entirely.
$bulkResult = null;
if (isset($_GET['bulk_result']) && isset($_SESSION['orders_bulk_result'])) {
    $bulkResult = $_SESSION['orders_bulk_result'];
}
unset($_SESSION['orders_bulk_result']);
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

<?php if ($bulkResult !== null): ?>
    <?php
    $bulkApprovedCount = count(array_filter($bulkResult, static fn (array $r): bool => $r['success']));
    $bulkFailedResults = array_filter($bulkResult, static fn (array $r): bool => !$r['success']);
    ?>
    <div class="alert <?php echo $bulkFailedResults === [] ? 'alert-success' : 'alert-warning'; ?>">
        <div><?php echo (int) $bulkApprovedCount; ?> of <?php echo count($bulkResult); ?> order(s) approved.</div>
        <?php if ($bulkFailedResults !== []): ?>
            <ul class="mb-0 small mt-1">
                <?php foreach ($bulkFailedResults as $failure): ?>
                    <li><?php echo app_escape(order_display_number((string) $failure['order_number'])); ?>: <?php echo app_escape($failure['message']); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <?php if ($filterProductId !== null): ?>
            <input type="hidden" name="product_id" value="<?php echo (int) $filterProductId; ?>">
        <?php endif; ?>
        <div class="col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Order number or customer name">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach (array_merge(ORDER_STATUS_WORKFLOW, ['cancelled']) as $statusOption): ?>
                    <option value="<?php echo app_escape($statusOption); ?>" <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>><?php echo app_escape(order_status_label($statusOption)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Payment</label>
            <select name="payment_status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($paymentStatusOptions as $paymentOption): ?>
                    <option value="<?php echo app_escape($paymentOption); ?>" <?php echo $filterPaymentStatus === $paymentOption ? 'selected' : ''; ?>><?php echo app_escape(ucfirst($paymentOption)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/orders/index.php<?php echo $filterProductId !== null ? ('?product_id=' . (int) $filterProductId) : ''; ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<?php
// UI/UX Phase 5E.1: bulk Approve Payment - the checkbox only appears on a row that's actually
// eligible (payment_status pending, not historical), so an invalid selection can't even be
// made from here. modules/orders/bulk_action.php re-validates each order independently
// anyway (order_approve_payment() inside order_bulk_approve_payment()) since page state can
// go stale between load and submit.
$bulkEligibleCount = 0;
foreach ($orders as $order) {
    if ($order['payment_status'] === 'pending' && empty($order['is_historical'])) {
        $bulkEligibleCount++;
    }
}
?>
<form method="post" action="/modules/orders/bulk_action.php" id="orders-bulk-form">
    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
    <input type="hidden" name="bulk_action" value="approve_payment">
    <div class="card p-4">
        <?php if ($canManage && $bulkEligibleCount > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="small text-muted"><span id="orders-bulk-count">0</span> selected</div>
                <button type="submit" class="btn btn-sm btn-primary" id="orders-bulk-submit" disabled onclick="return confirm('Approve payment for the selected order(s)? Ready-stock items will be reserved where possible.');">Approve Selected Payments</button>
            </div>
        <?php endif; ?>
        <div class="table-responsive">
        <table class="table table-hover align-middle responsive-stack-table">
            <thead>
                <tr>
                    <?php if ($canManage && $bulkEligibleCount > 0): ?>
                        <th><input type="checkbox" class="form-check-input" id="orders-bulk-select-all"></th>
                    <?php endif; ?>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Order Date</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Receipt</th>
                    <th>Tracking</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php $bulkEligible = $order['payment_status'] === 'pending' && empty($order['is_historical']); ?>
                    <tr>
                        <?php if ($canManage && $bulkEligibleCount > 0): ?>
                            <td data-label="">
                                <?php if ($bulkEligible): ?>
                                    <input type="checkbox" class="form-check-input orders-bulk-checkbox" name="order_ids[]" value="<?php echo (int) $order['id']; ?>">
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
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
                        <td data-label="Order Date"><?php echo $order['order_date'] !== null ? app_escape($order['order_date']) : '&mdash;'; ?></td>
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
                        <td colspan="9">
                            <div class="empty-state">
                                <div class="empty-state-title">No Orders Match</div>
                                <p class="empty-state-text"><?php echo ($searchTerm !== '' || $filterStatus !== null || $filterPaymentStatus !== null) ? 'Try adjusting or clearing your filters.' : 'Customer orders will appear here once created.'; ?></p>
                                <?php if ($canManage && $searchTerm === '' && $filterStatus === null && $filterPaymentStatus === null): ?>
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
</form>

<?php if ($canManage && $bulkEligibleCount > 0): ?>
<script>
(function () {
    var selectAll = document.getElementById('orders-bulk-select-all');
    var checkboxes = document.querySelectorAll('.orders-bulk-checkbox');
    var counter = document.getElementById('orders-bulk-count');
    var submitBtn = document.getElementById('orders-bulk-submit');

    function updateState() {
        var checked = document.querySelectorAll('.orders-bulk-checkbox:checked').length;
        counter.textContent = String(checked);
        submitBtn.disabled = checked === 0;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
            updateState();
        });
    }
    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateState);
    });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>