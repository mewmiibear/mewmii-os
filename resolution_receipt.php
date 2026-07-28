<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/order_resolution.php';

/**
 * Customer Order Resolution System - lets the customer re-view a receipt THEY uploaded, gated
 * by the same token as resolution.php (never by a login they don't have). The receipt id alone
 * is not enough - it must belong to the resolution the token resolves to, checked explicitly
 * below, matching "customer can only upload receipts for their own resolution request" applied
 * to viewing too.
 */

$pdo = app_db();
$rawToken = trim((string) ($_GET['token'] ?? ''));
$resolution = $rawToken !== '' ? resolution_find_by_token($pdo, $rawToken) : null;

if ($resolution === null) {
    http_response_code(404);
    exit('Not found.');
}

$receiptId = (int) ($_GET['receipt_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM payment_receipts WHERE id = ? AND resolution_id = ?');
$stmt->execute([$receiptId, (int) $resolution['id']]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if ($receipt === false) {
    http_response_code(404);
    exit('Not found.');
}

receipt_storage_stream((string) $receipt['file_path'], (string) $receipt['file_name']);
