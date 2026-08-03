<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/receipt_storage.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.view');

/**
 * The ONLY way an asset_attachments file is ever served - never a direct link into
 * storage/payment_receipts/ (blocked at the web server level, includes/receipt_storage.php).
 * Same pattern as modules/finance/receipt_download.php: check permission, log, stream via
 * receipt_storage_stream(), which itself re-validates the resolved path.
 */

$attachmentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId < 1) {
    http_response_code(404);
    exit('Document not found.');
}

$pdo = app_db();
$attachment = asset_attachment_get($pdo, $attachmentId);

if ($attachment === null) {
    http_response_code(404);
    exit('Document not found.');
}

activity_log($pdo, 'finance', 'viewed_asset_document', (int) $attachment['asset_id'], 'Viewed document for asset #' . $attachment['asset_id'] . '.');

receipt_storage_stream((string) $attachment['file_path'], (string) $attachment['original_filename']);
