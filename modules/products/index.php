<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_login();
app_require_permission('products.view');

$appTitle = 'Products';
require_once __DIR__ . '/../../includes/header.php';

$stmt = app_db()->query('SELECT id, sku, name, product_type, catalog_type, status, selling_price FROM products ORDER BY id DESC LIMIT 20');
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canManage = app_has_permission('products.manage');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Products</h2>
        <p class="text-muted mb-0">Core product catalog for preorder, ready stock, and early bird items.</p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-primary" href="/modules/products/create.php">Add Product</a>
    <?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <?php if (isset($_GET['sync'])): ?>
            <div class="alert alert-info mb-0">
                <?php
                $successCount = isset($_GET['success']) ? (int) $_GET['success'] : 0;
                $failedCount = isset($_GET['failed']) ? (int) $_GET['failed'] : 0;

                if ($failedCount > 0) {
                    echo 'WooCommerce sync completed. ' . $successCount . ' succeeded and ' . $failedCount . ' failed.';
                } else {
                    echo 'WooCommerce sync completed for ' . $successCount . ' product(s).';
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($canManage): ?>
        <form method="post" action="/modules/products/sync.php" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <button type="submit" class="btn btn-outline-secondary">Sync to WooCommerce</button>
        </form>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Product created.</div>
<?php endif; ?>
<?php if (isset($_GET['duplicate_error'])): ?>
    <div class="alert alert-danger">Failed to duplicate product.</div>
<?php endif; ?>

<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Name</th>
                <th>Type</th>
                <th>Structure</th>
                <th>Status</th>
                <th>Price</th>
                <?php if ($canManage): ?><th></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
                <?php $isVariable = ($product['catalog_type'] ?? 'simple') === 'variable'; ?>
                <tr>
                    <td><?php echo app_escape($product['sku']); ?></td>
                    <td><?php echo app_escape($product['name']); ?></td>
                    <td><?php echo app_escape($product['product_type']); ?></td>
                    <td><span class="badge bg-<?php echo $isVariable ? 'info text-dark' : 'light text-dark'; ?>"><?php echo $isVariable ? 'Variable' : 'Simple'; ?></span></td>
                    <td><?php echo app_escape($product['status']); ?></td>
                    <td>RM <?php echo app_escape(number_format((float) $product['selling_price'], 2)); ?><?php if ($isVariable): ?> <span class="text-muted small">(default)</span><?php endif; ?></td>
                    <?php if ($canManage): ?>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/modules/products/edit.php?id=<?php echo (int) $product['id']; ?>">Edit</a>
                            <form method="post" action="/modules/products/duplicate.php" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Duplicate</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr>
                    <td colspan="<?php echo $canManage ? 6 : 5; ?>" class="text-muted">No products yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>