<?php

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/inventory.php';

/**
 * Product CSV Import. Scope, deliberately: every imported row becomes a *simple*,
 * ready_stock product - the same "catalog_type first, then optionally build a variation
 * matrix from the product form's Attribute Builder" flow every other product goes through
 * (see modules/products/_form.php + its Generate Variations fix) is left as the only way
 * to create *variable* products with real variations. The `attributes` column here only
 * attaches descriptive (non-variation) attributes - useful for search/filtering, never
 * generates product_variations rows. Brand/Collection/Tags are resolved via the exact same
 * catalog_get_or_create_*() functions the Catalogue Manager itself uses (get-or-create, so
 * a name that already exists is reused, never duplicated). Supplier is looked up by exact
 * name match ONLY - never auto-created, since a real supplier record needs more than a name
 * (currency, contact, terms) to be usable elsewhere in the app.
 *
 * Bugfix pass (investigation of the "0 created / 47 failed" incident): two root causes were
 * found and fixed here:
 *   1. Column-name rigidity - a CSV whose header row used human-readable names ("Product
 *      Name", "Cost Price", "Selling Price" - the exact phrasing this feature's own spec
 *      uses) rather than the machine-friendly snake_case this file originally required
 *      ("name", "cost", "selling_price") would silently produce a required-field failure
 *      on EVERY row, since csv_import_read_rows() only lowercases/trims headers - it never
 *      reconciles "cost price" with "cost". See product_import_normalize_row() below.
 *   2. All-or-nothing commit - even after fixing #1, a single genuinely bad row (typoed
 *      supplier name, duplicate SKU) rejected the ENTIRE file, so the "3 skipped" (valid)
 *      rows in that incident never got created alongside the 47 real failures. Import is
 *      now per-row: every row that validates gets created independently of whether other
 *      rows fail (still never bypassing validation - a genuinely invalid row is still never
 *      written), each in its own mini-transaction so one row's mid-creation failure can't
 *      roll back a row already committed before it.
 *
 * WooCommerce sync is never triggered by any function in this file - imported products are
 * created as local drafts only. Syncing to WooCommerce remains a separate, deliberate step
 * (the existing "Sync to WooCommerce" button/bulk action on the Products list) - this is
 * intentional, not an oversight (see modules/products/import.php's post-import notice).
 */

const PRODUCT_IMPORT_COLUMNS = ['name', 'sku', 'brand', 'collection', 'tags', 'supplier', 'cost', 'currency', 'selling_price', 'stock', 'status', 'attributes'];

// Every raw CSV header is normalized (lowercased, trimmed, then stripped to bare
// alphanumerics) and matched against these alias lists using the same normalization - so
// "Product Name", "product_name", and "ProductName" all resolve to the canonical `name`
// key regardless of which one a given spreadsheet export happens to use.
const PRODUCT_IMPORT_COLUMN_ALIASES = [
    'name' => ['name', 'productname', 'product', 'title'],
    'sku' => ['sku', 'productsku', 'code'],
    'brand' => ['brand', 'brandname', 'manufacturer'],
    'collection' => ['collection', 'collectionname'],
    'tags' => ['tags', 'tag'],
    'supplier' => ['supplier', 'suppliername', 'vendor'],
    'cost' => ['cost', 'costprice', 'unitcost', 'suppliercost', 'supplierprice'],
    'currency' => ['currency', 'costcurrency'],
    'selling_price' => ['sellingprice', 'price', 'retailprice'],
    'stock' => ['stock', 'stockquantity', 'quantity', 'qty', 'initialstock'],
    'status' => ['status', 'productstatus'],
    'attributes' => ['attributes', 'attribute'],
];

function product_import_normalize_header_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim($key)));
}

/**
 * Remaps one csv_import_read_rows() row (keyed by whatever header text the file actually
 * used, already lowercased/trimmed) onto the canonical PRODUCT_IMPORT_COLUMNS keys - see
 * this file's docblock for why this exists. A raw header that matches no known alias is
 * dropped (not an error - the row is simply missing that field, same as if the column had
 * been absent entirely).
 */
function product_import_normalize_row(array $rawRow): array
{
    $aliasToCanonical = [];
    foreach (PRODUCT_IMPORT_COLUMN_ALIASES as $canonical => $aliases) {
        foreach ($aliases as $alias) {
            $aliasToCanonical[$alias] = $canonical;
        }
    }

    $normalized = [];
    foreach ($rawRow as $rawKey => $value) {
        $normalizedKey = product_import_normalize_header_key((string) $rawKey);
        if (isset($aliasToCanonical[$normalizedKey]) && !isset($normalized[$aliasToCanonical[$normalizedKey]])) {
            $normalized[$aliasToCanonical[$normalizedKey]] = $value;
        }
    }

    return $normalized;
}

function product_import_template_csv(): string
{
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, PRODUCT_IMPORT_COLUMNS);
    fputcsv($handle, [
        'Hello Kitty Plush', 'HK-PLUSH-001', 'Sanrio', 'Avail x Sanrio', 'plush,new-arrival',
        'Avail Toys Co', '25.00', 'USD', '59.90', '10', 'draft', 'Character:Hello Kitty|Size:M',
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
 *
 * Returns ['errors' => [['row_num','name','reason','suggestion'], ...], 'validated' =>
 * array[]] - unlike a strict all-or-nothing import, $validated is populated independently
 * of whether $errors is empty, since each row is judged on its own (see this file's
 * docblock for why the earlier all-or-nothing behavior was itself part of the bug).
 */
function product_import_validate_rows(PDO $pdo, array $rawRows): array
{
    $statusOptions = ['draft', 'active', 'hidden', 'archived'];
    $errors = [];
    $validated = [];

    $existingSkus = array_flip($pdo->query('SELECT LOWER(sku) FROM products')->fetchAll(PDO::FETCH_COLUMN));
    $existingVariationSkus = array_flip($pdo->query('SELECT LOWER(sku) FROM product_variations')->fetchAll(PDO::FETCH_COLUMN));

    $suppliersByName = [];
    foreach ($pdo->query('SELECT id, name FROM suppliers')->fetchAll(PDO::FETCH_ASSOC) as $supplierRow) {
        $suppliersByName[strtolower($supplierRow['name'])] = (int) $supplierRow['id'];
    }

    $seenSkus = [];

    foreach ($rawRows as $i => $rawRow) {
        $rowNum = $i + 2; // +1 for header, +1 for 1-indexed display
        $row = product_import_normalize_row($rawRow);

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
        $status = trim((string) ($row['status'] ?? ''));
        $attributesRaw = trim((string) ($row['attributes'] ?? ''));

        $reason = null;
        $suggestion = null;

        if ($name === '' || strlen($name) > 255) {
            $reason = 'Product name is missing or too long (max 255 characters).';
            $suggestion = 'Fill in the "name" column with a value 255 characters or fewer.';
        } elseif ($sku === '' || strlen($sku) > 100) {
            $reason = 'SKU is missing or too long (max 100 characters).';
            $suggestion = 'Fill in the "sku" column with a unique value 100 characters or fewer.';
        } elseif (isset($existingSkus[strtolower($sku)]) || isset($existingVariationSkus[strtolower($sku)])) {
            $reason = "SKU \"{$sku}\" already exists on another product or variation.";
            $suggestion = 'Use a unique SKU, or edit the existing product directly instead of importing it again.';
        } elseif (isset($seenSkus[strtolower($sku)])) {
            $reason = "SKU \"{$sku}\" is duplicated elsewhere in this file.";
            $suggestion = 'Make sure every row in the file has a unique SKU.';
        } elseif ($cost === '' || !is_numeric($cost) || (float) $cost < 0) {
            $reason = 'Cost is missing or not a valid non-negative number.';
            $suggestion = 'Enter a plain number in the "cost" column (e.g. 25.00).';
        } elseif ($sellingPrice === '' || !is_numeric($sellingPrice) || (float) $sellingPrice < 0) {
            $reason = 'Selling price is missing or not a valid non-negative number.';
            $suggestion = 'Enter a plain number in the "selling_price" column (e.g. 59.90).';
        } elseif ($stock !== '' && !ctype_digit($stock)) {
            $reason = 'Stock must be a whole number.';
            $suggestion = 'Enter a whole number (e.g. 10) in the "stock" column, or leave it blank.';
        } elseif (strlen($currency) > 10) {
            $reason = 'Currency code is too long (max 10 characters).';
            $suggestion = 'Use a short code like USD or MYR.';
        } elseif ($status !== '' && !in_array(strtolower($status), $statusOptions, true)) {
            $reason = "Status \"{$status}\" is not recognized.";
            $suggestion = 'Use one of: draft, active, hidden, archived - or leave it blank to default to draft.';
        }

        $supplierId = null;
        if ($reason === null && $supplierName !== '') {
            if (!isset($suppliersByName[strtolower($supplierName)])) {
                $reason = "Supplier \"{$supplierName}\" was not found.";
                $suggestion = 'Create this supplier first in Suppliers, or fix the spelling to match an existing supplier name exactly, then re-import.';
            } else {
                $supplierId = $suppliersByName[strtolower($supplierName)];
            }
        }

        if ($reason !== null) {
            $errors[] = [
                'row_num' => $rowNum,
                'name' => $name !== '' ? $name : '(no name given)',
                'reason' => $reason,
                'suggestion' => $suggestion,
            ];

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
            'status' => $status !== '' ? strtolower($status) : 'draft',
            'attributes' => product_import_parse_attributes($attributesRaw),
        ];
    }

    return ['errors' => $errors, 'validated' => $validated];
}

/**
 * Inserts one already-validated row as a new simple/ready_stock product - same defaults
 * modules/products/create.php uses for a manually-created simple product. Caller wraps this
 * in its own transaction (see product_import_commit() below) so a failure here rolls back
 * only this one row, never any product already committed before it.
 */
function product_import_commit_row(PDO $pdo, array $row): int
{
    $insertStmt = $pdo->prepare("
        INSERT INTO products (sku, name, product_type, catalog_type, brand_id, supplier_id, product_cost, cost_currency, selling_price, status)
        VALUES (?, ?, 'ready_stock', 'simple', ?, ?, ?, ?, ?, ?)
    ");

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
        $row['status'],
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
        // catalog_set_product_attributes()'s $selections shape is keyed by attribute, not
        // by individual value.
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

    return $productId;
}

/**
 * Commits every already-validated row independently - each in its own transaction, so one
 * row's mid-creation failure (a real DB error slipping past pre-validation, e.g. a race
 * against another import) never rolls back a product already committed before it. This is
 * the direct fix for the "0 created despite 3 valid rows" symptom: previously the entire
 * batch shared one transaction and was only ever attempted if EVERY row had already passed
 * validation.
 *
 * Returns ['created' => int, 'failed' => [['row_num','name','reason','suggestion'], ...]].
 */
function product_import_commit(PDO $pdo, array $validatedRows): array
{
    $created = 0;
    $failed = [];

    foreach ($validatedRows as $row) {
        $pdo->beginTransaction();

        try {
            product_import_commit_row($pdo, $row);
            $pdo->commit();
            $created++;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $failed[] = [
                'row_num' => $row['row_num'],
                'name' => $row['name'],
                'reason' => 'Database error while creating this product: ' . $exception->getMessage(),
                'suggestion' => 'Re-check this row and try importing it again. If this keeps happening, contact support.',
            ];
        }
    }

    return ['created' => $created, 'failed' => $failed];
}
