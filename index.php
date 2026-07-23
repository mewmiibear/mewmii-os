<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/purchase_planning.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/customer_storage.php';

app_require_permission('dashboard.view');

$appTitle = 'Dashboard';

$pdo = app_db();

// Per-widget destination permission - each widget must only render (data included) for a
// user who actually holds the permission its linked page requires, not just dashboard.view.
// Allocation Center and Reservation Queue both link to inventory.view-gated pages, so they
// share one check.
$canManageSupplierOrders = app_has_permission('supplier-orders.manage');
$canViewSupplierOrders = app_has_permission('supplier-orders.view');
$canViewInventory = app_has_permission('inventory.view');
$canViewShipMyBox = app_has_permission('ship-my-box.view');

// Purchase Planning: reuses purchase_planning_needs() (includes/purchase_planning.php)
// unchanged - one row per sellable unit (product or variation) currently needing a supplier
// order. Estimated Purchase Value sums the same suggested_quantity * cost_price figure
// already shown per line on modules/purchase-planning/generate.php. Only computed at all for
// a user who can reach that page (supplier-orders.manage) - a user without it never sees the
// count, the value, or the link.
$purchasePlanningCount = 0;
$purchasePlanningValue = 0.0;
if ($canManageSupplierOrders) {
    $purchasePlanningNeeds = purchase_planning_needs($pdo);
    $purchasePlanningCount = count($purchasePlanningNeeds);
    foreach ($purchasePlanningNeeds as $need) {
        $purchasePlanningValue += (int) $need['suggested_quantity'] * (float) $need['cost_price'];
    }
}

// Allocation Center / Reservation Queue: both reuse their existing queue functions
// unchanged, grouped by product with a nested 'units' list (one entry per sellable SKU/
// variation) - counting units rather than products so the widget reflects the actual number
// of queue rows an admin would see on those pages. Only computed for inventory.view.
$allocationCount = 0;
$reservationCount = 0;
if ($canViewInventory) {
    foreach (inventory_allocation_queue($pdo) as $product) {
        $allocationCount += count($product['units']);
    }

    foreach (inventory_reservation_queue($pdo) as $product) {
        $reservationCount += count($product['units']);
    }
}

// Supplier Orders: counts by the real backend status values - 'received' is labelled
// "Receiving" here per the operational wording requested for this widget (elsewhere in the
// app it displays as "Arrived", e.g. supplier_order_status_label()). No new status value.
// Only computed for supplier-orders.view.
$supplierStatusCounts = array_fill_keys(['draft', 'ordered', 'partially_received', 'received'], 0);
if ($canViewSupplierOrders) {
    $supplierStatusStmt = $pdo->query("
        SELECT status, COUNT(*) AS cnt
        FROM supplier_orders
        WHERE status IN ('draft', 'ordered', 'partially_received', 'received')
        GROUP BY status
    ");
    foreach ($supplierStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $supplierStatusCounts[$row['status']] = (int) $row['cnt'];
    }
}

// Ship My Box: requests not yet shipped (pending/processing) - the two statuses before
// ship_request_process() actually creates+ships a shipment (see includes/ship_my_box.php).
// Only computed for ship-my-box.view.
$shipRequestPendingCount = 0;
if ($canViewShipMyBox) {
    $shipRequestPendingStmt = $pdo->query("SELECT COUNT(*) FROM ship_requests WHERE status IN ('pending', 'processing')");
    $shipRequestPendingCount = (int) $shipRequestPendingStmt->fetchColumn();
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row g-4">

    <?php if ($canManageSupplierOrders): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Purchase Planning</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $purchasePlanningCount; ?></h2>
                <p class="text-muted small mb-2">product(s) needing ordering</p>
                <p class="mb-3">Estimated Purchase Value: <strong>RM <?php echo app_escape(number_format($purchasePlanningValue, 2)); ?></strong></p>
                <a class="btn btn-primary btn-sm mt-auto" href="/modules/purchase-planning/generate.php">Review &amp; Generate</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canViewInventory): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Allocation Center</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $allocationCount; ?></h2>
                <p class="text-muted small mb-3">item(s) waiting allocation</p>
                <a class="btn btn-primary btn-sm mt-auto" href="/modules/inventory/allocation-center.php">Open Allocation Center</a>
            </div>
        </div>

        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Reservation Queue</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $reservationCount; ?></h2>
                <p class="text-muted small mb-3">item(s) waiting reservation</p>
                <a class="btn btn-primary btn-sm mt-auto" href="/modules/inventory/reservation-center.php">Open Reservation Center</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canViewSupplierOrders): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-3">Supplier Orders</h6>
                <ul class="list-unstyled mb-3 flex-grow-1">
                    <li class="d-flex justify-content-between border-bottom py-1"><span>Draft</span><strong><?php echo (int) $supplierStatusCounts['draft']; ?></strong></li>
                    <li class="d-flex justify-content-between border-bottom py-1"><span>Ordered</span><strong><?php echo (int) $supplierStatusCounts['ordered']; ?></strong></li>
                    <li class="d-flex justify-content-between border-bottom py-1"><span>Partially Received</span><strong><?php echo (int) $supplierStatusCounts['partially_received']; ?></strong></li>
                    <li class="d-flex justify-content-between py-1"><span>Receiving</span><strong><?php echo (int) $supplierStatusCounts['received']; ?></strong></li>
                </ul>
                <a class="btn btn-primary btn-sm mt-auto" href="/modules/supplier-orders/index.php">Open Supplier Orders</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canViewShipMyBox): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Ship My Box</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $shipRequestPendingCount; ?></h2>
                <p class="text-muted small mb-3">request(s) pending / processing</p>
                <a class="btn btn-primary btn-sm mt-auto" href="/modules/ship-my-box/index.php">Open Ship My Box</a>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>