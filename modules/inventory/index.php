<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/product_images.php';
app_require_permission('inventory.view');

$appTitle = 'Inventory';
$error = '';
$pdo = app_db();

$adjustmentReasons = ['Damaged', 'Recount / Stock Take', 'Correction', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !app_has_permission('inventory.manage')) {
        http_response_code(403);
        $error = 'You do not have permission to adjust inventory.';
    }

    if ($error === '') {
        $unitKey = trim((string) ($_POST['unit_key'] ?? ''));
        $adjustmentType = (string) ($_POST['adjustment_type'] ?? 'increase');
        $quantityInput = (int) ($_POST['quantity'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $unit = $unitKey !== '' ? catalog_parse_sellable_key($unitKey) : null;

        if ($unit === null || $unit['product_id'] < 1) {
            $error = 'Select a product.';
        } elseif (!in_array($adjustmentType, ['increase', 'decrease', 'set_exact'], true)) {
            $error = 'Invalid adjustment type.';
        } elseif ($quantityInput < 0 || ($adjustmentType !== 'set_exact' && $quantityInput === 0)) {
            $error = 'Enter a valid quantity.';
        } elseif ($reason === '') {
            $error = 'Select a reason for this adjustment.';
        } else {
            $productId = $unit['product_id'];
            $variationId = $unit['variation_id'];

            $pdo->beginTransaction();

            try {
                $row = inventory_get_or_create_row($pdo, $productId, $variationId);

                $delta = match ($adjustmentType) {
                    'decrease' => -$quantityInput,
                    'set_exact' => $quantityInput - (int) $row['available_quantity'],
                    default => $quantityInput,
                };

                if ($delta === 0) {
                    throw new RuntimeException('This adjustment would not change the current quantity.');
                }

                if ($delta < 0 && (int) $row['available_quantity'] + $delta < 0) {
                    throw new RuntimeException('Adjustment would result in negative available stock.');
                }

                $pdo->prepare('
                    UPDATE mewmii_inventory
                    SET available_quantity = available_quantity + ?
                    WHERE product_id = ? AND variation_id <=> ?
                ')->execute([$delta, $productId, $variationId]);

                inventory_log_transaction($pdo, $productId, 'adjustment', $delta, 'manual_adjustment', (int) ($_SESSION['user_id'] ?? 0), $variationId, $reason, $notes !== '' ? $notes : null);

                $pdo->commit();

                app_redirect('/modules/inventory/index.php?adjusted=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to adjust inventory.';
            }
        }
    }
}

// Products drive the listing (grouping variations under their parent) rather than a flat
// union of "sellable units" - LIMIT is scoped to products, then each variable product's
// variations are fetched underneath it. Simple products are still simple single rows.
$productTypeLabels = ['ready_stock' => 'Ready Stock', 'preorder' => 'Preorder', 'early_bird' => 'Early Bird'];

$productsStmt = $pdo->query("
    SELECT id, sku, name, catalog_type, product_type, min_stock_threshold
    FROM products
    ORDER BY id DESC
    LIMIT 20
");
$inventory = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

$simpleIds = [];
$variableIds = [];
foreach ($inventory as $product) {
    if ($product['catalog_type'] === 'variable') {
        $variableIds[] = (int) $product['id'];
    } else {
        $simpleIds[] = (int) $product['id'];
    }
}

$simpleInventoryByProduct = [];
if ($simpleIds !== []) {
    $placeholders = implode(',', array_fill(0, count($simpleIds), '?'));
    $stmt = $pdo->prepare("
        SELECT product_id,
               COALESCE(available_quantity, 0) AS stock_quantity,
               COALESCE(reserved_quantity, 0) AS reserved_stock,
               COALESCE(incoming_quantity, 0) AS incoming_stock,
               COALESCE(arrived_quantity, 0) AS arrived_stock,
               updated_at
        FROM mewmii_inventory
        WHERE product_id IN ($placeholders) AND variation_id IS NULL
    ");
    $stmt->execute($simpleIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $simpleInventoryByProduct[(int) $row['product_id']] = $row;
    }
}

$variationsByProduct = [];
if ($variableIds !== []) {
    $placeholders = implode(',', array_fill(0, count($variableIds), '?'));
    $stmt = $pdo->prepare("
        SELECT pv.id AS variation_id, pv.product_id, pv.sku,
               COALESCE(inv.available_quantity, 0) AS stock_quantity,
               COALESCE(inv.reserved_quantity, 0) AS reserved_stock,
               COALESCE(inv.incoming_quantity, 0) AS incoming_stock,
               COALESCE(inv.arrived_quantity, 0) AS arrived_stock,
               inv.updated_at
        FROM product_variations pv
        LEFT JOIN mewmii_inventory inv ON inv.variation_id = pv.id
        WHERE pv.product_id IN ($placeholders) AND pv.status <> 'archived'
        ORDER BY pv.product_id ASC, pv.id ASC
    ");
    $stmt->execute($variableIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // variation_build_full_label() spells out each attribute's name (e.g. "Character:
        // My Melody") rather than the compact value-only label used elsewhere in the app,
        // since a bare "My Melody" wouldn't say which attribute it is at a glance here.
        $row['variation_label'] = variation_build_full_label($pdo, (int) $row['variation_id']);
        $variationsByProduct[(int) $row['product_id']][] = $row;
    }
}

foreach ($inventory as &$product) {
    $productId = (int) $product['id'];
    $mainImage = product_image_get_main($pdo, $productId);
    $product['thumb_path'] = $mainImage['image_path'] ?? null;

    if ($product['catalog_type'] === 'variable') {
        $product['variations'] = $variationsByProduct[$productId] ?? [];

        // A variable product never holds its own mewmii_inventory row (see
        // inventory_get_or_create_row()) - these are a read-only rollup across its
        // variations for the group heading row, purely for at-a-glance scanning. The real,
        // adjustable quantities stay on each variation row underneath.
        $totals = product_effective_stock($pdo, $productId);
        $product['stock_quantity'] = (int) $totals['available_quantity'];
        $product['reserved_stock'] = (int) $totals['reserved_quantity'];
        $product['incoming_stock'] = (int) $totals['incoming_quantity'];
        $product['arrived_stock'] = (int) $totals['arrived_quantity'];
        $product['updated_at'] = null;
    } else {
        $row = $simpleInventoryByProduct[$productId] ?? ['stock_quantity' => 0, 'reserved_stock' => 0, 'incoming_stock' => 0, 'arrived_stock' => 0, 'updated_at' => null];
        $product['stock_quantity'] = (int) $row['stock_quantity'];
        $product['reserved_stock'] = (int) $row['reserved_stock'];
        $product['incoming_stock'] = (int) $row['incoming_stock'];
        $product['arrived_stock'] = (int) $row['arrived_stock'];
        $product['updated_at'] = $row['updated_at'];
    }
}
unset($product);

$sellableUnits = catalog_sellable_units($pdo);
$canManage = app_has_permission('inventory.manage');

/**
 * Low Stock / Out of Stock badges: exact rule already established in
 * modules/products/edit.php, reused verbatim here for consistency. Only ready_stock is
 * ever flagged - preorder/early_bird are deliberately purchasable at 0 stock (see
 * catalog_product_is_orderable()), so they never show either badge.
 */
function inventory_stock_badges(string $productType, int $availableQuantity, ?int $minStockThreshold): string
{
    if ($productType !== 'ready_stock') {
        return '';
    }

    if ($availableQuantity === 0) {
        return '<span class="badge bg-danger ms-1">Out of Stock</span>';
    }

    if ($minStockThreshold !== null && $availableQuantity < $minStockThreshold) {
        return '<span class="badge bg-warning text-dark ms-1">Low Stock</span>';
    }

    return '';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Inventory</h2>
        <p class="text-muted mb-0">Current stock at a glance - adjustments and history stay one click away.</p>
    </div>
    <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" onclick="InventoryUI.openAdjustModal('')">Adjust Stock</button>
    <?php endif; ?>
</div>

<?php if (isset($_GET['adjusted'])): ?>
    <div class="alert alert-success">Inventory adjusted.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th></th>
                <th>Product</th>
                <th>Variation</th>
                <th>SKU</th>
                <th>Type</th>
                <th>Current</th>
                <th>Available</th>
                <th>Reserved</th>
                <th>Incoming</th>
                <th>Arrived</th>
                <th>Last Updated</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventory as $product): ?>
                <?php
                $isVariable = $product['catalog_type'] === 'variable';
                $productTypeLabel = $productTypeLabels[$product['product_type']] ?? $product['product_type'];
                ?>
                <tr class="<?php echo $isVariable ? 'table-light' : ''; ?>">
                    <td data-label="">
                        <?php if (!empty($product['thumb_path'])): ?>
                            <img src="/<?php echo app_escape($product['thumb_path']); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;">
                        <?php else: ?>
                            <div class="bg-light text-muted border rounded d-flex align-items-center justify-content-center" style="width:40px;height:40px;font-size:.6rem;">No image</div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Product">
                        <span class="fw-semibold"><?php echo app_escape($product['name']); ?></span>
                        <?php if ($isVariable): ?>
                            <span class="badge bg-info text-dark ms-1">Variable</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Variation" class="text-muted">&mdash;</td>
                    <td data-label="SKU"><?php echo app_escape($product['sku']); ?></td>
                    <td data-label="Type"><?php echo app_escape($productTypeLabel); ?></td>
                    <td data-label="Current"<?php echo $isVariable ? ' class="text-muted"' : ''; ?>><?php echo app_escape((string) ($product['stock_quantity'] + $product['reserved_stock'])); ?></td>
                    <td data-label="Available"<?php echo $isVariable ? ' class="text-muted"' : ''; ?>>
                        <?php echo app_escape((string) $product['stock_quantity']); ?>
                        <?php if (!$isVariable): ?>
                            <?php echo inventory_stock_badges($product['product_type'], $product['stock_quantity'], $product['min_stock_threshold'] !== null ? (int) $product['min_stock_threshold'] : null); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Reserved"<?php echo $isVariable ? ' class="text-muted"' : ''; ?>><?php echo app_escape((string) $product['reserved_stock']); ?></td>
                    <td data-label="Incoming"<?php echo $isVariable ? ' class="text-muted"' : ''; ?>><?php echo app_escape((string) $product['incoming_stock']); ?></td>
                    <td data-label="Arrived"<?php echo $isVariable ? ' class="text-muted"' : ''; ?>><?php echo app_escape((string) $product['arrived_stock']); ?></td>
                    <td data-label="Last Updated" class="text-muted small"><?php echo $product['updated_at'] !== null ? app_escape($product['updated_at']) : '&mdash;'; ?></td>
                    <td data-label="" class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <?php if (!$isVariable): ?>
                                <?php if ($canManage): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" title="Adjust Stock" onclick="InventoryUI.openAdjustModal('<?php echo (int) $product['id']; ?>:0')">&plusmn;</button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="View History" onclick="InventoryUI.openHistoryModal(<?php echo (int) $product['id']; ?>, 0, '<?php echo app_escape(addslashes($product['sku'])); ?>')">&#128337;</button>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="/modules/products/edit.php?id=<?php echo (int) $product['id']; ?>" title="Edit Product">&#9998;</a>
                            <?php if (!$isVariable && (int) $product['arrived_stock'] > 0): ?>
                                <a class="btn btn-sm btn-outline-primary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['id']; ?>">Allocate</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if ($isVariable): ?>
                    <?php foreach ($product['variations'] as $variation): ?>
                        <?php $unitKey = (int) $product['id'] . ':' . (int) $variation['variation_id']; ?>
                        <tr>
                            <td data-label=""></td>
                            <td data-label="Product"></td>
                            <td data-label="Variation" style="padding-left: 2rem;">
                                &#8627; <?php echo $variation['variation_label'] !== '' ? app_escape($variation['variation_label']) : '<span class="text-muted">&mdash;</span>'; ?>
                            </td>
                            <td data-label="SKU"><?php echo app_escape($variation['sku']); ?></td>
                            <td data-label="Type"></td>
                            <td data-label="Current"><?php echo app_escape((string) ($variation['stock_quantity'] + $variation['reserved_stock'])); ?></td>
                            <td data-label="Available">
                                <?php echo app_escape((string) $variation['stock_quantity']); ?>
                                <?php echo inventory_stock_badges($product['product_type'], (int) $variation['stock_quantity'], $product['min_stock_threshold'] !== null ? (int) $product['min_stock_threshold'] : null); ?>
                            </td>
                            <td data-label="Reserved"><?php echo app_escape((string) $variation['reserved_stock']); ?></td>
                            <td data-label="Incoming"><?php echo app_escape((string) $variation['incoming_stock']); ?></td>
                            <td data-label="Arrived"><?php echo app_escape((string) $variation['arrived_stock']); ?></td>
                            <td data-label="Last Updated" class="text-muted small"><?php echo $variation['updated_at'] !== null ? app_escape($variation['updated_at']) : '&mdash;'; ?></td>
                            <td data-label="" class="text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <?php if ($canManage): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" title="Adjust Stock" onclick="InventoryUI.openAdjustModal('<?php echo app_escape($unitKey); ?>')">&plusmn;</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="View History" onclick="InventoryUI.openHistoryModal(<?php echo (int) $product['id']; ?>, <?php echo (int) $variation['variation_id']; ?>, '<?php echo app_escape(addslashes($variation['sku'])); ?>')">&#128337;</button>
                                    <a class="btn btn-sm btn-outline-secondary" href="/modules/products/edit.php?id=<?php echo (int) $product['id']; ?>" title="Edit Product">&#9998;</a>
                                    <?php if ((int) $variation['arrived_stock'] > 0): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['id']; ?>&variation_id=<?php echo (int) $variation['variation_id']; ?>">Allocate</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($product['variations'] === []): ?>
                        <tr>
                            <td data-label=""></td>
                            <td data-label="" colspan="11" class="text-muted small">No active variations.</td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($inventory === []): ?>
                <tr><td colspan="12" class="text-muted">No products yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($canManage): ?>
<div class="modal fade modal-fullscreen-sm-down" id="adjustStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select class="form-select" name="unit_key" id="adjust-unit-key" required>
                            <option value="">Select a product&hellip;</option>
                            <?php foreach ($sellableUnits as $unit): ?>
                                <option value="<?php echo app_escape($unit['key']); ?>">
                                    <?php echo app_escape($unit['sku']); ?> &mdash; <?php echo app_escape($unit['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Variable products don't hold stock themselves - pick the specific variation to adjust.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select class="form-select" name="adjustment_type" required>
                            <option value="increase">Increase</option>
                            <option value="decrease">Decrease</option>
                            <option value="set_exact">Set Exact Quantity</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control" name="quantity" min="0" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <select class="form-select" name="reason" required>
                            <option value="">Select a reason&hellip;</option>
                            <?php foreach ($adjustmentReasons as $reasonOption): ?>
                                <option value="<?php echo app_escape($reasonOption); ?>"><?php echo app_escape($reasonOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Notes (optional)</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade modal-fullscreen-sm-down" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="history-modal-title">Transaction History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <input type="text" class="form-control form-control-sm" id="history-search" placeholder="Search reason, notes, reference&hellip;">
                    </div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" id="history-type-filter">
                            <option value="">All transaction types</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" id="history-filter-apply">Filter</button>
                    </div>
                </div>
                <div id="history-body">
                    <p class="text-muted">Loading&hellip;</p>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="history-prev">&larr; Newer</button>
                    <span class="text-muted small" id="history-page-info"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="history-next">Older &rarr;</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/inventory.js"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
