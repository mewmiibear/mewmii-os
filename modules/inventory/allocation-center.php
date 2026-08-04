<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/customer_storage.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/catalog.php';
// order_recompute_status(), needed by inventory_allocate_fifo_apply() - see its docblock for why
// includes/customer_storage.php cannot require this itself without creating a cycle.
require_once __DIR__ . '/../../includes/order_fulfillment.php';
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

$appTitle = 'Allocation Center';
$pdo = app_db();

$canManage = app_has_permission('inventory.manage');

$allocateMessage = '';
$allocateError = '';

// Allocate straight from the queue. Every row here already had an "Allocate" button, but it
// navigated to modules/inventory/allocate.php, where the operator clicked "Allocate
// Automatically (FIFO)" and came back - two page loads per product, repeated for every line of
// an arriving preorder shipment. This runs the identical action in place via
// inventory_allocate_fifo_apply(), the same function allocate.php now calls; no allocation logic
// exists here. allocate.php remains the place to go for manual, per-order allocation.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();

        if (!$canManage) {
            http_response_code(403);
            $allocateError = 'You do not have permission to allocate inventory.';
        } elseif ((string) ($_POST['action'] ?? '') === 'allocate_fifo') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $variationId = isset($_POST['variation_id']) && (int) $_POST['variation_id'] > 0 ? (int) $_POST['variation_id'] : null;

            if ($productId < 1) {
                $allocateError = 'Invalid product.';
            } else {
                try {
                    $result = inventory_allocate_fifo_apply($pdo, $productId, $variationId);
                    $allocateMessage = 'Allocated to ' . count($result['allocations']) . ' order'
                        . (count($result['allocations']) === 1 ? '' : 's') . '.';
                } catch (RuntimeException $exception) {
                    $allocateError = $exception->getMessage();
                } catch (Exception $exception) {
                    $allocateError = 'Failed to auto-allocate stock.';
                }
            }
        }
    } catch (RuntimeException $exception) {
        $allocateError = $exception->getMessage();
    }
}

// Built AFTER the handler above so an allocation made on this request is reflected immediately -
// the queue is a live read over the ledger, so recomputing it here is what makes the row
// disappear (or its Need Allocate drop) without a manual refresh.
$queue = inventory_allocation_queue($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Allocation Center</h2>
        <p class="text-muted mb-0">Arrived preorder/early-bird stock waiting to be matched to customer orders.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Back to Inventory</a>
</div>

<?php if ($allocateMessage !== ''): ?>
    <div class="alert alert-success"><?php echo app_escape($allocateMessage); ?></div>
<?php endif; ?>
<?php if ($allocateError !== ''): ?>
    <div class="alert alert-warning"><?php echo app_escape($allocateError); ?></div>
<?php endif; ?>

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
                                            <form method="post" class="d-inline" onsubmit="return confirm('Automatically allocate arrived stock to the oldest outstanding orders first?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="allocate_fifo">
                                                <input type="hidden" name="product_id" value="<?php echo (int) $product['product_id']; ?>">
                                                <input type="hidden" name="variation_id" value="<?php echo (int) $unit['variation_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-primary">Auto Allocate</button>
                                            </form>
                                            <a class="btn btn-sm btn-outline-secondary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['product_id']; ?>&variation_id=<?php echo (int) $unit['variation_id']; ?>">Manual</a>
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
                                        <form method="post" class="d-inline" onsubmit="return confirm('Automatically allocate arrived stock to the oldest outstanding orders first?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="allocate_fifo">
                                            <input type="hidden" name="product_id" value="<?php echo (int) $product['product_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">Auto Allocate</button>
                                        </form>
                                        <a class="btn btn-sm btn-outline-secondary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['product_id']; ?>">Manual</a>
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
