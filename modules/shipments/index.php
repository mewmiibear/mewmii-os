<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/shipments.php';
require_once __DIR__ . '/../../includes/saved_views_widget.php';
app_require_permission('shipments.view');

$appTitle = 'Shipments';
$pdo = app_db();
$bulkMessage = '';
$bulkError = '';

// Workflow: Mark Packed directly from this list, one row or many at once.
//
// Packing is the highest-frequency repetitive action in fulfilment, and it previously cost a
// page load, a click and a back-navigation per parcel because the only row action here was
// "View". shipment_mark_packed() needs nothing but an id - no carrier, no tracking - so it is
// safe to trigger from a list. Mark Shipped deliberately stays on the detail page, since
// shipping without carrier/tracking is almost always a mistake.
//
// Reuses shipment_mark_packed() unchanged; this adds no fulfilment logic of its own. Each
// shipment gets its own transaction so one ineligible row (already packed, cancelled, or
// concurrently changed) cannot roll back the ones that succeeded - the function's own
// "only a Pending shipment can be marked Packed" guard still decides eligibility.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_permission('shipments.manage');

    try {
        app_require_csrf();

        if ((string) ($_POST['bulk_action'] ?? '') === 'mark_packed') {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', (array) ($_POST['shipment_ids'] ?? [])),
                static fn (int $id): bool => $id > 0
            )));

            $packed = 0;
            $failures = [];

            foreach ($ids as $shipmentId) {
                $pdo->beginTransaction();
                try {
                    shipment_mark_packed($pdo, $shipmentId);
                    $pdo->commit();
                    $packed++;
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $failures[] = '#' . $shipmentId . ': ' . $exception->getMessage();
                }
            }

            if ($packed > 0) {
                $bulkMessage = $packed . ' shipment' . ($packed === 1 ? '' : 's') . ' marked Packed.';
            }
            if ($failures !== []) {
                $bulkError = count($failures) . ' could not be packed - ' . implode(' ', array_slice($failures, 0, 3));
            }
            if ($packed === 0 && $failures === []) {
                $bulkError = 'Select at least one pending shipment.';
            }
        } elseif ((string) ($_POST['row_action'] ?? '') === 'mark_shipped') {
            // Inline dispatch from the list - identical inputs and identical call to what
            // modules/shipments/view.php's Confirm Shipped form does. shipment_mark_shipped()
            // remains the single source of truth: it consumes the ledger, writes tracking, and
            // recomputes each affected order's status. Nothing is duplicated here.
            $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
            $carrier = trim((string) ($_POST['carrier'] ?? ''));
            $trackingNumber = trim((string) ($_POST['tracking_number'] ?? ''));

            if ($shipmentId < 1) {
                $bulkError = 'Invalid shipment.';
            } elseif ($carrier === '' || $trackingNumber === '') {
                $bulkError = 'Enter both a carrier and a tracking number to mark a shipment shipped.';
            } else {
                $pdo->beginTransaction();
                try {
                    shipment_mark_shipped($pdo, $shipmentId, $carrier, $trackingNumber, date('Y-m-d'));
                    $pdo->commit();
                    $bulkMessage = 'Shipment marked Shipped (' . $carrier . ' ' . $trackingNumber . ').';
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $bulkError = 'Could not mark shipped: ' . $exception->getMessage();
                }
            }
        }
    } catch (RuntimeException $exception) {
        $bulkError = $exception->getMessage();
    }
}

// Carrier pre-fill for the inline dispatch controls - a day's parcels almost always go out with
// one courier, so defaulting to the last one used removes a retyped field per parcel. The query
// itself now lives in shipment_last_used_carrier() (includes/shipments.php) so the other
// dispatch forms can share the same default instead of leaving the field blank.
$lastCarrier = shipment_last_used_carrier($pdo);

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

<?php if ($bulkMessage !== ''): ?>
    <div class="alert alert-success"><?php echo app_escape($bulkMessage); ?></div>
<?php endif; ?>
<?php if ($bulkError !== ''): ?>
    <div class="alert alert-warning"><?php echo app_escape($bulkError); ?></div>
<?php endif; ?>

<div class="card p-4">
    <?php $pendingCount = count(array_filter($shipments, static fn (array $s): bool => $s['shipping_status'] === 'pending')); ?>
    <?php
    // The bulk form deliberately does NOT wrap the table: each shippable row carries its own
    // inline dispatch form, and HTML forbids nesting one form inside another. Row controls join
    // this form via the HTML5 form="" attribute instead, which keeps both interactions on one
    // page with no navigation.
    ?>
    <form method="post" id="shipment-bulk-form">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="bulk_action" value="mark_packed">
        <?php if ($canManage && $pendingCount > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small"><?php echo (int) $pendingCount; ?> pending shipment<?php echo $pendingCount === 1 ? '' : 's'; ?> on this page</span>
                <button type="submit" class="btn btn-sm btn-primary" id="shipment-bulk-pack-btn" disabled>Mark Packed</button>
            </div>
        <?php endif; ?>
    </form>
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <?php if ($canManage && $pendingCount > 0): ?>
                    <th style="width:32px;"><input type="checkbox" class="form-check-input" id="shipment-select-all" title="Select all pending"></th>
                <?php endif; ?>
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
                    <?php if ($canManage && $pendingCount > 0): ?>
                        <td data-label="">
                            <?php if ($shipment['shipping_status'] === 'pending'): ?>
                                <input type="checkbox" class="form-check-input shipment-select" form="shipment-bulk-form" name="shipment_ids[]" value="<?php echo (int) $shipment['id']; ?>">
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
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
                        <?php if ($canManage && $shipment['shipping_status'] === 'pending'): ?>
                            <button type="submit" class="btn btn-sm btn-primary" form="shipment-bulk-form" name="shipment_ids[]" value="<?php echo (int) $shipment['id']; ?>">Mark Packed</button>
                        <?php endif; ?>
                        <?php if ($canManage && in_array($shipment['shipping_status'], ['pending', 'packed'], true)): ?>
                            <?php /* Inline dispatch - the same carrier/tracking/date that
                                     modules/shipments/view.php's Confirm Shipped form collects, and the
                                     same shipment_mark_shipped() behind it. Entering tracking for a day's
                                     parcels previously cost one page load each; this keeps it on the list.
                                     Carrier pre-fills from the most recently shipped parcel, since a
                                     batch almost always goes out with one courier. */ ?>
                            <form method="post" class="d-inline-flex gap-1 align-items-center flex-wrap justify-content-end mt-1">
                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                <input type="hidden" name="row_action" value="mark_shipped">
                                <input type="hidden" name="shipment_id" value="<?php echo (int) $shipment['id']; ?>">
                                <input type="text" class="form-control form-control-sm" style="max-width:110px;" name="carrier" required placeholder="Carrier" value="<?php echo app_escape($lastCarrier ?? ''); ?>">
                                <input type="text" class="form-control form-control-sm" style="max-width:130px;" name="tracking_number" required placeholder="Tracking no.">
                                <button type="submit" class="btn btn-sm btn-success">Ship</button>
                            </form>
                        <?php endif; ?>
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
<script>
// Select-all + enable/disable for the Mark Packed bulk button. Progressive enhancement only:
// with JS off, the per-row "Mark Packed" submit buttons still work, since each carries its own
// name="shipment_ids[]" value.
(function () {
    var form = document.getElementById('shipment-bulk-form');
    if (!form) { return; }
    var selectAll = document.getElementById('shipment-select-all');
    var bulkBtn = document.getElementById('shipment-bulk-pack-btn');
    // Scoped to the document, not the form: the checkboxes live in the table and join the form
    // via the HTML5 form="" attribute, so they are NOT descendants of the form element.
    var boxes = document.querySelectorAll('.shipment-select');

    function sync() {
        if (!bulkBtn) { return; }
        var checked = document.querySelectorAll('.shipment-select:checked').length;
        bulkBtn.disabled = checked === 0;
        bulkBtn.textContent = checked > 0 ? ('Mark Packed (' + checked + ')') : 'Mark Packed';
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            for (var i = 0; i < boxes.length; i++) { boxes[i].checked = selectAll.checked; }
            sync();
        });
    }
    for (var i = 0; i < boxes.length; i++) {
        boxes[i].addEventListener('change', sync);
    }
    sync();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
