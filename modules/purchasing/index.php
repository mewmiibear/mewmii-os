<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/purchase_planning.php';
require_once __DIR__ . '/../../includes/product_cost.php';
app_require_permission('inventory.view');

$appTitle = 'Purchasing';
$pdo = app_db();

/**
 * Phase 7A - Smart Purchasing Dashboard. Read-only recommendations built entirely from
 * already-stored values (products.selling_price/min_stock_threshold, mewmii_inventory's
 * available/reserved/incoming quantities) - no new inventory math, no forecasting. Available
 * Stock = Current Stock - Reserved + Incoming, exactly the formula requested, computed here
 * only (never written back to any table). Phase 7G: Purchase Cost/Gross Margin/Inventory
 * Value (Cost) now come from includes/product_cost.php's product_cost_calculate_batch()
 * (true Landed Cost) instead of raw products.product_cost - see that file for the formula.
 */

$statusOptions = ['critical', 'low_stock', 'healthy', 'out_of_stock'];
$statusLabels = ['critical' => 'Critical', 'low_stock' => 'Low Stock', 'healthy' => 'Healthy', 'out_of_stock' => 'Out of Stock'];
$sortOptions = ['stock_low', 'margin_high', 'supplier', 'sku', 'name'];
$sortLabels = [
    'stock_low' => 'Lowest Stock',
    'margin_high' => 'Highest Margin',
    'supplier' => 'Supplier',
    'sku' => 'SKU',
    'name' => 'Product Name',
];

// --- Filters: same whitelist-before-query convention as modules/products/index.php - an
// invalid/garbage GET value is silently treated as "no filter" rather than coerced. ---
$filterSupplierId = isset($_GET['supplier_id']) && ctype_digit((string) $_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
$filterStatus = in_array($_GET['status'] ?? '', $statusOptions, true) ? $_GET['status'] : null;
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$inStockOnly = ($_GET['in_stock_only'] ?? '') === '1';
$reorderOnly = ($_GET['reorder_only'] ?? '') === '1';
$sortKey = in_array($_GET['sort'] ?? '', $sortOptions, true) ? $_GET['sort'] : 'stock_low';

// Phase 7G - 'margin_high' is no longer a SQL-sortable column: Gross Margin now comes from
// product_cost_calculate_batch() (PHP, after the query below), the same way
// modules/reports/margins.php already sorts by margin. Every other option is still a plain
// indexed/joined column, so it stays a SQL ORDER BY exactly as before.
$sortColumns = [
    'stock_low' => 'COALESCE(stock.available_quantity, 0) ASC',
    'supplier' => 's.name ASC',
    'sku' => 'p.sku ASC',
    'name' => 'p.name ASC',
];
$orderSql = isset($sortColumns[$sortKey]) ? ($sortColumns[$sortKey] . ', p.id DESC') : 'p.name ASC, p.id DESC';

// Batched stock rollup - identical to modules/products/index.php's own $stockJoinSql (same
// simple-row / non-archived-variation-sum rule as includes/inventory.php:product_effective_stock()),
// reused verbatim rather than re-derived, so the numbers here never drift from Products/Inventory.
$stockJoinSql = "
    LEFT JOIN (
        SELECT inv.product_id,
               SUM(inv.available_quantity) AS available_quantity,
               SUM(inv.reserved_quantity) AS reserved_quantity,
               SUM(inv.incoming_quantity) AS incoming_quantity
        FROM mewmii_inventory inv
        LEFT JOIN product_variations pv ON pv.id = inv.variation_id
        WHERE inv.variation_id IS NULL OR pv.status <> 'archived'
        GROUP BY inv.product_id
    ) stock ON stock.product_id = p.id
";

$whereSql = " AND p.status <> 'archived'";
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
if ($inStockOnly) {
    $whereSql .= ' AND COALESCE(stock.available_quantity, 0) > 0';
}

$selectSql = "
    SELECT
        p.id, p.sku, p.name, p.selling_price, p.min_stock_threshold,
        s.name AS supplier_name,
        COALESCE(stock.available_quantity, 0) AS current_stock,
        COALESCE(stock.reserved_quantity, 0) AS reserved_stock,
        COALESCE(stock.incoming_quantity, 0) AS incoming_stock
    FROM products p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    {$stockJoinSql}
    WHERE 1 = 1 {$whereSql}
    ORDER BY {$orderSql}
";
$stmt = $pdo->prepare($selectSql);
$stmt->execute($params);
$rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Phase 7G - one batched product_cost_calculate_batch() call for every matching product, not
// one calculation per row. Same pattern as modules/reports/margins.php: Purchase Cost/Gross
// Margin below come entirely from this call's landed_cost/gross_profit/gross_margin_percent,
// never recomputed from products.product_cost here.
$productIds = array_map(static fn (array $row): int => (int) $row['id'], $rawRows);
$costByProduct = product_cost_calculate_batch($pdo, $productIds);

/**
 * Status/Available Stock are computed once per row in PHP rather than as SQL CASE
 * expressions - Status and Reorder Only both depend on a value (Available Stock) derived
 * from three already-joined columns, and this single query is already admin-catalog scale
 * (same "cheap to filter/paginate in PHP" reasoning already used by modules/products/index.php's
 * lifecycle quick-filter and modules/inventory/index.php's attention summary), so no second
 * query or per-row lookup is introduced.
 */
$allRows = [];
foreach ($rawRows as $row) {
    $currentStock = (int) $row['current_stock'];
    $reservedStock = (int) $row['reserved_stock'];
    $incomingStock = (int) $row['incoming_stock'];
    $availableStock = $currentStock - $reservedStock + $incomingStock;
    $reorderLevel = $row['min_stock_threshold'] !== null ? (int) $row['min_stock_threshold'] : null;

    if ($currentStock <= 0) {
        $status = 'out_of_stock';
    } elseif ($availableStock <= 0) {
        $status = 'critical';
    } elseif ($reorderLevel !== null && $availableStock < $reorderLevel) {
        $status = 'low_stock';
    } else {
        $status = 'healthy';
    }

    $cost = $costByProduct[(int) $row['id']] ?? null;

    $row['current_stock'] = $currentStock;
    $row['reserved_stock'] = $reservedStock;
    $row['incoming_stock'] = $incomingStock;
    $row['available_stock'] = $availableStock;
    $row['reorder_level'] = $reorderLevel;
    $row['status'] = $status;
    $row['landed_cost'] = $cost['landed_cost'] ?? null;
    $row['is_estimated'] = $cost['is_estimated'] ?? false;
    $row['margin_amount'] = $cost['gross_profit'] ?? null;
    $row['margin_percent'] = $cost['gross_margin_percent'] ?? null;

    $allRows[] = $row;
}
unset($row);

// Summary cards reflect the current Supplier/Search/In-stock-only filters (the same
// convention modules/inventory/index.php's attention summary uses) but not the Status/Reorder
// Only quick toggles below, so the cards stay meaningful reference points while browsing a
// narrowed view - computed from the same in-memory rows already fetched above, no extra query.
$needsReorderCount = 0;
$outOfStockCount = 0;
$lowStockCount = 0;
$inventoryValueCost = 0.0;
$inventoryValueSelling = 0.0;
foreach ($allRows as $row) {
    if ($row['status'] !== 'healthy') {
        $needsReorderCount++;
    }
    if ($row['status'] === 'out_of_stock') {
        $outOfStockCount++;
    }
    if ($row['status'] === 'low_stock') {
        $lowStockCount++;
    }
    // Phase 7G - only summed over rows with a computed Landed Cost, same convention as
    // modules/reports/margins.php's own Inventory Value (Cost) card - a product whose cost
    // data isn't configured contributes an unknown amount, never guessed as raw product_cost.
    if ($row['landed_cost'] !== null) {
        $inventoryValueCost += $row['current_stock'] * $row['landed_cost'];
    }
    $inventoryValueSelling += $row['current_stock'] * (float) $row['selling_price'];
}

// Status / Reorder Only: applied after the summary cards above are computed, so the cards
// always reflect the full (supplier/search/in-stock) filtered set even while a Status or
// Reorder Only toggle narrows the table beneath them.
$filteredRows = array_values(array_filter($allRows, static function (array $row) use ($filterStatus, $reorderOnly): bool {
    if ($filterStatus !== null && $row['status'] !== $filterStatus) {
        return false;
    }
    if ($reorderOnly && $row['status'] === 'healthy') {
        return false;
    }

    return true;
}));

// Phase 7G - 'margin_high' sorts on the batch-computed gross_margin_percent, so (like
// modules/reports/margins.php) it's applied here in PHP rather than in the SQL ORDER BY above.
// A product with no computed margin (cost not configured) sorts last regardless of direction.
if ($sortKey === 'margin_high') {
    usort($filteredRows, static fn (array $a, array $b): int => ($b['margin_percent'] ?? -INF) <=> ($a['margin_percent'] ?? -INF));
}

// --- Pagination: over the already-filtered in-memory array, same page-slice approach as
// modules/products/index.php's lifecycle quick-filter branch. ---
$perPage = 50;
$totalCount = count($filteredRows);
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$page = min($page, $totalPages);
$products = array_slice($filteredRows, ($page - 1) * $perPage, $perPage);

$filterSuppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

/**
 * Phase 7B - Purchase Recommendations: reuses purchase_planning_needs() (includes/purchase_planning.php)
 * exactly as-is - the same "what needs ordering, how much" calculation already used by the
 * root dashboard's Purchasing card and modules/purchase-planning/generate.php. This section
 * only groups/totals what that function already returns; no quantity/cost/MOQ math is
 * duplicated here. Deliberately a SEPARATE notion of "needs ordering" from the Status column
 * above (this page's own current/reserved/incoming-vs-reorder-level view) - the two already
 * coexist without conflict elsewhere (modules/inventory/index.php has both its own Low Stock
 * rule and a separate purchase_planning_needs()-powered "Needs Ordering" filter), so they stay
 * clearly labeled here too rather than merged into one number.
 */
$supplierNameById = array_column($filterSuppliers, 'name', 'id');

$purchaseNeeds = purchase_planning_needs($pdo);
if ($filterSupplierId !== null) {
    $purchaseNeeds = array_values(array_filter(
        $purchaseNeeds,
        static fn (array $need): bool => $need['supplier_id'] === $filterSupplierId
    ));
}

$recommendationsBySupplier = [];
foreach ($purchaseNeeds as $need) {
    $supplierKey = $need['supplier_id'] ?? 0;
    if (!isset($recommendationsBySupplier[$supplierKey])) {
        $recommendationsBySupplier[$supplierKey] = [
            'supplier_id' => $need['supplier_id'],
            'supplier_name' => $need['supplier_id'] !== null ? ($supplierNameById[$need['supplier_id']] ?? 'Unknown Supplier') : 'No Supplier Assigned',
            'lines' => [],
            'total_cost' => 0.0,
        ];
    }
    // Estimated Purchase Cost = suggested_quantity x cost_price, both already computed by
    // purchase_planning_needs() (MOQ-rounded quantity, variation-aware cost fallback) - not a
    // new calculation, just multiplying two of its existing output fields.
    $need['estimated_cost'] = $need['suggested_quantity'] * $need['cost_price'];
    $recommendationsBySupplier[$supplierKey]['lines'][] = $need;
    $recommendationsBySupplier[$supplierKey]['total_cost'] += $need['estimated_cost'];
}

// "No Supplier Assigned" always sorts last rather than wherever it happens to land
// alphabetically - every other group is a real supplier name, sorted A-Z.
$namedRecommendationGroups = [];
$unassignedRecommendationGroup = null;
foreach ($recommendationsBySupplier as $group) {
    if ($group['supplier_id'] === null) {
        $unassignedRecommendationGroup = $group;
    } else {
        $namedRecommendationGroups[] = $group;
    }
}
usort($namedRecommendationGroups, static fn (array $a, array $b): int => strcasecmp($a['supplier_name'], $b['supplier_name']));
foreach ($namedRecommendationGroups as &$group) {
    usort($group['lines'], static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
}
unset($group);
if ($unassignedRecommendationGroup !== null) {
    usort($unassignedRecommendationGroup['lines'], static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
    $namedRecommendationGroups[] = $unassignedRecommendationGroup;
}
$recommendationGroups = $namedRecommendationGroups;

$recommendationsTotalProducts = count($purchaseNeeds);
$recommendationsGrandTotal = array_sum(array_column($recommendationGroups, 'total_cost'));

$statusBadge = static function (string $status) use ($statusLabels): string {
    $classes = [
        'out_of_stock' => 'badge bg-danger',
        'critical' => 'badge bg-danger',
        'low_stock' => 'badge bg-warning text-dark',
        'healthy' => 'badge bg-success',
    ];

    return '<span class="' . $classes[$status] . '">' . app_escape($statusLabels[$status]) . '</span>';
};

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1">Purchasing</h2>
        <p class="page-description">What to reorder, based on current stock, reservations, and incoming supplier orders.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a class="card stat-card stat-card-link p-3 h-100" href="?reorder_only=1">
            <div class="stat-label">Needs Reorder</div>
            <div class="stat-value <?php echo $needsReorderCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $needsReorderCount; ?></div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a class="card stat-card stat-card-link p-3 h-100" href="?status=out_of_stock">
            <div class="stat-label">Out of Stock</div>
            <div class="stat-value <?php echo $outOfStockCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $outOfStockCount; ?></div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a class="card stat-card stat-card-link p-3 h-100" href="?status=low_stock">
            <div class="stat-label">Low Stock</div>
            <div class="stat-value <?php echo $lowStockCount > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) $lowStockCount; ?></div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Inventory Value (Cost)</div>
            <div class="stat-value" style="font-size: 1.3rem;">RM <?php echo app_escape(number_format($inventoryValueCost, 2)); ?></div>
            <div class="stat-helper">Selling: RM <?php echo app_escape(number_format($inventoryValueSelling, 2)); ?></div>
        </div>
    </div>
</div>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Name or SKU">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Supplier</label>
            <select name="supplier_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($filterSuppliers as $supplier): ?>
                    <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $filterSupplierId === (int) $supplier['id'] ? 'selected' : ''; ?>><?php echo app_escape($supplier['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($statusOptions as $statusValue): ?>
                    <option value="<?php echo app_escape($statusValue); ?>" <?php echo $filterStatus === $statusValue ? 'selected' : ''; ?>><?php echo app_escape($statusLabels[$statusValue]); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Sort by</label>
            <select name="sort" class="form-select form-select-sm">
                <?php foreach ($sortOptions as $sortOption): ?>
                    <option value="<?php echo app_escape($sortOption); ?>" <?php echo $sortKey === $sortOption ? 'selected' : ''; ?>><?php echo app_escape($sortLabels[$sortOption]); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="in-stock-only-toggle" name="in_stock_only" value="1" <?php echo $inStockOnly ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="in-stock-only-toggle">In-stock only</label>
            </div>
        </div>
        <div class="col-auto">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="reorder-only-toggle" name="reorder_only" value="1" <?php echo $reorderOnly ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="reorder-only-toggle">Reorder only</label>
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/purchasing/index.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
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
                <th>Supplier</th>
                <th class="text-end">Current Stock</th>
                <th class="text-end">Reserved</th>
                <th class="text-end">Incoming</th>
                <th class="text-end">Available Stock</th>
                <th class="text-end">Reorder Level</th>
                <th class="text-end">Purchase Cost</th>
                <th class="text-end">Selling Price</th>
                <th class="text-end">Gross Margin</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td data-label="Product"><?php echo app_escape($product['name']); ?></td>
                    <td data-label="SKU"><?php echo app_escape($product['sku']); ?></td>
                    <td data-label="Supplier"><?php echo $product['supplier_name'] !== null ? app_escape($product['supplier_name']) : '—'; ?></td>
                    <td data-label="Current Stock" class="text-end"><?php echo (int) $product['current_stock']; ?></td>
                    <td data-label="Reserved" class="text-end"><?php echo (int) $product['reserved_stock']; ?></td>
                    <td data-label="Incoming" class="text-end"><?php echo (int) $product['incoming_stock']; ?></td>
                    <td data-label="Available Stock" class="text-end"><?php echo (int) $product['available_stock']; ?></td>
                    <td data-label="Reorder Level" class="text-end"><?php echo $product['reorder_level'] !== null ? (int) $product['reorder_level'] : '—'; ?></td>
                    <td data-label="Purchase Cost" class="text-end">
                        <?php if ($product['landed_cost'] !== null): ?>
                            RM <?php echo app_escape(number_format($product['landed_cost'], 2)); ?>
                            <?php if ($product['is_estimated']): ?>
                                <span class="badge bg-warning text-dark ms-1">Estimated</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary">Not configured</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Selling Price" class="text-end">RM <?php echo app_escape(number_format((float) $product['selling_price'], 2)); ?></td>
                    <td data-label="Gross Margin" class="text-end">
                        <?php if ($product['margin_amount'] !== null): ?>
                            RM <?php echo app_escape(number_format($product['margin_amount'], 2)); ?>
                            <?php if ($product['margin_percent'] !== null): ?>
                                <div class="text-muted small"><?php echo app_escape(number_format($product['margin_percent'], 1)); ?>%</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary">Not configured</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Status"><?php echo $statusBadge($product['status']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr>
                    <td colspan="12">
                        <div class="empty-state">
                            <div class="empty-state-title">No Products Match These Filters</div>
                            <p class="empty-state-text">Try adjusting or clearing your filters to see more results.</p>
                            <a class="btn btn-outline-secondary btn-sm" href="/modules/purchasing/index.php">Clear filters</a>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    $pageUrl = static function (int $targetPage): string {
        return '/modules/purchasing/index.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $rangeStart; ?>&ndash;<?php echo (int) $rangeEnd; ?> of <?php echo (int) $totalCount; ?> product<?php echo $totalCount === 1 ? '' : 's'; ?>
            <?php else: ?>
                0 products
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

<style>
    .purchase-rec-header[aria-expanded="false"] .purchase-rec-caret { transform: rotate(-90deg); }
    .purchase-rec-caret { display: inline-block; transition: transform 0.15s ease; }
</style>

<div class="page-header d-flex justify-content-between align-items-center mt-4">
    <div>
        <h2 class="mb-1">Purchase Recommendations</h2>
        <p class="page-description">
            What quantity to buy, grouped by supplier - from Purchase Planning's existing needs-ordering calculation.
            <?php if ($recommendationsTotalProducts > 0): ?>
                <?php echo (int) count($recommendationGroups); ?> supplier<?php echo count($recommendationGroups) === 1 ? '' : 's'; ?>
                &middot; <?php echo (int) $recommendationsTotalProducts; ?> product<?php echo $recommendationsTotalProducts === 1 ? '' : 's'; ?>
                &middot; Est. total RM <?php echo app_escape(number_format($recommendationsGrandTotal, 2)); ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($recommendationGroups === []): ?>
    <div class="card p-4">
        <div class="empty-state">
            <div class="empty-state-title">Nothing Needs Ordering Right Now</div>
            <p class="empty-state-text">Purchase Planning has no open shortages<?php echo $filterSupplierId !== null ? ' for this supplier' : ''; ?>.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($recommendationGroups as $group): ?>
        <?php $groupDomId = 'purchase-rec-' . ($group['supplier_id'] ?? 'none'); ?>
        <div class="card mb-3">
            <div class="card-header purchase-rec-header d-flex justify-content-between align-items-center" role="button" data-bs-toggle="collapse" data-bs-target="#<?php echo app_escape($groupDomId); ?>" aria-expanded="true" aria-controls="<?php echo app_escape($groupDomId); ?>">
                <div>
                    <i class="bi bi-chevron-down purchase-rec-caret me-2"></i>
                    <strong><?php echo app_escape($group['supplier_name']); ?></strong>
                    <span class="text-muted small ms-2"><?php echo count($group['lines']); ?> product<?php echo count($group['lines']) === 1 ? '' : 's'; ?></span>
                </div>
                <span class="badge bg-info text-dark">Est. RM <?php echo app_escape(number_format($group['total_cost'], 2)); ?></span>
            </div>
            <div class="collapse show" id="<?php echo app_escape($groupDomId); ?>">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 responsive-stack-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-end">MOQ</th>
                                <th class="text-end">Suggested Purchase Qty</th>
                                <th class="text-end">Supplier Pack Size</th>
                                <th class="text-end">Estimated Purchase Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['lines'] as $line): ?>
                                <tr>
                                    <td data-label="Product"><?php echo app_escape($line['label']); ?></td>
                                    <td data-label="SKU"><?php echo app_escape($line['sku']); ?></td>
                                    <td data-label="MOQ" class="text-end"><?php echo (int) $line['moq']; ?></td>
                                    <td data-label="Suggested Purchase Qty" class="text-end"><?php echo (int) $line['suggested_quantity']; ?></td>
                                    <td data-label="Supplier Pack Size" class="text-end"><span class="text-muted" title="Not tracked in Mewmii yet">&mdash;</span></td>
                                    <td data-label="Estimated Purchase Cost" class="text-end">RM <?php echo app_escape(number_format($line['estimated_cost'], 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
