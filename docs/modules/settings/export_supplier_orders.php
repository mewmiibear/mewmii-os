<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('settings.manage');

$pdo = app_db();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="supplier-orders-export-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Purchase Number', 'Supplier', 'Status', 'Payment Status', 'Product Subtotal', 'Shipping Fee', 'Total Purchase Amount', 'Paid Amount', 'Remaining Amount', 'Order Date']);

$stmt = $pdo->query('
    SELECT so.id, so.purchase_number, s.name AS supplier_name, so.status, so.payment_status,
           so.estimated_cost, so.shipping_fee, so.order_date
    FROM supplier_orders so
    INNER JOIN suppliers s ON s.id = so.supplier_id
    ORDER BY so.id DESC
');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $total = (float) $row['estimated_cost'] + (float) $row['shipping_fee'];
    $paid = supplier_order_paid_amount($pdo, (int) $row['id']);

    fputcsv($out, [
        $row['purchase_number'],
        $row['supplier_name'],
        $row['status'],
        $row['payment_status'],
        number_format((float) $row['estimated_cost'], 2, '.', ''),
        number_format((float) $row['shipping_fee'], 2, '.', ''),
        number_format($total, 2, '.', ''),
        number_format($paid, 2, '.', ''),
        number_format($total - $paid, 2, '.', ''),
        $row['order_date'] ?? '',
    ]);
}

fclose($out);
