<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('inventory.view');

/**
 * Phase 8D - Demand Forecasting & Smart Reorder. Simple, explainable arithmetic only - no
 * machine learning, no trend/seasonality modelling. Every input is an existing, already-
 * trusted figure read straight from its source table; nothing here recomputes a number another
 * page already owns:
 *   - Available/Incoming Stock: the same batched mewmii_inventory rollup used throughout
 *     modules/products/index.php, modules/purchasing/index.php, etc.
 *   - Sales Velocity: SUM(quantity) over the same "valid order" condition
 *     (payment_status='paid' AND order_status<>'cancelled') modules/reports/sales.php and
 *     modules/reports/inventory.php already use, via the same includes/reports.php period
 *     helper those two reports use.
 *   - Supplier Lead Time: supplier_lead_time_stats_batch() (includes/supplier_orders.php) -
 *     the exact "Average Delivery Time" formula modules/reports/suppliers.php already shows,
 *     factored out so this page never defines a second version of it.
 *   - Safety Stock: products.min_stock_threshold, already-existing per-product data, used
 *     as-is (not converted, not re-derived).
 *
 * This is a SEPARATE, complementary signal from includes/purchase_planning.php's own "needs
 * ordering" logic (target-stock-level based) - the two are never merged, same precedent as
 * Phase 7A/7B/7G keeping the Purchasing Dashboard's own Status column and Purchase Planning's
 * needs list side by side without conflating them. purchase_planning_needs()/
 * purchase_planning_generate() are not touched by this file at all.
 *
 * Formula (explainable, no ML):
 *   Average Daily Sales = quantity sold in the selected period / number of days in that period
 *   Days of Stock Remaining = Available Stock / Average Daily Sales
 *   Demand During Lead Time = Average Daily Sales x Supplier Lead Time (days)
 *   Recommended Order Quantity = MAX(0, Demand During Lead Time + Safety Stock
 *                                       - Available Stock - Incoming Stock)
 *   Suggested Reorder Date = Today + MAX(0, Days of Stock Remaining - Supplier Lead Time)
 *
 * A product missing EITHER Sales Velocity OR Supplier Lead Time shows "Not enough data" for
 * every figure that depends on it, rather than guessing a default (0 sales/day, a generic lead
 * time, etc.) - per the explicit "do not guess demand, do not create false recommendations"
 * rule this phase was given.
 */

$appTitle = 'Demand Forecast';
$pdo = app_db();

// Only 7/30/90-day windows are offered - 'today' is too noisy for a daily-average velocity and
// 'all' has no fixed day count to divide by, so neither fits this formula's simple arithmetic.
$forecastPeriodOptions = ['7days' => 7, '30days' => 30, '90days' => 90];
$period = array_key_exists($_GET['period'] ?? '', $forecastPeriodOptions) ? $_GET['period'] : '30days';
$periodDays = $forecastPeriodOptions[$period];
$dateCondition = report_period_date_condition($period, 'o.order_date');

$filterSupplierId = isset($_GET['supplier_id']) && ctype_digit((string) $_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$reorderOnly = ($_GET['reorder_only'] ?? '') === '1';

// Batched stock rollup - identical to modules/products/index.php's / modules/purchasing/
// index.php's own $stockJoinSql, reused verbatim.
$stockJoinSql = "
    LEFT JOIN (
        SELECT inv.product_id,
               SUM(inv.available_quantity) AS available_quantity,
               SUM(inv.incoming_quantity) AS incoming_quantity
        FROM mewmii_inventory inv
        LEFT JOIN product_variations pv ON pv.id = inv.variation_id
        WHERE inv.variation_id IS NULL OR pv.status <> 'archived'
        GROUP BY inv.product_id
    ) stock ON stock.product_id = p.id
";

// Scope deliberately ready_stock only - same precedent as modules/reports/inventory.php:
// preorder/early_bird are never gated on physical stock, so "days of stock remaining"/"reorder
// before it runs out" doesn't map onto them the same way.
$whereSql = " AND p.product_type = 'ready_stock' AND p.status <> 'archived'";
$params = [];
if ($filterSupplierId !== null) {
    $whereSql .= ' AND p.supplier_id = ?';
    $params[] = $filterSupplierId;
}
if ($searchTerm !== '') {
    $whereSql .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}

$productsStmt = $pdo->prepare("
    SELECT p.id, p.sku, p.name, p.supplier_id, p.min_stock_threshold, s.name AS supplier_name,
           COALESCE(stock.available_quantity, 0) AS available_stock,
           COALESCE(stock.incoming_quantity, 0) AS incoming_stock
    FROM products p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    {$stockJoinSql}
    WHERE 1 = 1 {$whereSql}
    ORDER BY p.name ASC
");
$productsStmt->execute($params);
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// One batched query for every candidate product's sales in the selected period - not one per
// product. Same valid-order condition modules/reports/sales.php/inventory.php already use.
$salesByProduct = [];
if ($products !== []) {
    $productIds = array_map(static fn (array $p): int => (int) $p['id'], $products);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $salesStmt = $pdo->prepare("
        SELECT oi.product_id, SUM(oi.quantity) AS quantity_sold
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE oi.product_id IN ({$placeholders})
          AND o.payment_status = 'paid' AND o.order_status <> 'cancelled'{$dateCondition}
        GROUP BY oi.product_id
    ");
    $salesStmt->execute($productIds);
    foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $salesByProduct[(int) $row['product_id']] = (int) $row['quantity_sold'];
    }
}

// One batched query for every distinct supplier behind these products - not one per product.
$supplierIds = array_values(array_unique(array_filter(array_column($products, 'supplier_id'))));
$leadTimeBySupplier = supplier_lead_time_stats_batch($pdo, $supplierIds);

$rows = [];
foreach ($products as $product) {
    $productId = (int) $product['id'];
    $availableStock = (int) $product['available_stock'];
    $incomingStock = (int) $product['incoming_stock'];
    $safetyStock = $product['min_stock_threshold'] !== null ? (int) $product['min_stock_threshold'] : 0;

    $quantitySold = $salesByProduct[$productId] ?? 0;
    // "Not enough data" is a real state, never treated as a velocity of 0 - a product with no
    // observed sales in the window can't be forecast without guessing.
    $hasSalesData = $quantitySold > 0;
    $avgDailySales = $hasSalesData ? ($quantitySold / $periodDays) : null;
    $daysRemaining = ($avgDailySales !== null && $avgDailySales > 0) ? ($availableStock / $avgDailySales) : null;

    $leadTimeStats = $product['supplier_id'] !== null ? ($leadTimeBySupplier[(int) $product['supplier_id']] ?? null) : null;
    $leadTimeDays = $leadTimeStats['avg_lead_time_days'] ?? null;

    $recommendedQty = null;
    $reorderDate = null;
    if ($avgDailySales !== null && $leadTimeDays !== null) {
        $demandDuringLeadTime = $avgDailySales * $leadTimeDays;
        $recommendedQty = (int) ceil(max(0, $demandDuringLeadTime + $safetyStock - $availableStock - $incomingStock));
        $reorderInDays = max(0, ($daysRemaining ?? 0) - $leadTimeDays);
        $reorderDate = date('Y-m-d', strtotime('+' . (int) floor($reorderInDays) . ' days'));
    }

    if ($reorderOnly && ($recommendedQty === null || $recommendedQty <= 0)) {
        continue;
    }

    $rows[] = [
        'id' => $productId,
        'sku' => $product['sku'],
        'name' => $product['name'],
        'supplier_name' => $product['supplier_name'],
        'available_stock' => $availableStock,
        'incoming_stock' => $incomingStock,
        'avg_daily_sales' => $avgDailySales,
        'days_remaining' => $daysRemaining,
        'lead_time_days' => $leadTimeDays,
        'reorder_date' => $reorderDate,
        'recommended_qty' => $recommendedQty,
    ];
}

// Most urgent (soonest to run out) first - same "lowest stock first" default already
// established by modules/purchasing/index.php's own default sort. A product with no computed
// days-remaining (not enough data) sorts last, never assumed to be either urgent or safe.
usort($rows, static fn (array $a, array $b): int => ($a['days_remaining'] ?? INF) <=> ($b['days_remaining'] ?? INF));

$filterSuppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$naBadge = '<span class="text-muted">Not enough data</span>';
$canViewProducts = app_has_permission('products.view');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1"><i class="bi bi-graph-up"></i> Demand Forecast</h2>
        <p class="page-description">Simple, explainable reorder timing from sales velocity, supplier lead time, and safety stock - not a prediction model.</p>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($forecastPeriodOptions as $periodValue => $days): ?>
        <a class="btn btn-sm <?php echo $period === $periodValue ? 'btn-primary' : 'btn-outline-secondary'; ?>"
           href="?<?php echo app_escape(http_build_query(array_merge($_GET, ['period' => $periodValue]))); ?>">
            Last <?php echo (int) $days; ?> Days
        </a>
    <?php endforeach; ?>
</div>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="period" value="<?php echo app_escape($period); ?>">
        <div class="col-md-3">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Name or SKU">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Supplier</label>
            <select name="supplier_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($filterSuppliers as $supplier): ?>
                    <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $filterSupplierId === (int) $supplier['id'] ? 'selected' : ''; ?>><?php echo app_escape($supplier['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="reorder-only-toggle" name="reorder_only" value="1" <?php echo $reorderOnly ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="reorder-only-toggle">Needs reorder only</label>
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/reports/forecast.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th class="text-end">Available</th>
                <th class="text-end">Incoming</th>
                <th class="text-end">Sales Velocity</th>
                <th class="text-end">Days Remaining</th>
                <th class="text-end">Supplier Lead Time</th>
                <th>Suggested Reorder Date</th>
                <th class="text-end">Recommended Qty</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td data-label="Product">
                        <?php if ($canViewProducts): ?>
                            <a href="/modules/products/view.php?id=<?php echo (int) $row['id']; ?>"><?php echo app_escape($row['name']); ?></a>
                        <?php else: ?>
                            <?php echo app_escape($row['name']); ?>
                        <?php endif; ?>
                        <?php if ($row['supplier_name'] !== null): ?>
                            <div class="text-muted small"><?php echo app_escape($row['supplier_name']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="SKU"><?php echo app_escape($row['sku']); ?></td>
                    <td data-label="Available" class="text-end"><?php echo (int) $row['available_stock']; ?></td>
                    <td data-label="Incoming" class="text-end"><?php echo (int) $row['incoming_stock']; ?></td>
                    <td data-label="Sales Velocity" class="text-end">
                        <?php echo $row['avg_daily_sales'] !== null ? (app_escape(number_format($row['avg_daily_sales'], 2)) . '/day') : $naBadge; ?>
                    </td>
                    <td data-label="Days Remaining" class="text-end">
                        <?php if ($row['days_remaining'] === null): ?>
                            <?php echo $naBadge; ?>
                        <?php else: ?>
                            <span class="<?php echo $row['days_remaining'] < ($row['lead_time_days'] ?? 0) ? 'text-danger fw-semibold' : ''; ?>">
                                <?php echo app_escape(number_format($row['days_remaining'], 1)); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Supplier Lead Time" class="text-end">
                        <?php echo $row['lead_time_days'] !== null ? (app_escape(number_format($row['lead_time_days'], 1)) . ' days') : $naBadge; ?>
                    </td>
                    <td data-label="Suggested Reorder Date">
                        <?php echo $row['reorder_date'] !== null ? app_escape($row['reorder_date']) : $naBadge; ?>
                    </td>
                    <td data-label="Recommended Qty" class="text-end">
                        <?php if ($row['recommended_qty'] === null): ?>
                            <?php echo $naBadge; ?>
                        <?php elseif ($row['recommended_qty'] <= 0): ?>
                            <span class="badge bg-success">Well stocked</span>
                        <?php else: ?>
                            <strong><?php echo (int) $row['recommended_qty']; ?></strong>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <div class="empty-state-title">No Products Match These Filters</div>
                            <p class="empty-state-text">Try adjusting or clearing your filters to see more results.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
