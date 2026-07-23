<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('products.view');

$appTitle = 'Product';
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

$canManage = app_has_permission('products.manage');

// Brand - same "look up the name by id" shape already used for Supplier below and in
// modules/products/control-center.php; no dedicated catalog_product_brand_name() exists.
$brandName = null;
if ($product['brand_id'] !== null) {
    $brandStmt = $pdo->prepare('SELECT name FROM brands WHERE id = ? LIMIT 1');
    $brandStmt->execute([$product['brand_id']]);
    $brandName = $brandStmt->fetchColumn() ?: null;
}

// Category / Collection - reuse the existing single-name lookups (this data model has always
// been single-category/single-collection per product, despite the pivot tables underneath -
// see catalog_sync_product_category()/catalog_sync_product_collection()).
$categoryName = catalog_product_category_name($pdo, $productId);
$collectionName = catalog_product_collection_name($pdo, $productId);

// Tags - reuse the same two functions the edit form already uses for its checkbox list,
// just filtered down to this product's selected ids instead of rendering every tag.
$allTags = catalog_list_tags($pdo);
$productTagIds = catalog_get_product_tag_ids($pdo, $productId);
$productTags = array_values(array_filter(
    $allTags,
    static fn (array $tag): bool => in_array((int) $tag['id'], $productTagIds, true)
));

// Supplier - identical shape to modules/products/control-center.php's supplier lookup.
$supplier = null;
if ($product['supplier_id'] !== null) {
    $supplierStmt = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ? LIMIT 1');
    $supplierStmt->execute([$product['supplier_id']]);
    $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Inventory Summary - reuses the same rollup function as the edit page and Control Center,
// no new calculation.
$currentStock = product_effective_stock($pdo, $productId);

// Variations - reuses the same listing function the edit form uses for its variation table.
$variations = $product['catalog_type'] === 'variable' ? variation_list_for_product($pdo, $productId) : [];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">
            <?php echo app_escape($product['name']); ?>
            <?php echo catalog_lifecycle_badge($product); ?>
        </h2>
        <p class="text-muted mb-0">
            <?php echo app_escape($product['sku']); ?>
            &middot; <?php echo app_escape(catalog_status_dot($product['status'])); ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canManage): ?>
            <a class="btn btn-primary btn-sm" href="/modules/products/edit.php?id=<?php echo (int) $productId; ?>">Edit Product</a>
            <a class="btn btn-outline-primary btn-sm" href="/modules/products/control-center.php?id=<?php echo (int) $productId; ?>">Open Product Control Center</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
    </div>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Basic Information</h5>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="text-muted small">Name</div>
            <div><?php echo app_escape($product['name']); ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">SKU</div>
            <div><?php echo app_escape($product['sku']); ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Barcode</div>
            <div><?php echo $product['barcode'] !== null && $product['barcode'] !== '' ? app_escape($product['barcode']) : '—'; ?></div>
        </div>
        <?php if (!empty($product['internal_code'])): ?>
            <div class="col-md-4">
                <div class="text-muted small">Internal Code</div>
                <div><?php echo app_escape($product['internal_code']); ?></div>
            </div>
        <?php endif; ?>
        <div class="col-md-4">
            <div class="text-muted small">Brand</div>
            <div><?php echo $brandName !== null ? app_escape($brandName) : '—'; ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Category</div>
            <div><?php echo $categoryName !== null ? app_escape($categoryName) : '—'; ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Collection</div>
            <div><?php echo $collectionName !== null ? app_escape($collectionName) : '—'; ?></div>
        </div>
        <div class="col-12">
            <div class="text-muted small mb-1">Tags</div>
            <?php if ($productTags === []): ?>
                <div class="text-muted">—</div>
            <?php else: ?>
                <?php foreach ($productTags as $tag): ?>
                    <span class="badge bg-light text-dark border me-1"><?php echo app_escape($tag['name']); ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Inventory Summary</h5>
            <div class="d-flex justify-content-between"><span>Available</span><strong><?php echo (int) $currentStock['available_quantity']; ?></strong></div>
            <div class="d-flex justify-content-between"><span>Reserved</span><strong><?php echo (int) $currentStock['reserved_quantity']; ?></strong></div>
            <div class="d-flex justify-content-between"><span>Incoming</span><strong><?php echo (int) $currentStock['incoming_quantity']; ?></strong></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Supplier</h5>
            <?php if ($supplier !== null): ?>
                <div><?php echo app_escape($supplier['name']); ?></div>
            <?php else: ?>
                <div class="text-muted">No supplier assigned</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($product['catalog_type'] === 'variable'): ?>
    <div class="card p-4">
        <h5 class="mb-3">Variations</h5>
        <?php if ($variations === []): ?>
            <p class="text-muted mb-0">No variations yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Variation</th>
                            <th>SKU</th>
                            <th>Price</th>
                            <th>Available</th>
                            <th>Reserved</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($variations as $variation): ?>
                            <tr>
                                <td><?php echo app_escape($variation['label']); ?></td>
                                <td><?php echo app_escape($variation['sku']); ?></td>
                                <td>
                                    RM <?php echo app_escape(number_format(variation_effective_price($variation, $product['selling_price']), 2)); ?>
                                    <?php if (($variation['price_mode'] ?? 'inherit') !== 'custom'): ?>
                                        <span class="text-muted small">(follows product price)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $variation['available_quantity']; ?></td>
                                <td><?php echo (int) $variation['reserved_quantity']; ?></td>
                                <td><?php echo app_escape(ucfirst($variation['status'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
