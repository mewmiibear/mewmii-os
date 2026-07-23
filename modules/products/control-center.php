<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
// order_status_badge() lives in orders.php; supplier_order_status_badge() lives in
// supplier_orders.php, which orders.php already require_once's internally.
require_once __DIR__ . '/../../includes/orders.php';
app_require_permission('products.manage');

$appTitle = 'Product Control Center';
$pdo = app_db();

$productId = (int) ($_GET['id'] ?? 0);

if ($productId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
$productStmt->execute([$productId]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Inventory Summary - reuses the existing rollup function, no new calculation.
$currentStock = product_effective_stock($pdo, $productId);

// Supplier Summary
$controlCenterSupplier = null;
if ($product['supplier_id'] !== null) {
    $supplierLookupStmt = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ? LIMIT 1');
    $supplierLookupStmt->execute([$product['supplier_id']]);
    $controlCenterSupplier = $supplierLookupStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$lastSupplierOrderStmt = $pdo->prepare('
    SELECT so.id, so.purchase_number, so.status, so.order_date
    FROM supplier_order_items soi
    INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
    WHERE soi.product_id = ?
    ORDER BY so.order_date DESC, so.id DESC
    LIMIT 1
');
$lastSupplierOrderStmt->execute([$productId]);
$lastSupplierOrder = $lastSupplierOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// Sales History (capped)
$recentCustomerOrdersStmt = $pdo->prepare('
    SELECT o.id, o.order_number, o.order_status, o.order_date, o.is_historical, oi.quantity
    FROM mewmii_order_items oi
    INNER JOIN mewmii_orders o ON o.id = oi.order_id
    WHERE oi.product_id = ?
    ORDER BY o.order_date DESC, o.id DESC
    LIMIT 10
');
$recentCustomerOrdersStmt->execute([$productId]);
$recentCustomerOrders = $recentCustomerOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

// Purchase History (capped)
$recentSupplierOrdersStmt = $pdo->prepare('
    SELECT so.id, so.purchase_number, so.status, so.order_date, so.is_historical,
           s.name AS supplier_name, soi.total_quantity
    FROM supplier_order_items soi
    INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
    INNER JOIN suppliers s ON s.id = so.supplier_id
    WHERE soi.product_id = ?
    ORDER BY so.order_date DESC, so.id DESC
    LIMIT 10
');
$recentSupplierOrdersStmt->execute([$productId]);
$recentSupplierOrders = $recentSupplierOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

// Quick Navigation - each link is gated on the PERMISSION OF ITS DESTINATION, not this page's
// own products.manage gate - a link only appears if the admin could actually open the page it
// points to.
$controlCenterPermissions = [
    'inventory' => app_has_permission('inventory.view'),
    'orders' => app_has_permission('orders.view'),
    'supplierOrders' => app_has_permission('supplier-orders.view'),
    'purchasePlanning' => app_has_permission('supplier-orders.manage'),
];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Product Control Center</h2>
        <p class="text-muted mb-0"><?php echo app_escape($product['sku']); ?> &middot; <?php echo app_escape($product['name']); ?></p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-primary btn-sm" href="/modules/products/edit.php?id=<?php echo (int) $productId; ?>">Edit Product</a>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
    </div>
</div>

<div class="card p-4 mb-4">
    <div class="row g-4">
        <div class="col-md-6 col-lg-4">
            <h6 class="text-muted text-uppercase small mb-2">Inventory</h6>
            <div class="d-flex justify-content-between"><span>Available</span><strong><?php echo (int) $currentStock['available_quantity']; ?></strong></div>
            <div class="d-flex justify-content-between"><span>Reserved</span><strong><?php echo (int) $currentStock['reserved_quantity']; ?></strong></div>
            <div class="d-flex justify-content-between"><span>Incoming</span><strong><?php echo (int) $currentStock['incoming_quantity']; ?></strong></div>
        </div>

        <div class="col-md-6 col-lg-4">
            <h6 class="text-muted text-uppercase small mb-2">Supplier</h6>
            <?php if ($controlCenterSupplier !== null): ?>
                <div>Current supplier: <strong><?php echo app_escape($controlCenterSupplier['name']); ?></strong></div>
            <?php else: ?>
                <div class="text-muted">No supplier assigned</div>
            <?php endif; ?>
            <?php if (!empty($product['supplier_sku'])): ?>
                <div>Supplier SKU: <strong><?php echo app_escape($product['supplier_sku']); ?></strong></div>
            <?php endif; ?>
            <?php if ($lastSupplierOrder !== null): ?>
                <div class="mt-1 small">
                    Last order:
                    <?php if ($controlCenterPermissions['supplierOrders']): ?>
                        <a href="/modules/supplier-orders/view.php?id=<?php echo (int) $lastSupplierOrder['id']; ?>"><?php echo app_escape($lastSupplierOrder['purchase_number']); ?></a>
                    <?php else: ?>
                        <?php echo app_escape($lastSupplierOrder['purchase_number']); ?>
                    <?php endif; ?>
                    &middot; <?php echo app_escape($lastSupplierOrder['order_date'] ?? '-'); ?>
                    &middot; <?php echo supplier_order_status_badge($lastSupplierOrder['status']); ?>
                </div>
            <?php else: ?>
                <div class="text-muted small mt-1">No supplier orders yet.</div>
            <?php endif; ?>
        </div>

        <div class="col-md-6 col-lg-4">
            <h6 class="text-muted text-uppercase small mb-2">Quick Navigation</h6>
            <div class="d-grid gap-1">
                <?php if ($controlCenterPermissions['inventory']): ?>
                    <a class="btn btn-sm btn-outline-secondary text-start" href="/modules/inventory/index.php?q=<?php echo urlencode($product['sku']); ?>">View Inventory</a>
                <?php endif; ?>
                <?php if ($controlCenterPermissions['orders']): ?>
                    <a class="btn btn-sm btn-outline-secondary text-start" href="/modules/orders/index.php?product_id=<?php echo (int) $productId; ?>">View Orders containing this product</a>
                <?php endif; ?>
                <?php if ($controlCenterPermissions['supplierOrders']): ?>
                    <a class="btn btn-sm btn-outline-secondary text-start" href="/modules/supplier-orders/index.php?product_id=<?php echo (int) $productId; ?>">View Supplier Orders for this product</a>
                <?php endif; ?>
                <?php if ($controlCenterPermissions['purchasePlanning']): ?>
                    <a class="btn btn-sm btn-outline-secondary text-start" href="/modules/purchase-planning/generate.php?highlight_product_id=<?php echo (int) $productId; ?>">View Purchase Planning for this product</a>
                <?php endif; ?>
                <?php if (!in_array(true, $controlCenterPermissions, true)): ?>
                    <p class="text-muted small mb-0">No linked modules available for your permissions.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h6 class="text-muted text-uppercase small mb-1">Sales History</h6>
            <p class="text-muted small mb-2">Last <?php echo count($recentCustomerOrders); ?> customer order(s) containing this product.</p>
            <?php if ($recentCustomerOrders === []): ?>
                <p class="text-muted small mb-0">No customer orders yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Order</th><th>Date</th><th>Qty</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentCustomerOrders as $historyOrder): ?>
                                <tr>
                                    <td>
                                        <?php if ($controlCenterPermissions['orders']): ?>
                                            <a href="/modules/orders/view.php?id=<?php echo (int) $historyOrder['id']; ?>"><?php echo app_escape($historyOrder['order_number']); ?></a>
                                        <?php else: ?>
                                            <?php echo app_escape($historyOrder['order_number']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($historyOrder['is_historical'])): ?><span class="badge bg-secondary">Historical</span><?php endif; ?>
                                    </td>
                                    <td><?php echo app_escape($historyOrder['order_date'] ?? '-'); ?></td>
                                    <td><?php echo (int) $historyOrder['quantity']; ?></td>
                                    <td><?php echo order_status_badge($historyOrder['order_status']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h6 class="text-muted text-uppercase small mb-1">Purchase History</h6>
            <p class="text-muted small mb-2">Last <?php echo count($recentSupplierOrders); ?> supplier order(s) containing this product.</p>
            <?php if ($recentSupplierOrders === []): ?>
                <p class="text-muted small mb-0">No supplier orders yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Purchase #</th><th>Supplier</th><th>Date</th><th>Qty</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentSupplierOrders as $historyOrder): ?>
                                <tr>
                                    <td>
                                        <?php if ($controlCenterPermissions['supplierOrders']): ?>
                                            <a href="/modules/supplier-orders/view.php?id=<?php echo (int) $historyOrder['id']; ?>"><?php echo app_escape($historyOrder['purchase_number']); ?></a>
                                        <?php else: ?>
                                            <?php echo app_escape($historyOrder['purchase_number']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($historyOrder['is_historical'])): ?><span class="badge bg-secondary">Historical</span><?php endif; ?>
                                    </td>
                                    <td><?php echo app_escape($historyOrder['supplier_name']); ?></td>
                                    <td><?php echo app_escape($historyOrder['order_date'] ?? '-'); ?></td>
                                    <td><?php echo (int) $historyOrder['total_quantity']; ?></td>
                                    <td><?php echo supplier_order_status_badge($historyOrder['status']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
