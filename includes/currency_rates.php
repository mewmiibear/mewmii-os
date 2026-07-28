<?php

/**
 * Phase 9F.1/9F.2 (Multi-Purpose Currency Rate Settings) - currency_rates is the single,
 * centrally-managed source of "1 unit of this currency = ? MYR" (modules/settings/
 * currency_rates.php). No page anywhere asks an admin to type a rate directly onto a product
 * anymore - includes/pricing_engine.php looks this table up live for Original/Supplier/
 * Market Price conversion, and products.exchange_rate (the one column
 * includes/product_cost.php's actual Landed Cost engine still reads - deliberately NOT
 * modified) is kept in sync automatically by currency_rates_sync_product_exchange_rate()/
 * currency_rates_bulk_refresh_products_exchange_rate() below, so that engine keeps working
 * unmodified without anyone ever typing a rate into a product form again.
 *
 * Phase 9F.2 - a single rate per currency code was wrong: Supplier, Original, and Market
 * Price conversion each need their OWN rate even for the same code (JPY's supplier rate,
 * original rate, and market rate are three independent numbers). Every function here now
 * takes a $rateType ('supplier'/'original'/'market') alongside the currency code - a
 * currency+rate_type pair with no row is "not configured", never assumed 1:1.
 *
 * MYR is expected to always be on file at 1.000000 for EACH of the three rate types
 * (seeded by database/migrate_currency_rates.php for 'supplier', added by the admin for
 * 'original'/'market' via the settings page).
 */

const CURRENCY_RATE_OPTIONS = ['MYR', 'JPY', 'HKD', 'USD', 'CNY'];

const CURRENCY_RATE_TYPES = ['supplier', 'original', 'market'];

const CURRENCY_RATE_TYPE_LABELS = [
    'supplier' => 'Supplier Exchange Rates',
    'original' => 'Original Exchange Rates',
    'market' => 'Market Exchange Rates',
];

/**
 * Every configured rate of ONE type, alphabetical by currency - for
 * modules/settings/currency_rates.php's per-type list section.
 */
function currency_rates_list(PDO $pdo, string $rateType): array
{
    $stmt = $pdo->prepare('SELECT id, rate_type, currency_code, exchange_rate FROM currency_rates WHERE rate_type = ? ORDER BY currency_code ASC');
    $stmt->execute([$rateType]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Find one specific row by rate type and currency code.
 */
function currency_rates_find(PDO $pdo, string $rateType, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM currency_rates WHERE rate_type = ? AND currency_code = ? LIMIT 1');
    $stmt->execute([$rateType, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * Fetch all three rows for one currency code, keyed by rate type.
 */
function currency_rates_find_for_currency(PDO $pdo, string $currencyCode): array
{
    $stmt = $pdo->prepare('SELECT * FROM currency_rates WHERE currency_code = ? ORDER BY CASE rate_type WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 ELSE 4 END');
    $stmt->execute([strtoupper(trim($currencyCode)), 'supplier', 'original', 'market']);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[$row['rate_type']] = $row;
    }

    return $rows;
}

/**
 * Return grouped currency rows in a shape convenient for the settings UI.
 */
function currency_rates_list_by_currency(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM currency_rates ORDER BY currency_code ASC, CASE rate_type WHEN "supplier" THEN 1 WHEN "original" THEN 2 WHEN "market" THEN 3 ELSE 4 END');

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grouped[$row['currency_code']][$row['rate_type']] = $row;
    }

    return $grouped;
}

/**
 * Check whether a currency already exists in the rate table for any rate type.
 */
function currency_rates_currency_exists(PDO $pdo, string $currencyCode): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM currency_rates WHERE currency_code = ?');
    $stmt->execute([strtoupper(trim($currencyCode))]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Phase 9G (Inline Pricing & Inventory Calculation UI) - every configured rate for all three
 * types in one shape: ['supplier' => [code => rate], 'original' => [...], 'market' => [...]].
 * One query, not three-per-page-load-times-N-fields - for modules/products/_form.php's
 * inline calculator to embed as JSON and recompute Supplier/Original/Market Price RM live in
 * the browser as the admin types, without a server round-trip per keystroke.
 */
function currency_rates_all_maps(PDO $pdo): array
{
    $maps = [];
    foreach (CURRENCY_RATE_TYPES as $rateType) {
        $map = [];
        foreach (currency_rates_list($pdo, $rateType) as $row) {
            $map[$row['currency_code']] = (float) $row['exchange_rate'];
        }
        $maps[$rateType] = $map;
    }

    return $maps;
}

/**
 * One batched lookup for a set of currency codes WITHIN ONE rate type - returns
 * [code => rate] for only the codes that actually have a configured row for that type; a
 * code with no row is simply absent from the returned array (the "not configured" signal -
 * never assumed 1:1, never defaulted to 0). Used by includes/pricing_engine.php's batch
 * calculation so a list of N products never issues more than one query per rate type,
 * regardless of how many products or distinct currencies they use.
 */
function currency_rates_lookup_batch(PDO $pdo, string $rateType, array $currencyCodes): array
{
    $currencyCodes = array_values(array_unique(array_filter(array_map(
        static fn($code): string => strtoupper(trim((string) $code)),
        $currencyCodes
    ), static fn(string $code): bool => $code !== '')));

    if ($currencyCodes === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($currencyCodes), '?'));
    $stmt = $pdo->prepare("SELECT currency_code, exchange_rate FROM currency_rates WHERE rate_type = ? AND currency_code IN ({$placeholders})");
    $stmt->execute(array_merge([$rateType], $currencyCodes));

    $rates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rates[$row['currency_code']] = (float) $row['exchange_rate'];
    }

    return $rates;
}

/**
 * Single-currency convenience wrapper for one-off lookups (e.g. syncing one product on
 * save) - not for use in a loop over many products, see currency_rates_lookup_batch() for
 * that. $currencyCode === null means "already MYR" (same convention as cost_currency/
 * original_currency/market_currency elsewhere) - returns 1.0, not a database lookup.
 */
function currency_rates_get(PDO $pdo, string $rateType, ?string $currencyCode): ?float
{
    if ($currencyCode === null) {
        return 1.0;
    }

    $rates = currency_rates_lookup_batch($pdo, $rateType, [$currencyCode]);

    return $rates[strtoupper(trim($currencyCode))] ?? null;
}

/**
 * Keeps products.exchange_rate mirroring the current 'supplier' rate for the product's own
 * cost_currency, so includes/product_cost.php's actual Landed Cost engine (unmodified) always
 * reads an up-to-date, centrally-managed value instead of a manually-typed one. Called right
 * after modules/products/create.php/edit.php save product_cost/cost_currency. Always uses
 * rate_type = 'supplier' specifically - that's the category this column corresponds to.
 * NULL cost_currency (already MYR) clears exchange_rate to NULL, matching product_cost.php's
 * own "currency NULL = no conversion needed" convention.
 */
function currency_rates_sync_product_exchange_rate(PDO $pdo, int $productId, ?string $costCurrency): void
{
    $rate = $costCurrency !== null ? currency_rates_get($pdo, 'supplier', $costCurrency) : null;

    $pdo->prepare('UPDATE products SET exchange_rate = ? WHERE id = ?')->execute([$rate, $productId]);
}

/**
 * Refreshes products.exchange_rate for EVERY product using the given currency in one
 * statement - called whenever modules/settings/currency_rates.php saves a new 'supplier' rate
 * for a currency, so already-saved products immediately reflect the updated global rate
 * without needing to be individually re-saved. One UPDATE, not one per product (no N+1).
 * Only meaningful for rate_type = 'supplier' - 'original'/'market' rates are looked up live
 * by includes/pricing_engine.php, not mirrored onto any product column.
 */
function currency_rates_bulk_refresh_products_exchange_rate(PDO $pdo, string $currencyCode, ?float $rate): void
{
    $pdo->prepare('UPDATE products SET exchange_rate = ? WHERE cost_currency = ?')->execute([$rate, $currencyCode]);
}
