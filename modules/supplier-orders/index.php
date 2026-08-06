<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/saved_views_widget.php';
app_require_permission('supplier-orders.view');

$appTitle = 'Supplier Orders';
$pdo = app_db();

// Optional ?product_id= filter - same read-only, additive pattern as orders/index.php's
// ?product_id= filter. Lets the product Control Center link to "supplier orders containing
// this product" without touching supplier-order logic. DISTINCT guards against a product
// appearing via more than one variation on the same supplier order.
$filterProductId = isset($_GET['product_id']) && ctype_digit((string) $_GET['product_id']) && (int) $_GET['product_id'] > 0
    ? (int) $_GET['product_id']
    : null;
$filterProductLabel = null;

// Optional ?filter=overdue - same read-only, additive pattern as ?product_id= above. The
// overdue condition is copied verbatim from index.php's (dashboard) Overdue card - never
// re-derived, so this filter can never disagree with that count.
$filterOverdue = ($_GET['filter'] ?? '') === 'overdue';

// UI/UX Phase 5C: search + status filter - same additive pattern already used by
// modules/orders/index.php (?q=/?status=). Matches purchase_number or supplier name.
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$allStatuses = array_merge(SUPPLIER_ORDER_WORKFLOW, ['partially_received', 'cancelled']);
$filterStatus = isset($_GET['status']) && in_array($_GET['status'], $allStatuses, true) ? $_GET['status'] : null;

// FROM/JOIN/WHERE built once and shared between the COUNT and the paginated SELECT below -
// same split already used by modules/products/index.php's $stockJoinSql/$whereSql, so the
// two queries can never drift out of sync with each other.
$fromSql = '
    FROM supplier_orders so
    INNER JOIN suppliers s ON s.id = so.supplier_id
';
$conditions = [];
$params = [];
if ($filterProductId !== null) {
    $fromSql .= ' INNER JOIN supplier_order_items soi ON soi.supplier_order_id = so.id AND soi.product_id = ?';
    $params[] = $filterProductId;

    $productLookupStmt = $pdo->prepare('SELECT name, sku FROM products WHERE id = ?');
    $productLookupStmt->execute([$filterProductId]);
    $productLookupRow = $productLookupStmt->fetch(PDO::FETCH_ASSOC);
    $filterProductLabel = $productLookupRow !== false ? ($productLookupRow['sku'] . ' - ' . $productLookupRow['name']) : null;
}
if ($filterOverdue) {
    $conditions[] = "so.expected_delivery_date IS NOT NULL AND so.expected_delivery_date < CURDATE() AND so.status NOT IN ('received', 'completed', 'cancelled')";
}
if ($filterStatus !== null) {
    $conditions[] = 'so.status = ?';
    $params[] = $filterStatus;
}
if ($searchTerm !== '') {
    $conditions[] = '(so.purchase_number LIKE ? OR s.name LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}
$whereSql = $conditions !== [] ? (' WHERE ' . implode(' AND ', $conditions)) : '';

// Production Hardening Phase 2: this page used to be a hard `LIMIT 20` with no page
// parameter at all - any supplier order beyond the 20 most recent (within whatever filters
// were active) was simply unreachable. Real COUNT(*) + LIMIT/OFFSET pagination below, same
// pattern already used by modules/products/index.php and modules/inventory/index.php.
$countStmt = $pdo->prepare('SELECT COUNT(DISTINCT so.id) ' . $fromSql . $whereSql);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();

$perPage = 20;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT DISTINCT so.id, so.purchase_number, so.status, so.payment_status, so.is_historical, so.estimated_cost, so.actual_cost, so.shipping_fee, so.order_date, so.expected_delivery_date, s.name AS supplier_name '
    . $fromSql . $whereSql . " ORDER BY so.id DESC LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$supplierOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Per-row overdue flag + days overdue for the badge below - same definition as the WHERE
// clause above / the dashboard's Overdue card, just evaluated in PHP per row since a single
// boolean column isn't enough here (the badge also needs the day count).
//
// blocked_order_count used to call supplier_order_blocked_customer_orders() once per row
// (itself a query per line unit, then a query per matching customer order item) - now one
// batch call for the whole current page instead of N+1 queries.
$blockedOrderIds = array_values(array_map(static fn (array $order): int => (int) $order['id'], array_filter(
    $supplierOrders,
    static fn (array $order): bool => in_array($order['status'], ['ordered', 'partially_received'], true)
)));
$blockedOrdersBySupplierOrder = supplier_order_blocked_customer_orders_batch($pdo, $blockedOrderIds);

// SO-D - ordered/received/outstanding units for the whole page in one query, same batching
// discipline as blocked_order_count above. Read-only; derived from the inventory ledger.
$outstandingBySupplierOrder = supplier_orders_outstanding_batch(
    $pdo,
    array_map(static fn (array $order): int => (int) $order['id'], $supplierOrders)
);

foreach ($supplierOrders as &$supplierOrder) {
    $supplierOrder['is_overdue'] = $supplierOrder['expected_delivery_date'] !== null
        && strtotime($supplierOrder['expected_delivery_date']) < strtotime('today')
        && !in_array($supplierOrder['status'], ['received', 'completed', 'cancelled'], true);
    $supplierOrder['days_overdue'] = $supplierOrder['is_overdue']
        ? (int) floor((strtotime('today') - strtotime($supplierOrder['expected_delivery_date'])) / 86400)
        : 0;

    $supplierOrder['blocked_order_count'] = count($blockedOrdersBySupplierOrder[(int) $supplierOrder['id']] ?? []);

    $units = $outstandingBySupplierOrder[(int) $supplierOrder['id']] ?? ['ordered' => 0, 'received' => 0, 'outstanding' => 0];
    $supplierOrder['units_ordered'] = $units['ordered'];
    $supplierOrder['units_received'] = $units['received'];
    // Only meaningful while an order can still receive stock - a cancelled or completed order
    // has nothing outstanding to chase, regardless of what the arithmetic says.
    $supplierOrder['units_outstanding'] = in_array($supplierOrder['status'], ['cancelled', 'completed'], true)
        ? 0
        : $units['outstanding'];
}
unset($supplierOrder);

$canManage = app_has_permission('supplier-orders.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1"><i class="bi bi-clipboard-check"></i> Supplier Orders</h1>
        <p class="page-description">
            Purchase orders sent to suppliers and inventory receiving.
            <?php if ($filterProductId !== null): ?>
                &middot; Containing product: <strong><?php echo app_escape($filterProductLabel ?? ('#' . $filterProductId)); ?></strong>
                <a href="/modules/supplier-orders/index.php" class="ms-1">(clear)</a>
            <?php endif; ?>
        </p>
    </div>
    <?php if ($canManage): ?>
        <div class="action-bar">
            <a class="btn btn-primary" href="/modules/supplier-orders/create.php">New Supplier Order</a>
            <a class="btn btn-outline-primary" href="/modules/purchase-planning/generate.php">View Purchase Planning</a>
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions">
                    <i class="bi bi-three-dots"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="/modules/supplier-orders/import.php">Import Historical Order</a></li>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Supplier order created.</div>
<?php endif; ?>
<?php if (isset($_GET['generated'])): ?>
    <div class="alert alert-success"><?php echo (int) $_GET['generated']; ?> supplier order(s) generated from Purchase Planning.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Supplier order deleted.</div>
<?php endif; ?>

<?php render_saved_views_widget($pdo, 'supplier-orders'); ?>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <?php if ($filterProductId !== null): ?>
            <input type="hidden" name="product_id" value="<?php echo (int) $filterProductId; ?>">
        <?php endif; ?>
        <div class="col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Purchase # or supplier name">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($allStatuses as $statusOption): ?>
                    <option value="<?php echo app_escape($statusOption); ?>" <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>><?php echo app_escape(supplier_order_status_label($statusOption)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1 d-block">&nbsp;</label>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="filterOverdue" name="filter" value="overdue" <?php echo $filterOverdue ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="filterOverdue">Overdue only</label>
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/supplier-orders/index.php<?php echo $filterProductId !== null ? ('?product_id=' . (int) $filterProductId) : ''; ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Purchase #</th>
                <th>Supplier</th>
                <th>Status</th>
                <th>Payment</th>
                <th class="text-end">Units</th>
                <th class="text-end">Total Purchase Amount</th>
                <th>Order Date</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($supplierOrders as $order): ?>
                <tr>
                    <td data-label="Purchase #">
                        <?php echo app_escape($order['purchase_number']); ?>
                        <?php if (!empty($order['is_historical'])): ?>
                            <span class="badge bg-secondary">Historical</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Supplier">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle bg-light text-secondary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 28px; height: 28px; font-size: 0.75rem; font-weight: 600;"><?php echo app_escape(strtoupper(substr($order['supplier_name'], 0, 1))); ?></span>
                            <?php echo app_escape($order['supplier_name']); ?>
                        </div>
                    </td>
                    <td data-label="Status">
                        <?php echo supplier_order_status_badge($order['status']); ?>
                        <?php if ($order['is_overdue']): ?>
                            <span class="badge bg-danger">Overdue by <?php echo (int) $order['days_overdue']; ?> day<?php echo $order['days_overdue'] === 1 ? '' : 's'; ?></span>
                        <?php endif; ?>
                        <?php if ($order['blocked_order_count'] > 0): ?>
                            <span class="badge bg-danger" title="Customer orders waiting on this supplier order"><i class="bi bi-exclamation-triangle"></i> Blocking <?php echo (int) $order['blocked_order_count']; ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Payment"><?php echo supplier_order_payment_status_badge((string) $order['payment_status']); ?></td>
                    <td data-label="Units" class="text-end">
                        <?php echo (int) $order['units_received']; ?> / <?php echo (int) $order['units_ordered']; ?>
                        <?php if ($order['units_outstanding'] > 0): ?>
                            <div><span class="badge bg-warning text-dark" title="Units ordered but not yet received"><?php echo (int) $order['units_outstanding']; ?> outstanding</span></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Total Purchase Amount" class="text-end">RM <?php echo app_escape(number_format((float) $order['estimated_cost'] + (float) $order['shipping_fee'], 2)); ?></td>
                    <td data-label="Order Date"><?php echo app_escape($order['order_date'] ?? '-'); ?></td>
                    <td data-label="" class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-primary" href="/modules/supplier-orders/view.php?id=<?php echo (int) $order['id']; ?>">View</a>
                            <?php if ($canManage): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/supplier-orders/edit.php?id=<?php echo (int) $order['id']; ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($canManage): ?>
                                <form method="post" action="/modules/supplier-orders/delete.php" class="d-inline" data-confirm="This cannot be undone. The order and its lines are removed permanently." data-confirm-title="Delete supplier order <?php echo app_escape($order['purchase_number']); ?>?" data-confirm-label="Delete order" data-confirm-tone="danger">
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
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <div class="empty-state-title">No Supplier Orders Match</div>
                            <p class="empty-state-text"><?php echo ($searchTerm !== '' || $filterStatus !== null || $filterOverdue) ? 'Try adjusting or clearing your filters.' : 'Supplier orders will appear here once created.'; ?></p>
                            <?php if ($canManage && $searchTerm === '' && $filterStatus === null && !$filterOverdue): ?>
                                <a class="btn btn-primary btn-sm" href="/modules/supplier-orders/create.php">Create Supplier Order</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    $supplierOrdersPageUrl = static function (int $targetPage): string {
        return '/modules/supplier-orders/index.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $supplierOrdersRangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $supplierOrdersRangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $supplierOrdersRangeStart; ?>&ndash;<?php echo (int) $supplierOrdersRangeEnd; ?> of <?php echo (int) $totalCount; ?> supplier order<?php echo $totalCount === 1 ? '' : 's'; ?>
            <?php else: ?>
                0 supplier orders
            <?php endif; ?>
        </p>
        <?php if ($totalPages > 1): ?>
            <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo app_escape($supplierOrdersPageUrl(max(1, $page - 1))); ?>">&laquo; Prev</a>
                <span class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo app_escape($supplierOrdersPageUrl(min($totalPages, $page + 1))); ?>">Next &raquo;</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
