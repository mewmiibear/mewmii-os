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

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERPWD => $config['consumer_key'] . ':' . $config['consumer_secret'],
    ]);

    if (in_array($method, ['POST', 'PUT'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $responseBody = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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
 * right next to Add to Cart), so customers see arrival/closing info regardless of the
 * native badge. Returns null for ready_stock (or any other type) - callers must leave
 * short_description untouched in that case, never overwriting what staff wrote manually.
 */
function wc_client_build_preorder_blurb(array $product): ?string
{
    $productType = $product['product_type'] ?? 'ready_stock';
    if (!in_array($productType, ['preorder', 'early_bird'], true)) {
        return null;
    }

    $typeLabel = $productType === 'early_bird' ? 'Early Bird' : 'Preorder';
    $lines = [$typeLabel . ' available.'];

    if (!empty($product['estimated_arrival_date'])) {
        $lines[] = 'Estimated arrival: ' . $product['estimated_arrival_date'] . '.';
    }
    if (!empty($product['preorder_closing_date'])) {
        $lines[] = 'Orders close: ' . $product['preorder_closing_date'] . '.';
    }

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
        'status' => 'publish',
    ];

    if (in_array($product['product_type'] ?? 'ready_stock', ['preorder', 'early_bird'], true)) {
        // Preorder/early-bird stock is never tracked against available_quantity - it must
        // stay purchasable at 0 stock, so WooCommerce stock management is left off entirely
        // and purchasability is driven only by status/closing date instead.
        $payload['manage_stock'] = false;
        $payload['stock_status'] = catalog_product_is_orderable($product) ? 'instock' : 'outofstock';
    } else {
        $stock = product_effective_stock($pdo, $productId);
        $payload['manage_stock'] = true;
        $payload['stock_quantity'] = (int) $stock['available_quantity'];
    }

    $payload = array_merge($payload, wc_client_build_sale_price_fields($product));

    $preorderBlurb = wc_client_build_preorder_blurb($product);
    if ($preorderBlurb !== null) {
        $payload['short_description'] = $preorderBlurb;
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

    $existingProduct = wc_client_find_product_by_sku($sku);
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
        'status' => 'publish',
        'attributes' => wc_client_build_variable_attributes_payload($pdo, $productId),
    ];

    $preorderBlurb = wc_client_build_preorder_blurb($product);
    if ($preorderBlurb !== null) {
        $payload['short_description'] = $preorderBlurb;
    }

    $images = wc_client_build_gallery_images($pdo, $productId);
    if ($images !== []) {
        $payload['images'] = $images;
    }

    $existingProduct = wc_client_find_product_by_sku($sku);
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
            // available_quantity must never gate purchasability for these product types.
            $variationPayload['manage_stock'] = false;
            $variationPayload['stock_status'] = catalog_product_is_orderable($product) ? 'instock' : 'outofstock';
        } else {
            $variationPayload['manage_stock'] = true;
            $variationPayload['stock_quantity'] = (int) $variation['available_quantity'];
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

        $existingVariation = wc_client_find_variation_by_sku($remoteProductId, $variationSku);
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
