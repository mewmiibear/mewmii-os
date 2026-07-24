<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/purchase_planning.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/customer_storage.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/wc_order_import.php';

app_require_permission('dashboard.view');

$appTitle = 'Dashboard';

$pdo = app_db();

/**
 * Operations Command Centre (Dashboard Phase 3) - answers one question, "what needs my
 * attention today", via 6 named sections matching this app's own workflow areas: Receipt
 * Verification, Purchasing, Fulfilment, Customer Actions, Inventory, WooCommerce - followed by
 * the pre-existing Quick Actions and Business Snapshot (unchanged, kept below the new sections
 * rather than removed). Every number is either a direct reuse of an existing function
 * (purchase_planning_needs(), purchase_planning_untargeted_demand(), inventory_allocation_
 * queue(), inventory_reservation_queue(), wc_order_import_get_setting() - all unchanged) or a
 * plain read-only COUNT/GROUP BY aggregate over existing tables - no calculation, formula, or
 * write path here or anywhere else was touched. Each section's data (and underlying query) is
 * only fetched at all for a user who holds the permission its linked destination page actually
 * requires - see the per-section $can* flags below - so neither the query cost nor the data is
 * paid for/shown to a user who can't act on it anyway.
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

// --- 5. Business Snapshot -------------------------------------------------------------------
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

// --- 6. Receipt Verification (Operations Command Centre) ----------------------------------
// mewmii_orders.receipt_status is set exclusively by the WooCommerce importer/receipt
// verification workflow (includes/wc_order_import.php, modules/orders/view.php) - this is a
// plain filtered read of that already-computed column, same permission gate as the Orders
// section above (links straight to modules/orders/view.php, which requires orders.view).
$pendingReceiptCount = 0;
$pendingReceipts = [];
if ($canViewOrders) {
    $pendingReceiptCountStmt = $pdo->query("SELECT COUNT(*) FROM mewmii_orders WHERE receipt_status = 'pending'");
    $pendingReceiptCount = (int) $pendingReceiptCountStmt->fetchColumn();

    $pendingReceiptsStmt = $pdo->query("
        SELECT o.id, o.order_number, o.updated_at, c.name AS customer_name
        FROM mewmii_orders o
        LEFT JOIN customers c ON c.id = o.customer_id
        WHERE o.receipt_status = 'pending'
        ORDER BY o.updated_at ASC
        LIMIT 8
    ");
    $pendingReceipts = $pendingReceiptsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 7. Customer Actions (Operations Command Centre) ---------------------------------------
$canViewCustomerStorage = app_has_permission('customer-storage.view');
$customerStorageAttentionCount = 0;
if ($canViewCustomerStorage) {
    // Same per-lot rule ship_request_storage_lot_available()/ship_request_lot_committed_
    // quantity() (includes/ship_my_box.php) already apply, expressed as one batch query
    // instead of looping those functions over every stored lot - the exact same "batch an
    // existing per-unit rule instead of re-deriving it" precedent the Low Stock query below
    // already uses. A lot "requires attention" once it has stored quantity not yet claimed by
    // an open ('pending'/'processing') ship request - nothing here decides differently than
    // those two functions already do.
    $storageAttentionStmt = $pdo->query("
        SELECT COUNT(*) FROM customer_storage cs
        WHERE cs.status = 'stored' AND cs.quantity > 0
          AND cs.quantity > (
              SELECT COALESCE(SUM(sri.quantity), 0)
              FROM ship_request_items sri
              INNER JOIN ship_requests sr ON sr.id = sri.ship_request_id
              WHERE sri.customer_storage_id = cs.id AND sr.status IN ('pending', 'processing')
          )
    ");
    $customerStorageAttentionCount = (int) $storageAttentionStmt->fetchColumn();
}

// --- 8. Inventory (Operations Command Centre additions) ------------------------------------
// Negative stock is a trivial anomaly check on mewmii_inventory.available_quantity - the same
// authoritative column every other inventory query in this app already reads (including Low
// Stock above) - not a new stock calculation, just a threshold check flagging bad data.
// missingTargetCount (purchase_planning_untargeted_demand(), section 2 above) is reused here,
// not recomputed.
$negativeStockCount = 0;
if ($canViewInventory) {
    $negativeStockStmt = $pdo->query('SELECT COUNT(*) FROM mewmii_inventory WHERE available_quantity < 0');
    $negativeStockCount = (int) $negativeStockStmt->fetchColumn();
}

// --- 9. WooCommerce Sync (Operations Command Centre) ----------------------------------------
// Entirely reused from the Sync Automation phases (includes/wc_order_import.php,
// modules/integrations/woocommerce.php) - same settings keys, same sync_logs table/sync_type,
// no new sync state introduced here. Gated on settings.manage, matching the integration page's
// own permission requirement (its destination controls the gate, same convention as every
// other section on this dashboard).
$canManageIntegrations = app_has_permission('settings.manage');
$wcLastRunSummary = null;
$wcLastSyncCursor = null;
$wcFailedTodayCount = 0;
$wcImportedTodayCount = 0;
if ($canManageIntegrations) {
    $wcLastSyncCursor = wc_order_import_get_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT);
    $wcLastRunSummaryRaw = wc_order_import_get_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_RUN_SUMMARY);
    $wcLastRunSummaryDecoded = $wcLastRunSummaryRaw !== null ? json_decode($wcLastRunSummaryRaw, true) : null;
    $wcLastRunSummary = is_array($wcLastRunSummaryDecoded) ? $wcLastRunSummaryDecoded : null;

    $wcSyncTodayStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'failed' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS failed_today,
            SUM(CASE WHEN status = 'success' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS imported_today
        FROM sync_logs
        WHERE sync_type = ?
    ");
    $wcSyncTodayStmt->execute([WC_ORDER_IMPORT_SYNC_TYPE]);
    $wcSyncTodayStats = $wcSyncTodayStmt->fetch(PDO::FETCH_ASSOC);
    $wcFailedTodayCount = (int) ($wcSyncTodayStats['failed_today'] ?? 0);
    $wcImportedTodayCount = (int) ($wcSyncTodayStats['imported_today'] ?? 0);
}
// "Healthy" is narrowly defined as "no failures observed" (this run's per-order failures, plus
// any failed sync_logs rows today) - deliberately NOT a staleness/freshness check, since there
// is no stored "expected sync interval" anywhere in this app to compare against without
// inventing one.
$wcSyncHealthy = $canManageIntegrations
    && $wcLastRunSummary !== null
    && (int) ($wcLastRunSummary['failed'] ?? 0) === 0
    && $wcFailedTodayCount === 0;

/**
 * Display-only GMT->local conversion for the WooCommerce section, identical helper to the one
 * on modules/integrations/woocommerce.php (not shared via an include since each page is small
 * enough that a tiny local copy is simpler than a new shared file for one 10-line function).
 */
function dashboard_format_gmt_setting(?string $gmtTimestamp): ?string
{
    if ($gmtTimestamp === null || $gmtTimestamp === '') {
        return null;
    }

    try {
        $dt = new DateTime($gmtTimestamp, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $dt->format('Y-m-d H:i:s');
    } catch (Exception) {
        return $gmtTimestamp;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <h3 class="mb-1">Operations Command Centre</h3>
    <p class="text-muted small mb-0">What needs your attention today, grouped by workflow area. Every number links straight to where you'd act on it.</p>
</div>

<?php if ($canViewOrders): ?>
<div class="mb-4">
    <h4 class="mb-1">1. Receipt Verification</h4>
    <p class="text-muted small mb-3">Bank transfer / QR receipts waiting on an approve or reject decision.</p>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card stat-card p-4 h-100 d-flex flex-column">
                <div class="stat-label">Pending Receipt Approvals</div>
                <div class="stat-value <?php echo $pendingReceiptCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $pendingReceiptCount; ?></div>
                <div class="stat-helper mb-2">Awaiting an Approve/Reject decision.</div>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/orders/index.php">View Orders</a>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card p-4 h-100">
                <?php if ($pendingReceipts === []): ?>
                    <p class="text-muted small mb-0">No receipts waiting on review right now.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($pendingReceipts as $order): ?>
                            <li class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small">
                                    <?php echo app_escape(order_display_number($order['order_number'])); ?>
                                    <?php if (!empty($order['customer_name'])): ?>
                                        &mdash; <?php echo app_escape($order['customer_name']); ?>
                                    <?php endif; ?>
                                </span>
                                <a class="btn btn-outline-primary btn-sm" href="/modules/orders/view.php?id=<?php echo (int) $order['id']; ?>">Review &rarr;</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($pendingReceiptCount > count($pendingReceipts)): ?>
                        <div class="text-muted small mt-2">+ <?php echo (int) ($pendingReceiptCount - count($pendingReceipts)); ?> more - see Orders.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canViewSupplierOrders || $canManageSupplierOrders): ?>
<div class="mb-4">
    <h4 class="mb-1">2. Purchasing</h4>
    <p class="text-muted small mb-3">What to buy, and what's already on order.</p>
    <div class="row g-4">
        <?php if ($canManageSupplierOrders): ?>
            <div class="col-md-4">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Products Needing Purchase</div>
                    <div class="stat-value <?php echo $purchasePlanningCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $purchasePlanningCount; ?></div>
                    <div class="stat-helper mb-2">Below target stock, or below outstanding customer demand.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/purchase-planning/generate.php">Generate Purchase Planning</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($canViewSupplierOrders): ?>
            <div class="col-md-4">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Draft Supplier Orders</div>
                    <div class="stat-value"><?php echo (int) $supplierOrderStatusCounts['draft']; ?></div>
                    <div class="stat-helper mb-2">Created but not yet placed with the supplier.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/supplier-orders/index.php">View Supplier Orders</a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Overdue Supplier Orders</div>
                    <div class="stat-value <?php echo $overdueSupplierOrderCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $overdueSupplierOrderCount; ?></div>
                    <div class="stat-helper mb-2">Past expected delivery date, not yet received.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/supplier-orders/index.php?filter=overdue">View Overdue</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($canManageInventory || $canViewOrders || $canViewShipments): ?>
<div class="mb-4">
    <h4 class="mb-1">3. Fulfilment</h4>
    <p class="text-muted small mb-3">Paid orders moving from stock to shipment.</p>
    <div class="row g-4">
        <?php if ($canManageInventory): ?>
            <div class="col-md-3">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Waiting Reservation</div>
                    <div class="stat-value <?php echo $reservationCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $reservationCount; ?></div>
                    <div class="stat-helper mb-2">Paid ready-stock items not yet reserved.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/inventory/reservation-center.php">Reservation Center</a>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Waiting Allocation</div>
                    <div class="stat-value <?php echo $allocationCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $allocationCount; ?></div>
                    <div class="stat-helper mb-2">Preorder/Early Bird stock arrived, not yet allocated.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/inventory/allocation-center.php">Allocation Center</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($canViewOrders): ?>
            <div class="col-md-3">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Ready to Ship</div>
                    <div class="stat-value"><?php echo (int) $orderStatusCounts['ready_to_ship']; ?></div>
                    <div class="stat-helper mb-2">Shipment already created, not yet shipped.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/orders/index.php?status=ready_to_ship">View Orders</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($canViewShipments): ?>
            <div class="col-md-3">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Shipment Requests Waiting</div>
                    <div class="stat-value <?php echo $shipmentAwaitingTrackingCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $shipmentAwaitingTrackingCount; ?></div>
                    <div class="stat-helper mb-2">Shipment created, still missing a tracking number.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/shipments/index.php">View Shipments</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($canViewShipMyBox || $canViewCustomerStorage): ?>
<div class="mb-4">
    <h4 class="mb-1">4. Customer Actions</h4>
    <p class="text-muted small mb-3">Requests and stored items that need a customer-facing decision.</p>
    <div class="row g-4">
        <?php if ($canViewShipMyBox): ?>
            <div class="col-md-6">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Ship My Box Requests</div>
                    <div class="stat-value <?php echo $shipRequestPendingCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $shipRequestPendingCount; ?></div>
                    <div class="stat-helper mb-2">Pending or in review.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/ship-my-box/index.php">View Requests</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($canViewCustomerStorage): ?>
            <div class="col-md-6">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Customer Storage Requiring Attention</div>
                    <div class="stat-value <?php echo $customerStorageAttentionCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $customerStorageAttentionCount; ?></div>
                    <div class="stat-helper mb-2">Stored items not yet claimed by an open ship request.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/customer-storage/index.php">View Customer Storage</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($canViewInventory || $canManageSupplierOrders): ?>
<div class="mb-4">
    <h4 class="mb-1">5. Inventory</h4>
    <p class="text-muted small mb-3">Stock levels and data integrity checks.</p>
    <div class="row g-4">
        <?php if ($canViewInventory): ?>
            <div class="col-md-4">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Low Stock</div>
                    <div class="stat-value <?php echo $lowStockCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $lowStockCount; ?></div>
                    <div class="stat-helper mb-2">Ready-stock products below threshold.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/inventory/index.php?stock_status=low_stock">View Inventory</a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Negative Stock</div>
                    <div class="stat-value <?php echo $negativeStockCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $negativeStockCount; ?></div>
                    <div class="stat-helper mb-2">Data integrity check - should always read zero.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/inventory/index.php">View Inventory</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($canManageSupplierOrders): ?>
            <div class="col-md-4">
                <div class="card stat-card p-4 h-100 d-flex flex-column">
                    <div class="stat-label">Inventory Warnings</div>
                    <div class="stat-value <?php echo $missingTargetCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $missingTargetCount; ?></div>
                    <div class="stat-helper mb-2">Ready-stock items with real demand but no target stock level set - can never appear in Purchase Planning until fixed.</div>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/products/index.php">Review Products</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($canManageIntegrations): ?>
<div class="mb-4">
    <h4 class="mb-1">6. WooCommerce</h4>
    <p class="text-muted small mb-3">Order sync health with mewmiibear.com.</p>
    <div class="row g-4">
        <div class="col-md-3">
            <div class="card stat-card p-4 h-100 d-flex flex-column">
                <div class="stat-label">Last Sync Run</div>
                <div class="stat-value" style="font-size: 1.25rem;"><?php echo app_escape(dashboard_format_gmt_setting($wcLastRunSummary['ran_at'] ?? null) ?? 'Never'); ?></div>
                <div class="stat-helper mb-2">Manual or automated - whichever ran most recently.</div>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/integrations/woocommerce.php">View Integration</a>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card p-4 h-100 d-flex flex-column">
                <div class="stat-label">Sync Health</div>
                <div class="stat-value <?php echo $wcSyncHealthy ? '' : 'stat-value-alert'; ?>" style="font-size: 1.25rem;"><?php echo $wcSyncHealthy ? 'Healthy' : 'Needs Review'; ?></div>
                <div class="stat-helper mb-2">Based on the most recent run and today's activity.</div>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/sync-logs/index.php">View Sync Logs</a>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card p-4 h-100 d-flex flex-column">
                <div class="stat-label">Failed Syncs Today</div>
                <div class="stat-value <?php echo $wcFailedTodayCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $wcFailedTodayCount; ?></div>
                <div class="stat-helper mb-2">Individual order sync failures today.</div>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/sync-logs/index.php">View Sync Logs</a>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card p-4 h-100 d-flex flex-column">
                <div class="stat-label">Orders Imported Today</div>
                <div class="stat-value"><?php echo (int) $wcImportedTodayCount; ?></div>
                <div class="stat-helper mb-2">Created or updated from WooCommerce today.</div>
                <a class="btn btn-outline-primary btn-sm mt-auto" href="/modules/integrations/woocommerce.php">View Integration</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
