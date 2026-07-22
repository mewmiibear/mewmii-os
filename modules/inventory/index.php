<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('inventory.view');

$appTitle = 'Inventory';
$error = '';
$pdo = app_db();

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
        $delta = (int) ($_POST['delta'] ?? 0);
        $unit = $unitKey !== '' ? catalog_parse_sellable_key($unitKey) : null;

        if ($unit === null || $unit['product_id'] < 1) {
            $error = 'Select a product.';
        } elseif ($delta === 0) {
            $error = 'Enter a non-zero adjustment quantity.';
        } else {
            $productId = $unit['product_id'];
            $variationId = $unit['variation_id'];

            $pdo->beginTransaction();

            try {
                $row = inventory_get_or_create_row($pdo, $productId, $variationId);

                if ($delta < 0 && (int) $row['available_quantity'] + $delta < 0) {
                    throw new RuntimeException('Adjustment would result in negative available stock.');
                }

                $pdo->prepare('
                    UPDATE mewmii_inventory
                    SET available_quantity = available_quantity + ?
                    WHERE product_id = ? AND variation_id <=> ?
                ')->execute([$delta, $productId, $variationId]);

                inventory_log_transaction($pdo, $productId, 'adjustment', $delta, 'manual_adjustment', (int) ($_SESSION['user_id'] ?? 0), $variationId);

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

// Products drive the listing now (grouping variations under their parent), not a flat
// union of "sellable units" - LIMIT is scoped to products (same cap as before), then each
// variable product's variations are fetched underneath it. Simple products are still
// simple single rows.
$productTypeLabels = ['ready_stock' => 'Ready Stock', 'preorder' => 'Preorder', 'early_bird' => 'Early Bird'];

$productsStmt = $pdo->query("
    SELECT id, sku, name, catalog_type, product_type
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
               COALESCE(arrived_quantity, 0) AS arrived_stock
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
               COALESCE(inv.arrived_quantity, 0) AS arrived_stock
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

    if ($product['catalog_type'] === 'variable') {
        $product['variations'] = $variationsByProduct[$productId] ?? [];

        // A variable product never holds its own mewmii_inventory row (see
        // inventory_get_or_create_row()) - these are a read-only rollup across its
        // variations for the group heading row, purely for at-a-glance scanning. The real,
        // adjustable quantities stay on each variation row underneath, per product_effective_stock().
        $totals = product_effective_stock($pdo, $productId);
        $product['stock_quantity'] = (int) $totals['available_quantity'];
        $product['reserved_stock'] = (int) $totals['reserved_quantity'];
        $product['arrived_stock'] = (int) $totals['arrived_quantity'];
    } else {
        $row = $simpleInventoryByProduct[$productId] ?? ['stock_quantity' => 0, 'reserved_stock' => 0, 'arrived_stock' => 0];
        $product['stock_quantity'] = (int) $row['stock_quantity'];
        $product['reserved_stock'] = (int) $row['reserved_stock'];
        $product['arrived_stock'] = (int) $row['arrived_stock'];
    }
}
unset($product);

$sellableUnits = catalog_sellable_units($pdo);

$txStmt = $pdo->query("
    SELECT it.id, it.transaction_type, it.quantity, it.reference_type, it.reference_id, it.created_at, it.variation_id,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name
    FROM inventory_transactions it
    INNER JOIN products p ON p.id = it.product_id
    LEFT JOIN product_variations pv ON pv.id = it.variation_id
    ORDER BY it.created_at DESC, it.id DESC
    LIMIT 50
");
$transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($transactions as &$txRow) {
    $txRow['variation_label'] = $txRow['variation_id'] !== null ? variation_build_label($pdo, (int) $txRow['variation_id']) : '';
}
unset($txRow);

$canManage = app_has_permission('inventory.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Inventory</h2>
        <p class="text-muted mb-0">Available and reserved stock, driven by order workflow and manual adjustments.</p>
    </div>
</div>

<?php if (isset($_GET['adjusted'])): ?>
    <div class="alert alert-success">Inventory adjusted.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">Adjust Stock</h5>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

            <div class="col-md-6">
                <label class="form-label">Product</label>
                <select class="form-select" name="unit_key" required>
                    <option value="">Select a product&hellip;</option>
                    <?php foreach ($sellableUnits as $unit): ?>
                        <option value="<?php echo app_escape($unit['key']); ?>">
                            <?php echo app_escape($unit['sku']); ?> &mdash; <?php echo app_escape($unit['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Variable products don't hold stock themselves - pick the specific variation to adjust.</div>
            </div>

            <div class="col-md-3">
                <label class="form-label">Adjustment (+/-)</label>
                <input type="number" class="form-control" name="delta" placeholder="e.g. 10 or -5" required>
            </div>

            <div class="col-md-3">
                <button class="btn btn-primary w-100" type="submit">Apply Adjustment</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Product</th>
                <th>Variation</th>
                <th>SKU</th>
                <th>Type</th>
                <th>Available</th>
                <th>Reserved</th>
                <th>Arrived</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventory as $product): ?>
                <?php $isVariable = $product['catalog_type'] === 'variable'; ?>
                <tr class="<?php echo $isVariable ? 'table-light' : ''; ?>">
                    <td>
                        <span class="fw-semibold"><?php echo app_escape($product['name']); ?></span>
                        <?php if ($isVariable): ?>
                            <span class="badge bg-info text-dark ms-1">Variable</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted">&mdash;</td>
                    <td><?php echo app_escape($product['sku']); ?></td>
                    <td><?php echo app_escape($productTypeLabels[$product['product_type']] ?? $product['product_type']); ?></td>
                    <td<?php echo $isVariable ? ' class="text-muted"' : ''; ?>><?php echo app_escape((string) $product['stock_quantity']); ?></td>
                    <td<?php echo $isVariable ? ' class="text-muted"' : ''; ?>><?php echo app_escape((string) $product['reserved_stock']); ?></td>
                    <td<?php echo $isVariable ? ' class="text-muted"' : ''; ?>><?php echo app_escape((string) $product['arrived_stock']); ?></td>
                    <td class="text-end">
                        <?php if (!$isVariable && (int) $product['arrived_stock'] > 0): ?>
                            <a class="btn btn-sm btn-outline-primary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['id']; ?>">Allocate</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($isVariable): ?>
                    <?php foreach ($product['variations'] as $variation): ?>
                        <tr>
                            <td></td>
                            <td style="padding-left: 2rem;">
                                &#8627; <?php echo $variation['variation_label'] !== '' ? app_escape($variation['variation_label']) : '<span class="text-muted">&mdash;</span>'; ?>
                            </td>
                            <td><?php echo app_escape($variation['sku']); ?></td>
                            <td></td>
                            <td><?php echo app_escape((string) $variation['stock_quantity']); ?></td>
                            <td><?php echo app_escape((string) $variation['reserved_stock']); ?></td>
                            <td><?php echo app_escape((string) $variation['arrived_stock']); ?></td>
                            <td class="text-end">
                                <?php if ((int) $variation['arrived_stock'] > 0): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['id']; ?>&variation_id=<?php echo (int) $variation['variation_id']; ?>">Allocate</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($product['variations'] === []): ?>
                        <tr>
                            <td></td>
                            <td colspan="7" class="text-muted small">No active variations.</td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($inventory === []): ?>
                <tr><td colspan="8" class="text-muted">No products yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card p-4">
    <h5 class="mb-3">Transaction History</h5>
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Product</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Reference</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $tx): ?>
                <tr>
                    <td>
                        <?php echo app_escape($tx['sku']); ?> &mdash; <?php echo app_escape($tx['product_name']); ?>
                        <?php if (!empty($tx['variation_label'])): ?>
                            <div class="text-muted small"><?php echo app_escape($tx['variation_label']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($tx['transaction_type']); ?></td>
                    <td><?php echo app_escape((string) $tx['quantity']); ?></td>
                    <td>
                        <?php if ($tx['reference_type'] === 'order' && $tx['reference_id']): ?>
                            <a href="/modules/orders/view.php?id=<?php echo (int) $tx['reference_id']; ?>">Order #<?php echo (int) $tx['reference_id']; ?></a>
                        <?php else: ?>
                            <?php echo app_escape($tx['reference_type'] ?? '-'); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($tx['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($transactions === []): ?>
                <tr><td colspan="5" class="text-muted">No inventory transactions yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
