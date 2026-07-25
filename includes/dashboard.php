<?php

require_once __DIR__ . '/orders.php';

/**
 * Sprint 10 (Operator Experience): extends the dashboard's Recent Activity list from
 * order-events-only to a real cross-entity feed, without introducing any new tracking table.
 * Every branch reads a column that already exists (mewmii_order_events, or a plain
 * created_at on an already-existing table) - this is a UNION ALL over already-existing data,
 * not a new activity log. Each branch is only included in the SQL when its own $flags entry
 * is true, so a restricted-permission user never even runs (let alone sees) a query for a
 * section they can't view.
 *
 * $flags keys: 'orders', 'supplier_orders', 'shipments', 'customers', 'product_sync'.
 */
function dashboard_recent_activity(PDO $pdo, array $flags, int $limit = 8): array
{
    $branches = [];

    if (!empty($flags['orders'])) {
        $branches[] = "
            SELECT 'order' AS activity_type, o.id AS ref_id, o.order_number AS text1, e.description AS text2, e.created_at AS occurred_at
            FROM mewmii_order_events e
            INNER JOIN mewmii_orders o ON o.id = e.order_id
        ";
    }
    if (!empty($flags['supplier_orders'])) {
        $branches[] = "
            SELECT 'supplier_order' AS activity_type, so.id AS ref_id, so.purchase_number AS text1, CONCAT('Status: ', so.status) AS text2, so.created_at AS occurred_at
            FROM supplier_orders so
        ";
    }
    if (!empty($flags['shipments'])) {
        $branches[] = "
            SELECT 'shipment' AS activity_type, sh.id AS ref_id, sh.shipment_number AS text1, 'Shipment created' AS text2, sh.created_at AS occurred_at
            FROM shipments sh
        ";
    }
    if (!empty($flags['customers'])) {
        $branches[] = "
            SELECT 'customer' AS activity_type, c.id AS ref_id, c.name AS text1, 'New customer' AS text2, c.created_at AS occurred_at
            FROM customers c
        ";
    }
    if (!empty($flags['product_sync'])) {
        $branches[] = "
            SELECT 'product_sync' AS activity_type, sl.reference_id AS ref_id, COALESCE(p.name, CONCAT('Product #', sl.reference_id)) AS text1, CONCAT('WooCommerce sync: ', sl.status) AS text2, sl.created_at AS occurred_at
            FROM sync_logs sl
            LEFT JOIN products p ON p.id = sl.reference_id
            WHERE sl.sync_type = 'woocommerce_product_sync' AND sl.reference_id IS NOT NULL
        ";
    }

    if ($branches === []) {
        return [];
    }

    $sql = 'SELECT * FROM ((' . implode(') UNION ALL (', $branches) . ")) combined ORDER BY occurred_at DESC LIMIT {$limit}";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $activity = [];
    foreach ($rows as $row) {
        switch ($row['activity_type']) {
            case 'order':
                $activity[] = [
                    'description' => order_display_number_compact((string) $row['text1']) . ' — ' . $row['text2'],
                    'url' => '/modules/orders/view.php?id=' . (int) $row['ref_id'],
                    'occurred_at' => $row['occurred_at'],
                ];
                break;
            case 'supplier_order':
                $activity[] = [
                    'description' => 'Supplier order ' . $row['text1'] . ' — ' . $row['text2'],
                    'url' => '/modules/supplier-orders/view.php?id=' . (int) $row['ref_id'],
                    'occurred_at' => $row['occurred_at'],
                ];
                break;
            case 'shipment':
                $activity[] = [
                    'description' => 'Shipment ' . $row['text1'] . ' created',
                    'url' => '/modules/shipments/view.php?id=' . (int) $row['ref_id'],
                    'occurred_at' => $row['occurred_at'],
                ];
                break;
            case 'customer':
                $activity[] = [
                    'description' => 'New customer: ' . $row['text1'],
                    'url' => '/modules/customers/view.php?id=' . (int) $row['ref_id'],
                    'occurred_at' => $row['occurred_at'],
                ];
                break;
            case 'product_sync':
                $activity[] = [
                    'description' => $row['text1'] . ' — ' . $row['text2'],
                    'url' => '/modules/products/view.php?id=' . (int) $row['ref_id'],
                    'occurred_at' => $row['occurred_at'],
                ];
                break;
        }
    }

    return $activity;
}
