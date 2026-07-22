<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/inventory.php';
app_require_login();
app_require_permission('products.view');

$appTitle = 'Products';

$statusOptions = ['draft', 'active', 'hidden', 'archived'];
$catalogTypes = ['simple', 'variable'];
$productTypeLabels = ['ready_stock' => 'Ready Stock', 'preorder' => 'Preorder', 'early_bird' => 'Early Bird'];

$pdo = app_db();

// --- Filters: every value is whitelisted/typed before it ever reaches the query, so an
// invalid or garbage GET value is silently ignored (treated as "no filter") rather than
// coerced into something that could match the wrong rows or throw. ---
$filterCategoryId = isset($_GET['category_id']) && ctype_digit((string) $_GET['category_id']) ? (int) $_GET['category_id'] : null;
$filterBrandId = isset($_GET['brand_id']) && ctype_digit((string) $_GET['brand_id']) ? (int) $_GET['brand_id'] : null;
$filterCollectionId = isset($_GET['collection_id']) && ctype_digit((string) $_GET['collection_id']) ? (int) $_GET['collection_id'] : null;
$filterSupplierId = isset($_GET['supplier_id']) && ctype_digit((string) $_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
$filterCatalogType = in_array($_GET['catalog_type'] ?? '', $catalogTypes, true) ? $_GET['catalog_type'] : null;
$filterStatus = in_array($_GET['status'] ?? '', $statusOptions, true) ? $_GET['status'] : null;
// The Availability dropdown is gone - the lifecycle-stage button tabs below are its
// replacement (see catalog_product_lifecycle_stage()), not an addition on top of it.
$quick = in_array($_GET['quick'] ?? '', ['ready_stock', 'preorder', 'early_bird', 'low_stock'], true) ? $_GET['quick'] : null;
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT
        p.id, p.sku, p.name, p.product_type, p.catalog_type, p.status, p.selling_price,
        p.min_stock_threshold, p.preorder_closing_date, p.preorder_reopened_at, p.availability_override,
        b.name AS brand_name,
        cat.id AS category_id, cat.name AS category_name,
        col.id AS collection_id, col.name AS collection_name,
        s.name AS supplier_name,
        (SELECT image_path FROM product_images pi
            WHERE pi.product_id = p.id AND pi.variation_id IS NULL AND pi.image_type = 'main'
            ORDER BY pi.id DESC LIMIT 1) AS thumb_path
    FROM products p
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    LEFT JOIN product_category_relationships pcr ON pcr.product_id = p.id
    LEFT JOIN categories cat ON cat.id = pcr.category_id
    LEFT JOIN product_collection_relationships pclr ON pclr.product_id = p.id
    LEFT JOIN collections col ON col.id = pclr.collection_id
    WHERE 1 = 1
";
$params = [];

if ($filterCategoryId !== null) {
    $sql .= ' AND EXISTS (SELECT 1 FROM product_category_relationships r WHERE r.product_id = p.id AND r.category_id = ?)';
    $params[] = $filterCategoryId;
}
if ($filterCollectionId !== null) {
    $sql .= ' AND EXISTS (SELECT 1 FROM product_collection_relationships r WHERE r.product_id = p.id AND r.collection_id = ?)';
    $params[] = $filterCollectionId;
}
if ($filterBrandId !== null) {
    $sql .= ' AND p.brand_id = ?';
    $params[] = $filterBrandId;
}
if ($filterSupplierId !== null) {
    $sql .= ' AND p.supplier_id = ?';
    $params[] = $filterSupplierId;
}
if ($filterCatalogType !== null) {
    $sql .= ' AND p.catalog_type = ?';
    $params[] = $filterCatalogType;
}
if ($filterStatus !== null) {
    $sql .= ' AND p.status = ?';
    $params[] = $filterStatus;
}
if ($searchTerm !== '') {
    $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}

$sql .= ' ORDER BY p.id DESC LIMIT 300';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// quick=low_stock has no clean SQL equivalent without duplicating the low-stock business
// rule (ready_stock only, min_stock_threshold set, effective available stock below it) -
// reuse the exact formula from modules/products/edit.php via product_effective_stock()
// instead of re-deriving stock math in raw SQL. Bounded by the 300-row cap above, so this
// is at most ~300 extra small PK-lookup queries - fine at this admin-tool scale.
if ($quick === 'low_stock') {
    $products = array_values(array_filter($products, static function (array $product) use ($pdo): bool {
        if ($product['product_type'] !== 'ready_stock' || $product['min_stock_threshold'] === null) {
            return false;
        }
        $stock = product_effective_stock($pdo, (int) $product['id']);

        return (int) $stock['available_quantity'] < (int) $product['min_stock_threshold'];
    }));
} elseif (in_array($quick, ['ready_stock', 'preorder', 'early_bird'], true)) {
    // These three tabs filter by the product's *current computed lifecycle stage*
    // (catalog_product_lifecycle_stage(), the same logic behind catalog_lifecycle_badge()),
    // not the raw product_type column - a reopened Early Bird product shows under the
    // Preorder tab, and a closed/inactive product shows under neither, matching what its
    // badge actually displays rather than a static database value.
    $products = array_values(array_filter($products, static fn (array $product): bool => catalog_product_lifecycle_stage($product) === $quick));
}

$filterCategories = catalog_list_categories_tree($pdo);
$filterBrands = catalog_list_brands($pdo);
$filterCollections = catalog_list_collections($pdo);
$filterSuppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$chipActive = static function (array $chipParams): bool {
    foreach ($chipParams as $key => $value) {
        if (($_GET[$key] ?? null) !== $value) {
            return false;
        }
    }

    return true;
};

$chips = [
    ['label' => 'All', 'params' => []],
    ['label' => '🟩 Ready Stock', 'params' => ['quick' => 'ready_stock']],
    ['label' => '🟪 Preorder', 'params' => ['quick' => 'preorder']],
    ['label' => '🟧 Early Bird', 'params' => ['quick' => 'early_bird']],
    ['label' => '⚠️ Low Stock', 'params' => ['quick' => 'low_stock']],
];

$canManage = app_has_permission('products.manage');

require_once __DIR__ . '/../../includes/header.php';
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


<?php $filterKeys = ['category_id', 'brand_id', 'collection_id', 'supplier_id', 'catalog_type', 'status', 'quick', 'q']; ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($chips as $chip): ?>
        <?php
        $isActive = $chip['params'] === []
            ? array_filter(array_intersect_key($_GET, array_flip($filterKeys))) === []
            : $chipActive($chip['params']);
        ?>
        <a class="btn btn-sm <?php echo $isActive ? 'btn-primary' : 'btn-outline-secondary'; ?>"
           href="/modules/products/index.php<?php echo $chip['params'] !== [] ? ('?' . http_build_query($chip['params'])) : ''; ?>">
            <?php echo app_escape($chip['label']); ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Name, SKU, or barcode">
        </div>

        <div class="col-md-2">
            <label class="form-label small mb-1">Category</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($filterCategories as $cat): ?>
                    <option value="<?php echo (int) $cat['id']; ?>" <?php echo $filterCategoryId === $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo str_repeat('&nbsp;&nbsp;', $cat['depth']) . app_escape($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label small mb-1">Brand</label>
            <select name="brand_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($filterBrands as $brand): ?>
                    <option value="<?php echo (int) $brand['id']; ?>" <?php echo $filterBrandId === (int) $brand['id'] ? 'selected' : ''; ?>><?php echo app_escape($brand['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label small mb-1">Collection</label>
            <select name="collection_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($filterCollections as $collection): ?>
                    <option value="<?php echo (int) $collection['id']; ?>" <?php echo $filterCollectionId === (int) $collection['id'] ? 'selected' : ''; ?>><?php echo app_escape($collection['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-1">
            <label class="form-label small mb-1">Structure</label>
            <select name="catalog_type" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($catalogTypes as $type): ?>
                    <option value="<?php echo app_escape($type); ?>" <?php echo $filterCatalogType === $type ? 'selected' : ''; ?>><?php echo app_escape(ucfirst($type)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($statusOptions as $statusValue): ?>
                    <option value="<?php echo app_escape($statusValue); ?>" <?php echo $filterStatus === $statusValue ? 'selected' : ''; ?>><?php echo app_escape(catalog_status_dot($statusValue)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label small mb-1">Supplier</label>
            <select name="supplier_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($filterSuppliers as $supplier): ?>
                    <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $filterSupplierId === (int) $supplier['id'] ? 'selected' : ''; ?>><?php echo app_escape($supplier['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/products/index.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th></th>
                <th>Product</th>
                <th>Category</th>
                <th>Brand</th>
                <th>Availability</th>
                <th>Structure</th>
                <th>Stage</th>
                <th>Status</th>
                <th>Price</th>
                <?php if ($canManage): ?><th></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
                <?php
                $isVariable = ($product['catalog_type'] ?? 'simple') === 'variable';
                $secondary = array_filter([
                    $product['sku'],
                    $product['category_name'] ?? null,
                    $product['brand_name'] ?? null,
                    $product['collection_name'] ?? null,
                    $product['supplier_name'] ?? null,
                ]);
                ?>
                <tr>
                    <td>
                        <?php if (!empty($product['thumb_path'])): ?>
                            <img src="/<?php echo app_escape($product['thumb_path']); ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px;">
                        <?php else: ?>
                            <div class="bg-light text-muted border rounded d-flex align-items-center justify-content-center" style="width:56px;height:56px;font-size:.65rem;text-align:center;">No image</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-semibold"><?php echo app_escape($product['name']); ?></div>
                        <?php if ($secondary !== []): ?>
                            <div class="text-muted small"><?php echo app_escape(implode(' · ', $secondary)); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $product['category_name'] !== null ? app_escape($product['category_name']) : '—'; ?></td>
                    <td><?php echo $product['brand_name'] !== null ? app_escape($product['brand_name']) : '—'; ?></td>
                    <td><?php echo app_escape($productTypeLabels[$product['product_type']] ?? $product['product_type']); ?></td>
                    <td><span class="badge bg-<?php echo $isVariable ? 'info text-dark' : 'light text-dark'; ?>"><?php echo $isVariable ? 'Variable' : 'Simple'; ?></span></td>
                    <td><?php echo catalog_lifecycle_badge($product); ?></td>
                    <td><?php echo app_escape(catalog_status_dot($product['status'])); ?></td>
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
                    <td colspan="<?php echo $canManage ? 10 : 9; ?>" class="text-muted">No products match these filters.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
