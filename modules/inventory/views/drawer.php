<?php
/**
 * Mewmii OS v2 Phase 2 - Drawer View for Inventory (docs/PHASE2_IMPLEMENTATION.md). Rendered
 * by modules/inventory/ajax/drawer.php (the Controller) - never invoked directly, relies on
 * that file's variables ($productRow, $variationId, $variationSku, $variationLabel, $stock,
 * $recentTransactions, $productTypeLabel, $canManage, $canManageProducts, $unitKey) already
 * being in scope, the same plain-include convention every other partial in this codebase
 * already uses (e.g. modules/orders/_item_picker_modal.php). Pure presentation - no query, no
 * business logic.
 */
?>
<div class="p-3 border-bottom">
    <div class="fw-semibold"><?php echo app_escape($productRow['name']); ?></div>
    <?php if ($variationLabel !== null && $variationLabel !== ''): ?>
        <div class="text-muted small">&#8627; <?php echo app_escape($variationLabel); ?></div>
    <?php endif; ?>
    <div class="text-muted small mt-1">
        SKU <?php echo app_escape($variationSku ?? $productRow['sku']); ?>
        &middot; <?php echo app_escape($productTypeLabel); ?>
        &middot; <?php echo app_escape(ucfirst((string) $productRow['status'])); ?>
    </div>
</div>

<div class="p-3 border-bottom">
    <h6 class="text-muted small text-uppercase mb-2">Stock</h6>
    <div class="row g-2 text-center">
        <div class="col-3">
            <div class="fs-5 fw-bold"><?php echo (int) $stock['available_quantity']; ?></div>
            <div class="text-muted small">Available</div>
        </div>
        <div class="col-3">
            <div class="fs-5 fw-bold"><?php echo (int) $stock['reserved_quantity']; ?></div>
            <div class="text-muted small">Reserved</div>
        </div>
        <div class="col-3">
            <div class="fs-5 fw-bold"><?php echo (int) $stock['incoming_quantity']; ?></div>
            <div class="text-muted small">Incoming</div>
        </div>
        <div class="col-3">
            <div class="fs-5 fw-bold"><?php echo (int) $stock['arrived_quantity']; ?></div>
            <div class="text-muted small">Arrived</div>
        </div>
    </div>
</div>

<div class="p-3 border-bottom">
    <h6 class="text-muted small text-uppercase mb-2">Recent Activity</h6>
    <?php if ($recentTransactions === []): ?>
        <p class="text-muted small mb-0">No transactions yet.</p>
    <?php else: ?>
        <ul class="list-unstyled mb-0 small">
            <?php foreach ($recentTransactions as $transaction): ?>
                <?php $qty = (int) $transaction['quantity']; ?>
                <li class="d-flex justify-content-between border-bottom py-1">
                    <span><?php echo app_escape(str_replace('_', ' ', (string) $transaction['transaction_type'])); ?></span>
                    <span class="<?php echo $qty > 0 ? 'text-success' : ($qty < 0 ? 'text-danger' : ''); ?>">
                        <?php echo $qty > 0 ? '+' : ''; ?><?php echo $qty; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="p-3">
    <h6 class="text-muted small text-uppercase mb-2">Actions</h6>
    <div class="d-flex flex-column gap-2">
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="InventoryUI.openAdjustModal('<?php echo app_escape($unitKey); ?>')">Adjust Stock</button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="InventoryUI.openHistoryModal(<?php echo (int) $productRow['id']; ?>, <?php echo (int) ($variationId ?? 0); ?>, '<?php echo app_escape(addslashes($variationSku ?? $productRow['sku'])); ?>')">View Full History</button>
        <?php if ($canManageProducts): ?>
            <a class="btn btn-outline-secondary btn-sm" href="/modules/products/edit.php?id=<?php echo (int) $productRow['id']; ?>">Edit Product</a>
        <?php endif; ?>
        <?php if ((int) $stock['arrived_quantity'] > 0): ?>
            <a class="btn btn-outline-primary btn-sm" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $productRow['id']; ?><?php echo $variationId !== null ? '&variation_id=' . (int) $variationId : ''; ?>">Allocate</a>
        <?php endif; ?>
    </div>
</div>
