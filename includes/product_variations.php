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
 * The short SKU fragment for one attribute value: its explicit `code` (e.g. "CN" for
 * "Cinnamoroll") if one was set, otherwise a short prefix auto-derived from the value name
 * (first 3 alphanumeric characters, uppercased) so a variation SKU never embeds the full
 * customer-facing name even for legacy values created before codes existed. Expects a row
 * with 'code' and 'value' keys (e.g. a product_attribute_values row).
 */
function catalog_attribute_value_sku_code(array $attributeValue): string
{
    $code = trim((string) ($attributeValue['code'] ?? ''));
    if ($code !== '') {
        return $code;
    }

    $value = (string) ($attributeValue['value'] ?? '');
    $alnum = (string) preg_replace('/[^A-Za-z0-9]+/', '', $value);

    return $alnum !== '' ? strtoupper(substr($alnum, 0, 3)) : 'X';
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
        SELECT pav.id, pav.value, pav.code
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
                    'value_code' => $value['code'] ?? null,
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

        // Short per-value codes, not full value names - see catalog_attribute_value_sku_code().
        $codes = array_map(
            static fn (array $part): string => catalog_attribute_value_sku_code(['code' => $part['value_code'], 'value' => $part['value_label']]),
            $combo
        );
        $baseSku = variation_generate_sku((string) $product['sku'], $codes);
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
 * mode/custom price/status/image, posted as variation_sku[SIGNATURE] etc.) onto the
 * variations variation_generate_combinations() just created, matching each by its
 * attribute-value combination signature - the same format the JS preview computes
 * client-side (sorted "attributeId:valueId" pairs joined by "|"), so both sides agree on
 * which posted values belong to which new row even though the create page has no
 * variation ids to key on until this exact moment. If a user-typed SKU collides with an
 * existing product/variation, that one field is silently left at its auto-generated
 * value (which is always guaranteed unique) rather than aborting the whole product save.
 * Stock is never set here - inventory quantities are only ever adjusted via the
 * Inventory module's Adjust Stock action, never from product creation/editing.
 */
function variation_apply_preview_edits(PDO $pdo, int $productId, array $createdVariations): void
{
    $skus = $_POST['variation_sku'] ?? [];
    $barcodes = $_POST['variation_barcode'] ?? [];
    $weights = $_POST['variation_weight'] ?? [];
    $priceModes = $_POST['variation_price_mode'] ?? [];
    $customPrices = $_POST['variation_custom_price'] ?? [];
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
 * price), status, weight, and/or clearing the barcode. Only keys present in $changes are
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

    // Stock is deliberately not settable here - inventory quantities are only ever
    // adjusted via the Inventory module's Adjust Stock action, never from bulk product
    // form edits (see modules/inventory/index.php).
}

// --- Delete (only if never actually used) --------------------------------------------------

/**
 * Hard-deletes a variation, but only if it has no real transaction history - checked
 * against every table that can reference a variation_id and does NOT cascade-delete on
 * its own (mewmii_order_items/supplier_order_items/customer_storage would raise a raw FK
 * constraint error if deleted into anyway; inventory_transactions.variation_id is
 * ON DELETE SET NULL, so the DB would silently let it through and lose the traceability -
 * checked explicitly here so both cases get the same friendly message instead of one
 * throwing an ugly SQL error). If none of those reference it, deleting the row cascades
 * (ON DELETE CASCADE) to its attribute-value links, images, and mewmii_inventory row -
 * nothing is left behind. Throws RuntimeException with an admin-facing message if blocked.
 */
function variation_delete_if_unused(PDO $pdo, int $variationId): void
{
    $checks = [
        'mewmii_order_items' => 'This variation has existing order history and cannot be deleted.',
        'inventory_transactions' => 'This variation has existing transaction history and cannot be deleted.',
        'supplier_order_items' => 'This variation has existing supplier order history and cannot be deleted.',
        'customer_storage' => 'This variation has existing customer storage history and cannot be deleted.',
    ];

    foreach ($checks as $table => $message) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE variation_id = ?");
        $stmt->execute([$variationId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException($message);
        }
    }

    $pdo->prepare('DELETE FROM product_variations WHERE id = ?')->execute([$variationId]);
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
        SELECT id, sku, name, selling_price, product_cost, moq, product_type, status, preorder_closing_date,
               preorder_reopened_at, availability_override, sale_enabled, sale_price, sale_start_date
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
            'moq' => $row['moq'] !== null ? (int) $row['moq'] : null,
            'product_type' => $row['product_type'],
            'status' => $row['status'],
            'availability_override' => $row['availability_override'],
            'preorder_closing_date' => $row['preorder_closing_date'],
            'preorder_reopened_at' => $row['preorder_reopened_at'],
        ];
    }

    $variableStmt = $pdo->query("
        SELECT p.id AS product_id, p.name AS product_name, p.selling_price AS parent_price, p.moq AS parent_moq,
               p.product_type, p.status, p.preorder_closing_date, p.preorder_reopened_at, p.availability_override,
               p.sale_enabled, p.sale_price, p.sale_start_date,
               pv.id AS variation_id, pv.sku, pv.price_mode, pv.custom_price, pv.cost_price, pv.moq AS variation_moq
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
        // Variation's own MOQ if set, otherwise the parent's - see
        // catalog_variation_effective_moq() (same rule, applied inline here to avoid a
        // cross-file dependency on includes/catalog.php from this file).
        $moq = $row['variation_moq'] !== null ? (int) $row['variation_moq'] : ($row['parent_moq'] !== null ? (int) $row['parent_moq'] : null);

        $units[] = [
            'key' => $row['product_id'] . ':' . $variationId,
            'product_id' => (int) $row['product_id'],
            'variation_id' => $variationId,
            'sku' => $row['sku'],
            'label' => $row['product_name'] . ($label !== '' ? (' - ' . $label) : ''),
            'selling_price' => $price,
            'cost_price' => (float) $row['cost_price'],
            'moq' => $moq,
            'product_type' => $row['product_type'],
            'status' => $row['status'],
            'availability_override' => $row['availability_override'],
            'preorder_closing_date' => $row['preorder_closing_date'],
            'preorder_reopened_at' => $row['preorder_reopened_at'],
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
 * available_quantity: it must be published (status = active). If preorder_closing_date
 * ("Early Bird Closing Date") is set and has passed, the product enters a "waiting for
 * release" state and stops taking new orders - but this is only ever TEMPORARY and never
 * self-resolving: there is no separate "Preorder Closing Date" that permanently ends
 * ordering, and estimated_release_month arriving does NOT reopen it automatically (it's
 * purely informational - see catalog_format_release_month()). The only way out of
 * "waiting for release" is a deliberate admin action - preorder_reopened_at being set (see
 * modules/products/reopen_preorder.php) - at which point ordering resumes at Regular Price
 * (Early Bird pricing never comes back; see catalog_product_effective_price()).
 * ready_stock purchasability is governed by available_quantity instead - callers should
 * only consult this for preorder/early_bird.
 */
function catalog_product_is_orderable(array $product): bool
{
    if (($product['status'] ?? '') !== 'active') {
        return false;
    }

    $closingDate = $product['preorder_closing_date'] ?? null;
    if (empty($closingDate) || strtotime((string) $closingDate) >= strtotime('today')) {
        return true;
    }

    // Closing date has passed - "waiting for release" until an admin manually reopens it.
    return !empty($product['preorder_reopened_at']);
}

/**
 * The single authoritative "is this available right now" decision - for a product's own
 * row, or one of its variations (pass the variation's own available_quantity; every other
 * field - product_type, availability_override, preorder_closing_date, etc - always comes
 * from the PARENT product, since a variation never has its own type/override/lifecycle
 * state). Every caller that needs to know whether something is purchasable/in-stock
 * (WooCommerce sync, Inventory display, order creation, lifecycle badges, the product
 * form's variation table) should go through this one function - never re-derive the
 * priority order locally. Strict priority, each tier only consulted if the one above
 * doesn't decide it:
 *   1. availability_override = 'available'/'out_of_stock' - authoritative, skips
 *      everything else below (including the closing-date/reopen lifecycle gate - a manual
 *      override is a deliberate admin escape hatch, even out of "Waiting for Release").
 *   2. Lifecycle state (catalog_product_is_orderable()) - Preorder/Early Bird only, the
 *      closing-date/reopen state machine. Quantity never factors in here.
 *   3. Stock quantity - Ready Stock only, via $availableQuantity. Unchanged from before
 *      this function existed.
 */
function catalog_product_availability_status(array $product, int $availableQuantity = 0): string
{
    $override = (string) ($product['availability_override'] ?? 'auto');

    if ($override === 'available') {
        return 'available';
    }
    if ($override === 'out_of_stock') {
        return 'out_of_stock';
    }

    if (($product['product_type'] ?? 'ready_stock') !== 'ready_stock') {
        return catalog_product_is_orderable($product) ? 'available' : 'out_of_stock';
    }

    return $availableQuantity > 0 ? 'available' : 'out_of_stock';
}

/**
 * The price a customer actually pays right now: sale_price when Enable Sale is on AND
 * today falls within [sale_start_date, preorder_closing_date] (either bound is optional -
 * an unset bound doesn't constrain that side), otherwise the regular selling_price. Once
 * preorder_closing_date ("Early Bird Closing Date") passes, this always returns
 * regular_price - including after a manual reopen (see catalog_product_is_orderable()):
 * Early Bird pricing is a one-time window that never comes back, even though ordering
 * itself can resume. Expects a row with at least selling_price, sale_enabled, sale_price,
 * sale_start_date, preorder_closing_date (e.g. a `products` row or a
 * catalog_sellable_units() entry).
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
