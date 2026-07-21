<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_login();
app_require_permission('products.view');

$appTitle = 'Products';
require_once __DIR__ . '/../../includes/header.php';

$stmt = app_db()->query('SELECT id, sku, name, product_type, status, selling_price FROM products ORDER BY id DESC LIMIT 20');
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Products</h2>
        <p class="text-muted mb-0">Core product catalog for preorder, ready stock, and early bird items.</p>
    </div>
    <a class="btn btn-primary" href="#">Add Product</a>
</div>
<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Name</th>
                <th>Type</th>
                <th>Status</th>
                <th>Price</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td><?php echo app_escape($product['sku']); ?></td>
                    <td><?php echo app_escape($product['name']); ?></td>
                    <td><?php echo app_escape($product['product_type']); ?></td>
                    <td><?php echo app_escape($product['status']); ?></td>
                    <td><?php echo app_escape((string) $product['selling_price']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>