<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/product_cost.php';
app_require_permission('inventory.view');

/**
 * Inventory Intelligence (UI/UX Phase 5F.1) - read-only reporting, no new stock calculation
 * and no forecasting. Every number here is built from primitives that already exist and are
 * already trusted elsewhere:
 *   - catalog_sellable_units() (includes/product_variations.php, unchanged) - the same
 *     product/variation enumeration that purchase_planning_needs() already builds on.
 *   - mewmii_inventory.available_quantity - the same authoritative on-hand column every
 *     other inventory query in this app reads.
 *   - The exact "valid order" condition modules/reports/sales.php uses
 *     (payment_status = 'paid' AND order_status <> 'cancelled'), via the same
 *     includes/reports.php period helper sales.php now also uses.
 *   - Phase 7G / SO-A2C: Capital Tied Up / Total / Slow-Moving Inventory Value come from
 *     includes/product_cost.php's product_cost_calculate_units_batch() (true Landed Cost -
 *     Supplier Cost + Currency Conversion + Shipping Allocation + Additional Costs), resolved
 *     per product+variation because this page values units, not products. margins.php and
 *     purchasing/index.php keep using the product-level product_cost_calculate_batch(), which
 *     is correct for them - both roll stock up per product. Either way, instead of raw
 *     product_cost/variation cost_price. That engine works at the PARENT PRODUCT level only
 *     (no per-variation cost), so every variation of the same variable product now shows the
 *     same landed cost - consistent with how Purchasing/Margin Report already treat cost, but
 *     a change from this report's previous per-variation cost_price precision.
 *
 * Scope is deliberately ready_stock only, same product-type split purchase_planning_needs()
 * already applies: preorder/early_bird capital lives in arrived_quantity/customer_storage
 * instead of available_quantity, a different concept "dead stock"/"fast mover" doesn't map
 * onto the same way. Total/Slow-Moving Inventory Value below are both scoped to this same
 * ready-stock, on-hand basis, so the three top-line stats stay internally consistent with
 * each other - they are not a whole-business capital figure.
 */

$appTitle = 'Inventory Intelligence';
$pdo = app_db();

$period = report_period_from_request($_GET['period'] ?? null, '30days');
$dateCondition = report_period_date_condition($period, 'o.order_date');
$validOrderCondition = "o.payment_status = 'paid' AND o.order_status <> 'cancelled'{$dateCondition}";

$units = array_values(array_filter(
    catalog_sellable_units($pdo),
    static fn (array $unit): bool => $unit['product_type'] === 'ready_stock' && ($unit['status'] ?? 'draft') !== 'archived'
));

// SO-A2C - one batched call at SELLABLE UNIT grain. This page iterates units (a variable
// product contributes one unit per variation), so the previous product-level call applied a
// single blended cost to every variation of a product regardless of what each actually cost to
// buy. product_cost_calculate_units_batch() resolves purchase cost, shipping allocation,
// additional costs, and the effective selling price per product+variation instead. Still one
// batched call, not one per unit - the N+1 avoidance Phase 7G established is unchanged.
$costByUnit = product_cost_calculate_units_batch($pdo, $units);

$deadStock = [];
$fastMovers = [];
$totalInventoryValue = 0.0;
$slowMovingValue = 0.0;

if ($units !== []) {
    $unitConditions = [];
    $unitParams = [];
    foreach ($units as $unit) {
        $unitConditions[] = '(product_id = ? AND variation_id <=> ?)';
        $unitParams[] = $unit['product_id'];
        $unitParams[] = $unit['variation_id'];
    }
    $unitWhere = implode(' OR ', $unitConditions);

    // On-hand quantity, batched - same "(product_id = ? AND variation_id <=> ?) OR ..."
    // pattern already used by modules/orders/view.php's Phase 5D waiting-stock lookup.
    $stockByUnit = [];
    $stockStmt = $pdo->prepare("SELECT product_id, variation_id, available_quantity FROM mewmii_inventory WHERE {$unitWhere}");
    $stockStmt->execute($unitParams);
    foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stockByUnit[(int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0)] = (int) $row['available_quantity'];
    }

    // Recent sales, batched - the exact valid-order condition modules/reports/sales.php uses,
    // grouped down to product_id+variation_id (sales.php itself groups by product_id only,
    // rolling variations up) since one variation can be dead while a sibling isn't.
    $salesByUnit = [];
    $salesStmt = $pdo->prepare("
        SELECT oi.product_id, oi.variation_id, SUM(oi.quantity) AS units_sold
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE ({$unitWhere}) AND {$validOrderCondition}
        GROUP BY oi.product_id, oi.variation_id
    ");
    $salesStmt->execute($unitParams);
    foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $salesByUnit[(int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0)] = (int) $row['units_sold'];
    }

    foreach ($units as $unit) {
        $key = $unit['product_id'] . ':' . ($unit['variation_id'] ?? 0);
        $onHand = $stockByUnit[$key] ?? 0;

        if ($onHand <= 0) {
            continue;
        }

        $recentSales = $salesByUnit[$key] ?? 0;

        // Phase 7G - Landed Cost from the batch call above, keyed by product_id (this engine
        // has no per-variation cost, so every variation of the same product shares its parent's
        // figure). A product whose cost data isn't configured contributes an unknown amount -
        // capital_value stays null rather than guessed as 0 or as raw product_cost, and is
        // excluded from both sum cards below, same convention as modules/reports/margins.php.
        // Same "productId:variationId" key catalog_sellable_units() and $stockByUnit above
        // already use - a null variation collapses to 0.
        $cost = $costByUnit[$unit['product_id'] . ':' . (int) ($unit['variation_id'] ?? 0)] ?? null;
        $landedCost = $cost['landed_cost'] ?? null;
        $capitalValue = $landedCost !== null ? $onHand * $landedCost : null;
        if ($capitalValue !== null) {
            $totalInventoryValue += $capitalValue;
        }

        $row = $unit;
        $row['on_hand'] = $onHand;
        $row['units_sold'] = $recentSales;
        $row['capital_value'] = $capitalValue;
        $row['is_estimated'] = $cost['is_estimated'] ?? false;

        if ($recentSales === 0) {
            // Dead Stock / Slow Moving: real stock on hand, zero sales in the selected period.
            $deadStock[] = $row;
            if ($capitalValue !== null) {
                $slowMovingValue += $capitalValue;
            }
        } elseif ($onHand <= $recentSales) {
            // Fast Mover At Risk: a plain ratio flag ("sold at least as much as is currently
            // on hand, in this period") - a risk indicator only, not a projected stockout
            // date and not a reorder-point calculation.
            $fastMovers[] = $row;
        }
    }
}

// Phase 7G - capital_value can now be null (cost not configured) - sorts last regardless of
// how much stock is on hand, since an unknown value can't be meaningfully ranked.
usort($deadStock, static fn (array $a, array $b): int => ($b['capital_value'] ?? -INF) <=> ($a['capital_value'] ?? -INF));
usort($fastMovers, static fn (array $a, array $b): int => $b['units_sold'] <=> $a['units_sold']);
$deadStock = array_slice($deadStock, 0, 50);
$fastMovers = array_slice($fastMovers, 0, 50);

$canViewProducts = app_has_permission('products.view');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1"><i class="bi bi-graph-up"></i> Inventory Intelligence</h1>
        <p class="page-description">Dead stock, fast movers, and capital tied up in ready-stock inventory - a risk indicator, not a forecast.</p>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach (REPORT_PERIOD_LABELS as $value => $label): ?>
        <a class="btn btn-sm btn-filter<?php echo $period === $value ? ' is-active' : ''; ?>"
           href="/modules/reports/inventory.php?period=<?php echo app_escape($value); ?>">
            <?php echo app_escape($label); ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card stat-card p-4">
            <div class="stat-label">Total Inventory Value (Ready Stock)</div>
            <div class="stat-value">RM <?php echo app_escape(number_format($totalInventoryValue, 2)); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card stat-card p-4">
            <div class="stat-label">Slow-Moving Inventory Value</div>
            <div class="stat-value <?php echo $slowMovingValue > 0 ? 'stat-value-alert' : ''; ?>">RM <?php echo app_escape(number_format($slowMovingValue, 2)); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card stat-card p-4">
            <div class="stat-label">Fast Mover Risk Count</div>
            <div class="stat-value <?php echo count($fastMovers) > 0 ? 'stat-value-alert' : ''; ?>"><?php echo count($fastMovers); ?></div>
        </div>
    </div>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-1"><i class="bi bi-hourglass-split"></i> Dead Stock / Slow Moving</h5>
    <p class="text-muted small mb-3">Ready-stock products with real stock on hand but zero sales in <?php echo app_escape(strtolower(REPORT_PERIOD_LABELS[$period])); ?>. Ranked by capital tied up.</p>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th class="text-end">On Hand</th>
                    <th class="text-end">Units Sold</th>
                    <th class="text-end">Capital Tied Up</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deadStock as $row): ?>
                    <tr>
                        <td>
                            <?php if ($canViewProducts): ?>
                                <a href="/modules/products/view.php?id=<?php echo (int) $row['product_id']; ?>"><?php echo app_escape($row['label']); ?></a>
                            <?php else: ?>
                                <?php echo app_escape($row['label']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo app_escape($row['sku']); ?></td>
                        <td class="text-end"><?php echo (int) $row['on_hand']; ?></td>
                        <td class="text-end"><?php echo (int) $row['units_sold']; ?></td>
                        <td class="text-end">
                            <?php if ($row['capital_value'] !== null): ?>
                                RM <?php echo app_escape(number_format($row['capital_value'], 2)); ?>
                                <?php if ($row['is_estimated']): ?>
                                    <span class="badge bg-warning text-dark ms-1">Estimated</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary">Not configured</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($deadStock === []): ?>
                    <tr><td colspan="5"><div class="empty-state"><p class="empty-state-text mb-0">No dead stock in this period - every ready-stock product with on-hand stock has sold at least one unit.</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card p-4">
    <h5 class="mb-1"><i class="bi bi-exclamation-triangle"></i> Fast Movers At Risk</h5>
    <p class="text-muted small mb-3">Ready-stock products that sold at least as many units as are currently on hand, in <?php echo app_escape(strtolower(REPORT_PERIOD_LABELS[$period])); ?>. A risk indicator only - not a projected stockout date.</p>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th class="text-end">On Hand</th>
                    <th class="text-end">Units Sold</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fastMovers as $row): ?>
                    <tr>
                        <td>
                            <?php if ($canViewProducts): ?>
                                <a href="/modules/products/view.php?id=<?php echo (int) $row['product_id']; ?>"><?php echo app_escape($row['label']); ?></a>
                            <?php else: ?>
                                <?php echo app_escape($row['label']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo app_escape($row['sku']); ?></td>
                        <td class="text-end"><?php echo (int) $row['on_hand']; ?></td>
                        <td class="text-end"><?php echo (int) $row['units_sold']; ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/modules/inventory/index.php?q=<?php echo app_escape($row['sku']); ?>">View in Inventory</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($fastMovers === []): ?>
                    <tr><td colspan="5"><div class="empty-state"><p class="empty-state-text mb-0">No fast movers at risk in this period.</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
