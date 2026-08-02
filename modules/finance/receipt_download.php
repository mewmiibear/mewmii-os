<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/receipt_storage.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.view');

/**
 * The ONLY way an expense_attachments file is ever served - never a direct link into
 * storage/payment_receipts/ (blocked at the web server level, includes/receipt_storage.php).
 * Same pattern as modules/orders/receipt_download.php: check permission, log, stream via
 * receipt_storage_stream(), which itself re-validates the resolved path.
 */

$attachmentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}

$pdo = app_db();
$attachment = expense_attachment_get($pdo, $attachmentId);

if ($attachment === null) {
    http_response_code(404);
    exit('Receipt not found.');
}

activity_log($pdo, 'finance', 'viewed_receipt', (int) $attachment['expense_id'], 'Viewed receipt for expense #' . $attachment['expense_id'] . '.');

receipt_storage_stream((string) $attachment['file_path'], (string) $attachment['original_filename']);
