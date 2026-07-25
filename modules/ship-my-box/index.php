<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ship_my_box.php';
app_require_permission('ship-my-box.view');

$appTitle = 'Ship My Box';
$pdo = app_db();

// UI/UX Phase 5E.3: search + status filter + quick filter chips - same additive, whitelist-
// before-query pattern already used by modules/orders/index.php and
// modules/supplier-orders/index.php. ship_requests.status itself, and every write path that
// sets it (modules/ship-my-box/view.php's POST handler, ship_request_process()), are untouched.
$statusOptions = ['pending', 'processing', 'shipped', 'completed'];
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$filterStatus = isset($_GET['status']) && in_array($_GET['status'], $statusOptions, true) ? $_GET['status'] : null;
// "Needs Action" quick filter - pending or processing, the two statuses
// ship_request_next_action() still returns a real next step for.
$filterNeedsAction = ($_GET['filter'] ?? '') === 'needs_action';

$sql = '
    SELECT
        sr.id,
        sr.request_number,
        sr.status,
        sr.created_at,
        c.name AS customer_name,
        (SELECT COUNT(*) FROM ship_request_items sri WHERE sri.ship_request_id = sr.id) AS item_count,
        (SELECT COALESCE(SUM(sri.quantity), 0) FROM ship_request_items sri WHERE sri.ship_request_id = sr.id) AS total_quantity
    FROM ship_requests sr
    INNER JOIN customers c ON c.id = sr.customer_id
';
$conditions = [];
$params = [];
if ($filterStatus !== null) {
    $conditions[] = 'sr.status = ?';
    $params[] = $filterStatus;
} elseif ($filterNeedsAction) {
    $conditions[] = "sr.status IN ('pending', 'processing')";
}
if ($searchTerm !== '') {
    $conditions[] = '(sr.request_number LIKE ? OR c.name LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}
if ($conditions !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY sr.id DESC LIMIT 20';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shipRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = app_has_permission('ship-my-box.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1"><i class="bi bi-box2"></i> Ship My Box</h2>
        <p class="page-description">Requests to ship items currently held in customer storage.</p>
    </div>
    <?php if ($canManage): ?>
        <div class="action-bar">
            <a class="btn btn-primary" href="/modules/ship-my-box/create.php">New Ship Request</a>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Ship request created.</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm <?php echo $filterStatus === 'pending' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="/modules/ship-my-box/index.php?status=pending">New</a>
    <a class="btn btn-sm <?php echo $filterStatus === 'processing' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="/modules/ship-my-box/index.php?status=processing">Preparing</a>
    <a class="btn btn-sm <?php echo $filterNeedsAction ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="/modules/ship-my-box/index.php?filter=needs_action">Needs Action</a>
    <a class="btn btn-sm <?php echo $filterStatus === 'completed' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="/modules/ship-my-box/index.php?status=completed">Completed</a>
    <?php if ($filterStatus !== null || $filterNeedsAction): ?>
        <a class="btn btn-sm btn-outline-secondary" href="/modules/ship-my-box/index.php">Clear quick filters</a>
    <?php endif; ?>
</div>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Request # or customer name">
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($statusOptions as $statusOption): ?>
                    <option value="<?php echo app_escape($statusOption); ?>" <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>><?php echo app_escape(ship_request_status_label($statusOption)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/ship-my-box/index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Status</th>
                <th class="text-end">Items</th>
                <th class="text-end">Total Qty</th>
                <th>Requested</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($shipRequests as $request): ?>
                <tr>
                    <td data-label="Customer">
                        <?php echo app_escape($request['customer_name']); ?>
                        <div class="text-muted small"><?php echo app_escape($request['request_number'] ?? ('#' . $request['id'])); ?></div>
                    </td>
                    <td data-label="Status"><?php echo ship_request_status_badge($request['status']); ?></td>
                    <td data-label="Items" class="text-end"><?php echo app_escape((string) $request['item_count']); ?></td>
                    <td data-label="Total Qty" class="text-end"><?php echo app_escape((string) $request['total_quantity']); ?></td>
                    <td data-label="Requested"><?php echo app_escape($request['created_at']); ?></td>
                    <td data-label="" class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/modules/ship-my-box/view.php?id=<?php echo (int) $request['id']; ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($shipRequests === []): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <div class="empty-state-title">No Ship Requests Match</div>
                            <p class="empty-state-text"><?php echo ($searchTerm !== '' || $filterStatus !== null || $filterNeedsAction) ? 'Try adjusting or clearing your filters.' : 'Requests to ship items from customer storage will appear here.'; ?></p>
                            <?php if ($canManage && $searchTerm === '' && $filterStatus === null && !$filterNeedsAction): ?>
                                <a class="btn btn-primary btn-sm" href="/modules/ship-my-box/create.php">New Ship Request</a>
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
