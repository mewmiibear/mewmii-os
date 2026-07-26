<?php

require_once __DIR__ . '/demand_forecast.php';
require_once __DIR__ . '/product_cost.php';
require_once __DIR__ . '/supplier_orders.php';

/**
 * Phase 9B - Notification & Alert Center. Generates and reads rows in mewmii_notifications
 * (a table that already existed, scaffolded, never wired up anywhere until now - see
 * database/schema.sql for what Phase 9B added to it).
 *
 * Generation is INTENTIONALLY never triggered by a page load - only cli/generate_alerts.php
 * (for a cron entry) and modules/notifications/index.php's own manual "Generate Alerts Now"
 * button call notification_generate_alerts(). Every other page (including index.php's
 * dashboard summary) only ever READS already-generated rows - this is what "avoid duplicate
 * notifications on every page load" means in practice: page load never writes here at all.
 *
 * Every alert type reuses an existing Phase 7/8/9 calculation, never a new rule:
 *   - inventory_risk: demand_forecast_calculate() (includes/demand_forecast.php) - the exact
 *     formula modules/reports/forecast.php/modules/purchasing/control-center.php already use.
 *   - cost_increase: product_cost_history_product_ids()/product_cost_history_list_batch()/
 *     product_cost_calculate_batch() (includes/product_cost.php) - the exact "current landed
 *     cost higher than the last snapshot" rule control-center.php's Cost Change Alerts and
 *     index.php's Phase 9A Cost Alerts card already apply.
 *   - supplier_delay: supplier_lead_time_stats_batch() (includes/supplier_orders.php) - the
 *     exact "late orders" formula modules/reports/suppliers.php already shows.
 *   - supplier_order_overdue: the exact overdue predicate (expected_delivery_date < CURDATE(),
 *     status not yet received/completed/cancelled) already used verbatim by index.php's own
 *     Overdue Supplier Orders card and modules/supplier-orders/index.php's ?filter=overdue.
 *
 * De-duplication: a candidate is only inserted if no UNREAD notification of the same type +
 * reference_id already exists - checked in ONE batched query per type (not one per candidate),
 * so re-running generation while an alert is still open and unread never creates a second row
 * for the same thing. Once an admin marks it read, the underlying condition (if it's still
 * true next run) is free to raise a fresh notification - it is not permanently silenced.
 */

const NOTIFICATION_TYPES = ['inventory_risk', 'cost_increase', 'supplier_delay', 'supplier_order_overdue'];

const NOTIFICATION_TYPE_LABELS = [
    'inventory_risk' => 'Inventory Risk',
    'cost_increase' => 'Cost Increase',
    'supplier_delay' => 'Supplier Delay',
    'supplier_order_overdue' => 'Supplier Order Overdue',
];

/**
 * Batched insert-if-not-already-unread for one notification type. $candidates: list of
 * ['reference_id' => int, 'title' => string, 'message' => string]. Returns how many NEW rows
 * were actually inserted (candidates matching an already-unread row are silently skipped, not
 * counted as failures).
 */
function notification_bulk_create_if_not_exists(PDO $pdo, string $type, array $candidates): int
{
    if ($candidates === []) {
        return 0;
    }

    $referenceIds = array_map(static fn (array $c): int => (int) $c['reference_id'], $candidates);
    $placeholders = implode(',', array_fill(0, count($referenceIds), '?'));

    // One batched dedup check for the whole candidate list, not one query per candidate.
    $existingStmt = $pdo->prepare("
        SELECT DISTINCT reference_id
        FROM mewmii_notifications
        WHERE type = ? AND read_status = 0 AND reference_id IN ({$placeholders})
    ");
    $existingStmt->execute(array_merge([$type], $referenceIds));
    $existingRefIds = array_flip(array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN)));

    $insertStmt = $pdo->prepare('
        INSERT INTO mewmii_notifications (title, message, type, reference_id)
        VALUES (?, ?, ?, ?)
    ');

    $createdCount = 0;
    foreach ($candidates as $candidate) {
        if (isset($existingRefIds[(int) $candidate['reference_id']])) {
            continue;
        }
        $insertStmt->execute([$candidate['title'], $candidate['message'], $type, (int) $candidate['reference_id']]);
        $createdCount++;
    }

    return $createdCount;
}

/**
 * Runs all four alert generators and returns how many new notifications each one created
 * (candidates matching an already-unread row for the same reference are not re-created - see
 * this file's own docblock). Every underlying number comes from an existing, unmodified
 * Phase 7/8 function/query - nothing here recomputes a business rule.
 */
function notification_generate_alerts(PDO $pdo): array
{
    $created = ['inventory_risk' => 0, 'cost_increase' => 0, 'supplier_delay' => 0, 'supplier_order_overdue' => 0];

    // --- Inventory Risk ---------------------------------------------------------------------
    $inventoryCandidates = [];
    foreach (demand_forecast_calculate($pdo, '30days', []) as $forecastRow) {
        if ($forecastRow['recommended_qty'] === null || $forecastRow['recommended_qty'] <= 0) {
            continue;
        }
        $daysRemainingText = $forecastRow['days_remaining'] !== null ? number_format($forecastRow['days_remaining'], 1) . ' days' : 'an unknown number of days';
        $inventoryCandidates[] = [
            'reference_id' => $forecastRow['id'],
            'title' => 'Reorder needed: ' . $forecastRow['name'],
            'message' => $forecastRow['name'] . ' (' . $forecastRow['sku'] . ') has ' . $daysRemainingText
                . ' of stock remaining. Recommended order quantity: ' . (int) $forecastRow['recommended_qty'] . '.',
        ];
    }
    $created['inventory_risk'] = notification_bulk_create_if_not_exists($pdo, 'inventory_risk', $inventoryCandidates);

    // --- Cost Increase -----------------------------------------------------------------------
    $costCandidates = [];
    $historyProductIds = product_cost_history_product_ids($pdo);
    if ($historyProductIds !== []) {
        $placeholders = implode(',', array_fill(0, count($historyProductIds), '?'));
        $productStmt = $pdo->prepare("SELECT id, sku, name FROM products WHERE id IN ({$placeholders})");
        $productStmt->execute($historyProductIds);
        $productsById = [];
        foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productsById[(int) $row['id']] = $row;
        }

        $historyByProduct = product_cost_history_list_batch($pdo, $historyProductIds);
        $currentCostByProduct = product_cost_calculate_batch($pdo, $historyProductIds);

        foreach ($historyProductIds as $productId) {
            $history = $historyByProduct[$productId] ?? [];
            if ($history === [] || !isset($productsById[$productId])) {
                continue;
            }

            $previousLandedCost = (float) $history[count($history) - 1]['landed_cost'];
            $currentLandedCost = $currentCostByProduct[$productId]['landed_cost'] ?? null;
            if ($currentLandedCost === null || $previousLandedCost <= 0 || $currentLandedCost <= $previousLandedCost) {
                continue;
            }

            $changePercent = ($currentLandedCost - $previousLandedCost) / $previousLandedCost * 100;
            $product = $productsById[$productId];
            $costCandidates[] = [
                'reference_id' => $productId,
                'title' => 'Landed cost increased: ' . $product['name'],
                'message' => $product['name'] . ' (' . $product['sku'] . ') landed cost rose from RM'
                    . number_format($previousLandedCost, 2) . ' to RM' . number_format($currentLandedCost, 2)
                    . ' (+' . number_format($changePercent, 1) . '%).',
            ];
        }
    }
    $created['cost_increase'] = notification_bulk_create_if_not_exists($pdo, 'cost_increase', $costCandidates);

    // --- Supplier Delay ------------------------------------------------------------------------
    $supplierCandidates = [];
    $supplierIdsWithOrders = array_map('intval', $pdo->query('SELECT DISTINCT supplier_id FROM supplier_orders')->fetchAll(PDO::FETCH_COLUMN));
    if ($supplierIdsWithOrders !== []) {
        $supplierPlaceholders = implode(',', array_fill(0, count($supplierIdsWithOrders), '?'));
        $supplierNameStmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id IN ({$supplierPlaceholders})");
        $supplierNameStmt->execute($supplierIdsWithOrders);
        $supplierNamesById = [];
        foreach ($supplierNameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $supplierNamesById[(int) $row['id']] = $row['name'];
        }

        foreach (supplier_lead_time_stats_batch($pdo, $supplierIdsWithOrders) as $supplierId => $stats) {
            if (($stats['late_orders_count'] ?? 0) <= 0 || !isset($supplierNamesById[$supplierId])) {
                continue;
            }

            $avgLeadTimeText = $stats['avg_lead_time_days'] !== null ? number_format($stats['avg_lead_time_days'], 1) . ' days' : 'not available';
            $supplierCandidates[] = [
                'reference_id' => $supplierId,
                'title' => 'Late deliveries: ' . $supplierNamesById[$supplierId],
                'message' => $supplierNamesById[$supplierId] . ' has ' . (int) $stats['late_orders_count'] . ' of '
                    . (int) $stats['late_eligible_count'] . ' orders delivered late (average lead time: ' . $avgLeadTimeText . ').',
            ];
        }
    }
    $created['supplier_delay'] = notification_bulk_create_if_not_exists($pdo, 'supplier_delay', $supplierCandidates);

    // --- Supplier Order Overdue ----------------------------------------------------------------
    // Exact same predicate already used verbatim by index.php's Overdue Supplier Orders card
    // and modules/supplier-orders/index.php's ?filter=overdue - never re-derived.
    $overdueStmt = $pdo->query("
        SELECT so.id, so.purchase_number, so.expected_delivery_date, s.name AS supplier_name
        FROM supplier_orders so
        INNER JOIN suppliers s ON s.id = so.supplier_id
        WHERE so.expected_delivery_date IS NOT NULL AND so.expected_delivery_date < CURDATE()
          AND so.status NOT IN ('received', 'completed', 'cancelled')
    ");
    $overdueCandidates = [];
    foreach ($overdueStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $overdueCandidates[] = [
            'reference_id' => (int) $row['id'],
            'title' => 'Supplier order overdue: ' . $row['purchase_number'],
            'message' => $row['purchase_number'] . ' (' . $row['supplier_name'] . ') was expected by '
                . $row['expected_delivery_date'] . ' and has not been received.',
        ];
    }
    $created['supplier_order_overdue'] = notification_bulk_create_if_not_exists($pdo, 'supplier_order_overdue', $overdueCandidates);

    return $created;
}

/**
 * Count of unread notifications - for the dashboard summary and any nav badge. One plain
 * COUNT, no per-row work.
 */
function notification_unread_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM mewmii_notifications WHERE read_status = 0')->fetchColumn();
}

/**
 * Most recent notifications, newest first - for the dashboard summary
 * (modules/notifications/index.php uses its own paginated query for the full list). One query,
 * no N+1 - the destination URL for each row is resolved in PHP from its type/reference_id via
 * notification_url_for(), not a second lookup per row.
 */
function notification_recent(PDO $pdo, int $limit): array
{
    $stmt = $pdo->prepare('
        SELECT id, title, message, type, reference_id, read_status, created_at
        FROM mewmii_notifications
        ORDER BY created_at DESC, id DESC
        LIMIT ' . max(1, $limit)
    );
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Where a notification's "view" link should go, purely from its already-known type/
 * reference_id - no extra query. Falls back to the notifications list itself for an unknown
 * type (forward-compatible if a type is ever added without updating this map).
 */
function notification_url_for(array $notification): string
{
    $referenceId = $notification['reference_id'] !== null ? (int) $notification['reference_id'] : null;
    if ($referenceId === null) {
        return '/modules/notifications/index.php';
    }

    switch ($notification['type']) {
        case 'inventory_risk':
        case 'cost_increase':
            return '/modules/products/view.php?id=' . $referenceId;
        case 'supplier_delay':
            return '/modules/reports/supplier_detail.php?id=' . $referenceId;
        case 'supplier_order_overdue':
            return '/modules/supplier-orders/view.php?id=' . $referenceId;
        default:
            return '/modules/notifications/index.php';
    }
}

function notification_mark_read(PDO $pdo, int $notificationId): void
{
    $pdo->prepare('UPDATE mewmii_notifications SET read_status = 1 WHERE id = ?')->execute([$notificationId]);
}

function notification_mark_all_read(PDO $pdo): void
{
    $pdo->exec('UPDATE mewmii_notifications SET read_status = 1 WHERE read_status = 0');
}
