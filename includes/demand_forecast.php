<?php

require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/supplier_orders.php';

/**
 * Phase 8D/8E - Demand Forecasting formula, factored out of modules/reports/forecast.php so
 * modules/purchasing/control-center.php (Phase 8E) can reuse the exact same calculation for
 * its "Urgent Reorder Products" section instead of a second definition. Both callers get
 * identical numbers for identical inputs - this is the ONLY place the formula is implemented.
 *
 * Simple, explainable arithmetic only - no machine learning, no trend/seasonality modelling.
 * Every input is an existing, already-trusted figure read straight from its source table:
 *   - Available/Incoming Stock: the same batched mewmii_inventory rollup used throughout
 *     modules/products/index.php, modules/purchasing/index.php, etc.
 *   - Sales Velocity: SUM(quantity) over the same "valid order" condition
 *     (payment_status='paid' AND order_status<>'cancelled') modules/reports/sales.php and
 *     modules/reports/inventory.php already use, via includes/reports.php's period helper.
 *   - Supplier Lead Time: supplier_lead_time_stats_batch() (includes/supplier_orders.php) -
 *     the exact "Average Delivery Time" formula modules/reports/suppliers.php already shows.
 *   - Safety Stock: products.min_stock_threshold, used as-is.
 *
 * A SEPARATE, complementary signal from includes/purchase_planning.php's own "needs ordering"
 * logic (target-stock-level based) - the two are never merged; purchase_planning_needs()/
 * purchase_planning_generate() are not touched by this file at all.
 *
 * Formula:
 *   Average Daily Sales = quantity sold in the selected period / number of days in that period
 *   Days of Stock Remaining = Available Stock / Average Daily Sales
 *   Demand During Lead Time = Average Daily Sales x Supplier Lead Time (days)
 *   Recommended Order Quantity = MAX(0, Demand During Lead Time + Safety Stock
 *                                       - Available Stock - Incoming Stock)
 *   Suggested Reorder Date = Today + MAX(0, Days of Stock Remaining - Supplier Lead Time)
 *
 * A product missing EITHER Sales Velocity OR Supplier Lead Time gets null for every figure
 * that depends on it, rather than a guessed default - callers render that as "Not enough data".
 */

const DEMAND_FORECAST_PERIOD_DAYS = ['7days' => 7, '30days' => 30, '90days' => 90];

/**
 * $filters: 'supplier_id' (?int), 'search' (string) - both optional, same whitelist-before-
 * query values the caller is expected to have already validated. Returns an unsorted list of
 * rows (one per ready_stock, non-archived product matching the filters) - sorting/pagination/
 * "reorder only" trimming are display concerns left to the caller (modules/reports/forecast.php
 * sorts by days remaining and paginates; modules/purchasing/control-center.php filters down to
 * urgent-only and caps the count).
 */
function demand_forecast_calculate(PDO $pdo, string $period, array $filters = []): array
{
    $periodDays = DEMAND_FORECAST_PERIOD_DAYS[$period] ?? DEMAND_FORECAST_PERIOD_DAYS['30days'];
    $dateCondition = report_period_date_condition($period, 'o.order_date');

    $filterSupplierId = $filters['supplier_id'] ?? null;
    $searchTerm = trim((string) ($filters['search'] ?? ''));

    // Batched stock rollup - identical to modules/products/index.php's / modules/purchasing/
    // index.php's own $stockJoinSql, reused verbatim.
    $stockJoinSql = "
        LEFT JOIN (
            SELECT inv.product_id,
                   SUM(inv.available_quantity) AS available_quantity,
                   SUM(inv.incoming_quantity) AS incoming_quantity
            FROM mewmii_inventory inv
            LEFT JOIN product_variations pv ON pv.id = inv.variation_id
            WHERE inv.variation_id IS NULL OR pv.status <> 'archived'
            GROUP BY inv.product_id
        ) stock ON stock.product_id = p.id
    ";

    // Scope deliberately ready_stock only - same precedent as modules/reports/inventory.php:
    // preorder/early_bird are never gated on physical stock, so "days of stock remaining"
    // doesn't map onto them the same way.
    $whereSql = " AND p.product_type = 'ready_stock' AND p.status <> 'archived'";
    $params = [];
    if ($filterSupplierId !== null) {
        $whereSql .= ' AND p.supplier_id = ?';
        $params[] = $filterSupplierId;
    }
    if ($searchTerm !== '') {
        $whereSql .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
        $likeTerm = '%' . $searchTerm . '%';
        $params[] = $likeTerm;
        $params[] = $likeTerm;
    }

    $productsStmt = $pdo->prepare("
        SELECT p.id, p.sku, p.name, p.supplier_id, p.min_stock_threshold, s.name AS supplier_name,
               COALESCE(stock.available_quantity, 0) AS available_stock,
               COALESCE(stock.incoming_quantity, 0) AS incoming_stock
        FROM products p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        {$stockJoinSql}
        WHERE 1 = 1 {$whereSql}
        ORDER BY p.name ASC
    ");
    $productsStmt->execute($params);
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

    // One batched query for every candidate product's sales in the selected period - not one
    // per product. Same valid-order condition modules/reports/sales.php/inventory.php use.
    $salesByProduct = [];
    if ($products !== []) {
        $productIds = array_map(static fn (array $p): int => (int) $p['id'], $products);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $salesStmt = $pdo->prepare("
            SELECT oi.product_id, SUM(oi.quantity) AS quantity_sold
            FROM mewmii_order_items oi
            INNER JOIN mewmii_orders o ON o.id = oi.order_id
            WHERE oi.product_id IN ({$placeholders})
              AND o.payment_status = 'paid' AND o.order_status <> 'cancelled'{$dateCondition}
            GROUP BY oi.product_id
        ");
        $salesStmt->execute($productIds);
        foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $salesByProduct[(int) $row['product_id']] = (int) $row['quantity_sold'];
        }
    }

    // One batched query for every distinct supplier behind these products - not one per product.
    $supplierIds = array_values(array_unique(array_filter(array_column($products, 'supplier_id'))));
    $leadTimeBySupplier = supplier_lead_time_stats_batch($pdo, $supplierIds);

    $rows = [];
    foreach ($products as $product) {
        $productId = (int) $product['id'];
        $availableStock = (int) $product['available_stock'];
        $incomingStock = (int) $product['incoming_stock'];
        $safetyStock = $product['min_stock_threshold'] !== null ? (int) $product['min_stock_threshold'] : 0;

        $quantitySold = $salesByProduct[$productId] ?? 0;
        // "Not enough data" is a real state, never treated as a velocity of 0.
        $hasSalesData = $quantitySold > 0;
        $avgDailySales = $hasSalesData ? ($quantitySold / $periodDays) : null;
        $daysRemaining = ($avgDailySales !== null && $avgDailySales > 0) ? ($availableStock / $avgDailySales) : null;

        $leadTimeStats = $product['supplier_id'] !== null ? ($leadTimeBySupplier[(int) $product['supplier_id']] ?? null) : null;
        $leadTimeDays = $leadTimeStats['avg_lead_time_days'] ?? null;

        $recommendedQty = null;
        $reorderDate = null;
        if ($avgDailySales !== null && $leadTimeDays !== null) {
            $demandDuringLeadTime = $avgDailySales * $leadTimeDays;
            $recommendedQty = (int) ceil(max(0, $demandDuringLeadTime + $safetyStock - $availableStock - $incomingStock));
            $reorderInDays = max(0, ($daysRemaining ?? 0) - $leadTimeDays);
            $reorderDate = date('Y-m-d', strtotime('+' . (int) floor($reorderInDays) . ' days'));
        }

        $rows[] = [
            'id' => $productId,
            'sku' => $product['sku'],
            'name' => $product['name'],
            'supplier_id' => $product['supplier_id'] !== null ? (int) $product['supplier_id'] : null,
            'supplier_name' => $product['supplier_name'],
            'available_stock' => $availableStock,
            'incoming_stock' => $incomingStock,
            'avg_daily_sales' => $avgDailySales,
            'days_remaining' => $daysRemaining,
            'lead_time_days' => $leadTimeDays,
            'reorder_date' => $reorderDate,
            'recommended_qty' => $recommendedQty,
        ];
    }

    return $rows;
}
