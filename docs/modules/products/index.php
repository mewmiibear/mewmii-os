<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/saved_views_widget.php';
app_require_login();
app_require_permission('products.view');

$appTitle = 'Products';

$statusOptions = ['draft', 'active', 'hidden', 'archived'];
$catalogTypes = ['simple', 'variable'];

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
// Sprint 12: Tags (match ANY selected tag) and Stock Status - independent of the lifecycle
// "quick" chips above (which describe preorder/early_bird/ready_stock stage, not raw
// available_quantity), so both can be combined with every other filter on this page.
$stockStatusOptions = ['in_stock', 'low_stock', 'out_of_stock'];
$filterTagIds = array_values(array_unique(array_filter(array_map('intval', $_GET['tag_ids'] ?? []), static fn (int $id): bool => $id > 0)));
$filterStockStatus = in_array($_GET['stock_status'] ?? '', $stockStatusOptions, true) ? $_GET['stock_status'] : null;
// Sprint 13: missing-image detection - a product with no main image at all (the same
// image_type = 'main' row modules/products/_form.php's "Product Image" field manages).
$filterMissingImage = ($_GET['missing_image'] ?? '') === '1';

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
if ($filterTagIds !== []) {
    $tagPlaceholders = implode(',', array_fill(0, count($filterTagIds), '?'));
    $whereSql .= " AND EXISTS (SELECT 1 FROM product_tag_relationships r WHERE r.product_id = p.id AND r.tag_id IN ({$tagPlaceholders}))";
    foreach ($filterTagIds as $tagId) {
        $params[] = $tagId;
    }
}
// Same batched stock.available_quantity used for display/sorting/quick=low_stock above -
// a raw on-hand-quantity view, independent of the lifecycle "quick" chips (preorder/early
// bird availability never depends on quantity - see catalog_product_availability_status()).
if ($filterStockStatus === 'in_stock') {
    $whereSql .= ' AND COALESCE(stock.available_quantity, 0) > 0';
} elseif ($filterStockStatus === 'out_of_stock') {
    $whereSql .= ' AND COALESCE(stock.available_quantity, 0) <= 0';
} elseif ($filterStockStatus === 'low_stock') {
    $whereSql .= ' AND p.min_stock_threshold IS NOT NULL AND COALESCE(stock.available_quantity, 0) > 0 AND COALESCE(stock.available_quantity, 0) < p.min_stock_threshold';
}
if ($filterMissingImage) {
    $whereSql .= " AND NOT EXISTS (SELECT 1 FROM product_images pi3 WHERE pi3.product_id = p.id AND pi3.variation_id IS NULL AND pi3.image_type = 'main')";
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
$filterTagsList = catalog_list_tags($pdo);

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

// Sprint 10: one-time flash from modules/products/bulk_action.php, same convention as
// modules/orders/bulk_action.php -> modules/orders/index.php.
$bulkResult = $_SESSION['products_bulk_result'] ?? null;
unset($_SESSION['products_bulk_result']);

// Bugfix pass: one-time flash from modules/products/sync.php, same convention as above -
// carries the categorized failure-reason breakdown the bare ?sync=1&failed= query params
// can't (see wc_client_classify_sync_error()).
$syncResult = $_SESSION['products_sync_result'] ?? null;
unset($_SESSION['products_sync_result']);

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
            <a class="btn btn-outline-secondary" href="/modules/products/import.php">Import CSV</a>
        </div>
    <?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <?php if (isset($_GET['sync'])): ?>
            <?php
            // Prefer the session flash (has the categorized reason breakdown); fall back to
            // the bare query params if the flash is somehow missing (e.g. an old bookmarked
            // redirect URL) so this never shows a blank/broken banner either way.
            $updatedCount = $syncResult['updated'] ?? (isset($_GET['updated']) ? (int) $_GET['updated'] : 0);
            $skippedCount = $syncResult['skipped'] ?? (isset($_GET['skipped']) ? (int) $_GET['skipped'] : 0);
            $staleCount = $syncResult['stale'] ?? (isset($_GET['stale']) ? (int) $_GET['stale'] : 0);
            $failedCount = $syncResult['failed'] ?? (isset($_GET['failed']) ? (int) $_GET['failed'] : 0);
            $syncFailureReasons = $syncResult['failure_reasons'] ?? [];
            $syncMissingImageCount = $syncResult['missing_images'] ?? 0;
            ?>
            <div class="alert <?php echo ($failedCount > 0 || $syncMissingImageCount > 0) ? 'alert-warning' : 'alert-info'; ?> mb-0">
                <?php
                echo 'WooCommerce sync completed. ' . $updatedCount . ' updated, ' . $skippedCount . ' unchanged (skipped)'
                    . ($staleCount > 0 ? ', ' . $staleCount . ' skipped (WooCommerce has a newer edit - see Sync Logs)' : '')
                    . ($failedCount > 0 ? ', ' . $failedCount . ' failed.' : '.');
                ?>
                <?php if ($syncMissingImageCount > 0): ?>
                    <div class="small">&#9888; <?php echo (int) $syncMissingImageCount; ?> product(s) synced with one or more images skipped (file missing on disk) - see Sync Logs.</div>
                <?php endif; ?>
                <?php if ($syncFailureReasons !== []): ?>
                    <ul class="mb-0 small">
                        <?php foreach ($syncFailureReasons as $reason => $count): ?>
                            <li><?php echo (int) $count; ?> &mdash; <?php echo app_escape($reason); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (app_has_permission('settings.manage')): ?>
                        <a href="/modules/sync-logs/index.php" class="small">View Sync Logs for details</a>
                    <?php endif; ?>
                <?php endif; ?>
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
<?php if ($bulkResult !== null): ?>
    <div class="alert <?php echo ($bulkResult['failed_count'] > 0 || ($bulkResult['missing_images'] ?? 0) > 0) ? 'alert-warning' : 'alert-success'; ?>">
        <?php
        $bulkActionLabels = [
            'set_brand' => 'Set Brand',
            'set_category' => 'Set Category',
            'set_collection' => 'Set Collection',
            'add_tags' => 'Add Tags',
            'archive' => 'Archive',
            'sync_woocommerce' => 'Sync to WooCommerce',
        ];
        echo app_escape($bulkActionLabels[$bulkResult['action']] ?? 'Bulk action') . ': ' . (int) $bulkResult['success_count'] . ' updated';
        if ($bulkResult['skipped_count'] > 0) {
            echo ', ' . (int) $bulkResult['skipped_count'] . ' unchanged (skipped)';
        }
        if ($bulkResult['stale_count'] > 0) {
            echo ', ' . (int) $bulkResult['stale_count'] . ' skipped (WooCommerce has a newer edit - see Sync Logs)';
        }
        if ($bulkResult['failed_count'] > 0) {
            echo ', ' . (int) $bulkResult['failed_count'] . ' failed';
        }
        echo '.';
        ?>
        <?php if (($bulkResult['missing_images'] ?? 0) > 0): ?>
            <div class="small">&#9888; <?php echo (int) $bulkResult['missing_images']; ?> product(s) synced with one or more images skipped (file missing on disk) - see Sync Logs.</div>
        <?php endif; ?>
        <?php if (($bulkResult['failure_reasons'] ?? []) !== []): ?>
            <ul class="mb-0 small">
                <?php foreach ($bulkResult['failure_reasons'] as $reason => $count): ?>
                    <li><?php echo (int) $count; ?> &mdash; <?php echo app_escape($reason); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (app_has_permission('settings.manage')): ?>
                <a href="/modules/sync-logs/index.php" class="small">View Sync Logs for details</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php render_saved_views_widget($pdo, 'products'); ?>

<?php $filterKeys = ['category_id', 'brand_id', 'collection_id', 'supplier_id', 'catalog_type', 'status', 'quick', 'q', 'tag_ids', 'stock_status', 'missing_image']; ?>
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
            <label class="form-label small mb-1">Stock Status</label>
            <select name="stock_status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="in_stock" <?php echo $filterStockStatus === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                <option value="low_stock" <?php echo $filterStockStatus === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                <option value="out_of_stock" <?php echo $filterStockStatus === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
            </select>
        </div>

        <div class="col-md-2">
            <div class="d-flex justify-content-between align-items-center">
                <label class="form-label small mb-1">Tags</label>
                <a class="small" href="/modules/catalog/index.php?tab=tags" target="_blank" rel="noopener">Manage &#8599;</a>
            </div>
            <select name="tag_ids[]" class="form-select form-select-sm" multiple size="4">
                <?php foreach ($filterTagsList as $tag): ?>
                    <option value="<?php echo (int) $tag['id']; ?>" <?php echo in_array((int) $tag['id'], $filterTagIds, true) ? 'selected' : ''; ?>><?php echo app_escape($tag['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Ctrl/Cmd-click to select multiple. Matches any.</div>
        </div>

        <div class="col-md-2 d-flex align-items-center">
            <div class="form-check mt-2">
                <input type="checkbox" class="form-check-input" id="missing-image-toggle" name="missing_image" value="1" <?php echo $filterMissingImage ? 'checked' : ''; ?> onchange="this.form.submit()">
                <label class="form-check-label small" for="missing-image-toggle">Missing image only</label>
            </div>
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

<?php if ($canManage): ?>
<form id="products-bulk-form" method="post" action="/modules/products/bulk_action.php"></form>
<div class="card p-3 mb-3">
    <div class="d-flex flex-wrap align-items-end gap-2">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>" form="products-bulk-form">
        <div>
            <label class="form-label small mb-1">Bulk action</label>
            <select id="bulk-action-select" name="bulk_action" class="form-select form-select-sm" form="products-bulk-form">
                <option value="">Choose an action&hellip;</option>
                <option value="set_brand">Set Brand</option>
                <option value="set_category">Set Category</option>
                <option value="set_collection">Set Collection</option>
                <option value="add_tags">Add Tags</option>
                <option value="archive">Archive</option>
                <option value="sync_woocommerce">Sync to WooCommerce</option>
            </select>
        </div>
        <div class="bulk-target d-none" data-for="set_brand">
            <label class="form-label small mb-1">Brand</label>
            <select name="bulk_brand_id" class="form-select form-select-sm" form="products-bulk-form">
                <option value="">Select brand</option>
                <?php foreach ($filterBrands as $brand): ?>
                    <option value="<?php echo (int) $brand['id']; ?>"><?php echo app_escape($brand['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bulk-target d-none" data-for="set_category">
            <label class="form-label small mb-1">Category</label>
            <select name="bulk_category_id" class="form-select form-select-sm" form="products-bulk-form">
                <option value="">Select category</option>
                <?php foreach ($filterCategories as $cat): ?>
                    <option value="<?php echo (int) $cat['id']; ?>"><?php echo str_repeat('&nbsp;&nbsp;', $cat['depth']) . app_escape($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bulk-target d-none" data-for="set_collection">
            <label class="form-label small mb-1">Collection</label>
            <select name="bulk_collection_id" class="form-select form-select-sm" form="products-bulk-form">
                <option value="">Select collection</option>
                <?php foreach ($filterCollections as $collection): ?>
                    <option value="<?php echo (int) $collection['id']; ?>"><?php echo app_escape($collection['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bulk-target d-none" data-for="add_tags">
            <label class="form-label small mb-1">Tags to add</label>
            <select name="bulk_tag_ids[]" class="form-select form-select-sm" form="products-bulk-form" multiple size="4" style="min-width: 160px;">
                <?php foreach (catalog_list_tags($pdo) as $tag): ?>
                    <option value="<?php echo (int) $tag['id']; ?>"><?php echo app_escape($tag['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" id="bulk-submit-btn" class="btn btn-sm btn-primary" form="products-bulk-form" disabled>Apply</button>
            <span id="bulk-selected-count" class="text-muted small ms-2">0 selected</span>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <?php if ($canManage): ?><th><input type="checkbox" id="products-bulk-select-all" aria-label="Select all products on this page"></th><?php endif; ?>
                <th></th>
                <th>Product</th>
                <th>Category</th>
                <th>Brand</th>
                <th>Supplier</th>
                <th>Structure</th>
                <th>Stage</th>
                <th>Stock</th>
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
                    <?php if ($canManage): ?>
                        <td data-label=""><input type="checkbox" class="products-bulk-checkbox" name="product_ids[]" value="<?php echo (int) $product['id']; ?>" form="products-bulk-form" aria-label="Select <?php echo app_escape($product['name']); ?>"></td>
                    <?php endif; ?>
                    <td data-label="">
                        <?php if (!empty($product['thumb_path'])): ?>
                            <img src="/<?php echo app_escape($product['thumb_path']); ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px;">
                        <?php else: ?>
                            <div class="bg-light text-muted border rounded d-flex align-items-center justify-content-center" style="width:56px;height:56px;font-size:.65rem;text-align:center;">No image</div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Product">
                        <div class="fw-semibold"><?php echo app_escape($product['name']); ?></div>
                        <?php if ($secondary !== []): ?>
                            <div class="text-muted small"><?php echo app_escape(implode(' · ', $secondary)); ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Category"><?php echo $product['category_name'] !== null ? app_escape($product['category_name']) : '—'; ?></td>
                    <td data-label="Brand"><?php echo $product['brand_name'] !== null ? app_escape($product['brand_name']) : '—'; ?></td>
                    <td data-label="Supplier"><?php echo $product['supplier_name'] !== null ? app_escape($product['supplier_name']) : '—'; ?></td>
                    <td data-label="Structure"><span class="badge bg-<?php echo $isVariable ? 'info text-dark' : 'light text-dark'; ?>"><?php echo $isVariable ? 'Variable' : 'Simple'; ?></span></td>
                    <td data-label="Stage"><?php echo catalog_lifecycle_badge($product); ?></td>
                    <td data-label="Stock">
                        <?php if ((int) $product['available_quantity'] > 0): ?>
                            <?php echo (int) $product['available_quantity']; ?>
                        <?php else: ?>
                            <span class="badge bg-danger">🔴 Out of Stock</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Reserved"><?php echo (int) $product['reserved_quantity']; ?></td>
                    <td data-label="Incoming"><?php echo (int) $product['incoming_quantity']; ?></td>
                    <td data-label="Price">RM <?php echo app_escape(number_format((float) $product['selling_price'], 2)); ?><?php if ($isVariable): ?> <span class="text-muted small">(default)</span><?php endif; ?></td>
                    <td data-label="" class="text-end">
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
                    <td colspan="<?php echo $canManage ? 13 : 12; ?>">
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
<?php if ($canManage): ?>
<script>
(function () {
    var selectAll = document.getElementById('products-bulk-select-all');
    var checkboxes = document.querySelectorAll('.products-bulk-checkbox');
    var actionSelect = document.getElementById('bulk-action-select');
    var submitBtn = document.getElementById('bulk-submit-btn');
    var countLabel = document.getElementById('bulk-selected-count');
    var targets = document.querySelectorAll('.bulk-target');

    function selectedCount() {
        var count = 0;
        checkboxes.forEach(function (cb) { if (cb.checked) { count++; } });
        return count;
    }

    function actionNeedsTarget(action) {
        return action === 'set_brand' || action === 'set_category' || action === 'set_collection' || action === 'add_tags';
    }

    function refreshState() {
        var count = selectedCount();
        countLabel.textContent = count + ' selected';
        var action = actionSelect.value;
        var ready = count > 0 && action !== '';
        submitBtn.disabled = !ready;

        targets.forEach(function (target) {
            target.classList.toggle('d-none', target.getAttribute('data-for') !== action);
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
            refreshState();
        });
    }
    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', refreshState);
    });
    if (actionSelect) {
        actionSelect.addEventListener('change', refreshState);
    }

    var bulkForm = document.getElementById('products-bulk-form');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function (event) {
            var action = actionSelect.value;
            // The target-value pickers live in the toolbar <div>, associated with this
            // <form> only via the HTML5 form="products-bulk-form" attribute (so the table's
            // per-row Duplicate <form> never ends up nested inside a table-wrapping form) -
            // they are not DOM descendants of bulkForm, so look them up from the document.
            if (actionNeedsTarget(action)) {
                if (action === 'add_tags') {
                    var tagSelect = document.querySelector('select[name="bulk_tag_ids[]"]');
                    if (!tagSelect || tagSelect.selectedOptions.length === 0) {
                        event.preventDefault();
                        alert('Select at least one tag to add.');
                        return;
                    }
                } else {
                    var targetSelect = document.querySelector('.bulk-target[data-for="' + action + '"] select');
                    if (!targetSelect || targetSelect.value === '') {
                        event.preventDefault();
                        alert('Select a value for this bulk action.');
                        return;
                    }
                }
            }
        });
    }

    refreshState();
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
