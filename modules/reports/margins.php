<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_cost.php';
app_require_permission('products.view');

/**
 * Phase 7E - Profit & Margin Analytics. Read-only reporting, no cost/margin math of its own -
 * every Landed Cost/Gross Profit/Gross Margin % figure on this page comes from
 * product_cost_calculate_batch() (includes/product_cost.php), called exactly once for every
 * product this page's filters match. This page only joins/aggregates already-computed values
 * (batched stock rollup x landed cost for inventory value, array_sum()/count() for the summary
 * cards) - see includes/product_cost.php for the actual Landed Cost/Margin formula.
 */

$appTitle = 'Margin Report';
$pdo = app_db();

// --- Filters: same whitelist-before-query convention as modules/products/index.php /
// modules/purchasing/index.php - an invalid/garbage GET value is silently treated as "no
// filter" rather than coerced. ---
$filterSupplierId = isset($_GET['supplier_id']) && ctype_digit((string) $_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
$filterCategoryId = isset($_GET['category_id']) && ctype_digit((string) $_GET['category_id']) ? (int) $_GET['category_id'] : null;
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$marginMinRaw = trim((string) ($_GET['margin_min'] ?? ''));
$marginMaxRaw = trim((string) ($_GET['margin_max'] ?? ''));
$marginMin = $marginMinRaw !== '' && is_numeric($marginMinRaw) ? (float) $marginMinRaw : null;
$marginMax = $marginMaxRaw !== '' && is_numeric($marginMaxRaw) ? (float) $marginMaxRaw : null;

$sortOptions = ['margin_low', 'margin_high', 'name', 'sku', 'supplier'];
$sortLabels = [
    'margin_low' => 'Lowest Margin',
    'margin_high' => 'Highest Margin',
    'name' => 'Product Name',
    'sku' => 'SKU',
    'supplier' => 'Supplier',
];
$sortKey = in_array($_GET['sort'] ?? '', $sortOptions, true) ? $_GET['sort'] : 'margin_low';

// Batched stock rollup - identical to modules/products/index.php's / modules/purchasing/index.php's
// own $stockJoinSql, reused verbatim (not re-derived) so Inventory Value here never drifts from
// what those pages already show.
$stockJoinSql = "
    LEFT JOIN (
        SELECT inv.product_id,
               SUM(inv.available_quantity) AS available_quantity
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
if ($filterCategoryId !== null) {
    $whereSql .= ' AND EXISTS (SELECT 1 FROM product_category_relationships r WHERE r.product_id = p.id AND r.category_id = ?)';
    $params[] = $filterCategoryId;
}
if ($searchTerm !== '') {
    $whereSql .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}

$selectSql = "
    SELECT
        p.id, p.sku, p.name,
        s.name AS supplier_name,
        cat.name AS category_name,
        COALESCE(stock.available_quantity, 0) AS current_stock
    FROM products p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    LEFT JOIN product_category_relationships pcr ON pcr.product_id = p.id
    LEFT JOIN categories cat ON cat.id = pcr.category_id
    {$stockJoinSql}
    WHERE 1 = 1 {$whereSql}
    ORDER BY p.name ASC
";
$stmt = $pdo->prepare($selectSql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// One batched call for every matching product's cost breakdown - not one call per row. This
// is the ONLY place cost/margin numbers come from on this page; nothing here recomputes them.
$productIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
$costByProduct = product_cost_calculate_batch($pdo, $productIds);

$allRows = [];
foreach ($rows as $row) {
    $cost = $costByProduct[(int) $row['id']] ?? null;
    $row['landed_cost'] = $cost['landed_cost'] ?? null;
    $row['is_estimated'] = $cost['is_estimated'] ?? false;
    $row['selling_price'] = $cost['selling_price'] ?? 0.0;
    $row['gross_profit'] = $cost['gross_profit'] ?? null;
    $row['gross_margin_percent'] = $cost['gross_margin_percent'] ?? null;
    // Phase 7F - surfaced on the Landed Cost cell below (a tooltip/badge summary), never
    // recomputed here - straight from the same batch call as everything else on this row.
    $row['other_costs_configured'] = $cost['other_costs_configured'] ?? false;
    $row['other_costs'] = $cost['other_costs'] ?? 0.0;
    $row['other_costs_breakdown'] = $cost['other_costs_breakdown'] ?? [];
    $allRows[] = $row;
}
unset($row);

// Summary cards reflect the current Supplier/Search/Category filters (same convention as
// modules/inventory/index.php's attention summary and modules/purchasing/index.php's stat
// cards) but not the Margin Range filter below, so the cards stay meaningful reference points
// while narrowing the table beneath them. Computed from the same in-memory rows already
// fetched above - no extra query.
$analysedRows = array_values(array_filter($allRows, static fn (array $row): bool => $row['gross_margin_percent'] !== null));
$totalProductsAnalysed = count($analysedRows);
$averageMarginPercent = $totalProductsAnalysed > 0
    ? array_sum(array_column($analysedRows, 'gross_margin_percent')) / $totalProductsAnalysed
    : null;

// Inventory Value (Cost): only summed over rows with a computed Landed Cost - a product whose
// cost data isn't configured contributes an unknown amount, never guessed as 0 or as raw
// product_cost. Inventory Value (Selling): selling_price is a required, always-populated
// column, so every row contributes here regardless of cost configuration.
$inventoryValueCost = 0.0;
$inventoryValueSelling = 0.0;
foreach ($allRows as $row) {
    if ($row['landed_cost'] !== null) {
        $inventoryValueCost += $row['current_stock'] * $row['landed_cost'];
    }
    $inventoryValueSelling += $row['current_stock'] * $row['selling_price'];
}

$rankedByMargin = $analysedRows;
usort($rankedByMargin, static fn (array $a, array $b): int => $b['gross_margin_percent'] <=> $a['gross_margin_percent']);
$highestMarginProducts = array_slice($rankedByMargin, 0, 3);
$lowestMarginProducts = array_slice(array_reverse($rankedByMargin), 0, 3);

// Margin Range: depends on the batch-computed gross_margin_percent, so - same as
// modules/products/index.php's lifecycle quick-filter and modules/purchasing/index.php's
// Status/Reorder Only toggles - applied in PHP after the single fetch above rather than as a
// second query. A row with no computable margin is excluded whenever a range is active (a
// numeric range can't meaningfully include "unknown").
$filteredRows = array_values(array_filter($allRows, static function (array $row) use ($marginMin, $marginMax): bool {
    if ($marginMin === null && $marginMax === null) {
        return true;
    }
    if ($row['gross_margin_percent'] === null) {
        return false;
    }
    if ($marginMin !== null && $row['gross_margin_percent'] < $marginMin) {
        return false;
    }
    if ($marginMax !== null && $row['gross_margin_percent'] > $marginMax) {
        return false;
    }

    return true;
}));

$sortComparators = [
    'margin_low' => static fn (array $a, array $b): int => ($a['gross_margin_percent'] ?? -INF) <=> ($b['gross_margin_percent'] ?? -INF),
    'margin_high' => static fn (array $a, array $b): int => ($b['gross_margin_percent'] ?? -INF) <=> ($a['gross_margin_percent'] ?? -INF),
    'name' => static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']),
    'sku' => static fn (array $a, array $b): int => strcasecmp($a['sku'], $b['sku']),
    'supplier' => static fn (array $a, array $b): int => strcasecmp((string) ($a['supplier_name'] ?? ''), (string) ($b['supplier_name'] ?? '')),
];
usort($filteredRows, $sortComparators[$sortKey]);

// --- Pagination: over the already-filtered/sorted in-memory array, same page-slice approach
// as modules/purchasing/index.php. ---
$perPage = 50;
$totalCount = count($filteredRows);
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$page = min($page, $totalPages);
$products = array_slice($filteredRows, ($page - 1) * $perPage, $perPage);

$filterSuppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$filterCategories = catalog_list_categories_tree($pdo);
$canViewProducts = app_has_permission('products.view');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1"><i class="bi bi-graph-up-arrow"></i> Margin Report</h2>
        <p class="page-description">Selling price vs. true landed cost, per product - built entirely from the Product Cost Engine, not recalculated here.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Products Analysed</div>
            <div class="stat-value" style="font-size: 1.6rem;"><?php echo (int) $totalProductsAnalysed; ?></div>
            <div class="stat-helper"><?php echo (int) (count($allRows) - $totalProductsAnalysed); ?> with cost data not yet configured</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Average Margin %</div>
            <div class="stat-value <?php echo $averageMarginPercent !== null && $averageMarginPercent < 0 ? 'stat-value-alert' : ''; ?>" style="font-size: 1.6rem;">
                <?php echo $averageMarginPercent !== null ? app_escape(number_format($averageMarginPercent, 1)) . '%' : '&mdash;'; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Inventory Value (Cost)</div>
            <div class="stat-value" style="font-size: 1.3rem;">RM <?php echo app_escape(number_format($inventoryValueCost, 2)); ?></div>
            <div class="stat-helper">Cost-configured products only</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 h-100">
            <div class="stat-label">Inventory Value (Selling)</div>
            <div class="stat-value" style="font-size: 1.3rem;">RM <?php echo app_escape(number_format($inventoryValueSelling, 2)); ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card p-3 h-100">
            <div class="stat-label mb-2">Highest Margin Products</div>
            <?php if ($highestMarginProducts === []): ?>
                <p class="text-muted small mb-0">No products with computed margin yet.</p>
            <?php else: ?>
                <?php foreach ($highestMarginProducts as $row): ?>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span>
                            <?php if ($canViewProducts): ?>
                                <a href="/modules/products/view.php?id=<?php echo (int) $row['id']; ?>"><?php echo app_escape($row['name']); ?></a>
                            <?php else: ?>
                                <?php echo app_escape($row['name']); ?>
                            <?php endif; ?>
                            <span class="text-muted small">(<?php echo app_escape($row['sku']); ?>)</span>
                        </span>
                        <span class="badge bg-success"><?php echo app_escape(number_format($row['gross_margin_percent'], 1)); ?>%</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3 h-100">
            <div class="stat-label mb-2">Lowest Margin Products</div>
            <?php if ($lowestMarginProducts === []): ?>
                <p class="text-muted small mb-0">No products with computed margin yet.</p>
            <?php else: ?>
                <?php foreach ($lowestMarginProducts as $row): ?>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span>
                            <?php if ($canViewProducts): ?>
                                <a href="/modules/products/view.php?id=<?php echo (int) $row['id']; ?>"><?php echo app_escape($row['name']); ?></a>
                            <?php else: ?>
                                <?php echo app_escape($row['name']); ?>
                            <?php endif; ?>
                            <span class="text-muted small">(<?php echo app_escape($row['sku']); ?>)</span>
                        </span>
                        <span class="badge <?php echo $row['gross_margin_percent'] < 0 ? 'bg-danger' : 'bg-warning text-dark'; ?>"><?php echo app_escape(number_format($row['gross_margin_percent'], 1)); ?>%</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
            <label class="form-label small mb-1">Category</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($filterCategories as $cat): ?>
                    <option value="<?php echo (int) $cat['id']; ?>" <?php echo $filterCategoryId === $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo str_repeat('&nbsp;&nbsp;', $cat['depth']) . app_escape($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <label class="form-label small mb-1">Margin % Min</label>
            <input type="number" step="0.1" class="form-control form-control-sm" name="margin_min" value="<?php echo app_escape($marginMinRaw); ?>">
        </div>
        <div class="col-md-1">
            <label class="form-label small mb-1">Margin % Max</label>
            <input type="number" step="0.1" class="form-control form-control-sm" name="margin_max" value="<?php echo app_escape($marginMaxRaw); ?>">
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
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/reports/margins.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
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
                <th class="text-end">Selling Price</th>
                <th class="text-end">Landed Cost</th>
                <th class="text-end">Gross Profit</th>
                <th class="text-end">Gross Margin %</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td data-label="Product">
                        <?php if ($canViewProducts): ?>
                            <a href="/modules/products/view.php?id=<?php echo (int) $product['id']; ?>"><?php echo app_escape($product['name']); ?></a>
                        <?php else: ?>
                            <?php echo app_escape($product['name']); ?>
                        <?php endif; ?>
                        <?php if (!empty($product['category_name'])): ?>
                            <div class="text-muted small"><?php echo app_escape($product['category_name']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="SKU"><?php echo app_escape($product['sku']); ?></td>
                    <td data-label="Supplier"><?php echo $product['supplier_name'] !== null ? app_escape($product['supplier_name']) : '—'; ?></td>
                    <td data-label="Selling Price" class="text-end">RM <?php echo app_escape(number_format($product['selling_price'], 2)); ?></td>
                    <td data-label="Landed Cost" class="text-end">
                        <?php if ($product['landed_cost'] !== null): ?>
                            RM <?php echo app_escape(number_format($product['landed_cost'], 2)); ?>
                            <?php if ($product['is_estimated']): ?>
                                <span class="badge bg-warning text-dark ms-1">Estimated</span>
                            <?php endif; ?>
                            <?php if ($product['other_costs_configured']): ?>
                                <?php
                                $otherCostsTitle = implode(', ', array_map(
                                    static fn (array $line): string => $line['cost_type'] . ': RM ' . number_format($line['amount'], 2),
                                    $product['other_costs_breakdown']
                                ));
                                ?>
                                <div class="text-muted small" title="<?php echo app_escape($otherCostsTitle); ?>">
                                    +RM <?php echo app_escape(number_format($product['other_costs'], 2)); ?> additional costs
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary">Not configured</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Gross Profit" class="text-end"><?php echo $product['gross_profit'] !== null ? ('RM ' . app_escape(number_format($product['gross_profit'], 2))) : '—'; ?></td>
                    <td data-label="Gross Margin %" class="text-end">
                        <?php if ($product['gross_margin_percent'] !== null): ?>
                            <span class="badge <?php echo $product['gross_margin_percent'] < 0 ? 'bg-danger' : ($product['gross_margin_percent'] < 20 ? 'bg-warning text-dark' : 'bg-success'); ?>">
                                <?php echo app_escape(number_format($product['gross_margin_percent'], 1)); ?>%
                            </span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="empty-state-title">No Products Match These Filters</div>
                            <p class="empty-state-text">Try adjusting or clearing your filters to see more results.</p>
                            <a class="btn btn-outline-secondary btn-sm" href="/modules/reports/margins.php">Clear filters</a>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    $pageUrl = static function (int $targetPage): string {
        return '/modules/reports/margins.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
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
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
