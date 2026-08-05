<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/purchase_planning.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/customer_storage.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/wc_order_import.php';
require_once __DIR__ . '/includes/dashboard.php';
require_once __DIR__ . '/includes/notifications.php';

app_require_permission('dashboard.view');

$appTitle = 'Dashboard';

$pdo = app_db();

/**
 * Dashboard Mission Control (Mewmii OS v2 Phase 1 - see docs/DASHBOARD_PHILOSOPHY.md, the
 * permanent governing spec for this page). Replaces the earlier "Operations Command Centre"
 * layout (stat-card strip + Operations Overview + Purchasing Intelligence + Needs Attention +
 * Notifications card + Business Snapshot detail) with three things only: a Status Line (silent
 * when healthy), My Day (a live, auto-generated task list - no new schema, no stored
 * "completed" state, a task exists only because its underlying condition is currently true),
 * and Today's Business (3 numbers). Per DASHBOARD_PHILOSOPHY.md §8, several sections that used
 * to live here moved out: Top Selling Products/Business Snapshot detail -> Reports, Notifications
 * card -> the header badge (includes/header.php, Phase 1 Step 2) + /modules/notifications/,
 * Sync Health/Inventory Health permanent cards -> folded silently into the Status Line's health
 * tier. Every figure below is still either a direct call to an existing function or a plain
 * read-only aggregate over an existing table - nothing here introduces new business logic; see
 * docs/PHASE1_READINESS_REVIEW.md §1 for the reuse audit this restructure was built against.
 */

// --- Permission flags: one per destination permission domain this dashboard links into -----
$canViewOrders = app_has_permission('orders.view');
$canManageOrders = app_has_permission('orders.manage');
$canViewSupplierOrders = app_has_permission('supplier-orders.view');
$canManageSupplierOrders = app_has_permission('supplier-orders.manage');
$canViewInventory = app_has_permission('inventory.view');
$canManageShipments = app_has_permission('shipments.manage');
$canViewProducts = app_has_permission('products.view');
$canManageProducts = app_has_permission('products.manage');
$canManageCustomers = app_has_permission('customers.manage');
$canManageIntegrations = app_has_permission('settings.manage');

// --- Orders: status counts feed both My Day (ready-to-ship) and the Shortcuts badges --------
$orderStatusCounts = ['pending' => 0, 'waiting_stock' => 0, 'waiting_ship_my_box' => 0, 'ready_to_ship' => 0, 'partially_fulfilled' => 0];
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
}

// --- Purchasing: purchase_planning_needs() (includes/purchase_planning.php), unchanged, called
// exactly once - its already-computed array is grouped by supplier in PHP below for My Day, no
// second query re-derives what it already calculated.
$purchasePlanningNeeds = [];
$purchasePlanningCount = 0;
if ($canManageSupplierOrders) {
    $purchasePlanningNeeds = purchase_planning_needs($pdo);
    $purchasePlanningCount = count($purchasePlanningNeeds);
}

$supplierOrderStatusCounts = ['draft' => 0, 'ordered' => 0, 'partially_received' => 0];
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
}

// Mewmii OS v2 Phase 1: now calls the shared supplier_orders_overdue() (includes/purchase_
// planning.php) instead of an inline query - the same predicate notifications.php's
// supplier_order_overdue alert uses, extracted so both read one definition. See
// docs/PHASE1_READINESS_REVIEW.md §1.
$overdueSupplierOrders = [];
if ($canViewSupplierOrders) {
    $overdueSupplierOrders = supplier_orders_overdue($pdo);
}
$overdueSupplierOrderCount = count($overdueSupplierOrders);

// --- Inventory: Low Stock mirrors the exact threshold rule inventory_stock_badges() already
// applies (modules/inventory/index.php) - see that page for the rule's origin. Same query as
// the previous dashboard version, unchanged.
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

// Negative stock - an anomaly check (available_quantity < 0), unchanged. Feeds the Status
// Line's CRITICAL tier below, not shown as its own card anymore.
$negativeStockCount = 0;
if ($canViewInventory) {
    $negativeStockStmt = $pdo->query('SELECT COUNT(*) FROM mewmii_inventory WHERE available_quantity < 0');
    $negativeStockCount = (int) $negativeStockStmt->fetchColumn();
}

// Allocation Center / Reservation Queue counts - unchanged existing queue functions
// (includes/customer_storage.php, includes/inventory.php).
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

// --- Receipts: mewmii_orders.receipt_status, set exclusively by the WooCommerce importer/
// receipt verification workflow (includes/wc_order_import.php, modules/orders/view.php) - a
// plain filtered read of that already-computed column, unchanged from the previous version.
$pendingReceiptCount = 0;
if ($canViewOrders) {
    $pendingReceiptCount = (int) $pdo->query("SELECT COUNT(*) FROM mewmii_orders WHERE receipt_status = 'pending'")->fetchColumn();
}

// Open customer resolution/issue requests - new for Mission Control's Status Line (feeds the
// ATTENTION tier only; there is no admin list page to link a My Day task to yet - see
// docs/PHASE1_READINESS_REVIEW.md §1's "open resolutions" row - so this stays a silent count,
// not a clickable task, until that page exists). Same "status <> 'resolved'" predicate
// includes/order_resolution.php already uses everywhere else (e.g. line 265) - not a new
// definition of "open".
$openResolutionCount = 0;
if ($canViewOrders) {
    $openResolutionCount = (int) $pdo->query("SELECT COUNT(*) FROM resolution_requests WHERE status <> 'resolved'")->fetchColumn();
}

// --- WooCommerce Sync Health - unchanged (includes/wc_order_import.php). Feeds the Status
// Line's CRITICAL tier only; the full breakdown still lives on modules/integrations/
// woocommerce.php, not duplicated here.
$wcLastSyncCursor = $canManageIntegrations
    ? wc_order_import_get_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT)
    : null;
$wcSyncHealth = $canManageIntegrations
    ? wc_order_import_sync_health($wcLastSyncCursor)
    : ['level' => 'unknown', 'message' => '', 'minutes_ago' => null];

// --- Business Health (DASHBOARD_PHILOSOPHY.md §5) - a single-value summary of signals already
// computed above, fully rule-based, no prediction/AI. "Any critical notification" from the
// philosophy's rule table is deliberately left out: mewmii_notifications has no severity/
// critical concept today (confirmed in docs/PHASE1_READINESS_REVIEW.md), and inventing one
// just to satisfy this one clause would be exactly the kind of unnecessary abstraction this
// project's philosophy warns against. Extend this rule if/when notifications gain a severity
// field - see DASHBOARD_PHILOSOPHY.md §9's own note that this 3-tier model is a deliberate
// simplification, not meant to be exhaustive from day one.
$businessHealthTier = 'healthy';
if (($canManageIntegrations && $wcSyncHealth['level'] === 'critical') || ($canViewInventory && $negativeStockCount > 0)) {
    $businessHealthTier = 'critical';
} elseif (
    ($canViewInventory && $lowStockCount > 0)
    || ($canViewOrders && $pendingReceiptCount > 0)
    || ($canViewOrders && $openResolutionCount > 0)
    || ($canManageSupplierOrders && $purchasePlanningCount > 0)
) {
    $businessHealthTier = 'attention';
}

// --- My Day (DASHBOARD_PHILOSOPHY.md §4) - a live view, not a to-do app. No new table, no
// manual task creation, no stored "completed" state: a task exists only because its underlying
// condition is currently true right now, and disappears the moment that condition resolves.
// Sorted overdue-first (lowest $rank first), capped at 8 with "+N more". Six of the seven task
// sources named in the philosophy doc are implemented here; the seventh ("Respond to N customer
// issues") is deferred - see the $openResolutionCount comment above for why.
$myDayTasks = [];

foreach ($overdueSupplierOrders as $overdue) {
    $daysLate = (int) floor((strtotime('today') - strtotime($overdue['expected_delivery_date'])) / 86400);
    $myDayTasks[] = [
        'label' => 'Receive ' . $overdue['purchase_number'] . ' (' . $daysLate . ' day' . ($daysLate === 1 ? '' : 's') . ' late)',
        'url' => '/modules/supplier-orders/view.php?id=' . (int) $overdue['id'],
        'rank' => 0,
    ];
}

if ($canViewOrders && $orderStatusCounts['ready_to_ship'] > 0) {
    $n = $orderStatusCounts['ready_to_ship'];
    $myDayTasks[] = ['label' => 'Ship ' . $n . ' ready order' . ($n === 1 ? '' : 's'), 'url' => '/modules/orders/index.php?status=ready_to_ship', 'rank' => 1];
}

if ($canViewOrders && $pendingReceiptCount > 0) {
    $myDayTasks[] = ['label' => 'Verify ' . $pendingReceiptCount . ' payment receipt' . ($pendingReceiptCount === 1 ? '' : 's'), 'url' => '/modules/orders/index.php', 'rank' => 1];
}

if ($canViewInventory && $allocationCount > 0) {
    $myDayTasks[] = ['label' => 'Allocate ' . $allocationCount . ' arrived preorder unit' . ($allocationCount === 1 ? '' : 's'), 'url' => '/modules/inventory/allocation-center.php', 'rank' => 1];
}

if ($canViewInventory && $reservationCount > 0) {
    $myDayTasks[] = ['label' => 'Reserve ' . $reservationCount . ' waiting order unit' . ($reservationCount === 1 ? '' : 's'), 'url' => '/modules/inventory/reservation-center.php', 'rank' => 1];
}

if ($canViewInventory && $lowStockCount > 0) {
    $myDayTasks[] = ['label' => 'Review ' . $lowStockCount . ' low-stock item' . ($lowStockCount === 1 ? '' : 's'), 'url' => '/modules/inventory/index.php?stock_status=low_stock', 'rank' => 2];
}

if ($canManageSupplierOrders && $purchasePlanningNeeds !== []) {
    $needsBySupplier = [];
    foreach ($purchasePlanningNeeds as $need) {
        $needsBySupplier[(int) $need['supplier_id']][] = $need;
    }
    $supplierNamesById = [];
    $supplierIds = array_keys($needsBySupplier);
    if ($supplierIds !== []) {
        $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
        $supplierNameStmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id IN ({$placeholders})");
        $supplierNameStmt->execute($supplierIds);
        foreach ($supplierNameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $supplierNamesById[(int) $row['id']] = $row['name'];
        }
    }
    foreach ($needsBySupplier as $supplierId => $needs) {
        $n = count($needs);
        $supplierLabel = $supplierNamesById[$supplierId] ?? 'Unassigned Supplier';
        $myDayTasks[] = ['label' => 'Order ' . $n . ' product' . ($n === 1 ? '' : 's') . ' from ' . $supplierLabel, 'url' => '/modules/purchase-planning/generate.php', 'rank' => 3];
    }
}

usort($myDayTasks, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
$myDayOverflowCount = max(0, count($myDayTasks) - 8);
$myDayTasks = array_slice($myDayTasks, 0, 8);

// --- Today's Business - 3 numbers max (DASHBOARD_PHILOSOPHY.md §3), scoped to today (not the
// 30-day window the previous "Business Snapshot" section showed - that detail moved to
// modules/reports/sales.php per §8). Same "valid order" condition modules/reports/sales.php and
// the previous dashboard version already used, just filtered to CURDATE() instead of a rolling
// window.
$todayBusiness = ['orders' => 0, 'revenue' => 0.0];
if ($canViewOrders) {
    $todayStmt = $pdo->query("
        SELECT COUNT(DISTINCT o.id) AS orders, COALESCE(SUM(oi.subtotal), 0) AS revenue
        FROM mewmii_orders o
        INNER JOIN mewmii_order_items oi ON oi.order_id = o.id
        WHERE o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.order_date = CURDATE()
    ");
    $todayBusiness = $todayStmt->fetch(PDO::FETCH_ASSOC);
}
$todayBusiness['aov'] = ((int) $todayBusiness['orders'] > 0)
    ? ((float) $todayBusiness['revenue'] / (int) $todayBusiness['orders'])
    : null;

// This-month revenue teaser (DASHBOARD_PHILOSOPHY.md §3's "This month: RM X revenue -> Full
// Report" line) - a genuine calendar-month figure, same valid-order condition as above.
$monthRevenue = 0.0;
if ($canViewOrders) {
    $monthRevenue = (float) $pdo->query("
        SELECT COALESCE(SUM(oi.subtotal), 0)
        FROM mewmii_orders o
        INNER JOIN mewmii_order_items oi ON oi.order_id = o.id
        WHERE o.payment_status = 'paid' AND o.order_status <> 'cancelled'
          AND o.order_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    ")->fetchColumn();
}

// --- Recent Activity - unchanged dashboard_recent_activity() (includes/dashboard.php), kept as
// a compact teaser below Today's Business. DASHBOARD_PHILOSOPHY.md §3's wireframe points this
// at a dedicated Activity Log page - that page doesn't exist until Phase 2
// (docs/COMPONENT_LIBRARY_SPEC.md §2), so this stays the existing inline mini-feed for now
// rather than link to a page that isn't built yet.
$recentActivity = dashboard_recent_activity($pdo, [
    'orders' => $canViewOrders,
    'supplier_orders' => $canViewSupplierOrders,
    'shipments' => $canManageShipments,
    'customers' => $canManageCustomers,
    'product_sync' => $canManageIntegrations && $canViewProducts,
], 5);

require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <h1 class="mb-1">Dashboard</h1>
    <p class="text-muted small mb-0">What's broken, what to do next, and how business is today.</p>
</div>

<?php
// --- Status Line - silent when healthy, per DASHBOARD_PHILOSOPHY.md §1/§2. Only ever one line.
$healthCopy = [
    'critical' => ['icon' => '&#128308;', 'label' => 'Needs attention now', 'class' => 'text-danger'],
    'attention' => ['icon' => '&#128993;', 'label' => 'A few things need attention', 'class' => 'text-warning'],
    'healthy' => ['icon' => '&#128994;', 'label' => 'All clear', 'class' => 'text-success'],
][$businessHealthTier];
?>
<div class="mb-4 d-flex align-items-center gap-2">
    <span style="font-size: 1.1rem;"><?php echo $healthCopy['icon']; ?></span>
    <span class="fw-semibold <?php echo $healthCopy['class']; ?>"><?php echo $healthCopy['label']; ?></span>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card p-4 h-100">
            <h5 class="mb-3">My Day</h5>
            <?php if ($myDayTasks === []): ?>
                <p class="text-muted small mb-0">Nothing needs action right now - you're caught up.</p>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($myDayTasks as $task): ?>
                        <div class="attention-item <?php echo $task['rank'] === 0 ? 'tone-danger' : ($task['rank'] <= 1 ? 'tone-warning' : ''); ?> d-flex justify-content-between align-items-center p-3">
                            <span><?php echo app_escape($task['label']); ?></span>
                            <a class="btn btn-outline-primary btn-sm" href="<?php echo app_escape($task['url']); ?>">Go &rarr;</a>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($myDayOverflowCount > 0): ?>
                        <p class="text-muted small mb-0 mt-1">+<?php echo (int) $myDayOverflowCount; ?> more</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Today's Business</h5>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <div class="text-muted small">Orders</div>
                    <div class="fs-4 fw-bold"><?php echo (int) $todayBusiness['orders']; ?></div>
                </div>
                <div class="col-6">
                    <div class="text-muted small">Revenue</div>
                    <div class="fs-4 fw-bold">RM <?php echo app_escape(number_format((float) $todayBusiness['revenue'], 2)); ?></div>
                </div>
                <div class="col-12">
                    <div class="text-muted small">Avg Order Value</div>
                    <div class="fs-5 fw-bold">
                        <?php if ($todayBusiness['aov'] !== null): ?>
                            RM <?php echo app_escape(number_format($todayBusiness['aov'], 2)); ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($canViewOrders): ?>
                <div class="small text-muted border-top pt-3">
                    This month: RM <?php echo app_escape(number_format($monthRevenue, 2)); ?> revenue &middot;
                    <a href="/modules/reports/sales.php">Full Report &rarr;</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="mb-4">
    <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="text-muted mb-0">Recent Activity</h6>
            <a class="small" href="/modules/notifications/index.php">Notifications &rarr;</a>
        </div>
        <?php if ($recentActivity === []): ?>
            <p class="text-muted small mb-0">No recent activity.</p>
        <?php else: ?>
            <ul class="list-unstyled mb-0 small">
                <?php foreach ($recentActivity as $event): ?>
                    <li class="mb-2 pb-2 border-bottom d-flex justify-content-between">
                        <a href="<?php echo app_escape($event['url']); ?>"><?php echo app_escape($event['description']); ?></a>
                        <span class="text-muted ms-2 text-nowrap"><?php echo app_escape($event['occurred_at']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="mb-4">
    <h4 class="mb-3">Shortcuts</h4>
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
                    View Purchase Planning
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
        <?php if ($canManageShipments): ?>
            <div class="col-md-4 col-lg-2">
                <a class="btn btn-primary w-100 h-100 py-3" href="/modules/shipments/index.php">Ship Orders</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
