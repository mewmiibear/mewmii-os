<?php

require_once __DIR__ . '/image_upload.php';

/**
 * Deployment-protection incident (uploads/ wiped by an external deploy process) - a
 * startup-style check for the uploads directory itself: does it exist, is it a real
 * directory, is it writable. Checked first/most prominently on System Health, since a
 * deploy that deleted or replaced this directory (see DEPLOYMENT.md) is exactly the failure
 * this is meant to catch immediately, rather than discovering it later via a WooCommerce
 * sync error or the Stored Images Audit below.
 *
 * @return array{path: string, exists: bool, is_dir: bool, writable: bool, ok: bool}
 */
function system_health_check_uploads_directory(): array
{
    $path = image_upload_base_dir();
    $exists = file_exists($path);
    $isDir = $exists && is_dir($path);
    $writable = $isDir && is_writable($path);

    return [
        'path' => $path,
        'exists' => $exists,
        'is_dir' => $isDir,
        'writable' => $writable,
        'ok' => $exists && $isDir && $writable,
    ];
}

/**
 * Domain-vs-subdomain uploads mapping incident (WooCommerce image sync 404 after the URL
 * itself is confirmed well-formed and the file confirmed present on disk). The admin app's
 * own root ("public_html/admin", one level above includes/) is a SUBFOLDER of the main
 * domain's document root ("public_html") - image_upload_base_dir() only ever writes under
 * the subfolder's own uploads/, but the public URL WooCommerce is given may point at the
 * ROOT domain's uploads/ instead (a different, sibling directory that was never written to
 * at all). This checks both candidate directories directly on disk - a pure filesystem
 * check, no guessing about which vhost/domain serves which - so "does public_html/uploads/
 * even exist" is answered with certainty rather than inferred from an HTTP response.
 *
 * @return array{
 *   admin_uploads_dir: string,
 *   admin_uploads_exists: bool,
 *   root_uploads_dir: string,
 *   root_uploads_exists: bool,
 *   root_uploads_is_symlink: bool,
 *   root_uploads_symlink_target: ?string,
 * }
 */
function system_health_check_uploads_dual_location(): array
{
    $adminUploadsDir = image_upload_base_dir();
    // One level above the app's own root (dirname(__DIR__), same computation
    // image_upload_base_dir() itself uses) - the main domain's document root, if the app
    // lives in a subfolder of it (e.g. "public_html/admin").
    $rootUploadsDir = dirname(__DIR__, 2) . '/uploads';

    $rootIsLink = @is_link($rootUploadsDir);

    return [
        'admin_uploads_dir' => $adminUploadsDir,
        'admin_uploads_exists' => @is_dir($adminUploadsDir),
        'root_uploads_dir' => $rootUploadsDir,
        'root_uploads_exists' => @is_dir($rootUploadsDir),
        'root_uploads_is_symlink' => $rootIsLink,
        'root_uploads_symlink_target' => $rootIsLink ? (@readlink($rootUploadsDir) ?: null) : null,
    ];
}

/**
 * Definitive runtime test for "which physical directory does https://{$domain}/uploads/...
 * actually serve from" - writes a uniquely-named marker file into EACH candidate directory
 * (whichever ones actually exist and are writable), fetches https://{$domain}/uploads/
 * products/{marker} for each over the real internet (via curl, same as
 * system_health_check_url_reachable()), and reports which marker(s) were actually
 * reachable. Whichever marker comes back 200 tells you, with certainty, which directory
 * that URL prefix is physically served from - not an assumption. Every marker file is
 * deleted again immediately after the check, whether it was reached or not.
 *
 * @return array{
 *   domain: string,
 *   admin_marker_result: ?array{reachable: bool, http_status: ?int, error: ?string},
 *   root_marker_result: ?array{reachable: bool, http_status: ?int, error: ?string},
 *   admin_marker_skipped_reason: ?string,
 *   root_marker_skipped_reason: ?string,
 * }
 */
function system_health_test_uploads_url_mapping(string $domain): array
{
    $dirs = system_health_check_uploads_dual_location();
    $marker = 'health-check-' . bin2hex(random_bytes(6)) . '.txt';
    $domain = rtrim($domain, '/');

    $result = [
        'domain' => $domain,
        'admin_marker_result' => null,
        'root_marker_result' => null,
        'admin_marker_skipped_reason' => null,
        'root_marker_skipped_reason' => null,
    ];

    $candidates = [
        'admin' => $dirs['admin_uploads_dir'],
        'root' => $dirs['root_uploads_dir'],
    ];

    foreach ($candidates as $key => $baseDir) {
        $productsDir = $baseDir . '/products';
        if (!@is_dir($productsDir) || !@is_writable($productsDir)) {
            $result[$key . '_marker_skipped_reason'] = 'products/ subdirectory does not exist or is not writable at ' . $productsDir;

            continue;
        }

        $markerPath = $productsDir . '/' . $marker;
        if (file_put_contents($markerPath, 'health check') === false) {
            $result[$key . '_marker_skipped_reason'] = 'Could not write marker file to ' . $markerPath;

            continue;
        }

        $result[$key . '_marker_result'] = system_health_check_url_reachable($domain . '/uploads/products/' . $marker);

        @unlink($markerPath);
    }

    return $result;
}

/**
 * "Safest fix": if the root domain's uploads/ path (public_html/uploads) doesn't exist at
 * all, create it as a symlink pointing at the admin app's real uploads/ folder
 * (public_html/admin/uploads) - so https://{root domain}/uploads/products/x.webp serves the
 * exact same file admin.{domain}/uploads/products/x.webp already does, with NO file moved,
 * copied, or duplicated, and NO database/sync code touched. Deliberately refuses to
 * overwrite an existing file/directory/symlink at the target path - this only ever fills in
 * a genuinely missing path, never replaces something already there.
 *
 * Not run automatically - PHP's symlink() can fail for reasons this can't fully diagnose
 * from inside the request (open_basedir restricting this vhost from ever seeing outside its
 * own subfolder is the likely one on shared hosting, exactly why the split existed in the
 * first place) - this is a deliberate, explicit, admin-triggered action that reports exactly
 * what happened either way.
 *
 * @return array{ok: bool, message: string}
 */
function system_health_create_uploads_symlink(): array
{
    $dirs = system_health_check_uploads_dual_location();
    $target = $dirs['admin_uploads_dir'];
    $link = $dirs['root_uploads_dir'];

    if (file_exists($link) || is_link($link)) {
        return ['ok' => false, 'message' => 'Something already exists at ' . $link . ' - refusing to overwrite it. Remove or rename it manually first if it is safe to do so.'];
    }

    if (!is_dir($target)) {
        return ['ok' => false, 'message' => 'Symlink target ' . $target . ' does not exist - nothing to link to.'];
    }

    $created = @symlink($target, $link);

    if (!$created) {
        $lastError = error_get_last();

        return [
            'ok' => false,
            'message' => 'symlink() failed (' . ($lastError['message'] ?? 'no further detail from PHP') . '). '
                . 'Most likely cause on shared hosting: this vhost\'s open_basedir restricts PHP to its own subfolder and cannot write outside it - '
                . 'create the symlink manually instead via SSH or your hosting file manager: ln -s "' . $target . '" "' . $link . '"',
        ];
    }

    return ['ok' => true, 'message' => 'Created symlink: ' . $link . ' -> ' . $target];
}

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
    ['label' => 'Additional costs framework', 'migration' => 'migrate_additional_costs.php', 'table' => 'supplier_order_item_costs', 'column' => null],
    ['label' => 'Product cost history', 'migration' => 'migrate_product_cost_history.php', 'table' => 'product_cost_history', 'column' => null],
    ['label' => 'Notification & Alert Center', 'migration' => 'migrate_notifications.php', 'table' => 'mewmii_notifications', 'column' => 'reference_id'],
    ['label' => 'Notification Actions & Lifecycle', 'migration' => 'migrate_notification_lifecycle.php', 'table' => 'mewmii_notifications', 'column' => 'resolved_status'],
    ['label' => 'Pricing Engine', 'migration' => 'migrate_pricing_engine.php', 'table' => 'products', 'column' => 'original_price'],
    ['label' => 'Pricing Engine (shipping rate countries)', 'migration' => 'migrate_pricing_engine.php', 'table' => 'shipping_rate_countries', 'column' => null],
    ['label' => 'Variation Weight Mode', 'migration' => 'migrate_variation_weight_mode.php', 'table' => 'product_variations', 'column' => 'weight_mode'],
    ['label' => 'Global Currency Exchange Rate Settings', 'migration' => 'migrate_currency_rates.php', 'table' => 'currency_rates', 'column' => null],
    ['label' => 'Multi-Purpose Currency Rate Settings', 'migration' => 'migrate_currency_rate_types.php', 'table' => 'currency_rates', 'column' => 'rate_type'],
    ['label' => 'Customer delete-webhook lifecycle (archive/anonymise)', 'migration' => 'migrate_customer_delete_lifecycle.php', 'table' => 'customers', 'column' => 'archived_at'],
    ['label' => 'Unified Outbound Job Queue', 'migration' => 'migrate_outbound_jobs.php', 'table' => 'outbound_jobs', 'column' => null],
    ['label' => 'Customer Order Resolution System', 'migration' => 'migrate_order_resolution.php', 'table' => 'resolution_requests', 'column' => null],
    // Finance & Accounting Phase A. Deliberately checks expenses.category_id rather than the
    // `expenses` TABLE: a pre-Phase-A database already had an `expenses` table (dead scaffolding
    // with a free-text `category VARCHAR(100)`), so a table-existence check here would report a
    // false "applied" on exactly the database that still needs this migration. category_id only
    // exists on the rebuilt shape, and it is also the artifact this migration's own data-safety
    // guard withholds if it refuses to rebuild a non-empty legacy table - so it doubles as the
    // signal for that specific partial-application case.
    ['label' => 'Finance Phase A (expense categories, expenses, receipt attachments)', 'migration' => 'migrate_finance_phase_a.php', 'table' => 'expenses', 'column' => 'category_id'],
    // Finance & Accounting Phase B - three rows for one migration, following the existing
    // two-row migrate_pricing_engine.php precedent above, because this migration spans one new
    // table, one ALTER to a pre-existing table, and a second new table, each of which the app
    // depends on independently (a missing manual_income breaks Manual Income; a missing
    // expenses.bank_account_id breaks every Expense list/detail/form query).
    ['label' => 'Finance Phase B (bank accounts)', 'migration' => 'migrate_finance_phase_b.php', 'table' => 'bank_accounts', 'column' => null],
    ['label' => 'Finance Phase B (expense/bank account linkage)', 'migration' => 'migrate_finance_phase_b.php', 'table' => 'expenses', 'column' => 'bank_account_id'],
    ['label' => 'Finance Phase B (manual income)', 'migration' => 'migrate_finance_phase_b.php', 'table' => 'manual_income', 'column' => null],
    // Finance & Accounting Phase C - two new tables, no ALTER, so a plain table-existence check
    // per table is unambiguous here (no pre-existing scaffolding to produce a false positive,
    // unlike Phase A's `expenses`). Registered in the same change that added the migration
    // itself, deliberately - the drift this array keeps suffering happens precisely when
    // registration is left as a follow-up task.
    ['label' => 'Finance Phase C (assets)', 'migration' => 'migrate_finance_phase_c.php', 'table' => 'assets', 'column' => null],
    ['label' => 'Finance Phase C (asset attachments)', 'migration' => 'migrate_finance_phase_c.php', 'table' => 'asset_attachments', 'column' => null],
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
