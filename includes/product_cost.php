<?php

/**
 * Phase 7C - Product Cost Engine. The single reusable place that computes "true landed cost" -
 * product pages, Purchasing, and any future report call these functions instead of each
 * re-deriving their own version. Reads only already-stored values (products.product_cost/
 * cost_currency/exchange_rate/selling_price, supplier_order_items.shipping_allocated) - see
 * database/migrate_product_costing.php, which added those columns in Sprint 13 specifically
 * as prep for this formula and documents the same intent. No forecasting, no automation, no
 * writes - a pure read-only calculation layer.
 *
 * Landed Cost = Converted Supplier Cost + Shipping Allocation + Additional Costs
 *   - Converted Supplier Cost = product_cost, converted via cost_currency/exchange_rate when
 *     the cost was quoted in a foreign currency; cost_currency NULL means "already in the
 *     store's base currency" (no conversion needed - a complete state, not missing data).
 *     cost_currency SET but exchange_rate NULL means the rate genuinely isn't on file -
 *     conversion (and therefore everything downstream) is reported as not configured rather
 *     than silently assumed to be 1:1.
 *   - Shipping Allocation = the most recent supplier_order_items.shipping_allocated on file
 *     for this product (never a guessed default). That column exists but is not yet written
 *     to anywhere in the app, so this will report "not configured" until a future phase adds
 *     an entry point for it - exactly the honest behaviour this engine is required to have.
 *   - Additional Costs = no field exists anywhere in the schema for this yet (no product or
 *     supplier-order column represents it), so it is always reported as not configured. Adding
 *     one is a schema decision for a later phase, not guessed here.
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

    // One batched query for every product's most recent shipping_allocated, not one query per
    // product - ORDER BY so the first row PHP sees per product_id is already the most recent.
    $shippingByProduct = [];
    $shippingStmt = $pdo->prepare("
        SELECT soi.product_id, soi.shipping_allocated
        FROM supplier_order_items soi
        INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
        WHERE soi.product_id IN ({$placeholders}) AND soi.shipping_allocated IS NOT NULL
        ORDER BY so.order_date DESC, so.id DESC
    ");
    $shippingStmt->execute($productIds);
    foreach ($shippingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int) $row['product_id'];
        if (!isset($shippingByProduct[$pid])) {
            $shippingByProduct[$pid] = (float) $row['shipping_allocated'];
        }
    }

    $results = [];
    foreach ($productIds as $productId) {
        if (!isset($productsById[$productId])) {
            continue;
        }
        $results[$productId] = product_cost_build_breakdown($productsById[$productId], $shippingByProduct[$productId] ?? null);
    }

    return $results;
}

function product_cost_calculate(PDO $pdo, int $productId): ?array
{
    $batch = product_cost_calculate_batch($pdo, [$productId]);

    return $batch[$productId] ?? null;
}

function product_cost_build_breakdown(array $product, ?float $shippingAllocated): array
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
    // No column anywhere in the schema represents "additional costs" yet - always reported as
    // not configured rather than inventing a value or a source for one.
    $otherCostsConfigured = false;
    $otherCosts = 0.0;

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
