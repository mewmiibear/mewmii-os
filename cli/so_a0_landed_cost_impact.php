#!/usr/bin/env php
<?php

/**
 * SO-A0 - Landed Cost Impact Analysis. READ-ONLY, TEMPORARY, THROWAWAY.
 *
 * Measures what would change if SO-A1 (landed cost correctness) were applied, WITHOUT applying
 * it. Answers one question before any production code is touched: how many products' landed
 * cost moves, by how much, and how many "Landed cost increased" notifications that would fire.
 *
 * THIS SCRIPT WRITES NOTHING. Every statement is a SELECT. It never INSERTs, UPDATEs, DELETEs,
 * ALTERs, sends a notification, or calls any mutating function. Safe to run against production.
 *
 * NOT A PERMANENT FEATURE. Delete this file once SO-A1 is decided - it exists only to inform
 * that decision. It is deliberately placed in cli/ so it inherits the two protection layers
 * every script here already has: the PHP_SAPI check below (exits before bootstrap.php or the
 * database are touched under a web request) and cli/.htaccess (server-level deny for the whole
 * directory). It is never reachable by URL, unlike database/migrate_*.php.
 *
 * HOW THE COMPARISON IS BUILT (this matters for trusting the numbers):
 *
 *   CURRENT  = includes/product_cost.php's real product_cost_calculate_batch(), called
 *              unmodified. The "before" column is not a reimplementation - it IS production
 *              behaviour, so it cannot drift from what the app actually shows today.
 *
 *   PROPOSED = includes/product_cost.php's real product_cost_build_breakdown(), called with a
 *              SYNTHETIC product row whose product_cost is the supplier order line cost and
 *              whose cost_currency is NULL. Reusing the production formula and changing only
 *              its input means this script cannot invent a different formula by accident, and
 *              cost_currency = NULL is exactly how the engine already expresses "this value is
 *              already in base currency, do not convert" - which is also the mechanism that
 *              prevents the double-conversion risk (R2) SO-A1 has to avoid.
 *
 *              Shipping Allocation and Additional Costs are carried over from the CURRENT
 *              result unchanged, so the only variable between the two columns is the base
 *              supplier cost. That isolation is the whole point of this measurement.
 *
 * Usage:  php cli/so_a0_landed_cost_impact.php [--limit=N] [--csv=/path/to/out.csv]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/product_cost.php';

$options = getopt('', ['limit::', 'csv::']);
$limit = isset($options['limit']) && ctype_digit((string) $options['limit']) ? (int) $options['limit'] : 0;
$csvPath = isset($options['csv']) && $options['csv'] !== false ? (string) $options['csv'] : null;

$pdo = app_db();

echo str_repeat('=', 100) . PHP_EOL;
echo 'SO-A0 LANDED COST IMPACT ANALYSIS - READ ONLY, NOTHING IS WRITTEN OR SENT' . PHP_EOL;
echo 'Generated: ' . date('Y-m-d H:i:s') . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------------------
// 1. Candidate products. Archived products are excluded - they can't be purchased or sold,
//    so a cost change on one has no business impact and would only inflate the counts.
// ---------------------------------------------------------------------------------------
$productSql = "SELECT id, sku, name, product_cost, cost_currency, exchange_rate, selling_price, catalog_type
               FROM products WHERE status <> 'archived' ORDER BY id ASC";
if ($limit > 0) {
    $productSql .= ' LIMIT ' . $limit;
}
$products = $pdo->query($productSql)->fetchAll(PDO::FETCH_ASSOC);
$productIds = array_map(static fn (array $p): int => (int) $p['id'], $products);

if ($productIds === []) {
    exit("No non-archived products found - nothing to analyse.\n");
}

echo 'Products in scope (status <> archived): ' . count($productIds) . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------------------
// 2. CURRENT landed cost - the real engine, unmodified.
// ---------------------------------------------------------------------------------------
$currentByProduct = product_cost_calculate_batch($pdo, $productIds);

// ---------------------------------------------------------------------------------------
// 3. PROPOSED base cost source: the most recent supplier order line per product.
//
//    Deliberately has NO "shipping_allocated IS NOT NULL OR has additional costs" predicate -
//    that filter is what makes the CURRENT engine miss products that have real purchase
//    history but no allocation yet (defect D3 in the SO-A plan). Removing it here is the
//    point of the exercise.
//
//    Per the approved SO-A rules:
//      - cancelled supplier orders EXCLUDED
//      - is_historical orders INCLUDED (they are real purchases with real prices)
//      - priority unit_cost_myr > 0, then supplier_price > 0, then master cost fallback
//        (0.00 counts as "not set", matching variation_effective_cost()'s existing precedent)
//    Ordered newest-first; the first row seen per product wins.
// ---------------------------------------------------------------------------------------
$placeholders = implode(',', array_fill(0, count($productIds), '?'));
$lineStmt = $pdo->prepare("
    SELECT soi.product_id, soi.variation_id, soi.id AS item_id,
           soi.unit_cost_myr, soi.supplier_price,
           so.id AS order_id, so.status, so.is_historical, so.currency, so.order_date
    FROM supplier_order_items soi
    INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
    WHERE soi.product_id IN ({$placeholders})
      AND so.status <> 'cancelled'
    ORDER BY so.order_date DESC, so.id DESC, soi.id DESC
");
$lineStmt->execute($productIds);

$referenceLine = [];   // product_id => winning line
$lineCount = [];       // product_id => how many non-cancelled lines exist
$hasVariationLine = [];// product_id => any line carries a variation_id
$foreignCurrency = []; // product_id => winning line's order is non-MYR
$historicalUsed = [];  // product_id => winning line's order is is_historical

foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pid = (int) $row['product_id'];
    $lineCount[$pid] = ($lineCount[$pid] ?? 0) + 1;
    if ($row['variation_id'] !== null) {
        $hasVariationLine[$pid] = true;
    }
    if (!isset($referenceLine[$pid])) {
        $referenceLine[$pid] = $row;
        $foreignCurrency[$pid] = strtoupper((string) ($row['currency'] ?? 'MYR')) !== 'MYR';
        $historicalUsed[$pid] = (int) ($row['is_historical'] ?? 0) === 1;
    }
}

// Count cancelled-only products separately, purely to prove the exclusion is doing something.
$cancelledStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT soi.product_id)
    FROM supplier_order_items soi
    INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
    WHERE soi.product_id IN ({$placeholders}) AND so.status = 'cancelled'
");
$cancelledStmt->execute($productIds);
$productsTouchedByCancelledOrders = (int) $cancelledStmt->fetchColumn();

// ---------------------------------------------------------------------------------------
// 4. Build the comparison.
// ---------------------------------------------------------------------------------------
$rows = [];
$counters = [
    'analysed' => 0, 'changed' => 0, 'increased' => 0, 'decreased' => 0, 'unchanged' => 0,
    'src_unit_cost_myr' => 0, 'src_supplier_price' => 0, 'src_master_fallback' => 0,
    'po_history_no_shipping' => 0, 'foreign_currency_order' => 0, 'historical_order_used' => 0,
    'has_variation_history' => 0, 'newly_computable' => 0, 'became_incomputable' => 0,
    'current_null' => 0, 'proposed_null' => 0,
];
$deltas = [];

foreach ($products as $product) {
    $pid = (int) $product['id'];
    $current = $currentByProduct[$pid] ?? null;
    if ($current === null) {
        continue;
    }
    $counters['analysed']++;

    $line = $referenceLine[$pid] ?? null;
    $poCost = null;
    $source = 'master product_cost fallback';

    if ($line !== null) {
        $unitMyr = $line['unit_cost_myr'] !== null ? (float) $line['unit_cost_myr'] : 0.0;
        $supplierPrice = $line['supplier_price'] !== null ? (float) $line['supplier_price'] : 0.0;
        if ($unitMyr > 0) {
            $poCost = $unitMyr;
            $source = 'PO unit_cost_myr';
        } elseif ($supplierPrice > 0) {
            $poCost = $supplierPrice;
            $source = 'PO supplier_price';
        }
    }

    if ($poCost !== null) {
        // Synthetic row: cost_currency NULL => the engine treats it as already-base-currency
        // and applies no conversion. This is the R2 double-conversion guard, expressed using
        // the engine's own existing convention rather than a new flag.
        $syntheticProduct = [
            'product_cost' => $poCost,
            'cost_currency' => null,
            'exchange_rate' => null,
            'selling_price' => $product['selling_price'],
        ];
        $isEstimatedSource = false;
    } else {
        // No usable PO cost - fall back to the product master row exactly as today.
        $syntheticProduct = $product;
        $isEstimatedSource = true;
    }

    $proposed = product_cost_build_breakdown(
        $syntheticProduct,
        $current['shipping_cost'],            // shipping preserved unchanged
        $current['other_costs_breakdown']     // additional costs preserved unchanged
    );

    $counters[$source === 'PO unit_cost_myr' ? 'src_unit_cost_myr'
        : ($source === 'PO supplier_price' ? 'src_supplier_price' : 'src_master_fallback')]++;

    if ($line !== null && $current['shipping_cost'] === null) {
        $counters['po_history_no_shipping']++;
    }
    if (!empty($foreignCurrency[$pid])) {
        $counters['foreign_currency_order']++;
    }
    if (!empty($historicalUsed[$pid])) {
        $counters['historical_order_used']++;
    }
    if (!empty($hasVariationLine[$pid])) {
        $counters['has_variation_history']++;
    }

    $cur = $current['landed_cost'];
    $pro = $proposed['landed_cost'];
    if ($cur === null) {
        $counters['current_null']++;
    }
    if ($pro === null) {
        $counters['proposed_null']++;
    }
    if ($cur === null && $pro !== null) {
        $counters['newly_computable']++;
    }
    if ($cur !== null && $pro === null) {
        $counters['became_incomputable']++;
    }

    $diff = ($cur !== null && $pro !== null) ? round($pro - $cur, 2) : null;
    $diffPct = ($diff !== null && $cur > 0) ? round($diff / $cur * 100, 2) : null;

    if ($diff !== null && abs($diff) >= 0.01) {
        $counters['changed']++;
        $diff > 0 ? $counters['increased']++ : $counters['decreased']++;
        $deltas[] = $diffPct ?? 0.0;
    } elseif ($diff !== null) {
        $counters['unchanged']++;
    }

    $rows[] = [
        'product_id' => $pid,
        'sku' => (string) $product['sku'],
        'name' => (string) $product['name'],
        'current_landed' => $cur,
        'proposed_landed' => $pro,
        'diff_rm' => $diff,
        'diff_pct' => $diffPct,
        'source' => $source,
        'estimated_fallback' => $isEstimatedSource ? 'yes' : 'no',
        'has_variation_history' => !empty($hasVariationLine[$pid]) ? 'yes' : 'no',
        'po_lines_found' => $lineCount[$pid] ?? 0,
    ];
}

// ---------------------------------------------------------------------------------------
// 5. R1 - notification impact. Mirrors includes/notifications.php's cost-increase predicate
//    exactly: compare the LATEST frozen product_cost_history.landed_cost against the live
//    figure, and alert when live > previous AND previous > 0. There is no percentage
//    threshold in that logic, which is precisely why this count matters.
//
//    NOTHING IS SENT. This only counts what the existing generator WOULD produce.
// ---------------------------------------------------------------------------------------
$historyByProduct = product_cost_history_list_batch($pdo, $productIds);
$alertsToday = 0;
$alertsAfter = 0;
$alertsNewlyTriggered = 0;
$proposedByProduct = [];
foreach ($rows as $r) {
    $proposedByProduct[$r['product_id']] = $r['proposed_landed'];
}

foreach ($productIds as $pid) {
    $history = $historyByProduct[$pid] ?? [];
    if ($history === []) {
        continue;
    }
    $previous = (float) $history[count($history) - 1]['landed_cost'];
    if ($previous <= 0) {
        continue;
    }
    $curLanded = $currentByProduct[$pid]['landed_cost'] ?? null;
    $proLanded = $proposedByProduct[$pid] ?? null;

    $firesToday = $curLanded !== null && $curLanded > $previous;
    $firesAfter = $proLanded !== null && $proLanded > $previous;

    if ($firesToday) {
        $alertsToday++;
    }
    if ($firesAfter) {
        $alertsAfter++;
    }
    if ($firesAfter && !$firesToday) {
        $alertsNewlyTriggered++;
    }
}

// ---------------------------------------------------------------------------------------
// 6. Report.
// ---------------------------------------------------------------------------------------
usort($rows, static function (array $a, array $b): int {
    return ($b['diff_rm'] ?? 0) <=> ($a['diff_rm'] ?? 0);
});

$changedRows = array_values(array_filter($rows, static fn (array $r): bool => $r['diff_rm'] !== null && abs($r['diff_rm']) >= 0.01));

echo str_repeat('-', 100) . PHP_EOL;
echo 'PER-PRODUCT CHANGES (products whose landed cost moves)' . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;
printf("%-7s %-34s %12s %12s %10s %9s  %-22s %-4s %s" . PHP_EOL,
    'ID', 'PRODUCT', 'CURRENT', 'PROPOSED', 'DIFF RM', 'DIFF %', 'SOURCE', 'VAR', 'LINES');

if ($changedRows === []) {
    echo '(none - no product landed cost changes under the proposed rules)' . PHP_EOL;
}
foreach ($changedRows as $r) {
    printf("%-7d %-34s %12s %12s %10s %8s%%  %-22s %-4s %d" . PHP_EOL,
        $r['product_id'],
        mb_strimwidth($r['name'], 0, 34, '..'),
        $r['current_landed'] === null ? 'n/a' : number_format($r['current_landed'], 2),
        $r['proposed_landed'] === null ? 'n/a' : number_format($r['proposed_landed'], 2),
        number_format($r['diff_rm'], 2),
        $r['diff_pct'] === null ? 'n/a' : number_format($r['diff_pct'], 1),
        $r['source'],
        $r['has_variation_history'],
        $r['po_lines_found']
    );
}

$avgPct = $deltas !== [] ? array_sum($deltas) / count($deltas) : 0.0;
$increases = array_slice(array_filter($changedRows, static fn (array $r): bool => $r['diff_rm'] > 0), 0, 5);
$decreases = array_slice(array_reverse(array_filter($changedRows, static fn (array $r): bool => $r['diff_rm'] < 0)), 0, 5);

echo PHP_EOL . str_repeat('=', 100) . PHP_EOL;
echo 'SUMMARY' . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;
printf("  Products analysed                    : %d" . PHP_EOL, $counters['analysed']);
printf("  Products changed                     : %d" . PHP_EOL, $counters['changed']);
printf("    - increased                        : %d" . PHP_EOL, $counters['increased']);
printf("    - decreased                        : %d" . PHP_EOL, $counters['decreased']);
printf("  Products unchanged                   : %d" . PHP_EOL, $counters['unchanged']);
printf("  Average change (of changed products) : %s%%" . PHP_EOL, number_format($avgPct, 2));
printf("  Landed cost newly computable         : %d" . PHP_EOL, $counters['newly_computable']);
printf("  Landed cost became incomputable      : %d   <-- must be 0" . PHP_EOL, $counters['became_incomputable']);

echo PHP_EOL . '  Largest increases:' . PHP_EOL;
foreach ($increases as $r) {
    printf("    +%-9s (%6s%%)  #%d %s" . PHP_EOL, number_format($r['diff_rm'], 2),
        $r['diff_pct'] === null ? 'n/a' : number_format($r['diff_pct'], 1), $r['product_id'], $r['name']);
}
if ($increases === []) { echo '    (none)' . PHP_EOL; }

echo PHP_EOL . '  Largest decreases:' . PHP_EOL;
foreach ($decreases as $r) {
    printf("    %-10s (%6s%%)  #%d %s" . PHP_EOL, number_format($r['diff_rm'], 2),
        $r['diff_pct'] === null ? 'n/a' : number_format($r['diff_pct'], 1), $r['product_id'], $r['name']);
}
if ($decreases === []) { echo '    (none)' . PHP_EOL; }

echo PHP_EOL . str_repeat('=', 100) . PHP_EOL;
echo 'R1 - NOTIFICATION IMPACT (counted only, nothing sent)' . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;
printf("  'Landed cost increased' alerts TODAY (current logic)  : %d" . PHP_EOL, $alertsToday);
printf("  'Landed cost increased' alerts AFTER SO-A1            : %d" . PHP_EOL, $alertsAfter);
printf("  NEWLY triggered by SO-A1 (the deploy-day spike)       : %d   <-- the R1 number" . PHP_EOL, $alertsNewlyTriggered);
echo PHP_EOL;
echo '  Note: includes/notifications.php has NO percentage threshold - any increase over the' . PHP_EOL;
echo '  latest frozen product_cost_history.landed_cost fires one alert.' . PHP_EOL;

echo PHP_EOL . str_repeat('=', 100) . PHP_EOL;
echo 'EDGE CASE COUNTS' . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;
printf("  Source: PO unit_cost_myr                        : %d" . PHP_EOL, $counters['src_unit_cost_myr']);
printf("  Source: PO supplier_price (fallback)            : %d" . PHP_EOL, $counters['src_supplier_price']);
printf("  Source: master product_cost (no usable PO cost) : %d" . PHP_EOL, $counters['src_master_fallback']);
printf("  PO history but NO shipping allocation (D3)      : %d" . PHP_EOL, $counters['po_history_no_shipping']);
printf("  Reference line from a foreign-currency order    : %d" . PHP_EOL, $counters['foreign_currency_order']);
printf("  Reference line from an is_historical order      : %d" . PHP_EOL, $counters['historical_order_used']);
printf("  Products with variation-level PO history        : %d" . PHP_EOL, $counters['has_variation_history']);
printf("  Products touched by cancelled orders (excluded) : %d" . PHP_EOL, $productsTouchedByCancelledOrders);
printf("  Current landed cost null (not configured)       : %d" . PHP_EOL, $counters['current_null']);
printf("  Proposed landed cost null                       : %d" . PHP_EOL, $counters['proposed_null']);

if ($csvPath !== null) {
    $fh = fopen($csvPath, 'w');
    if ($fh === false) {
        echo PHP_EOL . 'WARNING: could not open CSV path for writing: ' . $csvPath . PHP_EOL;
    } else {
        fputcsv($fh, ['product_id', 'sku', 'name', 'current_landed_cost', 'proposed_landed_cost',
            'diff_rm', 'diff_pct', 'cost_source', 'estimated_fallback', 'has_variation_history', 'po_lines_found']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['product_id'], $r['sku'], $r['name'], $r['current_landed'], $r['proposed_landed'],
                $r['diff_rm'], $r['diff_pct'], $r['source'], $r['estimated_fallback'],
                $r['has_variation_history'], $r['po_lines_found']]);
        }
        fclose($fh);
        echo PHP_EOL . 'Full per-product CSV written to: ' . $csvPath . PHP_EOL;
        echo '(This is the only file this script writes. The database was never modified.)' . PHP_EOL;
    }
}

echo PHP_EOL . 'Done. No database rows were read-locked, written, or modified; no notification was sent.' . PHP_EOL;
