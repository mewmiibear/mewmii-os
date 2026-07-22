<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('products.view');

$appTitle = 'Brands';
$error = '';
$pdo = app_db();
$canManage = app_has_permission('products.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !$canManage) {
        http_response_code(403);
        $error = 'You do not have permission to manage brands.';
    }

    if ($error === '') {
        $name = trim((string) ($_POST['brand_name'] ?? ''));

        if ($name === '') {
            $error = 'Enter a brand name.';
        } else {
            $pdo->beginTransaction();

            try {
                catalog_get_or_create_brand($pdo, $name);
                $pdo->commit();

                app_redirect('/modules/brands/index.php?saved=1');
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to save brand.';
            }
        }
    }
}

$brands = catalog_list_brands($pdo);
foreach ($brands as &$brand) {
    $brand['products'] = catalog_brand_product_names($pdo, (int) $brand['id']);
}
unset($brand);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Brands</h2>
        <p class="text-muted mb-0">Defined once here, picked from a dropdown on the product form.</p>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Saved.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">New Brand</h5>
        <form method="post" class="d-flex gap-2">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="text" class="form-control" name="brand_name" placeholder="e.g. Sanrio" required>
            <button class="btn btn-primary" type="submit">Add</button>
        </form>
    </div>
<?php endif; ?>

<div class="card p-4">
    <h5 class="mb-3">All Brands</h5>
    <?php if ($brands === []): ?>
        <p class="text-muted mb-0">No brands yet. Create one above (e.g. Sanrio).</p>
    <?php else: ?>
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Brand</th>
                    <th>Products</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($brands as $brand): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo app_escape($brand['name']); ?></td>
                        <td>
                            <?php if ($brand['products'] === []): ?>
                                <span class="text-muted small">No products yet.</span>
                            <?php else: ?>
                                <?php echo app_escape(implode(', ', $brand['products'])); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
