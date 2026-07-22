<?php

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/product_variations.php';

/**
 * Clones a product (and, if variable, its variations) into a new draft product - a
 * shortcut for building a similar product without retyping everything. The duplicate
 * always starts as status='draft' with sale disabled, never inheriting a live/on-sale
 * state by accident. Barcodes are cleared on the copy (both product and variations) since
 * a real barcode identifies one physical item and must never be duplicated onto another.
 * Images are physically copied to new files, never shared by reference, since
 * image_upload_delete() unconditionally removes a file when any single row referencing it
 * is deleted - sharing a path would risk one product's cleanup deleting the other's image.
 * Caller is responsible for the surrounding transaction.
 */
function product_duplicate(PDO $pdo, int $sourceProductId): int
{
    $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $productStmt->execute([$sourceProductId]);
    $source = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        throw new RuntimeException('Product not found.');
    }

    $newSku = variation_unique_sku($pdo, $source['sku'] . '-COPY');
    $newName = $source['name'] . ' (Copy)';

    $insertStmt = $pdo->prepare('
        INSERT INTO products (
            sku, name, description, product_type, catalog_type, brand_id, barcode,
            supplier_id, product_cost, selling_price, sale_enabled, sale_price,
            min_stock_threshold, sale_start_date, estimated_arrival_date,
            preorder_closing_date, expiry_date, moq, status
        ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 0, NULL, ?, ?, ?, ?, ?, ?, \'draft\')
    ');
    $insertStmt->execute([
        $newSku,
        $newName,
        $source['description'],
        $source['product_type'],
        $source['catalog_type'],
        $source['brand_id'],
        $source['supplier_id'],
        $source['product_cost'],
        $source['selling_price'],
        $source['min_stock_threshold'],
        $source['sale_start_date'],
        $source['estimated_arrival_date'],
        $source['preorder_closing_date'],
        $source['expiry_date'],
        $source['moq'],
    ]);
    $newProductId = (int) $pdo->lastInsertId();

    catalog_sync_product_category($pdo, $newProductId, catalog_get_product_category_id($pdo, $sourceProductId));
    catalog_sync_product_collection($pdo, $newProductId, catalog_get_product_collection_id($pdo, $sourceProductId));
    catalog_sync_product_tag_ids($pdo, $newProductId, catalog_get_product_tag_ids($pdo, $sourceProductId));

    $mainImage = product_image_get_main($pdo, $sourceProductId);
    if ($mainImage !== null) {
        $newPath = image_upload_duplicate($mainImage['image_path'], 'products');
        $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, NULL, 'main', ?, 0)")
            ->execute([$newProductId, $newPath]);
    }

    foreach (product_image_list_gallery($pdo, $sourceProductId) as $galleryImage) {
        $newPath = image_upload_duplicate($galleryImage['image_path'], 'products');
        $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, NULL, 'gallery', ?, ?)")
            ->execute([$newProductId, $newPath, $galleryImage['sort_order']]);
    }

    if ($source['catalog_type'] === 'variable') {
        product_duplicate_attributes($pdo, $sourceProductId, $newProductId);
        product_duplicate_variations($pdo, $sourceProductId, $newProductId);
    }

    return $newProductId;
}

function product_duplicate_attributes(PDO $pdo, int $sourceProductId, int $newProductId): void
{
    $insertAssignment = $pdo->prepare('INSERT INTO product_attribute_assignments (product_id, attribute_id, is_variation_attribute, sort_order) VALUES (?, ?, ?, ?)');
    $insertValue = $pdo->prepare('INSERT INTO product_attribute_assignment_values (assignment_id, attribute_value_id) VALUES (?, ?)');

    foreach (catalog_get_product_attribute_assignments($pdo, $sourceProductId) as $assignment) {
        $insertAssignment->execute([
            $newProductId,
            $assignment['attribute_id'],
            (int) $assignment['is_variation_attribute'],
            0,
        ]);
        $newAssignmentId = (int) $pdo->lastInsertId();

        foreach (catalog_get_assignment_value_ids($pdo, (int) $assignment['assignment_id']) as $valueId) {
            $insertValue->execute([$newAssignmentId, $valueId]);
        }
    }
}

function product_duplicate_variations(PDO $pdo, int $sourceProductId, int $newProductId): void
{
    $insertVariation = $pdo->prepare("
        INSERT INTO product_variations (product_id, sku, weight, price_mode, custom_price, status, is_system_generated)
        VALUES (?, ?, ?, ?, ?, 'draft', 0)
    ");
    $comboStmt = $pdo->prepare('SELECT attribute_id, attribute_value_id FROM product_variation_attribute_values WHERE variation_id = ?');
    $insertCombo = $pdo->prepare('INSERT INTO product_variation_attribute_values (variation_id, attribute_id, attribute_value_id) VALUES (?, ?, ?)');
    $insertInventory = $pdo->prepare('INSERT INTO mewmii_inventory (product_id, variation_id) VALUES (?, ?)');

    foreach (variation_list_for_product($pdo, $sourceProductId) as $variation) {
        if ($variation['status'] === 'archived') {
            continue;
        }

        $newVariationSku = variation_unique_sku($pdo, $variation['sku'] . '-COPY');

        $insertVariation->execute([
            $newProductId,
            $newVariationSku,
            $variation['weight'],
            $variation['price_mode'],
            $variation['custom_price'],
        ]);
        $newVariationId = (int) $pdo->lastInsertId();

        $comboStmt->execute([(int) $variation['id']]);
        foreach ($comboStmt->fetchAll(PDO::FETCH_ASSOC) as $comboRow) {
            $insertCombo->execute([$newVariationId, $comboRow['attribute_id'], $comboRow['attribute_value_id']]);
        }

        $insertInventory->execute([$newProductId, $newVariationId]);

        if (!empty($variation['image_path'])) {
            $newImagePath = image_upload_duplicate($variation['image_path'], 'variations');
            $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, ?, 'variation', ?, 0)")
                ->execute([$newProductId, $newVariationId, $newImagePath]);
        }
    }
}
