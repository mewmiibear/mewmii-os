<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('settings.manage');

$pdo = app_db();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="customers-export-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Name', 'Email', 'Phone', 'Instagram', 'Address', 'Created At']);

$stmt = $pdo->query('SELECT name, email, phone, instagram_username, address, created_at FROM customers ORDER BY name ASC');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    fputcsv($out, [
        $row['name'],
        $row['email'] ?? '',
        $row['phone'] ?? '',
        $row['instagram_username'] ?? '',
        $row['address'] ?? '',
        $row['created_at'],
    ]);
}

fclose($out);
