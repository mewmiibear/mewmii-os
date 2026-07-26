<?php

require_once __DIR__ . '/currency_rates.php';

/**
 * Phase 9D/9F/9F.1 - Pricing Engine. A separate, read-only planning layer alongside (never
 * inside) includes/product_cost.php's actual Landed Cost engine - see that file's own
 * docblock for "Converted Supplier Cost + Shipping Allocation + Additional Costs" from REAL
 * received supplier orders. This file answers a different question: BEFORE any supplier
 * order exists, what should this product's reference prices/estimated cost look like.
 * Nothing here writes anywhere, and nothing here is read by product_cost.php or
 * product_cost_history - the two systems never mix.
 *
 * Phase 9F.1 (Global Currency Exchange Rate Settings) removed manual exchange rate entry
 * entirely: every conversion here now looks up includes/currency_rates.php's centrally-
 * managed currency_rates table instead of a per-product rate column. products.exchange_rate/
 * original_exchange_rate/market_exchange_rate still exist in the schema (no destructive
 * column drop), but only products.exchange_rate is still written anywhere - automatically,
 * by currency_rates_sync_product_exchange_rate(), purely so includes/product_cost.php's
 * actual Landed Cost engine (deliberately never modified) keeps working unmodified. This file
 * never reads any of those three columns anymore.
 *
 * Reused, not duplicated:
 *   - Supplier Price = products.product_cost/cost_currency (the exact columns
 *     includes/product_cost.php already reads for the amount/currency side) - no separate
 *     supplier_price_* columns exist.
 *   - selling_price remains the single stored, admin-editable final price, set only from
 *     modules/products/edit.php - this engine never computes or writes a selling price.
 *
 * Formulas:
 *   Original/Supplier/Market Price MYR = amount x currency_rates.exchange_rate for that
 *     currency (currency NULL means already MYR, rate 1.0 - a complete state, not missing
 *     data; currency set but no currency_rates row for it means genuinely not configured,
 *     never assumed 1:1 - same convention product_cost.php already uses for Supplier Price).
 *   Supplier Discount % = (Original MYR - Supplier MYR) / Original MYR x 100
 *   Estimated Shipping Cost = weight_grams x shipping_rate_countries.rate_per_gram
 *   Estimated Cost = Supplier Price MYR + Estimated Shipping Cost (partial/"estimated" if
 *     shipping isn't configured yet, same as product_cost.php's is_estimated flag - never
 *     silently treated as zero)
 *
 * Explicitly removed (not just moved): selling multiplier, recommended selling price, any
 * auto-fill of selling_price - products.selling_price is manually controlled only, from
 * modules/products/edit.php. products.selling_multiplier remains in the schema but is no
 * longer read or written anywhere.
 */

/**
 * Converts one quoted amount to MYR using the same "currency NULL = already MYR (complete),
 * currency set + no rate on file = genuinely not configured (never assumed 1:1)" rule
 * includes/product_cost.php already uses for Supplier Price - kept as one small internal
 * helper here so Original/Supplier/Market Price below never each reimplement it separately.
 * $exchangeRate is whatever the caller already looked up (from a batched currency_rates
 * lookup, never a second query per amount) - this function does no lookups of its own.
 */
function pricing_convert_to_myr(?float $amount, ?string $currency, ?float $exchangeRate): array
{
    if ($amount === null) {
        return ['raw' => null, 'currency' => $currency, 'exchange_rate' => $exchangeRate, 'configured' => true, 'converted' => null];
    }

    if ($currency === null) {
        $configured = true;
        $effectiveRate = 1.0;
    } elseif ($exchangeRate !== null) {
        $configured = true;
        $effectiveRate = $exchangeRate;
    } else {
        $configured = false;
        $effectiveRate = null;
    }

    return [
        'raw' => $amount,
        'currency' => $currency,
        'exchange_rate' => $exchangeRate,
        'configured' => $configured,
        'converted' => $configured ? ($amount * $effectiveRate) : null,
    ];
}

function pricing_calculate_original_price(?float $originalPrice, ?string $originalCurrency, ?float $originalExchangeRate): array
{
    return pricing_convert_to_myr($originalPrice, $originalCurrency, $originalExchangeRate);
}

function pricing_calculate_supplier_price(?float $supplierPrice, ?string $supplierCurrency, ?float $supplierExchangeRate): array
{
    return pricing_convert_to_myr($supplierPrice, $supplierCurrency, $supplierExchangeRate);
}

/**
 * Market Price - its own quoted amount/currency (a competitor/reseller reference, genuinely
 * independent of Original Price), converted the exact same way as Original/Supplier above.
 */
function pricing_calculate_market_price(?float $marketPrice, ?string $marketCurrency, ?float $marketExchangeRate): array
{
    return pricing_convert_to_myr($marketPrice, $marketCurrency, $marketExchangeRate);
}

/**
 * International Shipping Cost = Weight (grams) x Country Rate Per Gram. Null (not "0") when
 * either side is missing - no shipping origin/weight configured yet is a genuinely unknown
 * cost, not a free one.
 */
function pricing_calculate_shipping_cost(?float $weightGrams, ?float $ratePerGram): ?float
{
    if ($weightGrams === null || $ratePerGram === null) {
        return null;
    }

    return $weightGrams * $ratePerGram;
}

/**
 * Estimated Cost = Supplier Price MYR + Estimated Shipping Cost. This is the planning number
 * this engine is for - it never reads from a real supplier order and never touches
 * product_cost_history. If Supplier Price itself isn't convertible (foreign currency, no
 * rate on file), Estimated Cost is null rather than silently treating it as zero. If Supplier
 * Price IS known but shipping isn't (no weight/origin yet), the cost is still returned - just
 * flagged partial, the same "is_estimated" convention product_cost.php already uses.
 */
function pricing_calculate_estimated_cost(?float $supplierPriceMyr, ?float $shippingCost): array
{
    if ($supplierPriceMyr === null) {
        return ['estimated_cost' => null, 'is_partial' => false];
    }

    return [
        'estimated_cost' => $supplierPriceMyr + ($shippingCost ?? 0.0),
        'is_partial' => $shippingCost === null,
    ];
}

/**
 * Supplier Discount % vs Original Price = (Original MYR - Supplier MYR) / Original MYR x 100.
 * Reference-only comparison (how much cheaper the supplier price is than brand retail) - not
 * used by any other formula here.
 */
function pricing_calculate_supplier_discount_percent(?float $originalPriceMyr, ?float $supplierPriceMyr): ?float
{
    if ($originalPriceMyr === null || $supplierPriceMyr === null || $originalPriceMyr <= 0) {
        return null;
    }

    return (($originalPriceMyr - $supplierPriceMyr) / $originalPriceMyr) * 100;
}

/**
 * One batched query for every product's pricing-engine columns (including a LEFT JOIN to its
 * shipping_rate_countries row, if any), PLUS one batched currency_rates lookup covering every
 * distinct currency used across the whole batch - never one currency_rates query per product,
 * regardless of how many products or distinct currencies are involved (Phase 9F.1
 * performance requirement).
 */
function pricing_calculate_batch(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            p.id, p.selling_price,
            p.product_cost, p.cost_currency,
            p.original_price, p.original_currency,
            p.market_price, p.market_currency,
            p.weight_grams, p.shipping_origin_country_id,
            src.country_name AS shipping_origin_country_name,
            src.rate_per_gram AS shipping_rate_per_gram
        FROM products p
        LEFT JOIN shipping_rate_countries src ON src.id = p.shipping_origin_country_id
        WHERE p.id IN ({$placeholders})
    ");
    $stmt->execute($productIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $currencyCodes = [];
    foreach ($rows as $row) {
        foreach ([$row['cost_currency'], $row['original_currency'], $row['market_currency']] as $code) {
            if ($code !== null) {
                $currencyCodes[] = $code;
            }
        }
    }
    $rateMap = currency_rates_lookup_batch($pdo, $currencyCodes);

    $results = [];
    foreach ($rows as $row) {
        $results[(int) $row['id']] = pricing_build_breakdown($row, $rateMap);
    }

    return $results;
}

function pricing_calculate(PDO $pdo, int $productId): ?array
{
    $batch = pricing_calculate_batch($pdo, [$productId]);

    return $batch[$productId] ?? null;
}

function pricing_build_breakdown(array $row, array $rateMap): array
{
    $original = pricing_calculate_original_price(
        $row['original_price'] !== null ? (float) $row['original_price'] : null,
        $row['original_currency'],
        $row['original_currency'] !== null ? ($rateMap[$row['original_currency']] ?? null) : null
    );
    $supplier = pricing_calculate_supplier_price(
        (float) $row['product_cost'],
        $row['cost_currency'],
        $row['cost_currency'] !== null ? ($rateMap[$row['cost_currency']] ?? null) : null
    );
    $market = pricing_calculate_market_price(
        $row['market_price'] !== null ? (float) $row['market_price'] : null,
        $row['market_currency'],
        $row['market_currency'] !== null ? ($rateMap[$row['market_currency']] ?? null) : null
    );

    $weightGrams = $row['weight_grams'] !== null ? (float) $row['weight_grams'] : null;
    $ratePerGram = $row['shipping_rate_per_gram'] !== null ? (float) $row['shipping_rate_per_gram'] : null;
    $shippingCost = pricing_calculate_shipping_cost($weightGrams, $ratePerGram);

    $estimatedCost = pricing_calculate_estimated_cost($supplier['converted'], $shippingCost);
    $supplierDiscountPercent = pricing_calculate_supplier_discount_percent($original['converted'], $supplier['converted']);

    return [
        'original_price' => $original['raw'],
        'original_currency' => $original['currency'],
        'original_exchange_rate' => $original['exchange_rate'],
        'original_price_configured' => $original['configured'],
        'original_price_myr' => $original['converted'],

        'supplier_price' => $supplier['raw'],
        'supplier_currency' => $supplier['currency'],
        'supplier_exchange_rate' => $supplier['exchange_rate'],
        'supplier_price_configured' => $supplier['configured'],
        'supplier_price_myr' => $supplier['converted'],
        'supplier_discount_percent' => $supplierDiscountPercent,

        'market_price' => $market['raw'],
        'market_currency' => $market['currency'],
        'market_exchange_rate' => $market['exchange_rate'],
        'market_price_configured' => $market['configured'],
        'market_price_myr' => $market['converted'],

        'weight_grams' => $weightGrams,
        'shipping_origin_country_id' => $row['shipping_origin_country_id'] !== null ? (int) $row['shipping_origin_country_id'] : null,
        'shipping_origin_country_name' => $row['shipping_origin_country_name'],
        'shipping_rate_per_gram' => $ratePerGram,
        'shipping_cost' => $shippingCost,

        'estimated_cost' => $estimatedCost['estimated_cost'],
        'estimated_cost_is_partial' => $estimatedCost['is_partial'],

        'selling_price' => (float) $row['selling_price'],
    ];
}

/**
 * Every configured shipping origin country, cheapest first isn't meaningful here so just
 * alphabetical - for modules/products/tabs/pricing.php's dropdown and
 * modules/settings/shipping_rates.php's list.
 */
function pricing_list_shipping_rate_countries(PDO $pdo): array
{
    return $pdo->query('SELECT id, country_name, rate_per_gram FROM shipping_rate_countries ORDER BY country_name ASC')->fetchAll(PDO::FETCH_ASSOC);
}
