<?php

require_once __DIR__ . '/inventory.php';

// --- Parent product images (shared gallery, variation_id IS NULL) ---------------------

function product_sync_images(PDO $pdo, int $productId, string $newlineSeparatedUrls): void
{
    $normalized = str_replace("\r\n", "\n", $newlineSeparatedUrls);
    $urls = array_filter(array_map('trim', explode("\n", $normalized)), static function (string $url): bool {
        return $url !== '';
    });

    $pdo->prepare('DELETE FROM product_images WHERE product_id = ? AND variation_id IS NULL')->execute([$productId]);

    $stmt = $pdo->prepare('INSERT INTO product_images (product_id, image_url, sort_order) VALUES (?, ?, ?)');
    $order = 0;
    foreach ($urls as $url) {
        $stmt->execute([$productId, $url, $order]);
        $order++;
    }
}

function product_images_text(PDO $pdo, int $productId): string
{
    $stmt = $pdo->prepare('SELECT image_url FROM product_images WHERE product_id = ? AND variation_id IS NULL ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$productId]);

    return implode("\n", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// --- Variation images (one per variation; upsert by delete-then-insert) ---------------

function variation_set_image(PDO $pdo, int $productId, int $variationId, string $imageUrl): void
{
    $pdo->prepare('DELETE FROM product_images WHERE variation_id = ?')->execute([$variationId]);

    $imageUrl = trim($imageUrl);
    if ($imageUrl !== '') {
        $pdo->prepare('INSERT INTO product_images (product_id, variation_id, image_url) VALUES (?, ?, ?)')
            ->execute([$productId, $variationId, $imageUrl]);
    }
}

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

        foreach ($combo as $part) {
            $insertAttrValueStmt->execute([$variationId, $part['attribute_id'], $part['value_id']]);
        }

        $insertInventoryStmt->execute([$productId, $variationId]);

        $created++;
    }

    return ['created' => $created, 'skipped' => $skipped];
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
            img.image_url
        FROM product_variations pv
        LEFT JOIN mewmii_inventory inv ON inv.variation_id = pv.id
        LEFT JOIN product_images img ON img.variation_id = pv.id
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
        SELECT id, sku, name, selling_price, product_cost
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
            'selling_price' => (float) $row['selling_price'],
            'cost_price' => (float) $row['product_cost'],
        ];
    }

    $variableStmt = $pdo->query("
        SELECT p.id AS product_id, p.name AS product_name, p.selling_price AS parent_price,
               pv.id AS variation_id, pv.sku, pv.price_mode, pv.custom_price, pv.cost_price
        FROM products p
        INNER JOIN product_variations pv ON pv.product_id = p.id
        WHERE p.catalog_type = 'variable' AND pv.status <> 'archived'
        ORDER BY p.name ASC, pv.id ASC
    ");
    foreach ($variableStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $variationId = (int) $row['variation_id'];
        $label = variation_build_label($pdo, $variationId);
        $price = variation_effective_price($row, $row['parent_price']);

        $units[] = [
            'key' => $row['product_id'] . ':' . $variationId,
            'product_id' => (int) $row['product_id'],
            'variation_id' => $variationId,
            'sku' => $row['sku'],
            'label' => $row['product_name'] . ($label !== '' ? (' - ' . $label) : ''),
            'selling_price' => $price,
            'cost_price' => (float) $row['cost_price'],
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
