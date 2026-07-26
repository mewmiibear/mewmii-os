<?php

/**
 * WooCommerce REST API client.
 *
 * Mewmii catalog_type maps to WooCommerce product type:
 *   simple   -> WooCommerce simple product
 *   variable -> WooCommerce variable product, with each product_variations row synced
 *               to a WooCommerce product variation.
 */

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/product_variations.php';
require_once __DIR__ . '/sync_log.php';
require_once __DIR__ . '/image_upload.php';

// Reuses the existing, previously-unused generic `settings` key-value table - same pattern as
// WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT (includes/wc_order_import.php) and
// WC_PRODUCT_IMPORT_SETTING_LAST_SYNCED_AT (includes/wc_product_import.php). When enabled,
// saving a product in Mewmii OS (modules/products/create.php/edit.php) pushes just that one
// product to WooCommerce automatically - see wc_client_auto_sync_product(). Off by default
// (wc_client_auto_sync_enabled() treats anything other than the literal string '1' as
// disabled), so installs that only ever use the manual "Sync to WooCommerce" button see no
// behavior change until an admin explicitly turns this on.
const WC_CLIENT_AUTO_SYNC_SETTING_KEY = 'woocommerce_auto_sync_enabled';

function wc_client_get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : null;
}

function wc_client_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('
        INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ')->execute([$key, $value]);
}

function wc_client_auto_sync_enabled(PDO $pdo): bool
{
    return wc_client_get_setting($pdo, WC_CLIENT_AUTO_SYNC_SETTING_KEY) === '1';
}

function wc_client_config(): array
{
    static $config = null;

    if ($config === null) {
        $configPath = __DIR__ . '/../config.php';
        $appConfig = is_file($configPath) ? require $configPath : [];
        $config = $appConfig['woocommerce'] ?? [];
    }

    return $config;
}

function wc_client_is_configured(): bool
{
    $config = wc_client_config();

    return !empty($config['url']) && !empty($config['consumer_key']) && !empty($config['consumer_secret']);
}

/**
 * Low-level request. Throws RuntimeException on transport failure, a non-2xx
 * response, or an unparsable body - callers should catch and log via sync_log.php.
 *
 * Security Hardening Phase 4D: retries exactly once, and only for GET requests, on a
 * transient failure - a curl-level transport error, an HTTP 5xx, or a 429 (rate limited,
 * respecting Retry-After if present). Never retries an auth/permission/invalid-request
 * response (401/403/400/422/...) - retrying those can't succeed and would just double the
 * wait for an error that isn't going away.
 *
 * Retry is deliberately GET-only: wc_client_post()/wc_client_put() (used only by product
 * sync - the order importer and receipt verification never call this function with POST/PUT
 * at all) could have already written something server-side before a transport failure lost
 * the response, and blindly retrying a write risks a duplicate. A GET has no such risk.
 */
function wc_client_request(string $method, string $endpoint, array $data = [], array $query = []): array
{
    if (!wc_client_is_configured()) {
        throw new RuntimeException('WooCommerce API is not configured.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP curl extension is required for WooCommerce API requests.');
    }

    $config = wc_client_config();
    $method = strtoupper($method);

    $url = rtrim($config['url'], '/') . '/wp-json/wc/v3/' . ltrim($endpoint, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $retryable = $method === 'GET';
    $maxAttempts = $retryable ? 2 : 1;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERPWD => $config['consumer_key'] . ':' . $config['consumer_secret'],
            CURLOPT_HEADERFUNCTION => function ($curlHandle, $headerLine) use (&$responseHeaders) {
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($headerLine);
            },
        ]);

        if (in_array($method, ['POST', 'PUT'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $responseBody = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $isTransportFailure = $curlErrno !== 0;
        $isServerError = !$isTransportFailure && $statusCode >= 500 && $statusCode < 600;
        $isRateLimited = !$isTransportFailure && $statusCode === 429;

        $canRetryAgain = $retryable && $attempt < $maxAttempts;
        if ($canRetryAgain && ($isTransportFailure || $isServerError || $isRateLimited)) {
            if ($isRateLimited) {
                // Respect Retry-After if WooCommerce provided one, bounded to a few seconds -
                // this is a single synchronous retry within one request/cron run, not a
                // queue, so an unbounded wait here isn't appropriate even if the header asked
                // for one.
                $retryAfterHeader = (int) ($responseHeaders['retry-after'] ?? 0);
                sleep(min(10, max(1, $retryAfterHeader > 0 ? $retryAfterHeader : 2)));
            } else {
                sleep(1);
            }

            continue;
        }

        break;
    }

    if ($curlErrno !== 0) {
        throw new RuntimeException('WooCommerce API request failed: ' . $curlError);
    }

    $decoded = json_decode((string) $responseBody, true);

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($decoded) && isset($decoded['message'])
            ? (string) $decoded['message']
            : 'HTTP ' . $statusCode;

        throw new RuntimeException('WooCommerce API error (' . $statusCode . '): ' . $message);
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('WooCommerce API returned an unparsable response.');
    }

    return $decoded;
}

function wc_client_get(string $endpoint, array $query = []): array
{
    return wc_client_request('GET', $endpoint, [], $query);
}

function wc_client_post(string $endpoint, array $data = []): array
{
    return wc_client_request('POST', $endpoint, $data);
}

function wc_client_put(string $endpoint, array $data = []): array
{
    return wc_client_request('PUT', $endpoint, $data);
}

/**
 * Bugfix pass (missing woocommerce_sync_hash column incident) - turns a caught sync
 * exception into one of a small set of admin-facing categories, so a bulk sync's summary
 * can show "12 failed - Database error" instead of a bare, uninformative count. Based on
 * the exact exception types/messages this file and PDO actually throw (see
 * wc_client_request()'s RuntimeExceptions above and PDO::ATTR_ERRMODE = ERRMODE_EXCEPTION
 * in config/database.php) - not a guess at hypothetical error shapes.
 */
function wc_client_classify_sync_error(Throwable $e): string
{
    if ($e instanceof PDOException) {
        return 'Database error';
    }

    $message = $e->getMessage();

    if (stripos($message, 'not configured') !== false) {
        return 'WooCommerce not configured';
    }
    if (stripos($message, 'curl extension') !== false) {
        return 'Server configuration error';
    }
    if (wc_client_is_sku_conflict_message($message)) {
        return 'SKU conflict';
    }
    if (stripos($message, 'is missing') !== false) {
        return 'Invalid product data';
    }
    // Bugfix pass (image URL missing domain) - WooCommerce's own remote-image-fetch error
    // ("Error getting remote image {url}. Error: A valid URL was not provided.") is
    // otherwise indistinguishable from any other HTTP error, checked before the generic
    // 'API error' fallback so an image problem reads as one, not just a bare API failure.
    if (stripos($message, 'image') !== false && (stripos($message, 'url') !== false || stripos($message, 'remote') !== false)) {
        return 'Image upload error';
    }
    if (preg_match('/API error \(\d+\)/', $message) === 1 || stripos($message, 'request failed') !== false || stripos($message, 'unparsable') !== false || stripos($message, 'did not return') !== false) {
        return 'API error';
    }

    return 'Unknown error';
}

/**
 * Bugfix (SKU sync errors) - same substring test wc_client_classify_sync_error() uses to
 * bucket a caught exception as 'SKU conflict', extracted so wc_client_sync_product_from_
 * mewmii()/wc_client_sync_variable_product_from_mewmii() can react to this ONE specific
 * failure (see their own callers below) without duplicating or drifting from that rule.
 */
function wc_client_is_sku_conflict_message(string $message): bool
{
    return stripos($message, 'sku') !== false
        && (stripos($message, 'duplicat') !== false || stripos($message, 'already') !== false || stripos($message, 'invalid') !== false);
}

/**
 * Bugfix (SKU sync errors) - status=any broadens this search beyond the REST API's default
 * (published-ish) status filter to draft/pending/private products too (WordPress's own
 * "any" still excludes trash - this is a real, known limitation, not a guaranteed catch-all).
 * Without this, a non-published product already holding a SKU was invisible to this search,
 * so wc_client_resolve_existing_product() found nothing, fell through to a CREATE, and
 * WooCommerce rejected that with "Invalid or duplicated SKU" for a conflict this function
 * could have resolved (by updating the matched product) instead of failing outright. A SKU
 * still held by a genuinely trashed product, or by a variation elsewhere, is caught
 * separately - see wc_client_is_sku_conflict_message()'s callers below, which turn that
 * remaining case into a clear, SKU-named error instead of a bare WooCommerce 400.
 */
function wc_client_find_product_by_sku(string $sku): ?array
{
    $products = wc_client_get('products', ['sku' => $sku, 'per_page' => 10, 'status' => 'any']);

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        if (trim((string) ($product['sku'] ?? '')) === $sku) {
            return $product;
        }
    }

    return null;
}

function wc_client_find_variation_by_sku(int $parentWcId, string $sku): ?array
{
    $variations = wc_client_get('products/' . $parentWcId . '/variations', ['sku' => $sku, 'per_page' => 10]);

    foreach ($variations as $variation) {
        if (!is_array($variation)) {
            continue;
        }

        if (trim((string) ($variation['sku'] ?? '')) === $sku) {
            return $variation;
        }
    }

    return null;
}

/**
 * Direct lookup by WooCommerce product id - a single GET /products/{id}, no search involved.
 * Returns null (never throws) on any failure, so callers can fall back to a SKU search - the
 * stored id may be stale (e.g. the product was deleted on the WooCommerce side since the last
 * sync) without that being treated as a hard error.
 */
function wc_client_find_product_by_id(int $wcId): ?array
{
    if ($wcId < 1) {
        return null;
    }

    try {
        $product = wc_client_get('products/' . $wcId);
    } catch (Throwable $e) {
        return null;
    }

    return ((int) ($product['id'] ?? 0) === $wcId) ? $product : null;
}

/** Same as wc_client_find_product_by_id(), for one variation under a known parent product. */
function wc_client_find_variation_by_id(int $parentWcId, int $wcVariationId): ?array
{
    if ($parentWcId < 1 || $wcVariationId < 1) {
        return null;
    }

    try {
        $variation = wc_client_get('products/' . $parentWcId . '/variations/' . $wcVariationId);
    } catch (Throwable $e) {
        return null;
    }

    return ((int) ($variation['id'] ?? 0) === $wcVariationId) ? $variation : null;
}

/**
 * Resolves the existing WooCommerce product (if any) a Mewmii product row should be synced
 * against - woocommerce_product_id PREFERRED (a single direct GET, no search), SKU used only
 * as a fallback when that id is missing, or when it's set but no longer resolves on the
 * WooCommerce side (e.g. deleted there since the last sync). This is what makes a SKU edit in
 * Mewmii OS safe to sync: once a product has a woocommerce_product_id, a later SKU change can
 * never cause the SKU-search fallback to silently miss it and create a duplicate - the id
 * lookup already found (and will keep using) the correct product regardless of what its SKU
 * is on either side.
 */
function wc_client_resolve_existing_product(array $product, string $sku): ?array
{
    $wcId = (int) ($product['woocommerce_product_id'] ?? 0);

    if ($wcId > 0) {
        $existing = wc_client_find_product_by_id($wcId);
        if ($existing !== null) {
            return $existing;
        }
    }

    return wc_client_find_product_by_sku($sku);
}

/** Same id-preferred, SKU-fallback resolution as wc_client_resolve_existing_product(), for one variation. */
function wc_client_resolve_existing_variation(int $remoteProductId, array $variation, string $variationSku): ?array
{
    $wcVariationId = (int) ($variation['woocommerce_variation_id'] ?? 0);

    if ($wcVariationId > 0) {
        $existing = wc_client_find_variation_by_id($remoteProductId, $wcVariationId);
        if ($existing !== null) {
            return $existing;
        }
    }

    return wc_client_find_variation_by_sku($remoteProductId, $variationSku);
}

/**
 * Mewmii products.status -> WooCommerce product status. The inverse of
 * wc_import_map_product_status() (includes/wc_product_import.php), kept consistent with it:
 * active <-> publish, hidden <-> private. 'draft' maps straight across. 'archived' has no
 * WooCommerce equivalent - mapped to 'draft' (hidden from the storefront, but never WordPress
 * 'trash', which carries its own auto-delete behavior far more destructive than intended here).
 */
function wc_client_map_product_status_for_woocommerce(string $mewmiiStatus): string
{
    switch ($mewmiiStatus) {
        case 'active':
            return 'publish';
        case 'hidden':
            return 'private';
        default: // draft, archived, or anything unrecognized - never force-publish by default.
            return 'draft';
    }
}

function wc_client_build_gallery_images(PDO $pdo, int $productId): array
{
    // Main image first (if any), then gallery images in order - matches WooCommerce's
    // own model where images[0] is the featured image.
    $stmt = $pdo->prepare("
        SELECT image_path FROM product_images
        WHERE product_id = ? AND variation_id IS NULL AND image_type IN ('main', 'gallery')
        ORDER BY (image_type = 'gallery') ASC, sort_order ASC, id ASC
    ");
    $stmt->execute([$productId]);

    $images = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        // Bugfix pass: a row whose file no longer exists on disk (moved, deleted outside
        // the app, wiped by a deployment that didn't preserve uploads/) is skipped here
        // rather than sent to WooCommerce as a URL guaranteed to 404 - see
        // wc_client_find_missing_images(), which callers use to attach a clear warning to
        // an otherwise-successful sync instead of silently having fewer photos appear.
        if (!image_upload_exists((string) $path)) {
            continue;
        }

        // image_path is stored as a bare relative path ("uploads/products/x.webp") -
        // WooCommerce needs a real absolute URL it can fetch, see
        // image_upload_public_url()'s own docblock for what used to go wrong here. A path
        // that can't be resolved to an absolute URL is skipped entirely rather than sent
        // malformed - the exact failure this fixes.
        $url = image_upload_public_url((string) $path);
        if ($url !== '') {
            $images[] = ['src' => $url];
        }
    }

    return $images;
}

function wc_client_build_variation_image(PDO $pdo, int $variationId): ?array
{
    $stmt = $pdo->prepare("SELECT image_path FROM product_images WHERE variation_id = ? AND image_type = 'variation' LIMIT 1");
    $stmt->execute([$variationId]);
    $path = $stmt->fetchColumn();

    if ($path === false || $path === '' || !image_upload_exists((string) $path)) {
        return null;
    }

    $url = image_upload_public_url((string) $path);

    return $url !== '' ? ['src' => $url] : null;
}

/**
 * Bugfix pass - lists every product_images row (main/gallery, and each variation's own
 * image) belonging to this product whose stored image_path points at a file that no
 * longer exists on disk. wc_client_build_gallery_images()/wc_client_build_variation_image()
 * already silently skip these when building the actual WooCommerce payload (so a sync
 * still succeeds), but skipping silently would leave staff wondering why WooCommerce shows
 * fewer photos than Mewmii OS does - this is what lets a caller attach a visible warning to
 * an otherwise-successful sync instead (see wc_client_sync_if_changed()).
 *
 * @return string[] human-readable descriptions, e.g. "main/gallery image" or "variation HK-PLUSH-001-M"
 */
function wc_client_find_missing_images(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("
        SELECT pi.image_path, pi.variation_id, pv.sku AS variation_sku
        FROM product_images pi
        LEFT JOIN product_variations pv ON pv.id = pi.variation_id
        WHERE pi.product_id = ?
    ");
    $stmt->execute([$productId]);

    $missing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (image_upload_exists((string) $row['image_path'])) {
            continue;
        }

        $missing[] = $row['variation_sku'] !== null
            ? ('variation ' . $row['variation_sku'])
            : 'main/gallery image';
    }

    return $missing;
}

/**
 * Attributes for a WooCommerce VARIABLE product's own payload: one entry per
 * variation-defining attribute, listing every value in play as an "option".
 */
function wc_client_build_variable_attributes_payload(PDO $pdo, int $productId): array
{
    $assignments = catalog_get_product_attribute_assignments($pdo, $productId);
    $attributes = [];

    foreach ($assignments as $assignment) {
        if (!$assignment['is_variation_attribute']) {
            continue;
        }

        $valueIds = catalog_get_assignment_value_ids($pdo, (int) $assignment['assignment_id']);
        if ($valueIds === []) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($valueIds), '?'));
        $stmt = $pdo->prepare("SELECT value FROM product_attribute_values WHERE id IN ({$placeholders}) ORDER BY sort_order ASC, value ASC");
        $stmt->execute($valueIds);

        $attributes[] = [
            'name' => $assignment['attribute_name'],
            'variation' => true,
            'visible' => true,
            'options' => $stmt->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    return $attributes;
}

/**
 * Attributes for one WooCommerce variation's own payload: the specific value this
 * variation was generated with for each attribute (e.g. Character=Hello Kitty, Color=Pink).
 */
function wc_client_build_variation_attributes_payload(PDO $pdo, int $variationId): array
{
    $stmt = $pdo->prepare('
        SELECT pa.name AS attribute_name, pav.value
        FROM product_variation_attribute_values pvav
        INNER JOIN product_attributes pa ON pa.id = pvav.attribute_id
        INNER JOIN product_attribute_values pav ON pav.id = pvav.attribute_value_id
        WHERE pvav.variation_id = ?
    ');
    $stmt->execute([$variationId]);

    $attributes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $attributes[] = [
            'name' => $row['attribute_name'],
            'option' => $row['value'],
        ];
    }

    return $attributes;
}

/**
 * Preorder/early-bird storefront messaging: WooCommerce's native "Out of Stock" label is
 * theme-rendered from stock_status and can't be reworded via the REST API - fixing that
 * fully requires a WordPress theme/plugin change outside this codebase. What we CAN do from
 * here is push a clear preorder blurb as the product's short_description (commonly shown
 * right next to Add to Cart), so customers see arrival/waiting-for-release info regardless
 * of the native badge. Returns null for ready_stock (or any other type) - callers must
 * leave short_description untouched in that case, never overwriting what staff wrote
 * manually. Three distinct states, matching catalog_product_is_orderable(): still within
 * the Early Bird window, waiting for release (closing date passed, not yet reopened), or
 * reopened (closing date passed, admin manually reopened - Regular Price, no more Early
 * Bird). There is no separate "Preorder Closing Date" - closing date only ever pauses
 * ordering, and reopening is always a deliberate admin action, never automatic.
 */
function wc_client_build_preorder_blurb(array $product): ?string
{
    $productType = $product['product_type'] ?? 'ready_stock';
    if (!in_array($productType, ['preorder', 'early_bird'], true)) {
        return null;
    }

    $typeLabel = $productType === 'early_bird' ? 'Early Bird' : 'Preorder';
    $closingDate = $product['preorder_closing_date'] ?? null;
    $hasClosed = !empty($closingDate) && strtotime((string) $closingDate) < strtotime('today');
    $reopened = !empty($product['preorder_reopened_at']);

    if ($hasClosed && !$reopened) {
        $lines = [$typeLabel . ' has ended - now waiting for release.'];
    } elseif ($hasClosed && $reopened) {
        $lines = ['Preorder available at regular price.'];
    } else {
        $lines = [$typeLabel . ' available.'];
        if (!empty($closingDate)) {
            $lines[] = 'Early Bird pricing ends: ' . $closingDate . '.';
        }
    }

    $releaseMonth = catalog_format_release_month($product['estimated_release_month'] ?? null);
    if ($releaseMonth !== null) {
        $lines[] = 'Estimated release: ' . $releaseMonth . '.';
    }
    // Customer-facing arrival messaging is always this fixed line - never the raw
    // estimated_arrival_date/supplier ETA, which is an internal logistics field only.
    // Release timing itself is driven by Estimated Release Month and the actual
    // release/reopen workflow (see catalog_product_is_orderable()), not this line.
    $lines[] = 'Estimated arrival: 2-3 weeks after product release.';

    return implode("\n", $lines);
}

/**
 * Native WooCommerce sale-price fields (sale_price/date_on_sale_from/date_on_sale_to) so
 * WooCommerce's own storefront logic shows/hides the sale price during the Early Bird
 * window - no need to replicate catalog_product_effective_price()'s date math here, and it
 * stays correct even for a product page WooCommerce renders without an API round-trip.
 * Returns [] when Enable Sale is off or no sale_price is set, so callers can just merge it
 * into their payload unconditionally.
 */
function wc_client_build_sale_price_fields(array $product): array
{
    if (empty($product['sale_enabled']) || $product['sale_price'] === null || $product['sale_price'] === '') {
        return [];
    }

    $fields = ['sale_price' => number_format((float) $product['sale_price'], 2, '.', '')];

    if (!empty($product['sale_start_date'])) {
        $fields['date_on_sale_from'] = $product['sale_start_date'];
    }
    if (!empty($product['preorder_closing_date'])) {
        $fields['date_on_sale_to'] = $product['preorder_closing_date'];
    }

    return $fields;
}

function wc_client_build_product_payload(array $product, PDO $pdo): array
{
    $productId = (int) ($product['id'] ?? 0);
    $name = trim((string) ($product['name'] ?? ''));
    $description = trim((string) ($product['description'] ?? ''));
    $price = number_format((float) ($product['selling_price'] ?? 0), 2, '.', '');

    $payload = [
        'name' => $name,
        'type' => 'simple',
        'description' => $description,
        'regular_price' => $price,
        'price' => $price,
        'status' => wc_client_map_product_status_for_woocommerce((string) ($product['status'] ?? 'draft')),
    ];

    if (in_array($product['product_type'] ?? 'ready_stock', ['preorder', 'early_bird'], true)) {
        // Preorder/early-bird stock is never tracked against available_quantity - it must
        // stay purchasable at 0 stock, so WooCommerce stock management is left off entirely.
        // catalog_product_availability_status() already folds in both the manual override
        // (checked first) and the closing-date/reopen lifecycle gate - quantity never
        // factors into either one.
        $payload['manage_stock'] = false;
        $payload['stock_status'] = catalog_product_availability_status($product) === 'available' ? 'instock' : 'outofstock';
    } else {
        $stock = product_effective_stock($pdo, $productId);
        $availabilityStatus = catalog_product_availability_status($product, (int) $stock['available_quantity']);

        if (($product['availability_override'] ?? 'auto') === 'auto') {
            // Untouched existing behavior: let WooCommerce compute in/out of stock from the
            // real quantity itself.
            $payload['manage_stock'] = true;
            $payload['stock_quantity'] = (int) $stock['available_quantity'];
        } else {
            // Admin has manually forced Available/Out of Stock for this Ready Stock
            // product, overriding the real quantity.
            $payload['manage_stock'] = false;
            $payload['stock_status'] = $availabilityStatus === 'available' ? 'instock' : 'outofstock';
        }
    }

    $payload = array_merge($payload, wc_client_build_sale_price_fields($product));

    // The admin-entered short description (Basic Information section) and the
    // auto-generated preorder/Early Bird blurb are two independent pieces of customer-
    // facing text - the blurb is appended after the admin's own summary rather than
    // replacing it, so setting one never silently erases the other.
    $shortDescription = trim((string) ($product['short_description'] ?? ''));
    $preorderBlurb = wc_client_build_preorder_blurb($product);
    $shortDescriptionParts = array_filter([$shortDescription !== '' ? $shortDescription : null, $preorderBlurb]);
    if ($shortDescriptionParts !== []) {
        $payload['short_description'] = implode("\n\n", $shortDescriptionParts);
    }

    $images = wc_client_build_gallery_images($pdo, $productId);
    if ($images !== []) {
        $payload['images'] = $images;
    }

    return $payload;
}

function wc_client_sync_product_from_mewmii(PDO $pdo, array $product): array
{
    $productId = (int) ($product['id'] ?? 0);
    $sku = trim((string) ($product['sku'] ?? ''));

    if ($productId < 1) {
        throw new RuntimeException('Product ID is missing.');
    }

    if ($sku === '') {
        throw new RuntimeException('Product SKU is missing.');
    }

    $payload = wc_client_build_product_payload($product, $pdo);
    $payload['sku'] = $sku;

    $existingProduct = wc_client_resolve_existing_product($product, $sku);
    if ($existingProduct !== null) {
        $response = wc_client_put('products/' . (int) ($existingProduct['id'] ?? 0), $payload);
    } else {
        try {
            $response = wc_client_post('products', $payload);
        } catch (RuntimeException $e) {
            // Bugfix (SKU sync errors) - the search above (including trashed products, see
            // wc_client_find_product_by_sku()) found nothing to update, yet WooCommerce still
            // rejected the create as a SKU conflict - most likely the SKU is in use by a
            // VARIATION elsewhere (WooCommerce enforces SKU uniqueness across variations too,
            // and this search never looks there) or some other product this app has no id/SKU
            // record of. Re-thrown with the SKU named so the exact reason is visible in Sync
            // Logs instead of a bare "Invalid or duplicated SKU" with no product context -
            // this is still just one product's exception, the caller's own per-product
            // try/catch (sync.php/bulk_action.php/sync_one.php) is what keeps the rest of a
            // batch running regardless.
            if (wc_client_is_sku_conflict_message($e->getMessage())) {
                throw new RuntimeException("SKU '{$sku}' already exists in WooCommerce but could not be automatically matched to update (it may belong to a variation, or a product this app hasn't linked) - resolve the conflict in WooCommerce, then retry.", 0, $e);
            }

            throw $e;
        }
    }

    $remoteProductId = (int) ($response['id'] ?? 0);
    if ($remoteProductId < 1) {
        throw new RuntimeException('WooCommerce did not return a product identifier.');
    }

    $stmt = $pdo->prepare('
        UPDATE products
        SET woocommerce_product_id = ?, published_to_woocommerce = 1, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');
    $stmt->execute([$remoteProductId, $productId]);

    // date_modified is WooCommerce's own timestamp for the product as of THIS push - see
    // wc_client_sync_if_changed()'s staleness baseline, which stores this so a later push
    // can tell whether WooCommerce has since been edited by something other than Mewmii OS.
    return ['id' => $remoteProductId, 'date_modified' => $response['date_modified'] ?? null];
}

/**
 * Syncs a variable product: the parent as a WooCommerce variable product (with its
 * variation-defining attributes), then every non-archived variation as a WooCommerce
 * product variation underneath it (attributes, price, image, stock, SKU).
 *
 * WooCommerce has no "inherit parent price" concept - every variation must carry its own
 * price to be purchasable, so a price_mode = 'inherit' variation resolves the parent's
 * current selling_price at sync time and pushes that as its regular_price. If the parent
 * price changes later, inheriting variations must be re-synced to pick it up.
 */
function wc_client_sync_variable_product_from_mewmii(PDO $pdo, array $product): array
{
    $productId = (int) ($product['id'] ?? 0);
    $sku = trim((string) ($product['sku'] ?? ''));

    if ($productId < 1) {
        throw new RuntimeException('Product ID is missing.');
    }

    if ($sku === '') {
        throw new RuntimeException('Product SKU is missing.');
    }

    $payload = [
        'name' => trim((string) ($product['name'] ?? '')),
        'type' => 'variable',
        'description' => trim((string) ($product['description'] ?? '')),
        'sku' => $sku,
        'status' => wc_client_map_product_status_for_woocommerce((string) ($product['status'] ?? 'draft')),
        'attributes' => wc_client_build_variable_attributes_payload($pdo, $productId),
    ];

    $shortDescription = trim((string) ($product['short_description'] ?? ''));
    $preorderBlurb = wc_client_build_preorder_blurb($product);
    $shortDescriptionParts = array_filter([$shortDescription !== '' ? $shortDescription : null, $preorderBlurb]);
    if ($shortDescriptionParts !== []) {
        $payload['short_description'] = implode("\n\n", $shortDescriptionParts);
    }

    $images = wc_client_build_gallery_images($pdo, $productId);
    if ($images !== []) {
        $payload['images'] = $images;
    }

    $existingProduct = wc_client_resolve_existing_product($product, $sku);
    if ($existingProduct !== null) {
        $response = wc_client_put('products/' . (int) ($existingProduct['id'] ?? 0), $payload);
    } else {
        try {
            $response = wc_client_post('products', $payload);
        } catch (RuntimeException $e) {
            // Bugfix (SKU sync errors) - same reasoning as wc_client_sync_product_from_mewmii().
            if (wc_client_is_sku_conflict_message($e->getMessage())) {
                throw new RuntimeException("SKU '{$sku}' already exists in WooCommerce but could not be automatically matched to update (it may belong to a variation, or a product this app hasn't linked) - resolve the conflict in WooCommerce, then retry.", 0, $e);
            }

            throw $e;
        }
    }

    $remoteProductId = (int) ($response['id'] ?? 0);
    if ($remoteProductId < 1) {
        throw new RuntimeException('WooCommerce did not return a product identifier.');
    }

    $pdo->prepare('
        UPDATE products
        SET woocommerce_product_id = ?, published_to_woocommerce = 1, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ')->execute([$remoteProductId, $productId]);

    $syncedVariations = 0;
    $isPreorderType = in_array($product['product_type'] ?? 'ready_stock', ['preorder', 'early_bird'], true);

    foreach (variation_list_for_product($pdo, $productId) as $variation) {
        if ($variation['status'] === 'archived') {
            continue;
        }

        $variationId = (int) $variation['id'];
        $variationSku = trim((string) $variation['sku']);
        if ($variationSku === '') {
            continue;
        }

        $price = variation_effective_price($variation, $product['selling_price'] ?? 0);

        $variationPayload = [
            'sku' => $variationSku,
            'regular_price' => number_format($price, 2, '.', ''),
            'attributes' => wc_client_build_variation_attributes_payload($pdo, $variationId),
        ];

        if ($isPreorderType) {
            // Same reasoning as wc_client_build_product_payload(): a variation's
            // available_quantity must never gate purchasability for these product types -
            // only catalog_product_availability_status()'s override/lifecycle checks do.
            $variationPayload['manage_stock'] = false;
            $variationPayload['stock_status'] = catalog_product_availability_status($product) === 'available' ? 'instock' : 'outofstock';
        } elseif (($product['availability_override'] ?? 'auto') === 'auto') {
            $variationPayload['manage_stock'] = true;
            $variationPayload['stock_quantity'] = (int) $variation['available_quantity'];
        } else {
            // Ready Stock variation, but the parent product has a manual override set.
            $variationPayload['manage_stock'] = false;
            $variationPayload['stock_status'] = catalog_product_availability_status($product, (int) $variation['available_quantity']) === 'available' ? 'instock' : 'outofstock';
        }

        // A price_mode='custom' variation's price is fully its own - Early Bird sale
        // pricing is a product-level concept and only applies to 'inherit' mode variations.
        if (($variation['price_mode'] ?? 'inherit') !== 'custom') {
            $variationPayload = array_merge($variationPayload, wc_client_build_sale_price_fields($product));
        }

        // Phase 9E - variation's own weight when weight_mode = 'custom', otherwise the
        // parent product's weight_grams (Phase 9D) - previously this only ever pushed the
        // variation's own (usually blank) weight, so an "inherit" variation's shipping
        // weight was silently omitted from WooCommerce instead of falling back to the
        // parent's. See variation_effective_weight() in includes/product_variations.php.
        $effectiveWeight = variation_effective_weight(
            (string) ($variation['weight_mode'] ?? 'inherit'),
            $variation['weight'],
            $product['weight_grams'] ?? null
        );
        if ($effectiveWeight !== null) {
            $variationPayload['weight'] = (string) $effectiveWeight;
        }

        $image = wc_client_build_variation_image($pdo, $variationId);
        if ($image !== null) {
            $variationPayload['image'] = $image;
        }

        $existingVariation = wc_client_resolve_existing_variation($remoteProductId, $variation, $variationSku);
        $variationResponse = $existingVariation !== null
            ? wc_client_put('products/' . $remoteProductId . '/variations/' . (int) ($existingVariation['id'] ?? 0), $variationPayload)
            : wc_client_post('products/' . $remoteProductId . '/variations', $variationPayload);

        $remoteVariationId = (int) ($variationResponse['id'] ?? 0);
        if ($remoteVariationId > 0) {
            $pdo->prepare('UPDATE product_variations SET woocommerce_variation_id = ? WHERE id = ?')
                ->execute([$remoteVariationId, $variationId]);
            $syncedVariations++;
        }
    }

    // date_modified is WooCommerce's own timestamp for the PARENT product as of this push -
    // same reasoning as wc_client_sync_product_from_mewmii()'s return value.
    return ['id' => $remoteProductId, 'variations_synced' => $syncedVariations, 'date_modified' => $response['date_modified'] ?? null];
}

/**
 * Routes a product to the correct sync path based on catalog_type.
 */
function wc_client_sync_any_product_from_mewmii(PDO $pdo, array $product): array
{
    if (($product['catalog_type'] ?? 'simple') === 'variable') {
        return wc_client_sync_variable_product_from_mewmii($pdo, $product);
    }

    return wc_client_sync_product_from_mewmii($pdo, $product);
}

/**
 * A stable fingerprint of every field wc_client_sync_any_product_from_mewmii() actually pushes
 * to WooCommerce - built from the same raw Mewmii data wc_client_build_product_payload()/
 * wc_client_sync_variable_product_from_mewmii() read, not the WooCommerce-shaped payload
 * itself, so this never needs to duplicate that shaping logic (reuses
 * product_effective_stock(), wc_client_build_gallery_images(), variation_list_for_product(),
 * wc_client_build_variation_attributes_payload() exactly as the real sync does). Used by
 * wc_client_sync_if_changed() to skip a product whose WooCommerce-relevant fields haven't
 * changed since its last successful sync, without an API call.
 */
function wc_client_product_sync_fingerprint(PDO $pdo, array $product): string
{
    $productId = (int) ($product['id'] ?? 0);
    $catalogType = ($product['catalog_type'] ?? 'simple') === 'variable' ? 'variable' : 'simple';

    $parts = [
        $product['name'] ?? '',
        $product['description'] ?? '',
        $product['short_description'] ?? '',
        $product['sku'] ?? '',
        $product['selling_price'] ?? '',
        $product['sale_enabled'] ?? '',
        $product['sale_price'] ?? '',
        $product['sale_start_date'] ?? '',
        $product['preorder_closing_date'] ?? '',
        $product['preorder_reopened_at'] ?? '',
        $product['product_type'] ?? '',
        $product['status'] ?? '',
        $product['availability_override'] ?? '',
        $product['estimated_release_month'] ?? '',
        $catalogType,
    ];

    if ($catalogType === 'simple') {
        $stock = product_effective_stock($pdo, $productId);
        $parts[] = (int) $stock['available_quantity'];
    }

    // Images (main + gallery) - path and order, since either changing should trigger a re-sync.
    foreach (wc_client_build_gallery_images($pdo, $productId) as $image) {
        $parts[] = $image['src'];
    }

    if ($catalogType === 'variable') {
        foreach (variation_list_for_product($pdo, $productId) as $variation) {
            if ($variation['status'] === 'archived') {
                continue;
            }

            $parts[] = implode('|', [
                $variation['id'], $variation['sku'], $variation['price_mode'], $variation['custom_price'],
                $variation['weight'], $variation['status'], $variation['available_quantity'],
                $variation['image_path'] ?? '',
            ]);

            foreach (wc_client_build_variation_attributes_payload($pdo, (int) $variation['id']) as $attribute) {
                $parts[] = $attribute['name'] . '=' . $attribute['option'];
            }
        }
    }

    return hash('sha256', json_encode($parts));
}

/**
 * Production Hardening Phase 2 - WooCommerce conflict protection. Reads the LIVE WooCommerce
 * product and compares its own `date_modified` against the timestamp Mewmii OS last
 * confirmed matched (products.woocommerce_last_seen_modified_at, set after every successful
 * import or push - see includes/wc_product_import.php and wc_client_sync_if_changed() below).
 * If WooCommerce's copy has moved on since then, something other than Mewmii OS changed it
 * (a direct edit in WordPress/WooCommerce) - pushing now would silently overwrite that edit.
 *
 * There is no webhook/event mechanism in this codebase, so detecting this costs one extra
 * WooCommerce API read per push - accepted as the price of the guarantee being asked for.
 * Fails OPEN (not stale) if WooCommerce doesn't return a usable `date_modified`, or if this
 * product has never had a baseline recorded - a missing/unreadable signal must never block
 * every future push.
 *
 * @return array{stale: bool, remote_modified_at: ?string}
 */
function wc_client_check_product_staleness(int $wooCommerceProductId, ?string $lastSeenModifiedAt): array
{
    $remote = wc_client_get('products/' . $wooCommerceProductId);
    $remoteModifiedAt = trim((string) ($remote['date_modified'] ?? ''));

    if ($remoteModifiedAt === '') {
        return ['stale' => false, 'remote_modified_at' => null];
    }

    $stale = $lastSeenModifiedAt !== null && strtotime($remoteModifiedAt) > strtotime($lastSeenModifiedAt);

    return ['stale' => $stale, 'remote_modified_at' => $remoteModifiedAt];
}

/**
 * Wraps wc_client_sync_any_product_from_mewmii() with a "skip if nothing WooCommerce-relevant
 * changed since the last successful sync" gate - reduces API calls on a bulk "Sync to
 * WooCommerce" run without touching the sync logic itself (see
 * wc_client_product_sync_fingerprint()). A product that has never been synced
 * (woocommerce_product_id not yet set) is always synced regardless of the stored hash, since
 * a NULL/stale hash must never be mistaken for "nothing to do" on a first-time push.
 *
 * $force bypasses the unchanged-skip gate entirely (still recomputes and stores the hash
 * afterward) - for the explicit, single-product "Sync this Product"/"Retry" actions (see
 * modules/products/sync_one.php), where a deliberate click must always actually push, never
 * silently no-op just because nothing looked different. $force does NOT bypass the
 * staleness/conflict check below - a deliberate click must still never overwrite a newer
 * WooCommerce-side edit, it only means "push even if the Mewmii-side fingerprint looks
 * unchanged."
 *
 * @return array{action: 'updated'|'skipped'|'stale', warning?: string} merged with
 * wc_client_sync_any_product_from_mewmii()'s own return value when action is 'updated'.
 */
function wc_client_sync_if_changed(PDO $pdo, array $product, bool $force = false): array
{
    $productId = (int) ($product['id'] ?? 0);
    $fingerprint = wc_client_product_sync_fingerprint($pdo, $product);

    $storedHash = $product['woocommerce_sync_hash'] ?? null;
    $wooCommerceProductId = (int) ($product['woocommerce_product_id'] ?? 0);
    // Bugfix pass (new-product false-positive conflict) - a product with no
    // woocommerce_product_id yet has, by definition, never been pushed or imported, so there is
    // nothing on the WooCommerce side it could conflict with. $alreadyPublished being false
    // skips BOTH the unchanged-fingerprint skip below (a product must always attempt its first
    // real push) AND the staleness/conflict check further down (there is no prior WooCommerce
    // edit to protect against yet) - see wc_client_check_product_staleness()'s own docblock for
    // why this same signal doubles as its required baseline.
    $alreadyPublished = $wooCommerceProductId > 0;

    if (!$force && $alreadyPublished && $storedHash !== null && hash_equals($storedHash, $fingerprint)) {
        return ['action' => 'skipped'];
    }

    $remoteModifiedAt = null;
    if ($alreadyPublished) {
        $staleness = wc_client_check_product_staleness($wooCommerceProductId, $product['woocommerce_last_seen_modified_at'] ?? null);

        if ($staleness['stale']) {
            return [
                'action' => 'stale',
                'warning' => 'WooCommerce has a newer edit for this product than Mewmii OS last saw - push skipped to avoid overwriting it. Review the product in WooCommerce, then retry.',
            ];
        }

        $remoteModifiedAt = $staleness['remote_modified_at'];
    }

    $result = wc_client_sync_any_product_from_mewmii($pdo, $product);

    // Bugfix pass - re-read the just-pushed product instead of trusting the write response's
    // own echoed date_modified as the new baseline. WooCommerce can touch a product again
    // internally in the moments right after a create/update (stock/price lookup-table
    // recalculation, image attachment bookkeeping, etc.), which can leave the create/update
    // response's date_modified slightly behind WooCommerce's own true state - the exact gap
    // that previously made a product's very next sync attempt (e.g. Auto Sync during save
    // immediately followed by a manual "Sync"/"Retry" click) look like an external edit and get
    // flagged stale, even though nothing outside Mewmii OS ever touched it. Falls back to the
    // write response's value (then the pre-push read) if this confirmation read fails, so a
    // transient WooCommerce error right after a successful push never blocks the product from
    // being marked as synced.
    $remoteProductId = (int) ($result['id'] ?? $wooCommerceProductId);
    $confirmed = $remoteProductId > 0 ? wc_client_find_product_by_id($remoteProductId) : null;
    $newBaseline = $confirmed['date_modified'] ?? $result['date_modified'] ?? $remoteModifiedAt;

    $pdo->prepare('UPDATE products SET woocommerce_sync_hash = ?, woocommerce_last_seen_modified_at = ? WHERE id = ?')
        ->execute([$fingerprint, $newBaseline, $productId]);

    // Bugfix pass - the push above already silently omitted any image whose file is
    // missing on disk (see wc_client_build_gallery_images()/wc_client_build_variation_
    // image()), so the sync itself never fails over this. Attached here so every entry
    // point (bulk sync, single-product sync, Auto Sync) can log a visible warning on an
    // otherwise-successful sync instead of staff only noticing WooCommerce has fewer
    // photos than Mewmii OS days later.
    $missingImages = wc_client_find_missing_images($pdo, $productId);

    return array_merge(['action' => 'updated', 'missing_images' => $missingImages], $result);
}

/**
 * The exact column set wc_client_sync_if_changed()/wc_client_product_sync_fingerprint() need
 * from a `products` row - shared by every push-sync entry point (the bulk "Sync to
 * WooCommerce" button in modules/products/sync.php, Auto Sync, and the single-product "Sync
 * this Product"/"Retry" actions in modules/products/sync_one.php) so the three can never
 * drift out of sync with each other over which columns are selected.
 */
function wc_client_load_product_for_sync(PDO $pdo, int $productId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, sku, name, short_description, description, catalog_type, selling_price, product_type,
               status, availability_override, preorder_closing_date, preorder_reopened_at,
               estimated_arrival_date, estimated_release_month, sale_enabled, sale_price, sale_start_date,
               woocommerce_product_id, woocommerce_sync_hash, woocommerce_last_seen_modified_at
        FROM products WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    return $product !== false ? $product : null;
}

/**
 * Pushes exactly one product to WooCommerce via wc_client_sync_if_changed() - the "Auto Sync
 * to WooCommerce" action (see wc_client_auto_sync_enabled()), called from
 * modules/products/create.php/edit.php right after a successful local save, when the setting
 * is on. Deliberately never THROWS: a WooCommerce-side failure must never block the save it's
 * reacting to, or the redirect that follows it - instead it reports what happened in its
 * return value, so the caller can show "Saved and synced to WooCommerce." or "...WooCommerce
 * sync failed" without the save itself ever being at risk. Also logged to sync_logs exactly
 * like the manual "Sync to WooCommerce" button (same sync_type), so a failure is still visible
 * on modules/integrations/woocommerce.php regardless of whether anyone reads the redirect
 * notice. Re-reads the product row itself (rather than trusting the caller's own in-memory
 * $form array, which isn't shaped like a full `products` row and doesn't carry
 * woocommerce_product_id/woocommerce_sync_hash) so it always syncs exactly what was just
 * committed to the database, not what was posted.
 *
 * @return array{status: 'synced'|'skipped'|'stale'|'failed', error: ?string} 'skipped' covers
 * both "no SKU yet" and wc_client_sync_if_changed()'s own unchanged-skip - neither is an
 * error, and callers should show no WooCommerce-specific notice for either. 'stale' means
 * WooCommerce has a newer edit than Mewmii OS has seen - the push was deliberately withheld
 * (see wc_client_check_product_staleness()); callers should show $error as a warning, not an
 * error, same severity as 'skipped' but worth surfacing since it needs a human to look.
 */
function wc_client_auto_sync_product(PDO $pdo, int $productId): array
{
    try {
        $product = wc_client_load_product_for_sync($pdo, $productId);

        if ($product === null || trim((string) $product['sku']) === '') {
            return ['status' => 'skipped', 'error' => null];
        }

        $result = wc_client_sync_if_changed($pdo, $product);

        if ($result['action'] === 'updated') {
            sync_log_success($pdo, 'woocommerce_product_sync', $productId);

            // Bugfix pass - the sync itself already succeeded (missing images are skipped,
            // never a hard failure - see wc_client_find_missing_images()), but this still
            // deserves its own visible warning entry rather than silently having fewer
            // photos on WooCommerce than in Mewmii OS.
            $missingImages = $result['missing_images'] ?? [];
            if ($missingImages !== []) {
                sync_log_write($pdo, 'woocommerce_product_sync', 'warning', $productId, 'Synced, but skipped missing image file(s) for: ' . implode(', ', $missingImages) . '. Re-upload the affected image(s).');
            }

            return ['status' => 'synced', 'error' => null, 'missing_images' => $missingImages];
        }

        if ($result['action'] === 'stale') {
            // Logged as its own 'warning' status (sync_logs.status is a plain VARCHAR, not
            // an enum, so this needed no schema change) rather than 'failed' - nothing
            // actually went wrong, the push was deliberately withheld to avoid overwriting
            // a newer WooCommerce-side edit. Visible on modules/integrations/woocommerce.php
            // alongside every other sync outcome.
            sync_log_write($pdo, 'woocommerce_product_sync', 'warning', $productId, $result['warning'] ?? null);

            return ['status' => 'stale', 'error' => $result['warning'] ?? null];
        }

        return ['status' => 'skipped', 'error' => null];
    } catch (Throwable $e) {
        $message = $e->getMessage();

        // The logging call itself is also guarded - this function's whole point is that
        // NOTHING it does may ever propagate back to the save it's reacting to.
        try {
            sync_log_failure($pdo, 'woocommerce_product_sync', $message, $productId);
        } catch (Throwable $loggingFailure) {
        }

        return ['status' => 'failed', 'error' => $message];
    }
}

/** Latest sync_logs row for one product's push-sync history (woocommerce_product_sync), or null if it has never been attempted. */
function wc_client_get_last_sync_log(PDO $pdo, int $productId): ?array
{
    $stmt = $pdo->prepare("
        SELECT status, error_message, created_at
        FROM sync_logs
        WHERE sync_type = 'woocommerce_product_sync' AND reference_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * "2 minutes ago" / "3 hours ago" / "5 days ago" style relative time for a MySQL TIMESTAMP
 * column value (e.g. sync_logs.created_at) - read and displayed in whatever timezone this app
 * is configured for (see includes/bootstrap.php's date_default_timezone_set()), same as every
 * other raw timestamp this app already displays (e.g. the Recent Sync Activity tables on
 * modules/integrations/woocommerce.php), so this never needs its own GMT handling.
 */
function wc_client_format_time_ago(string $mysqlTimestamp): string
{
    $timestamp = strtotime($mysqlTimestamp);
    if ($timestamp === false) {
        return $mysqlTimestamp;
    }

    $secondsAgo = max(0, time() - $timestamp);

    if ($secondsAgo < 60) {
        return 'just now';
    }
    if ($secondsAgo < 3600) {
        $minutes = (int) floor($secondsAgo / 60);

        return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
    }
    if ($secondsAgo < 86400) {
        $hours = (int) floor($secondsAgo / 3600);

        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }

    $days = (int) floor($secondsAgo / 86400);

    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}
