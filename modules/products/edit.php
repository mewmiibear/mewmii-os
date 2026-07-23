<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('products.manage');

$appTitle = 'Edit Product';
$error = '';
$pdo = app_db();
$canManage = true;
$isEdit = true;

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

// Older rows predating these columns won't have the keys at all (not just NULL values),
// so ?? is required here rather than a plain null check.
$product['sale_enabled'] = $product['sale_enabled'] ?? 0;
$product['sale_price'] = $product['sale_price'] ?? null;
$product['min_stock_threshold'] = $product['min_stock_threshold'] ?? null;
$product['preorder_closing_date'] = $product['preorder_closing_date'] ?? null;
$product['preorder_reopened_at'] = $product['preorder_reopened_at'] ?? null;
$product['availability_override'] = $product['availability_override'] ?? 'auto';

$catalogTypes = ['simple', 'variable'];
$productTypes = ['ready_stock', 'preorder', 'early_bird'];
$availabilityOverrideOptions = ['auto', 'available', 'out_of_stock'];

$baseStatusOptions = ['draft', 'active', 'hidden', 'archived'];
$statusOptions = in_array($product['status'], $baseStatusOptions, true)
    ? $baseStatusOptions
    : array_merge($baseStatusOptions, [$product['status']]);

$currentStock = product_effective_stock($pdo, $productId);
$lowStock = $product['product_type'] === 'ready_stock'
    && $product['min_stock_threshold'] !== null
    && (int) $currentStock['available_quantity'] < (int) $product['min_stock_threshold'];

$form = [
    'catalog_type' => $product['catalog_type'] ?? 'simple',
    'name' => $product['name'],
    'sku' => $product['sku'],
    'barcode' => (string) ($product['barcode'] ?? ''),
    'supplier_sku' => (string) ($product['supplier_sku'] ?? ''),
    'internal_code' => (string) ($product['internal_code'] ?? ''),
    'short_description' => (string) ($product['short_description'] ?? ''),
    'description' => (string) $product['description'],
    'brand_id' => $product['brand_id'] !== null ? (string) $product['brand_id'] : '',
    'category_id' => (string) (catalog_get_product_category_id($pdo, $productId) ?? ''),
    'collection_id' => (string) (catalog_get_product_collection_id($pdo, $productId) ?? ''),
    'supplier_id' => $product['supplier_id'] !== null ? (string) $product['supplier_id'] : '',
    'product_type' => $product['product_type'],
    'status' => $product['status'],
    'availability_override' => $product['availability_override'],
    'product_cost' => (string) $product['product_cost'],
    'selling_price' => (string) $product['selling_price'],
    'sale_enabled' => (bool) $product['sale_enabled'],
    'sale_price' => $product['sale_price'] !== null ? (string) $product['sale_price'] : '',
    'sale_start_date' => (string) ($product['sale_start_date'] ?? ''),
    'has_expiry' => !empty($product['expiry_date']),
    'expiry_date' => (string) ($product['expiry_date'] ?? ''),
    'stock_quantity' => (string) (int) $currentStock['available_quantity'],
    'min_stock_threshold' => $product['min_stock_threshold'] !== null ? (string) $product['min_stock_threshold'] : '',
    'estimated_arrival_date' => (string) ($product['estimated_arrival_date'] ?? ''),
    'estimated_release_month' => (string) ($product['estimated_release_month'] ?? ''),
    'moq' => (string) $product['moq'],
    'preorder_closing_date' => (string) ($product['preorder_closing_date'] ?? ''),
];
$selectedTagIds = catalog_get_product_tag_ids($pdo, $productId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['catalog_type'] = (string) ($_POST['catalog_type'] ?? $form['catalog_type']);
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['sku'] = trim((string) ($_POST['sku'] ?? ''));
    $form['barcode'] = trim((string) ($_POST['barcode'] ?? ''));
    $form['supplier_sku'] = trim((string) ($_POST['supplier_sku'] ?? ''));
    $form['internal_code'] = trim((string) ($_POST['internal_code'] ?? ''));
    $form['short_description'] = trim((string) ($_POST['short_description'] ?? ''));
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['brand_id'] = trim((string) ($_POST['brand_id'] ?? ''));
    $form['category_id'] = trim((string) ($_POST['category_id'] ?? ''));
    $form['collection_id'] = trim((string) ($_POST['collection_id'] ?? ''));
    $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));
    $form['product_type'] = (string) ($_POST['product_type'] ?? 'ready_stock');
    $form['status'] = (string) ($_POST['status'] ?? 'draft');
    $form['availability_override'] = (string) ($_POST['availability_override'] ?? 'auto');
    $form['product_cost'] = trim((string) ($_POST['product_cost'] ?? ''));
    $form['selling_price'] = trim((string) ($_POST['selling_price'] ?? ''));
    $form['sale_enabled'] = !empty($_POST['sale_enabled']);
    $form['sale_price'] = trim((string) ($_POST['sale_price'] ?? ''));
    $form['sale_start_date'] = trim((string) ($_POST['sale_start_date'] ?? ''));
    $form['has_expiry'] = !empty($_POST['has_expiry']);
    $form['expiry_date'] = trim((string) ($_POST['expiry_date'] ?? ''));
    $form['stock_quantity'] = trim((string) ($_POST['stock_quantity'] ?? ''));
    $form['min_stock_threshold'] = trim((string) ($_POST['min_stock_threshold'] ?? ''));
    $form['estimated_arrival_date'] = trim((string) ($_POST['estimated_arrival_date'] ?? ''));
    $form['estimated_release_month'] = trim((string) ($_POST['estimated_release_month'] ?? ''));
    $form['moq'] = trim((string) ($_POST['moq'] ?? '1'));
    $form['preorder_closing_date'] = trim((string) ($_POST['preorder_closing_date'] ?? ''));
    $selectedTagIds = array_map('intval', $_POST['tag_ids'] ?? []);

    if ($error === '') {
        if ($form['sku'] === '' || strlen($form['sku']) > 100) {
            $error = 'SKU is required and must be 100 characters or fewer.';
        } elseif ($form['name'] === '' || strlen($form['name']) > 255) {
            $error = 'Name is required and must be 255 characters or fewer.';
        } elseif (strlen($form['short_description']) > 500) {
            $error = 'Short description must be 500 characters or fewer.';
        } elseif (!in_array($form['catalog_type'], $catalogTypes, true)) {
            $error = 'Invalid product structure (simple/variable).';
        } elseif ($product['catalog_type'] === 'variable' && $form['catalog_type'] === 'simple') {
            $error = 'Cannot switch a variable product back to simple while it has variations. Archive its variations first.';
        } elseif (!in_array($form['product_type'], $productTypes, true)) {
            $error = 'Invalid availability type.';
        } elseif (!in_array($form['status'], $statusOptions, true)) {
            $error = 'Invalid status.';
        } elseif (!in_array($form['availability_override'], $availabilityOverrideOptions, true)) {
            $error = 'Invalid availability override.';
        } elseif (!is_numeric($form['product_cost']) || (float) $form['product_cost'] < 0) {
            $error = 'Cost price must be a valid non-negative number.';
        } elseif (!is_numeric($form['selling_price']) || (float) $form['selling_price'] < 0) {
            $error = 'Selling price must be a valid non-negative number.';
        } elseif ($form['sale_enabled'] && (!is_numeric($form['sale_price']) || (float) $form['sale_price'] < 0)) {
            $error = 'Enter a valid sale price, or disable Enable Sale.';
        } elseif ($form['estimated_release_month'] !== '' && !preg_match('/^\d{4}-\d{2}$/', $form['estimated_release_month'])) {
            $error = 'Estimated Release Month must be a valid month.';
        }
    }

    $supplierId = null;
    if ($error === '' && $form['supplier_id'] !== '') {
        $supplierId = (int) $form['supplier_id'];
        $check = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id = ?');
        $check->execute([$supplierId]);
        if ((int) $check->fetchColumn() === 0) {
            $error = 'Selected supplier does not exist.';
        }
    }

    $brandId = null;
    if ($error === '' && $form['brand_id'] !== '') {
        $brandId = (int) $form['brand_id'];
        $check = $pdo->prepare('SELECT COUNT(*) FROM brands WHERE id = ?');
        $check->execute([$brandId]);
        if ((int) $check->fetchColumn() === 0) {
            $error = 'Selected brand does not exist.';
        }
    }

    $categoryId = null;
    if ($error === '' && $form['category_id'] !== '') {
        $categoryId = (int) $form['category_id'];
        $check = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
        $check->execute([$categoryId]);
        if ((int) $check->fetchColumn() === 0) {
            $error = 'Selected category does not exist.';
        }
    }

    $collectionId = null;
    if ($error === '' && $form['collection_id'] !== '') {
        $collectionId = (int) $form['collection_id'];
        $check = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE id = ?');
        $check->execute([$collectionId]);
        if ((int) $check->fetchColumn() === 0) {
            $error = 'Selected collection does not exist.';
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
            $newClosingDate = $form['preorder_closing_date'] !== '' ? $form['preorder_closing_date'] : null;

            // Reopening is scoped to one closing-date cycle: if the closing date itself is
            // being changed (a new Early Bird cycle), a stale reopen from the previous cycle
            // must not carry over - the new cycle needs its own fresh manual reopen. If the
            // closing date is untouched, preserve whatever reopened state already exists.
            $preorderReopenedAt = $newClosingDate === $product['preorder_closing_date']
                ? $product['preorder_reopened_at']
                : null;

            $stmt = $pdo->prepare('
                UPDATE products
                SET sku = ?, name = ?, short_description = ?, description = ?, product_type = ?, catalog_type = ?, brand_id = ?, barcode = ?,
                    supplier_sku = ?, internal_code = ?,
                    supplier_id = ?, product_cost = ?, selling_price = ?, sale_enabled = ?, sale_price = ?,
                    min_stock_threshold = ?, sale_start_date = ?, estimated_arrival_date = ?, estimated_release_month = ?,
                    preorder_closing_date = ?, preorder_reopened_at = ?, expiry_date = ?, moq = ?, status = ?, availability_override = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $form['sku'],
                $form['name'],
                $form['short_description'] !== '' ? $form['short_description'] : null,
                $form['description'] !== '' ? $form['description'] : null,
                $form['product_type'],
                $form['catalog_type'],
                $brandId,
                $form['barcode'] !== '' ? $form['barcode'] : null,
                $form['supplier_sku'] !== '' ? $form['supplier_sku'] : null,
                $form['internal_code'] !== '' ? $form['internal_code'] : null,
                $supplierId,
                round((float) $form['product_cost'], 2),
                round((float) $form['selling_price'], 2),
                $form['sale_enabled'] ? 1 : 0,
                ($form['sale_enabled'] && $form['sale_price'] !== '') ? round((float) $form['sale_price'], 2) : null,
                $form['min_stock_threshold'] !== '' ? (int) $form['min_stock_threshold'] : null,
                $form['sale_start_date'] !== '' ? $form['sale_start_date'] : null,
                $form['estimated_arrival_date'] !== '' ? $form['estimated_arrival_date'] : null,
                $form['estimated_release_month'] !== '' ? $form['estimated_release_month'] : null,
                $newClosingDate,
                $preorderReopenedAt,
                ($form['has_expiry'] && $form['expiry_date'] !== '') ? $form['expiry_date'] : null,
                $form['moq'] !== '' ? max(1, (int) $form['moq']) : 1,
                $form['status'],
                $form['availability_override'],
                $productId,
            ]);

            catalog_sync_product_category($pdo, $productId, $categoryId);
            catalog_sync_product_collection($pdo, $productId, $collectionId);
            catalog_sync_product_tag_ids($pdo, $productId, $selectedTagIds);

            // Images: normal AJAX handles the "instant" experience in the browser, but the
            // plain form submit still applies these directly too (progressive enhancement -
            // works without JS, just with a full page reload).
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

            // Simple product stock: only settable for ready_stock, never for preorder/
            // early_bird regardless of what was posted.
            if ($form['catalog_type'] === 'simple' && $form['product_type'] === 'ready_stock' && $form['stock_quantity'] !== '' && is_numeric($form['stock_quantity'])) {
                $targetStock = max(0, (int) $form['stock_quantity']);
                $row = inventory_get_or_create_row($pdo, $productId, null);
                $delta = $targetStock - (int) $row['available_quantity'];
                if ($delta !== 0) {
                    $pdo->prepare('UPDATE mewmii_inventory SET available_quantity = ? WHERE product_id = ? AND variation_id IS NULL')
                        ->execute([$targetStock, $productId]);
                    inventory_log_transaction($pdo, $productId, 'manual_adjustment', $delta, 'product_edit', $productId, null);
                }
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

$brands = catalog_list_brands($pdo);
$categoriesTree = catalog_list_categories_tree($pdo);
$collections = catalog_list_collections($pdo);
$tags = catalog_list_tags($pdo);
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
$attributes = array_map(static function (array $attribute) use ($pdo): array {
    $attribute['values'] = catalog_list_attribute_values($pdo, (int) $attribute['id']);

    return $attribute;
}, catalog_list_attributes($pdo));

$existingAssignments = [];
foreach (catalog_get_product_attribute_assignments($pdo, $productId) as $assignment) {
    $existingAssignments[] = [
        'attributeId' => (int) $assignment['attribute_id'],
        'isVariation' => (bool) $assignment['is_variation_attribute'],
        'valueIds' => catalog_get_assignment_value_ids($pdo, (int) $assignment['assignment_id']),
    ];
}

$variations = $product['catalog_type'] === 'variable' ? variation_list_for_product($pdo, $productId) : [];

// Computed server-side (not in JS from raw available_quantity) since availability depends
// on the PARENT product's type/override/lifecycle state, none of which the variation table
// otherwise has access to - see catalog_product_availability_status(). Every variation
// shares the same parent, so this is purchasable/not-purchasable, never a per-variation
// quantity check for preorder/early_bird.
foreach ($variations as &$variation) {
    $variation['is_available'] = catalog_product_availability_status($product, (int) $variation['available_quantity']) === 'available';
}
unset($variation);

$mainImage = product_image_get_main($pdo, $productId);
$galleryImages = product_image_list_gallery($pdo, $productId);

require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_form.php';
require_once __DIR__ . '/../../includes/footer.php';
