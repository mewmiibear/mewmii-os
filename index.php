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
 * Operations Dashboard (UI/UX Refinement Phase) - answers "what should I work on right now",
 * grouped into Today's Overview / Needs Attention / Quick Actions / Business Snapshot. Every
 * number is either a direct reuse of an existing function (purchase_planning_needs(),
 * purchase_planning_untargeted_demand(), inventory_allocation_queue(), inventory_reservation_
 * queue() - all unchanged) or a plain read-only COUNT/GROUP BY aggregate over existing tables -
 * no calculation, formula, or write path here or anywhere else was touched. Business Snapshot's
 * three queries are new but reuse the exact "valid order" condition and query shapes already
 * established in modules/reports/sales.php and modules/customers/index.php, just scoped to a
 * fixed 30-day window. Each section's data (and underlying query) is only fetched at all for a
 * user who holds the permission its linked destination page actually requires - see the
 * per-section $can* flags below - so neither the query cost nor the data is paid for/shown to
 * a user who can't act on it anyway.
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
$canViewCustomers = app_has_permission('customers.view');

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

// --- 5. Needs Attention (display-only regrouping of the numbers above) --------------------
// Every entry here reuses a count already computed in sections 1-4 - no new calculation, just
// a short, filtered list of "the ones that are actually non-zero right now" so the dashboard
// doesn't show a wall of empty rows. Same permission gate each count's own section already used.
$attentionItems = [];
if ($canViewOrders && $orderStatusCounts['waiting_stock'] > 0) {
    $attentionItems[] = ['label' => 'Orders Waiting on Stock', 'count' => $orderStatusCounts['waiting_stock'], 'url' => '/modules/orders/index.php?status=waiting_stock', 'tone' => 'danger'];
}
if ($canViewInventory && $lowStockCount > 0) {
    $attentionItems[] = ['label' => 'Low Stock Products', 'count' => $lowStockCount, 'url' => '/modules/inventory/index.php?stock_status=low_stock', 'tone' => 'danger'];
}
if ($canViewSupplierOrders && $overdueSupplierOrderCount > 0) {
    $attentionItems[] = ['label' => 'Overdue Supplier Orders', 'count' => $overdueSupplierOrderCount, 'url' => '/modules/supplier-orders/index.php?filter=overdue', 'tone' => 'danger'];
}
if ($canViewOrders && $orderStatusCounts['ready_to_ship'] > 0) {
    $attentionItems[] = ['label' => 'Orders Ready to Ship', 'count' => $orderStatusCounts['ready_to_ship'], 'url' => '/modules/orders/index.php?status=ready_to_ship', 'tone' => 'warning'];
}
if ($canManageInventory && $reservationCount > 0) {
    $attentionItems[] = ['label' => 'Orders Waiting to Be Reserved', 'count' => $reservationCount, 'url' => '/modules/inventory/reservation-center.php', 'tone' => 'warning'];
}
if ($canManageInventory && $allocationCount > 0) {
    $attentionItems[] = ['label' => 'Preorders Waiting to Be Allocated', 'count' => $allocationCount, 'url' => '/modules/inventory/allocation-center.php', 'tone' => 'warning'];
}
if ($canViewShipMyBox && $shipRequestPendingCount > 0) {
    $attentionItems[] = ['label' => 'Ship Requests Waiting', 'count' => $shipRequestPendingCount, 'url' => '/modules/ship-my-box/index.php', 'tone' => 'warning'];
}
if ($canViewShipments && $shipmentAwaitingTrackingCount > 0) {
    $attentionItems[] = ['label' => 'Shipments Awaiting Tracking Number', 'count' => $shipmentAwaitingTrackingCount, 'url' => '/modules/shipments/index.php', 'tone' => 'warning'];
}

// --- 6. Business Snapshot ------------------------------------------------------------------
// New for this phase, but not new logic: these three queries reuse the exact "valid order"
// condition and query shape already established in modules/reports/sales.php (payment_status
// = 'paid' AND order_status <> 'cancelled') and the aggregation style already used in
// modules/customers/index.php - just scoped down to a fixed, lightweight 30-day window with no
// filter UI, since the dashboard links out to the full report for anything beyond a glance.
$snapshotPeriodCondition = "o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";

$salesSnapshot = ['total_orders' => 0, 'units_sold' => 0, 'revenue' => 0.0, 'active_customers' => 0];
$topProducts = [];
if ($canViewOrders) {
    $salesSnapshotStmt = $pdo->query("
        SELECT
            COUNT(DISTINCT o.id) AS total_orders,
            COALESCE(SUM(oi.quantity), 0) AS units_sold,
            COALESCE(SUM(oi.subtotal), 0) AS revenue,
            COUNT(DISTINCT o.customer_id) AS active_customers
        FROM mewmii_orders o
        INNER JOIN mewmii_order_items oi ON oi.order_id = o.id
        WHERE {$snapshotPeriodCondition}
    ");
    $salesSnapshot = $salesSnapshotStmt->fetch(PDO::FETCH_ASSOC);

    // Top 5 sellers, same shape as modules/reports/sales.php's Best Selling Products query,
    // just LIMIT 5 instead of 20 for a dashboard-sized glance.
    $topProductsStmt = $pdo->query("
        SELECT p.id AS product_id, p.name AS product_name, SUM(oi.quantity) AS units_sold, SUM(oi.subtotal) AS revenue
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        INNER JOIN products p ON p.id = oi.product_id
        WHERE {$snapshotPeriodCondition}
        GROUP BY p.id, p.name
        ORDER BY revenue DESC
        LIMIT 5
    ");
    $topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);
}
$salesSnapshot['average_order_value'] = ((int) $salesSnapshot['total_orders'] > 0)
    ? ((float) $salesSnapshot['revenue'] / (int) $salesSnapshot['total_orders'])
    : null;

$newCustomerCount = 0;
if ($canViewCustomers) {
    $newCustomerStmt = $pdo->query('SELECT COUNT(*) FROM customers WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)');
    $newCustomerCount = (int) $newCustomerStmt->fetchColumn();
}

// Product row links go to modules/products/view.php, which requires products.view - the
// destination controls permission, not this page's own dashboard.view gate.
$canViewProducts = app_has_permission('products.view');

require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <h4 class="mb-1">Today's Overview</h4>
    <p class="text-muted small mb-3">The numbers that matter for running the warehouse today.</p>
    <div class="row g-4">
        <?php if ($canViewOrders): ?>
            <div class="col-md-6 col-lg-3">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Orders Requiring Attention</div>
                    <div class="stat-value"><?php echo (int) ($orderStatusCounts['waiting_stock'] + $orderStatusCounts['ready_to_ship']); ?></div>
                    <div class="stat-helper mb-2">Waiting on stock or ready to ship</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/orders/index.php">View Orders</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($canViewInventory): ?>
            <div class="col-md-6 col-lg-3">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Low Stock</div>
                    <div class="stat-value <?php echo $lowStockCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $lowStockCount; ?></div>
                    <div class="stat-helper mb-2">Ready-stock products below threshold</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/inventory/index.php?stock_status=low_stock">View Inventory</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($canViewSupplierOrders): ?>
            <div class="col-md-6 col-lg-3">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Incoming Supplier Orders</div>
                    <div class="stat-value"><?php echo (int) ($supplierOrderStatusCounts['ordered'] + $supplierOrderStatusCounts['partially_received']); ?></div>
                    <div class="stat-helper mb-2">Confirmed or arriving</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/supplier-orders/index.php">View Supplier Orders</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($canViewOrders): ?>
            <div class="col-md-6 col-lg-3">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Fulfillment Status</div>
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <span class="text-muted small">Ready to Pack</span>
                        <span class="fw-bold fs-5"><?php echo (int) $orderStatusCounts['waiting_ship_my_box']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <span class="text-muted small">Ready to Ship</span>
                        <span class="fw-bold fs-5"><?php echo (int) $orderStatusCounts['ready_to_ship']; ?></span>
                    </div>
                    <?php if ($canViewShipments): ?>
                        <div class="d-flex justify-content-between align-items-baseline mb-2">
                            <span class="text-muted small">Shipped Today</span>
                            <span class="fw-bold fs-5"><?php echo (int) $shipmentsShippedTodayCount; ?></span>
                        </div>
                    <?php endif; ?>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/orders/index.php">View Orders</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="mb-4">
    <h4 class="mb-1">Needs Attention</h4>
    <p class="text-muted small mb-3">Only shows what actually needs a decision right now.</p>
    <div class="card p-4">
        <?php if ($attentionItems === []): ?>
            <p class="text-muted mb-0">All caught up - nothing needs attention right now. 🌸</p>
        <?php else: ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($attentionItems as $item): ?>
                    <div class="attention-item tone-<?php echo app_escape($item['tone']); ?> d-flex justify-content-between align-items-center p-3">
                        <span><?php echo app_escape($item['label']); ?></span>
                        <div class="d-flex align-items-center gap-3">
                            <span class="fw-bold fs-5"><?php echo (int) $item['count']; ?></span>
                            <a class="btn btn-outline-primary btn-sm" href="<?php echo app_escape($item['url']); ?>">Resolve &rarr;</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="mb-4">
    <h4 class="mb-3">Quick Actions</h4>
    <div class="row g-3">
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
</div>

<?php if ($canViewOrders): ?>
<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <h4 class="mb-0">Business Snapshot</h4>
        <a class="small" href="/modules/reports/sales.php">View Full Sales Report &rarr;</a>
    </div>
    <p class="text-muted small mb-3">Last 30 days, at a glance.</p>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card p-4 h-100">
                <h6 class="text-muted mb-3">Sales &amp; Orders</h6>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="text-muted small">Orders</div>
                        <div class="fs-5 fw-bold"><?php echo (int) $salesSnapshot['total_orders']; ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Units Sold</div>
                        <div class="fs-5 fw-bold"><?php echo (int) $salesSnapshot['units_sold']; ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Revenue</div>
                        <div class="fs-5 fw-bold">RM <?php echo app_escape(number_format((float) $salesSnapshot['revenue'], 2)); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Avg Order Value</div>
                        <div class="fs-5 fw-bold">
                            <?php if ($salesSnapshot['average_order_value'] !== null): ?>
                                RM <?php echo app_escape(number_format($salesSnapshot['average_order_value'], 2)); ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card p-4 h-100">
                <h6 class="text-muted mb-3">Top Selling Products</h6>
                <?php if ($topProducts === []): ?>
                    <p class="text-muted small mb-0">No sales in the last 30 days.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($topProducts as $product): ?>
                            <li class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small">
                                    <?php if ($canViewProducts): ?>
                                        <a href="/modules/products/view.php?id=<?php echo (int) $product['product_id']; ?>"><?php echo app_escape($product['product_name']); ?></a>
                                    <?php else: ?>
                                        <?php echo app_escape($product['product_name']); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="text-muted small text-nowrap ms-2"><?php echo (int) $product['units_sold']; ?> sold</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($canViewCustomers): ?>
            <div class="col-lg-3">
                <div class="card p-4 h-100">
                    <h6 class="text-muted mb-3">Customer Activity</h6>
                    <div class="mb-2">
                        <div class="text-muted small">Active Customers</div>
                        <div class="fs-5 fw-bold"><?php echo (int) $salesSnapshot['active_customers']; ?></div>
                    </div>
                    <div>
                        <div class="text-muted small">New Customers</div>
                        <div class="fs-5 fw-bold"><?php echo (int) $newCustomerCount; ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
