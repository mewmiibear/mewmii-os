<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/receipt_storage.php';
require_once __DIR__ . '/../../includes/activity_log.php';
app_require_permission('orders.view');

/**
 * Customer Order Resolution System - the ONLY way a payment_receipts file is ever served to an
 * admin. Never a direct link into storage/payment_receipts/ (blocked at the web server level -
 * see receipt_storage_ensure_htaccess()) - this script checks permission, then streams the file
 * via receipt_storage_stream(), which itself re-validates the resolved path stays inside the
 * receipt storage directory.
 */

$receiptId = (int) ($_GET['id'] ?? 0);
if ($receiptId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}

$pdo = app_db();
$stmt = $pdo->prepare('SELECT * FROM payment_receipts WHERE id = ?');
$stmt->execute([$receiptId]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if ($receipt === false) {
    http_response_code(404);
    exit('Receipt not found.');
}

activity_log($pdo, 'resolution', 'admin_viewed_receipt', (int) $receipt['resolution_id'], 'Admin viewed payment receipt #' . $receiptId . '.');

receipt_storage_stream((string) $receipt['file_path'], (string) $receipt['file_name']);
