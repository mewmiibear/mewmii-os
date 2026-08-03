#!/usr/bin/env php
<?php

/**
 * SO-A2A - Variation-Level Costing Impact Measurement. READ-ONLY, TEMPORARY, THROWAWAY.
 *
 * Measures what would change if the Inventory Report moved from PRODUCT-level costing to
 * VARIATION-level costing (SO-A2B), WITHOUT changing anything. Same philosophy and the same
 * guarantees as cli/so_a0_landed_cost_impact.php, which gated SO-A1.
 *
 * THIS SCRIPT WRITES NOTHING TO THE DATABASE. Every statement is a SELECT. No INSERT, UPDATE,
 * DELETE, ALTER, transaction, activity_log(), notification, or history capture. No production
 * function is modified and no production behaviour changes by running it. The only write of any
 * kind is the optional --csv export to a path you choose.
 *
 * NOT A PERMANENT FEATURE. Delete once SO-A2B is decided. Placed in cli/ so it inherits both
 * protections every script here has: the PHP_SAPI check below (exits before bootstrap.php or the
 * database are touched under a web request) and cli/.htaccess (server-level deny for the whole
 * directory). Unlike database/migrate_*.php it is never reachable by URL.
 *
 * HOW THE COMPARISON IS BUILT
 *
 *   CURRENT  = exactly what modules/reports/inventory.php does today: one
 *              product_cost_calculate_batch() call, then EVERY sellable unit of a product is
 *              valued at that single product-level landed cost. Not a reimplementation - it
 *              calls the real production function, so the "before" column cannot drift.
 *
 *   PROPOSED = per-unit costing, built by calling the real product_cost_build_breakdown() with
 *              per-unit inputs. Nothing here re-derives the landed cost formula; only its INPUTS
 *              change. Three inputs differ from today:
 *
 *                1. Purchase cost - newest non-cancelled supplier order line for THAT EXACT
 *                   product+variation (not the product as a whole), unit_cost_myr then
 *                   supplier_price. Cancelled excluded, is_historical included, 0.00 = not set,
 *                   newest-line-only - identical rules to SO-A1's Lookup A, just at unit grain.
 *
 *                2. Master-cost fallback - variation_effective_cost(pv.cost_price,
 *                   p.product_cost) instead of raw p.product_cost. This is defect D4 from the
 *                   SO-A2 architecture review: the engine currently ignores a variation's own
 *                   cost_price entirely. Reuses the existing helper, never a second copy of the
 *                   rule.
 *
 *                3. Selling price - the unit's own effective price via the existing
 *                   variation_effective_price() + catalog_product_effective_price() helpers
 *                   (price_mode/custom_price AND sale windows). This is defect D5. These helpers
 *                   are REUSED, never reimplemented - duplicating live pricing logic inside the
 *                   costing engine is exactly what .ai/AI_GUIDE.md forbids.
 *
 *              Shipping Allocation and Additional Costs are also resolved per product+variation
 *              rather than per product. A variation with no allocation of its own reports "not
 *              configured" instead of borrowing a sibling's - deliberately no cross-variation
 *              inheritance, since attributing one variation's costs to another is the exact
 *              error SO-A2 exists to remove.
 *
 * Usage:  php cli/so_a2a_variation_cost_impact.php [--limit=N] [--csv=/path/out.csv] [--all-units]
 *
 *   --limit=N     cap products scanned (smoke test)
 *   --csv=PATH    write the full per-unit table
 *   --all-units   include simple products too (default: variable products only, since simple
 *                 products have exactly one unit and cannot change)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/product_cost.php';
require_once __DIR__ . '/../includes/product_variations.php';

$options = getopt('', ['limit::', 'csv::', 'all-units']);
$limit = isset($options['limit']) && ctype_digit((string) $options['limit']) ? (int) $options['limit'] : 0;
$csvPath = isset($options['csv']) && $options['csv'] !== false ? (string) $options['csv'] : null;
$allUnits = array_key_exists('all-units', $options);

$pdo = app_db();

echo str_repeat('=', 104) . PHP_EOL;
echo 'SO-A2A VARIATION-LEVEL COSTING IMPACT - READ ONLY, NOTHING WRITTEN OR SENT' . PHP_EOL;
echo 'Generated: ' . date('Y-m-d H:i:s') . PHP_EOL;
echo str_repeat('=', 104) . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------------------
// 1. Sellable units, filtered the SAME way modules/reports/inventory.php filters them, so the
//    valuation figures below are comparable to what that page actually shows.
// ---------------------------------------------------------------------------------------
$units = array_values(array_filter(
    catalog_sellable_units($pdo),
    static fn (array $u): bool => $u['product_type'] === 'ready_stock' && ($u['status'] ?? 'draft') !== 'archived'
));

if (!$allUnits) {
    $units = array_values(array_filter($units, static fn (array $u): bool => $u['variation_id'] !== null));
}

if ($limit > 0) {
    $keepProducts = array_slice(array_values(array_unique(array_column($units, 'product_id'))), 0, $limit);
    $keepLookup = array_flip($keepProducts);
    $units = array_values(array_filter($units, static fn (array $u): bool => isset($keepLookup[$u['product_id']])));
}

if ($units === []) {
    exit("No matching sellable units found - nothing to analyse.\n");
}

$productIds = array_values(array_unique(array_map(static fn (array $u): int => (int) $u['product_id'], $units)));
$variationIds = array_values(array_filter(array_map(static fn (array $u) => $u['variation_id'], $units)));

echo 'Products analysed        : ' . count($productIds) . PHP_EOL;
echo 'Sellable units analysed  : ' . count($units) . ' (' . count($variationIds) . ' variations)' . PHP_EOL;
echo 'Scope                    : ' . ($allUnits ? 'all ready_stock units' : 'variable-product units only') . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------------------
// 2. CURRENT - the real production path, unmodified.
// ---------------------------------------------------------------------------------------
$currentByProduct = product_cost_calculate_batch($pdo, $productIds);

// ---------------------------------------------------------------------------------------
// 3. On-hand quantities, same source and keying modules/reports/inventory.php uses.
// ---------------------------------------------------------------------------------------
$stockByUnit = [];
$stockRows = $pdo->query('SELECT product_id, variation_id, available_quantity FROM mewmii_inventory')->fetchAll(PDO::FETCH_ASSOC);
foreach ($stockRows as $row) {
    $stockByUnit[(int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0)] = (int) $row['available_quantity'];
}

// ---------------------------------------------------------------------------------------
// 4. Master-cost inputs per product (for the fallback path) - product_cost, cost_currency,
//    exchange_rate. Plus each variation's own cost_price for variation_effective_cost().
// ---------------------------------------------------------------------------------------
$ph = implode(',', array_fill(0, count($productIds), '?'));
$prodStmt = $pdo->prepare("SELECT id, product_cost, cost_currency, exchange_rate FROM products WHERE id IN ({$ph})");
$prodStmt->execute($productIds);
$productMaster = [];
foreach ($prodStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $productMaster[(int) $row['id']] = $row;
}

$variationCostPrice = [];
if ($variationIds !== []) {
    $vph = implode(',', array_fill(0, count($variationIds), '?'));
    $varStmt = $pdo->prepare("SELECT id, cost_price FROM product_variations WHERE id IN ({$vph})");
    $varStmt->execute($variationIds);
    foreach ($varStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $variationCostPrice[(int) $row['id']] = $row['cost_price'];
    }
}

// ---------------------------------------------------------------------------------------
// 5. Unit-level Lookup A - newest non-cancelled PO line per product+variation.
//    Same rules as SO-A1's Lookup A, one grain finer.
// ---------------------------------------------------------------------------------------
$unitPurchase = [];   // "pid:vid" => ['cost' => float, 'source' => string]
$unitDecided = [];
$unitPoLineCount = [];
$unitForeign = [];
$unitHistorical = [];

$poStmt = $pdo->prepare("
    SELECT soi.product_id, soi.variation_id, soi.unit_cost_myr, soi.supplier_price,
           so.currency, so.is_historical
    FROM supplier_order_items soi
    INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
    WHERE soi.product_id IN ({$ph})
      AND so.status <> 'cancelled'
    ORDER BY so.order_date DESC, so.id DESC, soi.id DESC
");
$poStmt->execute($productIds);
foreach ($poStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0);
    $unitPoLineCount[$key] = ($unitPoLineCount[$key] ?? 0) + 1;
    if (isset($unitDecided[$key])) {
        continue;
    }
    $unitDecided[$key] = true;

    $unitMyr = $row['unit_cost_myr'] !== null ? (float) $row['unit_cost_myr'] : 0.0;
    $supplierPrice = $row['supplier_price'] !== null ? (float) $row['supplier_price'] : 0.0;
    if ($unitMyr > 0) {
        $unitPurchase[$key] = ['cost' => $unitMyr, 'source' => 'variation PO unit_cost_myr'];
    } elseif ($supplierPrice > 0) {
        $unitPurchase[$key] = ['cost' => $supplierPrice, 'source' => 'variation PO supplier_price'];
    }
    if (isset($unitPurchase[$key])) {
        $unitForeign[$key] = strtoupper((string) ($row['currency'] ?? 'MYR')) !== 'MYR';
        $unitHistorical[$key] = (int) ($row['is_historical'] ?? 0) === 1;
    }
}

$cancelledStmt = $pdo->prepare("
    SELECT COUNT(*) FROM supplier_order_items soi
    INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
    WHERE soi.product_id IN ({$ph}) AND so.status = 'cancelled'
");
$cancelledStmt->execute($productIds);
$cancelledLinesExcluded = (int) $cancelledStmt->fetchColumn();

// ---------------------------------------------------------------------------------------
// 6. Unit-level Lookup B - shipping allocation + additional costs per product+variation.
//    Same predicate as the production reference-line query, one grain finer. No
//    cross-variation inheritance.
// ---------------------------------------------------------------------------------------
$unitShipping = [];
$unitOtherCosts = [];
$unitRefDecided = [];

$refStmt = $pdo->prepare("
    SELECT soi.product_id, soi.variation_id, soi.id AS item_id, soi.shipping_allocated
    FROM supplier_order_items soi
    INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
    LEFT JOIN (
        SELECT supplier_order_item_id, SUM(amount) AS total_other_cost
        FROM supplier_order_item_costs GROUP BY supplier_order_item_id
    ) agg ON agg.supplier_order_item_id = soi.id
    WHERE soi.product_id IN ({$ph})
      AND (soi.shipping_allocated IS NOT NULL OR agg.total_other_cost IS NOT NULL)
    ORDER BY so.order_date DESC, so.id DESC, soi.id DESC
");
$refStmt->execute($productIds);
$refItemIds = [];
foreach ($refStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0);
    if (isset($unitRefDecided[$key])) {
        continue;
    }
    $unitRefDecided[$key] = true;
    $unitShipping[$key] = $row['shipping_allocated'] !== null ? (float) $row['shipping_allocated'] : null;
    $refItemIds[$key] = (int) $row['item_id'];
}

$costsByItem = [];
if ($refItemIds !== []) {
    $ids = array_values(array_unique($refItemIds));
    $iph = implode(',', array_fill(0, count($ids), '?'));
    $cStmt = $pdo->prepare("SELECT supplier_order_item_id, cost_type, amount, notes
                            FROM supplier_order_item_costs WHERE supplier_order_item_id IN ({$iph}) ORDER BY id ASC");
    $cStmt->execute($ids);
    foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $costsByItem[(int) $row['supplier_order_item_id']][] = [
            'cost_type' => $row['cost_type'], 'amount' => (float) $row['amount'], 'notes' => $row['notes'],
        ];
    }
}
foreach ($refItemIds as $key => $itemId) {
    $unitOtherCosts[$key] = $costsByItem[$itemId] ?? [];
}

// ---------------------------------------------------------------------------------------
// 7. Compare.
// ---------------------------------------------------------------------------------------
$rows = [];
$c = [
    'changed' => 0, 'increased' => 0, 'decreased' => 0, 'unchanged' => 0,
    'src_variation_po' => 0, 'src_variation_cost_price' => 0, 'src_parent_cost' => 0,
    'price_inherited' => 0, 'price_custom' => 0,
    'foreign_po' => 0, 'historical_po' => 0, 'no_usable_po' => 0,
    'with_shipping' => 0, 'without_shipping' => 0,
    'newly_computable' => 0, 'became_incomputable' => 0,
];
$productsAffected = [];
$currentValuation = 0.0;
$proposedValuation = 0.0;
$currentMarginSum = 0.0;
$proposedMarginSum = 0.0;
$marginSamples = 0;

// price_mode per variation, to classify inherited vs custom selling price.
$priceModeByVariation = [];
if ($variationIds !== []) {
    $vph = implode(',', array_fill(0, count($variationIds), '?'));
    $pmStmt = $pdo->prepare("SELECT id, price_mode, custom_price FROM product_variations WHERE id IN ({$vph})");
    $pmStmt->execute($variationIds);
    foreach ($pmStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $priceModeByVariation[(int) $row['id']] = $row;
    }
}

foreach ($units as $unit) {
    $pid = (int) $unit['product_id'];
    $vid = $unit['variation_id'] !== null ? (int) $unit['variation_id'] : null;
    $key = $pid . ':' . ($vid ?? 0);

    $current = $currentByProduct[$pid] ?? null;
    if ($current === null || !isset($productMaster[$pid])) {
        continue;
    }

    $master = $productMaster[$pid];
    $purchase = $unitPurchase[$key] ?? null;
    $shipping = $unitShipping[$key] ?? null;
    $otherCosts = $unitOtherCosts[$key] ?? [];

    // Unit's own effective selling price (D5) - already resolved by catalog_sellable_units()
    // using the production helpers. Reused, never recomputed here.
    $unitSellingPrice = (float) $unit['selling_price'];

    if ($purchase !== null) {
        $synthetic = ['product_cost' => $purchase['cost'], 'cost_currency' => null,
                      'exchange_rate' => null, 'selling_price' => $unitSellingPrice];
        $proposed = product_cost_build_breakdown($synthetic, $shipping, $otherCosts, $purchase['cost']);
        $source = $purchase['source'];
        $c['src_variation_po']++;
        if (!empty($unitForeign[$key])) { $c['foreign_po']++; }
        if (!empty($unitHistorical[$key])) { $c['historical_po']++; }
    } else {
        // D4: variation's own cost_price wins over the parent's, via the existing helper.
        $c['no_usable_po']++;
        $rawVariationCost = $vid !== null ? ($variationCostPrice[$vid] ?? null) : null;
        $effectiveCost = variation_effective_cost($rawVariationCost, $master['product_cost']);
        $usedVariationOwnCost = $rawVariationCost !== null && (float) $rawVariationCost > 0;
        $source = $usedVariationOwnCost ? 'variation cost_price fallback' : 'parent product_cost fallback';
        $usedVariationOwnCost ? $c['src_variation_cost_price']++ : $c['src_parent_cost']++;

        $synthetic = ['product_cost' => $effectiveCost, 'cost_currency' => $master['cost_currency'],
                      'exchange_rate' => $master['exchange_rate'], 'selling_price' => $unitSellingPrice];
        $proposed = product_cost_build_breakdown($synthetic, $shipping, $otherCosts);
    }

    $shipping !== null ? $c['with_shipping']++ : $c['without_shipping']++;

    if ($vid !== null && isset($priceModeByVariation[$vid])) {
        ($priceModeByVariation[$vid]['price_mode'] === 'custom' && $priceModeByVariation[$vid]['custom_price'] !== null)
            ? $c['price_custom']++ : $c['price_inherited']++;
    } else {
        $c['price_inherited']++;
    }

    $cur = $current['landed_cost'];
    $pro = $proposed['landed_cost'];
    if ($cur === null && $pro !== null) { $c['newly_computable']++; }
    if ($cur !== null && $pro === null) { $c['became_incomputable']++; }

    $onHand = $stockByUnit[$key] ?? 0;
    if ($cur !== null) { $currentValuation += $onHand * $cur; }
    if ($pro !== null) { $proposedValuation += $onHand * $pro; }

    if ($current['gross_margin_percent'] !== null && $proposed['gross_margin_percent'] !== null) {
        $currentMarginSum += $current['gross_margin_percent'];
        $proposedMarginSum += $proposed['gross_margin_percent'];
        $marginSamples++;
    }

    $diff = ($cur !== null && $pro !== null) ? round($pro - $cur, 2) : null;
    $diffPct = ($diff !== null && $cur > 0) ? round($diff / $cur * 100, 2) : null;

    $reason = 'unchanged';
    if ($diff !== null && abs($diff) >= 0.01) {
        $c['changed']++;
        $diff > 0 ? $c['increased']++ : $c['decreased']++;
        $productsAffected[$pid] = true;
        if ($purchase !== null) {
            $reason = 'variation has its own PO price, differs from the product-level figure';
        } elseif ($source === 'variation cost_price fallback') {
            $reason = 'variation cost_price now honoured (D4) instead of parent product_cost';
        } else {
            $reason = 'shipping/additional costs resolved per variation instead of per product';
        }
    } elseif ($diff !== null) {
        $c['unchanged']++;
    }

    $rows[] = [
        'product_id' => $pid, 'variation_id' => $vid, 'sku' => (string) $unit['sku'],
        'label' => (string) $unit['label'],
        'current' => $cur, 'proposed' => $pro, 'diff' => $diff, 'diff_pct' => $diffPct,
        'source' => $source, 'reason' => $reason, 'on_hand' => $onHand,
        'po_lines' => $unitPoLineCount[$key] ?? 0,
        'selling_price' => $unitSellingPrice,
    ];
}

// ---------------------------------------------------------------------------------------
// 8. Output.
// ---------------------------------------------------------------------------------------
$changedRows = array_values(array_filter($rows, static fn (array $r): bool => $r['diff'] !== null && abs($r['diff']) >= 0.01));
usort($changedRows, static fn (array $a, array $b): int => abs($b['diff']) <=> abs($a['diff']));

echo str_repeat('-', 104) . PHP_EOL;
echo 'PER-VARIATION CHANGES' . PHP_EOL;
echo str_repeat('-', 104) . PHP_EOL;
printf("%-8s %-30s %10s %10s %9s %8s  %-30s" . PHP_EOL,
    'VAR ID', 'PRODUCT / VARIATION', 'CURRENT', 'PROPOSED', 'DIFF', 'DIFF %', 'SOURCE');
if ($changedRows === []) {
    echo '(none - no variation landed cost changes under the proposed rules)' . PHP_EOL;
}
foreach ($changedRows as $r) {
    printf("%-8s %-30s %10s %10s %9s %7s%%  %-30s" . PHP_EOL,
        $r['variation_id'] ?? '-',
        mb_strimwidth($r['label'], 0, 30, '..'),
        $r['current'] === null ? 'n/a' : number_format($r['current'], 2),
        $r['proposed'] === null ? 'n/a' : number_format($r['proposed'], 2),
        number_format($r['diff'], 2),
        $r['diff_pct'] === null ? 'n/a' : number_format($r['diff_pct'], 1),
        $r['source']);
    echo str_repeat(' ', 10) . 'reason: ' . $r['reason'] . PHP_EOL;
}

echo PHP_EOL . str_repeat('=', 104) . PHP_EOL;
echo 'SUMMARY' . PHP_EOL;
echo str_repeat('=', 104) . PHP_EOL;
printf("  Products analysed                 : %d" . PHP_EOL, count($productIds));
printf("  Sellable units analysed           : %d" . PHP_EOL, count($rows));
printf("  Variations affected               : %d" . PHP_EOL, $c['changed']);
printf("  Products affected                 : %d" . PHP_EOL, count($productsAffected));
printf("    - increased                     : %d" . PHP_EOL, $c['increased']);
printf("    - decreased                     : %d" . PHP_EOL, $c['decreased']);
printf("    - unchanged                     : %d" . PHP_EOL, $c['unchanged']);
printf("  Newly computable                  : %d" . PHP_EOL, $c['newly_computable']);
printf("  Became incomputable               : %d   <-- must be 0" . PHP_EOL, $c['became_incomputable']);
echo PHP_EOL;
printf("  Inventory valuation CURRENT       : RM %s" . PHP_EOL, number_format($currentValuation, 2));
printf("  Inventory valuation PROPOSED      : RM %s" . PHP_EOL, number_format($proposedValuation, 2));
printf("  Valuation change                  : RM %s (%s%%)" . PHP_EOL,
    number_format($proposedValuation - $currentValuation, 2),
    $currentValuation > 0 ? number_format(($proposedValuation - $currentValuation) / $currentValuation * 100, 2) : 'n/a');
echo PHP_EOL;
if ($marginSamples > 0) {
    printf("  Avg gross margin CURRENT          : %s%%" . PHP_EOL, number_format($currentMarginSum / $marginSamples, 2));
    printf("  Avg gross margin PROPOSED         : %s%%" . PHP_EOL, number_format($proposedMarginSum / $marginSamples, 2));
    printf("  Margin change                     : %s pp (over %d units)" . PHP_EOL,
        number_format(($proposedMarginSum - $currentMarginSum) / $marginSamples, 2), $marginSamples);
} else {
    echo '  Margin comparison                 : no unit had both figures computable' . PHP_EOL;
}

echo PHP_EOL . str_repeat('=', 104) . PHP_EOL;
echo 'EDGE CASE COUNTS' . PHP_EOL;
echo str_repeat('=', 104) . PHP_EOL;
printf("  Variation PO price used                     : %d" . PHP_EOL, $c['src_variation_po']);
printf("  Variation cost_price fallback used (D4)     : %d" . PHP_EOL, $c['src_variation_cost_price']);
printf("  Parent product_cost fallback used           : %d" . PHP_EOL, $c['src_parent_cost']);
printf("  Inherited selling price                     : %d" . PHP_EOL, $c['price_inherited']);
printf("  Custom selling price (D5)                   : %d" . PHP_EOL, $c['price_custom']);
printf("  Foreign-currency PO as source               : %d" . PHP_EOL, $c['foreign_po']);
printf("  Historical (imported) PO as source          : %d" . PHP_EOL, $c['historical_po']);
printf("  Cancelled PO lines excluded                 : %d" . PHP_EOL, $cancelledLinesExcluded);
printf("  Variations without a usable PO line         : %d" . PHP_EOL, $c['no_usable_po']);
printf("  Units WITH shipping allocation              : %d" . PHP_EOL, $c['with_shipping']);
printf("  Units WITHOUT shipping allocation           : %d" . PHP_EOL, $c['without_shipping']);

if ($csvPath !== null) {
    $fh = fopen($csvPath, 'w');
    if ($fh === false) {
        echo PHP_EOL . 'WARNING: could not open CSV path for writing: ' . $csvPath . PHP_EOL;
    } else {
        fputcsv($fh, ['product_id', 'variation_id', 'sku', 'label', 'current_landed_cost',
            'proposed_landed_cost', 'diff_rm', 'diff_pct', 'cost_source', 'reason',
            'on_hand', 'po_lines_for_unit', 'unit_selling_price']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['product_id'], $r['variation_id'], $r['sku'], $r['label'], $r['current'],
                $r['proposed'], $r['diff'], $r['diff_pct'], $r['source'], $r['reason'],
                $r['on_hand'], $r['po_lines'], $r['selling_price']]);
        }
        fclose($fh);
        echo PHP_EOL . 'Full per-unit CSV written to: ' . $csvPath . PHP_EOL;
        echo '(This is the only file this script writes. The database was never modified.)' . PHP_EOL;
    }
}

echo PHP_EOL . 'Done. No database writes, no schema change, no notification, no history capture.' . PHP_EOL;
