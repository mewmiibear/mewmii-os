<?php

/**
 * Phase 9F.1 (Global Currency Exchange Rate Settings) - currency_rates is the single,
 * centrally-managed source of "1 unit of this currency = ? MYR" (modules/settings/
 * currency_rates.php). No page anywhere asks an admin to type a rate directly onto a
 * product anymore - includes/pricing_engine.php looks this table up live for Original/
 * Supplier/Market Price conversion, and products.exchange_rate (the one column
 * includes/product_cost.php's actual Landed Cost engine still reads - deliberately NOT
 * modified by this phase) is kept in sync automatically by currency_rates_sync_product_
 * exchange_rate()/currency_rates_bulk_refresh_products_exchange_rate() below, so that engine
 * keeps working unmodified without anyone ever typing a rate into a product form again.
 *
 * A currency with no row here is "not configured" - never assumed 1:1. MYR is expected to
 * always be on file at 1.000000 (seeded by database/migrate_currency_rates.php).
 */

const CURRENCY_RATE_OPTIONS = ['MYR', 'JPY', 'HKD', 'USD', 'CNY'];

/**
 * Every configured currency rate, alphabetical - for modules/settings/currency_rates.php's
 * list and any admin-facing display.
 */
function currency_rates_list(PDO $pdo): array
{
    return $pdo->query('SELECT id, currency_code, exchange_rate FROM currency_rates ORDER BY currency_code ASC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One batched lookup for a set of currency codes - returns [code => rate] for only the codes
 * that actually have a configured row; a code with no row is simply absent from the returned
 * array (the "not configured" signal - never assumed 1:1, never defaulted to 0). Used by
 * includes/pricing_engine.php's batch calculation so a list of N products never issues more
 * than one currency_rates query regardless of how many distinct currencies they use.
 */
function currency_rates_lookup_batch(PDO $pdo, array $currencyCodes): array
{
    $currencyCodes = array_values(array_unique(array_filter(array_map(
        static fn ($code): string => strtoupper(trim((string) $code)),
        $currencyCodes
    ), static fn (string $code): bool => $code !== '')));

    if ($currencyCodes === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($currencyCodes), '?'));
    $stmt = $pdo->prepare("SELECT currency_code, exchange_rate FROM currency_rates WHERE currency_code IN ({$placeholders})");
    $stmt->execute($currencyCodes);

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
function currency_rates_get(PDO $pdo, ?string $currencyCode): ?float
{
    if ($currencyCode === null) {
        return 1.0;
    }

    $rates = currency_rates_lookup_batch($pdo, [$currencyCode]);

    return $rates[strtoupper(trim($currencyCode))] ?? null;
}

/**
 * Keeps products.exchange_rate mirroring the current global rate for the product's own
 * cost_currency, so includes/product_cost.php's actual Landed Cost engine (unmodified) always
 * reads an up-to-date, centrally-managed value instead of a manually-typed one. Called right
 * after modules/products/create.php/edit.php save product_cost/cost_currency. NULL
 * cost_currency (already MYR) clears exchange_rate to NULL, matching product_cost.php's own
 * "currency NULL = no conversion needed" convention.
 */
function currency_rates_sync_product_exchange_rate(PDO $pdo, int $productId, ?string $costCurrency): void
{
    $rate = $costCurrency !== null ? currency_rates_get($pdo, $costCurrency) : null;

    $pdo->prepare('UPDATE products SET exchange_rate = ? WHERE id = ?')->execute([$rate, $productId]);
}

/**
 * Refreshes products.exchange_rate for EVERY product using the given currency in one
 * statement - called whenever modules/settings/currency_rates.php saves a new rate for a
 * currency, so already-saved products immediately reflect the updated global rate without
 * needing to be individually re-saved. One UPDATE, not one per product (no N+1).
 */
function currency_rates_bulk_refresh_products_exchange_rate(PDO $pdo, string $currencyCode, ?float $rate): void
{
    $pdo->prepare('UPDATE products SET exchange_rate = ? WHERE cost_currency = ?')->execute([$rate, $currencyCode]);
}
