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

$stmt = $pdo->query("
    (SELECT p.id AS product_id, NULL AS variation_id, p.sku, p.name AS product_name,
            COALESCE(i.available_quantity, 0) AS stock_quantity,
            COALESCE(i.reserved_quantity, 0) AS reserved_stock,
            p.id AS sort_key
     FROM products p
     LEFT JOIN mewmii_inventory i ON i.product_id = p.id AND i.variation_id IS NULL
     WHERE p.catalog_type = 'simple')
    UNION ALL
    (SELECT p.id AS product_id, pv.id AS variation_id, pv.sku, p.name AS product_name,
            COALESCE(iv.available_quantity, 0) AS stock_quantity,
            COALESCE(iv.reserved_quantity, 0) AS reserved_stock,
            pv.id AS sort_key
     FROM product_variations pv
     INNER JOIN products p ON p.id = pv.product_id
     LEFT JOIN mewmii_inventory iv ON iv.variation_id = pv.id
     WHERE pv.status <> 'archived')
    ORDER BY sort_key DESC
    LIMIT 20
");
$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($inventory as &$invRow) {
    $invRow['variation_label'] = $invRow['variation_id'] !== null ? variation_build_label($pdo, (int) $invRow['variation_id']) : '';
}
unset($invRow);

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
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
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
                <th>SKU</th>
                <th>Name</th>
                <th>Available</th>
                <th>Reserved</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventory as $item): ?>
                <tr>
                    <td><?php echo app_escape($item['sku']); ?></td>
                    <td>
                        <?php echo app_escape($item['product_name']); ?>
                        <?php if (!empty($item['variation_label'])): ?>
                            <div class="text-muted small"><?php echo app_escape($item['variation_label']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape((string) $item['stock_quantity']); ?></td>
                    <td><?php echo app_escape((string) $item['reserved_stock']); ?></td>
                </tr>
            <?php endforeach; ?>
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
