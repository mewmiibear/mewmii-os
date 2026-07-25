<?php

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/inventory.php';

/**
 * Product CSV Import (Sprint 13). Scope, deliberately: every imported row becomes a
 * *simple*, ready_stock product - the same "catalog_type first, then optionally build a
 * variation matrix from the product form's Attribute Builder" flow every other product
 * goes through (see modules/products/_form.php + Sprint 11's fixes to it) is left as the
 * only way to create *variable* products with real variations. The `attributes` column
 * here only attaches descriptive (non-variation) attributes - useful for search/filtering,
 * never generates product_variations rows. Brand/Collection/Tags are resolved via the
 * exact same catalog_get_or_create_*() functions the Catalogue Manager itself uses
 * (get-or-create, so a name that already exists is reused, never duplicated). Supplier is
 * looked up by exact name match ONLY - never auto-created, since a real supplier record
 * needs more than a name (currency, contact, terms) to be usable elsewhere in the app.
 */

const PRODUCT_IMPORT_COLUMNS = ['name', 'sku', 'brand', 'collection', 'tags', 'supplier', 'cost', 'currency', 'selling_price', 'stock', 'attributes'];

function product_import_template_csv(): string
{
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, PRODUCT_IMPORT_COLUMNS);
    fputcsv($handle, [
        'Hello Kitty Plush', 'HK-PLUSH-001', 'Sanrio', 'Avail x Sanrio', 'plush,new-arrival',
        'Avail Toys Co', '25.00', 'USD', '59.90', '10', 'Character:Hello Kitty|Size:M',
    ]);
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return $csv;
}

/**
 * "AttributeName:Value|AttributeName2:Value2" -> [['name' => ..., 'value' => ...], ...].
 * Blank/malformed pairs are silently skipped rather than erroring the whole row - the
 * attributes column is the one genuinely optional/best-effort field in this import.
 */
function product_import_parse_attributes(string $raw): array
{
    $pairs = [];
    foreach (explode('|', $raw) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        [$name, $value] = array_pad(explode(':', $part, 2), 2, '');
        $name = trim($name);
        $value = trim($value);
        if ($name !== '' && $value !== '') {
            $pairs[] = ['name' => $name, 'value' => $value];
        }
    }

    return $pairs;
}

/**
 * Validates every row of a parsed CSV (see csv_import_read_rows()) against the current
 * database state - dedup (SKU, both against existing products/variations AND duplicated
 * within the same file) and supplier existence are checked here, fresh, every time this is
 * called. Called twice per import (once for the preview, once again right before the
 * actual insert) so a second import started in the meantime, or a supplier deleted between
 * preview and confirm, is always caught rather than trusted from a stale preview.
 * Returns ['errors' => string[], 'validated' => array[]] - $validated is only meaningful
 * when $errors is empty (all-or-nothing, matching every other CSV import in this app).
 */
function product_import_validate_rows(PDO $pdo, array $rawRows): array
{
    $errors = [];
    $validated = [];

    $existingSkus = array_flip($pdo->query('SELECT LOWER(sku) FROM products')->fetchAll(PDO::FETCH_COLUMN));
    $existingVariationSkus = array_flip($pdo->query('SELECT LOWER(sku) FROM product_variations')->fetchAll(PDO::FETCH_COLUMN));

    $suppliersByName = [];
    foreach ($pdo->query('SELECT id, name FROM suppliers')->fetchAll(PDO::FETCH_ASSOC) as $supplierRow) {
        $suppliersByName[strtolower($supplierRow['name'])] = (int) $supplierRow['id'];
    }

    $seenSkus = [];

    foreach ($rawRows as $i => $row) {
        $rowNum = $i + 2; // +1 for header, +1 for 1-indexed display

        $name = trim((string) ($row['name'] ?? ''));
        $sku = trim((string) ($row['sku'] ?? ''));
        $brand = trim((string) ($row['brand'] ?? ''));
        $collection = trim((string) ($row['collection'] ?? ''));
        $tagsRaw = trim((string) ($row['tags'] ?? ''));
        $tags = $tagsRaw !== '' ? array_values(array_filter(array_map('trim', explode(',', $tagsRaw)), static fn (string $t): bool => $t !== '')) : [];
        $supplierName = trim((string) ($row['supplier'] ?? ''));
        $cost = trim((string) ($row['cost'] ?? ''));
        $currency = trim((string) ($row['currency'] ?? ''));
        $sellingPrice = trim((string) ($row['selling_price'] ?? ''));
        $stock = trim((string) ($row['stock'] ?? ''));
        $attributesRaw = trim((string) ($row['attributes'] ?? ''));

        $rowError = null;

        if ($name === '' || strlen($name) > 255) {
            $rowError = "Row {$rowNum}: product name is required and must be 255 characters or fewer.";
        } elseif ($sku === '' || strlen($sku) > 100) {
            $rowError = "Row {$rowNum}: SKU is required and must be 100 characters or fewer.";
        } elseif (isset($existingSkus[strtolower($sku)]) || isset($existingVariationSkus[strtolower($sku)])) {
            $rowError = "Row {$rowNum}: a product or variation with SKU \"{$sku}\" already exists.";
        } elseif (isset($seenSkus[strtolower($sku)])) {
            $rowError = "Row {$rowNum}: SKU \"{$sku}\" is duplicated elsewhere in this file.";
        } elseif ($cost === '' || !is_numeric($cost) || (float) $cost < 0) {
            $rowError = "Row {$rowNum}: cost must be a valid non-negative number.";
        } elseif ($sellingPrice === '' || !is_numeric($sellingPrice) || (float) $sellingPrice < 0) {
            $rowError = "Row {$rowNum}: selling price must be a valid non-negative number.";
        } elseif ($stock !== '' && !ctype_digit($stock)) {
            $rowError = "Row {$rowNum}: stock must be a whole number (0 or more).";
        } elseif (strlen($currency) > 10) {
            $rowError = "Row {$rowNum}: currency must be 10 characters or fewer (e.g. USD, MYR).";
        }

        $supplierId = null;
        if ($rowError === null && $supplierName !== '') {
            if (!isset($suppliersByName[strtolower($supplierName)])) {
                $rowError = "Row {$rowNum}: supplier \"{$supplierName}\" was not found - add it in Suppliers first, then re-import.";
            } else {
                $supplierId = $suppliersByName[strtolower($supplierName)];
            }
        }

        if ($rowError !== null) {
            $errors[] = $rowError;

            continue;
        }

        $seenSkus[strtolower($sku)] = true;
        $validated[] = [
            'row_num' => $rowNum,
            'name' => $name,
            'sku' => $sku,
            'brand' => $brand,
            'collection' => $collection,
            'tags' => $tags,
            'supplier' => $supplierName,
            'supplier_id' => $supplierId,
            'cost' => round((float) $cost, 2),
            'currency' => $currency !== '' ? strtoupper($currency) : null,
            'selling_price' => round((float) $sellingPrice, 2),
            'stock' => $stock !== '' ? (int) $stock : null,
            'attributes' => product_import_parse_attributes($attributesRaw),
        ];
    }

    return ['errors' => $errors, 'validated' => $validated];
}

/**
 * Inserts every already-validated row as a new simple/ready_stock/draft product - same
 * defaults modules/products/create.php uses for a manually-created simple product. Caller
 * is responsible for the surrounding transaction (matches every other bulk-write function
 * in this codebase, e.g. variation_bulk_apply()).
 */
function product_import_commit(PDO $pdo, array $validatedRows): int
{
    $insertStmt = $pdo->prepare("
        INSERT INTO products (sku, name, product_type, catalog_type, brand_id, supplier_id, product_cost, cost_currency, selling_price, status)
        VALUES (?, ?, 'ready_stock', 'simple', ?, ?, ?, ?, ?, 'draft')
    ");

    $imported = 0;

    foreach ($validatedRows as $row) {
        $brandId = $row['brand'] !== '' ? catalog_get_or_create_brand($pdo, $row['brand']) : null;
        $collectionId = $row['collection'] !== '' ? catalog_get_or_create_collection($pdo, $row['collection']) : null;

        $insertStmt->execute([
            $row['sku'],
            $row['name'],
            $brandId,
            $row['supplier_id'],
            $row['cost'],
            $row['currency'],
            $row['selling_price'],
        ]);
        $productId = (int) $pdo->lastInsertId();

        if ($collectionId !== null) {
            catalog_sync_product_collection($pdo, $productId, $collectionId);
        }

        if ($row['tags'] !== []) {
            $tagIds = array_map(static fn (string $tagName): int => catalog_get_or_create_tag($pdo, $tagName), $row['tags']);
            catalog_sync_product_tag_ids($pdo, $productId, $tagIds);
        }

        if ($row['attributes'] !== []) {
            // Grouped by attribute id (not one selection per pair) so two pairs sharing the
            // same attribute name in one row's `attributes` column merge into a single
            // multi-value selection instead of the second silently overwriting the first -
            // catalog_set_product_attributes()'s $selections shape is keyed by attribute,
            // not by individual value.
            $selectionsByAttribute = [];
            foreach ($row['attributes'] as $attribute) {
                $attributeId = catalog_get_or_create_attribute($pdo, $attribute['name']);
                $valueId = catalog_get_or_create_attribute_value($pdo, $attributeId, $attribute['value']);

                if (!isset($selectionsByAttribute[$attributeId])) {
                    $selectionsByAttribute[$attributeId] = [
                        'attribute_id' => $attributeId,
                        'is_variation_attribute' => false,
                        'value_ids' => [],
                    ];
                }
                $selectionsByAttribute[$attributeId]['value_ids'][] = $valueId;
            }
            catalog_set_product_attributes($pdo, $productId, array_values($selectionsByAttribute));
        }

        if ($row['stock'] !== null) {
            inventory_get_or_create_row($pdo, $productId, null);
            if ($row['stock'] > 0) {
                $pdo->prepare('UPDATE mewmii_inventory SET available_quantity = ? WHERE product_id = ? AND variation_id IS NULL')
                    ->execute([$row['stock'], $productId]);
                inventory_log_transaction($pdo, $productId, 'manual_adjustment', $row['stock'], 'product_import', $productId, null);
            }
        }

        $imported++;
    }

    return $imported;
}
