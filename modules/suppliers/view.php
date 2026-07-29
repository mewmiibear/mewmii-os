<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('suppliers.view');

$appTitle = 'Supplier Detail';

$supplierId = (int) ($_GET['id'] ?? 0);

if ($supplierId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$supplierStmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ? LIMIT 1');
$supplierStmt->execute([$supplierId]);
$supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Per-section destination permission - mirrors the Dashboard widget fix: supplier order and
// product data/links belong to those modules' own permission domain (supplier-orders.view,
// products.view/manage), not this page's suppliers.view, so each section below is only
// queried and rendered for a user who actually holds the permission its data/links require.
$canViewSupplierOrders = app_has_permission('supplier-orders.view');
$canViewProducts = app_has_permission('products.view');
$canManageProducts = app_has_permission('products.manage');

// One aggregate query for both headline numbers - never per-row. total_value deliberately
// excludes cancelled orders (they never resulted in an actual purchase), mirroring the same
// "Total Purchase Amount = estimated_cost + shipping_fee" figure already shown per order on
// modules/supplier-orders/view.php. total_orders counts every order ever placed for this
// supplier regardless of status. Both figures come from supplier_orders, so they're gated the
// same as the Open/Recent Orders sections below.
$summary = ['total_orders' => 0, 'total_value' => 0.0];
$openOrders = [];
$recentOrders = [];
if ($canViewSupplierOrders) {
    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN estimated_cost + shipping_fee ELSE 0 END), 0) AS total_value
        FROM supplier_orders
        WHERE supplier_id = ?
    ");
    $summaryStmt->execute([$supplierId]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // Open orders: the same in-flight status set (not yet completed or cancelled) already
    // used for the Dashboard's Supplier Orders widget - one query, no per-row lookups.
    $openOrdersStmt = $pdo->prepare("
        SELECT id, purchase_number, status, payment_status, estimated_cost, shipping_fee, order_date, expected_delivery_date
        FROM supplier_orders
        WHERE supplier_id = ? AND status NOT IN ('completed', 'cancelled')
        ORDER BY order_date DESC, id DESC
    ");
    $openOrdersStmt->execute([$supplierId]);
    $openOrders = $openOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent orders: latest activity regardless of status, capped at 20 - same list-size
    // convention already used on modules/supplier-orders/index.php.
    $recentOrdersStmt = $pdo->prepare('
        SELECT id, purchase_number, status, payment_status, estimated_cost, shipping_fee, order_date, created_at
        FROM supplier_orders
        WHERE supplier_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 20
    ');
    $recentOrdersStmt->execute([$supplierId]);
    $recentOrders = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Supplied products: the direct products.supplier_id relationship - one batched query, no
// per-product follow-up queries. Gated on products.view, matching modules/products/index.php.
$suppliedProducts = [];
if ($canViewProducts) {
    $productsStmt = $pdo->prepare("
        SELECT id, sku, name, catalog_type, product_type, status, moq, product_cost
        FROM products
        WHERE supplier_id = ?
        ORDER BY name ASC
    ");
    $productsStmt->execute([$supplierId]);
    $suppliedProducts = $productsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$productTypeLabels = ['ready_stock' => 'Ready Stock', 'preorder' => 'Preorder', 'early_bird' => 'Early Bird'];

$canManage = app_has_permission('suppliers.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1"><?php echo app_escape($supplier['name']); ?></h2>
        <p class="text-muted mb-0"><?php echo app_escape($supplier['country'] ?? '-'); ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canManage): ?>
            <a class="btn btn-outline-primary btn-sm" href="/modules/suppliers/edit.php?id=<?php echo (int) $supplierId; ?>">Edit</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/suppliers/index.php">Back to Suppliers</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-<?php echo $canViewSupplierOrders ? '7' : '12'; ?>">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Supplier Information</h5>
            <table class="table table-borderless mb-0">
                <tr><th>Contact</th><td><?php echo app_escape($supplier['contact'] ?? '-'); ?></td></tr>
                <tr><th>Contact Person</th><td><?php echo app_escape($supplier['contact_person'] ?? '-'); ?></td></tr>
                <tr><th>Phone</th><td><?php echo app_escape($supplier['phone'] ?? '-'); ?></td></tr>
                <tr><th>Email</th><td><?php echo app_escape($supplier['email'] ?? '-'); ?></td></tr>
                <tr><th>Country</th><td><?php echo app_escape($supplier['country'] ?? '-'); ?></td></tr>
                <tr><th>Currency</th><td><?php echo app_escape($supplier['currency'] ?? '-'); ?></td></tr>
                <tr><th>Payment Terms</th><td><?php echo app_escape($supplier['payment_terms'] ?? '-'); ?></td></tr>
                <?php if (!empty($supplier['notes'])): ?>
                    <tr><th>Notes</th><td><?php echo nl2br(app_escape($supplier['notes'])); ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <?php if ($canViewSupplierOrders): ?>
        <div class="col-lg-5">
            <div class="row g-4 h-100">
                <div class="col-sm-6 col-lg-12">
                    <div class="card p-4">
                        <h6 class="text-muted mb-2">Total Supplier Orders</h6>
                        <h2 class="fw-bold mb-0"><?php echo (int) $summary['total_orders']; ?></h2>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-12">
                    <div class="card p-4">
                        <h6 class="text-muted mb-2">Total Purchase Value</h6>
                        <h2 class="fw-bold mb-0">RM <?php echo app_escape(number_format((float) $summary['total_value'], 2)); ?></h2>
                        <p class="text-muted small mb-0">Excludes cancelled orders</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($canViewSupplierOrders): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">Open Supplier Orders</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Purchase #</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Total Purchase Amount</th>
                    <th>Order Date</th>
                    <th>Expected Delivery</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($openOrders as $order): ?>
                    <tr>
                        <td><?php echo app_escape($order['purchase_number']); ?></td>
                        <td><?php echo supplier_order_status_badge($order['status']); ?></td>
                        <td><?php echo supplier_order_payment_status_badge((string) $order['payment_status']); ?></td>
                        <td>RM <?php echo app_escape(number_format((float) $order['estimated_cost'] + (float) $order['shipping_fee'], 2)); ?></td>
                        <td><?php echo app_escape($order['order_date'] ?? '-'); ?></td>
                        <td><?php echo app_escape($order['expected_delivery_date'] ?? '-'); ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/modules/supplier-orders/view.php?id=<?php echo (int) $order['id']; ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($openOrders === []): ?>
                    <tr><td colspan="7" class="text-muted">No open supplier orders.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($canViewProducts): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">Supplied Products</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Type</th>
                    <th>MOQ</th>
                    <th>Product Cost</th>
                    <?php if ($canManageProducts): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($suppliedProducts as $product): ?>
                    <tr>
                        <td><?php echo app_escape($product['sku']); ?></td>
                        <td>
                            <?php echo app_escape($product['name']); ?>
                            <?php if ($product['catalog_type'] === 'variable'): ?>
                                <span class="badge bg-info text-dark ms-1">Variable</span>
                            <?php endif; ?>
                            <?php if ($product['status'] === 'archived'): ?>
                                <span class="badge bg-secondary ms-1">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo app_escape($productTypeLabels[$product['product_type']] ?? $product['product_type']); ?></td>
                        <td><?php echo $product['moq'] !== null ? (int) $product['moq'] : '-'; ?></td>
                        <td>RM <?php echo app_escape(number_format((float) $product['product_cost'], 2)); ?></td>
                        <?php if ($canManageProducts): ?>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/products/edit.php?id=<?php echo (int) $product['id']; ?>">View</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($suppliedProducts === []): ?>
                    <tr><td colspan="<?php echo $canManageProducts ? 6 : 5; ?>" class="text-muted">No products assigned to this supplier.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($canViewSupplierOrders): ?>
<div class="card p-4">
    <h5 class="mb-3">Recent Supplier Orders</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Purchase #</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Total Purchase Amount</th>
                    <th>Order Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentOrders as $order): ?>
                    <tr>
                        <td><?php echo app_escape($order['purchase_number']); ?></td>
                        <td><?php echo supplier_order_status_badge($order['status']); ?></td>
                        <td><?php echo supplier_order_payment_status_badge((string) $order['payment_status']); ?></td>
                        <td>RM <?php echo app_escape(number_format((float) $order['estimated_cost'] + (float) $order['shipping_fee'], 2)); ?></td>
                        <td><?php echo app_escape($order['order_date'] ?? '-'); ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/modules/supplier-orders/view.php?id=<?php echo (int) $order['id']; ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($recentOrders === []): ?>
                    <tr><td colspan="6" class="text-muted">No supplier orders yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
