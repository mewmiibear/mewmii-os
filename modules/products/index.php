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

// --- Sorting: whitelisted field -> safe SQL expression, never string-built from raw input.
// 'stock' sorts on the same batched available_quantity used for the new stock columns below,
// not a per-row recalculation. A stable p.id DESC tiebreaker keeps LIMIT/OFFSET pagination
// deterministic across pages even when many rows share the same sort value (e.g. stock = 0).
$sortColumns = [
    'name' => 'p.name',
    'sku' => 'p.sku',
    'stock' => 'COALESCE(stock.available_quantity, 0)',
    'created' => 'p.created_at',
];
$sortKey = isset($_GET['sort']) && array_key_exists($_GET['sort'], $sortColumns) ? $_GET['sort'] : null;
$sortDir = ($_GET['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';
$orderSql = $sortKey !== null ? ($sortColumns[$sortKey] . ' ' . $sortDir . ', p.id DESC') : 'p.id DESC';

// --- Pagination ---
$perPage = 50;
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;

// Batched stock rollup - one JOIN shared by every row instead of a per-product
// product_effective_stock() call. Mirrors that function's own rule exactly (simple products:
// their own variation_id-IS-NULL row; variable products: SUM across non-archived variations)
// so the numbers shown here never drift from what the product/Control Center pages compute -
// see includes/inventory.php:product_effective_stock().
$stockJoinSql = "
    LEFT JOIN (
        SELECT inv.product_id,
               SUM(inv.available_quantity) AS available_quantity,
               SUM(inv.reserved_quantity) AS reserved_quantity,
               SUM(inv.incoming_quantity) AS incoming_quantity
        FROM mewmii_inventory inv
        LEFT JOIN product_variations pv ON pv.id = inv.variation_id
        WHERE inv.variation_id IS NULL OR pv.status <> 'archived'
        GROUP BY inv.product_id
    ) stock ON stock.product_id = p.id
";

$whereSql = '';
$params = [];

if ($filterCategoryId !== null) {
    $whereSql .= ' AND EXISTS (SELECT 1 FROM product_category_relationships r WHERE r.product_id = p.id AND r.category_id = ?)';
    $params[] = $filterCategoryId;
}
if ($filterCollectionId !== null) {
    $whereSql .= ' AND EXISTS (SELECT 1 FROM product_collection_relationships r WHERE r.product_id = p.id AND r.collection_id = ?)';
    $params[] = $filterCollectionId;
}
if ($filterBrandId !== null) {
    $whereSql .= ' AND p.brand_id = ?';
    $params[] = $filterBrandId;
}
if ($filterSupplierId !== null) {
    $whereSql .= ' AND p.supplier_id = ?';
    $params[] = $filterSupplierId;
}
if ($filterCatalogType !== null) {
    $whereSql .= ' AND p.catalog_type = ?';
    $params[] = $filterCatalogType;
}
if ($filterStatus !== null) {
    $whereSql .= ' AND p.status = ?';
    $params[] = $filterStatus;
}
if ($searchTerm !== '') {
    $whereSql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}
// quick=low_stock is a plain numeric comparison against the same batched stock.available_quantity
// used for display/sorting above - not a re-derivation of a different formula, so it composes
// with SQL-level pagination instead of needing a PHP-side pass. Preserves the exact prior rule
// (ready_stock + a threshold set + available under that threshold; status is NOT part of this
// rule, matching the original PHP filter it replaces).
if ($quick === 'low_stock') {
    $whereSql .= ' AND p.product_type = ? AND p.min_stock_threshold IS NOT NULL AND COALESCE(stock.available_quantity, 0) < p.min_stock_threshold';
    $params[] = 'ready_stock';
}

$selectSql = "
    SELECT
        p.id, p.sku, p.name, p.internal_code, p.product_type, p.catalog_type, p.status, p.selling_price,
        p.min_stock_threshold, p.preorder_closing_date, p.preorder_reopened_at, p.availability_override,
        b.name AS brand_name,
        cat.id AS category_id, cat.name AS category_name,
        col.id AS collection_id, col.name AS collection_name,
        s.name AS supplier_name,
        COALESCE(stock.available_quantity, 0) AS available_quantity,
        COALESCE(stock.reserved_quantity, 0) AS reserved_quantity,
        COALESCE(stock.incoming_quantity, 0) AS incoming_quantity,
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
    {$stockJoinSql}
    WHERE 1 = 1 {$whereSql}
";

if ($quick === 'ready_stock' || $quick === 'preorder' || $quick === 'early_bird') {
    // These three tabs filter by the product's *current computed lifecycle stage*
    // (catalog_product_lifecycle_stage(), the same logic behind catalog_lifecycle_badge()),
    // not the raw product_type column - a reopened Early Bird product shows under the
    // Preorder tab, and a closed/inactive product shows under neither, matching what its
    // badge actually displays rather than a static database value. That stage is a multi-branch
    // state machine over dates/flags, not a single comparison like low_stock above, so
    // reimplementing it as SQL would mean maintaining the same rule twice - instead every
    // filtered+sorted row is fetched once (no row-count cap other than the filters above) and
    // the existing canonical function is reused to filter in PHP, then the requested page is
    // sliced out of that already-filtered list. Admin-catalog scale, so this stays cheap.
    $stmt = $pdo->prepare($selectSql . ' ORDER BY ' . $orderSql);
    $stmt->execute($params);
    $allMatching = array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        static fn (array $product): bool => catalog_product_lifecycle_stage($product) === $quick
    ));

    $totalCount = count($allMatching);
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page = min($page, $totalPages);
    $products = array_slice($allMatching, ($page - 1) * $perPage, $perPage);
} else {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p {$stockJoinSql} WHERE 1 = 1 {$whereSql}");
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare($selectSql . " ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        <div class="action-bar">
            <a class="btn btn-primary" href="/modules/products/create.php">Add Product</a>
        </div>
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
        <div class="action-bar">
            <form method="post" action="/modules/products/sync.php" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <button type="submit" class="btn btn-outline-secondary">Sync to WooCommerce</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Product created.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Product deleted.</div>
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

<div class="card filter-card p-3 mb-4">
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

        <div class="col-md-2">
            <label class="form-label small mb-1">Sort by</label>
            <select name="sort" class="form-select form-select-sm">
                <option value="">Newest first (default)</option>
                <option value="name" <?php echo $sortKey === 'name' ? 'selected' : ''; ?>>Product Name</option>
                <option value="sku" <?php echo $sortKey === 'sku' ? 'selected' : ''; ?>>SKU</option>
                <option value="stock" <?php echo $sortKey === 'stock' ? 'selected' : ''; ?>>Available Stock</option>
                <option value="created" <?php echo $sortKey === 'created' ? 'selected' : ''; ?>>Date Created</option>
            </select>
        </div>
        <div class="col-md-1">
            <label class="form-label small mb-1">Direction</label>
            <select name="dir" class="form-select form-select-sm">
                <option value="asc" <?php echo $sortDir === 'ASC' ? 'selected' : ''; ?>>Asc</option>
                <option value="desc" <?php echo $sortDir === 'DESC' ? 'selected' : ''; ?>>Desc</option>
            </select>
        </div>

        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/products/index.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th></th>
                <th>Product</th>
                <th>Category</th>
                <th>Brand</th>
                <th>Supplier</th>
                <th>Availability</th>
                <th>Structure</th>
                <th>Stage</th>
                <th>Status</th>
                <th>Available</th>
                <th>Reserved</th>
                <th>Incoming</th>
                <th>Price</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
                <?php
                $isVariable = ($product['catalog_type'] ?? 'simple') === 'variable';
                $secondary = array_filter([
                    $product['sku'],
                    !empty($product['internal_code']) ? ('Code: ' . $product['internal_code']) : null,
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
                    <td><?php echo $product['supplier_name'] !== null ? app_escape($product['supplier_name']) : '—'; ?></td>
                    <td><?php echo app_escape($productTypeLabels[$product['product_type']] ?? $product['product_type']); ?></td>
                    <td><span class="badge bg-<?php echo $isVariable ? 'info text-dark' : 'light text-dark'; ?>"><?php echo $isVariable ? 'Variable' : 'Simple'; ?></span></td>
                    <td><?php echo catalog_lifecycle_badge($product); ?></td>
                    <td><?php echo app_escape(catalog_status_dot($product['status'])); ?></td>
                    <td><?php echo (int) $product['available_quantity']; ?></td>
                    <td><?php echo (int) $product['reserved_quantity']; ?></td>
                    <td><?php echo (int) $product['incoming_quantity']; ?></td>
                    <td>RM <?php echo app_escape(number_format((float) $product['selling_price'], 2)); ?><?php if ($isVariable): ?> <span class="text-muted small">(default)</span><?php endif; ?></td>
                    <td class="text-end">
                        <?php if (app_has_permission('products.view')): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="/modules/products/view.php?id=<?php echo (int) $product['id']; ?>">View</a>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <a class="btn btn-sm btn-outline-primary" href="/modules/products/edit.php?id=<?php echo (int) $product['id']; ?>">Edit</a>
                            <a class="btn btn-sm btn-outline-secondary" href="/modules/products/control-center.php?id=<?php echo (int) $product['id']; ?>">Control Center</a>
                            <form method="post" action="/modules/products/duplicate.php" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Duplicate</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr>
                    <td colspan="14">
                        <div class="empty-state">
                            <div class="empty-state-title">No Products Match These Filters</div>
                            <p class="empty-state-text">Try adjusting or clearing your filters to see more results.</p>
                            <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Clear filters</a>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    $pageUrl = static function (int $targetPage): string {
        return '/modules/products/index.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $rangeStart; ?>&ndash;<?php echo (int) $rangeEnd; ?> of <?php echo (int) $totalCount; ?> product<?php echo $totalCount === 1 ? '' : 's'; ?>
            <?php else: ?>
                0 products
            <?php endif; ?>
        </p>
        <?php if ($totalPages > 1): ?>
            <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(max(1, $page - 1))); ?>">&laquo; Prev</a>
                <span class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(min($totalPages, $page + 1))); ?>">Next &raquo;</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
