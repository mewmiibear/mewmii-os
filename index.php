<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/purchase_planning.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/customer_storage.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/wc_order_import.php';
require_once __DIR__ . '/includes/dashboard.php';

app_require_permission('dashboard.view');

$appTitle = 'Dashboard';

$pdo = app_db();

/**
 * Operations Command Centre (UI/UX Phase 5A restructure) - answers one question, "what needs
 * my attention today", via: a Top Summary strip (Orders Today, Pending Actions, Inventory
 * Health, Purchasing, Sync Health), a consolidated Needs Attention list (receipts waiting, low
 * stock, supplier issues, shipment issues), and a Recent Activity timeline - followed by the
 * pre-existing Quick Actions and Business Snapshot (unchanged, kept below). Replaces the
 * earlier Phase 3 version's 6 full sections (Receipt Verification/Purchasing/Fulfilment/
 * Customer Actions/Inventory/WooCommerce), each with its own card row - same underlying data,
 * consolidated into fewer, denser surfaces per the Phase 5 UI/UX audit's explicit goal of not
 * overwhelming the page with boxes. Every number is either a direct reuse of an existing
 * function (purchase_planning_needs(), inventory_allocation_queue(), inventory_reservation_
 * queue(), wc_order_import_get_setting()/wc_order_import_sync_health() - all unchanged) or a
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
// UI/UX Phase 5D: 'pending' and 'partially_fulfilled' added to the existing IN() list below -
// same query, same GROUP BY, just two more of the same order_status values already being
// counted here. Powers the new Operations Overview row's "Pending Customer Orders" (pending)
// and extends "Orders Waiting Fulfillment" to include partially_fulfilled orders it was
// previously missing.
$orderStatusCounts = ['pending' => 0, 'waiting_stock' => 0, 'waiting_ship_my_box' => 0, 'ready_to_ship' => 0, 'partially_fulfilled' => 0];
$ordersTodayCount = 0;
if ($canViewOrders) {
    $orderStatusStmt = $pdo->query("
        SELECT order_status, COUNT(*) AS cnt
        FROM mewmii_orders
        WHERE is_historical = 0 AND order_status IN ('pending', 'waiting_stock', 'waiting_ship_my_box', 'ready_to_ship', 'partially_fulfilled')
        GROUP BY order_status
    ");
    foreach ($orderStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderStatusCounts[$row['order_status']] = (int) $row['cnt'];
    }

    // UI/UX Phase 5A: new for the Top Summary strip - a plain COUNT of today's orders, same
    // is_historical exclusion every other order query on this page already uses. Not reused
    // anywhere else, but not a calculation either - just a date filter.
    $ordersTodayStmt = $pdo->query("
        SELECT COUNT(*) FROM mewmii_orders WHERE is_historical = 0 AND DATE(created_at) = CURDATE()
    ");
    $ordersTodayCount = (int) $ordersTodayStmt->fetchColumn();
}

// --- 2. Purchasing ------------------------------------------------------------------------
// purchase_planning_needs()/purchase_planning_untargeted_demand() (includes/purchase_
// planning.php) are called exactly once each, unchanged, and their already-computed arrays
// are reused/sliced in PHP below - no second query re-derives what they already calculated.
$purchasePlanningNeeds = [];
$purchasePlanningCount = 0;
$purchasePlanningValue = 0.0;
$belowTargetCount = 0;
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

// UI/UX Phase 5D: Incoming Supplier Stock - count of inventory units (products/variations)
// currently showing incoming_quantity > 0, the exact same "incoming" bucket already surfaced
// per-row on modules/inventory/index.php and filterable there via ?stage=incoming (its
// inventory_unit_matches_filters() 'incoming' branch checks this same column > 0). One plain
// COUNT, no new column, no calculation.
$incomingStockUnitCount = 0;
if ($canViewInventory) {
    $incomingStockStmt = $pdo->query('SELECT COUNT(*) FROM mewmii_inventory WHERE incoming_quantity > 0');
    $incomingStockUnitCount = (int) $incomingStockStmt->fetchColumn();
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
$negativeStockCount = 0;
if ($canViewInventory) {
    $negativeStockStmt = $pdo->query('SELECT COUNT(*) FROM mewmii_inventory WHERE available_quantity < 0');
    $negativeStockCount = (int) $negativeStockStmt->fetchColumn();
}

// --- 9. WooCommerce Sync (Operations Command Centre) ----------------------------------------
// Entirely reused from the Sync Automation phases (includes/wc_order_import.php,
// modules/integrations/woocommerce.php) - same settings key, no new sync state introduced
// here. Gated on settings.manage, matching the integration page's own permission requirement
// (its destination controls the gate, same convention as every other section on this
// dashboard). UI/UX Phase 5A: the dashboard now only ever shows the single Sync Health
// tier (Top Summary strip) - the full "Last Sync Run / Failed Today / Imported Today"
// breakdown still lives on modules/integrations/woocommerce.php itself (unchanged, not
// duplicated here), so those detail queries were removed from this page rather than computed
// and left unused.
$canManageIntegrations = app_has_permission('settings.manage');
$wcLastSyncCursor = $canManageIntegrations
    ? wc_order_import_get_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT)
    : null;
// Time-based health (Sync Automation Phase 4A) - see wc_order_import_sync_health()'s own
// docblock in includes/wc_order_import.php.
$wcSyncHealth = $canManageIntegrations
    ? wc_order_import_sync_health($wcLastSyncCursor)
    : ['level' => 'unknown', 'message' => '', 'minutes_ago' => null];

// --- 10. Needs Attention + Pending Actions rollup (UI/UX Phase 5A) --------------------------
// Consolidates the same counts already computed above (sections 1-9) into one compact list
// instead of 6 full sections - no new calculation, every number here is a direct reuse of a
// variable already computed earlier on this exact page. Only shown when non-zero, same
// "don't show a wall of empty rows" convention the Phase 3-era version of this list used.
$needsAttention = [];
if ($canViewOrders && $pendingReceiptCount > 0) {
    $needsAttention[] = ['label' => 'Receipts Waiting', 'count' => $pendingReceiptCount, 'url' => '/modules/orders/index.php', 'tone' => 'danger'];
}
if ($canViewInventory && $lowStockCount > 0) {
    $needsAttention[] = ['label' => 'Low Stock', 'count' => $lowStockCount, 'url' => '/modules/inventory/index.php?stock_status=low_stock', 'tone' => 'danger'];
}
if ($canViewSupplierOrders && $overdueSupplierOrderCount > 0) {
    $needsAttention[] = ['label' => 'Supplier Issues (Overdue)', 'count' => $overdueSupplierOrderCount, 'url' => '/modules/supplier-orders/index.php?filter=overdue', 'tone' => 'danger'];
}
$shipmentIssueCount = $shipmentAwaitingTrackingCount + $shipRequestPendingCount;
if (($canViewShipments || $canViewShipMyBox) && $shipmentIssueCount > 0) {
    $needsAttention[] = ['label' => 'Shipment Issues', 'count' => $shipmentIssueCount, 'url' => '/modules/shipments/index.php', 'tone' => 'warning'];
}

// Single glanceable "how much is waiting on me" figure for the Top Summary strip - a plain
// sum of counts already computed above; each addend defaults to 0 when its own permission
// gate is false, so this never counts something the current user can't see anyway.
$pendingActionsCount = $pendingReceiptCount + $reservationCount + $allocationCount
    + $shipRequestPendingCount + $customerStorageAttentionCount + $overdueSupplierOrderCount
    + $shipmentAwaitingTrackingCount;

// --- 11. Recent Activity (UI/UX Phase 5A; extended cross-entity in Sprint 10) --------------
// Was order-events-only; now a UNION ALL across every module that has its own created_at
// timeline already (see includes/dashboard.php's dashboard_recent_activity()) - no new
// tracking table, each branch only runs if its own permission flag is true.
$recentActivity = dashboard_recent_activity($pdo, [
    'orders' => $canViewOrders,
    'supplier_orders' => $canViewSupplierOrders,
    'shipments' => $canViewShipments,
    'customers' => $canViewCustomers,
    'product_sync' => $canManageIntegrations && $canViewProducts,
], 8);

// --- 12. Purchasing Intelligence (Phase 9A) ------------------------------------------------
// Reuses the Phase 7/8 purchasing-intelligence subsystems exactly as built - no new formula,
// no new calculation shape beyond what those functions/query patterns already established.
// Each figure is only computed for a user who holds the permission its linked destination page
// requires, same convention as every other section on this dashboard.
require_once __DIR__ . '/includes/demand_forecast.php';
require_once __DIR__ . '/includes/product_cost.php';

// Inventory Risk - demand_forecast_calculate() (includes/demand_forecast.php, unchanged),
// the exact same formula modules/reports/forecast.php and modules/purchasing/control-
// center.php's "Urgent Reorder Products" section already use. Only a count is needed here.
$urgentReorderCount = 0;
if ($canViewInventory) {
    foreach (demand_forecast_calculate($pdo, '30days', []) as $forecastRow) {
        if ($forecastRow['recommended_qty'] !== null && $forecastRow['recommended_qty'] > 0) {
            $urgentReorderCount++;
        }
    }
}

// Purchasing Status - Ordered/Partially Received/Overdue counts already computed in section 2
// above ($supplierOrderStatusCounts, $overdueSupplierOrderCount) - no new query at all.
// "Receiving Required" = partially_received (some quantity already arrived, the rest still
// needs processing), the exact same count already sitting in $supplierOrderStatusCounts.
$pendingSupplierOrderCount = $supplierOrderStatusCounts['ordered'];
$receivingRequiredCount = $supplierOrderStatusCounts['partially_received'];

// Cost Alerts - product_cost_history_product_ids()/product_cost_history_list_batch()/
// product_cost_calculate_batch() (includes/product_cost.php), the exact "current landed cost
// higher than the last snapshot" rule modules/purchasing/control-center.php's "Cost Change
// Alerts" section already applies. Bounded to products that already have at least one
// snapshot - never scans the whole catalog.
$costAlertCount = 0;
if ($canViewProducts) {
    $historyProductIds = product_cost_history_product_ids($pdo);
    if ($historyProductIds !== []) {
        $historyByProduct = product_cost_history_list_batch($pdo, $historyProductIds);
        $currentCostByProduct = product_cost_calculate_batch($pdo, $historyProductIds);
        foreach ($historyProductIds as $historyProductId) {
            $history = $historyByProduct[$historyProductId] ?? [];
            if ($history === []) {
                continue;
            }
            $previousLandedCost = (float) $history[count($history) - 1]['landed_cost'];
            $currentLandedCost = $currentCostByProduct[$historyProductId]['landed_cost'] ?? null;
            if ($currentLandedCost !== null && $previousLandedCost > 0 && $currentLandedCost > $previousLandedCost) {
                $costAlertCount++;
            }
        }
    }
}

// Supplier Risk - supplier_lead_time_stats_batch() (includes/supplier_orders.php), the exact
// "late orders" formula modules/reports/suppliers.php already shows. Bounded to suppliers that
// actually have order history.
$supplierRiskCount = 0;
if ($canViewSupplierOrders) {
    $supplierIdsWithOrders = array_map('intval', $pdo->query('SELECT DISTINCT supplier_id FROM supplier_orders')->fetchAll(PDO::FETCH_COLUMN));
    foreach (supplier_lead_time_stats_batch($pdo, $supplierIdsWithOrders) as $leadTimeStats) {
        if (($leadTimeStats['late_orders_count'] ?? 0) > 0) {
            $supplierRiskCount++;
        }
    }
}

// Inventory Value - product_cost_calculate_batch() (includes/product_cost.php), the exact same
// Landed Cost formula modules/reports/margins.php/modules/purchasing/index.php already use for
// their own Inventory Value (Cost) cards. Bounded to products that actually have stock on hand
// (same batched rollup pattern those pages already use) - never the whole catalog.
$inventoryValueLandedCost = 0.0;
if ($canViewInventory) {
    $stockedProductsStmt = $pdo->query("
        SELECT p.id, stock.available_quantity
        FROM products p
        INNER JOIN (
            SELECT inv.product_id, SUM(inv.available_quantity) AS available_quantity
            FROM mewmii_inventory inv
            LEFT JOIN product_variations pv ON pv.id = inv.variation_id
            WHERE inv.variation_id IS NULL OR pv.status <> 'archived'
            GROUP BY inv.product_id
        ) stock ON stock.product_id = p.id
        WHERE p.status <> 'archived' AND stock.available_quantity > 0
    ");
    $stockedProducts = $stockedProductsStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($stockedProducts !== []) {
        $stockedProductIds = array_map(static fn (array $row): int => (int) $row['id'], $stockedProducts);
        $costByStockedProduct = product_cost_calculate_batch($pdo, $stockedProductIds);
        foreach ($stockedProducts as $stockedProduct) {
            $landedCost = $costByStockedProduct[(int) $stockedProduct['id']]['landed_cost'] ?? null;
            if ($landedCost !== null) {
                $inventoryValueLandedCost += (int) $stockedProduct['available_quantity'] * $landedCost;
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <h3 class="mb-1">Operations Command Centre</h3>
    <p class="text-muted small mb-0">What needs your attention today. Every number links straight to where you'd act on it.</p>
</div>

<div class="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3 mb-4">
    <?php if ($canViewOrders): ?>
        <div class="col">
            <a class="card stat-card stat-card-link p-3 h-100" href="/modules/orders/index.php">
                <div class="stat-label">Orders Today</div>
                <div class="stat-value" style="font-size: 1.6rem;"><?php echo (int) $ordersTodayCount; ?></div>
            </a>
        </div>
    <?php endif; ?>
    <div class="col">
        <a class="card stat-card stat-card-link p-3 h-100" href="#needs-attention">
            <div class="stat-label">Pending Actions</div>
            <div class="stat-value <?php echo $pendingActionsCount > 0 ? 'stat-value-alert' : ''; ?>" style="font-size: 1.6rem;"><?php echo (int) $pendingActionsCount; ?></div>
        </a>
    </div>
    <?php if ($canViewInventory): ?>
        <div class="col">
            <a class="card stat-card stat-card-link p-3 h-100" href="/modules/inventory/index.php">
                <div class="stat-label">Inventory Health</div>
                <div class="stat-value <?php echo ($lowStockCount + $negativeStockCount) > 0 ? 'stat-value-alert' : ''; ?>" style="font-size: 1.6rem;">
                    <?php echo ($lowStockCount + $negativeStockCount) > 0 ? ($lowStockCount + $negativeStockCount) . ' issue' . (($lowStockCount + $negativeStockCount) === 1 ? '' : 's') : 'Healthy'; ?>
                </div>
            </a>
        </div>
    <?php endif; ?>
    <?php if ($canManageSupplierOrders): ?>
        <div class="col">
            <a class="card stat-card stat-card-link p-3 h-100" href="/modules/purchase-planning/generate.php">
                <div class="stat-label">Purchasing</div>
                <div class="stat-value <?php echo $purchasePlanningCount > 0 ? 'stat-value-alert' : ''; ?>" style="font-size: 1.6rem;"><?php echo (int) $purchasePlanningCount; ?></div>
            </a>
        </div>
    <?php endif; ?>
    <?php if ($canManageIntegrations): ?>
        <div class="col">
            <a class="card stat-card stat-card-link p-3 h-100" href="/modules/integrations/woocommerce.php">
                <div class="stat-label">Sync Health</div>
                <div class="stat-value <?php echo $wcSyncHealth['level'] !== 'healthy' ? 'stat-value-alert' : ''; ?>" style="font-size: 1.6rem;">
                    <?php echo match ($wcSyncHealth['level']) {
                        'healthy' => 'Healthy',
                        'warning' => 'Warning',
                        'critical' => 'Critical',
                        default => 'Unknown',
                    }; ?>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php
// UI/UX Phase 5D: Operations Overview - action-focused counts, each linking straight to its
// filtered list, prioritised per the brief as: orders requiring action, stock problems,
// supplier delays, shipment actions. Every number below is either already computed above
// (Low Stock, Overdue Supplier Orders, Shipments Awaiting Tracking, the order_status/supplier
// status GROUP BY results) or one new plain COUNT ($incomingStockUnitCount) - no charts, same
// .stat-card look as the Top Summary strip above.
$operationsOverviewCards = [];
if ($canViewOrders) {
    $operationsOverviewCards[] = ['label' => 'Pending Customer Orders', 'count' => $orderStatusCounts['pending'], 'url' => '/modules/orders/index.php?status=pending'];
    $operationsOverviewCards[] = ['label' => 'Orders Waiting Fulfillment', 'count' => $orderStatusCounts['waiting_stock'] + $orderStatusCounts['waiting_ship_my_box'] + $orderStatusCounts['ready_to_ship'] + $orderStatusCounts['partially_fulfilled'], 'url' => '/modules/orders/index.php'];
}
if ($canViewInventory) {
    $operationsOverviewCards[] = ['label' => 'Low Stock Products', 'count' => $lowStockCount, 'url' => '/modules/inventory/index.php?stock_status=low_stock'];
    $operationsOverviewCards[] = ['label' => 'Incoming Supplier Stock', 'count' => $incomingStockUnitCount, 'url' => '/modules/inventory/index.php?stage=incoming'];
}
if ($canViewSupplierOrders) {
    $operationsOverviewCards[] = ['label' => 'Pending Supplier Orders', 'count' => $supplierOrderStatusCounts['ordered'], 'url' => '/modules/supplier-orders/index.php?status=ordered'];
    $operationsOverviewCards[] = ['label' => 'Overdue Supplier Orders', 'count' => $overdueSupplierOrderCount, 'url' => '/modules/supplier-orders/index.php?filter=overdue'];
}
if ($canViewShipments) {
    $operationsOverviewCards[] = ['label' => 'Shipments Waiting Action', 'count' => $shipmentAwaitingTrackingCount, 'url' => '/modules/shipments/index.php?awaiting_tracking=1'];
}
?>
<?php if ($operationsOverviewCards !== []): ?>
<div class="mb-4">
    <h4 class="mb-3">Operations Overview</h4>
    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3">
        <?php foreach ($operationsOverviewCards as $card): ?>
            <div class="col">
                <a class="card stat-card stat-card-link p-3 h-100" href="<?php echo app_escape($card['url']); ?>">
                    <div class="stat-label"><?php echo app_escape($card['label']); ?></div>
                    <div class="stat-value <?php echo $card['count'] > 0 ? 'stat-value-alert' : ''; ?>" style="font-size: 1.6rem;"><?php echo (int) $card['count']; ?></div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php
// Phase 9A - Purchasing Intelligence: teaser cards for the Phase 8 subsystems, each linking
// out to its own full page (modules/purchasing/control-center.php, modules/reports/
// suppliers.php, modules/reports/margins.php) rather than duplicating their detail here.
$purchasingIntelligenceCards = [];
if ($canViewInventory) {
    $purchasingIntelligenceCards[] = [
        'label' => 'Inventory Risk',
        'value' => $urgentReorderCount . ' urgent',
        'alert' => $urgentReorderCount > 0,
        'url' => '/modules/purchasing/control-center.php',
    ];
}
if ($canViewSupplierOrders) {
    $purchasingIntelligenceCards[] = [
        'label' => 'Purchasing Status',
        'value' => $overdueSupplierOrderCount . ' overdue',
        'helper' => $pendingSupplierOrderCount . ' pending &middot; ' . $receivingRequiredCount . ' receiving',
        'alert' => $overdueSupplierOrderCount > 0,
        'url' => '/modules/supplier-orders/index.php',
    ];
}
if ($canViewProducts) {
    $purchasingIntelligenceCards[] = [
        'label' => 'Cost Alerts',
        'value' => $costAlertCount . ' increased',
        'alert' => $costAlertCount > 0,
        'url' => '/modules/purchasing/control-center.php',
    ];
}
if ($canViewSupplierOrders) {
    $purchasingIntelligenceCards[] = [
        'label' => 'Supplier Risk',
        'value' => $supplierRiskCount . ' at risk',
        'alert' => $supplierRiskCount > 0,
        'url' => '/modules/reports/suppliers.php',
    ];
}
if ($canViewInventory) {
    $purchasingIntelligenceCards[] = [
        'label' => 'Inventory Value',
        'value' => 'RM ' . number_format($inventoryValueLandedCost, 2),
        'alert' => false,
        'url' => '/modules/reports/margins.php',
    ];
}
?>
<?php if ($purchasingIntelligenceCards !== []): ?>
<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Purchasing Intelligence</h4>
        <a class="small" href="/modules/purchasing/control-center.php">Open Purchasing Control Center &rarr;</a>
    </div>
    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3">
        <?php foreach ($purchasingIntelligenceCards as $card): ?>
            <div class="col">
                <a class="card stat-card stat-card-link p-3 h-100" href="<?php echo app_escape($card['url']); ?>">
                    <div class="stat-label"><?php echo app_escape($card['label']); ?></div>
                    <div class="stat-value <?php echo $card['alert'] ? 'stat-value-alert' : ''; ?>" style="font-size: 1.3rem;"><?php echo app_escape($card['value']); ?></div>
                    <?php if (isset($card['helper'])): ?>
                        <div class="stat-helper"><?php echo $card['helper']; ?></div>
                    <?php endif; ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card p-4 h-100" id="needs-attention">
            <h5 class="mb-3">Needs Attention</h5>
            <?php if ($needsAttention === []): ?>
                <p class="text-muted small mb-0">All caught up - nothing needs attention right now.</p>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($needsAttention as $item): ?>
                        <div class="attention-item tone-<?php echo app_escape($item['tone']); ?> d-flex justify-content-between align-items-center p-3">
                            <span><?php echo app_escape($item['label']); ?></span>
                            <div class="d-flex align-items-center gap-3">
                                <span class="fw-bold"><?php echo (int) $item['count']; ?></span>
                                <a class="btn btn-outline-primary btn-sm" href="<?php echo app_escape($item['url']); ?>">Review &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Recent Activity</h5>
            <?php if ($recentActivity === []): ?>
                <p class="text-muted small mb-0">No recent activity.</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($recentActivity as $event): ?>
                        <li class="mb-3 pb-3 border-bottom">
                            <div class="small">
                                <a href="<?php echo app_escape($event['url']); ?>"><?php echo app_escape($event['description']); ?></a>
                            </div>
                            <div class="text-muted small mt-1"><?php echo app_escape($event['occurred_at']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$canManageProducts = app_has_permission('products.manage');
$canManageCustomers = app_has_permission('customers.manage');
?>
<div class="mb-4">
    <h4 class="mb-3">Quick Actions</h4>
    <div class="row g-3">
        <?php if ($canManageOrders): ?>
            <div class="col-md-4 col-lg-2">
                <a class="btn btn-primary w-100 h-100 py-3" href="/modules/orders/create.php">Create Customer Order</a>
            </div>
        <?php endif; ?>
        <?php if ($canManageProducts): ?>
            <div class="col-md-4 col-lg-2">
                <a class="btn btn-primary w-100 h-100 py-3" href="/modules/products/create.php">Add Product</a>
            </div>
        <?php endif; ?>
        <?php if ($canManageCustomers): ?>
            <div class="col-md-4 col-lg-2">
                <a class="btn btn-primary w-100 h-100 py-3" href="/modules/customers/create.php">Add Customer</a>
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
