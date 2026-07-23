<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('supplier-orders.view');

$appTitle = 'Supplier Orders';
$pdo = app_db();

// Optional ?product_id= filter - same read-only, additive pattern as orders/index.php's
// ?product_id= filter. Lets the product Control Center link to "supplier orders containing
// this product" without touching supplier-order logic. DISTINCT guards against a product
// appearing via more than one variation on the same supplier order.
$filterProductId = isset($_GET['product_id']) && ctype_digit((string) $_GET['product_id']) && (int) $_GET['product_id'] > 0
    ? (int) $_GET['product_id']
    : null;
$filterProductLabel = null;

$sql = '
    SELECT DISTINCT so.id, so.purchase_number, so.status, so.payment_status, so.is_historical, so.estimated_cost, so.actual_cost, so.shipping_fee, so.order_date, s.name AS supplier_name
    FROM supplier_orders so
    INNER JOIN suppliers s ON s.id = so.supplier_id
';
$params = [];
if ($filterProductId !== null) {
    $sql .= ' INNER JOIN supplier_order_items soi ON soi.supplier_order_id = so.id AND soi.product_id = ?';
    $params[] = $filterProductId;

    $productLookupStmt = $pdo->prepare('SELECT name, sku FROM products WHERE id = ?');
    $productLookupStmt->execute([$filterProductId]);
    $productLookupRow = $productLookupStmt->fetch(PDO::FETCH_ASSOC);
    $filterProductLabel = $productLookupRow !== false ? ($productLookupRow['sku'] . ' - ' . $productLookupRow['name']) : null;
}
$sql .= ' ORDER BY so.id DESC LIMIT 20';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$supplierOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = app_has_permission('supplier-orders.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Supplier Orders</h2>
        <p class="text-muted mb-0">
            Purchase orders sent to suppliers and inventory receiving.
            <?php if ($filterProductId !== null): ?>
                &middot; Containing product: <strong><?php echo app_escape($filterProductLabel ?? ('#' . $filterProductId)); ?></strong>
                <a href="/modules/supplier-orders/index.php" class="ms-1">(clear)</a>
            <?php endif; ?>
        </p>
    </div>
    <?php if ($canManage): ?>
        <div class="d-flex gap-2">
            <a class="btn btn-primary" href="/modules/supplier-orders/create.php">New Supplier Order</a>
            <a class="btn btn-outline-primary" href="/modules/purchase-planning/generate.php">Purchase Planning / Products Need Ordering</a>
            <a class="btn btn-outline-secondary" href="/modules/supplier-orders/import.php">Import Historical Order</a>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Supplier order created.</div>
<?php endif; ?>
<?php if (isset($_GET['generated'])): ?>
    <div class="alert alert-success"><?php echo (int) $_GET['generated']; ?> supplier order(s) generated from Purchase Planning.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Supplier order deleted.</div>
<?php endif; ?>

<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Purchase #</th>
                <th>Supplier</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Total Purchase Amount</th>
                <th>Order Date</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($supplierOrders as $order): ?>
                <tr>
                    <td>
                        <?php echo app_escape($order['purchase_number']); ?>
                        <?php if (!empty($order['is_historical'])): ?>
                            <span class="badge bg-secondary">Historical</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($order['supplier_name']); ?></td>
                    <td><?php echo supplier_order_status_badge($order['status']); ?></td>
                    <td><?php echo supplier_order_payment_status_badge((string) $order['payment_status']); ?></td>
                    <td>RM <?php echo app_escape(number_format((float) $order['estimated_cost'] + (float) $order['shipping_fee'], 2)); ?></td>
                    <td><?php echo app_escape($order['order_date'] ?? '-'); ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-primary" href="/modules/supplier-orders/view.php?id=<?php echo (int) $order['id']; ?>">View</a>
                            <?php if ($canManage): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/supplier-orders/edit.php?id=<?php echo (int) $order['id']; ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($canManage): ?>
                                <form method="post" action="/modules/supplier-orders/delete.php" class="d-inline" onsubmit="return confirm('Delete this supplier order? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                    <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($supplierOrders === []): ?>
                <tr><td colspan="7" class="text-muted">No supplier orders yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
