<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('inventory.view');

$pdo = app_db();
$productTypeLabels = ['ready_stock' => 'Ready Stock', 'preorder' => 'Preorder', 'early_bird' => 'Early Bird'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="inventory-export-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Product', 'Variation', 'SKU', 'Type', 'Current', 'Available', 'Reserved', 'Incoming', 'Arrived', 'Last Updated']);

$simpleStmt = $pdo->query("
    SELECT p.id, p.sku, p.name, p.product_type,
           COALESCE(i.available_quantity, 0) AS available_quantity,
           COALESCE(i.reserved_quantity, 0) AS reserved_quantity,
           COALESCE(i.incoming_quantity, 0) AS incoming_quantity,
           COALESCE(i.arrived_quantity, 0) AS arrived_quantity,
           i.updated_at
    FROM products p
    LEFT JOIN mewmii_inventory i ON i.product_id = p.id AND i.variation_id IS NULL
    WHERE p.catalog_type = 'simple'
    ORDER BY p.name ASC
");
foreach ($simpleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    fputcsv($out, [
        $row['name'],
        '',
        $row['sku'],
        $productTypeLabels[$row['product_type']] ?? $row['product_type'],
        (int) $row['available_quantity'] + (int) $row['reserved_quantity'],
        (int) $row['available_quantity'],
        (int) $row['reserved_quantity'],
        (int) $row['incoming_quantity'],
        (int) $row['arrived_quantity'],
        $row['updated_at'] ?? '',
    ]);
}

$variableStmt = $pdo->query("
    SELECT p.id AS product_id, p.name AS product_name, p.product_type,
           pv.id AS variation_id, pv.sku,
           COALESCE(inv.available_quantity, 0) AS available_quantity,
           COALESCE(inv.reserved_quantity, 0) AS reserved_quantity,
           COALESCE(inv.incoming_quantity, 0) AS incoming_quantity,
           COALESCE(inv.arrived_quantity, 0) AS arrived_quantity,
           inv.updated_at
    FROM products p
    INNER JOIN product_variations pv ON pv.product_id = p.id
    LEFT JOIN mewmii_inventory inv ON inv.variation_id = pv.id
    WHERE p.catalog_type = 'variable' AND pv.status <> 'archived'
    ORDER BY p.name ASC, pv.id ASC
");
foreach ($variableStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $variationLabel = variation_build_full_label($pdo, (int) $row['variation_id']);
    fputcsv($out, [
        $row['product_name'],
        $variationLabel,
        $row['sku'],
        $productTypeLabels[$row['product_type']] ?? $row['product_type'],
        (int) $row['available_quantity'] + (int) $row['reserved_quantity'],
        (int) $row['available_quantity'],
        (int) $row['reserved_quantity'],
        (int) $row['incoming_quantity'],
        (int) $row['arrived_quantity'],
        $row['updated_at'] ?? '',
    ]);
}

fclose($out);
