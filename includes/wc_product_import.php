<?php

/**
 * WooCommerce -> Mewmii OS product/variation import (initial + delta catalog sync).
 *
 * Mirrors includes/wc_order_import.php's architecture: one wc_product_import_run()
 * entrypoint (MySQL advisory lock + delta cursor stored in the `settings` table), callable
 * from both cli/wc_product_import.php (cron/manual CLI, a thin wrapper) and
 * modules/integrations/woocommerce.php (the "Import Products Now" button) - both call this
 * exact function, unchanged from either caller's point of view.
 *
 * Idempotent by design: products are matched by woocommerce_product_id first, falling back
 * to SKU, and updated in place rather than duplicated - safe to re-run. An update only ever
 * touches the columns this import owns (name/sku/pricing/status/catalog_type/
 * woocommerce_product_id); product_cost, MOQ, brand/category/supplier, description, and
 * every other staff-authored field are never overwritten by a re-run. Main/gallery/variation
 * images and Opening Stock are likewise only ever set once (see the "if missing" image
 * helpers and inventory_import_opening_stock()'s own history check below) - re-running this
 * never re-downloads an image staff may have replaced, or double-counts stock.
 *
 * Inventory Ledger Compliance: stock is never written directly to mewmii_inventory. Every
 * unit's opening stock goes through inventory_import_opening_stock() (includes/inventory.php),
 * the same function modules/inventory/import_opening_stock.php's CSV importer uses - it logs
 * an inventory_transactions row (transaction_type = 'opening_stock') and updates
 * mewmii_inventory.available_quantity in the same call, and refuses outright if that unit
 * already has ANY transaction history, which is exactly what keeps re-runs from double-
 * counting stock that's already been through normal use. See
 * wc_product_import_set_opening_stock() below for how this safety check stays intact even
 * with per-write-unit transactions.
 *
 * v1 scope: imports products, variations, images, prices, and stock only. Brand/category/
 * collection mapping is deliberately NOT included yet, even though categories.woocommerce_term_id
 * (see database/schema.sql) exists for exactly this purpose - out of scope for this pass. A
 * product's brand_id/category/collection assignment is left completely untouched by this
 * importer, whether the product is newly created or already exists.
 *
 * Transaction boundaries (MySQL error 2006 "server has gone away" fix): every write is
 * wrapped in its own short, single-purpose transaction (product upsert; each opening-stock
 * call; each variation upsert+attribute-sync) - NONE of them ever span a network call.
 * Downloading and WebP-converting an image happens with no transaction open at all; the
 * resulting INSERT into product_images is then a single already-atomic statement. Previously
 * one transaction wrapped an entire product - every image download AND the variations API
 * fetch - leaving the MySQL connection idle-but-in-a-transaction for however long that
 * network I/O took. On hosts with a low wait_timeout (common on shared hosting - see
 * cli/wc_order_sync.php's docblock for the same "Hostinger" context), MySQL would kill that
 * idle connection mid-transaction; the next query on it then fails with error 2006. See
 * wc_product_import_is_connection_lost()/wc_product_import_reconnect() for the other half of
 * this fix: automatic recovery if it still happens (a slow image host, a network blip, etc.).
 */

require_once __DIR__ . '/wc_client.php';
require_once __DIR__ . '/sync_log.php';

const WC_PRODUCT_IMPORT_SYNC_TYPE = 'woocommerce_product_import';

// Reuses the existing, previously-unused generic `settings` key-value table - same pattern as
// WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT/_LAST_RUN_SUMMARY in includes/wc_order_import.php.
// LAST_SYNCED_AT is the delta cursor (only ever advanced after a full run succeeds - see
// wc_product_import_run_body()); LAST_RUN_SUMMARY is display-only stats for
// modules/integrations/woocommerce.php, updated after every run attempt regardless of outcome.
const WC_PRODUCT_IMPORT_SETTING_LAST_SYNCED_AT = 'wc_product_import_last_synced_at';
const WC_PRODUCT_IMPORT_SETTING_LAST_RUN_SUMMARY = 'wc_product_import_last_run_summary';

// Products per WooCommerce REST page, and a hard ceiling on how many pages one run will walk -
// same reasoning as WC_ORDER_IMPORT_PAGE_SIZE/_MAX_PAGES: protects shared hosting from an
// unbounded request chain on the very first run (no stored cursor yet -> fetches the full
// catalog) or an unexpectedly large backlog. Hitting the ceiling does NOT advance the cursor
// (see wc_product_import_run_body()), so a re-run simply continues instead of silently losing
// whatever was past the ceiling. 100/page matches this importer's original (CLI-only) page size.
const WC_PRODUCT_IMPORT_PAGE_SIZE = 100;
const WC_PRODUCT_IMPORT_MAX_PAGES = 25;

// Cap on how many products are FULLY processed (upsert + images + stock + variations) in one
// run - distinct from WC_PRODUCT_IMPORT_MAX_PAGES, which only bounds how many pages of the
// lightweight /products LISTING are fetched. Bounds total run duration (and therefore how
// long the web button's single HTTP request runs, subject to the host's PHP execution-time
// limit, independent of MySQL) regardless of how many products WooCommerce reports. Same
// "don't advance the cursor if we stopped early" treatment as the page ceiling - see
// wc_product_import_run_body().
const WC_PRODUCT_IMPORT_BATCH_SIZE = 50;

// MySQL advisory lock name - distinct from WC_ORDER_IMPORT_LOCK_NAME (includes/wc_order_import.php)
// so an order sync and a product sync can run concurrently without contending for the same lock;
// this only prevents two product-import runs (cron + the manual button, or two overlapping cron
// ticks) from running against the same products at once.
const WC_PRODUCT_IMPORT_LOCK_NAME = 'mewmii_wc_product_import';

// Distinct RuntimeException code for "another product sync is already running" - lets callers
// (cli/wc_product_import.php) tell this benign, expected condition apart from a real failure
// without string-matching the message. Distinct from WC_ORDER_IMPORT_LOCK_BUSY_CODE.
const WC_PRODUCT_IMPORT_LOCK_BUSY_CODE = 42302;

/**
 * Thin key-value helpers over the `settings` table - identical pattern to
 * wc_order_import_get_setting()/wc_order_import_set_setting() in includes/wc_order_import.php.
 */
function wc_product_import_get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : null;
}

function wc_product_import_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('
        INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ')->execute([$key, $value]);
}

// --- Small mapping/parsing helpers (unchanged from the original CLI-only importer) --------

function wc_import_map_product_status(string $wcStatus): string
{
    switch ($wcStatus) {
        case 'publish':
            return 'active';
        case 'private':
            return 'hidden';
        default: // draft, pending, trash, future, ...
            return 'draft';
    }
}

function wc_import_map_variation_status(string $wcStatus): string
{
    return $wcStatus === 'publish' ? 'active' : 'draft';
}

function wc_import_price_or_null($value): ?float
{
    $value = trim((string) ($value ?? ''));

    return $value === '' ? null : (float) $value;
}

function wc_import_price_or_zero($value): float
{
    $value = trim((string) ($value ?? ''));

    return $value === '' ? 0.0 : (float) $value;
}

/**
 * Pages through a WooCommerce list endpoint until an empty page comes back or
 * WC_PRODUCT_IMPORT_MAX_PAGES is hit, merging every page into one array. wc_client_get()
 * (includes/wc_client.php) already handles auth, transient-failure retry, and error decoding -
 * this only adds the paging loop on top, shared by both the /products and
 * /products/<id>/variations endpoints. $query carries extra filters (e.g. modified_after for
 * the delta cursor); per_page/page are always added here, never passed in by the caller. Pure
 * network I/O - never called with a transaction open (see wc_product_import_run_body()).
 *
 * fully_completed is true only when the walk ended because a page came back with fewer than
 * WC_PRODUCT_IMPORT_PAGE_SIZE items (i.e. there was nothing left to fetch) - false if the
 * ceiling was hit first. Only the top-level /products caller in wc_product_import_run_body()
 * actually uses this flag (to decide whether the delta cursor may advance); the nested
 * per-product variations fetch ignores it, since variations have no cursor of their own and
 * are always walked in full for every product this run touches - unchanged behavior.
 *
 * @return array{items: array, fully_completed: bool}
 */
function wc_import_fetch_all(string $endpoint, array $query, callable $onPage): array
{
    $all = [];
    $page = 1;
    $fullyCompleted = false;

    while (true) {
        if ($page > WC_PRODUCT_IMPORT_MAX_PAGES) {
            break;
        }

        $items = wc_client_get($endpoint, array_merge($query, ['per_page' => WC_PRODUCT_IMPORT_PAGE_SIZE, 'page' => $page]));
        if (!is_array($items) || $items === []) {
            $fullyCompleted = true;
            break;
        }

        $all = array_merge($all, $items);
        $onPage($page, count($items));

        if (count($items) < WC_PRODUCT_IMPORT_PAGE_SIZE) {
            $fullyCompleted = true;
            break;
        }

        $page++;
    }

    return ['items' => $all, 'fully_completed' => $fullyCompleted];
}

// --- Product / variation upsert (unchanged from the original CLI-only importer) -----------

/**
 * @return array{id: int, created: bool}
 */
function wc_import_upsert_product(PDO $pdo, array $wcProduct, bool $dryRun): array
{
    $sku = trim((string) ($wcProduct['sku'] ?? ''));
    if ($sku === '') {
        throw new RuntimeException('No SKU (products.sku is required and unique in Mewmii OS).');
    }

    $wcId = (int) ($wcProduct['id'] ?? 0);

    $existing = null;
    if ($wcId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE woocommerce_product_id = ? LIMIT 1');
        $stmt->execute([$wcId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $existing = (int) $id;
        }
    }
    if ($existing === null) {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $existing = (int) $id;
        }
    }

    if ($dryRun) {
        return ['id' => $existing ?? 0, 'created' => $existing === null];
    }

    $name = trim((string) ($wcProduct['name'] ?? ''));
    $catalogType = ($wcProduct['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple';
    $status = wc_import_map_product_status((string) ($wcProduct['status'] ?? 'draft'));
    $sellingPrice = wc_import_price_or_zero($wcProduct['regular_price'] ?? ($wcProduct['price'] ?? 0));
    $salePrice = wc_import_price_or_null($wcProduct['sale_price'] ?? null);

    if ($existing !== null) {
        if ($catalogType === 'simple') {
            // Never downgrade an existing variable product back to simple while it still has
            // variations - mirrors the same protection modules/products/_form.php's Product
            // Type radio enforces in the UI (disabled once variations exist).
            $variationCountStmt = $pdo->prepare('SELECT COUNT(*) FROM product_variations WHERE product_id = ?');
            $variationCountStmt->execute([$existing]);
            if ((int) $variationCountStmt->fetchColumn() > 0) {
                $catalogType = 'variable';
            }
        }

        // Deliberately narrow: only the fields this import owns are ever touched on update.
        // product_cost, MOQ, brand/category/supplier, description, availability_override,
        // and every other staff-authored field are left completely alone, so re-running this
        // import can never clobber a manual edit made inside Mewmii OS itself.
        $pdo->prepare('
            UPDATE products
            SET name = ?, sku = ?, catalog_type = ?, selling_price = ?, sale_enabled = ?, sale_price = ?,
                status = ?, woocommerce_product_id = ?, published_to_woocommerce = 1, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $name, $sku, $catalogType, $sellingPrice, $salePrice !== null ? 1 : 0, $salePrice,
            $status, $wcId > 0 ? $wcId : null, $existing,
        ]);

        return ['id' => $existing, 'created' => false];
    }

    // Only used on first insert - a re-run never overwrites a description staff may have
    // since rewritten (see the docblock above). WooCommerce descriptions are HTML;
    // short_description here is a plain customer-facing summary elsewhere in this app (see
    // modules/products/_form.php), so tags are stripped to keep first-import content usable.
    $description = trim((string) ($wcProduct['description'] ?? ''));
    $shortDescription = trim(strip_tags((string) ($wcProduct['short_description'] ?? '')));

    $stmt = $pdo->prepare('
        INSERT INTO products
            (woocommerce_product_id, sku, name, short_description, description, catalog_type,
             selling_price, sale_enabled, sale_price, status, published_to_woocommerce)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ');
    $stmt->execute([
        $wcId > 0 ? $wcId : null,
        $sku,
        $name,
        $shortDescription !== '' ? mb_substr($shortDescription, 0, 500) : null,
        $description !== '' ? $description : null,
        $catalogType,
        $sellingPrice,
        $salePrice !== null ? 1 : 0,
        $salePrice,
        $status,
    ]);

    return ['id' => (int) $pdo->lastInsertId(), 'created' => true];
}

/**
 * @return array{id: int, created: bool}
 */
function wc_import_upsert_variation(PDO $pdo, int $localProductId, array $wcVariation): array
{
    $sku = trim((string) ($wcVariation['sku'] ?? ''));
    if ($sku === '') {
        throw new RuntimeException('No SKU.');
    }

    $wcVariationId = (int) ($wcVariation['id'] ?? 0);
    $status = wc_import_map_variation_status((string) ($wcVariation['status'] ?? 'draft'));
    // WooCommerce always carries its own price per variation - there is no "inherit parent
    // price" concept on that side (see wc_client_sync_variable_product_from_mewmii()'s
    // docblock for the same fact in the opposite sync direction) - so an imported variation's
    // price is always its own (price_mode='custom'), never price_mode='inherit'.
    $customPrice = wc_import_price_or_null($wcVariation['regular_price'] ?? null);
    $weightRaw = trim((string) ($wcVariation['weight'] ?? ''));
    $weight = ($weightRaw !== '' && is_numeric($weightRaw)) ? round((float) $weightRaw, 3) : null;

    $existing = null;
    if ($wcVariationId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM product_variations WHERE woocommerce_variation_id = ? LIMIT 1');
        $stmt->execute([$wcVariationId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $existing = (int) $id;
        }
    }
    if ($existing === null) {
        $stmt = $pdo->prepare('SELECT id FROM product_variations WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $existing = (int) $id;
        }
    }

    if ($existing !== null) {
        $pdo->prepare('
            UPDATE product_variations
            SET sku = ?, weight = ?, price_mode = ?, custom_price = ?, status = ?, woocommerce_variation_id = ?,
                is_system_generated = 0, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $sku, $weight, $customPrice !== null ? 'custom' : 'inherit', $customPrice, $status,
            $wcVariationId > 0 ? $wcVariationId : null, $existing,
        ]);

        return ['id' => $existing, 'created' => false];
    }

    $stmt = $pdo->prepare('
        INSERT INTO product_variations
            (product_id, sku, weight, price_mode, custom_price, status, is_system_generated, woocommerce_variation_id)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?)
    ');
    $stmt->execute([
        $localProductId, $sku, $weight, $customPrice !== null ? 'custom' : 'inherit', $customPrice, $status,
        $wcVariationId > 0 ? $wcVariationId : null,
    ]);

    $variationId = (int) $pdo->lastInsertId();

    // Every variation needs its own mewmii_inventory row before any ledger entry can
    // reference it (see inventory_get_or_create_row()) - variation_generate_combinations()
    // creates this at generation time; a WooCommerce-sourced variation has no such generation
    // step, so it's created explicitly here instead. INSERT IGNORE: harmless no-op if a
    // previous run already created it (e.g. a partially-failed retry).
    $pdo->prepare('INSERT IGNORE INTO mewmii_inventory (product_id, variation_id) VALUES (?, ?)')
        ->execute([$localProductId, $variationId]);

    return ['id' => $variationId, 'created' => true];
}

/**
 * Maps a WooCommerce variation's attributes (e.g. [{name: "Character", option: "Hello
 * Kitty"}]) onto this app's relational model: product_attributes/product_attribute_values
 * (global, get-or-create by name/value - same helpers the "+ Add Attribute"/"+ Add Value"
 * modals use), product_attribute_assignments/product_attribute_assignment_values (which
 * attributes/values are available on the PARENT product), and finally
 * product_variation_attribute_values (this exact variation's combination). Deliberately
 * additive only - never removes an existing assignment/link - so re-running this import can
 * never delete a relationship that might still be in use.
 */
function wc_import_sync_variation_attributes(PDO $pdo, int $localProductId, int $localVariationId, array $wcAttributes): void
{
    foreach ($wcAttributes as $wcAttribute) {
        if (!is_array($wcAttribute)) {
            continue;
        }

        $attributeName = trim((string) ($wcAttribute['name'] ?? ''));
        $optionValue = trim((string) ($wcAttribute['option'] ?? ''));
        if ($attributeName === '' || $optionValue === '') {
            continue;
        }

        $attributeId = catalog_get_or_create_attribute($pdo, $attributeName);
        $valueId = catalog_get_or_create_attribute_value($pdo, $attributeId, $optionValue);

        $assignmentStmt = $pdo->prepare('SELECT id FROM product_attribute_assignments WHERE product_id = ? AND attribute_id = ?');
        $assignmentStmt->execute([$localProductId, $attributeId]);
        $assignmentId = $assignmentStmt->fetchColumn();

        if ($assignmentId === false) {
            $pdo->prepare('INSERT INTO product_attribute_assignments (product_id, attribute_id, is_variation_attribute) VALUES (?, ?, 1)')
                ->execute([$localProductId, $attributeId]);
            $assignmentId = (int) $pdo->lastInsertId();
        } else {
            $assignmentId = (int) $assignmentId;
        }

        $pdo->prepare('INSERT IGNORE INTO product_attribute_assignment_values (assignment_id, attribute_value_id) VALUES (?, ?)')
            ->execute([$assignmentId, $valueId]);

        $pdo->prepare('
            INSERT INTO product_variation_attribute_values (variation_id, attribute_id, attribute_value_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE attribute_value_id = VALUES(attribute_value_id)
        ')->execute([$localVariationId, $attributeId, $valueId]);
    }
}

// --- Images ---------------------------------------------------------------------------------

/**
 * Streams straight to a temp file via CURLOPT_FILE rather than buffering the whole response
 * in memory (the original CLI-only importer used CURLOPT_RETURNTRANSFER + file_put_contents,
 * holding an entire image as a PHP string before writing it out) - avoids doubling memory use
 * on top of whatever GD then needs to decode it in image_upload_process_from_path(). Size is
 * still enforced the same way as before, just after the fact: image_upload_process_from_path()
 * already rejects anything over IMAGE_UPLOAD_MAX_BYTES once it's on disk (see
 * includes/image_upload.php) - unchanged.
 */
function wc_import_download_to_tmp(string $url): string
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'wcimg_');
    if ($tmpPath === false) {
        throw new RuntimeException('could not create a local temp file.');
    }

    $fileHandle = fopen($tmpPath, 'wb');
    if ($fileHandle === false) {
        @unlink($tmpPath);
        throw new RuntimeException('could not open a local temp file for writing.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fileHandle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fileHandle);

    if ($errno !== 0) {
        @unlink($tmpPath);
        throw new RuntimeException('download failed: ' . $error);
    }
    if ($statusCode < 200 || $statusCode >= 300 || filesize($tmpPath) === 0) {
        @unlink($tmpPath);
        throw new RuntimeException('download failed: HTTP ' . $statusCode);
    }

    return $tmpPath;
}

/**
 * Main/gallery/variation images are only ever set the FIRST time a unit has none - a
 * re-import never re-downloads or replaces an image staff may have since changed inside
 * Mewmii OS. product_images has no column identifying which WooCommerce image a row came
 * from, so there's no way to detect "this is the same picture, just re-synced" versus "staff
 * uploaded a different one" - treating any existing image as authoritative and untouchable is
 * the safe direction to err in, matching this whole importer's "never clobber a manual edit"
 * rule for every other field.
 *
 * Deliberately called with NO transaction open (see wc_product_import_run_body()/
 * wc_product_import_process_one_product()) - the download+WebP-conversion happens first with
 * no DB interaction at all, then this function's own INSERT is a single, already-atomic
 * statement. This is the actual MySQL-error-2006 fix: a transaction must never sit open for
 * the duration of a network download.
 */
function wc_import_set_main_image_if_missing(PDO $pdo, int $productId, string $imageUrl): bool
{
    if (product_image_get_main($pdo, $productId) !== null) {
        return false;
    }

    $tmpPath = wc_import_download_to_tmp($imageUrl);
    try {
        $relativePath = image_upload_process_from_path($tmpPath, 'products');
    } finally {
        @unlink($tmpPath);
    }

    $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, NULL, 'main', ?, 0)")
        ->execute([$productId, $relativePath]);

    return true;
}

function wc_import_add_gallery_images_if_empty(PDO $pdo, int $productId, array $imageUrls): int
{
    if ($imageUrls === [] || product_image_list_gallery($pdo, $productId) !== []) {
        return 0;
    }

    $added = 0;
    $insertStmt = $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, NULL, 'gallery', ?, ?)");

    foreach ($imageUrls as $sortOrder => $imageUrl) {
        $tmpPath = wc_import_download_to_tmp($imageUrl);
        try {
            $relativePath = image_upload_process_from_path($tmpPath, 'products');
        } finally {
            @unlink($tmpPath);
        }

        $insertStmt->execute([$productId, $relativePath, $sortOrder]);
        $added++;
    }

    return $added;
}

function wc_import_set_variation_image_if_missing(PDO $pdo, int $productId, int $variationId, string $imageUrl): bool
{
    if (product_image_get_variation($pdo, $variationId) !== null) {
        return false;
    }

    $tmpPath = wc_import_download_to_tmp($imageUrl);
    try {
        $relativePath = image_upload_process_from_path($tmpPath, 'variations');
    } finally {
        @unlink($tmpPath);
    }

    $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, ?, 'variation', ?, 0)")
        ->execute([$productId, $variationId, $relativePath]);

    return true;
}

// --- Connection-loss detection and recovery (MySQL error 2006 fix) ------------------------

/**
 * Whether $e represents a dropped MySQL connection (error 2006 "server has gone away", 2013
 * "lost connection to MySQL server during query") rather than a data/logic problem with this
 * specific product - i.e. whether reconnecting and continuing makes sense, as opposed to a
 * real error a fresh connection wouldn't fix. Checked by PDOException::errorInfo's driver code
 * first (the reliable signal), falling back to a message match in case the error surfaced
 * through a code path (e.g. during beginTransaction()/commit() rather than a plain query)
 * where the driver code isn't populated the same way.
 */
function wc_product_import_is_connection_lost(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }

    $driverCode = is_array($e->errorInfo ?? null) ? ($e->errorInfo[1] ?? null) : null;
    if (in_array($driverCode, [2006, 2013], true)) {
        return true;
    }

    $message = $e->getMessage();

    return strpos($message, 'server has gone away') !== false
        || strpos($message, 'Lost connection') !== false;
}

/**
 * Rebuilds a fresh PDO connection using the exact same connection parameters as
 * config/database.php, for recovering from wc_product_import_is_connection_lost(). Also
 * updates the global $pdo that app_db() (includes/bootstrap.php) reads from, so anything else
 * that happens to run later in the same request transparently picks up the fresh connection
 * too, exactly as if the request had started with it - this import run is the only thing
 * both current callers (cli/wc_product_import.php, modules/integrations/woocommerce.php's
 * button) do with $pdo afterward, but keeping the global consistent costs nothing and avoids
 * a subtle trap for any future caller that reads app_db() after calling this.
 */
function wc_product_import_reconnect(): PDO
{
    $configPath = __DIR__ . '/../config.php';
    $config = is_file($configPath) ? require $configPath : [];
    $db = $config['db'] ?? [];

    $host = $db['host'] ?? 'localhost';
    $dbname = $db['database'] ?? '';
    $username = $db['username'] ?? '';
    $password = $db['password'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';

    $fresh = new PDO("mysql:host={$host};dbname={$dbname};charset={$charset}", $username, $password);
    $fresh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    global $pdo;
    $pdo = $fresh;

    return $fresh;
}

// --- Opening stock (shared by the simple-product and per-variation cases) -----------------

/**
 * Wraps inventory_import_opening_stock() in its own short, self-contained transaction -
 * deliberately separate from the product/variation upsert transaction and from any image
 * download, so a connection drop during a slow image download can never leave this
 * multi-statement, ledger-writing call half-committed (it checks history, then updates
 * mewmii_inventory, then inserts an inventory_transactions row - all three must succeed or
 * none may). Used for both a simple product's own stock and each variation's stock -
 * deduplicates what was two near-identical inline blocks in the original importer.
 *
 * The "already has history" RuntimeException (see inventory_import_opening_stock()'s own
 * docblock in includes/inventory.php) is caught and treated as a benign per-unit skip, not a
 * failure - this IS the Inventory Ledger Compliance safety check this importer has always
 * relied on, unchanged: opening stock is refused, never silently reapplied, once a unit has
 * any transaction history. Matched by exact class (not `instanceof`) because PDOException
 * also extends RuntimeException - a dropped connection must never be mistaken for this one
 * specific, deliberate condition and silently swallowed as a "skip".
 */
function wc_product_import_set_opening_stock(PDO $pdo, int $productId, ?int $variationId, array $wcUnit, array &$stats): void
{
    $stockManaged = !empty($wcUnit['manage_stock']);
    $stockQuantity = (int) ($wcUnit['stock_quantity'] ?? 0);

    if (!$stockManaged || $stockQuantity < 1) {
        return;
    }

    $pdo->beginTransaction();

    try {
        inventory_import_opening_stock($pdo, $productId, $variationId, $stockQuantity, 'Imported from WooCommerce initial sync');
        $pdo->commit();
        $stats['opening_stock_set']++;
    } catch (Throwable $e) {
        $pdo->rollBack();

        if (get_class($e) === RuntimeException::class) {
            // Already has ledger history (typically a re-run) - expected, not an error.
            $stats['opening_stock_skipped']++;
            return;
        }

        throw $e;
    }
}

// --- Per-product processing ----------------------------------------------------------------

/**
 * Processes one WooCommerce product end to end: upsert, images, opening stock, and (if
 * variable) every variation. Every DB write happens in its own short transaction, and NONE of
 * them are ever open while a network call (image download, or the variations API fetch) is in
 * flight - see the file-level docblock. A Throwable propagating out of this function means
 * this product's remaining work stops here (matches the original importer's per-product
 * all-stop-on-first-error granularity), but whatever already committed for this product
 * (the product row itself, earlier variations, earlier images) stays committed rather than
 * being rolled back - a deliberate improvement over the original single-giant-transaction
 * design, where a late failure (e.g. one bad variation) would roll back even the already-
 * succeeded product upsert, silently making the returned $stats count a "created" product
 * that the rollback had actually just erased.
 *
 * @return int the local product id (0 in dry-run when the product doesn't exist locally yet)
 */
function wc_product_import_process_one_product(PDO $pdo, array $wcProduct, bool $dryRun, array &$stats): int
{
    if (!$dryRun) {
        $pdo->beginTransaction();
    }
    $productResult = wc_import_upsert_product($pdo, $wcProduct, $dryRun);
    if (!$dryRun) {
        $pdo->commit();
    }

    $localProductId = $productResult['id'];
    $stats[$productResult['created'] ? 'products_created' : 'products_updated']++;

    if ($dryRun) {
        // Dry run never touches images/stock/variations beyond the product-level preview -
        // unchanged from the original CLI-only importer's behavior.
        return $localProductId;
    }

    if ($localProductId > 0) {
        $images = $wcProduct['images'] ?? [];
        if (is_array($images) && $images !== []) {
            $mainImage = array_shift($images);
            $mainUrl = is_array($mainImage) ? trim((string) ($mainImage['src'] ?? '')) : '';

            if ($mainUrl !== '') {
                try {
                    if (wc_import_set_main_image_if_missing($pdo, $localProductId, $mainUrl)) {
                        $stats['images_downloaded']++;
                    }
                } catch (Throwable $e) {
                    if (wc_product_import_is_connection_lost($e)) {
                        throw $e;
                    }
                    $stats['images_failed']++;
                }
            }

            $galleryUrls = [];
            foreach ($images as $galleryImage) {
                if (is_array($galleryImage) && !empty($galleryImage['src'])) {
                    $galleryUrls[] = (string) $galleryImage['src'];
                }
            }

            if ($galleryUrls !== []) {
                try {
                    $stats['images_downloaded'] += wc_import_add_gallery_images_if_empty($pdo, $localProductId, $galleryUrls);
                } catch (Throwable $e) {
                    if (wc_product_import_is_connection_lost($e)) {
                        throw $e;
                    }
                    $stats['images_failed']++;
                }
            }
        }

        $isVariableProduct = ($wcProduct['type'] ?? 'simple') === 'variable';
        if (!$isVariableProduct) {
            wc_product_import_set_opening_stock($pdo, $localProductId, null, $wcProduct, $stats);
        }
    }

    if (($wcProduct['type'] ?? 'simple') === 'variable' && (int) ($wcProduct['id'] ?? 0) > 0) {
        // Network fetch - deliberately outside any transaction, same as every image download.
        $variationsFetch = wc_import_fetch_all('products/' . (int) $wcProduct['id'] . '/variations', [], static function (int $page, int $count): void {});
        $wcVariations = $variationsFetch['items'];

        foreach ($wcVariations as $wcVariation) {
            $variationSku = trim((string) ($wcVariation['sku'] ?? ''));
            if ($variationSku === '') {
                continue;
            }

            $pdo->beginTransaction();
            $variationResult = wc_import_upsert_variation($pdo, $localProductId, $wcVariation);
            $localVariationId = $variationResult['id'];

            $wcAttributes = $wcVariation['attributes'] ?? [];
            if (is_array($wcAttributes) && $wcAttributes !== []) {
                wc_import_sync_variation_attributes($pdo, $localProductId, $localVariationId, $wcAttributes);
            }

            $pdo->commit();
            $stats[$variationResult['created'] ? 'variations_created' : 'variations_updated']++;

            $variationImage = $wcVariation['image'] ?? null;
            $variationImageUrl = is_array($variationImage) ? trim((string) ($variationImage['src'] ?? '')) : '';
            if ($variationImageUrl !== '') {
                try {
                    if (wc_import_set_variation_image_if_missing($pdo, $localProductId, $localVariationId, $variationImageUrl)) {
                        $stats['images_downloaded']++;
                    }
                } catch (Throwable $e) {
                    if (wc_product_import_is_connection_lost($e)) {
                        throw $e;
                    }
                    $stats['images_failed']++;
                }
            }

            wc_product_import_set_opening_stock($pdo, $localProductId, $localVariationId, $wcVariation, $stats);
        }
    }

    return $localProductId;
}

// --- Run entrypoint --------------------------------------------------------------------

/**
 * Batch entrypoint for the manual "Import Products Now" admin action (see
 * modules/integrations/woocommerce.php) and cli/wc_product_import.php (cron/manual CLI) -
 * both call this exact function, unchanged from either caller's point of view. Wraps
 * wc_product_import_run_body() in a MySQL advisory lock, identical reasoning to
 * wc_order_import_run() in includes/wc_order_import.php: GET_LOCK(name, 0) never waits - if
 * another product sync already holds the lock, this fails fast rather than queuing up behind
 * it. Released via `finally` on a best-effort basis: if wc_product_import_run_body() had to
 * reconnect mid-run (see wc_product_import_reconnect()), the ORIGINAL connection this function
 * received is already dead, so releasing the lock on it here will itself fail - swallowed
 * deliberately, since MySQL already auto-released that connection's advisory lock the moment
 * it dropped, same as it would on any crashed process (the lock is scoped to a DB session, and
 * this app never uses persistent PDO connections - see config/database.php).
 *
 * @throws RuntimeException with code WC_PRODUCT_IMPORT_LOCK_BUSY_CODE if another product sync
 * is already running - callers (see cli/wc_product_import.php) should treat this as benign/
 * expected, not a real failure.
 */
function wc_product_import_run(PDO $pdo, bool $dryRun = false, int $batchSize = WC_PRODUCT_IMPORT_BATCH_SIZE): array
{
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockStmt->execute([WC_PRODUCT_IMPORT_LOCK_NAME]);

    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException(
            'Another WooCommerce product sync is already in progress - skipped this run.',
            WC_PRODUCT_IMPORT_LOCK_BUSY_CODE
        );
    }

    try {
        return wc_product_import_run_body($pdo, $dryRun, $batchSize);
    } finally {
        try {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([WC_PRODUCT_IMPORT_LOCK_NAME]);
        } catch (Throwable $e) {
            // See docblock above - expected if this connection was replaced mid-run.
        }
    }
}

/**
 * The actual delta-sync logic, only ever called by wc_product_import_run() above, which
 * holds the advisory lock for the entire duration of this function (on whichever connection
 * currently holds it - see the reconnect handling below). Fetches /products with
 * modified_after set to the stored cursor (omitted entirely on the very first run, which
 * fetches the full catalog up to the page ceiling). Per-product processing (upsert, images,
 * opening stock, variations+their attributes) lives in
 * wc_product_import_process_one_product() - unchanged business logic from the original
 * CLI-only importer, restructured only in WHEN each write commits relative to network I/O
 * (see the file-level docblock). A product with no SKU is still skipped (not logged to
 * sync_logs, only counted - matching wc_order_import_single()'s "skipped means no log row"
 * convention).
 *
 * The delta cursor only advances if the /products walk fully completed (reached a page with
 * fewer than WC_PRODUCT_IMPORT_PAGE_SIZE results), the batch size limit was not hit, the
 * connection was never lost beyond recovery, AND this was not a dry run. Any of those leaving
 * the cursor untouched means the next run simply re-covers the same ground rather than risk
 * skipping something this run never reached - already-imported products/variations in that
 * re-covered range are harmless no-op updates (matched via woocommerce_product_id/
 * woocommerce_variation_id), so this is always safe to retry, never duplicates.
 *
 * If a product's processing fails with a dropped connection (see
 * wc_product_import_is_connection_lost()), this reconnects (wc_product_import_reconnect())
 * and re-acquires the advisory lock on the fresh connection before continuing - mutual
 * exclusion with any other product-import run must never lapse just because this one
 * reconnected. If either step fails (the reconnect itself, or another run has since claimed
 * the lock), the run stops where it is rather than continue unprotected; whatever already
 * committed for earlier products stays committed, and the cursor is not advanced.
 *
 * $dryRun makes every write a no-op - not just the product/variation/image/inventory writes
 * the original CLI script already gated, but also the new sync_logs rows and the settings
 * cursor/summary writes added here. A dry run still queries WooCommerce for real (per_page
 * paging against the real API, respecting the stored cursor) so it accurately previews what
 * the next real run would do, it just never persists anything.
 */
function wc_product_import_run_body(PDO $pdo, bool $dryRun, int $batchSize = WC_PRODUCT_IMPORT_BATCH_SIZE): array
{
    $stats = [
        'products_created' => 0, 'products_updated' => 0, 'products_skipped' => 0, 'products_failed' => 0,
        'variations_created' => 0, 'variations_updated' => 0,
        'opening_stock_set' => 0, 'opening_stock_skipped' => 0,
        'images_downloaded' => 0, 'images_failed' => 0,
    ];

    // Captured before the first fetch, not after the last one - a product modified while this
    // run is still in progress must be picked up on the NEXT run, never silently skipped
    // because it looked "already covered" by a cursor stamped after the run finished. Same
    // reasoning as wc_order_import_run_body().
    $syncStartedAt = gmdate('Y-m-d\TH:i:s');
    $previousCursor = wc_product_import_get_setting($pdo, WC_PRODUCT_IMPORT_SETTING_LAST_SYNCED_AT);

    $productsQuery = [];
    if ($previousCursor !== null) {
        $productsQuery['modified_after'] = $previousCursor;
        $productsQuery['dates_are_gmt'] = true;
    }

    $noopPageCallback = static function (int $page, int $count): void {};

    try {
        $fetchResult = wc_import_fetch_all('products', $productsQuery, $noopPageCallback);
    } catch (Throwable $e) {
        // A fetch itself failed (connection/API error) - deliberately not advancing the
        // cursor, so the next run re-walks from the old cursor rather than risk skipping a
        // page this run never reached. Mirrors wc_order_import_run_body()'s fetch-failure path.
        if (!$dryRun) {
            sync_log_failure($pdo, WC_PRODUCT_IMPORT_SYNC_TYPE, 'WooCommerce product fetch failed: ' . $e->getMessage());
            wc_product_import_store_run_summary($pdo, $syncStartedAt, $stats);
        }

        return $stats;
    }

    $wcProducts = $fetchResult['items'];
    $fullyCompleted = $fetchResult['fully_completed'];

    if (!$fullyCompleted && !$dryRun) {
        sync_log_write($pdo, WC_PRODUCT_IMPORT_SYNC_TYPE, 'failed', null,
            'Stopped after ' . WC_PRODUCT_IMPORT_MAX_PAGES . ' page(s) (safety limit) - more products may remain. Cursor not advanced; re-run to continue.');
    }

    $processedCount = 0;

    foreach ($wcProducts as $wcProduct) {
        if ($processedCount >= $batchSize) {
            // Hit the per-run processing cap before finishing the fetched list - same
            // "don't advance the cursor" treatment as hitting the page ceiling above: this run
            // did not fully cover the delta window, so the next run must re-cover the same
            // ground rather than silently skip whatever was left.
            $fullyCompleted = false;

            if (!$dryRun) {
                sync_log_write($pdo, WC_PRODUCT_IMPORT_SYNC_TYPE, 'failed', null,
                    'Stopped after ' . $batchSize . ' product(s) (batch size limit) - more products may remain. Cursor not advanced; re-run to continue.');
            }

            break;
        }

        $sku = trim((string) ($wcProduct['sku'] ?? ''));
        if ($sku === '') {
            $stats['products_skipped']++;
            $processedCount++;
            continue;
        }

        try {
            $localProductId = wc_product_import_process_one_product($pdo, $wcProduct, $dryRun, $stats);

            if (!$dryRun) {
                sync_log_success($pdo, WC_PRODUCT_IMPORT_SYNC_TYPE, $localProductId);
            }
        } catch (Throwable $e) {
            if (!$dryRun && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $ignored) {
                    // The connection may already be gone - nothing more to do here, the
                    // reconnect branch below is what actually recovers.
                }
            }

            $stats['products_failed']++;
            $processedCount++;

            // Default: no connection was lost, so $pdo is still the one that just failed and
            // is fine to log with below. Only the connection-lost branch below can change this.
            $canLogWithCurrentPdo = true;
            $stopRun = false;

            if (wc_product_import_is_connection_lost($e)) {
                $canLogWithCurrentPdo = false;

                try {
                    $pdo = wc_product_import_reconnect();
                    $relockStmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
                    $relockStmt->execute([WC_PRODUCT_IMPORT_LOCK_NAME]);
                    $canLogWithCurrentPdo = (int) $relockStmt->fetchColumn() === 1;
                } catch (Throwable $ignored) {
                    $canLogWithCurrentPdo = false;
                }

                if (!$canLogWithCurrentPdo) {
                    // Either the reconnect itself failed, or another product-import run has
                    // since claimed exclusivity in the gap - stop here rather than continue
                    // unprotected, or on a connection that still isn't usable. Whatever
                    // already committed for earlier products in this run stays committed.
                    $fullyCompleted = false;
                    $stopRun = true;
                }
            }

            // Logged in BOTH the ordinary-failure and the recovered-reconnect case - a
            // connection drop mid-product must still leave an audit trail (using whichever
            // $pdo currently works), not just move the count into $stats silently. Skipped
            // only when there's truly no working connection left to log with (the run is
            // stopping anyway in that case).
            if (!$dryRun && $canLogWithCurrentPdo) {
                try {
                    sync_log_failure($pdo, WC_PRODUCT_IMPORT_SYNC_TYPE, "Product {$sku}: " . $e->getMessage());
                } catch (Throwable $ignored) {
                    // Never let a logging failure mask this run's actual result.
                }
            }

            if ($stopRun) {
                break;
            }

            continue;
        }

        $processedCount++;
    }

    if ($fullyCompleted && !$dryRun) {
        wc_product_import_set_setting($pdo, WC_PRODUCT_IMPORT_SETTING_LAST_SYNCED_AT, $syncStartedAt);
    }

    if (!$dryRun) {
        wc_product_import_store_run_summary($pdo, $syncStartedAt, $stats);
    }

    return $stats;
}

/**
 * Run-level summary for modules/integrations/woocommerce.php - "total imported, created,
 * updated, skipped, failed" (products only; variations/images/stock stay in the returned
 * $stats array for the redirect message, matching how wc_order_import_run_body() keeps its
 * own summary shape simple). Mirrors WC_ORDER_IMPORT_SETTING_LAST_RUN_SUMMARY exactly - stored
 * regardless of outcome (even a total fetch failure), so staff can see "did this actually run"
 * separately from "how far does coverage reach" (the cursor).
 */
function wc_product_import_store_run_summary(PDO $pdo, string $ranAt, array $stats): void
{
    $created = $stats['products_created'];
    $updated = $stats['products_updated'];

    wc_product_import_set_setting($pdo, WC_PRODUCT_IMPORT_SETTING_LAST_RUN_SUMMARY, json_encode([
        'ran_at' => $ranAt,
        'imported' => $created + $updated,
        'created' => $created,
        'updated' => $updated,
        'skipped' => $stats['products_skipped'],
        'failed' => $stats['products_failed'],
    ]));
}
