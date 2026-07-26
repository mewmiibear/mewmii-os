<?php

/**
 * Phase 7C - Product Cost Engine (completed in Phase 7F). The single reusable place that
 * computes "true landed cost" - product pages, Purchasing, Margin Report, and any future
 * report call these functions instead of each re-deriving their own version. Reads only
 * already-stored values (products.product_cost/cost_currency/exchange_rate/selling_price,
 * supplier_order_items.shipping_allocated, supplier_order_item_costs.amount) - no forecasting,
 * no automation, no writes, a pure read-only calculation layer.
 *
 * Landed Cost = Converted Supplier Cost + Shipping Allocation + Additional Costs
 *   - Converted Supplier Cost = product_cost, converted via cost_currency/exchange_rate when
 *     the cost was quoted in a foreign currency; cost_currency NULL means "already in the
 *     store's base currency" (no conversion needed - a complete state, not missing data).
 *     cost_currency SET but exchange_rate NULL means the rate genuinely isn't on file -
 *     conversion (and therefore everything downstream) is reported as not configured rather
 *     than silently assumed to be 1:1.
 *   - Shipping Allocation = the most recent supplier_order_items.shipping_allocated on file for
 *     this product (never a guessed default) - see Phase 7D.
 *   - Additional Costs (Phase 7F) = every supplier_order_item_costs row (Customs, Handling,
 *     Packaging, Other, or any other cost_type an operator entered) attached to that SAME
 *     supplier order line - Shipping Allocation and Additional Costs are always sourced from
 *     one single reference line per product, never mixed from two different shipments, so a
 *     landed cost is never assembled from unrelated events. See product_cost_calculate_batch()
 *     for how that reference line is chosen, and database/schema.sql's
 *     supplier_order_item_costs table comment for why this lives at the supplier-order-line
 *     level rather than as fixed columns on `products`.
 *
 * If Currency Conversion itself isn't configured, Landed Cost/Gross Profit/Gross Margin are
 * all null ("Not configured") rather than computed from an unconverted foreign-currency number
 * mislabeled as the base currency. If only Shipping/Additional Costs are missing, Landed Cost
 * is still computed from what IS on file, but `is_estimated` is true so callers can flag it
 * rather than presenting a partial number as definitive.
 */

function product_cost_calculate_batch(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $productStmt = $pdo->prepare("
        SELECT id, product_cost, cost_currency, exchange_rate, selling_price
        FROM products
        WHERE id IN ({$placeholders})
    ");
    $productStmt->execute($productIds);
    $productsById = [];
    foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productsById[(int) $row['id']] = $row;
    }

    // One batched query for every product's reference supplier order line - the most recent
    // line (by order_date) that carries EITHER a shipping allocation OR at least one
    // additional-cost row. Both Shipping Allocation and Additional Costs below are always read
    // from this SAME line per product, never independently "most recent" for each, so a landed
    // cost is never assembled by mixing shipping from one shipment with customs from another.
    $referenceByProduct = [];
    $referenceStmt = $pdo->prepare("
        SELECT soi.product_id, soi.id AS item_id, soi.shipping_allocated
        FROM supplier_order_items soi
        INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
        LEFT JOIN (
            SELECT supplier_order_item_id, SUM(amount) AS total_other_cost
            FROM supplier_order_item_costs
            GROUP BY supplier_order_item_id
        ) agg ON agg.supplier_order_item_id = soi.id
        WHERE soi.product_id IN ({$placeholders})
          AND (soi.shipping_allocated IS NOT NULL OR agg.total_other_cost IS NOT NULL)
        ORDER BY so.order_date DESC, so.id DESC
    ");
    $referenceStmt->execute($productIds);
    foreach ($referenceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int) $row['product_id'];
        if (!isset($referenceByProduct[$pid])) {
            $referenceByProduct[$pid] = [
                'item_id' => (int) $row['item_id'],
                'shipping_allocated' => $row['shipping_allocated'] !== null ? (float) $row['shipping_allocated'] : null,
            ];
        }
    }

    // Additional-cost line-item detail, batched for exactly the reference item ids just
    // picked above (one small IN() query, not one per product and not every order line ever
    // placed) - this is both the source of `other_costs` (summed below) and the detailed
    // breakdown shown on the Cost Breakdown card/Margin Report.
    $referenceItemIds = array_values(array_unique(array_column($referenceByProduct, 'item_id')));
    $otherCostsByItem = [];
    if ($referenceItemIds !== []) {
        $itemPlaceholders = implode(',', array_fill(0, count($referenceItemIds), '?'));
        $otherCostsStmt = $pdo->prepare("
            SELECT supplier_order_item_id, cost_type, amount, notes
            FROM supplier_order_item_costs
            WHERE supplier_order_item_id IN ({$itemPlaceholders})
            ORDER BY id ASC
        ");
        $otherCostsStmt->execute($referenceItemIds);
        foreach ($otherCostsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemId = (int) $row['supplier_order_item_id'];
            $otherCostsByItem[$itemId][] = [
                'cost_type' => $row['cost_type'],
                'amount' => (float) $row['amount'],
                'notes' => $row['notes'],
            ];
        }
    }

    $results = [];
    foreach ($productIds as $productId) {
        if (!isset($productsById[$productId])) {
            continue;
        }
        $reference = $referenceByProduct[$productId] ?? null;
        $shippingAllocated = $reference['shipping_allocated'] ?? null;
        $otherCostsBreakdown = $reference !== null ? ($otherCostsByItem[$reference['item_id']] ?? []) : [];

        $results[$productId] = product_cost_build_breakdown($productsById[$productId], $shippingAllocated, $otherCostsBreakdown);
    }

    return $results;
}

function product_cost_calculate(PDO $pdo, int $productId): ?array
{
    $batch = product_cost_calculate_batch($pdo, [$productId]);

    return $batch[$productId] ?? null;
}

function product_cost_build_breakdown(array $product, ?float $shippingAllocated, array $otherCostsBreakdown = []): array
{
    $supplierCost = (float) $product['product_cost'];
    $costCurrency = $product['cost_currency'] ?? null;
    $exchangeRate = $product['exchange_rate'] !== null ? (float) $product['exchange_rate'] : null;

    if ($costCurrency === null) {
        // Already in the base currency - nothing to convert, nothing missing.
        $currencyConfigured = true;
        $effectiveRate = 1.0;
    } elseif ($exchangeRate !== null) {
        $currencyConfigured = true;
        $effectiveRate = $exchangeRate;
    } else {
        // A foreign currency is on file but no rate to convert it - genuinely missing data,
        // never assumed to be 1:1.
        $currencyConfigured = false;
        $effectiveRate = null;
    }
    $convertedCost = $currencyConfigured ? ($supplierCost * $effectiveRate) : null;

    $shippingConfigured = $shippingAllocated !== null;
    // Phase 7F - real additional-cost rows (Customs/Handling/Packaging/Other/etc.) from the
    // same reference supplier order line as Shipping Allocation above. An empty breakdown
    // means genuinely no additional-cost rows exist for this product yet - reported as not
    // configured, never assumed to be 0 the same way Shipping Allocation isn't.
    $otherCostsConfigured = $otherCostsBreakdown !== [];
    $otherCosts = array_sum(array_column($otherCostsBreakdown, 'amount'));

    if ($convertedCost !== null) {
        $landedCost = $convertedCost + ($shippingAllocated ?? 0.0) + $otherCosts;
        $isEstimated = !$shippingConfigured || !$otherCostsConfigured;
    } else {
        $landedCost = null;
        $isEstimated = false;
    }

    $sellingPrice = (float) $product['selling_price'];
    if ($landedCost !== null) {
        $grossProfit = $sellingPrice - $landedCost;
        $grossMarginPercent = $sellingPrice > 0 ? ($grossProfit / $sellingPrice * 100) : null;
    } else {
        $grossProfit = null;
        $grossMarginPercent = null;
    }

    return [
        'supplier_cost' => $supplierCost,
        'cost_currency' => $costCurrency,
        'exchange_rate' => $exchangeRate,
        'currency_configured' => $currencyConfigured,
        'converted_cost' => $convertedCost,
        'shipping_cost' => $shippingAllocated,
        'shipping_configured' => $shippingConfigured,
        'other_costs' => $otherCosts,
        'other_costs_configured' => $otherCostsConfigured,
        'other_costs_breakdown' => $otherCostsBreakdown,
        'landed_cost' => $landedCost,
        'is_estimated' => $isEstimated,
        'selling_price' => $sellingPrice,
        'gross_profit' => $grossProfit,
        'gross_margin_percent' => $grossMarginPercent,
    ];
}

/**
 * Phase 7C.1 (Product Cost Data Entry) - a data-entry-completeness READING, not a cost
 * calculation (it never touches Landed Cost/Gross Profit/Margin math above). Three states:
 *
 *   - 'complete': either cost_currency is NULL (base currency, nothing to configure) or both
 *     cost_currency and exchange_rate are set - product_cost_build_breakdown() can fully
 *     convert this product's cost.
 *   - 'missing_exchange_rate': cost_currency IS set but exchange_rate is NULL - a foreign
 *     currency was recorded (e.g. via CSV import, which can set cost_currency alone) but
 *     nobody has entered the rate to convert it yet. Landed Cost is null until this is fixed.
 *   - 'missing_currency_configuration': cost_currency is NULL (assumed base currency) but the
 *     product's own supplier has a non-MYR default currency (suppliers.currency) - a hint that
 *     this product's cost is likely quoted in that foreign currency too, just never actually
 *     recorded on the product yet. Not an error - just a nudge to double-check, since the
 *     as-saved cost is still being treated (and calculated) as base-currency until confirmed.
 *
 * $supplierCurrency is the assigned supplier's own `currency` column (already looked up by the
 * caller - modules/products/view.php already fetches the supplier row for its own Supplier
 * card, so this adds one column to that existing SELECT rather than a new query).
 */
function product_cost_configuration_status(array $product, ?string $supplierCurrency): string
{
    $costCurrency = $product['cost_currency'] ?? null;
    $exchangeRate = $product['exchange_rate'] ?? null;

    if ($costCurrency !== null && $exchangeRate === null) {
        return 'missing_exchange_rate';
    }
    if ($costCurrency === null && $supplierCurrency !== null && strtoupper($supplierCurrency) !== 'MYR') {
        return 'missing_currency_configuration';
    }

    return 'complete';
}

/**
 * Phase 8C (Product Cost History) - freezes product_cost_calculate_batch()'s Landed Cost
 * breakdown into product_cost_history at the moment a supplier order is received or completed.
 * Called from includes/supplier_orders.php (supplier_order_receive_item(), when an order
 * becomes fully received) and includes/supplier_order_import.php (a historical order imported
 * directly with status 'received'/'completed', which never goes through the normal receiving
 * flow) - see database/schema.sql's product_cost_history comment for why a frozen snapshot is
 * needed at all. This is the ONLY place that writes to product_cost_history; nothing here
 * duplicates the Landed Cost formula itself - every number comes straight from
 * product_cost_calculate_batch(), unchanged.
 *
 * A product whose Landed Cost isn't currently computable (currency not configured) is skipped
 * rather than snapshotting an incomplete/guessed value - the same "don't invent data" rule the
 * engine already applies everywhere else.
 */
function product_cost_history_capture(PDO $pdo, int $orderId): void
{
    $itemsStmt = $pdo->prepare('SELECT id, product_id, variation_id FROM supplier_order_items WHERE supplier_order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($items === []) {
        return;
    }

    $orderStmt = $pdo->prepare('SELECT supplier_id FROM supplier_orders WHERE id = ?');
    $orderStmt->execute([$orderId]);
    $supplierIdRaw = $orderStmt->fetchColumn();
    $supplierId = $supplierIdRaw !== false && $supplierIdRaw !== null ? (int) $supplierIdRaw : null;

    $productIds = array_map(static fn (array $item): int => (int) $item['product_id'], $items);
    $costByProduct = product_cost_calculate_batch($pdo, $productIds);

    $insertStmt = $pdo->prepare('
        INSERT INTO product_cost_history
            (product_id, variation_id, supplier_id, supplier_order_id, supplier_order_item_id,
             supplier_cost, cost_currency, exchange_rate, converted_cost, shipping_cost, other_costs, landed_cost)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    foreach ($items as $item) {
        $productId = (int) $item['product_id'];
        $cost = $costByProduct[$productId] ?? null;
        if ($cost === null || $cost['landed_cost'] === null) {
            continue;
        }

        $insertStmt->execute([
            $productId,
            $item['variation_id'] !== null ? (int) $item['variation_id'] : null,
            $supplierId,
            $orderId,
            (int) $item['id'],
            $cost['supplier_cost'],
            $cost['cost_currency'],
            $cost['exchange_rate'],
            $cost['converted_cost'],
            $cost['shipping_cost'],
            $cost['other_costs'],
            $cost['landed_cost'],
        ]);
    }
}

/**
 * Every frozen snapshot for one product, oldest first - for modules/products/view.php's Cost
 * History section (single product, single query).
 */
function product_cost_history_list_for_product(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('
        SELECT h.*, s.name AS supplier_name, so.purchase_number
        FROM product_cost_history h
        LEFT JOIN suppliers s ON s.id = h.supplier_id
        INNER JOIN supplier_orders so ON so.id = h.supplier_order_id
        WHERE h.product_id = ?
        ORDER BY h.captured_at ASC, h.id ASC
    ');
    $stmt->execute([$productId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Batched version of product_cost_history_list_for_product() for modules/reports/cost_history.php
 * - one query for every product's full snapshot list, not one query per product. Returns
 * product_id => list of snapshots, oldest first (same shape/order as the single-product
 * function above).
 */
function product_cost_history_list_batch(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("
        SELECT h.*, s.name AS supplier_name, so.purchase_number
        FROM product_cost_history h
        LEFT JOIN suppliers s ON s.id = h.supplier_id
        INNER JOIN supplier_orders so ON so.id = h.supplier_order_id
        WHERE h.product_id IN ({$placeholders})
        ORDER BY h.product_id ASC, h.captured_at ASC, h.id ASC
    ");
    $stmt->execute($productIds);

    $byProduct = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byProduct[(int) $row['product_id']][] = $row;
    }

    return $byProduct;
}

/**
 * Every distinct product_id that has at least one snapshot - the candidate list for
 * modules/reports/cost_history.php, so that page never has to scan the whole product catalog
 * looking for one with history.
 */
function product_cost_history_product_ids(PDO $pdo): array
{
    return array_map('intval', $pdo->query('SELECT DISTINCT product_id FROM product_cost_history')->fetchAll(PDO::FETCH_COLUMN));
}
