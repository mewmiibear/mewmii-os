<?php

/**
 * WooCommerce webhook receiver (Phase 6E). Server-to-server only - WooCommerce calls this
 * URL directly, there is no browser session and no CSRF token to check, so this file
 * deliberately does NOT call app_require_login()/app_require_csrf(). Authentication is the
 * HMAC signature check below instead.
 *
 * Responsibilities, and ONLY these: read the raw request body, verify its signature, queue
 * it into webhook_events (see includes/wc_webhook.php's wc_webhook_record_event()), and
 * respond. No product/order/customer is read or written in this request - that happens later,
 * out-of-band, in cli/wc_webhook_process.php (cron-ticked). This is deliberate: WooCommerce
 * expects a fast response and will retry/eventually disable a webhook that's slow or times
 * out, which is exactly the risk this app's own image-download/variation-fetch work already
 * carries elsewhere (see includes/wc_product_import.php's docblock on shared-hosting
 * execution-time limits) - so none of that runs synchronously here.
 *
 * Register this URL in WooCommerce (Settings > Advanced > Webhooks) once per topic that
 * should flow in: product.created, product.updated, product.deleted, order.created,
 * order.updated, order.deleted, customer.created, customer.updated, customer.deleted - all can
 * point at this SAME URL, since WooCommerce includes the topic/resource in its own request
 * headers (read below) rather than needing a separate endpoint per topic.
 *
 * WooCommerce delete-webhook support - the *.deleted topics never cause a hard delete on the
 * Mewmii OS side (see includes/wc_webhook.php's wc_webhook_dispatch_product_deleted()/
 * wc_webhook_dispatch_order_deleted()/wc_webhook_dispatch_customer_deleted()): a deleted
 * product is archived (status = 'archived'), a deleted order is cancelled (order_status =
 * 'cancelled'), and a deleted customer has its PII cleared but its row kept (archived_at set) -
 * every case preserves history instead of removing anything.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/wc_client.php';
require_once __DIR__ . '/../../includes/wc_webhook.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$config = wc_client_config();
$secret = trim((string) ($config['webhook_receive_secret'] ?? ''));

// --- TEMPORARY DIAGNOSTIC (production "Webhook receiving is not configured" audit) ------
if (isset($_GET['wc_webhook_diagnose'])) {
    $envPath = dirname(__DIR__, 2) . '/.env';
    $configPath = dirname(__DIR__, 2) . '/config.php';

    $getenvValue = getenv('WC_WEBHOOK_RECEIVE_SECRET');
    $envSuperglobalValue = $_ENV['WC_WEBHOOK_RECEIVE_SECRET'] ?? null;
    $serverSuperglobalValue = $_SERVER['WC_WEBHOOK_RECEIVE_SECRET'] ?? null;

    $opcacheInfo = null;
    if (function_exists('opcache_get_status')) {
        $status = @opcache_get_status(true);
        if (is_array($status) && isset($status['scripts'])) {
            $realConfigPath = realpath($configPath) ?: $configPath;
            $cachedEntry = $status['scripts'][$realConfigPath] ?? $status['scripts'][$configPath] ?? null;
            $opcacheInfo = [
                'opcache_enabled' => true,
                'config_php_cached' => $cachedEntry !== null,
                'cached_mtime_matches_disk_mtime' => $cachedEntry !== null
                    ? (($cachedEntry['timestamp'] ?? null) === (is_file($configPath) ? filemtime($configPath) : null))
                    : null,
            ];
        } else {
            $opcacheInfo = ['opcache_enabled' => false];
        }
    } else {
        $opcacheInfo = ['opcache_enabled' => false, 'note' => 'opcache extension not loaded'];
    }

    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'diagnostic' => 'wc_webhook_receive_secret',
        'php_sapi' => PHP_SAPI,
        'env_file' => [
            'resolved_path' => $envPath,
            'exists' => is_file($envPath),
            'readable' => is_file($envPath) && is_readable($envPath),
        ],
        'getenv' => [
            'present' => $getenvValue !== false,
            'nonempty' => $getenvValue !== false && trim((string) $getenvValue) !== '',
            'length' => $getenvValue !== false ? strlen(trim((string) $getenvValue)) : 0,
        ],
        'env_superglobal' => [
            'present' => $envSuperglobalValue !== null,
            'length' => $envSuperglobalValue !== null ? strlen(trim((string) $envSuperglobalValue)) : 0,
        ],
        'server_superglobal' => [
            'present' => $serverSuperglobalValue !== null,
            'length' => $serverSuperglobalValue !== null ? strlen(trim((string) $serverSuperglobalValue)) : 0,
        ],
        'config_php' => [
            'resolved_path' => $configPath,
            'realpath' => realpath($configPath) ?: null,
            'exists' => is_file($configPath),
            'mtime' => is_file($configPath) ? filemtime($configPath) : null,
            'mtime_human' => is_file($configPath) ? gmdate('Y-m-d H:i:s', filemtime($configPath)) . ' UTC' : null,
            'woocommerce_key_present' => isset($config) && is_array($config),
            'webhook_receive_secret_key_present' => array_key_exists('webhook_receive_secret', $config),
            'webhook_receive_secret_length' => strlen($secret),
        ],
        'opcache' => $opcacheInfo,
    ], JSON_PRETTY_PRINT);
    exit;
}
// --- END TEMPORARY DIAGNOSTIC -------------------------------------------------------------

// Read headers (supports both $_SERVER and getallheaders for LiteSpeed compatibility)
$allHeaders = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];

$signatureHeader = trim((string) ($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? $allHeaders['x-wc-webhook-signature'] ?? ''));
$topic           = trim((string) ($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? $allHeaders['x-wc-webhook-topic'] ?? ''));
$resource        = trim((string) ($_SERVER['HTTP_X_WC_WEBHOOK_RESOURCE'] ?? $allHeaders['x-wc-webhook-resource'] ?? ''));
$deliveryId      = trim((string) ($_SERVER['HTTP_X_WC_WEBHOOK_DELIVERY_ID'] ?? $allHeaders['x-wc-webhook-delivery-id'] ?? ''));

if ($secret === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Webhook receiving is not configured.']);
    exit;
}

// =========================================================================
// 1. PING CHECK (Placed before signature verification)
// =========================================================================
// WooCommerce sends a one-time "ping" delivery when a webhook is created/activated.
// Topic/resource headers are absent on pings in some WooCommerce versions.
// Acknowledge with 200 OK immediately so WooCommerce allows saving the webhook.
if ($topic === '' || $resource === '') {
    http_response_code(200);
    echo json_encode(['success' => true, 'ping' => true]);
    exit;
}

// =========================================================================
// 2. SIGNATURE VERIFICATION (For real event payloads)
// =========================================================================
if (!wc_webhook_verify_signature($rawBody, $signatureHeader, $secret)) {
    // --- TEMPORARY DIAGNOSTIC (Invalid signature audit) --------------------------------
    $expectedForLog = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    error_log(sprintf(
        '[wc_webhook][signature-diagnostic] remote_addr=%s method=%s content_length_header=%s content_type=%s topic=%s delivery_id=%s body_bytes=%d body_sha256=%s received_signature=%s (len=%d) expected_signature=%s (len=%d) secret_sha256_fingerprint=%s',
        $_SERVER['REMOTE_ADDR'] ?? '(unknown)',
        $_SERVER['REQUEST_METHOD'] ?? '(unknown)',
        $_SERVER['CONTENT_LENGTH'] ?? '(not set)',
        $_SERVER['CONTENT_TYPE'] ?? '(not set)',
        $topic !== '' ? $topic : '(none)',
        $deliveryId !== '' ? $deliveryId : '(none)',
        strlen($rawBody),
        hash('sha256', $rawBody),
        $signatureHeader !== '' ? $signatureHeader : '(empty/missing header)',
        strlen($signatureHeader),
        $expectedForLog,
        strlen($expectedForLog),
        hash('sha256', $secret)
    ));

    $inboundHeaders = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $inboundHeaders[$key] = $value;
        }
    }
    error_log('[wc_webhook][signature-diagnostic] inbound_headers=' . json_encode($inboundHeaders));
    if (function_exists('getallheaders')) {
        error_log('[wc_webhook][signature-diagnostic] getallheaders=' . json_encode(getallheaders()));
    }
    // --- END TEMPORARY DIAGNOSTIC -------------------------------------------------------

    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid signature.']);
    exit;
}

// =========================================================================
// 3. PAYLOAD PROCESSING
// =========================================================================
$payload = json_decode($rawBody, true);
$resourceId = is_array($payload) && isset($payload['id']) ? (int) $payload['id'] : 0;

$pdo = app_db();

try {
    $eventId = wc_webhook_record_event(
        $pdo,
        $topic,
        $resource,
        $resourceId > 0 ? $resourceId : null,
        $deliveryId !== '' ? $deliveryId : null,
        $rawBody
    );
} catch (Throwable $e) {
    error_log('[wc_webhook] Failed to record event (topic=' . $topic . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to record event.']);
    exit;
}

http_response_code(200);
echo json_encode(['success' => true, 'duplicate' => $eventId === null]);
