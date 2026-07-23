<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('settings.manage');

$pdo = app_db();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="products-export-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['SKU', 'Internal Code', 'Supplier SKU', 'Name', 'Type', 'Structure', 'Status', 'Category', 'Brand', 'Supplier', 'Selling Price', 'Cost Price']);

$stmt = $pdo->query("
    SELECT p.sku, p.internal_code, p.supplier_sku, p.name, p.product_type, p.catalog_type, p.status,
           p.selling_price, p.product_cost, b.name AS brand_name, s.name AS supplier_name,
           (SELECT cat.name FROM product_category_relationships pcr
               INNER JOIN categories cat ON cat.id = pcr.category_id
               WHERE pcr.product_id = p.id ORDER BY pcr.category_id ASC LIMIT 1) AS category_name
    FROM products p
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    ORDER BY p.name ASC
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    fputcsv($out, [
        $row['sku'],
        $row['internal_code'] ?? '',
        $row['supplier_sku'] ?? '',
        $row['name'],
        $row['product_type'],
        $row['catalog_type'],
        $row['status'],
        $row['category_name'] ?? '',
        $row['brand_name'] ?? '',
        $row['supplier_name'] ?? '',
        number_format((float) $row['selling_price'], 2, '.', ''),
        number_format((float) $row['product_cost'], 2, '.', ''),
    ]);
}

fclose($out);
