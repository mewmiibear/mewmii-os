<?php

require_once __DIR__ . '/demand_forecast.php';
require_once __DIR__ . '/product_cost.php';
require_once __DIR__ . '/supplier_orders.php';
require_once __DIR__ . '/purchase_planning.php';

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
 * De-duplication: a candidate is only inserted if no un-RESOLVED notification of the same type
 * + reference_id already exists - checked in ONE batched query per type (not one per
 * candidate), so re-running generation while an alert is still open (Active or Acknowledged)
 * never creates a second row for the same thing. Only once it's marked Resolved is that
 * reference free to raise a fresh notification if the condition recurs later.
 *
 * Phase 9C added a three-state LIFECYCLE on top of Phase 9B's plain read_status, without
 * changing read_status's own meaning or deleting anything:
 *   - Active:       read_status = 0 AND resolved_status = 0 (nobody has looked at it yet)
 *   - Acknowledged: read_status = 1 AND resolved_status = 0 (seen, but still an open issue)
 *   - Resolved:     resolved_status = 1 (the underlying condition no longer holds), regardless
 *                   of read_status - see notification_lifecycle_status().
 * Resolving never deletes a row - it only sets resolved_status/resolved_at, so the full history
 * stays on file. notification_auto_resolve() re-checks each type's ORIGINAL generation
 * condition (via the exact same reused functions above) for every currently-open notification
 * and resolves the ones that no longer match - never a second/different rule from generation.
 */

const NOTIFICATION_TYPES = ['inventory_risk', 'cost_increase', 'supplier_delay', 'supplier_order_overdue'];

const NOTIFICATION_TYPE_LABELS = [
    'inventory_risk' => 'Inventory Risk',
    'cost_increase' => 'Cost Increase',
    'supplier_delay' => 'Supplier Delay',
    'supplier_order_overdue' => 'Supplier Order Overdue',
];

/**
 * Batched insert-if-not-already-open for one notification type. $candidates: list of
 * ['reference_id' => int, 'title' => string, 'message' => string]. Returns how many NEW rows
 * were actually inserted (candidates matching an already-open, i.e. un-resolved, row are
 * silently skipped, not counted as failures).
 */
function notification_bulk_create_if_not_exists(PDO $pdo, string $type, array $candidates): int
{
    if ($candidates === []) {
        return 0;
    }

    $referenceIds = array_map(static fn (array $c): int => (int) $c['reference_id'], $candidates);
    $placeholders = implode(',', array_fill(0, count($referenceIds), '?'));

    // One batched dedup check for the whole candidate list, not one query per candidate.
    // resolved_status (not read_status) is the gate - an Acknowledged (read but still open)
    // alert must still block a duplicate; only a Resolved one frees the reference to fire again.
    $existingStmt = $pdo->prepare("
        SELECT DISTINCT reference_id
        FROM mewmii_notifications
        WHERE type = ? AND resolved_status = 0 AND reference_id IN ({$placeholders})
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
    // Mewmii OS v2 Phase 1: now calls supplier_orders_overdue() (includes/purchase_planning.php)
    // instead of maintaining its own copy of this predicate - see that function's docblock.
    $overdueCandidates = [];
    foreach (supplier_orders_overdue($pdo) as $row) {
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
 * Count of unread, un-resolved (i.e. Active) notifications - for the dashboard summary and any
 * nav badge. One plain COUNT, no per-row work. Phase 9C excludes resolved_status = 1 rows - a
 * resolved alert no longer needs attention even if nobody explicitly clicked "Mark Read".
 */
function notification_unread_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM mewmii_notifications WHERE read_status = 0 AND resolved_status = 0')->fetchColumn();
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
        SELECT id, title, message, type, reference_id, read_status, resolved_status, resolved_at, created_at
        FROM mewmii_notifications
        ORDER BY created_at DESC, id DESC
        LIMIT ' . max(1, $limit)
    );
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 'active' | 'acknowledged' | 'resolved' for one notification row - the single place this
 * three-way mapping is decided, so the dashboard summary and the full list page can never
 * disagree about which state a given row is in.
 */
function notification_lifecycle_status(array $notification): string
{
    if (!empty($notification['resolved_status'])) {
        return 'resolved';
    }

    return !empty($notification['read_status']) ? 'acknowledged' : 'active';
}

const NOTIFICATION_LIFECYCLE_LABELS = [
    'active' => 'Active',
    'acknowledged' => 'Acknowledged',
    'resolved' => 'Resolved',
];

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

/**
 * Phase 9C - the specific action links for one notification's type, all reusing an EXISTING
 * route (no new page/filter was created for this) - "Reuse existing routes" per the phase's
 * own rule:
 *   - inventory_risk: View Forecast (modules/reports/forecast.php's own ?reorder_only=1
 *     filter, Phase 8D/8E) + Create Supplier Order (modules/purchasing/control-center.php,
 *     where the real per-supplier "Create Supplier Order" action already lives - Phase 8A/8E;
 *     linking there rather than trying to infer a supplier_id inline avoids an extra lookup
 *     per notification).
 *   - cost_increase: View Cost History (modules/reports/cost_history.php, Phase 8C) + View
 *     Product (modules/products/view.php, whose own Cost Breakdown/Cost History sections
 *     already cover this exact product - Phase 7C/8C).
 *   - supplier_delay: View Supplier (modules/reports/supplier_detail.php, Phase 8B) + View
 *     Orders (the same page's own Purchase History section - a #purchase-history anchor was
 *     added to that existing card so this is still one real page, not a new route).
 *   - supplier_order_overdue: View Supplier Order (modules/supplier-orders/view.php, unchanged).
 */
function notification_action_links(array $notification): array
{
    $referenceId = $notification['reference_id'] !== null ? (int) $notification['reference_id'] : null;
    if ($referenceId === null) {
        return [];
    }

    switch ($notification['type']) {
        case 'inventory_risk':
            return [
                ['label' => 'View Forecast', 'url' => '/modules/reports/forecast.php?reorder_only=1'],
                ['label' => 'Create Supplier Order', 'url' => '/modules/purchasing/control-center.php'],
            ];
        case 'cost_increase':
            return [
                ['label' => 'View Cost History', 'url' => '/modules/reports/cost_history.php'],
                ['label' => 'View Product', 'url' => '/modules/products/view.php?id=' . $referenceId],
            ];
        case 'supplier_delay':
            return [
                ['label' => 'View Supplier', 'url' => '/modules/reports/supplier_detail.php?id=' . $referenceId],
                ['label' => 'View Orders', 'url' => '/modules/reports/supplier_detail.php?id=' . $referenceId . '#purchase-history'],
            ];
        case 'supplier_order_overdue':
            return [
                ['label' => 'View Supplier Order', 'url' => '/modules/supplier-orders/view.php?id=' . $referenceId],
            ];
        default:
            return [];
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

/**
 * Manually resolve one notification (e.g. an admin who fixed the underlying issue faster than
 * the next auto-resolve pass) - sets resolved_status/resolved_at, never deletes the row.
 */
function notification_mark_resolved(PDO $pdo, int $notificationId): void
{
    $pdo->prepare('UPDATE mewmii_notifications SET resolved_status = 1, resolved_at = NOW() WHERE id = ?')->execute([$notificationId]);
}

/**
 * Every distinct reference_id with at least one currently-open (Active or Acknowledged, i.e.
 * un-resolved) notification of the given type - the candidate set notification_auto_resolve()
 * re-checks. One query per type, not one per notification row.
 */
function notification_open_reference_ids(PDO $pdo, string $type): array
{
    $stmt = $pdo->prepare('SELECT DISTINCT reference_id FROM mewmii_notifications WHERE type = ? AND resolved_status = 0 AND reference_id IS NOT NULL');
    $stmt->execute([$type]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Resolves every currently-open notification of $type whose reference_id is in $referenceIds -
 * one batched UPDATE, not one per row. Returns the number of rows actually changed.
 */
function notification_resolve_by_type_and_reference(PDO $pdo, string $type, array $referenceIds): int
{
    $referenceIds = array_values(array_unique(array_map('intval', $referenceIds)));
    if ($referenceIds === []) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($referenceIds), '?'));
    $stmt = $pdo->prepare("
        UPDATE mewmii_notifications
        SET resolved_status = 1, resolved_at = NOW()
        WHERE type = ? AND resolved_status = 0 AND reference_id IN ({$placeholders})
    ");
    $stmt->execute(array_merge([$type], $referenceIds));

    return $stmt->rowCount();
}

/**
 * Phase 9C - auto-resolution. For every currently-open notification, re-checks its type's
 * ORIGINAL generation condition (via the exact same reused functions notification_generate_
 * alerts() itself calls) and resolves the ones that no longer match. Never a second/looser
 * rule than generation - resolution is simply "the generation condition is no longer true".
 * Like generation, this only ever runs from cli/generate_alerts.php or the manual button on
 * modules/notifications/index.php, never on a normal page load.
 *
 * Supplier Delay caveat (documented, not silently glossed over): supplier_lead_time_stats_
 * batch()'s late_orders_count is a lifetime/cumulative count of every order that supplier has
 * ever delivered late - it can only stay the same or grow, never shrink on its own. Reusing
 * that exact metric for resolution (as required - "reuse existing notification generators") means
 * a Supplier Delay alert will only auto-resolve if the underlying supplier_orders data itself
 * changes (a late order corrected/deleted), not simply because time passes without a new late
 * delivery. This is an honest consequence of reusing the existing all-time metric rather than
 * inventing a new "recent lateness" formula, which "do not duplicate business rules" forbids.
 */
function notification_auto_resolve(PDO $pdo): array
{
    $resolved = ['inventory_risk' => 0, 'cost_increase' => 0, 'supplier_delay' => 0, 'supplier_order_overdue' => 0];

    // --- Inventory Risk: resolve when recommended_qty <= 0 (or the product no longer appears
    // in the forecast at all) -------------------------------------------------------------------
    $openInventoryRefs = notification_open_reference_ids($pdo, 'inventory_risk');
    if ($openInventoryRefs !== []) {
        $stillNeedsReorder = [];
        foreach (demand_forecast_calculate($pdo, '30days', []) as $forecastRow) {
            if ($forecastRow['recommended_qty'] !== null && $forecastRow['recommended_qty'] > 0) {
                $stillNeedsReorder[$forecastRow['id']] = true;
            }
        }
        $toResolve = array_filter($openInventoryRefs, static fn (int $id): bool => !isset($stillNeedsReorder[$id]));
        $resolved['inventory_risk'] = notification_resolve_by_type_and_reference($pdo, 'inventory_risk', $toResolve);
    }

    // --- Cost Increase: resolve when the current landed cost is no longer higher than the most
    // recent snapshot on file ------------------------------------------------------------------
    $openCostRefs = notification_open_reference_ids($pdo, 'cost_increase');
    if ($openCostRefs !== []) {
        $historyByProduct = product_cost_history_list_batch($pdo, $openCostRefs);
        $currentCostByProduct = product_cost_calculate_batch($pdo, $openCostRefs);

        $toResolve = [];
        foreach ($openCostRefs as $productId) {
            $history = $historyByProduct[$productId] ?? [];
            if ($history === []) {
                // No snapshot at all to compare against - the "increase" condition can't hold.
                $toResolve[] = $productId;
                continue;
            }
            $previousLandedCost = (float) $history[count($history) - 1]['landed_cost'];
            $currentLandedCost = $currentCostByProduct[$productId]['landed_cost'] ?? null;
            $stillIncreased = $currentLandedCost !== null && $previousLandedCost > 0 && $currentLandedCost > $previousLandedCost;
            if (!$stillIncreased) {
                $toResolve[] = $productId;
            }
        }
        $resolved['cost_increase'] = notification_resolve_by_type_and_reference($pdo, 'cost_increase', $toResolve);
    }

    // --- Supplier Delay: resolve when late_orders_count is no longer > 0 (see this function's
    // own docblock for why that's a cumulative, rarely-decreasing metric) ----------------------
    $openSupplierRefs = notification_open_reference_ids($pdo, 'supplier_delay');
    if ($openSupplierRefs !== []) {
        $leadTimeStatsBySupplier = supplier_lead_time_stats_batch($pdo, $openSupplierRefs);
        $toResolve = [];
        foreach ($openSupplierRefs as $supplierId) {
            $stillLate = (($leadTimeStatsBySupplier[$supplierId]['late_orders_count'] ?? 0) > 0);
            if (!$stillLate) {
                $toResolve[] = $supplierId;
            }
        }
        $resolved['supplier_delay'] = notification_resolve_by_type_and_reference($pdo, 'supplier_delay', $toResolve);
    }

    // --- Supplier Order Overdue: resolve when the order no longer matches the exact overdue
    // predicate generation used (status changed to received/completed/cancelled, or the
    // expected date/order itself no longer applies) --------------------------------------------
    $openOrderRefs = notification_open_reference_ids($pdo, 'supplier_order_overdue');
    if ($openOrderRefs !== []) {
        $placeholders = implode(',', array_fill(0, count($openOrderRefs), '?'));
        $stillOverdueStmt = $pdo->prepare("
            SELECT id FROM supplier_orders
            WHERE id IN ({$placeholders})
              AND expected_delivery_date IS NOT NULL AND expected_delivery_date < CURDATE()
              AND status NOT IN ('received', 'completed', 'cancelled')
        ");
        $stillOverdueStmt->execute($openOrderRefs);
        $stillOverdueIds = array_flip(array_map('intval', $stillOverdueStmt->fetchAll(PDO::FETCH_COLUMN)));

        $toResolve = array_filter($openOrderRefs, static fn (int $id): bool => !isset($stillOverdueIds[$id]));
        $resolved['supplier_order_overdue'] = notification_resolve_by_type_and_reference($pdo, 'supplier_order_overdue', $toResolve);
    }

    return $resolved;
}
