<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/demand_forecast.php';
require_once __DIR__ . '/../../includes/product_cost.php';
app_require_permission('inventory.view');

/**
 * Phase 8E - Smart Purchasing Control Center. A single decision-support dashboard assembled
 * entirely from the four Phase 8 subsystems already built - it introduces NO new calculation
 * of its own:
 *   - Urgent Reorder Products: demand_forecast_calculate() (includes/demand_forecast.php,
 *     shared with modules/reports/forecast.php since Phase 8E factored it out of that page).
 *   - Cost Change Alerts: product_cost_history_product_ids()/product_cost_history_list_batch()/
 *     product_cost_calculate_batch() (includes/product_cost.php, unchanged since Phase 8C/7C).
 *   - Supplier Risk Summary: supplier_lead_time_stats_batch() (includes/supplier_orders.php,
 *     the same "Average Delivery Time"/"Late Orders" formula modules/reports/suppliers.php
 *     already shows, extended in Phase 8E with late-order counts so both pages can share it).
 *   - Create Supplier Order: posts to the existing modules/purchasing/create_order.php (Phase
 *     8A) - no second supplier-order-creation code path is introduced here.
 *
 * modules/purchasing/index.php (the Phase 7A/7B Purchasing Dashboard) and
 * modules/reports/forecast.php/suppliers.php/cost_history.php are all untouched in behaviour -
 * this page only reads what they already compute and links out to them for full detail.
 */

$appTitle = 'Purchasing Control Center';
$pdo = app_db();

$canManageSupplierOrders = app_has_permission('supplier-orders.manage');
$canViewProducts = app_has_permission('products.view');
$naBadge = '<span class="text-muted">Not available</span>';

// --- 1. Urgent Reorder Products - the exact demand-forecast formula, default 30-day window,
// no filters (this is a decision-dashboard summary, not the full filterable report) - trimmed
// to products that actually need reordering, most urgent first, capped for a dashboard-sized
// list. The full, filterable list lives at modules/reports/forecast.php. ---
$forecastRows = demand_forecast_calculate($pdo, '30days', []);
$urgentRows = array_values(array_filter(
    $forecastRows,
    static fn (array $row): bool => $row['recommended_qty'] !== null && $row['recommended_qty'] > 0
));
usort($urgentRows, static fn (array $a, array $b): int => ($a['days_remaining'] ?? INF) <=> ($b['days_remaining'] ?? INF));
$urgentRows = array_slice($urgentRows, 0, 15);

// --- 2. Cost Change Alerts - products with at least one frozen Landed Cost snapshot
// (product_cost_history) whose CURRENT live landed cost is higher than the most recent
// snapshot on file. Same batched-query shape as modules/reports/cost_history.php. ---
$costAlerts = [];
$historyProductIds = product_cost_history_product_ids($pdo);
if ($historyProductIds !== []) {
    $placeholders = implode(',', array_fill(0, count($historyProductIds), '?'));
    $productStmt = $pdo->prepare("SELECT id, sku, name FROM products WHERE id IN ({$placeholders})");
    $productStmt->execute($historyProductIds);
    $productsById = [];
    foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productsById[(int) $row['id']] = $row;
    }

    $historyByProduct = product_cost_history_list_batch($pdo, $historyProductIds);
    $currentCostByProduct = product_cost_calculate_batch($pdo, $historyProductIds);

    foreach ($historyProductIds as $productId) {
        $history = $historyByProduct[$productId] ?? [];
        if ($history === [] || !isset($productsById[$productId])) {
            continue;
        }

        $previousSnapshot = $history[count($history) - 1];
        $previousLandedCost = (float) $previousSnapshot['landed_cost'];
        $currentLandedCost = $currentCostByProduct[$productId]['landed_cost'] ?? null;

        if ($currentLandedCost === null || $previousLandedCost <= 0 || $currentLandedCost <= $previousLandedCost) {
            // Only an INCREASE is an alert - unchanged/decreased cost, or a current cost that
            // isn't computable right now, is never reported as a cost-change alert.
            continue;
        }

        $costAlerts[] = [
            'id' => $productId,
            'sku' => $productsById[$productId]['sku'],
            'name' => $productsById[$productId]['name'],
            'previous_landed_cost' => $previousLandedCost,
            'current_landed_cost' => $currentLandedCost,
            'change_percent' => ($currentLandedCost - $previousLandedCost) / $previousLandedCost * 100,
        ];
    }
}
usort($costAlerts, static fn (array $a, array $b): int => $b['change_percent'] <=> $a['change_percent']);
$costAlerts = array_slice($costAlerts, 0, 10);

// --- 3. Supplier Risk Summary - suppliers with at least one late-delivered order on file,
// worst first, alongside their average delivery time. ---
$supplierRisk = [];
$supplierIdsWithOrders = array_map('intval', $pdo->query('SELECT DISTINCT supplier_id FROM supplier_orders')->fetchAll(PDO::FETCH_COLUMN));
$leadTimeStatsBySupplier = supplier_lead_time_stats_batch($pdo, $supplierIdsWithOrders);
if ($supplierIdsWithOrders !== []) {
    $supplierPlaceholders = implode(',', array_fill(0, count($supplierIdsWithOrders), '?'));
    $supplierNameStmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id IN ({$supplierPlaceholders})");
    $supplierNameStmt->execute($supplierIdsWithOrders);
    $supplierNamesById = [];
    foreach ($supplierNameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $supplierNamesById[(int) $row['id']] = $row['name'];
    }

    foreach ($leadTimeStatsBySupplier as $supplierId => $stats) {
        if (($stats['late_orders_count'] ?? 0) > 0) {
            $supplierRisk[] = [
                'id' => $supplierId,
                'name' => $supplierNamesById[$supplierId] ?? 'Unknown Supplier',
                'late_orders_count' => $stats['late_orders_count'],
                'late_eligible_count' => $stats['late_eligible_count'],
                'avg_lead_time_days' => $stats['avg_lead_time_days'],
            ];
        }
    }
}
usort($supplierRisk, static fn (array $a, array $b): int => $b['late_orders_count'] <=> $a['late_orders_count']);
$supplierRisk = array_slice($supplierRisk, 0, 10);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1"><i class="bi bi-speedometer"></i> Purchasing Control Center</h1>
        <p class="page-description">One view of what needs buying, what's getting more expensive, and which suppliers are running late - assembled from the existing Purchasing, Forecast, Cost History, and Supplier Performance pages.</p>
    </div>
    <div class="action-bar">
        <a class="btn btn-outline-secondary btn-sm" href="/modules/purchasing/index.php">Purchasing Dashboard</a>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/reports/forecast.php">Full Forecast</a>
    </div>
</div>

<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Urgent Reorder Products</h5>
        <a class="small" href="/modules/reports/forecast.php?reorder_only=1">View full forecast &rarr;</a>
    </div>
    <p class="text-muted small mb-3">Products projected to run out before a reorder placed today would arrive - 30-day sales velocity, from includes/demand_forecast.php.</p>
    <?php if ($urgentRows === []): ?>
        <p class="text-muted small mb-0">Nothing is urgently low right now.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="text-end">Available</th>
                        <th class="text-end">Days Remaining</th>
                        <th class="text-end">Recommended Qty</th>
                        <th>Supplier</th>
                        <th class="text-end">Lead Time</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($urgentRows as $row): ?>
                        <tr>
                            <td>
                                <?php if ($canViewProducts): ?>
                                    <a href="/modules/products/view.php?id=<?php echo (int) $row['id']; ?>"><?php echo app_escape($row['name']); ?></a>
                                <?php else: ?>
                                    <?php echo app_escape($row['name']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo app_escape($row['sku']); ?></td>
                            <td class="text-end"><?php echo (int) $row['available_stock']; ?></td>
                            <td class="text-end text-danger fw-semibold"><?php echo $row['days_remaining'] !== null ? app_escape(number_format($row['days_remaining'], 1)) : $naBadge; ?></td>
                            <td class="text-end"><strong><?php echo (int) $row['recommended_qty']; ?></strong></td>
                            <td><?php echo $row['supplier_name'] !== null ? app_escape($row['supplier_name']) : '—'; ?></td>
                            <td class="text-end"><?php echo $row['lead_time_days'] !== null ? (app_escape(number_format($row['lead_time_days'], 1)) . 'd') : $naBadge; ?></td>
                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end flex-wrap">
                                    <?php if ($canViewProducts): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="/modules/products/view.php?id=<?php echo (int) $row['id']; ?>">Open Product</a>
                                    <?php endif; ?>
                                    <?php if ($row['supplier_id'] !== null): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="/modules/reports/supplier_detail.php?id=<?php echo (int) $row['supplier_id']; ?>">Open Supplier</a>
                                        <?php if ($canManageSupplierOrders): ?>
                                            <form method="post" action="/modules/purchasing/create_order.php" class="d-inline" onsubmit="return confirm('Create a draft supplier order for <?php echo app_escape(addslashes($row['supplier_name'] ?? '')); ?>? You can review and edit it before sending.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                                <input type="hidden" name="supplier_id" value="<?php echo (int) $row['supplier_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-primary">Create Supplier Order</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <h5 class="mb-0"><i class="bi bi-graph-up-arrow"></i> Cost Change Alerts</h5>
        <a class="small" href="/modules/reports/cost_history.php">View full cost history &rarr;</a>
    </div>
    <p class="text-muted small mb-3">Products whose current landed cost is higher than the last time a supplier order for them was received - from product_cost_history.</p>
    <?php if ($costAlerts === []): ?>
        <p class="text-muted small mb-0">No landed cost increases on file.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="text-end">Previous Landed Cost</th>
                        <th class="text-end">Current Landed Cost</th>
                        <th class="text-end">Change</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($costAlerts as $alert): ?>
                        <tr>
                            <td>
                                <?php if ($canViewProducts): ?>
                                    <a href="/modules/products/view.php?id=<?php echo (int) $alert['id']; ?>"><?php echo app_escape($alert['name']); ?></a>
                                <?php else: ?>
                                    <?php echo app_escape($alert['name']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo app_escape($alert['sku']); ?></td>
                            <td class="text-end">RM <?php echo app_escape(number_format($alert['previous_landed_cost'], 2)); ?></td>
                            <td class="text-end">RM <?php echo app_escape(number_format($alert['current_landed_cost'], 2)); ?></td>
                            <td class="text-end"><span class="badge bg-danger">+<?php echo app_escape(number_format($alert['change_percent'], 1)); ?>%</span></td>
                            <td class="text-end">
                                <?php if ($canViewProducts): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="/modules/products/view.php?id=<?php echo (int) $alert['id']; ?>">Open Product</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <h5 class="mb-0"><i class="bi bi-truck"></i> Supplier Risk Summary</h5>
        <a class="small" href="/modules/reports/suppliers.php">View full supplier performance &rarr;</a>
    </div>
    <p class="text-muted small mb-3">Suppliers with at least one order delivered after its expected delivery date, from Supplier Performance.</p>
    <?php if ($supplierRisk === []): ?>
        <p class="text-muted small mb-0">No suppliers currently have a late-delivery record on file.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th class="text-end">Late Orders</th>
                        <th class="text-end">Avg Delivery Time</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierRisk as $risk): ?>
                        <tr>
                            <td><?php echo app_escape($risk['name']); ?></td>
                            <td class="text-end">
                                <?php echo (int) $risk['late_orders_count']; ?>
                                <span class="text-muted small">/ <?php echo (int) $risk['late_eligible_count']; ?></span>
                            </td>
                            <td class="text-end"><?php echo $risk['avg_lead_time_days'] !== null ? (app_escape(number_format($risk['avg_lead_time_days'], 1)) . ' days') : $naBadge; ?></td>
                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end flex-wrap">
                                    <a class="btn btn-sm btn-outline-secondary" href="/modules/reports/supplier_detail.php?id=<?php echo (int) $risk['id']; ?>">Open Supplier</a>
                                    <?php if ($canManageSupplierOrders): ?>
                                        <form method="post" action="/modules/purchasing/create_order.php" class="d-inline" onsubmit="return confirm('Create a draft supplier order for <?php echo app_escape(addslashes($risk['name'])); ?>? You can review and edit it before sending.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                            <input type="hidden" name="supplier_id" value="<?php echo (int) $risk['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">Create Supplier Order</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
