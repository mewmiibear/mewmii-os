<?php

/**
 * Customer Order Resolution System - secure, PRIVATE storage for uploaded payment receipts.
 * Deliberately NOT reusing includes/image_upload.php: that pipeline writes into uploads/, which
 * is publicly web-servable by design (product photos are meant to be public) and only accepts
 * image types. A receipt must never be reachable by a guessed/scraped URL, and PDFs are a valid
 * receipt format that IMAGE_UPLOAD_ALLOWED_MIME doesn't cover - so this is a parallel, smaller
 * pipeline for a genuinely different trust boundary, not a duplicate of the image one.
 *
 * Storage lives at storage/payment_receipts/ (outside uploads/), protected by its own
 * receipt_storage_ensure_htaccess() - Apache denies ALL direct requests there, both old
 * (`Deny from all`) and new (`Require all denied`) syntax so it's blocked regardless of Apache
 * version. The only way to read a stored receipt is receipt_storage_stream() after an explicit
 * permission/ownership check by the caller (modules/orders/receipt_download.php for admin,
 * resolution.php's own token-scoped view for the customer who uploaded it) - this file never
 * checks permissions itself, it only stores/streams bytes.
 */

const RECEIPT_UPLOAD_MAX_BYTES = 8 * 1024 * 1024; // 8 MB, same cap as product images
const RECEIPT_UPLOAD_ALLOWED_MIME = ['image/jpeg', 'image/png', 'application/pdf'];
const RECEIPT_UPLOAD_EXTENSIONS = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'application/pdf' => 'pdf',
];

function receipt_storage_base_dir(): string
{
    return dirname(__DIR__) . '/storage/payment_receipts';
}

function receipt_storage_ensure_dir(): void
{
    $dir = receipt_storage_base_dir();
    if (is_dir($dir)) {
        return;
    }

    if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the receipt storage directory. Check filesystem permissions.');
    }
}

function receipt_storage_ensure_htaccess(): void
{
    $htaccessPath = receipt_storage_base_dir() . '/.htaccess';
    if (is_file($htaccessPath)) {
        return;
    }

    file_put_contents($htaccessPath, "Order Deny,Allow\nDeny from all\n\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\nphp_flag engine off\n");
}

/**
 * Validates a $_FILES-style entry (type, size, real MIME via finfo - never the client-reported
 * type/extension) and moves it into private storage under a random, non-guessable filename.
 * The customer's original filename is preserved separately (for display only, in the returned
 * 'file_name') - it is NEVER used to build the on-disk path, which is what "secure filenames"
 * means here: no path traversal, no XSS-via-filename, no collision, no information disclosure
 * from a customer's own file naming.
 *
 * @return array{file_path: string, file_name: string} file_path is relative to the app root
 * (e.g. "storage/payment_receipts/ab12cd34ef56.pdf"), stored in payment_receipts.file_path.
 */
function receipt_upload_validate_and_store(array $file): array
{
    if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('No file was uploaded.');
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Receipt upload failed (error code ' . $file['error'] . ').');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload.');
    }
    if ((int) $file['size'] > RECEIPT_UPLOAD_MAX_BYTES) {
        throw new RuntimeException('File is too large (max ' . (int) (RECEIPT_UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB).');
    }

    if (!function_exists('finfo_open')) {
        throw new RuntimeException('The PHP fileinfo extension is required for receipt uploads.');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo !== false) {
        finfo_close($finfo);
    }

    if ($mime === false || !in_array($mime, RECEIPT_UPLOAD_ALLOWED_MIME, true)) {
        throw new RuntimeException('Unsupported file type. Upload a JPG, PNG, or PDF.');
    }

    receipt_storage_ensure_dir();
    receipt_storage_ensure_htaccess();

    $extension = RECEIPT_UPLOAD_EXTENSIONS[$mime];
    $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    $fullPath = receipt_storage_base_dir() . '/' . $storedFilename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new RuntimeException('Failed to store the uploaded receipt.');
    }

    // Display-only name - stripped of any path component and control characters, truncated to
    // a sane length. Never used to construct a filesystem path (see this function's own docblock).
    $originalName = basename((string) ($file['name'] ?? 'receipt'));
    $originalName = preg_replace('/[^\PC]/u', '', $originalName) ?? 'receipt';
    $originalName = substr($originalName, 0, 255);
    if ($originalName === '') {
        $originalName = 'receipt.' . $extension;
    }

    return [
        'file_path' => 'storage/payment_receipts/' . $storedFilename,
        'file_name' => $originalName,
    ];
}

/**
 * Streams a stored receipt to the current response. Caller MUST have already verified the
 * requester is allowed to see this exact file (admin permission, or the customer who owns the
 * resolution/token) - this function performs no authorization of its own, only path resolution
 * and safe output. $mimeOverride lets the caller pass the extension-derived type without a
 * second finfo call.
 */
function receipt_storage_stream(string $relativePath, string $displayName): void
{
    $fullPath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');

    // Defense in depth against a manipulated relativePath ever reaching this point - resolved
    // real path must still be inside the receipt storage directory.
    $realBase = realpath(receipt_storage_base_dir());
    $realFile = realpath($fullPath);
    if ($realBase === false || $realFile === false || strncmp($realFile, $realBase, strlen($realBase)) !== 0) {
        http_response_code(404);
        echo 'Receipt not found.';

        return;
    }

    $extension = strtolower((string) pathinfo($realFile, PATHINFO_EXTENSION));
    $contentType = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf',
    ][$extension] ?? 'application/octet-stream';

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($realFile));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $displayName) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');

    readfile($realFile);
}
