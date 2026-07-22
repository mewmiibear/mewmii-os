<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('products.manage');

$appTitle = 'Edit Product';
$error = '';

$productTypes = ['ready_stock', 'preorder', 'early_bird'];
$catalogTypes = ['simple', 'variable'];
$statuses = ['draft', 'coming_soon', 'active', 'preorder_closed', 'expired', 'hidden'];

$productId = (int) ($_GET['id'] ?? 0);

if ($productId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

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

$form = [
    'sku' => $product['sku'],
    'name' => $product['name'],
    'description' => (string) $product['description'],
    'product_type' => $product['product_type'],
    'catalog_type' => $product['catalog_type'] ?? 'simple',
    'brand' => '',
    'category' => catalog_product_category_name($pdo, $productId) ?? '',
    'collection' => catalog_product_collection_name($pdo, $productId) ?? '',
    'tags' => catalog_product_tags_string($pdo, $productId),
    'images' => product_images_text($pdo, $productId),
    'supplier_id' => $product['supplier_id'] !== null ? (string) $product['supplier_id'] : '',
    'product_cost' => (string) $product['product_cost'],
    'selling_price' => (string) $product['selling_price'],
    'status' => $product['status'],
];

if (!empty($product['brand_id'])) {
    $brandStmt = $pdo->prepare('SELECT name FROM brands WHERE id = ?');
    $brandStmt->execute([(int) $product['brand_id']]);
    $form['brand'] = (string) ($brandStmt->fetchColumn() ?: '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['sku'] = trim((string) ($_POST['sku'] ?? ''));
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['product_type'] = (string) ($_POST['product_type'] ?? '');
    $form['catalog_type'] = (string) ($_POST['catalog_type'] ?? 'simple');
    $form['brand'] = trim((string) ($_POST['brand'] ?? ''));
    $form['category'] = trim((string) ($_POST['category'] ?? ''));
    $form['collection'] = trim((string) ($_POST['collection'] ?? ''));
    $form['tags'] = trim((string) ($_POST['tags'] ?? ''));
    $form['images'] = (string) ($_POST['images'] ?? '');
    $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));
    $form['product_cost'] = trim((string) ($_POST['product_cost'] ?? ''));
    $form['selling_price'] = trim((string) ($_POST['selling_price'] ?? ''));
    $form['status'] = (string) ($_POST['status'] ?? '');

    if ($error === '') {
        if ($form['sku'] === '' || strlen($form['sku']) > 100) {
            $error = 'SKU is required and must be 100 characters or fewer.';
        } elseif ($form['name'] === '' || strlen($form['name']) > 255) {
            $error = 'Name is required and must be 255 characters or fewer.';
        } elseif (!in_array($form['product_type'], $productTypes, true)) {
            $error = 'Invalid product type.';
        } elseif (!in_array($form['catalog_type'], $catalogTypes, true)) {
            $error = 'Invalid product structure (simple/variable).';
        } elseif ($product['catalog_type'] === 'variable' && $form['catalog_type'] === 'simple') {
            $error = 'Cannot switch a variable product back to simple while it has variations. Archive its variations first.';
        } elseif (!in_array($form['status'], $statuses, true)) {
            $error = 'Invalid status.';
        } elseif (!is_numeric($form['product_cost']) || (float) $form['product_cost'] < 0) {
            $error = 'Cost price must be a valid non-negative number.';
        } elseif (!is_numeric($form['selling_price']) || (float) $form['selling_price'] < 0) {
            $error = 'Selling price must be a valid non-negative number.';
        }
    }

    $supplierId = null;
    if ($error === '' && $form['supplier_id'] !== '') {
        $supplierId = (int) $form['supplier_id'];
        $supplierCheck = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id = ?');
        $supplierCheck->execute([$supplierId]);
        if ((int) $supplierCheck->fetchColumn() === 0) {
            $error = 'Selected supplier does not exist.';
        }
    }

    if ($error === '') {
        $skuCheck = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ? AND id != ?');
        $skuCheck->execute([$form['sku'], $productId]);
        if ((int) $skuCheck->fetchColumn() > 0) {
            $error = 'SKU already exists.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $brandId = catalog_get_or_create_brand($pdo, $form['brand']);

            $stmt = $pdo->prepare('
                UPDATE products
                SET sku = ?, name = ?, description = ?, product_type = ?, catalog_type = ?, brand_id = ?, supplier_id = ?, product_cost = ?, selling_price = ?, status = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $form['sku'],
                $form['name'],
                $form['description'] !== '' ? $form['description'] : null,
                $form['product_type'],
                $form['catalog_type'],
                $brandId,
                $supplierId,
                round((float) $form['product_cost'], 2),
                round((float) $form['selling_price'], 2),
                $form['status'],
                $productId,
            ]);

            $categoryId = catalog_get_or_create_category($pdo, $form['category']);
            $collectionId = catalog_get_or_create_collection($pdo, $form['collection']);
            catalog_sync_product_category($pdo, $productId, $categoryId);
            catalog_sync_product_collection($pdo, $productId, $collectionId);
            catalog_sync_product_tags($pdo, $productId, $form['tags']);
            product_sync_images($pdo, $productId, $form['images']);

            $pdo->commit();

            app_redirect('/modules/products/edit.php?id=' . $productId . '&updated=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to update product.';
        }
    }
}

$suppliersStmt = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200');
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Edit Product</h2>
        <p class="text-muted mb-0"><?php echo app_escape($product['sku']); ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (($product['catalog_type'] ?? 'simple') === 'variable'): ?>
            <a class="btn btn-primary btn-sm" href="/modules/products/variations.php?product_id=<?php echo $productId; ?>">Manage Variations</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
    </div>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Product updated.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">SKU</label>
                <input type="text" class="form-control" name="sku" value="<?php echo app_escape($form['sku']); ?>" maxlength="100" required>
            </div>

            <div class="col-md-8">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" value="<?php echo app_escape($form['name']); ?>" maxlength="255" required>
            </div>

            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="3"><?php echo app_escape($form['description']); ?></textarea>
            </div>

            <div class="col-md-4">
                <label class="form-label">Brand</label>
                <input type="text" class="form-control" name="brand" value="<?php echo app_escape($form['brand']); ?>" placeholder="e.g. Sanrio">
            </div>

            <div class="col-md-4">
                <label class="form-label">Category</label>
                <input type="text" class="form-control" name="category" value="<?php echo app_escape($form['category']); ?>" placeholder="e.g. Plush">
            </div>

            <div class="col-md-4">
                <label class="form-label">Collection / Series</label>
                <input type="text" class="form-control" name="collection" value="<?php echo app_escape($form['collection']); ?>" placeholder="e.g. Spring Picnic Collection">
            </div>

            <div class="col-12">
                <label class="form-label">Tags</label>
                <input type="text" class="form-control" name="tags" value="<?php echo app_escape($form['tags']); ?>" placeholder="Comma-separated, e.g. Cute, Limited Edition, Gift">
            </div>

            <div class="col-12">
                <label class="form-label">Image URLs</label>
                <textarea class="form-control" name="images" rows="3" placeholder="One image URL per line"><?php echo app_escape($form['images']); ?></textarea>
            </div>

            <div class="col-md-3">
                <label class="form-label">Product Type</label>
                <select class="form-select" name="product_type" required>
                    <?php foreach ($productTypes as $type): ?>
                        <option value="<?php echo app_escape($type); ?>" <?php echo $form['product_type'] === $type ? 'selected' : ''; ?>>
                            <?php echo app_escape($type); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Structure</label>
                <select class="form-select" name="catalog_type" required>
                    <?php foreach ($catalogTypes as $type): ?>
                        <option value="<?php echo app_escape($type); ?>" <?php echo $form['catalog_type'] === $type ? 'selected' : ''; ?>>
                            <?php echo $type === 'simple' ? 'Simple product' : 'Variable product'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (($product['catalog_type'] ?? 'simple') === 'variable'): ?>
                    <div class="form-text">Use "Manage Variations" above to add/edit variations.</div>
                <?php endif; ?>
            </div>

            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status" required>
                    <?php foreach ($statuses as $statusOption): ?>
                        <option value="<?php echo app_escape($statusOption); ?>" <?php echo $form['status'] === $statusOption ? 'selected' : ''; ?>>
                            <?php echo app_escape($statusOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Supplier</label>
                <select class="form-select" name="supplier_id">
                    <option value="">None</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Cost Price</label>
                <input type="number" step="0.01" min="0" class="form-control" name="product_cost" value="<?php echo app_escape($form['product_cost']); ?>" required>
            </div>

            <div class="col-md-3">
                <label class="form-label">Selling Price</label>
                <input type="number" step="0.01" min="0" class="form-control" name="selling_price" value="<?php echo app_escape($form['selling_price']); ?>" required>
            </div>
        </div>

        <button class="btn btn-primary mt-4" type="submit">Save Changes</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
