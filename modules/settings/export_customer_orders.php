<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('settings.manage');

$pdo = app_db();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="customer-orders-export-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Order Number', 'Customer', 'Order Status', 'Payment Status', 'Subtotal', 'Discount', 'Shipping Fee', 'Total Amount', 'Order Date']);

$stmt = $pdo->query('
    SELECT o.order_number, c.name AS customer_name, o.order_status, o.payment_status,
           o.subtotal, o.discount, o.shipping_fee, o.total_amount, o.order_date
    FROM mewmii_orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    ORDER BY o.id DESC
');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    fputcsv($out, [
        $row['order_number'],
        $row['customer_name'] ?? '',
        $row['order_status'],
        $row['payment_status'],
        number_format((float) $row['subtotal'], 2, '.', ''),
        number_format((float) $row['discount'], 2, '.', ''),
        number_format((float) $row['shipping_fee'], 2, '.', ''),
        number_format((float) $row['total_amount'], 2, '.', ''),
        $row['order_date'] ?? '',
    ]);
}

fclose($out);
