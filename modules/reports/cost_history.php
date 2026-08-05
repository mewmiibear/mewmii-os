<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/product_cost.php';
app_require_permission('products.view');

/**
 * Phase 8C - Product Cost History. Read-only reporting over product_cost_history (frozen
 * Landed Cost snapshots captured at receiving/completion - see database/schema.sql). "Current
 * landed cost" below is the LIVE figure from product_cost_calculate_batch() (reused, never
 * recalculated here); "Previous landed cost" is the most recent snapshot on file. Comparing a
 * live number against a frozen one is the whole point of this table existing - see
 * includes/product_cost.php's product_cost_history_capture() docblock.
 */

$appTitle = 'Cost History';
$pdo = app_db();

$searchTerm = trim((string) ($_GET['q'] ?? ''));

// Candidate products: only those with at least one snapshot - never scans the whole catalog.
$productIds = product_cost_history_product_ids($pdo);

$rows = [];
if ($productIds !== []) {
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $productStmt = $pdo->prepare("
        SELECT id, sku, name
        FROM products
        WHERE id IN ({$placeholders})
        ORDER BY name ASC
    ");
    $productStmt->execute($productIds);
    $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

    // One batched query for every candidate product's full snapshot history, not one per
    // product - also reused below for the Date History expansion under each row.
    $historyByProduct = product_cost_history_list_batch($pdo, $productIds);

    // One batched call for every candidate product's LIVE current Landed Cost - the same
    // engine call every other cost-aware page in this app uses, never duplicated.
    $currentCostByProduct = product_cost_calculate_batch($pdo, $productIds);

    foreach ($products as $product) {
        if ($searchTerm !== '' && stripos($product['name'], $searchTerm) === false && stripos($product['sku'], $searchTerm) === false) {
            continue;
        }

        $productId = (int) $product['id'];
        $history = $historyByProduct[$productId] ?? [];
        if ($history === []) {
            continue;
        }

        $previousSnapshot = $history[count($history) - 1];
        $previousLandedCost = (float) $previousSnapshot['landed_cost'];

        $currentCost = $currentCostByProduct[$productId] ?? null;
        $currentLandedCost = $currentCost['landed_cost'] ?? null;

        $changePercent = ($currentLandedCost !== null && $previousLandedCost > 0)
            ? (($currentLandedCost - $previousLandedCost) / $previousLandedCost * 100)
            : null;

        $rows[] = [
            'product_id' => $productId,
            'sku' => $product['sku'],
            'name' => $product['name'],
            'supplier_name' => $previousSnapshot['supplier_name'],
            'previous_landed_cost' => $previousLandedCost,
            'previous_date' => $previousSnapshot['captured_at'],
            'current_landed_cost' => $currentLandedCost,
            'change_percent' => $changePercent,
            'history' => $history,
        ];
    }
}

usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

$canViewProducts = app_has_permission('products.view');
$canViewSupplierOrders = app_has_permission('supplier-orders.view');
$naBadge = '<span class="text-muted">Not available</span>';

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1"><i class="bi bi-clock-history"></i> Cost History</h1>
        <p class="page-description">Landed cost as it stood the last time each product was received, compared against today's live cost.</p>
    </div>
</div>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Name or SKU">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/reports/cost_history.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<?php if ($rows === []): ?>
    <div class="card p-4">
        <div class="empty-state">
            <div class="empty-state-title">No Cost History Yet</div>
            <p class="empty-state-text">A snapshot is captured the next time a supplier order is received or completed.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($rows as $row): ?>
        <?php $rowDomId = 'cost-history-' . $row['product_id']; ?>
        <div class="card mb-3">
            <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-2" style="cursor: pointer;" role="button" data-bs-toggle="collapse" data-bs-target="#<?php echo app_escape($rowDomId); ?>" aria-expanded="false" aria-controls="<?php echo app_escape($rowDomId); ?>">
                <div>
                    <strong>
                        <?php if ($canViewProducts): ?>
                            <a href="/modules/products/view.php?id=<?php echo (int) $row['product_id']; ?>" onclick="event.stopPropagation();"><?php echo app_escape($row['name']); ?></a>
                        <?php else: ?>
                            <?php echo app_escape($row['name']); ?>
                        <?php endif; ?>
                    </strong>
                    <span class="text-muted small ms-2"><?php echo app_escape($row['sku']); ?></span>
                    <?php if ($row['supplier_name'] !== null): ?>
                        <span class="text-muted small ms-2">&middot; <?php echo app_escape($row['supplier_name']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <div class="text-end small">
                        <div class="text-muted">Previous (<?php echo app_escape($row['previous_date']); ?>)</div>
                        <div>RM <?php echo app_escape(number_format($row['previous_landed_cost'], 2)); ?></div>
                    </div>
                    <div class="text-end small">
                        <div class="text-muted">Current</div>
                        <div><?php echo $row['current_landed_cost'] !== null ? ('RM ' . app_escape(number_format($row['current_landed_cost'], 2))) : $naBadge; ?></div>
                    </div>
                    <div class="text-end" style="min-width: 90px;">
                        <?php if ($row['change_percent'] === null): ?>
                            <?php echo $naBadge; ?>
                        <?php elseif (abs($row['change_percent']) < 0.01): ?>
                            <span class="badge bg-secondary">Unchanged</span>
                        <?php elseif ($row['change_percent'] > 0): ?>
                            <span class="badge bg-danger">+<?php echo app_escape(number_format($row['change_percent'], 1)); ?>%</span>
                        <?php else: ?>
                            <span class="badge bg-success"><?php echo app_escape(number_format($row['change_percent'], 1)); ?>%</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="collapse" id="<?php echo app_escape($rowDomId); ?>">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Supplier Order</th>
                                <th>Supplier</th>
                                <th class="text-end">Supplier Cost</th>
                                <th class="text-end">Shipping</th>
                                <th class="text-end">Other Costs</th>
                                <th class="text-end">Landed Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($row['history']) as $snapshot): ?>
                                <tr>
                                    <td><?php echo app_escape($snapshot['captured_at']); ?></td>
                                    <td>
                                        <?php if ($canViewSupplierOrders): ?>
                                            <a href="/modules/supplier-orders/view.php?id=<?php echo (int) $snapshot['supplier_order_id']; ?>"><?php echo app_escape($snapshot['purchase_number']); ?></a>
                                        <?php else: ?>
                                            <?php echo app_escape($snapshot['purchase_number']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $snapshot['supplier_name'] !== null ? app_escape($snapshot['supplier_name']) : '—'; ?></td>
                                    <td class="text-end">RM <?php echo app_escape(number_format((float) $snapshot['converted_cost'], 2)); ?></td>
                                    <td class="text-end"><?php echo $snapshot['shipping_cost'] !== null ? ('RM ' . app_escape(number_format((float) $snapshot['shipping_cost'], 2))) : '—'; ?></td>
                                    <td class="text-end">RM <?php echo app_escape(number_format((float) $snapshot['other_costs'], 2)); ?></td>
                                    <td class="text-end">RM <?php echo app_escape(number_format((float) $snapshot['landed_cost'], 2)); ?></td>
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
