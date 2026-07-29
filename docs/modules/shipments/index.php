<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/shipments.php';
require_once __DIR__ . '/../../includes/saved_views_widget.php';
app_require_permission('shipments.view');

$appTitle = 'Shipments';
$pdo = app_db();

// UI/UX Phase 5C: search + status filter - same additive, SELECT-only pattern already used by
// modules/orders/index.php and modules/supplier-orders/index.php. shipment_list_all() itself
// is left untouched (still used as-is by nothing else, but kept as the base query builder
// intent); this page now builds its own filtered SELECT directly, mirroring the convention
// modules/supplier-orders/index.php already established for a filterable list.
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$filterStatus = isset($_GET['status']) && in_array($_GET['status'], SHIPMENT_STATUSES, true) ? $_GET['status'] : null;
// Sprint 10: mirrors the exact "awaiting tracking" condition the dashboard's Operations
// Overview card already computes (index.php's $shipmentAwaitingTrackingCount) - the same
// tracking_number IS NULL AND shipping_status <> 'cancelled' rule, not a new definition.
$filterAwaitingTracking = ($_GET['awaiting_tracking'] ?? '') === '1';

$sql = '
    SELECT s.id, s.shipment_number, s.source_type, s.carrier, s.tracking_number, s.shipping_status, s.shipped_at, s.created_at, c.name AS customer_name
    FROM shipments s
    INNER JOIN customers c ON c.id = s.customer_id
';
$conditions = [];
$params = [];
if ($filterStatus !== null) {
    $conditions[] = 's.shipping_status = ?';
    $params[] = $filterStatus;
}
if ($filterAwaitingTracking) {
    $conditions[] = "s.tracking_number IS NULL AND s.shipping_status <> 'cancelled'";
}
if ($searchTerm !== '') {
    $conditions[] = '(s.shipment_number LIKE ? OR c.name LIKE ? OR s.tracking_number LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}
$whereSql = $conditions !== [] ? (' WHERE ' . implode(' AND ', $conditions)) : '';

// Sprint 10: real pagination, replacing the previous hardcoded LIMIT 200 with no way to
// reach anything beyond it - same COUNT + LIMIT/OFFSET convention as every other list page.
$perPage = 50;
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM shipments s INNER JOIN customers c ON c.id = s.customer_id{$whereSql}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql .= $whereSql . " ORDER BY s.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Per-shipment item count + distinct order count - display-only aggregate, one extra SELECT
// covering every row above instead of a query per row.
$itemTotals = [];
if ($shipments !== []) {
    $shipmentIds = array_map(static fn ($row) => (int) $row['id'], $shipments);
    $placeholders = implode(',', array_fill(0, count($shipmentIds), '?'));
    $totalsStmt = $pdo->prepare("
        SELECT shipment_id, COUNT(DISTINCT order_id) AS order_count, COALESCE(SUM(quantity), 0) AS item_qty
        FROM shipment_items
        WHERE shipment_id IN ({$placeholders})
        GROUP BY shipment_id
    ");
    $totalsStmt->execute($shipmentIds);
    foreach ($totalsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemTotals[(int) $row['shipment_id']] = $row;
    }
}

$canManage = app_has_permission('shipments.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1"><i class="bi bi-send"></i> Shipments</h2>
        <p class="page-description">Every physical package leaving the warehouse - from orders, Ship My Box requests, or manual (replacement/warranty).</p>
    </div>
    <?php if ($canManage): ?>
        <div class="action-bar">
            <a class="btn btn-primary" href="/modules/shipments/create.php">New Manual Shipment</a>
        </div>
    <?php endif; ?>
</div>

<?php render_saved_views_widget($pdo, 'shipments'); ?>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Shipment #, customer, or tracking number">
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach (SHIPMENT_STATUSES as $statusOption): ?>
                    <option value="<?php echo app_escape($statusOption); ?>" <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>><?php echo app_escape(shipment_status_label($statusOption)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-center">
            <div class="form-check mt-2">
                <input type="checkbox" class="form-check-input" id="awaiting-tracking-toggle" name="awaiting_tracking" value="1" <?php echo $filterAwaitingTracking ? 'checked' : ''; ?> onchange="this.form.submit()">
                <label class="form-check-label small" for="awaiting-tracking-toggle">Awaiting tracking only</label>
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/shipments/index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Shipment</th>
                <th>Customer</th>
                <th>Source</th>
                <th>Carrier / Tracking</th>
                <th>Status</th>
                <th class="text-end">Orders</th>
                <th class="text-end">Items</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($shipments as $shipment): ?>
                <?php $totals = $itemTotals[(int) $shipment['id']] ?? ['order_count' => 0, 'item_qty' => 0]; ?>
                <tr>
                    <td data-label="Shipment"><?php echo app_escape($shipment['shipment_number']); ?></td>
                    <td data-label="Customer"><?php echo app_escape($shipment['customer_name']); ?></td>
                    <td data-label="Source"><?php echo app_escape(ucfirst(str_replace('_', ' ', $shipment['source_type']))); ?></td>
                    <td data-label="Carrier / Tracking">
                        <?php if (!empty($shipment['tracking_number'])): ?>
                            <?php echo app_escape($shipment['tracking_number']); ?>
                            <?php if (!empty($shipment['carrier'])): ?><div class="text-muted small"><?php echo app_escape($shipment['carrier']); ?></div><?php endif; ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td data-label="Status"><?php echo shipment_status_badge($shipment['shipping_status']); ?></td>
                    <td data-label="Orders" class="text-end"><?php echo (int) $totals['order_count']; ?></td>
                    <td data-label="Items" class="text-end"><?php echo (int) $totals['item_qty']; ?></td>
                    <td data-label="Created"><?php echo app_escape($shipment['created_at']); ?></td>
                    <td data-label="" class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/modules/shipments/view.php?id=<?php echo (int) $shipment['id']; ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($shipments === []): ?>
                <?php $hasFilters = $searchTerm !== '' || $filterStatus !== null || $filterAwaitingTracking; ?>
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <div class="empty-state-title">No Shipments Match</div>
                            <p class="empty-state-text"><?php echo $hasFilters ? 'Try adjusting or clearing your filters.' : 'Packages leaving the warehouse - from orders, Ship My Box, or manual - will appear here.'; ?></p>
                            <?php if ($canManage && !$hasFilters): ?>
                                <a class="btn btn-primary btn-sm" href="/modules/shipments/create.php">New Manual Shipment</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    $pageUrl = static function (int $targetPage): string {
        return '/modules/shipments/index.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $rangeStart; ?>&ndash;<?php echo (int) $rangeEnd; ?> of <?php echo (int) $totalCount; ?> shipment<?php echo $totalCount === 1 ? '' : 's'; ?>
            <?php else: ?>
                0 shipments
            <?php endif; ?>
        </p>
        <?php if ($totalPages > 1): ?>
            <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(max(1, $page - 1))); ?>">&laquo; Prev</a>
                <span class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(min($totalPages, $page + 1))); ?>">Next &raquo;</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
