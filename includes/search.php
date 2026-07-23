<?php

/**
 * Global search backend (Phase 1 - no UI yet). One UNION ALL query across every supported
 * entity type, permission-agnostic by design: the caller (a module file, same convention as
 * everywhere else in this app) decides which entity types the current user may search via
 * $allowedTypes, computed from app_has_permission(). This file never calls app_has_permission()
 * itself and never gates anything - an empty/missing type in $allowedTypes simply omits that
 * branch from the query entirely, so a user without e.g. 'orders.view' can never see an order
 * in results, not even indirectly through another branch.
 */

/**
 * Escapes LIKE metacharacters (% _ \) in a raw search term before it's wrapped in %...% and
 * bound as a parameter - otherwise a literal "%" or "_" typed by the user would act as a
 * wildcard instead of a literal character. The bound parameter itself is already safe from SQL
 * injection via the prepared statement; this only controls LIKE's own pattern-matching syntax.
 */
function search_escape_like_term(string $term): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
}

/**
 * Runs the global search. $allowedTypes is a subset of:
 * ['products', 'variations', 'orders', 'supplier_orders', 'suppliers', 'shipments'] - any
 * type not present is simply not queried. $limitPerType caps how many rows each entity type
 * contributes (each UNION branch gets its own LIMIT via a parenthesised subquery, since a
 * single LIMIT on the outer UNION would let one entity type crowd out the rest). Minimum term
 * length is the caller's responsibility, not this function's.
 *
 * Returns a flat list of ['entity_type' => string, 'entity_id' => int, 'label' => string,
 * 'subtitle' => string, 'url' => string], one single SQL round trip regardless of how many
 * entity types are included.
 */
function global_search(PDO $pdo, string $term, array $allowedTypes, int $limitPerType = 10): array
{
    $term = trim($term);
    if ($term === '' || $allowedTypes === []) {
        return [];
    }

    $likeTerm = '%' . search_escape_like_term($term) . '%';
    $limitPerType = max(1, $limitPerType);

    $branches = [];
    $params = [];

    if (in_array('products', $allowedTypes, true)) {
        $branches[] = "
            (SELECT
                'products' AS entity_type,
                p.id AS entity_id,
                p.name AS label,
                CONCAT('SKU: ', p.sku) AS subtitle,
                CONCAT('/modules/products/view.php?id=', p.id) AS url
             FROM products p
             WHERE p.name LIKE ? ESCAPE '\\\\'
                OR p.sku LIKE ? ESCAPE '\\\\'
                OR p.barcode LIKE ? ESCAPE '\\\\'
                OR p.supplier_sku LIKE ? ESCAPE '\\\\'
                OR p.internal_code LIKE ? ESCAPE '\\\\'
             LIMIT {$limitPerType})
        ";
        array_push($params, $likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm);
    }

    if (in_array('variations', $allowedTypes, true)) {
        // Variations have no page of their own - results point at the parent product's
        // read-only view (modules/products/view.php), same as the rest of the Products branch.
        $branches[] = "
            (SELECT
                'variations' AS entity_type,
                pv.product_id AS entity_id,
                CONCAT(p.name, ' - ', pv.sku) AS label,
                'Variation' AS subtitle,
                CONCAT('/modules/products/view.php?id=', pv.product_id) AS url
             FROM product_variations pv
             INNER JOIN products p ON p.id = pv.product_id
             WHERE pv.sku LIKE ? ESCAPE '\\\\'
                OR pv.barcode LIKE ? ESCAPE '\\\\'
                OR pv.supplier_sku LIKE ? ESCAPE '\\\\'
             LIMIT {$limitPerType})
        ";
        array_push($params, $likeTerm, $likeTerm, $likeTerm);
    }

    if (in_array('orders', $allowedTypes, true)) {
        $branches[] = "
            (SELECT
                'orders' AS entity_type,
                o.id AS entity_id,
                CONCAT('Order ', o.order_number) AS label,
                CONCAT_WS(' - ',
                    CASE WHEN c.name IS NOT NULL THEN CONCAT('Customer: ', c.name) END,
                    CASE WHEN o.tracking_number IS NOT NULL THEN CONCAT('Tracking: ', o.tracking_number) END
                ) AS subtitle,
                CONCAT('/modules/orders/view.php?id=', o.id) AS url
             FROM mewmii_orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.order_number LIKE ? ESCAPE '\\\\'
                OR o.tracking_number LIKE ? ESCAPE '\\\\'
                OR c.name LIKE ? ESCAPE '\\\\'
             LIMIT {$limitPerType})
        ";
        array_push($params, $likeTerm, $likeTerm, $likeTerm);
    }

    if (in_array('supplier_orders', $allowedTypes, true)) {
        $branches[] = "
            (SELECT
                'supplier_orders' AS entity_type,
                so.id AS entity_id,
                CONCAT('Purchase Order ', so.purchase_number) AS label,
                CONCAT_WS(' - ', s.name, so.status) AS subtitle,
                CONCAT('/modules/supplier-orders/view.php?id=', so.id) AS url
             FROM supplier_orders so
             INNER JOIN suppliers s ON s.id = so.supplier_id
             WHERE so.purchase_number LIKE ? ESCAPE '\\\\'
             LIMIT {$limitPerType})
        ";
        $params[] = $likeTerm;
    }

    if (in_array('suppliers', $allowedTypes, true)) {
        $branches[] = "
            (SELECT
                'suppliers' AS entity_type,
                s.id AS entity_id,
                s.name AS label,
                COALESCE(s.country, 'Supplier') AS subtitle,
                CONCAT('/modules/suppliers/view.php?id=', s.id) AS url
             FROM suppliers s
             WHERE s.name LIKE ? ESCAPE '\\\\'
             LIMIT {$limitPerType})
        ";
        $params[] = $likeTerm;
    }

    if (in_array('shipments', $allowedTypes, true)) {
        $branches[] = "
            (SELECT
                'shipments' AS entity_type,
                sh.id AS entity_id,
                CONCAT('Shipment ', sh.shipment_number) AS label,
                CONCAT_WS(' - ',
                    CASE WHEN sh.tracking_number IS NOT NULL THEN CONCAT('Tracking: ', sh.tracking_number) END,
                    sh.carrier
                ) AS subtitle,
                CONCAT('/modules/shipments/view.php?id=', sh.id) AS url
             FROM shipments sh
             WHERE sh.tracking_number LIKE ? ESCAPE '\\\\'
                OR sh.shipment_number LIKE ? ESCAPE '\\\\'
             LIMIT {$limitPerType})
        ";
        array_push($params, $likeTerm, $likeTerm);
    }

    if ($branches === []) {
        return [];
    }

    $sql = implode("\n UNION ALL \n", $branches);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
