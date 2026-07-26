<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/demand_forecast.php';
app_require_permission('inventory.view');

/**
 * Phase 8D - Demand Forecasting & Smart Reorder. The formula itself now lives in
 * includes/demand_forecast.php's demand_forecast_calculate() (Phase 8E extracted it so
 * modules/purchasing/control-center.php could reuse it too, without a second definition) -
 * this page is display-only: filters, sorting, pagination-free listing, and rendering. See
 * that file's own docblock for the full formula and the exact same "Not enough data" rules.
 */

$appTitle = 'Demand Forecast';
$pdo = app_db();

// Only 7/30/90-day windows are offered - 'today' is too noisy for a daily-average velocity and
// 'all' has no fixed day count to divide by, so neither fits this formula's simple arithmetic.
$period = array_key_exists($_GET['period'] ?? '', DEMAND_FORECAST_PERIOD_DAYS) ? $_GET['period'] : '30days';

$filterSupplierId = isset($_GET['supplier_id']) && ctype_digit((string) $_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$reorderOnly = ($_GET['reorder_only'] ?? '') === '1';

$rows = demand_forecast_calculate($pdo, $period, [
    'supplier_id' => $filterSupplierId,
    'search' => $searchTerm,
]);

if ($reorderOnly) {
    $rows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['recommended_qty'] !== null && $row['recommended_qty'] > 0
    ));
}

// Most urgent (soonest to run out) first - same "lowest stock first" default already
// established by modules/purchasing/index.php's own default sort. A product with no computed
// days-remaining (not enough data) sorts last, never assumed to be either urgent or safe.
usort($rows, static fn (array $a, array $b): int => ($a['days_remaining'] ?? INF) <=> ($b['days_remaining'] ?? INF));

$forecastPeriodOptions = DEMAND_FORECAST_PERIOD_DAYS;
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
