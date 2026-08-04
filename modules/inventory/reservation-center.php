<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/product_variations.php';
// order_recompute_status(), needed by inventory_reserve_fifo_apply() - see its docblock for why
// includes/inventory.php cannot require this itself without creating a cycle.
require_once __DIR__ . '/../../includes/order_fulfillment.php';
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

$canManage = app_has_permission('inventory.manage');

$reserveMessage = '';
$reserveError = '';

// Reserve straight from the queue. Every row here already had a "Reserve" button, but it
// navigated to modules/inventory/reserve.php, where the operator clicked "Reserve Automatically
// (FIFO)" and came back - two page loads per unit, repeated for every line of a ready-stock
// restock. This runs the identical action in place via inventory_reserve_fifo_apply(), the same
// function reserve.php now calls; no reservation logic exists here. reserve.php remains the
// place to go for manual, per-order reservation. Mirrors modules/inventory/allocation-center.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();

        if (!$canManage) {
            http_response_code(403);
            $reserveError = 'You do not have permission to reserve inventory.';
        } elseif ((string) ($_POST['action'] ?? '') === 'reserve_fifo') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $variationId = isset($_POST['variation_id']) && (int) $_POST['variation_id'] > 0 ? (int) $_POST['variation_id'] : null;

            if ($productId < 1) {
                $reserveError = 'Invalid product.';
            } else {
                try {
                    $result = inventory_reserve_fifo_apply($pdo, $productId, $variationId);
                    $reserveMessage = 'Reserved for ' . count($result['reservations']) . ' order'
                        . (count($result['reservations']) === 1 ? '' : 's') . '.';
                } catch (RuntimeException $exception) {
                    $reserveError = $exception->getMessage();
                } catch (Exception $exception) {
                    $reserveError = 'Failed to auto-reserve stock.';
                }
            }
        }
    } catch (RuntimeException $exception) {
        $reserveError = $exception->getMessage();
    }
}

// Built AFTER the handler above so a reservation made on this request is reflected immediately -
// the queue is a live read over the ledger, so recomputing it here is what makes the row
// disappear (or its Need Reserve drop) without a manual refresh.
$queue = inventory_reservation_queue($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Reservation Center</h2>
        <p class="text-muted mb-0">Ready-stock units with available stock waiting to be reserved for backordered customer orders.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Back to Inventory</a>
</div>

<?php if ($reserveMessage !== ''): ?>
    <div class="alert alert-success"><?php echo app_escape($reserveMessage); ?></div>
<?php endif; ?>
<?php if ($reserveError !== ''): ?>
    <div class="alert alert-warning"><?php echo app_escape($reserveError); ?></div>
<?php endif; ?>

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
                                            <form method="post" class="d-inline" onsubmit="return confirm('Automatically reserve available stock for the oldest outstanding orders first?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="reserve_fifo">
                                                <input type="hidden" name="product_id" value="<?php echo (int) $product['product_id']; ?>">
                                                <input type="hidden" name="variation_id" value="<?php echo (int) $unit['variation_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-primary">Auto Reserve</button>
                                            </form>
                                            <a class="btn btn-sm btn-outline-secondary" href="/modules/inventory/reserve.php?product_id=<?php echo (int) $product['product_id']; ?>&variation_id=<?php echo (int) $unit['variation_id']; ?>">Manual</a>
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
                                        <form method="post" class="d-inline" onsubmit="return confirm('Automatically reserve available stock for the oldest outstanding orders first?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="reserve_fifo">
                                            <input type="hidden" name="product_id" value="<?php echo (int) $product['product_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">Auto Reserve</button>
                                        </form>
                                        <a class="btn btn-sm btn-outline-secondary" href="/modules/inventory/reserve.php?product_id=<?php echo (int) $product['product_id']; ?>">Manual</a>
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
