<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/purchase_planning.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/customer_storage.php';
require_once __DIR__ . '/includes/orders.php';

app_require_permission('dashboard.view');

$appTitle = 'Dashboard';

$pdo = app_db();

/**
 * Operations Dashboard (Phase 2A) - answers "what should I work on right now", grouped into
 * Orders / Purchasing / Inventory / Shipping / Quick Actions. Every number here is either a
 * direct reuse of an existing function (purchase_planning_needs(), purchase_planning_
 * untargeted_demand(), inventory_allocation_queue(), inventory_reservation_queue() - all
 * unchanged) or a plain read-only COUNT/GROUP BY aggregate over existing tables - no
 * calculation, formula, or write path here or anywhere else was touched. Each section's data
 * (and underlying query) is only fetched at all for a user who holds the permission its
 * linked destination page actually requires - see the per-section $can* flags below - so nei-
 * ther the query cost nor the data is paid for/shown to a user who can't act on it anyway.
 */

// --- Permission flags: one per destination permission domain this dashboard links into -----
$canViewOrders = app_has_permission('orders.view');
$canManageOrders = app_has_permission('orders.manage');
$canViewSupplierOrders = app_has_permission('supplier-orders.view');
$canManageSupplierOrders = app_has_permission('supplier-orders.manage');
$canViewInventory = app_has_permission('inventory.view');
$canManageInventory = app_has_permission('inventory.manage');
$canViewShipMyBox = app_has_permission('ship-my-box.view');
$canViewShipments = app_has_permission('shipments.view');
$canManageShipments = app_has_permission('shipments.manage');

// --- 1. Orders --------------------------------------------------------------------------
// order_status is exclusively written by order_recompute_status() (includes/order_
// fulfillment.php, unchanged) - this is a pure read of that already-computed column, one
// GROUP BY query, no per-order loop. is_historical = 0 excludes imported records, which
// order_recompute_status() never touches anyway (matches the exclusion already used
// everywhere else in this app, e.g. inventory_unit_outstanding_demand()).
// Mapping to the real order_status values (see includes/orders.php's order_status_label()):
//   Waiting for Stock -> 'waiting_stock'      (at least one item still unreserved/unallocated)
//   Ready to Pack      -> 'waiting_ship_my_box' (every item ready, no shipment started yet)
//   Ready to Ship       -> 'ready_to_ship'      (a shipment already exists, not yet shipped)
$orderStatusCounts = ['waiting_stock' => 0, 'waiting_ship_my_box' => 0, 'ready_to_ship' => 0];
if ($canViewOrders) {
    $orderStatusStmt = $pdo->query("
        SELECT order_status, COUNT(*) AS cnt
        FROM mewmii_orders
        WHERE is_historical = 0 AND order_status IN ('waiting_stock', 'waiting_ship_my_box', 'ready_to_ship')
        GROUP BY order_status
    ");
    foreach ($orderStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderStatusCounts[$row['order_status']] = (int) $row['cnt'];
    }
}

// --- 2. Purchasing ------------------------------------------------------------------------
// purchase_planning_needs()/purchase_planning_untargeted_demand() (includes/purchase_
// planning.php) are called exactly once each, unchanged, and their already-computed arrays
// are reused/sliced in PHP below - no second query re-derives what they already calculated.
$purchasePlanningNeeds = [];
$purchasePlanningCount = 0;
$purchasePlanningValue = 0.0;
$belowTargetCount = 0;
$missingTargetCount = 0;
if ($canManageSupplierOrders) {
    $purchasePlanningNeeds = purchase_planning_needs($pdo);
    $purchasePlanningCount = count($purchasePlanningNeeds);
    foreach ($purchasePlanningNeeds as $need) {
        $purchasePlanningValue += (int) $need['suggested_quantity'] * (float) $need['cost_price'];
        // "Products below target stock" is the ready-stock subset of the SAME array just
        // computed above - not a second query or a re-derived formula.
        if ($need['product_type'] === 'ready_stock') {
            $belowTargetCount++;
        }
    }

    $missingTargetCount = count(purchase_planning_untargeted_demand($pdo));
}

// Draft/Ordered/Partially Received counts (one GROUP BY) + Overdue (one COUNT, a plain
// expected_delivery_date < today comparison - no receiving/status logic re-derived).
$supplierOrderStatusCounts = ['draft' => 0, 'ordered' => 0, 'partially_received' => 0];
$overdueSupplierOrderCount = 0;
if ($canViewSupplierOrders) {
    $supplierStatusStmt = $pdo->query("
        SELECT status, COUNT(*) AS cnt
        FROM supplier_orders
        WHERE status IN ('draft', 'ordered', 'partially_received')
        GROUP BY status
    ");
    foreach ($supplierStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $supplierOrderStatusCounts[$row['status']] = (int) $row['cnt'];
    }

    $overdueStmt = $pdo->query("
        SELECT COUNT(*) FROM supplier_orders
        WHERE expected_delivery_date IS NOT NULL AND expected_delivery_date < CURDATE()
          AND status NOT IN ('received', 'completed', 'cancelled')
    ");
    $overdueSupplierOrderCount = (int) $overdueStmt->fetchColumn();
}

// --- 3. Inventory -------------------------------------------------------------------------
// Low Stock mirrors the exact threshold rule inventory_stock_badges() already applies
// (modules/inventory/index.php: ready_stock only, availability_override = 'auto', 0 <
// available < min_stock_threshold) - expressed as one UNION query (simple + each variable
// product's variations) instead of looping that display function over the whole catalog in
// PHP, which would mean fetching every product just to count. Same rule, not a new one.
$lowStockCount = 0;
if ($canViewInventory) {
    $lowStockStmt = $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT inv.available_quantity
            FROM products p
            INNER JOIN mewmii_inventory inv ON inv.product_id = p.id AND inv.variation_id IS NULL
            WHERE p.product_type = 'ready_stock' AND p.catalog_type = 'simple' AND p.status <> 'archived'
              AND p.availability_override = 'auto' AND p.min_stock_threshold IS NOT NULL
              AND inv.available_quantity > 0 AND inv.available_quantity < p.min_stock_threshold
            UNION ALL
            SELECT inv.available_quantity
            FROM product_variations pv
            INNER JOIN products p ON p.id = pv.product_id
            INNER JOIN mewmii_inventory inv ON inv.variation_id = pv.id
            WHERE p.product_type = 'ready_stock' AND p.catalog_type = 'variable' AND pv.status <> 'archived'
              AND p.availability_override = 'auto' AND p.min_stock_threshold IS NOT NULL
              AND inv.available_quantity > 0 AND inv.available_quantity < p.min_stock_threshold
        ) low_stock
    ");
    $lowStockCount = (int) $lowStockStmt->fetchColumn();
}

// Allocation Center / Reservation Queue counts - unchanged existing queue functions, reused
// as Quick Action badges below rather than given their own cards (not part of the 4 named
// Inventory-section metrics, but the data is already being fetched for the Quick Actions
// section either way).
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

// --- 4. Shipping ----------------------------------------------------------------------------
// Ship Requests waiting - unchanged from the previous dashboard version.
$shipRequestPendingCount = 0;
if ($canViewShipMyBox) {
    $shipRequestPendingStmt = $pdo->query("SELECT COUNT(*) FROM ship_requests WHERE status IN ('pending', 'processing')");
    $shipRequestPendingCount = (int) $shipRequestPendingStmt->fetchColumn();
}

// Awaiting Tracking / Created Today (Shipping section) / Shipped Today (Orders section,
// "Shipped Today" is a shipment-date fact, not an order_status value) - one query, three
// plain conditional aggregates over the same shipments table, no per-row loop.
$shipmentAwaitingTrackingCount = 0;
$shipmentsCreatedTodayCount = 0;
$shipmentsShippedTodayCount = 0;
if ($canViewShipments) {
    $shipmentStatsStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN tracking_number IS NULL AND shipping_status <> 'cancelled' THEN 1 ELSE 0 END) AS awaiting_tracking,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS created_today,
            SUM(CASE WHEN shipped_at IS NOT NULL AND DATE(shipped_at) = CURDATE() THEN 1 ELSE 0 END) AS shipped_today
        FROM shipments
    ");
    $shipmentStats = $shipmentStatsStmt->fetch(PDO::FETCH_ASSOC);
    $shipmentAwaitingTrackingCount = (int) ($shipmentStats['awaiting_tracking'] ?? 0);
    $shipmentsCreatedTodayCount = (int) ($shipmentStats['created_today'] ?? 0);
    $shipmentsShippedTodayCount = (int) ($shipmentStats['shipped_today'] ?? 0);
}

require_once __DIR__ . '/includes/header.php';
?>

<h4 class="mb-3">Orders</h4>
<div class="row g-4 mb-4">
    <?php if ($canViewOrders): ?>
        <div class="col-md-6 col-lg-3">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Waiting for Stock</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $orderStatusCounts['waiting_stock']; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/orders/index.php?status=waiting_stock">View Orders</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Ready to Pack</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $orderStatusCounts['waiting_ship_my_box']; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/orders/index.php?status=waiting_ship_my_box">View Orders</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Ready to Ship</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $orderStatusCounts['ready_to_ship']; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/orders/index.php?status=ready_to_ship">View Orders</a>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($canViewShipments): ?>
        <div class="col-md-6 col-lg-3">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Shipped Today</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $shipmentsShippedTodayCount; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/shipments/index.php">View Shipments</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<h4 class="mb-3">Purchasing</h4>
<div class="row g-4 mb-4">
    <?php if ($canManageSupplierOrders): ?>
        <div class="col-md-6 col-lg-3">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Purchase Planning</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $purchasePlanningCount; ?></h2>
                <p class="text-muted small mb-2">RM <?php echo app_escape(number_format($purchasePlanningValue, 2)); ?> estimated</p>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/purchase-planning/generate.php">Review &amp; Generate</a>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($canViewSupplierOrders): ?>
        <div class="col-md-6 col-lg-3">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Draft Supplier Orders</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $supplierOrderStatusCounts['draft']; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/supplier-orders/index.php">View Supplier Orders</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Awaiting Confirmation</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $supplierOrderStatusCounts['ordered']; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/supplier-orders/index.php">View Supplier Orders</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Arriving</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $supplierOrderStatusCounts['partially_received']; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/supplier-orders/index.php">View Supplier Orders</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Overdue</h6>
                <h2 class="fw-bold mb-0 <?php echo $overdueSupplierOrderCount > 0 ? 'text-danger' : ''; ?>"><?php echo (int) $overdueSupplierOrderCount; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/supplier-orders/index.php">View Supplier Orders</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<h4 class="mb-3">Inventory</h4>
<div class="row g-4 mb-4">
    <?php if ($canManageSupplierOrders): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Below Target Stock</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $belowTargetCount; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/purchase-planning/generate.php">Review in Purchase Planning</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Missing Target Stock</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $missingTargetCount; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/purchase-planning/generate.php">Review in Purchase Planning</a>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($canViewInventory): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Low Stock Alerts</h6>
                <h2 class="fw-bold mb-0 <?php echo $lowStockCount > 0 ? 'text-danger' : ''; ?>"><?php echo (int) $lowStockCount; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/inventory/index.php?stock_status=low_stock">View Inventory</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<h4 class="mb-3">Shipping</h4>
<div class="row g-4 mb-4">
    <?php if ($canViewShipMyBox): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Ship Requests Waiting</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $shipRequestPendingCount; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/ship-my-box/index.php">View Ship My Box</a>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($canViewShipments): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Awaiting Tracking</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $shipmentAwaitingTrackingCount; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/shipments/index.php">View Shipments</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 h-100 d-flex flex-column">
                <h6 class="text-muted mb-2">Shipments Created Today</h6>
                <h2 class="fw-bold mb-0"><?php echo (int) $shipmentsCreatedTodayCount; ?></h2>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/shipments/index.php">View Shipments</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<h4 class="mb-3">Quick Actions</h4>
<div class="row g-3 mb-4">
    <?php if ($canManageOrders): ?>
        <div class="col-md-4 col-lg-2">
            <a class="btn btn-primary w-100 h-100 py-3" href="/modules/orders/create.php">Create Customer Order</a>
        </div>
    <?php endif; ?>
    <?php if ($canManageSupplierOrders): ?>
        <div class="col-md-4 col-lg-2">
            <a class="btn btn-primary w-100 h-100 py-3" href="/modules/purchase-planning/generate.php">
                Generate Purchase Planning
                <?php if ($purchasePlanningCount > 0): ?><span class="badge bg-light text-dark ms-1"><?php echo (int) $purchasePlanningCount; ?></span><?php endif; ?>
            </a>
        </div>
        <div class="col-md-4 col-lg-2">
            <a class="btn btn-primary w-100 h-100 py-3" href="/modules/supplier-orders/index.php">
                Receive Supplier Order
                <?php $receivable = $supplierOrderStatusCounts['ordered'] + $supplierOrderStatusCounts['partially_received']; ?>
                <?php if ($receivable > 0): ?><span class="badge bg-light text-dark ms-1"><?php echo (int) $receivable; ?></span><?php endif; ?>
            </a>
        </div>
    <?php endif; ?>
    <?php if ($canManageInventory): ?>
        <div class="col-md-4 col-lg-2">
            <a class="btn btn-primary w-100 h-100 py-3" href="/modules/inventory/reservation-center.php">
                Reserve Waiting Orders
                <?php if ($reservationCount > 0): ?><span class="badge bg-light text-dark ms-1"><?php echo (int) $reservationCount; ?></span><?php endif; ?>
            </a>
        </div>
        <div class="col-md-4 col-lg-2">
            <a class="btn btn-primary w-100 h-100 py-3" href="/modules/inventory/allocation-center.php">
                Allocate Preorders
                <?php if ($allocationCount > 0): ?><span class="badge bg-light text-dark ms-1"><?php echo (int) $allocationCount; ?></span><?php endif; ?>
            </a>
        </div>
    <?php endif; ?>
    <?php if ($canManageShipments): ?>
        <div class="col-md-4 col-lg-2">
            <a class="btn btn-primary w-100 h-100 py-3" href="/modules/shipments/index.php">Ship Orders</a>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
