<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/product_images.php';
app_require_permission('products.view');

$appTitle = 'Manage Variations';
$error = '';
$pdo = app_db();
$canManage = app_has_permission('products.manage');

$productId = (int) ($_GET['product_id'] ?? 0);

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

if (($product['catalog_type'] ?? 'simple') !== 'variable') {
    http_response_code(400);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">This product is a simple product and does not use variations. Change its structure to "Variable product" on the edit page first.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !$canManage) {
        http_response_code(403);
        $error = 'You do not have permission to manage variations.';
    }

    if ($error === '') {
        try {
            if (!empty($_POST['archive_variation_id'])) {
                $variationId = (int) $_POST['archive_variation_id'];
                if ($variationId < 1) {
                    throw new RuntimeException('Invalid variation.');
                }

                $pdo->beginTransaction();
                variation_archive($pdo, $variationId);
                $pdo->commit();

                app_redirect('/modules/products/variations.php?product_id=' . $productId . '&saved=1#variations');
            }

            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'create_attribute') {
                $name = trim((string) ($_POST['attribute_name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Enter an attribute name.');
                }

                $pdo->beginTransaction();
                catalog_get_or_create_attribute($pdo, $name);
                $pdo->commit();

                app_redirect('/modules/products/variations.php?product_id=' . $productId . '&saved=1#attributes');
            } elseif ($action === 'add_attribute_value') {
                $attributeId = (int) ($_POST['attribute_id'] ?? 0);
                $value = trim((string) ($_POST['value'] ?? ''));
                if ($attributeId < 1 || $value === '') {
                    throw new RuntimeException('Select an attribute and enter a value.');
                }

                $pdo->beginTransaction();
                catalog_get_or_create_attribute_value($pdo, $attributeId, $value);
                $pdo->commit();

                app_redirect('/modules/products/variations.php?product_id=' . $productId . '&saved=1#attributes');
            } elseif ($action === 'apply_template') {
                $templateId = (int) ($_POST['template_id'] ?? 0);
                if ($templateId < 1) {
                    throw new RuntimeException('Select a template to apply.');
                }

                $pdo->beginTransaction();
                catalog_apply_template($pdo, $productId, $templateId);
                $pdo->commit();

                app_redirect('/modules/products/variations.php?product_id=' . $productId . '&saved=1#attributes');
            } elseif ($action === 'save_attributes') {
                $assignedIds = array_map('intval', $_POST['assigned'] ?? []);
                $isVariationPosted = $_POST['is_variation'] ?? [];
                $valuesPosted = $_POST['values'] ?? [];

                $selections = [];
                foreach ($assignedIds as $attributeId) {
                    $selections[] = [
                        'attribute_id' => $attributeId,
                        'is_variation_attribute' => !empty($isVariationPosted[$attributeId]),
                        'value_ids' => array_map('intval', $valuesPosted[$attributeId] ?? []),
                    ];
                }

                $pdo->beginTransaction();
                catalog_set_product_attributes($pdo, $productId, $selections);
                $pdo->commit();

                app_redirect('/modules/products/variations.php?product_id=' . $productId . '&saved=1#attributes');
            } elseif ($action === 'generate') {
                $pdo->beginTransaction();
                $result = variation_generate_combinations($pdo, $productId);
                $pdo->commit();

                app_redirect('/modules/products/variations.php?product_id=' . $productId . '&generated=1&created=' . $result['created'] . '&skipped=' . $result['skipped'] . '#variations');
            } elseif ($action === 'save_variations') {
                $skus = $_POST['sku'] ?? [];
                $barcodes = $_POST['barcode'] ?? [];
                $weights = $_POST['weight'] ?? [];
                $priceModes = $_POST['price_mode'] ?? [];
                $customPrices = $_POST['custom_price'] ?? [];
                $removeImageFlags = $_POST['remove_image'] ?? [];
                $variationImageFiles = image_upload_normalize_multi($_FILES['variation_image'] ?? []);

                $pdo->beginTransaction();

                foreach ($skus as $variationId => $sku) {
                    $variationId = (int) $variationId;
                    $sku = trim((string) $sku);

                    if ($sku === '') {
                        throw new RuntimeException('Every variation needs a SKU.');
                    }

                    $dupVariation = $pdo->prepare('SELECT COUNT(*) FROM product_variations WHERE sku = ? AND id != ?');
                    $dupVariation->execute([$sku, $variationId]);
                    if ((int) $dupVariation->fetchColumn() > 0) {
                        throw new RuntimeException('SKU "' . $sku . '" is already used by another variation.');
                    }

                    $dupProduct = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
                    $dupProduct->execute([$sku]);
                    if ((int) $dupProduct->fetchColumn() > 0) {
                        throw new RuntimeException('SKU "' . $sku . '" is already used by a product.');
                    }

                    $barcode = trim((string) ($barcodes[$variationId] ?? ''));
                    $weight = trim((string) ($weights[$variationId] ?? ''));
                    $priceMode = (string) ($priceModes[$variationId] ?? 'inherit');
                    if (!in_array($priceMode, ['inherit', 'custom'], true)) {
                        $priceMode = 'inherit';
                    }
                    $customPrice = trim((string) ($customPrices[$variationId] ?? ''));

                    if ($priceMode === 'custom' && ($customPrice === '' || !is_numeric($customPrice) || (float) $customPrice < 0)) {
                        throw new RuntimeException('Enter a valid custom price for every variation using "Custom price" mode.');
                    }

                    $pdo->prepare('
                        UPDATE product_variations
                        SET sku = ?, barcode = ?, weight = ?, price_mode = ?, custom_price = ?, is_system_generated = 0
                        WHERE id = ? AND product_id = ?
                    ')->execute([
                        $sku,
                        $barcode !== '' ? $barcode : null,
                        ($weight !== '' && is_numeric($weight)) ? round((float) $weight, 3) : null,
                        $priceMode,
                        $priceMode === 'custom' ? round((float) $customPrice, 2) : null,
                        $variationId,
                        $productId,
                    ]);

                    if (!empty($removeImageFlags[$variationId])) {
                        variation_image_remove($pdo, $variationId);
                    } elseif (isset($variationImageFiles[$variationId])) {
                        variation_image_set($pdo, $productId, $variationId, $variationImageFiles[$variationId]);
                    }
                }

                $pdo->commit();

                app_redirect('/modules/products/variations.php?product_id=' . $productId . '&saved=1#variations');
            } elseif ($action === 'bulk_edit') {
                $selectedIds = array_map('intval', $_POST['selected_variations'] ?? []);
                if ($selectedIds === []) {
                    throw new RuntimeException('Select at least one variation to bulk edit.');
                }

                $changes = [];
                if ((string) ($_POST['bulk_price_mode'] ?? '') !== '') {
                    $changes['price_mode'] = (string) $_POST['bulk_price_mode'];
                    $changes['custom_price'] = (string) ($_POST['bulk_custom_price'] ?? '');
                }
                if ((string) ($_POST['bulk_status'] ?? '') !== '') {
                    $changes['status'] = (string) $_POST['bulk_status'];
                }
                if ((string) ($_POST['bulk_stock'] ?? '') !== '') {
                    $changes['stock'] = (string) $_POST['bulk_stock'];
                }

                $pdo->beginTransaction();
                variation_bulk_apply($pdo, $selectedIds, $changes);
                $pdo->commit();

                app_redirect('/modules/products/variations.php?product_id=' . $productId . '&saved=1#variations');
            } else {
                $error = 'Unknown action.';
            }
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to save changes.';
        }
    }
}

$attributes = catalog_list_attributes($pdo);
$assignments = catalog_get_product_attribute_assignments($pdo, $productId);

$assignmentByAttribute = [];
$selectedValueIdsByAttribute = [];
foreach ($assignments as $assignment) {
    $attributeId = (int) $assignment['attribute_id'];
    $assignmentByAttribute[$attributeId] = $assignment;
    $selectedValueIdsByAttribute[$attributeId] = catalog_get_assignment_value_ids($pdo, (int) $assignment['assignment_id']);
}

$templates = catalog_list_templates($pdo);
$variations = variation_list_for_product($pdo, $productId);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Manage Variations</h2>
        <p class="text-muted mb-0"><?php echo app_escape($product['name']); ?> &middot; <?php echo app_escape($product['sku']); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/products/edit.php?id=<?php echo $productId; ?>">Back to Product</a>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Variable product created. Select attributes below to start building variations.</div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Changes saved.</div>
<?php endif; ?>

<?php if (isset($_GET['generated'])): ?>
    <div class="alert alert-success">
        Generated <?php echo (int) ($_GET['created'] ?? 0); ?> new variation(s)<?php if ((int) ($_GET['skipped'] ?? 0) > 0): ?>, <?php echo (int) $_GET['skipped']; ?> combination(s) already existed and were left unchanged<?php endif; ?>.
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div id="attributes" class="card p-4 mb-4">
    <h5 class="mb-1">Step 1 &amp; 2: Choose Attributes &amp; Values</h5>
    <p class="text-muted small">Character, Color, Size, and any other attribute are all managed the same way here - Character is not a separate list, it's just another attribute.</p>

    <?php if ($canManage): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <form method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_attribute">
                    <input type="text" class="form-control form-control-sm" name="attribute_name" placeholder="New attribute name (e.g. Character)" required>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Add Attribute</button>
                </form>
            </div>

            <div class="col-md-4">
                <form method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="add_attribute_value">
                    <select class="form-select form-select-sm" name="attribute_id" required>
                        <option value="">Attribute&hellip;</option>
                        <?php foreach ($attributes as $attribute): ?>
                            <option value="<?php echo (int) $attribute['id']; ?>"><?php echo app_escape($attribute['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm" name="value" placeholder="New value (e.g. Hello Kitty)" required>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Add Value</button>
                </form>
            </div>

            <?php if ($templates !== []): ?>
                <div class="col-md-4">
                    <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="apply_template">
                        <select class="form-select form-select-sm" name="template_id" required>
                            <option value="">Apply template&hellip;</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo (int) $template['id']; ?>"><?php echo app_escape($template['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($attributes === []): ?>
        <p class="text-muted mb-0">No attributes exist yet. Add one above (e.g. Character, Color, Size) to get started.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="save_attributes">

            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Use on this product</th>
                        <th>Attribute</th>
                        <th>Defines variations</th>
                        <th>Values</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attributes as $attribute): ?>
                        <?php
                        $attributeId = (int) $attribute['id'];
                        $isAssigned = isset($assignmentByAttribute[$attributeId]);
                        $isVariationAttribute = $isAssigned && (bool) $assignmentByAttribute[$attributeId]['is_variation_attribute'];
                        $selectedValues = $selectedValueIdsByAttribute[$attributeId] ?? [];
                        $values = catalog_list_attribute_values($pdo, $attributeId);
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input" name="assigned[]" value="<?php echo $attributeId; ?>" <?php echo $isAssigned ? 'checked' : ''; ?> <?php echo $canManage ? '' : 'disabled'; ?>>
                            </td>
                            <td><?php echo app_escape($attribute['name']); ?></td>
                            <td>
                                <input type="checkbox" class="form-check-input" name="is_variation[<?php echo $attributeId; ?>]" value="1" <?php echo $isVariationAttribute ? 'checked' : ''; ?> <?php echo $canManage ? '' : 'disabled'; ?>>
                            </td>
                            <td>
                                <?php if ($values === []): ?>
                                    <span class="text-muted small">No values yet - add one above.</span>
                                <?php else: ?>
                                    <?php foreach ($values as $value): ?>
                                        <label class="me-3">
                                            <input type="checkbox" name="values[<?php echo $attributeId; ?>][]" value="<?php echo (int) $value['id']; ?>" <?php echo in_array((int) $value['id'], $selectedValues, true) ? 'checked' : ''; ?> <?php echo $canManage ? '' : 'disabled'; ?>>
                                            <?php echo app_escape($value['value']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($canManage): ?>
                <button class="btn btn-primary" type="submit">Save Attributes &amp; Values</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-1">Step 3: Generate Combinations</h5>
        <p class="text-muted small mb-3">Creates every combination of the checked "defines variations" attributes/values above (e.g. Character &times; Color) that doesn't already exist. Existing variations are never duplicated or changed.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="generate">
            <button class="btn btn-primary" type="submit">Generate Combinations</button>
        </form>
    </div>
<?php endif; ?>

<div id="variations" class="card p-4">
    <h5 class="mb-1">Step 4: Review &amp; Bulk Edit Variations</h5>
    <p class="text-muted small">Each variation is its own sellable SKU with its own inventory. Removing a variation always archives it - it is never deleted, so past orders stay intact.</p>

    <?php if ($variations === []): ?>
        <p class="text-muted mb-0">No variations yet. Generate combinations above once attributes and values are selected.</p>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

            <?php if ($canManage): ?>
                <div class="border rounded p-3 mb-3 bg-light">
                    <div class="fw-semibold mb-2">Bulk Edit Selected</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Price Mode</label>
                            <select class="form-select form-select-sm" name="bulk_price_mode">
                                <option value="">No change</option>
                                <option value="inherit">Inherit parent price</option>
                                <option value="custom">Custom price</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Custom Price</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="bulk_custom_price" placeholder="If custom">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Status</label>
                            <select class="form-select form-select-sm" name="bulk_status">
                                <option value="">No change</option>
                                <option value="draft">Draft</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Set Stock To</label>
                            <input type="number" min="0" class="form-control form-control-sm" name="bulk_stock" placeholder="e.g. 20">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-sm btn-outline-primary w-100" type="submit" name="action" value="bulk_edit">Apply to Selected</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <?php if ($canManage): ?><th></th><?php endif; ?>
                            <th>Combination</th>
                            <th>SKU</th>
                            <th>Barcode</th>
                            <th>Weight</th>
                            <th>Price Mode</th>
                            <th>Custom Price</th>
                            <th>Stock</th>
                            <th>Image</th>
                            <th>Status</th>
                            <?php if ($canManage): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($variations as $variation): ?>
                            <?php $variationId = (int) $variation['id']; $archived = $variation['status'] === 'archived'; ?>
                            <tr class="<?php echo $archived ? 'text-muted' : ''; ?>">
                                <?php if ($canManage): ?>
                                    <td><input type="checkbox" class="form-check-input" name="selected_variations[]" value="<?php echo $variationId; ?>" <?php echo $archived ? 'disabled' : ''; ?>></td>
                                <?php endif; ?>
                                <td><?php echo app_escape($variation['label'] !== '' ? $variation['label'] : '(no attributes)'); ?></td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" name="sku[<?php echo $variationId; ?>]" value="<?php echo app_escape($variation['sku']); ?>" <?php echo ($canManage && !$archived) ? '' : 'readonly'; ?>>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" name="barcode[<?php echo $variationId; ?>]" value="<?php echo app_escape((string) ($variation['barcode'] ?? '')); ?>" <?php echo ($canManage && !$archived) ? '' : 'readonly'; ?>>
                                </td>
                                <td>
                                    <input type="number" step="0.001" min="0" class="form-control form-control-sm" style="width: 90px;" name="weight[<?php echo $variationId; ?>]" value="<?php echo app_escape((string) ($variation['weight'] ?? '')); ?>" <?php echo ($canManage && !$archived) ? '' : 'readonly'; ?>>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm" name="price_mode[<?php echo $variationId; ?>]" <?php echo ($canManage && !$archived) ? '' : 'disabled'; ?>>
                                        <option value="inherit" <?php echo $variation['price_mode'] === 'inherit' ? 'selected' : ''; ?>>Inherit parent</option>
                                        <option value="custom" <?php echo $variation['price_mode'] === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" style="width: 100px;" name="custom_price[<?php echo $variationId; ?>]" value="<?php echo app_escape($variation['custom_price'] !== null ? (string) $variation['custom_price'] : ''); ?>" <?php echo ($canManage && !$archived) ? '' : 'readonly'; ?>>
                                </td>
                                <td>
                                    <?php echo (int) $variation['available_quantity']; ?>
                                    <div class="text-muted small">reserved: <?php echo (int) $variation['reserved_quantity']; ?></div>
                                </td>
                                <td style="min-width: 160px;">
                                    <?php $hasOwnImage = !empty($variation['image_path']); ?>
                                    <?php $previewPath = $hasOwnImage ? $variation['image_path'] : variation_effective_image($pdo, $productId, $variationId); ?>
                                    <?php if ($previewPath !== null): ?>
                                        <img src="/<?php echo app_escape($previewPath); ?>" alt="" style="max-width: 60px; max-height: 60px;" class="border rounded d-block mb-1">
                                        <?php if (!$hasOwnImage): ?>
                                            <div class="text-muted small mb-1">using parent image</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-muted small mb-1">no image</div>
                                    <?php endif; ?>
                                    <?php if ($canManage && !$archived): ?>
                                        <input type="file" class="form-control form-control-sm mb-1" name="variation_image[<?php echo $variationId; ?>]" accept="image/*">
                                        <?php if ($hasOwnImage): ?>
                                            <label class="small">
                                                <input type="checkbox" name="remove_image[<?php echo $variationId; ?>]" value="1"> Remove (use parent image)
                                            </label>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $archived ? 'secondary' : ($variation['status'] === 'active' ? 'success' : 'light text-dark'); ?>"><?php echo app_escape($variation['status']); ?></span>
                                </td>
                                <?php if ($canManage): ?>
                                    <td class="text-end">
                                        <?php if (!$archived): ?>
                                            <button class="btn btn-sm btn-outline-danger" type="submit" name="archive_variation_id" value="<?php echo $variationId; ?>" onclick="return confirm('Archive this variation? It will no longer be sellable, but its history is kept.');">Archive</button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($canManage): ?>
                <button class="btn btn-primary mt-2" type="submit" name="action" value="save_variations">Save Changes</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
