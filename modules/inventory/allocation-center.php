<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/customer_storage.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('inventory.view');

/**
 * Preorder Allocation Center: the ONE dedicated place staff handle arrived preorder/early-
 * bird stock waiting to be matched to customer orders - deliberately separate from the main
 * Inventory page, which stays clean (On Hand/Reserved/Incoming/Arrived only, no allocation
 * warnings - see modules/inventory/index.php). Every row here comes from
 * inventory_allocation_queue(), a read-only view over the existing ledger; the actual
 * allocation action still happens on modules/inventory/allocate.php (unchanged), reached via
 * the "Allocate" button on each row/variation here.
 */

$appTitle = 'Allocate Preorders';
$pdo = app_db();

$queue = inventory_allocation_queue($pdo);
$canManage = app_has_permission('inventory.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Allocate Preorders</h2>
        <p class="text-muted mb-0">Arrived preorder/early-bird stock waiting to be matched to customer orders.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Back to Inventory</a>
</div>

<?php if ($queue === []): ?>
    <div class="card p-4">
        <p class="text-muted mb-0">Nothing needs allocation right now - every arrived preorder/early-bird item is either fully allocated or has no outstanding customer orders.</p>
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
                        <th>Arrived</th>
                        <th>Allocated</th>
                        <th>Need Allocate</th>
                        <th>Remaining</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queue as $product): ?>
                        <?php
                        $isVariable = $product['catalog_type'] === 'variable';
                        $groupKey = 'vg-' . (int) $product['product_id'];
                        // A variable product's parent row is only a container - it never
                        // carries its own inventory (see modules/inventory/index.php's same
                        // rule) - it just rolls up whether ANY variation below it needs
                        // allocation, and is expanded by default here since every row on
                        // this page, by definition, needs attention.
                        $needAllocateTotal = array_sum(array_column($product['units'], 'need_allocate'));
                        $remainingTotal = array_sum(array_column($product['units'], 'remaining'));
                        $arrivedTotal = array_sum(array_column($product['units'], 'arrived_total'));
                        $allocatedTotal = array_sum(array_column($product['units'], 'allocated_total'));
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
                                <td class="text-muted"><?php echo (int) $arrivedTotal; ?></td>
                                <td class="text-muted"><?php echo (int) $allocatedTotal; ?></td>
                                <td class="text-muted"><?php echo (int) $needAllocateTotal; ?></td>
                                <td class="text-muted"><?php echo (int) $remainingTotal; ?></td>
                                <td></td>
                            </tr>
                            <?php foreach ($product['units'] as $unit): ?>
                                <tr class="inventory-variation-row" data-group="<?php echo app_escape($groupKey); ?>">
                                    <td></td>
                                    <td></td>
                                    <td style="padding-left: 2rem;">&#8627; <?php echo app_escape($unit['label']); ?></td>
                                    <td><?php echo app_escape($unit['sku']); ?></td>
                                    <td><?php echo (int) $unit['arrived_total']; ?></td>
                                    <td><?php echo (int) $unit['allocated_total']; ?></td>
                                    <td><span class="badge bg-warning text-dark"><?php echo (int) $unit['need_allocate']; ?></span></td>
                                    <td><?php echo (int) $unit['remaining']; ?></td>
                                    <td class="text-end">
                                        <?php if ($canManage): ?>
                                            <a class="btn btn-sm btn-primary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['product_id']; ?>&variation_id=<?php echo (int) $unit['variation_id']; ?>">Allocate</a>
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
                                <td><?php echo (int) $unit['arrived_total']; ?></td>
                                <td><?php echo (int) $unit['allocated_total']; ?></td>
                                <td><span class="badge bg-warning text-dark"><?php echo (int) $unit['need_allocate']; ?></span></td>
                                <td><?php echo (int) $unit['remaining']; ?></td>
                                <td class="text-end">
                                    <?php if ($canManage): ?>
                                        <a class="btn btn-sm btn-primary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['product_id']; ?>">Allocate</a>
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
