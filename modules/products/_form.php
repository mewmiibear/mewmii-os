<?php
/**
 * Shared product create/edit form. Included by modules/products/create.php and
 * modules/products/edit.php after they've prepared these variables:
 *
 * $isEdit, $productId, $product (array|null), $form (array), $error (string),
 * $brands, $categoriesTree, $collections, $tags, $suppliers, $attributes (each with
 * 'values'), $selectedTagIds, $existingAssignments, $variations, $mainImage,
 * $galleryImages, $statusOptions, $canManage, $lowStock (bool)
 *
 * Everything here still has a real `name` attribute and posts to the same URL on a
 * plain submit - the JS in assets/js/product-form.js progressively enhances it
 * (searchable selects, inline "+ Add" modals, live show/hide, AJAX-driven variation
 * builder in edit mode) but a full-page submit of the "Save" button still works.
 */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">
            <?php echo $isEdit ? 'Edit Product' : 'Add Product'; ?>
            <?php if ($isEdit): ?>
                <?php echo catalog_lifecycle_badge($product); ?>
            <?php endif; ?>
        </h2>
        <p class="text-muted mb-0">
            <?php echo $isEdit ? app_escape($product['sku']) : 'Create a new product in the catalog.'; ?>
            <?php if ($isEdit): ?>
                &middot; <?php echo app_escape(catalog_status_dot($product['status'])); ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($isEdit && $canManage): ?>
            <form method="post" action="/modules/products/duplicate.php" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Duplicate</button>
            </form>
            <?php if ($product['status'] !== 'archived'): ?>
                <form method="post" action="/modules/products/deactivate.php" class="d-inline" onsubmit="return confirm('Deactivate this product? It will be archived and hidden from active use, but not deleted.');">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Deactivate</button>
                </form>
            <?php endif; ?>
            <form method="post" action="/modules/products/delete.php" class="d-inline" onsubmit="return confirm('Permanently delete this product? This only works if it has no order/inventory/supplier history, and cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
            </form>
        <?php endif; ?>
        <?php if ($isEdit): ?>
            <a class="btn btn-outline-primary btn-sm" href="/modules/products/control-center.php?id=<?php echo (int) $productId; ?>">Open Product Control Center</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
    </div>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Product updated.</div>
<?php endif; ?>
<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Product created.</div>
<?php endif; ?>
<?php if (isset($_GET['duplicated'])): ?>
    <div class="alert alert-success">Product duplicated as a draft. Review it below before publishing.</div>
<?php endif; ?>
<?php if (isset($_GET['reopened'])): ?>
    <div class="alert alert-success">Preorder reopened. Regular Price now applies - Early Bird pricing does not return.</div>
<?php endif; ?>
<?php if (isset($_GET['deactivated'])): ?>
    <div class="alert alert-success">Product deactivated (archived).</div>
<?php endif; ?>
<?php if (isset($_GET['delete_error'])): ?>
    <div class="alert alert-danger"><?php echo app_escape($_GET['delete_error'] === '1' ? 'Failed to delete product.' : $_GET['delete_error']); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="product-form">
    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Basic Information</h5>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Product Image</label>
                <?php if ($mainImage !== null): ?>
                    <div class="mb-2 position-relative d-inline-block">
                        <img src="/<?php echo app_escape($mainImage['image_path']); ?>" alt="Main image" style="max-width: 140px; max-height: 140px;" class="border rounded d-block">
                    </div>
                    <?php if ($canManage): ?>
                        <label class="d-block small mb-2">
                            <input type="checkbox" name="remove_main_image" value="1"> Remove current main image
                        </label>
                    <?php endif; ?>
                <?php endif; ?>
                <input type="file" class="form-control image-file-input" name="main_image" id="main-image-input" accept="image/*" style="max-width: 400px;">
                <div class="form-text">Automatically resized, compressed, and converted to WebP.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label">SKU</label>
                <input type="text" class="form-control" name="sku" value="<?php echo app_escape($form['sku']); ?>" maxlength="100" required>
            </div>
            <div class="col-md-8">
                <label class="form-label">Product Name</label>
                <input type="text" class="form-control" name="name" value="<?php echo app_escape($form['name']); ?>" maxlength="255" required>
            </div>

            <div class="col-12">
                <label class="form-label">Short Description</label>
                <textarea class="form-control" name="short_description" rows="2" maxlength="500"><?php echo app_escape($form['short_description']); ?></textarea>
                <div class="form-text">Customer-facing summary - syncs to WooCommerce's short description.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="3"><?php echo app_escape($form['description']); ?></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label">Barcode</label>
                <input type="text" class="form-control" name="barcode" value="<?php echo app_escape($form['barcode']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Supplier SKU</label>
                <input type="text" class="form-control" name="supplier_sku" value="<?php echo app_escape($form['supplier_sku']); ?>" maxlength="100">
                <div class="form-text">The supplier's own code for this product - kept alongside the internal SKU above, never replacing it.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Internal Code</label>
                <input type="text" class="form-control" name="internal_code" value="<?php echo app_escape($form['internal_code']); ?>" maxlength="100">
            </div>

            <div class="col-12"><hr class="my-1"></div>

            <div class="col-md-6">
                <label class="form-label d-block">Product Type</label>
                <div class="d-flex gap-4">
                    <label class="form-check">
                        <input type="radio" class="form-check-input" name="catalog_type" value="simple" <?php echo $form['catalog_type'] === 'simple' ? 'checked' : ''; ?> <?php echo $isEdit && $product['catalog_type'] === 'variable' ? 'disabled title="Cannot switch back to simple while it has variations."' : ''; ?>>
                        <span class="form-check-label">Simple Product</span>
                    </label>
                    <label class="form-check">
                        <input type="radio" class="form-check-input" name="catalog_type" value="variable" <?php echo $form['catalog_type'] === 'variable' ? 'checked' : ''; ?>>
                        <span class="form-check-label">Variable Product</span>
                    </label>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Product Availability Type</label>
                <select class="form-select" name="product_type" id="availability-type" required>
                    <option value="ready_stock" <?php echo $form['product_type'] === 'ready_stock' ? 'selected' : ''; ?>>Ready Stock</option>
                    <option value="preorder" <?php echo $form['product_type'] === 'preorder' ? 'selected' : ''; ?>>Preorder</option>
                    <option value="early_bird" <?php echo $form['product_type'] === 'early_bird' ? 'selected' : ''; ?>>Early Bird</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Availability Override</label>
                <select class="form-select" name="availability_override">
                    <option value="auto" <?php echo $form['availability_override'] === 'auto' ? 'selected' : ''; ?>>Auto</option>
                    <option value="available" <?php echo $form['availability_override'] === 'available' ? 'selected' : ''; ?>>Available</option>
                    <option value="out_of_stock" <?php echo $form['availability_override'] === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                </select>
                <div class="form-text">Ready Stock: follows actual quantity unless set here. Preorder/Early Bird: never gated on quantity - stays purchasable at 0 stock unless manually set to Out of Stock.</div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Gallery Images</h5>
        <input type="file" class="form-control" name="gallery_images[]" id="gallery-add-input" accept="image/*" multiple style="max-width: 400px;">
        <?php if ($galleryImages !== []): ?>
            <div id="gallery-container" class="d-flex flex-wrap gap-3 mt-3">
                <?php foreach ($galleryImages as $image): ?>
                    <div class="gallery-item border rounded p-2 text-center" style="width: 110px;" draggable="true" data-image-id="<?php echo (int) $image['id']; ?>">
                        <img src="/<?php echo app_escape($image['image_path']); ?>" alt="" style="max-width: 90px; max-height: 90px;" class="mb-1">
                        <input type="hidden" name="gallery_sort_order[<?php echo (int) $image['id']; ?>]" value="<?php echo (int) $image['sort_order']; ?>">
                        <label class="small d-block">
                            <input type="checkbox" class="gallery-delete" name="gallery_delete[]" value="<?php echo (int) $image['id']; ?>"> Delete
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div id="gallery-container"></div>
        <?php endif; ?>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Organization</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label mb-1">Brand</label>
                    <button type="button" class="btn btn-sm btn-link p-0" data-add-modal="brand">+ Add Brand</button>
                </div>
                <select class="form-select" name="brand_id" id="brand-select" data-searchable="1">
                    <option value="">None</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?php echo (int) $brand['id']; ?>" <?php echo $form['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>><?php echo app_escape($brand['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label mb-1">Category</label>
                    <button type="button" class="btn btn-sm btn-link p-0" data-add-modal="category">+ Add Category</button>
                </div>
                <select class="form-select" name="category_id" id="category-select" data-searchable="1">
                    <option value="">None</option>
                    <?php foreach ($categoriesTree as $category): ?>
                        <option value="<?php echo (int) $category['id']; ?>" data-depth="<?php echo (int) $category['depth']; ?>" <?php echo $form['category_id'] === (string) $category['id'] ? 'selected' : ''; ?>>
                            <?php echo str_repeat('&mdash; ', $category['depth']) . app_escape($category['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label mb-1">Collection</label>
                    <button type="button" class="btn btn-sm btn-link p-0" data-add-modal="collection">+ Add Collection</button>
                </div>
                <select class="form-select" name="collection_id" id="collection-select" data-searchable="1">
                    <option value="">None</option>
                    <?php foreach ($collections as $collection): ?>
                        <option value="<?php echo (int) $collection['id']; ?>" <?php echo $form['collection_id'] === (string) $collection['id'] ? 'selected' : ''; ?>><?php echo app_escape($collection['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label mb-1">Tags</label>
                    <button type="button" class="btn btn-sm btn-link p-0" data-add-modal="tag">+ Add Tag</button>
                </div>
                <div id="tags-checkbox-list" data-filterable-checkboxes="1">
                    <?php foreach ($tags as $tag): ?>
                        <label class="checkbox-item me-3">
                            <input type="checkbox" name="tag_ids[]" value="<?php echo (int) $tag['id']; ?>" <?php echo in_array((int) $tag['id'], $selectedTagIds, true) ? 'checked' : ''; ?>>
                            <?php echo app_escape($tag['name']); ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if ($tags === []): ?>
                        <span class="text-muted small">No tags yet.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Supplier</label>
                <select class="form-select" name="supplier_id" id="supplier-select" data-searchable="1">
                    <option value="">None</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>><?php echo app_escape($supplier['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach ($statusOptions as $statusValue): ?>
                        <option value="<?php echo app_escape($statusValue); ?>" <?php echo $form['status'] === $statusValue ? 'selected' : ''; ?>><?php echo app_escape(ucfirst($statusValue)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Pricing</h5>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Cost Price (RM)</label>
                <input type="number" step="0.01" min="0" class="form-control" name="product_cost" value="<?php echo app_escape($form['product_cost']); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Regular Price (RM)</label>
                <input type="number" step="0.01" min="0" class="form-control" name="selling_price" value="<?php echo app_escape($form['selling_price']); ?>" required>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="sale_enabled" value="1" id="enable-sale" <?php echo $form['sale_enabled'] ? 'checked' : ''; ?>>
                    <span class="form-check-label">Enable Sale (Early Bird)</span>
                </label>
            </div>

            <div class="col-md-4 js-sale-fields">
                <label class="form-label">Sale Price (RM)</label>
                <input type="number" step="0.01" min="0" class="form-control" name="sale_price" value="<?php echo app_escape($form['sale_price']); ?>">
            </div>
            <div class="col-md-4 js-sale-fields">
                <label class="form-label">Early Bird Start Date</label>
                <input type="date" class="form-control" name="sale_start_date" value="<?php echo app_escape($form['sale_start_date']); ?>">
            </div>
            <div class="col-md-4 js-sale-fields">
                <label class="form-label">Early Bird Closing Date</label>
                <input type="date" class="form-control" name="preorder_closing_date" value="<?php echo app_escape($form['preorder_closing_date']); ?>">
                <div class="form-text">Before this date, Sale Price (Early Bird Price) applies. After this date, the sale ends and - for Preorder/Early Bird products - ordering pauses until manually reopened (see below).</div>
            </div>

            <?php
            $showPreorderReopenControl = false;
            $isWaitingForRelease = false;
            if ($isEdit && in_array($form['product_type'], ['preorder', 'early_bird'], true) && !empty($product['preorder_closing_date'])) {
                if (strtotime($product['preorder_closing_date']) < strtotime('today')) {
                    $showPreorderReopenControl = true;
                    $isWaitingForRelease = empty($product['preorder_reopened_at']);
                }
            }
            ?>
            <?php if ($showPreorderReopenControl): ?>
                <div class="col-12">
                    <?php if ($isWaitingForRelease): ?>
                        <span class="badge bg-secondary">Waiting for Release</span>
                        <span class="text-muted small">Early Bird has ended. Ordering is paused until you manually reopen it - it does not resume on its own, even once the Estimated Release Month arrives.</span>
                        <?php if ($canManage): ?>
                            <form method="post" action="/modules/products/reopen_preorder.php" class="mt-2">
                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>">
                                <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Reopen preorder at Regular Price? Early Bird pricing will not return.');">Open Preorder</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-success">Preorder Reopened</span>
                        <span class="text-muted small">Reopened <?php echo app_escape($product['preorder_reopened_at']); ?> - Regular Price applies, Early Bird pricing will not return.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Inventory</h5>
        <div class="row g-3">
            <div class="col-md-4 js-stock-ready js-simple-section">
                <label class="form-label">Available Stock</label>
                <input type="number" min="0" class="form-control" name="stock_quantity" value="<?php echo app_escape($form['stock_quantity']); ?>">
            </div>
            <div class="col-md-4 js-stock-ready">
                <label class="form-label">Minimum Stock</label>
                <input type="number" min="0" class="form-control" name="min_stock_threshold" value="<?php echo app_escape($form['min_stock_threshold']); ?>">
                <?php if ($isEdit && $lowStock): ?>
                    <span class="badge bg-warning text-dark mt-1">Low Stock</span>
                <?php endif; ?>
            </div>
            <div class="col-md-4 js-stock-ready">
                <label class="form-label">Target Stock Level</label>
                <input type="number" min="0" class="form-control" name="target_stock_level" value="<?php echo app_escape($form['target_stock_level']); ?>">
                <div class="form-text">Purchase Planning orders up to this quantity. Leave blank to exclude this product from Purchase Planning.</div>
            </div>
            <div class="col-md-4 js-stock-preorder">
                <label class="form-label">ETA (Estimated Arrival)</label>
                <input type="date" class="form-control" name="estimated_arrival_date" value="<?php echo app_escape($form['estimated_arrival_date']); ?>">
            </div>
            <div class="col-md-4 js-stock-preorder">
                <label class="form-label">MOQ</label>
                <input type="number" min="1" class="form-control" name="moq" value="<?php echo app_escape($form['moq']); ?>">
            </div>
            <div class="col-md-4 js-stock-preorder">
                <label class="form-label">Estimated Release Month</label>
                <input type="month" class="form-control" name="estimated_release_month" value="<?php echo app_escape($form['estimated_release_month']); ?>">
                <?php $releaseMonthDisplay = catalog_format_release_month($form['estimated_release_month'] !== '' ? $form['estimated_release_month'] : null); ?>
                <?php if ($releaseMonthDisplay !== null): ?>
                    <div class="form-text">Shown to customers as "<?php echo app_escape($releaseMonthDisplay); ?>".</div>
                <?php endif; ?>
            </div>
            <p class="text-muted small mb-0 js-stock-preorder">No stock quantity is requested here - stock arrives later via Supplier Orders receiving (marked as arrived), then gets manually allocated to outstanding orders from the Inventory page.</p>

            <div class="col-12"><hr class="my-1"></div>

            <div class="col-12">
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="has_expiry" value="1" id="has-expiry-checkbox" <?php echo $form['expiry_date'] !== '' ? 'checked' : ''; ?>>
                    <span class="form-check-label">Product has expiry date</span>
                </label>
            </div>
            <div class="col-md-3 js-expiry-fields">
                <label class="form-label">Expiry Date</label>
                <input type="date" class="form-control" name="expiry_date" value="<?php echo app_escape($form['expiry_date']); ?>">
                <div class="form-text">Only for products that physically expire (food, cosmetics, etc.) - independent from Early Bird/Preorder/Release Month/inventory.</div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4 js-variable-section">
        <h5 class="mb-1">Variable Product: Attribute Builder</h5>
        <p class="text-muted small">Character, Color, Size, and any other attribute are managed the same way - Character is not a separate list, it's just an attribute. Each value has its own editable SKU prefix (max 5 letters/numbers) used to build variation SKUs - see catalog_attribute_value_sku_code().</p>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <button type="button" class="btn btn-sm btn-link p-0" data-add-modal="attribute">+ Add Attribute (new global attribute)</button>
        </div>
        <div id="attribute-builder-blocks"></div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="add-attribute-block-btn">Add Another Attribute</button>
        <div class="mt-3">
            <button type="button" class="btn btn-primary" id="generate-variations-btn">Generate Variations</button>
        </div>
    </div>

    <div class="card p-4 mb-4 js-variable-section<?php echo ($isEdit && $variations !== []) ? '' : ' d-none'; ?>" id="variation-table-wrapper">
        <h5 class="mb-1">Variations</h5>
        <p class="text-muted small">Deleting a variation removes it completely - only possible if it has no order/inventory/supplier history. Otherwise deletion is blocked to protect that history.</p>

        <div class="border rounded p-3 mb-3 bg-light">
            <div class="fw-semibold mb-2">Bulk Edit Selected</div>
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Price Mode</label>
                    <select class="form-select form-select-sm" id="bulk-price-mode">
                        <option value="">No change</option>
                        <option value="inherit">Follow Product Price</option>
                        <option value="custom">Custom Price</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Custom Price (RM)</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="bulk-custom-price">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">Weight</label>
                    <input type="number" step="0.001" min="0" class="form-control form-control-sm" id="bulk-weight">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select class="form-select form-select-sm" id="bulk-status">
                        <option value="">No change</option>
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Image</label>
                    <input type="file" class="form-control form-control-sm" id="bulk-image" accept="image/*">
                </div>
                <div class="col-md-2 form-check mt-4">
                    <input type="checkbox" class="form-check-input" id="bulk-clear-barcode">
                    <label class="form-check-label small">Clear barcode</label>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="bulk-apply-btn">Apply to Selected</button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="variation-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Variation</th>
                        <th>SKU</th>
                        <th>Barcode</th>
                        <th>Supplier SKU</th>
                        <th>Weight</th>
                        <th>Price Mode / Price</th>
                        <th>Cost Price</th>
                        <th>Main Image</th>
                        <th>Stock Status</th>
                        <th>Status</th>
                        <?php if ($isEdit): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <button class="btn btn-primary btn-lg mt-2" type="submit"><?php echo $isEdit ? 'Save Changes' : 'Create Product'; ?></button>
</form>

<?php if ($isEdit): ?>
<div class="modal fade" id="variationGalleryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Variation Gallery</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Close-up photos, different angles, packaging, detail shots - separate from the variation's Main Image.</p>
                <input type="file" class="form-control mb-3" id="variation-gallery-add-input" accept="image/*" multiple>
                <div id="variation-gallery-modal-images" class="d-flex flex-wrap gap-3">
                    <p class="text-muted small mb-0">Loading&hellip;</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script id="product-form-data" type="application/json"><?php echo json_encode([
    'csrfToken' => app_csrf_token(),
    'isEdit' => $isEdit,
    'productId' => $productId,
    'parentSku' => $form['sku'],
    'attributes' => array_map(static function (array $attribute): array {
        return [
            'id' => (int) $attribute['id'],
            'name' => $attribute['name'],
            'values' => array_map(static function (array $value): array {
                return ['id' => (int) $value['id'], 'value' => $value['value'], 'code' => $value['code'] ?? null];
            }, $attribute['values']),
        ];
    }, $attributes),
    'existingAssignments' => $existingAssignments,
    'variations' => $variations,
    'urls' => [
        'createBrand' => '/modules/products/ajax/create_brand.php',
        'createCategory' => '/modules/products/ajax/create_category.php',
        'createCollection' => '/modules/products/ajax/create_collection.php',
        'createTag' => '/modules/products/ajax/create_tag.php',
        'createAttribute' => '/modules/products/ajax/create_attribute.php',
        'createAttributeValue' => '/modules/products/ajax/create_attribute_value.php',
        'updateAttributeValue' => '/modules/products/ajax/update_attribute_value.php',
        'saveAttributes' => '/modules/products/ajax/save_attributes.php',
        'generateVariations' => '/modules/products/ajax/generate_variations.php',
        'saveVariation' => '/modules/products/ajax/save_variation.php',
        'deleteVariation' => '/modules/products/ajax/delete_variation.php',
        'bulkVariationAction' => '/modules/products/ajax/bulk_variation_action.php',
        'uploadMainImage' => '/modules/products/ajax/upload_main_image.php',
        'addGalleryImages' => '/modules/products/ajax/add_gallery_images.php',
        'updateGallery' => '/modules/products/ajax/update_gallery.php',
        'addVariationGalleryImages' => '/modules/products/ajax/add_variation_gallery_images.php',
        'updateVariationGallery' => '/modules/products/ajax/update_variation_gallery.php',
        'getVariationImages' => '/modules/products/ajax/get_variation_images.php',
    ],
]); ?></script>
<?php
$productFormJsPath = __DIR__ . '/../../assets/js/product-form.js';
$productFormJsVersion = is_file($productFormJsPath) ? filemtime($productFormJsPath) : time();
?>
<script src="/assets/js/product-form.js?v=<?php echo (int) $productFormJsVersion; ?>"></script>
