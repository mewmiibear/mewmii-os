<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/product_cost.php';
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
// `currency` added to Phase 7C.1's own SELECT (not a second query) - product_cost_configuration_
// status() below uses it to flag a product whose cost is likely foreign but never confirmed.
$supplier = null;
if ($product['supplier_id'] !== null) {
    $supplierStmt = $pdo->prepare('SELECT id, name, currency FROM suppliers WHERE id = ? LIMIT 1');
    $supplierStmt->execute([$product['supplier_id']]);
    $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Inventory Summary - reuses the same rollup function as the edit page and Control Center,
// no new calculation.
$currentStock = product_effective_stock($pdo, $productId);

// Variations - reuses the same listing function the edit form uses for its variation table.
$variations = $product['catalog_type'] === 'variable' ? variation_list_for_product($pdo, $productId) : [];

// Phase 7C - Cost Breakdown: reuses includes/product_cost.php's engine, no calculation here.
$costBreakdown = product_cost_calculate($pdo, $productId);
$notConfiguredBadge = '<span class="badge bg-secondary">Not configured</span>';

// Phase 7C.1 - Cost Data status (Complete / Missing Exchange Rate / Missing Currency
// Configuration), same reused engine, no calculation logic here either.
$costConfigStatus = product_cost_configuration_status($product, $supplier['currency'] ?? null);

// SO-C - count of sourcing alternatives, excluding the preferred supplier itself. Display only;
// products.supplier_id above remains the preferred supplier and is unaffected.
$altStmt = $pdo->prepare('SELECT COUNT(*) FROM supplier_products WHERE product_id = ? AND supplier_id <> COALESCE(?, 0)');
$altStmt->execute([$productId, $product['supplier_id']]);
$alternativeSupplierCount = (int) $altStmt->fetchColumn();
$costConfigLabels = [
    'complete' => 'Complete',
    'missing_exchange_rate' => 'Missing Exchange Rate',
    'missing_currency_configuration' => 'Missing Currency Configuration',
];
$costConfigBadgeClass = [
    'complete' => 'badge bg-success',
    'missing_exchange_rate' => 'badge bg-danger',
    'missing_currency_configuration' => 'badge bg-warning text-dark',
];

// Phase 8C - Cost History: frozen Landed Cost snapshots for this one product, single query
// (product_cost_history_list_for_product()), no calculation here either.
$costHistory = product_cost_history_list_for_product($pdo, $productId);
$canViewSupplierOrdersForHistory = app_has_permission('supplier-orders.view');

// UX U1 - this page's own location, handed to context-aware destinations so their
// Back returns here instead of their hardcoded default. See app_build_return_url().
$returnContext = app_build_return_url();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">
            <?php echo app_escape($product['name']); ?>
            <?php if (isset($_GET['debug_lifecycle'])): ?>
                <?php
                // --- TEMPORARY DEBUG INSTRUMENTATION (stale "Waiting Release" badge trace) ---
                // Same dump as _form.php's, for this page's own independent fetch/render of the
                // badge. Reach it manually via ?debug_lifecycle=1 (not carried by the "Open
                // Preorder" redirect, which lands on edit.php, not this page). Remove this block
                // (and the matching ones in reopen_preorder.php/edit.php/_form.php) once the
                // mismatch is found.
                $debugViewStage = catalog_product_lifecycle_stage($product);
                ?>
                <pre style="background:#111;color:#0f0;padding:1rem;white-space:pre-wrap;font-size:.85rem;">[LIFECYCLE-DEBUG view.php] right before catalog_lifecycle_badge($product) renders
product_type: <?php echo var_export($product['product_type'] ?? null, true); ?>

preorder_closing_date: <?php echo var_export($product['preorder_closing_date'] ?? null, true); ?>

preorder_reopened_at: <?php echo var_export($product['preorder_reopened_at'] ?? null, true); ?>

status: <?php echo var_export($product['status'] ?? null, true); ?>

availability_override: <?php echo var_export($product['availability_override'] ?? null, true); ?>

computed stage: <?php echo var_export($debugViewStage, true); ?>
</pre>
            <?php endif; ?>
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
                <div><?php echo app_escape($supplier['name']); ?> <span class="badge bg-secondary">Preferred</span></div>
            <?php else: ?>
                <div class="text-muted">No supplier assigned</div>
            <?php endif; ?>
            <?php if ($alternativeSupplierCount > 0): ?>
                <div class="text-muted small mt-2"><?php echo (int) $alternativeSupplierCount; ?> alternative supplier<?php echo $alternativeSupplierCount === 1 ? '' : 's'; ?> on file</div>
            <?php endif; ?>
            <div class="mt-3">
                <a class="btn btn-sm btn-outline-secondary" href="/modules/products/suppliers.php?id=<?php echo (int) $productId; ?>">Manage Suppliers</a>
            </div>
        </div>
    </div>
</div>

<?php if ($costBreakdown !== null): ?>
<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Cost Breakdown</h5>
        <span class="<?php echo $costConfigBadgeClass[$costConfigStatus]; ?>"><?php echo app_escape($costConfigLabels[$costConfigStatus]); ?></span>
    </div>
    <?php if ($costConfigStatus === 'missing_exchange_rate'): ?>
        <p class="text-muted small">This product's cost currency is set to <?php echo app_escape($costBreakdown['cost_currency']); ?> but has no exchange rate on file - Landed Cost cannot be calculated until one is added on Edit Product.</p>
    <?php elseif ($costConfigStatus === 'missing_currency_configuration'): ?>
        <p class="text-muted small">This product's cost is currently treated as MYR, but its supplier's default currency is <?php echo app_escape($supplier['currency']); ?> - confirm the Cost Currency on Edit Product if the cost was actually quoted in that currency.</p>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="text-muted small">Supplier Cost</div>
            <div>RM <?php echo app_escape(number_format($costBreakdown['supplier_cost'], 2)); ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Currency Rate</div>
            <div>
                <?php if ($costBreakdown['cost_currency'] === null): ?>
                    <span class="text-muted">MYR (no conversion needed)</span>
                <?php elseif ($costBreakdown['exchange_rate'] !== null): ?>
                    1 <?php echo app_escape($costBreakdown['cost_currency']); ?> = <?php echo app_escape(number_format($costBreakdown['exchange_rate'], 4)); ?> MYR
                <?php else: ?>
                    <?php echo $notConfiguredBadge; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Converted Cost</div>
            <div><?php echo $costBreakdown['converted_cost'] !== null ? ('RM ' . app_escape(number_format($costBreakdown['converted_cost'], 2))) : $notConfiguredBadge; ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Shipping Cost</div>
            <div><?php echo $costBreakdown['shipping_configured'] ? ('RM ' . app_escape(number_format($costBreakdown['shipping_cost'], 2))) : $notConfiguredBadge; ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Other Costs</div>
            <div>
                <?php echo $costBreakdown['other_costs_configured'] ? ('RM ' . app_escape(number_format($costBreakdown['other_costs'], 2))) : $notConfiguredBadge; ?>
                <?php if ($costBreakdown['other_costs_breakdown'] !== []): ?>
                    <div class="mt-1">
                        <?php foreach ($costBreakdown['other_costs_breakdown'] as $otherCostLine): ?>
                            <span class="badge bg-light text-dark border me-1 mb-1"><?php echo app_escape($otherCostLine['cost_type']); ?>: RM <?php echo app_escape(number_format($otherCostLine['amount'], 2)); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Total Landed Cost</div>
            <div>
                <?php if ($costBreakdown['landed_cost'] !== null): ?>
                    <strong>RM <?php echo app_escape(number_format($costBreakdown['landed_cost'], 2)); ?></strong>
                    <?php if ($costBreakdown['is_estimated']): ?>
                        <span class="badge bg-warning text-dark ms-1">Estimated</span>
                    <?php endif; ?>
                <?php else: ?>
                    <?php echo $notConfiguredBadge; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Selling Price</div>
            <div>RM <?php echo app_escape(number_format($costBreakdown['selling_price'], 2)); ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Gross Profit</div>
            <div><?php echo $costBreakdown['gross_profit'] !== null ? ('RM ' . app_escape(number_format($costBreakdown['gross_profit'], 2))) : $notConfiguredBadge; ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Gross Margin %</div>
            <div><?php echo $costBreakdown['gross_margin_percent'] !== null ? (app_escape(number_format($costBreakdown['gross_margin_percent'], 1)) . '%') : $notConfiguredBadge; ?></div>
        </div>
    </div>
    <?php if ($costBreakdown['landed_cost'] === null): ?>
        <p class="text-muted small mt-3 mb-0">Landed cost cannot be calculated - this product's cost currency is set but has no exchange rate on file. Add one on Edit Product.</p>
    <?php elseif ($costBreakdown['is_estimated']): ?>
        <p class="text-muted small mt-3 mb-0">This is an estimate - shipping and/or other costs are not yet configured for this product, so Landed Cost/Gross Profit/Margin reflect only Supplier Cost and Currency Conversion.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($costHistory !== []): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-1">Cost History</h5>
    <p class="text-muted small mb-3">Landed cost as frozen each time a supplier order for this product was received or completed - never recalculated after the fact.</p>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Supplier Order</th>
                    <th>Supplier</th>
                    <th class="text-end">Supplier Cost</th>
                    <th class="text-end">Shipping</th>
                    <th class="text-end">Other Costs</th>
                    <th class="text-end">Landed Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($costHistory) as $snapshot): ?>
                    <tr>
                        <td><?php echo app_escape($snapshot['captured_at']); ?></td>
                        <td>
                            <?php if ($canViewSupplierOrdersForHistory): ?>
                                <a href="/modules/supplier-orders/view.php?id=<?php echo (int) $snapshot['supplier_order_id']; ?>&return_url=<?php echo urlencode($returnContext); ?>"><?php echo app_escape($snapshot['purchase_number']); ?></a>
                            <?php else: ?>
                                <?php echo app_escape($snapshot['purchase_number']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $snapshot['supplier_name'] !== null ? app_escape($snapshot['supplier_name']) : '—'; ?></td>
                        <td class="text-end">RM <?php echo app_escape(number_format((float) $snapshot['converted_cost'], 2)); ?></td>
                        <td class="text-end"><?php echo $snapshot['shipping_cost'] !== null ? ('RM ' . app_escape(number_format((float) $snapshot['shipping_cost'], 2))) : '—'; ?></td>
                        <td class="text-end">RM <?php echo app_escape(number_format((float) $snapshot['other_costs'], 2)); ?></td>
                        <td class="text-end">RM <?php echo app_escape(number_format((float) $snapshot['landed_cost'], 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

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
