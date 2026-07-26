<?php

/**
 * Phase 9D/9F - Pricing Engine. A separate, read-only planning layer alongside (never inside)
 * includes/product_cost.php's actual Landed Cost engine - see that file's own docblock for
 * "Converted Supplier Cost + Shipping Allocation + Additional Costs" from REAL received
 * supplier orders. This file answers a different question: BEFORE any supplier order exists,
 * what should this product's reference prices/estimated cost look like. Nothing here writes
 * anywhere, and nothing here is read by product_cost.php or product_cost_history - the two
 * systems never mix.
 *
 * Phase 9F moved every field here (except the raw Original Price amount/currency, which stays
 * on the basic product form) onto its own "Price Calculation Setting" tab
 * (modules/products/tabs/pricing.php) - see that file for the editable UI. This file is UI-
 * agnostic and unchanged by that move except for the two formula corrections below.
 *
 * Reused, not duplicated:
 *   - Supplier Price = products.product_cost/cost_currency/exchange_rate (the exact columns
 *     includes/product_cost.php already reads) - no separate supplier_price_* columns exist.
 *   - selling_price remains the single stored, admin-editable final price, set only from
 *     modules/products/edit.php - this engine never computes or writes a selling price.
 *
 * Formulas:
 *   Original Price MYR = original_price x original_exchange_rate (currency NULL means already
 *     MYR, rate 1.0 - a complete state, not missing data; currency set but rate NULL means
 *     genuinely not configured, never assumed 1:1 - same convention as product_cost.php).
 *   Supplier Price MYR = product_cost x exchange_rate (same rule).
 *   Market Price MYR = original_price x market_exchange_rate (Phase 9F, confirmed) - reuses
 *     the SAME Original Price amount as above, just under a different rate assumption; there
 *     is no separate market_price amount/currency in this formula (products.market_price/
 *     market_currency are left unused - see the migration note below).
 *   Supplier Discount % = (Original MYR - Supplier MYR) / Original MYR x 100
 *   Estimated Shipping Cost = weight_grams x shipping_rate_countries.rate_per_gram
 *   Estimated Cost = Supplier Price MYR + Estimated Shipping Cost (partial/"estimated" if
 *     shipping isn't configured yet, same as product_cost.php's is_estimated flag - never
 *     silently treated as zero)
 *
 * Phase 9F explicitly removed: selling multiplier, recommended selling price, and any
 * auto-fill of selling_price - products.selling_price is manually controlled only, from
 * modules/products/edit.php. products.market_price/market_currency and
 * products.selling_multiplier remain in the schema (no destructive column drop) but are no
 * longer read or written by this engine or its UI.
 */

const PRICING_REFERENCE_CURRENCY_OPTIONS = ['MYR', 'JPY', 'HKD', 'USD'];

/**
 * Converts one quoted amount to MYR using the same "currency NULL = already MYR (complete),
 * currency set + rate NULL = genuinely not configured (never assumed 1:1)" rule
 * includes/product_cost.php already uses for Supplier Price - kept as one small internal
 * helper here so Original/Supplier Price below never each reimplement it separately.
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
 * Phase 9F (confirmed) - Market Price RM = Original Price x Market Exchange Rate. Deliberately
 * reuses the Original Price amount rather than a separate Market Price amount - a second,
 * market-specific exchange-rate assumption applied to the same quoted price, not an
 * independently-entered competitor price. Null when either side is missing - never assumed.
 */
function pricing_calculate_market_price(?float $originalPrice, ?float $marketExchangeRate): ?float
{
    if ($originalPrice === null || $marketExchangeRate === null) {
        return null;
    }

    return $originalPrice * $marketExchangeRate;
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
 * product_cost_history. If Supplier Price itself isn't convertible (foreign currency, no rate
 * on file), Estimated Cost is null rather than silently treating it as zero. If Supplier Price
 * IS known but shipping isn't (no weight/origin yet), the cost is still returned - just
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
 * shipping_rate_countries row, if any) - no N+1 for list/report pages. Builds the same full
 * breakdown array both modules/products/tabs/pricing.php (single product) and any future
 * batch consumer would use, so they never diverge.
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
            p.product_cost, p.cost_currency, p.exchange_rate,
            p.original_price, p.original_currency, p.original_exchange_rate,
            p.market_exchange_rate, p.weight_grams, p.shipping_origin_country_id,
            src.country_name AS shipping_origin_country_name,
            src.rate_per_gram AS shipping_rate_per_gram
        FROM products p
        LEFT JOIN shipping_rate_countries src ON src.id = p.shipping_origin_country_id
        WHERE p.id IN ({$placeholders})
    ");
    $stmt->execute($productIds);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[(int) $row['id']] = pricing_build_breakdown($row);
    }

    return $results;
}

function pricing_calculate(PDO $pdo, int $productId): ?array
{
    $batch = pricing_calculate_batch($pdo, [$productId]);

    return $batch[$productId] ?? null;
}

function pricing_build_breakdown(array $row): array
{
    $original = pricing_calculate_original_price(
        $row['original_price'] !== null ? (float) $row['original_price'] : null,
        $row['original_currency'],
        $row['original_exchange_rate'] !== null ? (float) $row['original_exchange_rate'] : null
    );
    $supplier = pricing_calculate_supplier_price(
        (float) $row['product_cost'],
        $row['cost_currency'],
        $row['exchange_rate'] !== null ? (float) $row['exchange_rate'] : null
    );

    $marketExchangeRate = $row['market_exchange_rate'] !== null ? (float) $row['market_exchange_rate'] : null;
    $marketPriceMyr = pricing_calculate_market_price($original['raw'], $marketExchangeRate);

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

        'market_exchange_rate' => $marketExchangeRate,
        'market_price_myr' => $marketPriceMyr,

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
