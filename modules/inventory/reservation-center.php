<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('inventory.view');

/**
 * Reservation Center: the ready-stock mirror of the Preorder Allocation Center - the ONE
 * dedicated place staff top up backordered ready-stock order items once more available
 * stock arrives (see inventory_reserve_for_order_partial(), which may leave an item
 * under-reserved at payment-approval time if stock wasn't on hand yet). Every row here comes
 * from inventory_reservation_queue(), a read-only view over the existing ledger; the actual
 * reservation action still happens on modules/inventory/reserve.php, reached via the
 * "Reserve" button on each row/variation here.
 */

$appTitle = 'Reservation Center';
$pdo = app_db();

$queue = inventory_reservation_queue($pdo);
$canManage = app_has_permission('inventory.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Reservation Center</h2>
        <p class="text-muted mb-0">Ready-stock units with available stock waiting to be reserved for backordered customer orders.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Back to Inventory</a>
</div>

<?php if ($queue === []): ?>
    <div class="card p-4">
        <p class="text-muted mb-0">Nothing needs reserving right now - every available ready-stock unit is either fully reserved or has no outstanding paid orders.</p>
    </div>
<?php else: ?>
    <div class="card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="inventory-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Product</th>
                        <th>Variation</th>
                        <th>SKU</th>
                        <th>Available</th>
                        <th>Need Reserve</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queue as $product): ?>
                        <?php
                        $isVariable = $product['catalog_type'] === 'variable';
                        $groupKey = 'vg-' . (int) $product['product_id'];
                        $needReserveTotal = array_sum(array_column($product['units'], 'need_reserve'));
                        $availableTotal = array_sum(array_column($product['units'], 'available'));
                        ?>
                        <?php if ($isVariable): ?>
                            <tr class="table-light js-inventory-parent" data-group="<?php echo app_escape($groupKey); ?>" data-expanded="1" style="cursor:pointer;">
                                <td></td>
                                <td>
                                    <span class="js-inventory-caret text-muted me-1">&#9660;</span>
                                    <span class="fw-semibold"><?php echo app_escape($product['name']); ?></span>
                                    <span class="badge bg-info text-dark ms-1">Variable</span>
                                </td>
                                <td class="text-muted">&mdash;</td>
                                <td><?php echo app_escape($product['sku']); ?></td>
                                <td class="text-muted"><?php echo (int) $availableTotal; ?></td>
                                <td class="text-muted"><?php echo (int) $needReserveTotal; ?></td>
                                <td></td>
                            </tr>
                            <?php foreach ($product['units'] as $unit): ?>
                                <tr class="inventory-variation-row" data-group="<?php echo app_escape($groupKey); ?>">
                                    <td></td>
                                    <td></td>
                                    <td style="padding-left: 2rem;">&#8627; <?php echo app_escape($unit['label']); ?></td>
                                    <td><?php echo app_escape($unit['sku']); ?></td>
                                    <td><?php echo (int) $unit['available']; ?></td>
                                    <td><span class="badge bg-warning text-dark"><?php echo (int) $unit['need_reserve']; ?></span></td>
                                    <td class="text-end">
                                        <?php if ($canManage): ?>
                                            <a class="btn btn-sm btn-primary" href="/modules/inventory/reserve.php?product_id=<?php echo (int) $product['product_id']; ?>&variation_id=<?php echo (int) $unit['variation_id']; ?>">Reserve</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php $unit = $product['units'][0]; ?>
                            <tr>
                                <td></td>
                                <td><span class="fw-semibold"><?php echo app_escape($product['name']); ?></span></td>
                                <td class="text-muted">&mdash;</td>
                                <td><?php echo app_escape($unit['sku']); ?></td>
                                <td><?php echo (int) $unit['available']; ?></td>
                                <td><span class="badge bg-warning text-dark"><?php echo (int) $unit['need_reserve']; ?></span></td>
                                <td class="text-end">
                                    <?php if ($canManage): ?>
                                        <a class="btn btn-sm btn-primary" href="/modules/inventory/reserve.php?product_id=<?php echo (int) $product['product_id']; ?>">Reserve</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
$inventoryJsPath = __DIR__ . '/../../assets/js/inventory.js';
$inventoryJsVersion = is_file($inventoryJsPath) ? filemtime($inventoryJsPath) : time();
?>
<script src="/assets/js/inventory.js?v=<?php echo (int) $inventoryJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
