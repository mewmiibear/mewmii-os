<?php

/**
 * Shared product create/edit form. Included by modules/products/create.php and
 * modules/products/edit.php after they've prepared these variables:
 *
 * $isEdit, $productId, $product (array|null), $form (array), $error (string), $pdo,
 * $brands, $categoriesTree, $collections, $tags, $suppliers, $attributes (each with
 * 'values'), $selectedTagIds, $existingAssignments, $variations, $mainImage,
 * $galleryImages, $statusOptions, $canManage, $lowStock (bool)
 *
 * Everything here still has a real `name` attribute and posts to the same URL on a
 * plain submit - the JS in assets/js/product-form.js progressively enhances it
 * (searchable selects, inline "+ Add" modals, live show/hide, AJAX-driven variation
 * builder in edit mode) but a full-page submit of the "Save" button still works.
 *
 * Shopify-style UI/UX redesign pass: two-column layout (assets/css/product-form.css),
 * reorganized into Basic Information / Pricing & Inventory / Variations (left column) and
 * Images / Publish (right column). This is a template/markup reshuffle only - every field
 * keeps its original name/id/class, no validation/save/image/inventory/WooCommerce/
 * variation logic changed. New product Status still defaults to Active (see
 * modules/products/create.php's $form default) and new variations still default to Active
 * (see includes/product_variations.php) - editing an existing product/variation still
 * shows and preserves its own real current status untouched.
 */
$productFormCssPath = __DIR__ . '/../../assets/css/product-form.css';
$productFormCssVersion = is_file($productFormCssPath) ? filemtime($productFormCssPath) : time();
?>
<link rel="stylesheet" href="/assets/css/product-form.css?v=<?php echo (int) $productFormCssVersion; ?>">

<div class="pf-page">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <?php echo $isEdit ? 'Edit Product' : 'Add Product'; ?>
                <?php if ($isEdit): ?>
                    <?php if (isset($_GET['debug_lifecycle'])): ?>
                        <?php
                        // --- TEMPORARY DEBUG INSTRUMENTATION (stale "Waiting Release" badge trace) ---
                        // Dumps state at the EXACT point/moment catalog_lifecycle_badge() is called to
                        // render the badge below - closes the loop on whether anything mutates $product
                        // between edit.php's fetch (top of file) and this render point. Remove this
                        // block (and the matching ones in reopen_preorder.php/edit.php/view.php) once
                        // the mismatch is found.
                        $debugFormStage = catalog_product_lifecycle_stage($product);
                        ?>
                        <pre style="background:#111;color:#0f0;padding:1rem;white-space:pre-wrap;font-size:.85rem;">[LIFECYCLE-DEBUG _form.php] right before catalog_lifecycle_badge($product) renders
product_type: <?php echo var_export($product['product_type'] ?? null, true); ?>

preorder_closing_date: <?php echo var_export($product['preorder_closing_date'] ?? null, true); ?>

preorder_reopened_at: <?php echo var_export($product['preorder_reopened_at'] ?? null, true); ?>

status: <?php echo var_export($product['status'] ?? null, true); ?>

availability_override: <?php echo var_export($product['availability_override'] ?? null, true); ?>

computed stage: <?php echo var_export($debugFormStage, true); ?>
</pre>
                    <?php endif; ?>
                    <?php echo catalog_lifecycle_badge($product); ?>
                <?php endif; ?>
            </h2>
            <p class="text-muted mb-0">
                <?php echo $isEdit ? app_escape($product['sku']) : 'Create a new product in the catalog.'; ?>
                <?php if ($isEdit): ?>
                    &middot; <?php echo app_escape(catalog_status_dot($product['status'])); ?>
                <?php endif; ?>
            </p>
            <?php if ($isEdit):
                $lastSyncLog = wc_client_get_last_sync_log($pdo, $productId);
            ?>
                <p class="text-muted small mb-0">
                    <?php if ($lastSyncLog === null): ?>
                        WooCommerce: not yet synced
                    <?php elseif ($lastSyncLog['status'] === 'success'): ?>
                        WooCommerce: <span class="text-success">&#10003; Synced <?php echo app_escape(wc_client_format_time_ago($lastSyncLog['created_at'])); ?></span>
                    <?php else: ?>
                        WooCommerce: <span class="text-danger">&#9888; Sync failed</span>
                        <?php if ($canManage): ?>
                <form method="post" action="/modules/products/sync_one.php" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>">
                    <button type="submit" class="btn btn-link btn-sm p-0 align-baseline">(Retry)</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        </p>
    <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <?php if ($isEdit && $canManage): ?>
                <form method="post" action="/modules/products/sync_one.php" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Sync this Product</button>
                </form>
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

    <?php if ($canManage): ?>
        <!-- Sticky Save bar - the button(s) live outside <form id="product-form"> (same as the
         header actions above), so they submit via the HTML5 form="" attribute instead of
         wrapping/duplicating the form. Triggers the exact same submit event (and
         entry-form-validation.js's validation) as the bottom button(s). -->
        <div class="sticky-top bg-white border-bottom py-2 mb-3" style="z-index: 1015; margin-left: -1.5rem; margin-right: -1.5rem; padding-left: 1.5rem; padding-right: 1.5rem;">
            <div class="pf-actionbar">
                <?php if (!$isEdit): ?>
                    <button class="btn btn-outline-secondary" type="submit" form="product-form" name="save_action" value="draft">Save Draft</button>
                    <button class="btn btn-primary" type="submit" form="product-form" name="save_action" value="publish">Create Product</button>
                <?php else: ?>
                    <button class="btn btn-primary" type="submit" form="product-form">Save Changes</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Product updated.</div>
    <?php endif; ?>
    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Product created.</div>
    <?php endif; ?>
    <?php if (($_GET['images_queued'] ?? '') === '1'): ?>
        <div class="alert alert-info py-2">
            Image(s) uploaded and queued for processing (compression/resize) - they'll appear below shortly.
            <?php if (app_has_permission('settings.manage')): ?>
                <a href="/modules/operations/job_queue.php">View Job Queue</a>.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (($_GET['wc_sync'] ?? '') === 'queued'): ?>
        <div class="alert alert-info py-2">
            Saved. WooCommerce sync has been queued and will run shortly.
            <?php if (app_has_permission('settings.manage')): ?>
                <a href="/modules/operations/job_queue.php">View Job Queue</a>.
            <?php endif; ?>
        </div>
    <?php elseif (($_GET['wc_sync'] ?? '') === 'synced'): ?>
        <?php if (($_GET['wc_sync_missing_images'] ?? '') === '1'): ?>
            <div class="alert alert-warning py-2">
                Saved and synced to WooCommerce, but one or more images were skipped because the stored file is missing on disk.
                <?php if (app_has_permission('settings.manage')): ?>
                    <a href="/modules/sync-logs/index.php">View Sync Logs</a>.
                <?php endif; ?>
                Re-upload the affected image(s) below and sync again.
            </div>
        <?php else: ?>
            <div class="alert alert-success py-2">Saved and synced to WooCommerce.</div>
        <?php endif; ?>
    <?php elseif (($_GET['wc_sync'] ?? '') === 'stale'): ?>
        <div class="alert alert-warning py-2">
            Product was saved locally, but WooCommerce has a newer edit than Mewmii OS has seen - sync was skipped to avoid overwriting it.
            <?php if (app_has_permission('settings.manage')): ?>
                <a href="/modules/sync-logs/index.php">View Sync Logs</a>.
            <?php endif; ?>
        </div>
    <?php elseif (($_GET['wc_sync'] ?? '') === 'failed'): ?>
        <div class="alert alert-warning py-2">
            Product was saved locally, but WooCommerce sync failed<?php echo ($_GET['wc_sync_reason'] ?? '') !== '' ? (' - ' . app_escape($_GET['wc_sync_reason'])) : '.'; ?>
            <?php if (app_has_permission('settings.manage')): ?>
                <a href="/modules/sync-logs/index.php">View Sync Logs</a>.
            <?php endif; ?>
        </div>
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

    <form method="post" enctype="multipart/form-data" id="product-form" data-validate="1" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <?php if (!$isEdit): ?>
            <!-- Populated by product-form.js right before submit (create mode, variable
             products only) - see initFormSubmitSync(). Edit mode persists attribute
             selections immediately via AJAX instead and never reads this field. -->
            <input type="hidden" name="attribute_selections" id="attribute-selections-field" value="">
        <?php endif; ?>

        <div class="pf-layout">
            <div class="pf-col-main">

                <div class="card pf-card">
                    <div class="pf-card-title"><span class="pf-card-step">1</span> Basic Information</div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Product Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="<?php echo app_escape($form['name']); ?>" maxlength="255" placeholder="e.g. Hello Kitty Plush 25cm" required>
                            <div class="invalid-feedback">Product name is required.</div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <label class="form-label mb-1">Brand</label>
                                <a class="small" href="/modules/catalog/index.php?tab=brands" target="_blank" rel="noopener">Manage &#8599;</a>
                            </div>
                            <select class="form-select" name="brand_id" id="brand-select" data-searchable="1">
                                <option value="">None</option>
                                <?php foreach ($brands as $brand): ?>
                                    <option value="<?php echo (int) $brand['id']; ?>" <?php echo $form['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>><?php echo app_escape($brand['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Short Description</label>
                            <textarea class="form-control" name="short_description" rows="2" maxlength="500" placeholder="One or two sentences shown to customers"><?php echo app_escape($form['short_description']); ?></textarea>
                            <div class="form-text">Customer-facing summary - syncs to WooCommerce's short description.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Describe this product..."><?php echo app_escape($form['description']); ?></textarea>
                        </div>

                        <div class="col-12">
                            <hr class="my-1">
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <label class="form-label mb-1">Category</label>
                                <a class="small" href="/modules/catalog/index.php?tab=categories" target="_blank" rel="noopener">Manage &#8599;</a>
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
                                <a class="small" href="/modules/catalog/index.php?tab=collections" target="_blank" rel="noopener">Manage &#8599;</a>
                            </div>
                            <select class="form-select" name="collection_id" id="collection-select" data-searchable="1">
                                <option value="">None</option>
                                <?php foreach ($collections as $collection): ?>
                                    <option value="<?php echo (int) $collection['id']; ?>" <?php echo $form['collection_id'] === (string) $collection['id'] ? 'selected' : ''; ?>><?php echo app_escape($collection['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <label class="form-label mb-1">Tags</label>
                                <a class="small" href="/modules/catalog/index.php?tab=tags" target="_blank" rel="noopener">Manage &#8599;</a>
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

                        <div class="col-12">
                            <hr class="my-1">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label d-block">Product Type</label>
                            <div class="d-flex gap-4">
                                <label class="form-check">
                                    <input type="radio" class="form-check-input" name="catalog_type" value="simple" id="pf-catalog-type-simple" data-was-variable="<?php echo ($isEdit && $product['catalog_type'] === 'variable') ? '1' : '0'; ?>" <?php echo $form['catalog_type'] === 'simple' ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Simple Product</span>
                                </label>
                                <label class="form-check">
                                    <input type="radio" class="form-check-input" name="catalog_type" value="variable" <?php echo $form['catalog_type'] === 'variable' ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Variable Product</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Product Availability Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="product_type" id="availability-type" required>
                                <option value="ready_stock" <?php echo $form['product_type'] === 'ready_stock' ? 'selected' : ''; ?>>Ready Stock</option>
                                <option value="preorder" <?php echo $form['product_type'] === 'preorder' ? 'selected' : ''; ?>>Preorder</option>
                                <option value="early_bird" <?php echo $form['product_type'] === 'early_bird' ? 'selected' : ''; ?>>Early Bird</option>
                            </select>
                            <div class="invalid-feedback">Availability type is required.</div>
                        </div>
                    </div>
                </div>

                <div class="card pf-card">
                    <div class="pf-card-title"><span class="pf-card-step">2</span> Pricing &amp; Inventory</div>

                    <div class="pf-group">
                        <div class="pf-group-label">Identifiers</div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">SKU <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="sku" value="<?php echo app_escape($form['sku']); ?>" maxlength="100" placeholder="HK-PLUSH-001" required>
                                <div class="invalid-feedback">SKU is required.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Supplier</label>
                                <select class="form-select" name="supplier_id" id="supplier-select" data-searchable="1">
                                    <option value="">None</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>><?php echo app_escape($supplier['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Supplier SKU</label>
                                <input type="text" class="form-control" name="supplier_sku" value="<?php echo app_escape($form['supplier_sku']); ?>" maxlength="100">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Barcode</label>
                                <input type="text" class="form-control" name="barcode" value="<?php echo app_escape($form['barcode']); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="pf-group">
                        <div class="pf-group-label">Supplier Price</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Supplier Price <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" class="form-control" name="product_cost" id="pf-supplier-price" value="<?php echo app_escape($form['product_cost']); ?>" placeholder="0.00" required>
                                <div class="invalid-feedback">Supplier price is required.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Supplier Currency</label>
                                <select class="form-select" name="cost_currency" id="cost-currency-select">
                                    <?php foreach (PRODUCT_COST_CURRENCY_OPTIONS as $currencyOption): ?>
                                        <option value="<?php echo app_escape($currencyOption); ?>" <?php echo $form['cost_currency'] === $currencyOption ? 'selected' : ''; ?>><?php echo app_escape($currencyOption); ?></option>
                                    <?php endforeach; ?>
                                    <option value="OTHER" <?php echo $form['cost_currency'] === 'OTHER' ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <input type="text" class="form-control mt-2<?php echo $form['cost_currency'] !== 'OTHER' ? ' d-none' : ''; ?>" id="cost-currency-other" name="cost_currency_other" maxlength="10" placeholder="e.g. KRW" value="<?php echo app_escape($form['cost_currency_other']); ?>">
                                <div class="form-text" id="pf-supplier-rate-display">Rate looked up automatically from Settings &gt; Currency Exchange Rates.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">Supplier Cost (RM)</label>
                                <div class="form-control-plaintext fw-semibold" id="pf-supplier-cost-rm">&mdash;</div>
                            </div>
                        </div>
                    </div>

                    <div class="pf-group">
                        <div class="pf-group-label">Original Price <span class="text-muted fw-normal text-lowercase">(brand/official retail reference)</span></div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Original Price</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="original_price" id="pf-original-price" value="<?php echo app_escape($form['original_price']); ?>" placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Original Currency</label>
                                <select class="form-select" name="original_currency" id="original-currency-select">
                                    <?php foreach (CURRENCY_RATE_OPTIONS as $currencyOption): ?>
                                        <option value="<?php echo app_escape($currencyOption); ?>" <?php echo $form['original_currency'] === $currencyOption ? 'selected' : ''; ?>><?php echo app_escape($currencyOption); ?></option>
                                    <?php endforeach; ?>
                                    <option value="OTHER" <?php echo $form['original_currency'] === 'OTHER' ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <input type="text" class="form-control mt-2<?php echo $form['original_currency'] !== 'OTHER' ? ' d-none' : ''; ?>" id="original-currency-other" name="original_currency_other" maxlength="10" placeholder="e.g. KRW" value="<?php echo app_escape($form['original_currency_other']); ?>">
                                <div class="form-text" id="pf-original-rate-display">Rate looked up automatically from Settings &gt; Currency Exchange Rates.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">Original Price (RM)</label>
                                <div class="form-control-plaintext fw-semibold" id="pf-original-price-rm">&mdash;</div>
                            </div>
                        </div>
                    </div>

                    <div class="pf-group">
                        <div class="pf-group-label">Market Price <span class="text-muted fw-normal text-lowercase">(calculated from Original Price)</span></div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Market Currency</label>
                                <select class="form-select" name="market_currency" id="market-currency-select">
                                    <?php foreach (CURRENCY_RATE_OPTIONS as $currencyOption): ?>
                                        <option value="<?php echo app_escape($currencyOption); ?>" <?php echo $form['market_currency'] === $currencyOption ? 'selected' : ''; ?>><?php echo app_escape($currencyOption); ?></option>
                                    <?php endforeach; ?>
                                    <option value="OTHER" <?php echo $form['market_currency'] === 'OTHER' ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <input type="text" class="form-control mt-2<?php echo $form['market_currency'] !== 'OTHER' ? ' d-none' : ''; ?>" id="market-currency-other" name="market_currency_other" maxlength="10" placeholder="e.g. KRW" value="<?php echo app_escape($form['market_currency_other']); ?>">
                                <div class="form-text" id="pf-market-rate-display">Rate looked up automatically from Settings &gt; Currency Exchange Rates.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">Market Price (RM)</label>
                                <div class="form-control-plaintext fw-semibold" id="pf-market-price-rm">&mdash;</div>
                                <div class="form-text">Original Price &times; Market Exchange Rate - no separate Market Price amount is entered.</div>
                            </div>
                        </div>
                    </div>

                    <div class="pf-group">
                        <div class="pf-group-label">Weight &amp; Shipping</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Weight (grams)</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="weight_grams" id="pf-weight-grams" value="<?php echo app_escape($form['weight_grams']); ?>" placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <label class="form-label mb-1">Shipping Origin</label>
                                    <a class="small" href="/modules/settings/shipping_rates.php" target="_blank" rel="noopener">Manage &#8599;</a>
                                </div>
                                <select class="form-select" name="shipping_origin_country_id" id="pf-shipping-origin">
                                    <option value="">None</option>
                                    <?php foreach ($shippingCountries as $shippingCountry): ?>
                                        <option value="<?php echo (int) $shippingCountry['id']; ?>" data-rate-per-gram="<?php echo app_escape((string) $shippingCountry['rate_per_gram']); ?>" <?php echo $form['shipping_origin_country_id'] === (string) $shippingCountry['id'] ? 'selected' : ''; ?>><?php echo app_escape($shippingCountry['country_name']); ?> (RM <?php echo app_escape(number_format((float) $shippingCountry['rate_per_gram'], 4)); ?>/g)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">Estimated Shipping Cost (RM)</label>
                                <div class="form-control-plaintext fw-semibold" id="pf-shipping-cost-rm">&mdash;</div>
                            </div>
                        </div>
                    </div>

                    <div class="pf-group">
                        <div class="pf-group-label">Cost Summary</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="text-muted small">Supplier Cost (RM)</div>
                                <div class="fw-semibold" id="pf-summary-supplier-cost">&mdash;</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">Shipping Cost (RM)</div>
                                <div class="fw-semibold" id="pf-summary-shipping-cost">&mdash;</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">Estimated Cost (RM)</div>
                                <div class="fw-semibold" id="pf-summary-estimated-cost">&mdash;</div>
                            </div>
                        </div>
                    </div>

                    <div class="pf-group">
                        <div class="pf-group-label">Selling Price</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Selling Price (RM) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" class="form-control" name="selling_price" id="pf-selling-price-input" value="<?php echo app_escape($form['selling_price']); ?>" placeholder="0.00" required>
                                <div class="invalid-feedback">Regular price is required.</div>
                                <div class="form-text">Manually controlled - never auto-filled or overwritten by the calculation below.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">&nbsp;</label>
                                <label class="form-check">
                                    <input type="checkbox" class="form-check-input" name="sale_enabled" value="1" id="enable-sale" <?php echo $form['sale_enabled'] ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Enable Sale (Early Bird)</span>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">Profit / Margin</label>
                                <div class="fw-semibold"><span id="pf-profit">&mdash;</span> <span class="text-muted small">(<span id="pf-margin">&mdash;</span>)</span></div>
                            </div>

                            <div class="col-md-4 js-sale-fields">
                                <label class="form-label">Sale Price (RM)</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="sale_price" value="<?php echo app_escape($form['sale_price']); ?>" placeholder="0.00">
                            </div>
                            <div class="col-md-4 js-sale-fields">
                                <label class="form-label">Early Bird Start Date</label>
                                <input type="date" class="form-control" name="sale_start_date" value="<?php echo app_escape($form['sale_start_date']); ?>">
                            </div>
                            <div class="col-md-4 js-sale-fields">
                                <label class="form-label">Early Bird Closing Date</label>
                                <input type="date" class="form-control" name="preorder_closing_date" value="<?php echo app_escape($form['preorder_closing_date']); ?>">
                                <div class="form-text">After this date, ordering pauses for Preorder/Early Bird until manually reopened.</div>
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

                    <div class="pf-group">
                        <div class="pf-group-label">Stock</div>
                        <div class="row g-3">
                            <div class="col-md-4 js-stock-ready js-simple-section">
                                <label class="form-label">Available Stock</label>
                                <input type="number" min="0" class="form-control" name="stock_quantity" value="<?php echo app_escape($form['stock_quantity']); ?>" placeholder="0">
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
                                <div class="form-text">Purchase Planning orders up to this quantity. Leave blank to exclude.</div>
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
                            <p class="text-muted small mb-0 js-stock-preorder">No stock quantity is requested here - stock arrives later via Supplier Orders receiving, then gets manually allocated from the Inventory page.</p>
                        </div>
                    </div>

                    <div class="pf-group">
                        <div class="pf-group-label">Availability</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Availability Override</label>
                                <select class="form-select" name="availability_override">
                                    <option value="auto" <?php echo $form['availability_override'] === 'auto' ? 'selected' : ''; ?>>Auto</option>
                                    <option value="available" <?php echo $form['availability_override'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="out_of_stock" <?php echo $form['availability_override'] === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                                </select>
                                <div class="form-text">Ready Stock: follows actual quantity unless set here. Preorder/Early Bird: stays purchasable at 0 stock unless manually set to Out of Stock.</div>
                            </div>
                        </div>
                    </div>

                    <details class="pf-advanced" <?php echo ($form['internal_code'] !== '' || $form['expiry_date'] !== '') ? 'open' : ''; ?>>
                        <summary>Advanced options</summary>
                        <div class="pf-advanced-body row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Internal Code</label>
                                <input type="text" class="form-control" name="internal_code" value="<?php echo app_escape($form['internal_code']); ?>" maxlength="100">
                            </div>
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" class="form-check-input" name="has_expiry" value="1" id="has-expiry-checkbox" <?php echo $form['expiry_date'] !== '' ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Product has expiry date</span>
                                </label>
                            </div>
                            <div class="col-md-4 js-expiry-fields">
                                <label class="form-label">Expiry Date</label>
                                <input type="date" class="form-control" name="expiry_date" value="<?php echo app_escape($form['expiry_date']); ?>">
                                <div class="form-text">Only for products that physically expire (food, cosmetics, etc.).</div>
                            </div>
                        </div>
                    </details>
                </div>

                <div class="card pf-card js-variable-section">
                    <div class="pf-card-title"><span class="pf-card-step">3</span> Variations</div>
                    <p class="pf-card-hint">Character, Color, Size, and any other attribute are managed the same way. Each value's SKU prefix can still be edited inline below, but new attributes and values are added from Catalog &gt; Attributes, not here. New variations are created Active by default. <a href="/modules/catalog/index.php?tab=attributes" target="_blank" rel="noopener">Manage Attributes &#8599;</a></p>
                    <div id="attribute-builder-blocks"></div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-outline-secondary" id="add-attribute-block-btn">+ Add Attribute</button>
                        <button type="button" class="btn btn-primary" id="generate-variations-btn">Generate Variations</button>
                        <?php if ($isEdit): ?>
                            <button type="button" class="btn btn-outline-primary" id="add-variation-manual-btn">+ Add Variation Manually</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($isEdit): ?>
                        <p class="pf-card-hint mt-2 mb-0">"Generate Variations" creates every combination of the attributes above. Use "+ Add Variation Manually" instead when only some combinations actually exist as real products (e.g. Kuromi 20cm exists but My Melody 20cm doesn't).</p>
                    <?php endif; ?>
                </div>

                <div class="card pf-card js-variable-section" id="variation-table-wrapper">
                    <div class="pf-variation-toolbar">
                        <div>
                            <div class="pf-card-title mb-1">Generated Variations</div>
                            <p class="pf-card-hint mb-0">Deleting a variation removes it completely if it has no order/inventory/supplier/customer-storage history - otherwise it's archived (deactivated) instead, so historical records keep showing exactly what they always have.</p>
                        </div>
                    </div>

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

            </div><!-- /.pf-col-main -->

            <div class="pf-col-side">

                <div class="card pf-card">
                    <div class="pf-card-title">Images</div>

                    <label class="form-label">Main Image</label>
                    <div class="pf-dropzone pf-main-dropzone<?php echo $mainImage !== null ? ' has-image' : ''; ?>" id="pf-main-image-dropzone">
                        <img alt="Main image" class="pf-main-dropzone-preview" id="pf-main-image-preview" <?php echo $mainImage !== null ? 'src="/' . app_escape($mainImage['image_path']) . '"' : ''; ?>>
                        <div class="pf-dropzone-hint">
                            <i class="bi bi-cloud-arrow-up"></i>
                            <strong>Drop image here</strong>
                            or click to upload
                        </div>
                        <input type="file" class="form-control image-file-input" name="main_image" id="main-image-input" accept="image/*">
                    </div>
                    <?php if ($mainImage !== null && $canManage): ?>
                        <div class="pf-main-image-actions">
                            <label class="btn btn-outline-secondary btn-sm mb-0" for="main-image-input">Change image</label>
                            <label class="btn btn-outline-danger btn-sm mb-0">
                                <input type="checkbox" name="remove_main_image" value="1" class="d-none"> Remove image
                            </label>
                        </div>
                    <?php endif; ?>
                    <div class="form-text mt-2">Automatically resized, compressed, and converted to WebP.</div>

                    <hr class="my-3">

                    <label class="form-label">Gallery Images</label>
                    <div class="pf-dropzone pf-gallery-dropzone" id="pf-gallery-dropzone">
                        <div class="pf-dropzone-hint">
                            <i class="bi bi-images"></i>
                            <strong>Drop images here</strong>
                            or click to upload
                        </div>
                        <input type="file" class="form-control" name="gallery_images[]" id="gallery-add-input" accept="image/*" multiple>
                    </div>
                    <div class="form-text mt-2">Additional photos - angles, packaging, in use, etc.</div>
                    <?php if ($galleryImages !== []): ?>
                        <div id="gallery-container" class="pf-gallery-grid">
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
                        <div id="gallery-container" class="pf-gallery-grid"></div>
                    <?php endif; ?>
                </div>

                <div class="card pf-card">
                    <div class="pf-card-title">Publish</div>
                    <div class="pf-publish-row">
                        <label for="pf-status-select">Status</label>
                        <select class="form-select form-select-sm" name="status" id="pf-status-select">
                            <?php foreach ($statusOptions as $statusValue): ?>
                                <option value="<?php echo app_escape($statusValue); ?>" <?php echo $form['status'] === $statusValue ? 'selected' : ''; ?>><?php echo app_escape(ucfirst($statusValue)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pf-publish-row">
                        <span>Availability</span>
                        <span class="pf-availability-readout" id="pf-availability-readout">
                            <?php
                            $availabilityLabels = ['ready_stock' => 'Ready Stock', 'preorder' => 'Preorder', 'early_bird' => 'Early Bird'];
                            echo app_escape($availabilityLabels[$form['product_type']] ?? 'Ready Stock');
                            ?>
                        </span>
                    </div>
                    <?php if (!$isEdit): ?>
                        <div class="form-text mt-2">New products are Active by default and visible immediately - switch to Draft above (or use "Save Draft") if it's not ready yet.</div>
                    <?php else: ?>
                        <div class="form-text mt-2">Change anytime - e.g. set to Draft/Hidden to temporarily pull a product without deleting it.</div>
                    <?php endif; ?>
                </div>

            </div><!-- /.pf-col-side -->
        </div><!-- /.pf-layout -->

        <div class="d-flex gap-2 mt-2">
            <?php if (!$isEdit): ?>
                <button class="btn btn-primary btn-lg" type="submit" name="save_action" value="publish">Create Product</button>
                <button class="btn btn-outline-secondary btn-lg" type="submit" name="save_action" value="draft">Save Draft</button>
            <?php else: ?>
                <button class="btn btn-primary btn-lg" type="submit">Save Changes</button>
            <?php endif; ?>
        </div>
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

        <div class="modal fade" id="addVariationManualModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Variation Manually</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Pick only the attribute values this specific variation actually has - you don't have to create every possible combination.</p>
                        <div id="add-variation-attribute-selects"></div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Barcode</label>
                                <input type="text" class="form-control form-control-sm" id="add-variation-barcode">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Supplier SKU</label>
                                <input type="text" class="form-control form-control-sm" id="add-variation-supplier-sku" placeholder="Blank = use parent SKU">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Weight</label>
                                <select class="form-select form-select-sm" id="add-variation-weight-mode">
                                    <option value="inherit">Follow Main Product Weight</option>
                                    <option value="custom">Custom Weight</option>
                                </select>
                                <input type="number" step="0.001" min="0" class="form-control form-control-sm mt-1 d-none" id="add-variation-weight" placeholder="grams">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Status</label>
                                <select class="form-select form-select-sm" id="add-variation-status">
                                    <option value="active" selected>Active</option>
                                    <option value="draft">Draft</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="add-variation-manual-submit-btn">Add Variation</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editVariationAttributesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Variation Attributes</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Change which attribute values define this variation, or set one to "&mdash; None &mdash;" to remove it from the variation entirely. SKU, pricing, weight, and history are untouched.</p>
                        <div id="edit-variation-attribute-selects"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="edit-variation-attributes-submit-btn">Save Attributes</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div><!-- /.pf-page -->

<script id="product-form-data" type="application/json">
    <?php echo json_encode([
        'csrfToken' => app_csrf_token(),
        'isEdit' => $isEdit,
        'productId' => $productId,
        'parentSku' => $form['sku'],
        // Phase 9G (Inline Pricing & Inventory Calculation UI) - every configured rate for all
        // three rate types (see includes/currency_rates.php), plus shipping_rate_countries keyed
        // by id - lets the inline calculator below recompute Supplier/Original/Market Price RM
        // and Estimated Shipping Cost instantly in the browser as fields change, with zero
        // server round-trips per keystroke. Mirrors includes/pricing_engine.php's own formulas
        // exactly so this preview never disagrees with what gets saved.
        'pricingCalculator' => [
            'systemSellingCurrency' => SYSTEM_SELLING_CURRENCY,
            'rates' => $currencyRateMaps,
            'shippingRatesByCountryId' => array_column($shippingCountries, 'rate_per_gram', 'id'),
        ],
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
        // Sprint 11: only ever non-empty in create mode, right after a failed submit for a
        // variable product - lets renderPreviewTable() restore the user's edited SKU/barcode/
        // price/etc via config.previewFieldOverrides instead of leaving the table empty.
        'previewFieldOverrides' => $previewFieldOverrides ?? [],
        'urls' => [
            // No createBrand/createCategory/createCollection/createTag/createAttribute/
            // createAttributeValue here - Catalog Management (modules/attributes,
            // modules/{categories,brands,collections,tags}) is the only place those get created
            // now. updateAttributeValue is kept: editing an existing value's SKU prefix inline
            // while building variations is still supported (see assets/js/product-form.js).
            'updateAttributeValue' => '/modules/products/ajax/update_attribute_value.php',
            'saveAttributes' => '/modules/products/ajax/save_attributes.php',
            'generateVariations' => '/modules/products/ajax/generate_variations.php',
            'saveVariation' => '/modules/products/ajax/save_variation.php',
            'deleteVariation' => '/modules/products/ajax/delete_variation.php',
            'addVariationManual' => '/modules/products/ajax/add_variation_manual.php',
            'updateVariationAttributes' => '/modules/products/ajax/update_variation_attributes.php',
            'bulkVariationAction' => '/modules/products/ajax/bulk_variation_action.php',
            'uploadMainImage' => '/modules/products/ajax/upload_main_image.php',
            'addGalleryImages' => '/modules/products/ajax/add_gallery_images.php',
            'updateGallery' => '/modules/products/ajax/update_gallery.php',
            'addVariationGalleryImages' => '/modules/products/ajax/add_variation_gallery_images.php',
            'updateVariationGallery' => '/modules/products/ajax/update_variation_gallery.php',
            'getVariationImages' => '/modules/products/ajax/get_variation_images.php',
        ],
    ]); ?>
</script>
<?php
$productFormJsPath = __DIR__ . '/../../assets/js/product-form.js';
$productFormJsVersion = is_file($productFormJsPath) ? filemtime($productFormJsPath) : time();
$entryFormJsPath = __DIR__ . '/../../assets/js/entry-form-validation.js';
$entryFormJsVersion = is_file($entryFormJsPath) ? filemtime($entryFormJsPath) : time();
?>
<script src="/assets/js/product-form.js?v=<?php echo (int) $productFormJsVersion; ?>"></script>
<script src="/assets/js/entry-form-validation.js?v=<?php echo (int) $entryFormJsVersion; ?>"></script>
<script>
    (function() {
        // Phase 9E (Product Weight & Variation SKU Logic) - switching an existing variable
        // product to Simple now archives its variations automatically on save (see
        // modules/products/edit.php) instead of the old hard block that disabled this radio
        // entirely - a plain confirm here is the only warning needed before submit, since the
        // archive itself is server-side and reversible in effect (variations are archived, never
        // deleted - see variation_archive_all_for_product()).
        var simpleRadio = document.getElementById('pf-catalog-type-simple');
        if (simpleRadio && simpleRadio.dataset.wasVariable === '1') {
            simpleRadio.addEventListener('change', function() {
                if (simpleRadio.checked && !confirm('Switch to Simple Product? All existing variations will be archived (not deleted) - their weight, Supplier SKU, pricing, and order/history data are kept.')) {
                    simpleRadio.checked = false;
                    var variableRadio = document.querySelector('input[name="catalog_type"][value="variable"]');
                    if (variableRadio) {
                        variableRadio.checked = true;
                    }
                }
            });
        }

        var pricingConfigEl = document.getElementById('product-form-data');
        var pricingConfig = pricingConfigEl ? JSON.parse(pricingConfigEl.textContent || '{}') : {};
        var systemSellingCurrency = pricingConfig.pricingCalculator && pricingConfig.pricingCalculator.systemSellingCurrency ?
            pricingConfig.pricingCalculator.systemSellingCurrency :
            'MYR';
        var pricingCalculatorData = pricingConfig.pricingCalculator || {
            rates: {},
            shippingRatesByCountryId: {}
        };
        var pricingRates = pricingCalculatorData.rates || {};
        var shippingRatesByCountryId = pricingCalculatorData.shippingRatesByCountryId || {};

        // Phase 7C.1 (Product Cost Data Entry) - toggle-by-classList shape already used by
        // product-form.js's own js-sale-fields/enable-sale toggle. Phase 9D (Pricing Engine)
        // reuses the exact same function for the two new currency selects (Original/Market)
        // instead of copy-pasting it a second and third time.
        function setupCurrencyToggle(selectId, otherInputId, foreignClass, rateLabelId) {
            var currencySelect = document.getElementById(selectId);
            var currencyOtherInput = document.getElementById(otherInputId);
            var rateLabel = document.getElementById(rateLabelId);

            function apply() {
                if (!currencySelect) {
                    return;
                }
                var isOther = currencySelect.value === 'OTHER';
                var isSelling = currencySelect.value === systemSellingCurrency;

                if (currencyOtherInput) {
                    currencyOtherInput.classList.toggle('d-none', !isOther);
                }
                document.querySelectorAll('.' + foreignClass).forEach(function(el) {
                    el.classList.toggle('d-none', isSelling);
                });
                if (rateLabel) {
                    rateLabel.textContent = isOther ? ((currencyOtherInput && currencyOtherInput.value) || 'unit') : currencySelect.value;
                }
            }

            if (currencySelect) {
                currencySelect.addEventListener('change', apply);
            }
            if (currencyOtherInput) {
                currencyOtherInput.addEventListener('input', apply);
            }
            apply();
        }

        setupCurrencyToggle('cost-currency-select', 'cost-currency-other', 'js-cost-currency-foreign', 'cost-currency-rate-label');
        setupCurrencyToggle('original-currency-select', 'original-currency-other', 'js-original-currency-foreign', 'original-currency-rate-label');
        setupCurrencyToggle('market-currency-select', 'market-currency-other', 'js-market-currency-foreign', 'market-currency-rate-label');

        // Phase 9G (Inline Pricing & Inventory Calculation UI) - mirrors includes/pricing_engine.php's
        // formulas exactly (Supplier/Original/Market Price converted to the selling currency = amount x that rate_type's rate;
        // Market Price reuses the Original Price amount, never a separate amount; Estimated
        // Shipping Cost = weight x rate_per_gram; Estimated Cost = Supplier Cost + Shipping;
        // Profit = Selling Price - Estimated Cost) so this preview never disagrees with what's
        // actually saved. Recomputed on every relevant field's input/change - no server round-trip.

        function pfResolveCurrencyCode(selectId, otherId) {
            var select = document.getElementById(selectId);
            if (!select) {
                return systemSellingCurrency;
            }
            if (select.value === 'OTHER') {
                var other = document.getElementById(otherId);
                return other ? other.value.trim().toUpperCase() : '';
            }
            return select.value;
        }

        function pfEffectiveRate(currencyCode, rateMap) {
            if (!currencyCode || currencyCode === systemSellingCurrency) {
                return 1;
            }
            var rate = (rateMap || {})[currencyCode];
            return (typeof rate === 'number') ? rate : null;
        }

        function pfFormatRM(value) {
            return 'RM ' + value.toFixed(2);
        }

        function pfUpdateRateDisplay(elementId, label, currencyCode, rate) {
            var el = document.getElementById(elementId);
            if (!el) {
                return;
            }
            if (!currencyCode || currencyCode === systemSellingCurrency) {
                el.textContent = 'Already in ' + systemSellingCurrency + ' - no conversion needed.';
            } else if (rate === null) {
                el.innerHTML = label + ': ' + currencyCode + ' (<span class="text-danger">Exchange rate not configured</span>)';
            } else {
                el.textContent = label + ': ' + currencyCode + ' (current rate: ' + rate.toFixed(6) + ')';
            }
        }

        function recomputeInlinePricing() {
            var supplierAmountInput = document.getElementById('pf-supplier-price');
            var originalAmountInput = document.getElementById('pf-original-price');
            var weightInput = document.getElementById('pf-weight-grams');
            var shippingSelect = document.getElementById('pf-shipping-origin');
            var sellingPriceInput = document.getElementById('pf-selling-price-input');

            var supplierAmount = supplierAmountInput ? parseFloat(supplierAmountInput.value) : NaN;
            var originalAmount = originalAmountInput ? parseFloat(originalAmountInput.value) : NaN;
            var weight = weightInput ? parseFloat(weightInput.value) : NaN;
            var sellingPrice = sellingPriceInput ? parseFloat(sellingPriceInput.value) : NaN;

            var supplierCurrency = pfResolveCurrencyCode('cost-currency-select', 'cost-currency-other');
            var originalCurrency = pfResolveCurrencyCode('original-currency-select', 'original-currency-other');
            var marketCurrency = pfResolveCurrencyCode('market-currency-select', 'market-currency-other');

            var supplierRate = pfEffectiveRate(supplierCurrency, pricingRates.supplier);
            var originalRate = pfEffectiveRate(originalCurrency, pricingRates.original);
            var marketRate = pfEffectiveRate(marketCurrency, pricingRates.market);

            pfUpdateRateDisplay('pf-supplier-rate-display', 'Supplier Currency', supplierCurrency, supplierRate);
            pfUpdateRateDisplay('pf-original-rate-display', 'Original Currency', originalCurrency, originalRate);
            pfUpdateRateDisplay('pf-market-rate-display', 'Market Currency', marketCurrency, marketRate);

            var supplierCostRM = (!isNaN(supplierAmount) && supplierRate !== null) ? (supplierAmount * supplierRate) : null;
            var originalPriceRM = (!isNaN(originalAmount) && originalRate !== null) ? (originalAmount * originalRate) : null;
            // Market Price reuses the Original Price amount - never a separate amount input.
            var marketPriceRM = (!isNaN(originalAmount) && marketRate !== null) ? (originalAmount * marketRate) : null;

            var supplierCostDisplay = document.getElementById('pf-supplier-cost-rm');
            var originalPriceDisplay = document.getElementById('pf-original-price-rm');
            var marketPriceDisplay = document.getElementById('pf-market-price-rm');
            if (supplierCostDisplay) {
                supplierCostDisplay.textContent = supplierCostRM !== null ? pfFormatRM(supplierCostRM) : '—';
            }
            if (originalPriceDisplay) {
                originalPriceDisplay.textContent = originalPriceRM !== null ? pfFormatRM(originalPriceRM) : '—';
            }
            if (marketPriceDisplay) {
                marketPriceDisplay.textContent = marketPriceRM !== null ? pfFormatRM(marketPriceRM) : '—';
            }

            // Estimated Shipping Cost = weight x rate_per_gram for the selected origin.
            var shippingCost = null;
            if (shippingSelect && shippingSelect.value !== '' && !isNaN(weight)) {
                var ratePerGram = shippingRatesByCountryId[shippingSelect.value];
                if (typeof ratePerGram !== 'number') {
                    var selectedOption = shippingSelect.options[shippingSelect.selectedIndex];
                    ratePerGram = selectedOption ? parseFloat(selectedOption.getAttribute('data-rate-per-gram')) : NaN;
                }
                if (!isNaN(ratePerGram)) {
                    shippingCost = weight * ratePerGram;
                }
            }
            var shippingCostDisplay = document.getElementById('pf-shipping-cost-rm');
            if (shippingCostDisplay) {
                shippingCostDisplay.textContent = shippingCost !== null ? pfFormatRM(shippingCost) : '—';
            }

            // Cost Summary: Estimated Cost = Supplier Cost + Shipping Cost (shipping missing = 0,
            // supplier missing = whole thing unknown - matches pricing_calculate_estimated_cost()).
            var estimatedCost = supplierCostRM !== null ? (supplierCostRM + (shippingCost || 0)) : null;
            var summarySupplier = document.getElementById('pf-summary-supplier-cost');
            var summaryShipping = document.getElementById('pf-summary-shipping-cost');
            var summaryEstimated = document.getElementById('pf-summary-estimated-cost');
            if (summarySupplier) {
                summarySupplier.textContent = supplierCostRM !== null ? pfFormatRM(supplierCostRM) : '—';
            }
            if (summaryShipping) {
                summaryShipping.textContent = shippingCost !== null ? pfFormatRM(shippingCost) : '—';
            }
            if (summaryEstimated) {
                summaryEstimated.textContent = estimatedCost !== null ? pfFormatRM(estimatedCost) : '—';
            }

            // Profit / Margin - Selling Price stays manually controlled; never written back here.
            var profitDisplay = document.getElementById('pf-profit');
            var marginDisplay = document.getElementById('pf-margin');
            if (profitDisplay && marginDisplay) {
                if (estimatedCost !== null && !isNaN(sellingPrice)) {
                    var profit = sellingPrice - estimatedCost;
                    profitDisplay.textContent = pfFormatRM(profit);
                    marginDisplay.textContent = sellingPrice > 0 ? ((profit / sellingPrice) * 100).toFixed(1) + '%' : '—';
                } else {
                    profitDisplay.textContent = '—';
                    marginDisplay.textContent = '—';
                }
            }
        }

        [
            'pf-supplier-price', 'cost-currency-select', 'cost-currency-other',
            'pf-original-price', 'original-currency-select', 'original-currency-other',
            'market-currency-select', 'market-currency-other',
            'pf-weight-grams', 'pf-shipping-origin', 'pf-selling-price-input',
        ].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', recomputeInlinePricing);
                el.addEventListener('change', recomputeInlinePricing);
            }
        });
        recomputeInlinePricing();
    })();
</script>