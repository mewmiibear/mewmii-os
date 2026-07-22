<?php

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/product_images.php';

// --- SKU generation ---------------------------------------------------------------------

function variation_generate_sku(string $parentSku, array $valueLabels): string
{
    $parts = array_map(static function (string $label): string {
        $slug = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $label));

        return $slug !== '' ? $slug : 'X';
    }, $valueLabels);

    return $parentSku . '-' . implode('-', $parts);
}

/**
 * SKUs must be unique across both products and product_variations - there is no
 * single database-level constraint spanning both tables, so uniqueness is enforced
 * here at generation time.
 */
function variation_unique_sku(PDO $pdo, string $baseSku): string
{
    $sku = $baseSku;
    $suffix = 2;
    $variationCheck = $pdo->prepare('SELECT COUNT(*) FROM product_variations WHERE sku = ?');
    $productCheck = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');

    while (true) {
        $variationCheck->execute([$sku]);
        $productCheck->execute([$sku]);

        if ((int) $variationCheck->fetchColumn() === 0 && (int) $productCheck->fetchColumn() === 0) {
            return $sku;
        }

        $sku = $baseSku . '-' . $suffix;
        $suffix++;
    }
}

// --- Combination generation --------------------------------------------------------------

/**
 * Generates every combination of the product's variation-defining attributes/values
 * (cartesian product) that doesn't already exist as a variation. Idempotent: re-running
 * after adding a new attribute value only creates the newly-possible combinations: it
 * never duplicates or touches existing variations. Each new variation gets an
 * auto-generated SKU, an empty (zeroed) inventory row, and price_mode 'inherit'.
 */
function variation_generate_combinations(PDO $pdo, int $productId): array
{
    $productStmt = $pdo->prepare('SELECT id, sku, catalog_type FROM products WHERE id = ?');
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new RuntimeException('Product not found.');
    }

    if ($product['catalog_type'] !== 'variable') {
        throw new RuntimeException('Only variable products can generate variations.');
    }

    $assignmentStmt = $pdo->prepare('
        SELECT paa.id AS assignment_id, paa.attribute_id
        FROM product_attribute_assignments paa
        WHERE paa.product_id = ? AND paa.is_variation_attribute = 1
        ORDER BY paa.sort_order ASC, paa.id ASC
    ');
    $assignmentStmt->execute([$productId]);
    $assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($assignments === []) {
        throw new RuntimeException('Select at least one variation attribute with values before generating combinations.');
    }

    $valueStmt = $pdo->prepare('
        SELECT pav.id, pav.value
        FROM product_attribute_assignment_values paav
        INNER JOIN product_attribute_values pav ON pav.id = paav.attribute_value_id
        WHERE paav.assignment_id = ?
        ORDER BY pav.sort_order ASC, pav.value ASC
    ');

    $axes = [];
    foreach ($assignments as $assignment) {
        $valueStmt->execute([(int) $assignment['assignment_id']]);
        $values = $valueStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($values === []) {
            throw new RuntimeException('Select at least one value for every variation attribute before generating combinations.');
        }

        $axes[] = [
            'attribute_id' => (int) $assignment['attribute_id'],
            'values' => $values,
        ];
    }

    // Cartesian product across all variation-defining attributes.
    $combinations = [[]];
    foreach ($axes as $axis) {
        $next = [];
        foreach ($combinations as $combo) {
            foreach ($axis['values'] as $value) {
                $next[] = array_merge($combo, [[
                    'attribute_id' => $axis['attribute_id'],
                    'value_id' => (int) $value['id'],
                    'value_label' => (string) $value['value'],
                ]]);
            }
        }
        $combinations = $next;
    }

    // Signatures of combinations that already exist as variations for this product.
    $existingStmt = $pdo->prepare('
        SELECT pv.id, pvav.attribute_id, pvav.attribute_value_id
        FROM product_variations pv
        INNER JOIN product_variation_attribute_values pvav ON pvav.variation_id = pv.id
        WHERE pv.product_id = ?
    ');
    $existingStmt->execute([$productId]);

    $partsByVariation = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $partsByVariation[(int) $row['id']][] = $row['attribute_id'] . ':' . $row['attribute_value_id'];
    }

    $existingSignatures = [];
    foreach ($partsByVariation as $parts) {
        sort($parts);
        $existingSignatures[implode('|', $parts)] = true;
    }

    $insertVariationStmt = $pdo->prepare("
        INSERT INTO product_variations (product_id, sku, price_mode, status, is_system_generated)
        VALUES (?, ?, 'inherit', 'draft', 1)
    ");
    $insertAttrValueStmt = $pdo->prepare('
        INSERT INTO product_variation_attribute_values (variation_id, attribute_id, attribute_value_id)
        VALUES (?, ?, ?)
    ');
    $insertInventoryStmt = $pdo->prepare('INSERT INTO mewmii_inventory (product_id, variation_id) VALUES (?, ?)');

    $created = 0;
    $skipped = 0;
    $createdVariations = [];

    foreach ($combinations as $combo) {
        $parts = [];
        foreach ($combo as $part) {
            $parts[] = $part['attribute_id'] . ':' . $part['value_id'];
        }
        sort($parts);
        $signature = implode('|', $parts);

        if (isset($existingSignatures[$signature])) {
            $skipped++;
            continue;
        }

        $labels = array_column($combo, 'value_label');
        $baseSku = variation_generate_sku((string) $product['sku'], $labels);
        $sku = variation_unique_sku($pdo, $baseSku);

        $insertVariationStmt->execute([$productId, $sku]);
        $variationId = (int) $pdo->lastInsertId();

        $combination = [];
        foreach ($combo as $part) {
            $insertAttrValueStmt->execute([$variationId, $part['attribute_id'], $part['value_id']]);
            $combination[(int) $part['attribute_id']] = (int) $part['value_id'];
        }

        $insertInventoryStmt->execute([$productId, $variationId]);

        $createdVariations[] = [
            'id' => $variationId,
            'sku' => $sku,
            'combination' => $combination,
        ];

        $created++;
    }

    return ['created' => $created, 'skipped' => $skipped, 'variations' => $createdVariations];
}

/**
 * Applies the create-page's client-side preview-table edits (SKU/barcode/weight/price
 * mode/custom price/stock/status/image, posted as variation_sku[SIGNATURE] etc.) onto the
 * variations variation_generate_combinations() just created, matching each by its
 * attribute-value combination signature - the same format the JS preview computes
 * client-side (sorted "attributeId:valueId" pairs joined by "|"), so both sides agree on
 * which posted values belong to which new row even though the create page has no
 * variation ids to key on until this exact moment. If a user-typed SKU collides with an
 * existing product/variation, that one field is silently left at its auto-generated
 * value (which is always guaranteed unique) rather than aborting the whole product save.
 */
function variation_apply_preview_edits(PDO $pdo, int $productId, array $createdVariations, string $productType): void
{
    $skus = $_POST['variation_sku'] ?? [];
    $barcodes = $_POST['variation_barcode'] ?? [];
    $weights = $_POST['variation_weight'] ?? [];
    $priceModes = $_POST['variation_price_mode'] ?? [];
    $customPrices = $_POST['variation_custom_price'] ?? [];
    $stocks = $_POST['variation_stock'] ?? [];
    $statuses = $_POST['variation_status'] ?? [];
    $imageFiles = image_upload_normalize_multi($_FILES['variation_image'] ?? []);

    foreach ($createdVariations as $variation) {
        $parts = [];
        foreach ($variation['combination'] as $attributeId => $valueId) {
            $parts[] = $attributeId . ':' . $valueId;
        }
        sort($parts);
        $signature = implode('|', $parts);
        $variationId = (int) $variation['id'];

        if (isset($skus[$signature])) {
            $sku = trim((string) $skus[$signature]);
            if ($sku !== '' && $sku !== $variation['sku']) {
                $dupVariation = $pdo->prepare('SELECT COUNT(*) FROM product_variations WHERE sku = ? AND id != ?');
                $dupVariation->execute([$sku, $variationId]);
                $dupProduct = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
                $dupProduct->execute([$sku]);

                if ((int) $dupVariation->fetchColumn() === 0 && (int) $dupProduct->fetchColumn() === 0) {
                    $pdo->prepare('UPDATE product_variations SET sku = ? WHERE id = ?')->execute([$sku, $variationId]);
                }
            }
        }

        $barcode = trim((string) ($barcodes[$signature] ?? ''));
        $weight = trim((string) ($weights[$signature] ?? ''));
        $priceMode = (string) ($priceModes[$signature] ?? 'inherit');
        if (!in_array($priceMode, ['inherit', 'custom'], true)) {
            $priceMode = 'inherit';
        }
        $customPrice = trim((string) ($customPrices[$signature] ?? ''));
        $status = (string) ($statuses[$signature] ?? 'draft');
        if (!in_array($status, ['draft', 'active', 'inactive'], true)) {
            $status = 'draft';
        }

        $pdo->prepare('
            UPDATE product_variations
            SET barcode = ?, weight = ?, price_mode = ?, custom_price = ?, status = ?, is_system_generated = 0
            WHERE id = ?
        ')->execute([
            $barcode !== '' ? $barcode : null,
            ($weight !== '' && is_numeric($weight)) ? round((float) $weight, 3) : null,
            $priceMode,
            ($priceMode === 'custom' && is_numeric($customPrice)) ? round((float) $customPrice, 2) : null,
            $status,
            $variationId,
        ]);

        // Stock is only settable for ready_stock - preorder/early_bird never request
        // available stock, even from the create-page preview table.
        if ($productType === 'ready_stock' && isset($stocks[$signature]) && $stocks[$signature] !== '' && is_numeric($stocks[$signature])) {
            $stockValue = max(0, (int) $stocks[$signature]);
            if ($stockValue > 0) {
                $pdo->prepare('UPDATE mewmii_inventory SET available_quantity = ? WHERE product_id = ? AND variation_id = ?')
                    ->execute([$stockValue, $productId, $variationId]);
                inventory_log_transaction($pdo, $productId, 'manual_adjustment', $stockValue, 'variation_edit', $variationId, $variationId);
            }
        }

        if (isset($imageFiles[$signature])) {
            variation_image_set($pdo, $productId, $variationId, $imageFiles[$signature]);
        }
    }
}

// --- Reading variations back out ---------------------------------------------------------

function variation_build_label(PDO $pdo, int $variationId): string
{
    $stmt = $pdo->prepare('
        SELECT pav.value
        FROM product_variation_attribute_values pvav
        INNER JOIN product_attributes pa ON pa.id = pvav.attribute_id
        INNER JOIN product_attribute_values pav ON pav.id = pvav.attribute_value_id
        WHERE pvav.variation_id = ?
        ORDER BY pa.name ASC
    ');
    $stmt->execute([$variationId]);

    return implode(' / ', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function variation_list_for_product(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("
        SELECT
            pv.*,
            COALESCE(inv.available_quantity, 0) AS available_quantity,
            COALESCE(inv.reserved_quantity, 0) AS reserved_quantity,
            img.image_path
        FROM product_variations pv
        LEFT JOIN mewmii_inventory inv ON inv.variation_id = pv.id
        LEFT JOIN product_images img ON img.variation_id = pv.id AND img.image_type = 'variation'
        WHERE pv.product_id = ?
        ORDER BY (pv.status = 'archived') ASC, pv.id ASC
    ");
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['label'] = variation_build_label($pdo, (int) $row['id']);
    }
    unset($row);

    return $rows;
}

/**
 * Resolves the price a variation actually sells at: its own custom_price when
 * price_mode = 'custom', otherwise the parent product's current selling_price - looked
 * up live, never copied, so a parent price change instantly applies to every
 * inheriting variation.
 */
function variation_effective_price(array $variation, $parentSellingPrice): float
{
    if (($variation['price_mode'] ?? 'inherit') === 'custom' && $variation['custom_price'] !== null) {
        return (float) $variation['custom_price'];
    }

    return (float) $parentSellingPrice;
}

// --- Bulk edit ----------------------------------------------------------------------------

/**
 * Applies the same change to a batch of variations in one go: price mode (+ custom
 * price), status, and/or an absolute stock quantity. Only keys present in $changes are
 * touched. Caller is responsible for the surrounding transaction.
 */
function variation_bulk_apply(PDO $pdo, array $variationIds, array $changes): void
{
    $variationIds = array_values(array_unique(array_map('intval', $variationIds)));
    if ($variationIds === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($variationIds), '?'));

    if (isset($changes['price_mode']) && in_array($changes['price_mode'], ['inherit', 'custom'], true)) {
        $pdo->prepare("UPDATE product_variations SET price_mode = ? WHERE id IN ({$placeholders})")
            ->execute(array_merge([$changes['price_mode']], $variationIds));

        if ($changes['price_mode'] === 'custom' && isset($changes['custom_price']) && $changes['custom_price'] !== '') {
            $pdo->prepare("UPDATE product_variations SET custom_price = ? WHERE id IN ({$placeholders})")
                ->execute(array_merge([round((float) $changes['custom_price'], 2)], $variationIds));
        }
    }

    if (isset($changes['status']) && in_array($changes['status'], ['draft', 'active', 'inactive'], true)) {
        $pdo->prepare("UPDATE product_variations SET status = ? WHERE id IN ({$placeholders})")
            ->execute(array_merge([$changes['status']], $variationIds));
    }

    if (isset($changes['weight']) && $changes['weight'] !== '' && is_numeric($changes['weight'])) {
        $pdo->prepare("UPDATE product_variations SET weight = ? WHERE id IN ({$placeholders})")
            ->execute(array_merge([round((float) $changes['weight'], 3)], $variationIds));
    }

    // Bulk barcode is a CLEAR-only action, deliberately - a real barcode identifies one
    // physical item, so assigning the same value to every selected variation would create
    // invalid duplicate barcodes. This just wipes stale/duplicated ones in bulk (e.g.
    // after generating combinations or duplicating a product).
    if (!empty($changes['clear_barcode'])) {
        $pdo->prepare("UPDATE product_variations SET barcode = NULL WHERE id IN ({$placeholders})")
            ->execute($variationIds);
    }

    if (isset($changes['stock']) && $changes['stock'] !== '') {
        $targetStock = max(0, (int) $changes['stock']);
        $productLookup = $pdo->prepare('SELECT product_id FROM product_variations WHERE id = ?');

        foreach ($variationIds as $variationId) {
            $productLookup->execute([$variationId]);
            $productId = (int) $productLookup->fetchColumn();
            if ($productId < 1) {
                continue;
            }

            $row = inventory_get_or_create_row($pdo, $productId, $variationId);
            $delta = $targetStock - (int) $row['available_quantity'];

            if ($delta === 0) {
                continue;
            }

            $pdo->prepare('UPDATE mewmii_inventory SET available_quantity = ? WHERE product_id = ? AND variation_id = ?')
                ->execute([$targetStock, $productId, $variationId]);

            inventory_log_transaction($pdo, $productId, 'bulk_adjustment', $delta, 'variation_bulk_edit', $variationId, $variationId);
        }
    }
}

// --- Archive (never delete) ---------------------------------------------------------------

function variation_archive(PDO $pdo, int $variationId): void
{
    $pdo->prepare("UPDATE product_variations SET status = 'archived', archived_at = NOW() WHERE id = ?")
        ->execute([$variationId]);
}

// --- Sellable units: what a staff member can actually pick in an order/PO/storage form ---

/**
 * Every sellable unit in the catalog: a simple product's own row, or one active
 * (non-archived) variation of a variable product. Used anywhere staff pick "a product"
 * for an order, purchase order, or customer storage entry - a variable product's parent
 * is never itself sellable, only its variations are.
 */
function catalog_sellable_units(PDO $pdo): array
{
    $units = [];

    $simpleStmt = $pdo->query("
        SELECT id, sku, name, selling_price, product_cost, product_type, status, preorder_closing_date,
               sale_enabled, sale_price, sale_start_date
        FROM products
        WHERE catalog_type = 'simple'
        ORDER BY name ASC
    ");
    foreach ($simpleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $units[] = [
            'key' => $row['id'] . ':0',
            'product_id' => (int) $row['id'],
            'variation_id' => null,
            'sku' => $row['sku'],
            'label' => $row['name'],
            // The effective (sale-aware) price - see catalog_product_effective_price(). This
            // is what checkout/order-creation should charge, not the raw regular price.
            'selling_price' => catalog_product_effective_price($row),
            'cost_price' => (float) $row['product_cost'],
            'product_type' => $row['product_type'],
            'status' => $row['status'],
            'preorder_closing_date' => $row['preorder_closing_date'],
        ];
    }

    $variableStmt = $pdo->query("
        SELECT p.id AS product_id, p.name AS product_name, p.selling_price AS parent_price,
               p.product_type, p.status, p.preorder_closing_date,
               p.sale_enabled, p.sale_price, p.sale_start_date,
               pv.id AS variation_id, pv.sku, pv.price_mode, pv.custom_price, pv.cost_price
        FROM products p
        INNER JOIN product_variations pv ON pv.product_id = p.id
        WHERE p.catalog_type = 'variable' AND pv.status <> 'archived'
        ORDER BY p.name ASC, pv.id ASC
    ");
    foreach ($variableStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $variationId = (int) $row['variation_id'];
        $label = variation_build_label($pdo, $variationId);
        // A price_mode='custom' variation's own custom_price fully overrides the parent -
        // sale pricing never applies to it. 'inherit' mode resolves to the parent's
        // effective (sale-aware) price instead of its raw selling_price, so inherit-mode
        // variations follow the same Early Bird pricing window as the parent.
        $effectiveParentPrice = catalog_product_effective_price([
            'selling_price' => $row['parent_price'],
            'sale_enabled' => $row['sale_enabled'],
            'sale_price' => $row['sale_price'],
            'sale_start_date' => $row['sale_start_date'],
            'preorder_closing_date' => $row['preorder_closing_date'],
        ]);
        $price = variation_effective_price($row, $effectiveParentPrice);

        $units[] = [
            'key' => $row['product_id'] . ':' . $variationId,
            'product_id' => (int) $row['product_id'],
            'variation_id' => $variationId,
            'sku' => $row['sku'],
            'label' => $row['product_name'] . ($label !== '' ? (' - ' . $label) : ''),
            'selling_price' => $price,
            'cost_price' => (float) $row['cost_price'],
            'product_type' => $row['product_type'],
            'status' => $row['status'],
            'preorder_closing_date' => $row['preorder_closing_date'],
        ];
    }

    return $units;
}

function catalog_parse_sellable_key(string $key): array
{
    $parts = explode(':', $key, 2);
    $productId = (int) ($parts[0] ?? 0);
    $variationId = isset($parts[1]) ? (int) $parts[1] : 0;

    return [
        'product_id' => $productId,
        'variation_id' => $variationId > 0 ? $variationId : null,
    ];
}

/**
 * Whether a preorder/early_bird product can currently be ordered, independent of
 * available_quantity: it must be published (status = active) and, if a preorder_closing_date
 * ("Early Bird Closing Date") is set, that date must not have passed - it's the preorder
 * closing date, not just a pricing boundary: once it passes, the product stops taking new
 * orders entirely (see catalog_product_effective_price() for the paired pricing effect -
 * Sale Price also ends at the same date). ready_stock purchasability is governed by
 * available_quantity instead - callers should only consult this for preorder/early_bird.
 */
function catalog_product_is_orderable(array $product): bool
{
    if (($product['status'] ?? '') !== 'active') {
        return false;
    }

    $closingDate = $product['preorder_closing_date'] ?? null;
    if (!empty($closingDate) && strtotime((string) $closingDate) < strtotime('today')) {
        return false;
    }

    return true;
}

/**
 * The price a customer actually pays right now: sale_price when Enable Sale is on AND
 * today falls within [sale_start_date, preorder_closing_date] (either bound is optional -
 * an unset bound doesn't constrain that side), otherwise the regular selling_price. Once
 * preorder_closing_date ("Early Bird Closing Date") passes, this returns regular_price -
 * paired with catalog_product_is_orderable() also going false at the same date, since
 * closing date ends both the discounted price AND the ability to order at all. Expects a
 * row with at least selling_price, sale_enabled, sale_price, sale_start_date,
 * preorder_closing_date (e.g. a `products` row or a catalog_sellable_units() entry).
 */
function catalog_product_effective_price(array $product): float
{
    $regularPrice = (float) ($product['selling_price'] ?? 0);

    if (empty($product['sale_enabled']) || $product['sale_price'] === null || $product['sale_price'] === '') {
        return $regularPrice;
    }

    $today = strtotime('today');

    $startDate = $product['sale_start_date'] ?? null;
    if (!empty($startDate) && strtotime((string) $startDate) > $today) {
        return $regularPrice;
    }

    $closingDate = $product['preorder_closing_date'] ?? null;
    if (!empty($closingDate) && strtotime((string) $closingDate) < $today) {
        return $regularPrice;
    }

    return (float) $product['sale_price'];
}

// --- Admin-facing error messages: describe a product/variation by name, never a bare ID ---

/**
 * Same variation as variation_build_label() but with each attribute's name spelled out
 * (e.g. "Color: Brown" or "Color: Brown, Size: M") rather than a bare value list
 * ("Brown / M") - used in admin-facing messages where the compact label isn't enough
 * context on its own.
 */
function variation_build_full_label(PDO $pdo, int $variationId): string
{
    $stmt = $pdo->prepare('
        SELECT pa.name AS attribute_name, pav.value
        FROM product_variation_attribute_values pvav
        INNER JOIN product_attributes pa ON pa.id = pvav.attribute_id
        INNER JOIN product_attribute_values pav ON pav.id = pvav.attribute_value_id
        WHERE pvav.variation_id = ?
        ORDER BY pa.name ASC
    ');
    $stmt->execute([$variationId]);

    $parts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parts[] = $row['attribute_name'] . ': ' . $row['value'];
    }

    return implode(', ', $parts);
}

/**
 * Product/variation identity for admin-facing error messages: name, SKU, and (for a
 * variation) its own SKU and full attribute label - so staff are never left staring at a
 * bare product_id/variation_id to figure out what an error is actually about.
 */
function catalog_describe_unit(PDO $pdo, int $productId, ?int $variationId = null): array
{
    $productStmt = $pdo->prepare('SELECT name, sku FROM products WHERE id = ?');
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    $description = [
        'product_name' => $product['name'] ?? ('Unknown product #' . $productId),
        'product_sku' => $product['sku'] ?? null,
        'variation_label' => null,
        'variation_sku' => null,
    ];

    if ($variationId !== null) {
        $variationStmt = $pdo->prepare('SELECT sku FROM product_variations WHERE id = ?');
        $variationStmt->execute([$variationId]);
        $variationSku = $variationStmt->fetchColumn();
        $description['variation_sku'] = $variationSku !== false ? (string) $variationSku : null;
        $description['variation_label'] = variation_build_full_label($pdo, $variationId);
    }

    return $description;
}

/**
 * Builds a multi-line, admin-friendly stock shortfall message: which product/variation,
 * which inventory bucket ran short, how much is on hand, and how much was requested.
 * $currentQtyLabel is the exact line label for the on-hand figure (e.g. "Available
 * quantity", "Arrived quantity", "Customer storage quantity", "Remaining ordered quantity")
 * so each call site can phrase it naturally rather than forcing one template onto every
 * bucket name - always name the bucket explicitly (never a bare "Quantity: N"), since this
 * app has several inventory buckets and admins need to know exactly which one is short. Used
 * anywhere a stock check fails so warehouse/admin users never have to look up a raw
 * product_id/variation_id in the database.
 */
function catalog_format_stock_error(PDO $pdo, string $headline, int $productId, ?int $variationId, string $currentQtyLabel, int $currentQty, int $requestedQty): string
{
    $unit = catalog_describe_unit($pdo, $productId, $variationId);

    $lines = [$headline, ''];
    $lines[] = 'Product: ' . $unit['product_name'];
    if ($unit['product_sku'] !== null) {
        $lines[] = 'SKU: ' . $unit['product_sku'];
    }
    if (!empty($unit['variation_label'])) {
        $lines[] = 'Variation: ' . $unit['variation_label'];
    }
    if ($unit['variation_sku'] !== null) {
        $lines[] = 'Variation SKU: ' . $unit['variation_sku'];
    }
    $lines[] = '';
    $lines[] = $currentQtyLabel . ': ' . $currentQty;
    $lines[] = 'Requested: ' . $requestedQty;

    return implode("\n", $lines);
}
