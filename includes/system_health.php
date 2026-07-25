<?php

require_once __DIR__ . '/image_upload.php';

/**
 * System Health (Issue 5 - Production Migration Safety). We've now hit two separate
 * incidents where code was deployed expecting a database column/table that a migration
 * script existed for, but was never actually run against production: saved_views (missing
 * table) and products.woocommerce_sync_hash (missing column). This is a lightweight,
 * read-only check - not a new migration runner or tracking table - that answers "does this
 * database actually have what the code currently expects" at a glance, so a gap like either
 * of those two is caught by looking at a page instead of by a production error.
 *
 * Each entry names one migration script and ONE representative column/table it adds - since
 * every migrate_*.php script here runs as a single all-or-nothing sitting (see each script's
 * own docblock), checking one artifact it creates is a reliable proxy for "was this whole
 * script run", without needing to duplicate every column of every migration into a second,
 * parallel manifest that could itself drift out of sync.
 */

const SYSTEM_HEALTH_MIGRATIONS = [
    ['label' => 'Catalog overhaul (attributes, variations, brands/categories/collections)', 'migration' => 'migrate_catalog.php', 'table' => 'products', 'column' => 'catalog_type'],
    ['label' => 'Catalogue Manager metadata (brand/category/collection descriptions+images)', 'migration' => 'migrate_catalog_management.php', 'table' => 'brands', 'column' => 'description'],
    ['label' => 'WooCommerce push sync (fingerprint/staleness)', 'migration' => 'migrate_woocommerce_sync.php', 'table' => 'products', 'column' => 'woocommerce_sync_hash'],
    ['label' => 'Production hardening (indexes, customer dedup)', 'migration' => 'migrate_production_hardening.php', 'table' => 'customers', 'column' => 'woocommerce_customer_id'],
    ['label' => 'Saved Views', 'migration' => 'migrate_saved_views.php', 'table' => 'saved_views', 'column' => null],
    ['label' => 'Product costing prep', 'migration' => 'migrate_product_costing.php', 'table' => 'products', 'column' => 'cost_currency'],
];

// A subset of migrate_production_hardening.php's own performance indexes - grouped as one
// health-check line ("required indexes") rather than one row each, since they were all
// added by that single migration and matter here as a set, not individually.
const SYSTEM_HEALTH_INDEXES = [
    ['table' => 'customers', 'index' => 'idx_customers_email'],
    ['table' => 'customers', 'index' => 'uq_customers_woocommerce_customer_id'],
    ['table' => 'mewmii_orders', 'index' => 'idx_mewmii_orders_order_status'],
    ['table' => 'mewmii_orders', 'index' => 'idx_mewmii_orders_order_date'],
    ['table' => 'mewmii_orders', 'index' => 'idx_mewmii_orders_created_at'],
    ['table' => 'inventory_transactions', 'index' => 'idx_inventory_transactions_reference'],
    ['table' => 'supplier_orders', 'index' => 'idx_supplier_orders_status'],
    ['table' => 'supplier_orders', 'index' => 'idx_supplier_orders_payment_status'],
    ['table' => 'supplier_orders', 'index' => 'idx_supplier_orders_expected_delivery_date'],
    ['table' => 'products', 'index' => 'idx_products_status'],
    ['table' => 'products', 'index' => 'idx_products_product_type'],
];

function system_health_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function system_health_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ');
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() > 0;
}

function system_health_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ');
    $stmt->execute([$table, $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Bugfix pass (WooCommerce image sync 404 incident) - a one-shot, admin-triggered reachability
 * probe against ONE real stored image's computed public URL (image_upload_public_url()), so
 * "are uploaded files actually publicly accessible" is answered by looking at this page
 * instead of by a confusing remote "Not Found" surfacing from WooCommerce mid-sync. Uses curl
 * directly (already required for the WooCommerce API client - see wc_client_request()) rather
 * than get_headers(), since that needs allow_url_fopen, which isn't guaranteed enabled.
 * Deliberately NOT run automatically during a real sync: a self-request from the same server
 * can spuriously fail even when the URL is genuinely reachable from the internet (hairpin
 * NAT/firewall quirks on some hosts), so this stays a manual, on-demand check only.
 *
 * @return array{reachable: bool, http_status: ?int, error: ?string}
 */
function system_health_check_url_reachable(string $url, int $timeoutSeconds = 5): array
{
    if (!function_exists('curl_init')) {
        return ['reachable' => false, 'http_status' => null, 'error' => 'The PHP curl extension is not available.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['reachable' => false, 'http_status' => null, 'error' => $error];
    }

    if ($status < 200 || $status >= 300) {
        return ['reachable' => false, 'http_status' => $status, 'error' => 'HTTP ' . $status];
    }

    return ['reachable' => true, 'http_status' => $status, 'error' => null];
}

/**
 * Picks the single most-recently-added product image (if any exist at all) to probe with
 * system_health_check_url_reachable() - a real, currently-stored file rather than a
 * synthetic path, so the check reflects an actual product an operator could sync today.
 *
 * @return array{path: string, url: string}|null
 */
function system_health_sample_image(PDO $pdo): ?array
{
    $path = $pdo->query('SELECT image_path FROM product_images ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($path === false || trim((string) $path) === '') {
        return null;
    }

    return ['path' => (string) $path, 'url' => image_upload_public_url((string) $path)];
}

/**
 * Bugfix pass (uploads accessibility still 404s after two URL-config attempts) - answers
 * the actual question at the filesystem level instead of guessing another domain: where
 * does image_upload_base_dir() (includes/image_upload.php) resolve to ON DISK, does the
 * given stored file actually exist there, and is that location even INSIDE the directory
 * tree the web server is serving for the current request ($_SERVER['DOCUMENT_ROOT'], the
 * one thing PHP itself can state with certainty, no guessing about which domain/vhost is
 * involved). If base_dir sits outside document_root entirely, no URL on any domain can
 * ever reach it - that's a hosting/deployment fix (symlink, move the folder, or a
 * PHP-streamed download endpoint), not another config value to try.
 *
 * @return array{
 *   base_dir: string,
 *   document_root: ?string,
 *   inside_document_root: ?bool,
 *   sample_relative_path: ?string,
 *   sample_absolute_path: ?string,
 *   sample_exists_on_disk: ?bool,
 * }
 */
function system_health_uploads_filesystem_info(?string $sampleRelativePath): array
{
    $baseDir = image_upload_base_dir();
    $baseDirReal = realpath($baseDir);
    $baseDirForCompare = $baseDirReal !== false ? $baseDirReal : $baseDir;

    $documentRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\') : null;
    $documentRootReal = $documentRoot !== null ? realpath($documentRoot) : false;
    $documentRootForCompare = $documentRootReal !== false ? $documentRootReal : $documentRoot;

    $insideDocumentRoot = null;
    if ($documentRootForCompare !== null) {
        // Normalize separators before the prefix check - a Windows dev box mixes / and \
        // in a way a straight string comparison would falsely fail on.
        $normalize = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');
        $insideDocumentRoot = str_starts_with($normalize($baseDirForCompare) . '/', $normalize($documentRootForCompare) . '/');
    }

    $sampleAbsolutePath = null;
    $sampleExists = null;
    if ($sampleRelativePath !== null && $sampleRelativePath !== '') {
        // $sampleRelativePath is image_path as stored (e.g. "uploads/variations/x.webp"),
        // already rooted at the app directory - NOT under $baseDir a second time.
        $sampleAbsolutePath = dirname(__DIR__) . '/' . ltrim($sampleRelativePath, '/');
        $sampleExists = is_file($sampleAbsolutePath);
    }

    return [
        'base_dir' => $baseDirForCompare,
        'document_root' => $documentRootForCompare,
        'inside_document_root' => $insideDocumentRoot,
        'sample_relative_path' => $sampleRelativePath,
        'sample_absolute_path' => $sampleAbsolutePath,
        'sample_exists_on_disk' => $sampleExists,
    ];
}

/**
 * Bugfix pass ("do not assume the upload succeeded because the code path says it should") -
 * runs the EXACT same steps image_upload_encode_webp() (includes/image_upload.php) runs for
 * a real upload, against a tiny synthetic image, and reports every intermediate result
 * instead of only a final pass/fail. This is a real disk write on THIS server, right now -
 * not a re-read of the source code - so it answers "does this actually work here" directly,
 * for whichever of uploads/products or uploads/variations is passed in. The test file is
 * deleted again immediately after every check is captured, whether the test passed or not.
 *
 * @return array{
 *   sub_dir: string,
 *   gd_available: bool,
 *   webp_available: bool,
 *   base_dir: string,
 *   target_dir: string,
 *   target_dir_created_ok: bool,
 *   target_dir_error: ?string,
 *   is_dir: bool,
 *   is_writable: bool,
 *   filename: string,
 *   full_path: string,
 *   imagewebp_returned: ?bool,
 *   file_exists_after_write: bool,
 *   filesize_after_write: ?int,
 *   public_url: string,
 *   cleaned_up: bool,
 * }
 */
function system_health_test_image_write(string $subDir): array
{
    $result = [
        'sub_dir' => $subDir,
        'gd_available' => function_exists('imagecreatetruecolor'),
        'webp_available' => function_exists('imagewebp'),
        'base_dir' => image_upload_base_dir(),
        'target_dir' => '',
        'target_dir_created_ok' => false,
        'target_dir_error' => null,
        'is_dir' => false,
        'is_writable' => false,
        'filename' => '',
        'full_path' => '',
        'imagewebp_returned' => null,
        'file_exists_after_write' => false,
        'filesize_after_write' => null,
        'public_url' => '',
        'cleaned_up' => false,
    ];

    if (!$result['gd_available'] || !$result['webp_available']) {
        return $result;
    }

    $baseDir = image_upload_base_dir();
    $targetDir = $baseDir . '/' . trim($subDir, '/');
    $result['target_dir'] = $targetDir;

    try {
        image_upload_ensure_dir($baseDir);
        image_upload_ensure_dir($targetDir);
        $result['target_dir_created_ok'] = true;
    } catch (Throwable $e) {
        $result['target_dir_error'] = $e->getMessage();

        return $result;
    }

    $result['is_dir'] = is_dir($targetDir);
    $result['is_writable'] = is_writable($targetDir);

    $filename = 'health-check-' . bin2hex(random_bytes(6)) . '.webp';
    $fullPath = $targetDir . '/' . $filename;
    $result['filename'] = $filename;
    $result['full_path'] = $fullPath;

    // A trivial 4x4 solid-color image - the point is exercising the real write path
    // (imagewebp() to this exact directory), not testing image processing itself.
    $image = imagecreatetruecolor(4, 4);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 100, 50));
    $result['imagewebp_returned'] = imagewebp($image, $fullPath, 82);
    imagedestroy($image);

    $result['file_exists_after_write'] = is_file($fullPath);
    $result['filesize_after_write'] = $result['file_exists_after_write'] ? filesize($fullPath) : null;

    if ($result['file_exists_after_write']) {
        $result['public_url'] = image_upload_public_url('uploads/' . trim($subDir, '/') . '/' . $filename);
        $result['cleaned_up'] = @unlink($fullPath);
    }

    return $result;
}

/**
 * Bugfix pass - every product_images row (not just one sample), each checked against the
 * real filesystem right now, so a systemic write problem (every recent upload missing) is
 * visibly different from an isolated one (a couple of old orphaned rows from a past
 * incident). Ordered newest-first and capped, since this is a diagnostic list for a human
 * to scan, not a paginated admin table.
 *
 * @return array<array{id: int, product_id: int, variation_id: ?int, image_type: string, image_path: string, exists_on_disk: bool, filesize: ?int, created_at: string}>
 */
function system_health_list_all_images(PDO $pdo, int $limit = 50): array
{
    $stmt = $pdo->prepare('
        SELECT id, product_id, variation_id, image_type, image_path, created_at
        FROM product_images
        ORDER BY id DESC
        LIMIT ' . max(1, $limit) . '
    ');
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $absolutePath = dirname(__DIR__) . '/' . ltrim((string) $row['image_path'], '/');
        $exists = is_file($absolutePath);

        $rows[] = [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'variation_id' => $row['variation_id'] !== null ? (int) $row['variation_id'] : null,
            'image_type' => (string) $row['image_type'],
            'image_path' => (string) $row['image_path'],
            'exists_on_disk' => $exists,
            'filesize' => $exists ? filesize($absolutePath) : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    return $rows;
}

/**
 * @return array{migrations: array, indexes: array{present: int, total: int, missing: array}, pending: array}
 */
function system_health_check(PDO $pdo): array
{
    $migrations = [];
    $pending = [];

    foreach (SYSTEM_HEALTH_MIGRATIONS as $check) {
        $applied = $check['column'] !== null
            ? system_health_column_exists($pdo, $check['table'], $check['column'])
            : system_health_table_exists($pdo, $check['table']);

        $migrations[] = array_merge($check, ['applied' => $applied]);

        if (!$applied) {
            $pending[] = $check['migration'];
        }
    }

    $missingIndexes = [];
    foreach (SYSTEM_HEALTH_INDEXES as $indexCheck) {
        if (!system_health_index_exists($pdo, $indexCheck['table'], $indexCheck['index'])) {
            $missingIndexes[] = $indexCheck['table'] . '.' . $indexCheck['index'];
        }
    }
    if ($missingIndexes !== [] && !in_array('migrate_production_hardening.php', $pending, true)) {
        $pending[] = 'migrate_production_hardening.php';
    }

    return [
        'migrations' => $migrations,
        'indexes' => [
            'present' => count(SYSTEM_HEALTH_INDEXES) - count($missingIndexes),
            'total' => count(SYSTEM_HEALTH_INDEXES),
            'missing' => $missingIndexes,
        ],
        'pending' => array_values(array_unique($pending)),
    ];
}
