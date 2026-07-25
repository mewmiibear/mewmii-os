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

function wc_client_find_product_by_sku(string $sku): ?array
{
    $products = wc_client_get('products', ['sku' => $sku, 'per_page' => 10]);

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
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $url) {
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
    $url = $stmt->fetchColumn();

    return ($url !== false && $url !== '') ? ['src' => (string) $url] : null;
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
    $response = $existingProduct !== null
        ? wc_client_put('products/' . (int) ($existingProduct['id'] ?? 0), $payload)
        : wc_client_post('products', $payload);

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

    return ['id' => $remoteProductId];
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
    $response = $existingProduct !== null
        ? wc_client_put('products/' . (int) ($existingProduct['id'] ?? 0), $payload)
        : wc_client_post('products', $payload);

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

        if (!empty($variation['weight'])) {
            $variationPayload['weight'] = (string) $variation['weight'];
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

    return ['id' => $remoteProductId, 'variations_synced' => $syncedVariations];
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
 * Wraps wc_client_sync_any_product_from_mewmii() with a "skip if nothing WooCommerce-relevant
 * changed since the last successful sync" gate - reduces API calls on a bulk "Sync to
 * WooCommerce" run without touching the sync logic itself (see
 * wc_client_product_sync_fingerprint()). A product that has never been synced
 * (woocommerce_product_id not yet set) is always synced regardless of the stored hash, since
 * a NULL/stale hash must never be mistaken for "nothing to do" on a first-time push.
 *
 * @return array{action: 'updated'|'skipped'} merged with wc_client_sync_any_product_from_mewmii()'s
 * own return value when action is 'updated'.
 */
function wc_client_sync_if_changed(PDO $pdo, array $product): array
{
    $productId = (int) ($product['id'] ?? 0);
    $fingerprint = wc_client_product_sync_fingerprint($pdo, $product);

    $storedHash = $product['woocommerce_sync_hash'] ?? null;
    $alreadyPublished = !empty($product['woocommerce_product_id']);

    if ($alreadyPublished && $storedHash !== null && hash_equals($storedHash, $fingerprint)) {
        return ['action' => 'skipped'];
    }

    $result = wc_client_sync_any_product_from_mewmii($pdo, $product);

    $pdo->prepare('UPDATE products SET woocommerce_sync_hash = ? WHERE id = ?')
        ->execute([$fingerprint, $productId]);

    return array_merge(['action' => 'updated'], $result);
}

/**
 * Pushes exactly one product to WooCommerce via wc_client_sync_if_changed() - the "Auto Sync
 * to WooCommerce" action (see wc_client_auto_sync_enabled()), called from
 * modules/products/create.php/edit.php right after a successful local save, when the setting
 * is on. Deliberately never throws: a WooCommerce-side failure must never block the save it's
 * reacting to, or the redirect that follows it - logged to sync_logs exactly like the manual
 * "Sync to WooCommerce" button (same sync_type), so a failure is still visible on
 * modules/integrations/woocommerce.php, just never disruptive to the person saving the
 * product. Re-reads the product row itself (rather than trusting the caller's own in-memory
 * $form array, which isn't shaped like a full `products` row and doesn't carry
 * woocommerce_product_id/woocommerce_sync_hash) so it always syncs exactly what was just
 * committed to the database, not what was posted.
 */
function wc_client_auto_sync_product(PDO $pdo, int $productId): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT id, sku, name, short_description, description, catalog_type, selling_price, product_type,
                   status, availability_override, preorder_closing_date, preorder_reopened_at,
                   estimated_arrival_date, estimated_release_month, sale_enabled, sale_price, sale_start_date,
                   woocommerce_product_id, woocommerce_sync_hash
            FROM products WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product === false || trim((string) $product['sku']) === '') {
            return;
        }

        $result = wc_client_sync_if_changed($pdo, $product);

        if ($result['action'] === 'updated') {
            sync_log_success($pdo, 'woocommerce_product_sync', $productId);
        }
    } catch (Throwable $e) {
        // The logging call itself is also guarded - this function's whole point is that
        // NOTHING it does may ever propagate back to the save it's reacting to.
        try {
            sync_log_failure($pdo, 'woocommerce_product_sync', $e->getMessage(), $productId);
        } catch (Throwable $loggingFailure) {
        }
    }
}
