<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('products.manage');

$appTitle = 'Add Product';
$error = '';
$pdo = app_db();
$canManage = true;

$isEdit = false;
$productId = null;
$product = null;
$existingAssignments = [];
$variations = [];
$mainImage = null;
$galleryImages = [];
$lowStock = false;
$statusOptions = ['draft', 'active', 'hidden', 'archived'];
$catalogTypes = ['simple', 'variable'];
$productTypes = ['ready_stock', 'preorder', 'early_bird'];

$form = [
    'catalog_type' => 'simple',
    'name' => '',
    'sku' => '',
    'barcode' => '',
    'description' => '',
    'brand_id' => '',
    'category_id' => '',
    'collection_id' => '',
    'supplier_id' => '',
    'product_type' => 'ready_stock',
    'status' => 'draft',
    'product_cost' => '',
    'selling_price' => '',
    'sale_enabled' => false,
    'sale_price' => '',
    'sale_start_date' => '',
    'has_expiry' => false,
    'expiry_date' => '',
    'stock_quantity' => '',
    'min_stock_threshold' => '',
    'estimated_arrival_date' => '',
    'moq' => '1',
    'preorder_closing_date' => '',
];
$selectedTagIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['catalog_type'] = (string) ($_POST['catalog_type'] ?? 'simple');
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['sku'] = trim((string) ($_POST['sku'] ?? ''));
    $form['barcode'] = trim((string) ($_POST['barcode'] ?? ''));
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['brand_id'] = trim((string) ($_POST['brand_id'] ?? ''));
    $form['category_id'] = trim((string) ($_POST['category_id'] ?? ''));
    $form['collection_id'] = trim((string) ($_POST['collection_id'] ?? ''));
    $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));
    $form['product_type'] = (string) ($_POST['product_type'] ?? 'ready_stock');
    $form['status'] = (string) ($_POST['status'] ?? 'draft');
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
    $form['moq'] = trim((string) ($_POST['moq'] ?? '1'));
    $form['preorder_closing_date'] = trim((string) ($_POST['preorder_closing_date'] ?? ''));
    $selectedTagIds = array_map('intval', $_POST['tag_ids'] ?? []);

    if ($error === '') {
        if ($form['sku'] === '' || strlen($form['sku']) > 100) {
            $error = 'SKU is required and must be 100 characters or fewer.';
        } elseif ($form['name'] === '' || strlen($form['name']) > 255) {
            $error = 'Name is required and must be 255 characters or fewer.';
        } elseif (!in_array($form['catalog_type'], $catalogTypes, true)) {
            $error = 'Invalid product structure (simple/variable).';
        } elseif (!in_array($form['product_type'], $productTypes, true)) {
            $error = 'Invalid availability type.';
        } elseif (!in_array($form['status'], $statusOptions, true)) {
            $error = 'Invalid status.';
        } elseif (!is_numeric($form['product_cost']) || (float) $form['product_cost'] < 0) {
            $error = 'Cost price must be a valid non-negative number.';
        } elseif (!is_numeric($form['selling_price']) || (float) $form['selling_price'] < 0) {
            $error = 'Selling price must be a valid non-negative number.';
        } elseif ($form['sale_enabled'] && (!is_numeric($form['sale_price']) || (float) $form['sale_price'] < 0)) {
            $error = 'Enter a valid sale price, or disable Enable Sale.';
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
        $skuCheck = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
        $skuCheck->execute([$form['sku']]);
        if ((int) $skuCheck->fetchColumn() > 0) {
            $error = 'SKU already exists.';
        }
    }

    $attributeSelections = [];
    if ($error === '' && $form['catalog_type'] === 'variable') {
        $rawSelections = json_decode((string) ($_POST['attribute_selections'] ?? '[]'), true);
        if (is_array($rawSelections)) {
            foreach ($rawSelections as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $attributeSelections[] = [
                    'attribute_id' => (int) ($item['attribute_id'] ?? 0),
                    'is_variation_attribute' => !empty($item['is_variation_attribute']),
                    'value_ids' => array_map('intval', $item['value_ids'] ?? []),
                ];
            }
        }
        if ($attributeSelections === []) {
            $error = 'Select at least one attribute with values for a variable product.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('
                INSERT INTO products (
                    sku, name, description, product_type, catalog_type, brand_id, barcode,
                    supplier_id, product_cost, selling_price, sale_enabled, sale_price,
                    min_stock_threshold, sale_start_date, estimated_arrival_date,
                    preorder_closing_date, expiry_date, moq, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $form['sku'],
                $form['name'],
                $form['description'] !== '' ? $form['description'] : null,
                $form['product_type'],
                $form['catalog_type'],
                $brandId,
                $form['barcode'] !== '' ? $form['barcode'] : null,
                $supplierId,
                round((float) $form['product_cost'], 2),
                round((float) $form['selling_price'], 2),
                $form['sale_enabled'] ? 1 : 0,
                ($form['sale_enabled'] && $form['sale_price'] !== '') ? round((float) $form['sale_price'], 2) : null,
                $form['min_stock_threshold'] !== '' ? (int) $form['min_stock_threshold'] : null,
                $form['sale_start_date'] !== '' ? $form['sale_start_date'] : null,
                $form['estimated_arrival_date'] !== '' ? $form['estimated_arrival_date'] : null,
                $form['preorder_closing_date'] !== '' ? $form['preorder_closing_date'] : null,
                ($form['has_expiry'] && $form['expiry_date'] !== '') ? $form['expiry_date'] : null,
                $form['moq'] !== '' ? max(1, (int) $form['moq']) : 1,
                $form['status'],
            ]);
            $productId = (int) $pdo->lastInsertId();

            catalog_sync_product_category($pdo, $productId, $categoryId);
            catalog_sync_product_collection($pdo, $productId, $collectionId);
            catalog_sync_product_tag_ids($pdo, $productId, $selectedTagIds);

            if (!empty($_FILES['main_image']['name'])) {
                product_image_set_main($pdo, $productId, $_FILES['main_image']);
            }

            $galleryFiles = image_upload_normalize_multi($_FILES['gallery_images'] ?? []);
            if ($galleryFiles !== []) {
                product_image_add_gallery($pdo, $productId, $galleryFiles);
            }

            if ($form['catalog_type'] === 'simple' && $form['product_type'] === 'ready_stock' && $form['stock_quantity'] !== '' && is_numeric($form['stock_quantity'])) {
                $initialStock = max(0, (int) $form['stock_quantity']);
                inventory_get_or_create_row($pdo, $productId, null);
                if ($initialStock > 0) {
                    $pdo->prepare('UPDATE mewmii_inventory SET available_quantity = ? WHERE product_id = ? AND variation_id IS NULL')
                        ->execute([$initialStock, $productId]);
                    inventory_log_transaction($pdo, $productId, 'manual_adjustment', $initialStock, 'product_create', $productId, null);
                }
            }

            if ($form['catalog_type'] === 'variable') {
                catalog_set_product_attributes($pdo, $productId, $attributeSelections);
                $generated = variation_generate_combinations($pdo, $productId);
                variation_apply_preview_edits($pdo, $productId, $generated['variations'], $form['product_type']);
            }

            $pdo->commit();

            app_redirect('/modules/products/edit.php?id=' . $productId . '&created=1');
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create product.';
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

require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_form.php';
require_once __DIR__ . '/../../includes/footer.php';
