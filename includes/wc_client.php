<?php

/**
 * Minimal WooCommerce REST API client. Auth-only plumbing for phase 1 -
 * no product/customer/order sync logic lives here.
 */

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

function wc_client_build_product_payload(array $product): array
{
    $name = trim((string) ($product['name'] ?? ''));
    $description = trim((string) ($product['description'] ?? ''));
    $price = number_format((float) ($product['selling_price'] ?? 0), 2, '.', '');

    return [
        'name' => $name,
        'type' => 'simple',
        'description' => $description,
        'regular_price' => $price,
        'price' => $price,
        'status' => 'publish',
    ];
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

    $payload = wc_client_build_product_payload($product);
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
