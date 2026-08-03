<?php

require_once __DIR__ . '/activity_log.php';

/**
 * SO-C (Multi-Supplier Sourcing) - the sourcing catalogue: which suppliers can supply a given
 * product/variation, at what quoted price, under what SKU, in what currency, at what priority.
 *
 * READ THIS BEFORE EXTENDING: `unit_cost` here is QUOTATION data for purchasing decisions. It is
 * deliberately never read by includes/product_cost.php. The costing chain established by SO-A1/
 * SO-A2 is actual PO line cost -> landed cost -> product_cost_history, and adding a quote into
 * that chain would create two competing answers to "what does this product cost". Likewise
 * `moq` is stored but intentionally not wired into purchasing yet - products.moq remains
 * authoritative everywhere.
 *
 * products.supplier_id is untouched and still means "the preferred supplier" (purchase planning
 * groups reorder needs by it). `priority` orders the ALTERNATIVES for a human deciding where to
 * buy; it never overrides that column.
 */

function supplier_product_variation_key(?int $variationId): int
{
    return $variationId ?? 0;
}

/**
 * Every catalogue row for one product, best priority first. $variationId null returns the whole
 * product's catalogue (product-level rows AND every variation's rows), which is what the product
 * page shows; pass a variation id to narrow it to that unit plus its product-level fallbacks.
 */
function supplier_products_list(PDO $pdo, int $productId, ?int $variationId = null): array
{
    $sql = '
        SELECT sp.*, s.name AS supplier_name, s.currency AS supplier_default_currency,
               pv.sku AS variation_sku
        FROM supplier_products sp
        INNER JOIN suppliers s ON s.id = sp.supplier_id
        LEFT JOIN product_variations pv ON pv.id = sp.variation_id
        WHERE sp.product_id = ?
    ';
    $params = [$productId];

    if ($variationId !== null) {
        $sql .= ' AND (sp.variation_id IS NULL OR sp.variation_id = ?)';
        $params[] = $variationId;
    }

    $sql .= ' ORDER BY sp.is_active DESC, sp.priority ASC, s.name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function supplier_product_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT sp.*, s.name AS supplier_name
        FROM supplier_products sp
        INNER JOIN suppliers s ON s.id = sp.supplier_id
        WHERE sp.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * Catalogue rows for a specific supplier across many products - used to surface that supplier's
 * own SKU/quoted price while building a purchase order for them. Keyed "productId:variationId"
 * (variation collapsed to 0), the same sellable-unit convention used everywhere else, so a
 * caller holding units can index directly. Read-only: nothing here changes how a PO is priced.
 */
function supplier_products_for_supplier(PDO $pdo, int $supplierId, array $productIds): array
{
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if ($productIds === [] || $supplierId < 1) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("
        SELECT product_id, variation_id, supplier_sku, unit_cost, currency, moq
        FROM supplier_products
        WHERE supplier_id = ? AND is_active = 1 AND product_id IN ({$placeholders})
    ");
    $stmt->execute(array_merge([$supplierId], $productIds));

    $byUnit = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int) $row['product_id'] . ':' . supplier_product_variation_key(
            $row['variation_id'] !== null ? (int) $row['variation_id'] : null
        );
        $byUnit[$key] = $row;
    }

    return $byUnit;
}

/**
 * @return array{errors: string[], data: array}
 */
function supplier_product_validate_form(PDO $pdo, array $input): array
{
    $errors = [];

    $data = [
        'product_id' => (int) ($input['product_id'] ?? 0),
        'variation_id' => isset($input['variation_id']) && (int) $input['variation_id'] > 0 ? (int) $input['variation_id'] : null,
        'supplier_id' => (int) ($input['supplier_id'] ?? 0),
        'supplier_sku' => trim((string) ($input['supplier_sku'] ?? '')),
        'unit_cost' => trim((string) ($input['unit_cost'] ?? '')),
        'currency' => strtoupper(trim((string) ($input['currency'] ?? ''))),
        'exchange_rate' => trim((string) ($input['exchange_rate'] ?? '')) !== '' ? (float) $input['exchange_rate'] : null,
        'priority' => isset($input['priority']) && $input['priority'] !== '' ? (int) $input['priority'] : 0,
        'moq' => isset($input['moq']) && trim((string) $input['moq']) !== '' ? (int) $input['moq'] : null,
        'notes' => trim((string) ($input['notes'] ?? '')),
        'is_active' => !empty($input['is_active']) ? 1 : 0,
    ];

    if ($data['product_id'] < 1) {
        $errors[] = 'Invalid product.';
    }

    if ($data['supplier_id'] < 1) {
        $errors[] = 'Choose a supplier.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id = ?');
        $stmt->execute([$data['supplier_id']]);
        if ((int) $stmt->fetchColumn() === 0) {
            $errors[] = 'Choose a valid supplier.';
        }
    }

    if ($data['variation_id'] !== null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_variations WHERE id = ? AND product_id = ?');
        $stmt->execute([$data['variation_id'], $data['product_id']]);
        if ((int) $stmt->fetchColumn() === 0) {
            $errors[] = 'Choose a variation that belongs to this product.';
        }
    }

    if (strlen($data['supplier_sku']) > 100) {
        $errors[] = 'Supplier SKU must be 100 characters or fewer.';
    }

    if ($data['unit_cost'] !== '') {
        if (!is_numeric($data['unit_cost']) || (float) $data['unit_cost'] < 0) {
            $errors[] = 'Quoted unit cost must be a non-negative number.';
        } else {
            $data['unit_cost'] = round((float) $data['unit_cost'], 2);
        }
    } else {
        $data['unit_cost'] = null;
    }

    if (strlen($data['currency']) > 10) {
        $errors[] = 'Currency code must be 10 characters or fewer.';
    }

    if ($data['exchange_rate'] !== null && $data['exchange_rate'] < 0) {
        $errors[] = 'Exchange rate cannot be negative.';
    }

    if ($data['moq'] !== null && $data['moq'] < 1) {
        $errors[] = 'Supplier MOQ must be at least 1, or left blank.';
    }

    if (strlen($data['notes']) > 255) {
        $errors[] = 'Notes must be 255 characters or fewer.';
    }

    return ['errors' => $errors, 'data' => $data];
}

/**
 * Insert or update by the natural key (product, variation, supplier) - a supplier can only appear
 * once per unit, enforced by uq_supplier_products_unit_supplier. Returns the row id.
 */
function supplier_product_upsert(PDO $pdo, array $data, ?int $userId): int
{
    $variationKey = supplier_product_variation_key($data['variation_id']);

    $existingStmt = $pdo->prepare('
        SELECT id FROM supplier_products
        WHERE product_id = ? AND variation_key = ? AND supplier_id = ?
    ');
    $existingStmt->execute([$data['product_id'], $variationKey, $data['supplier_id']]);
    $existingId = $existingStmt->fetchColumn();

    $values = [
        $data['supplier_sku'] !== '' ? $data['supplier_sku'] : null,
        $data['unit_cost'],
        $data['currency'] !== '' ? $data['currency'] : null,
        $data['exchange_rate'],
        $data['priority'],
        $data['moq'],
        $data['notes'] !== '' ? $data['notes'] : null,
        $data['is_active'],
    ];

    if ($existingId !== false) {
        $id = (int) $existingId;
        $pdo->prepare('
            UPDATE supplier_products
            SET supplier_sku = ?, unit_cost = ?, currency = ?, exchange_rate = ?,
                priority = ?, moq = ?, notes = ?, is_active = ?
            WHERE id = ?
        ')->execute(array_merge($values, [$id]));

        activity_log($pdo, 'products', 'supplier_source_updated', $data['product_id'], 'Updated supplier sourcing entry #' . $id . '.');

        return $id;
    }

    $pdo->prepare('
        INSERT INTO supplier_products
            (product_id, variation_id, supplier_id, supplier_sku, unit_cost, currency, exchange_rate, priority, moq, notes, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute(array_merge(
        [$data['product_id'], $data['variation_id'], $data['supplier_id']],
        $values,
        [$userId]
    ));

    $id = (int) $pdo->lastInsertId();
    activity_log($pdo, 'products', 'supplier_source_added', $data['product_id'], 'Added supplier sourcing entry #' . $id . '.');

    return $id;
}

function supplier_product_delete(PDO $pdo, int $id): void
{
    $row = supplier_product_get($pdo, $id);
    if ($row === null) {
        throw new RuntimeException('Sourcing entry not found.');
    }

    $pdo->prepare('DELETE FROM supplier_products WHERE id = ?')->execute([$id]);
    activity_log($pdo, 'products', 'supplier_source_removed', (int) $row['product_id'], 'Removed supplier sourcing entry for ' . $row['supplier_name'] . '.');
}

/**
 * Real purchase history per supplier for one product - DERIVED, never stored. Everything needed
 * already exists on supplier_order_items joined to supplier_orders (who, how much, what currency,
 * when), so a history table would only duplicate it. Cancelled orders are excluded; historical
 * (imported) orders are included, matching the same rules the costing engine uses.
 *
 * Read-only, and deliberately independent of supplier_products - a product can have purchase
 * history from a supplier that was never added to the catalogue, and that history still shows.
 */
function supplier_product_purchase_history(PDO $pdo, int $productId, ?int $variationId = null, int $limit = 50): array
{
    $sql = "
        SELECT so.id AS supplier_order_id, so.purchase_number, so.order_date, so.status,
               so.currency, so.exchange_rate, so.supplier_id, s.name AS supplier_name,
               soi.variation_id, soi.total_quantity, soi.unit_cost_foreign, soi.unit_cost_myr, soi.supplier_price,
               pv.sku AS variation_sku
        FROM supplier_order_items soi
        INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
        LEFT JOIN suppliers s ON s.id = so.supplier_id
        LEFT JOIN product_variations pv ON pv.id = soi.variation_id
        WHERE soi.product_id = ? AND so.status <> 'cancelled'
    ";
    $params = [$productId];

    if ($variationId !== null) {
        $sql .= ' AND soi.variation_id <=> ?';
        $params[] = $variationId;
    }

    $sql .= ' ORDER BY so.order_date DESC, so.id DESC LIMIT ' . max(1, $limit);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Per-supplier rollup of the history above - last paid price, order count, total units, most
 * recent order date. The comparison view a buyer actually wants when choosing where to reorder.
 */
function supplier_product_supplier_summary(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("
        SELECT so.supplier_id, s.name AS supplier_name,
               COUNT(DISTINCT so.id) AS order_count,
               SUM(soi.total_quantity) AS total_units,
               MAX(so.order_date) AS last_order_date
        FROM supplier_order_items soi
        INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
        LEFT JOIN suppliers s ON s.id = so.supplier_id
        WHERE soi.product_id = ? AND so.status <> 'cancelled'
        GROUP BY so.supplier_id, s.name
        ORDER BY last_order_date DESC
    ");
    $stmt->execute([$productId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
