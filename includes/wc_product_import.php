<?php

/**
 * WooCommerce -> Mewmii OS product/variation import (initial + delta catalog sync).
 *
 * Mirrors includes/wc_order_import.php's architecture exactly: one wc_product_import_run()
 * entrypoint (MySQL advisory lock + delta cursor stored in the `settings` table), callable
 * from both cli/wc_product_import.php (cron/manual CLI, now a thin wrapper) and
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
 * counting stock that's already been through normal use.
 *
 * v1 scope: imports products, variations, images, prices, and stock only. Brand/category/
 * collection mapping is deliberately NOT included yet, even though categories.woocommerce_term_id
 * (see database/schema.sql) exists for exactly this purpose - out of scope for this pass. A
 * product's brand_id/category/collection assignment is left completely untouched by this
 * importer, whether the product is newly created or already exists.
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
 * the delta cursor); per_page/page are always added here, never passed in by the caller.
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

// --- Images (unchanged from the original CLI-only importer) -------------------------------

function wc_import_download_to_tmp(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('download failed: ' . $error);
    }
    if ($statusCode < 200 || $statusCode >= 300 || $body === false || $body === '') {
        throw new RuntimeException('download failed: HTTP ' . $statusCode);
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'wcimg_');
    if ($tmpPath === false || file_put_contents($tmpPath, $body) === false) {
        throw new RuntimeException('could not write to a local temp file.');
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

// --- Run entrypoint --------------------------------------------------------------------

/**
 * Batch entrypoint for the manual "Import Products Now" admin action (see
 * modules/integrations/woocommerce.php) and cli/wc_product_import.php (cron/manual CLI) -
 * both call this exact function, unchanged from either caller's point of view. Wraps
 * wc_product_import_run_body() in a MySQL advisory lock, identical reasoning to
 * wc_order_import_run() in includes/wc_order_import.php: GET_LOCK(name, 0) never waits - if
 * another product sync already holds the lock, this fails fast rather than queuing up behind
 * it. Released in both the success and failure paths via `finally`, and MySQL also
 * auto-releases it if this PHP process dies before reaching the `finally` (the lock is scoped
 * to this request's own DB session, and this app never uses persistent PDO connections - see
 * config/database.php) - so a crashed run can never leave the lock stuck.
 *
 * @throws RuntimeException with code WC_PRODUCT_IMPORT_LOCK_BUSY_CODE if another product sync
 * is already running - callers (see cli/wc_product_import.php) should treat this as benign/
 * expected, not a real failure.
 */
function wc_product_import_run(PDO $pdo, bool $dryRun = false): array
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
        return wc_product_import_run_body($pdo, $dryRun);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([WC_PRODUCT_IMPORT_LOCK_NAME]);
    }
}

/**
 * The actual delta-sync logic, only ever called by wc_product_import_run() above, which
 * holds the advisory lock for the entire duration of this function. Fetches /products with
 * modified_after set to the stored cursor (omitted entirely on the very first run, which
 * fetches the full catalog up to the page ceiling). Per-product processing (upsert, images,
 * opening stock, variations+their attributes) is completely unchanged from the original
 * CLI-only importer - see wc_import_upsert_product()/wc_import_upsert_variation()/etc. above -
 * each product is still imported inside its own transaction so one bad product never rolls
 * back the rest of the run, and a product with no SKU is still skipped (not logged to
 * sync_logs, only counted - matching wc_order_import_single()'s "skipped means no log row"
 * convention).
 *
 * The delta cursor only advances if the /products walk fully completed (reached a page with
 * fewer than WC_PRODUCT_IMPORT_PAGE_SIZE results) AND this was not a dry run. A fetch failure
 * partway through, or hitting the page-count safety ceiling, leaves the cursor untouched - the
 * next run simply re-covers the same ground. Already-imported products/variations in that
 * re-covered range are harmless no-op updates (matched via woocommerce_product_id/
 * woocommerce_variation_id, see wc_import_upsert_product()/wc_import_upsert_variation()), so
 * this is always safe to retry, never duplicates.
 *
 * $dryRun makes every write a no-op - not just the product/variation/image/inventory writes
 * the original CLI script already gated, but also the new sync_logs rows and the settings
 * cursor/summary writes added here. A dry run still queries WooCommerce for real (per_page
 * paging against the real API, respecting the stored cursor) so it accurately previews what
 * the next real run would do, it just never persists anything.
 */
function wc_product_import_run_body(PDO $pdo, bool $dryRun): array
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

    foreach ($wcProducts as $wcProduct) {
        $sku = trim((string) ($wcProduct['sku'] ?? ''));

        if ($sku === '') {
            $stats['products_skipped']++;
            continue;
        }

        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        try {
            $productResult = wc_import_upsert_product($pdo, $wcProduct, $dryRun);
            $localProductId = $productResult['id'];
            $stats[$productResult['created'] ? 'products_created' : 'products_updated']++;

            if (!$dryRun && $localProductId > 0) {
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
                            $stats['images_failed']++;
                        }
                    }
                }

                $isVariableProduct = ($wcProduct['type'] ?? 'simple') === 'variable';
                if (!$isVariableProduct) {
                    $stockManaged = !empty($wcProduct['manage_stock']);
                    $stockQuantity = (int) ($wcProduct['stock_quantity'] ?? 0);

                    if ($stockManaged && $stockQuantity > 0) {
                        try {
                            inventory_import_opening_stock($pdo, $localProductId, null, $stockQuantity, 'Imported from WooCommerce initial sync');
                            $stats['opening_stock_set']++;
                        } catch (RuntimeException $e) {
                            // Already has ledger history (typically a re-run) - expected, not an error.
                            $stats['opening_stock_skipped']++;
                        }
                    }
                }
            }

            if (($wcProduct['type'] ?? 'simple') === 'variable' && (int) ($wcProduct['id'] ?? 0) > 0) {
                $variationsFetch = wc_import_fetch_all('products/' . (int) $wcProduct['id'] . '/variations', [], $noopPageCallback);
                $wcVariations = $variationsFetch['items'];

                foreach ($wcVariations as $wcVariation) {
                    $variationSku = trim((string) ($wcVariation['sku'] ?? ''));
                    if ($variationSku === '') {
                        continue;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    $variationResult = wc_import_upsert_variation($pdo, $localProductId, $wcVariation);
                    $localVariationId = $variationResult['id'];
                    $stats[$variationResult['created'] ? 'variations_created' : 'variations_updated']++;

                    $wcAttributes = $wcVariation['attributes'] ?? [];
                    if (is_array($wcAttributes) && $wcAttributes !== []) {
                        wc_import_sync_variation_attributes($pdo, $localProductId, $localVariationId, $wcAttributes);
                    }

                    $variationImage = $wcVariation['image'] ?? null;
                    $variationImageUrl = is_array($variationImage) ? trim((string) ($variationImage['src'] ?? '')) : '';
                    if ($variationImageUrl !== '') {
                        try {
                            if (wc_import_set_variation_image_if_missing($pdo, $localProductId, $localVariationId, $variationImageUrl)) {
                                $stats['images_downloaded']++;
                            }
                        } catch (Throwable $e) {
                            $stats['images_failed']++;
                        }
                    }

                    $variationStockManaged = !empty($wcVariation['manage_stock']);
                    $variationStockQuantity = (int) ($wcVariation['stock_quantity'] ?? 0);
                    if ($variationStockManaged && $variationStockQuantity > 0) {
                        try {
                            inventory_import_opening_stock($pdo, $localProductId, $localVariationId, $variationStockQuantity, 'Imported from WooCommerce initial sync');
                            $stats['opening_stock_set']++;
                        } catch (RuntimeException $e) {
                            $stats['opening_stock_skipped']++;
                        }
                    }
                }
            }

            if (!$dryRun) {
                $pdo->commit();
                sync_log_success($pdo, WC_PRODUCT_IMPORT_SYNC_TYPE, $localProductId);
            }
        } catch (Throwable $e) {
            if (!$dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $stats['products_failed']++;

            if (!$dryRun) {
                sync_log_failure($pdo, WC_PRODUCT_IMPORT_SYNC_TYPE, "Product {$sku}: " . $e->getMessage());
            }
        }
    }

    if ($fetchResult['fully_completed'] && !$dryRun) {
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
