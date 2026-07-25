<?php

require_once __DIR__ . '/orders.php';

/**
 * Global Search (Sprint 10 - Operator Experience). Nothing like this existed before - each
 * module only ever had its own local ?q= box. Every section below reuses that same module's
 * existing search columns (see modules/products/index.php, modules/orders/index.php, etc.)
 * and is only queried at all if the current session holds that module's own .view
 * permission - the same per-section gating style already used on index.php (the dashboard).
 *
 * Returns an ordered array of sections, each: ['label' => ..., 'items' => [['title' =>
 * ..., 'subtitle' => ..., 'url' => ...], ...]]. A section is omitted entirely when the
 * caller lacks permission for it or the term matched nothing there.
 */
function global_search(PDO $pdo, string $term, int $limitPerType): array
{
    $term = trim($term);
    if ($term === '') {
        return [];
    }

    $like = '%' . $term . '%';
    $sections = [];

    if (app_has_permission('products.view')) {
        $stmt = $pdo->prepare('
            SELECT id, name, sku
            FROM products
            WHERE name LIKE ? OR sku LIKE ? OR barcode LIKE ?
            ORDER BY name ASC
            LIMIT ' . $limitPerType . '
        ');
        $stmt->execute([$like, $like, $like]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'title' => $row['name'],
                'subtitle' => $row['sku'] !== null && $row['sku'] !== '' ? ('SKU: ' . $row['sku']) : null,
                'url' => '/modules/products/view.php?id=' . (int) $row['id'],
            ];
        }
        if ($items !== []) {
            $sections['products'] = ['label' => 'Products', 'items' => $items, 'see_all_url' => '/modules/products/index.php?q=' . urlencode($term)];
        }
    }

    if (app_has_permission('orders.view')) {
        $stmt = $pdo->prepare('
            SELECT o.id, o.order_number, o.order_status, c.name AS customer_name
            FROM mewmii_orders o
            LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.order_number LIKE ? OR c.name LIKE ?
            ORDER BY o.created_at DESC
            LIMIT ' . $limitPerType . '
        ');
        $stmt->execute([$like, $like]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subtitleParts = array_filter([
                $row['customer_name'],
                order_status_label((string) $row['order_status']),
            ]);
            $items[] = [
                'title' => order_display_number_compact((string) $row['order_number']),
                'subtitle' => $subtitleParts !== [] ? implode(' · ', $subtitleParts) : null,
                'url' => '/modules/orders/view.php?id=' . (int) $row['id'],
            ];
        }
        if ($items !== []) {
            $sections['orders'] = ['label' => 'Orders', 'items' => $items, 'see_all_url' => '/modules/orders/index.php?q=' . urlencode($term)];
        }
    }

    if (app_has_permission('customers.view')) {
        $stmt = $pdo->prepare('
            SELECT id, name, email, phone
            FROM customers
            WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?
            ORDER BY name ASC
            LIMIT ' . $limitPerType . '
        ');
        $stmt->execute([$like, $like, $like]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'title' => $row['name'],
                'subtitle' => $row['email'] ?? $row['phone'] ?? null,
                'url' => '/modules/customers/view.php?id=' . (int) $row['id'],
            ];
        }
        if ($items !== []) {
            $sections['customers'] = ['label' => 'Customers', 'items' => $items, 'see_all_url' => '/modules/customers/index.php?q=' . urlencode($term)];
        }
    }

    if (app_has_permission('supplier-orders.view')) {
        $stmt = $pdo->prepare('
            SELECT so.id, so.purchase_number, so.status, s.name AS supplier_name
            FROM supplier_orders so
            LEFT JOIN suppliers s ON s.id = so.supplier_id
            WHERE so.purchase_number LIKE ? OR s.name LIKE ?
            ORDER BY so.created_at DESC
            LIMIT ' . $limitPerType . '
        ');
        $stmt->execute([$like, $like]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'title' => (string) $row['purchase_number'],
                'subtitle' => $row['supplier_name'] ?? null,
                'url' => '/modules/supplier-orders/view.php?id=' . (int) $row['id'],
            ];
        }
        if ($items !== []) {
            $sections['supplier_orders'] = ['label' => 'Supplier Orders', 'items' => $items, 'see_all_url' => '/modules/supplier-orders/index.php?q=' . urlencode($term)];
        }
    }

    if (app_has_permission('shipments.view')) {
        $stmt = $pdo->prepare('
            SELECT sh.id, sh.shipment_number, sh.tracking_number, c.name AS customer_name
            FROM shipments sh
            LEFT JOIN customers c ON c.id = sh.customer_id
            WHERE sh.shipment_number LIKE ? OR sh.tracking_number LIKE ? OR c.name LIKE ?
            ORDER BY sh.created_at DESC
            LIMIT ' . $limitPerType . '
        ');
        $stmt->execute([$like, $like, $like]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'title' => (string) $row['shipment_number'],
                'subtitle' => $row['customer_name'] ?? null,
                'url' => '/modules/shipments/view.php?id=' . (int) $row['id'],
            ];
        }
        if ($items !== []) {
            $sections['shipments'] = ['label' => 'Shipments', 'items' => $items, 'see_all_url' => '/modules/shipments/index.php?q=' . urlencode($term)];
        }
    }

    if (app_has_permission('products.view')) {
        // Catalogue: categories/brands/collections/tags/attributes, one UNION ALL across
        // the five small admin-managed taxonomy tables - each already searched individually
        // on its own Catalogue Manager tab (modules/catalog/tabs/*.php), never before as one
        // combined search. Low-cardinality admin lists, so this stays cheap.
        $stmt = $pdo->prepare("
            (SELECT 'categories' AS tab, id, name FROM categories WHERE name LIKE ? LIMIT {$limitPerType})
            UNION ALL
            (SELECT 'brands' AS tab, id, name FROM brands WHERE name LIKE ? LIMIT {$limitPerType})
            UNION ALL
            (SELECT 'collections' AS tab, id, name FROM collections WHERE name LIKE ? LIMIT {$limitPerType})
            UNION ALL
            (SELECT 'tags' AS tab, id, name FROM product_tags WHERE name LIKE ? LIMIT {$limitPerType})
            UNION ALL
            (SELECT 'attributes' AS tab, id, name FROM product_attributes WHERE name LIKE ? LIMIT {$limitPerType})
        ");
        $stmt->execute([$like, $like, $like, $like, $like]);
        $items = [];
        foreach (array_slice($stmt->fetchAll(PDO::FETCH_ASSOC), 0, $limitPerType) as $row) {
            $items[] = [
                'title' => (string) $row['name'],
                'subtitle' => ucfirst((string) $row['tab']),
                'url' => '/modules/catalog/index.php?tab=' . urlencode((string) $row['tab']),
            ];
        }
        if ($items !== []) {
            $sections['catalogue'] = ['label' => 'Catalogue', 'items' => $items, 'see_all_url' => '/modules/catalog/index.php'];
        }
    }

    return $sections;
}
