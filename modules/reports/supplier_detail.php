<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('supplier-orders.view');

/**
 * Phase 8B - Supplier Performance & Purchase Intelligence: per-supplier drill-down from
 * modules/reports/suppliers.php. Same data/scope rules as that page's own docblock (cancelled
 * orders excluded from spending/products/cost figures; dates only used when actually on file).
 *
 * Everything below comes from ONE batched history query (every supplier_order_items row ever
 * placed with this supplier, joined to its order and product) - the Purchase History table and
 * the Products Bought / Cost Changes summary are both built from that same single result set in
 * PHP, never a second query per product.
 */

$appTitle = 'Supplier Detail';
$pdo = app_db();

$supplierId = (int) ($_GET['id'] ?? 0);
if ($supplierId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$supplierStmt = $pdo->prepare('SELECT id, name, contact_person, phone, email, currency FROM suppliers WHERE id = ? LIMIT 1');
$supplierStmt->execute([$supplierId]);
$supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Same aggregate formulas as modules/reports/suppliers.php, scoped to this one supplier -
// never a second definition of "total spending"/"average delivery time" etc.
$orderStatsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status <> 'cancelled' THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN status <> 'cancelled' THEN shipping_fee ELSE 0 END) AS active_shipping_total,
        SUM(CASE WHEN order_date IS NOT NULL AND received_date IS NOT NULL THEN DATEDIFF(received_date, order_date) ELSE 0 END) AS delivery_days_sum,
        SUM(CASE WHEN order_date IS NOT NULL AND received_date IS NOT NULL THEN 1 ELSE 0 END) AS delivery_sample_count,
        SUM(CASE WHEN received_date IS NOT NULL AND expected_delivery_date IS NOT NULL AND received_date > expected_delivery_date THEN 1 ELSE 0 END) AS late_orders_count,
        SUM(CASE WHEN received_date IS NOT NULL AND expected_delivery_date IS NOT NULL THEN 1 ELSE 0 END) AS late_eligible_count
    FROM supplier_orders
    WHERE supplier_id = ?
");
$orderStatsStmt->execute([$supplierId]);
$orderStats = $orderStatsStmt->fetch(PDO::FETCH_ASSOC);

// One batched query for the whole history - every line this supplier has ever supplied,
// oldest first (so PHP can pick each product's FIRST and LAST row directly from the array
// without a second query or a fragile SQL MIN/MAX-per-column trick).
$historyStmt = $pdo->prepare("
    SELECT so.id AS order_id, so.purchase_number, so.status, so.order_date, so.is_historical,
           soi.product_id, soi.variation_id, soi.total_quantity, soi.subtotal, soi.supplier_price,
           p.sku AS product_sku, p.name AS product_name, pv.sku AS variation_sku
    FROM supplier_order_items soi
    INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
    INNER JOIN products p ON p.id = soi.product_id
    LEFT JOIN product_variations pv ON pv.id = soi.variation_id
    WHERE so.supplier_id = ?
    ORDER BY so.order_date ASC, so.id ASC, soi.id ASC
");
$historyStmt->execute([$supplierId]);
$historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$itemsSubtotal = 0.0;
$productCount = 0;
$productSummary = [];
foreach ($historyRows as $row) {
    if ($row['status'] === 'cancelled') {
        continue;
    }

    $itemsSubtotal += (float) $row['subtotal'];

    $key = $row['product_id'] . ':' . ($row['variation_id'] ?? 0);
    if (!isset($productSummary[$key])) {
        $productCount++;
        $productSummary[$key] = [
            'label' => $row['product_name'] . ($row['variation_sku'] !== null ? (' - ' . $row['variation_sku']) : ''),
            'sku' => $row['variation_sku'] ?? $row['product_sku'],
            'times_purchased' => 0,
            'total_quantity' => 0,
            'first_cost' => (float) $row['supplier_price'],
            'first_date' => $row['order_date'],
            'latest_cost' => (float) $row['supplier_price'],
            'latest_date' => $row['order_date'],
        ];
    }
    $productSummary[$key]['times_purchased']++;
    $productSummary[$key]['total_quantity'] += (int) $row['total_quantity'];
    // Rows are already oldest-first, so the most recent occurrence simply overwrites these on
    // every later iteration - the last write wins, no re-sorting needed.
    $productSummary[$key]['latest_cost'] = (float) $row['supplier_price'];
    $productSummary[$key]['latest_date'] = $row['order_date'];
}
usort($productSummary, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

$activeOrders = (int) ($orderStats['active_orders'] ?? 0);
$totalSpending = $activeOrders > 0 ? ($itemsSubtotal + (float) ($orderStats['active_shipping_total'] ?? 0)) : null;
$averageOrderValue = ($totalSpending !== null && $activeOrders > 0) ? ($totalSpending / $activeOrders) : null;

$deliverySampleCount = (int) ($orderStats['delivery_sample_count'] ?? 0);
$averageDeliveryDays = $deliverySampleCount > 0 ? ((float) $orderStats['delivery_days_sum'] / $deliverySampleCount) : null;

$lateEligibleCount = (int) ($orderStats['late_eligible_count'] ?? 0);
$lateOrdersCount = $lateEligibleCount > 0 ? (int) $orderStats['late_orders_count'] : null;

// Latest Purchase Date - the most recent non-cancelled order_date on file, read directly from
// the same history rows already fetched above (already oldest-first, so the last non-cancelled
// row's date is it) rather than a second query.
$latestPurchaseDate = null;
foreach ($historyRows as $row) {
    if ($row['status'] !== 'cancelled' && $row['order_date'] !== null) {
        $latestPurchaseDate = $row['order_date'];
    }
}

$canViewSupplierOrders = app_has_permission('supplier-orders.view');
$canViewProducts = app_has_permission('products.view');
$naBadge = '<span class="text-muted">Not available</span>';

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1"><?php echo app_escape($supplier['name']); ?></h1>
        <p class="page-description">
            <?php echo app_escape($supplier['contact_person'] ?? ''); ?>
            <?php if (!empty($supplier['contact_person']) && (!empty($supplier['phone']) || !empty($supplier['email']))): ?>&middot;<?php endif; ?>
            <?php echo app_escape($supplier['phone'] ?? ''); ?>
            <?php if (!empty($supplier['phone']) && !empty($supplier['email'])): ?>&middot;<?php endif; ?>
            <?php echo app_escape($supplier['email'] ?? ''); ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canViewSupplierOrders): ?>
            <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/index.php?supplier_id=<?php echo (int) $supplierId; ?>">View Supplier Orders</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/reports/suppliers.php">Back to Supplier Performance</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Total Orders</div>
            <div class="stat-value" style="font-size: 1.6rem;"><?php echo (int) ($orderStats['total_orders'] ?? 0); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Total Spending</div>
            <div class="stat-value" style="font-size: 1.3rem;"><?php echo $totalSpending !== null ? ('RM ' . app_escape(number_format($totalSpending, 2))) : $naBadge; ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Avg Order Value</div>
            <div class="stat-value" style="font-size: 1.3rem;"><?php echo $averageOrderValue !== null ? ('RM ' . app_escape(number_format($averageOrderValue, 2))) : $naBadge; ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Latest Purchase</div>
            <div class="stat-value" style="font-size: 1.3rem;"><?php echo $latestPurchaseDate !== null ? app_escape($latestPurchaseDate) : $naBadge; ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Products Purchased</div>
            <div class="stat-value" style="font-size: 1.6rem;"><?php echo (int) $productCount; ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Avg Delivery Time</div>
            <div class="stat-value" style="font-size: 1.3rem;"><?php echo $averageDeliveryDays !== null ? (app_escape(number_format($averageDeliveryDays, 1)) . ' days') : $naBadge; ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Late Orders</div>
            <div class="stat-value" style="font-size: 1.3rem;">
                <?php if ($lateOrdersCount === null): ?>
                    <?php echo $naBadge; ?>
                <?php else: ?>
                    <?php echo (int) $lateOrdersCount; ?> <span class="text-muted" style="font-size: 1rem;">/ <?php echo (int) $lateEligibleCount; ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-1">Products Bought &amp; Cost Changes</h5>
    <p class="text-muted small mb-3">First vs. most recent unit cost paid to this supplier, per product. Cancelled orders excluded.</p>
    <?php if ($productSummary === []): ?>
        <p class="text-muted small mb-0">No products purchased from this supplier yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="text-end">Times Purchased</th>
                        <th class="text-end">Total Qty</th>
                        <th class="text-end">First Cost</th>
                        <th class="text-end">Latest Cost</th>
                        <th>Change</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productSummary as $summary): ?>
                        <?php
                        $delta = $summary['latest_cost'] - $summary['first_cost'];
                        if ($summary['times_purchased'] < 2) {
                            $changeBadge = '<span class="text-muted small">Only purchased once</span>';
                        } elseif (abs($delta) < 0.01) {
                            $changeBadge = '<span class="badge bg-secondary">Unchanged</span>';
                        } elseif ($delta > 0) {
                            $changeBadge = '<span class="badge bg-danger">+RM ' . number_format($delta, 2) . '</span>';
                        } else {
                            $changeBadge = '<span class="badge bg-success">-RM ' . number_format(abs($delta), 2) . '</span>';
                        }
                        ?>
                        <tr>
                            <td><?php echo app_escape($summary['label']); ?></td>
                            <td><?php echo app_escape($summary['sku']); ?></td>
                            <td class="text-end"><?php echo (int) $summary['times_purchased']; ?></td>
                            <td class="text-end"><?php echo (int) $summary['total_quantity']; ?></td>
                            <td class="text-end">RM <?php echo app_escape(number_format($summary['first_cost'], 2)); ?><div class="text-muted small"><?php echo app_escape($summary['first_date'] ?? '-'); ?></div></td>
                            <td class="text-end">RM <?php echo app_escape(number_format($summary['latest_cost'], 2)); ?><div class="text-muted small"><?php echo app_escape($summary['latest_date'] ?? '-'); ?></div></td>
                            <td><?php echo $changeBadge; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card p-4" id="purchase-history">
    <h5 class="mb-3">Purchase History</h5>
    <?php if ($historyRows === []): ?>
        <p class="text-muted small mb-0">No supplier orders for this supplier yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Purchase #</th>
                        <th>Date</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Cost</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($historyRows) as $row): ?>
                        <tr>
                            <td>
                                <?php if ($canViewSupplierOrders): ?>
                                    <a href="/modules/supplier-orders/view.php?id=<?php echo (int) $row['order_id']; ?>"><?php echo app_escape($row['purchase_number']); ?></a>
                                <?php else: ?>
                                    <?php echo app_escape($row['purchase_number']); ?>
                                <?php endif; ?>
                                <?php if (!empty($row['is_historical'])): ?><span class="badge bg-secondary">Historical</span><?php endif; ?>
                            </td>
                            <td><?php echo app_escape($row['order_date'] ?? '-'); ?></td>
                            <td>
                                <?php if ($canViewProducts): ?>
                                    <a href="/modules/products/view.php?id=<?php echo (int) $row['product_id']; ?>"><?php echo app_escape($row['product_name']); ?></a>
                                <?php else: ?>
                                    <?php echo app_escape($row['product_name']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo app_escape($row['variation_sku'] ?? $row['product_sku']); ?></td>
                            <td class="text-end"><?php echo (int) $row['total_quantity']; ?></td>
                            <td class="text-end">RM <?php echo app_escape(number_format((float) $row['supplier_price'], 2)); ?></td>
                            <td><?php echo supplier_order_status_badge($row['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
