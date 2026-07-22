<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/product_images.php';
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
    'brand_id' => $product['brand_id'] !== null ? (string) $product['brand_id'] : '',
    'category' => catalog_product_category_name($pdo, $productId) ?? '',
    'collection' => catalog_product_collection_name($pdo, $productId) ?? '',
    'tag_ids' => catalog_get_product_tag_ids($pdo, $productId),
    'supplier_id' => $product['supplier_id'] !== null ? (string) $product['supplier_id'] : '',
    'product_cost' => (string) $product['product_cost'],
    'selling_price' => (string) $product['selling_price'],
    'status' => $product['status'],
];

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
    $form['brand_id'] = trim((string) ($_POST['brand_id'] ?? ''));
    $form['category'] = trim((string) ($_POST['category'] ?? ''));
    $form['collection'] = trim((string) ($_POST['collection'] ?? ''));
    $form['tag_ids'] = array_map('intval', $_POST['tag_ids'] ?? []);
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

    $brandId = null;
    if ($error === '' && $form['brand_id'] !== '') {
        $brandId = (int) $form['brand_id'];
        $brandCheck = $pdo->prepare('SELECT COUNT(*) FROM brands WHERE id = ?');
        $brandCheck->execute([$brandId]);
        if ((int) $brandCheck->fetchColumn() === 0) {
            $error = 'Selected brand does not exist.';
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
            catalog_sync_product_tag_ids($pdo, $productId, $form['tag_ids']);

            if (!empty($_POST['remove_main_image'])) {
                product_image_remove_main($pdo, $productId);
            }

            if (!empty($_FILES['main_image']['name'])) {
                product_image_set_main($pdo, $productId, $_FILES['main_image']);
            }

            $galleryFiles = image_upload_normalize_multi($_FILES['gallery_images'] ?? []);
            if ($galleryFiles !== []) {
                product_image_add_gallery($pdo, $productId, $galleryFiles);
            }

            $gallerySortOrders = $_POST['gallery_sort_order'] ?? [];
            $galleryDeleteIds = $_POST['gallery_delete'] ?? [];
            if ($gallerySortOrders !== [] || $galleryDeleteIds !== []) {
                product_image_update_gallery($pdo, $productId, $gallerySortOrders, $galleryDeleteIds);
            }

            $pdo->commit();

            app_redirect('/modules/products/edit.php?id=' . $productId . '&updated=1');
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to update product.';
        }
    }
}

$suppliersStmt = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200');
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$brands = catalog_list_brands($pdo);
$tags = catalog_list_tags($pdo);
$mainImage = product_image_get_main($pdo, $productId);
$galleryImages = product_image_list_gallery($pdo, $productId);

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
    <form method="post" enctype="multipart/form-data">
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
                <select class="form-select" name="brand_id">
                    <option value="">None</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?php echo (int) $brand['id']; ?>" <?php echo $form['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($brand['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($brands === []): ?>
                    <div class="form-text">No brands yet - <a href="/modules/brands/index.php">create one</a> first.</div>
                <?php endif; ?>
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
                <div>
                    <?php if ($tags === []): ?>
                        <span class="text-muted small">No tags yet - <a href="/modules/tags/index.php">create some</a> first.</span>
                    <?php endif; ?>
                    <?php foreach ($tags as $tag): ?>
                        <label class="me-3">
                            <input type="checkbox" name="tag_ids[]" value="<?php echo (int) $tag['id']; ?>" <?php echo in_array((int) $tag['id'], $form['tag_ids'], true) ? 'checked' : ''; ?>>
                            <?php echo app_escape($tag['name']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Main Image</label>
                <?php if ($mainImage !== null): ?>
                    <div class="mb-2">
                        <img src="/<?php echo app_escape($mainImage['image_path']); ?>" alt="Main image" style="max-width: 120px; max-height: 120px;" class="border rounded">
                    </div>
                    <label class="d-block mb-2">
                        <input type="checkbox" name="remove_main_image" value="1"> Remove current main image
                    </label>
                <?php endif; ?>
                <input type="file" class="form-control" name="main_image" accept="image/*">
                <div class="form-text">Uploading a new file replaces the current main image. Images are automatically resized, compressed, and converted to WebP.</div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Add Gallery Images</label>
                <input type="file" class="form-control" name="gallery_images[]" accept="image/*" multiple>

                <?php if ($galleryImages !== []): ?>
                    <div class="mt-3">
                        <div class="form-label mb-2">Current Gallery</div>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($galleryImages as $image): ?>
                                <div class="border rounded p-2 text-center" style="width: 110px;">
                                    <img src="/<?php echo app_escape($image['image_path']); ?>" alt="Gallery image" style="max-width: 90px; max-height: 90px;" class="mb-1">
                                    <input type="number" class="form-control form-control-sm mb-1" name="gallery_sort_order[<?php echo (int) $image['id']; ?>]" value="<?php echo (int) $image['sort_order']; ?>" title="Sort order">
                                    <label class="small">
                                        <input type="checkbox" name="gallery_delete[]" value="<?php echo (int) $image['id']; ?>"> Delete
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
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
