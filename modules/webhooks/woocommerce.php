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
 * should flow in: product.created, product.updated, order.created, order.updated,
 * customer.created, customer.updated - all can point at this SAME URL, since WooCommerce
 * includes the topic/resource in its own request headers (read below) rather than needing a
 * separate endpoint per topic.
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

$signatureHeader = trim((string) ($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? ''));
$topic = trim((string) ($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? ''));
$resource = trim((string) ($_SERVER['HTTP_X_WC_WEBHOOK_RESOURCE'] ?? ''));
$deliveryId = trim((string) ($_SERVER['HTTP_X_WC_WEBHOOK_DELIVERY_ID'] ?? ''));

if ($secret === '') {
    // Not configured yet - reject rather than silently accepting unverifiable requests. 503,
    // not 401: this is a Mewmii OS configuration gap, not a request-level auth failure.
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Webhook receiving is not configured.']);
    exit;
}

if (!wc_webhook_verify_signature($rawBody, $signatureHeader, $secret)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid signature.']);
    exit;
}

// WooCommerce sends a one-time "ping" delivery when a webhook is first created/activated -
// topic/resource headers are absent on that ping in some WooCommerce versions, and there is
// nothing to queue either way. Signature (when present) was already verified above;
// acknowledged with 200 regardless so the new webhook doesn't get flagged/disabled over a
// ping it was never going to carry a real payload for.
if ($topic === '' || $resource === '') {
    http_response_code(200);
    echo json_encode(['success' => true, 'ping' => true]);
    exit;
}

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
    // A genuine failure to record (e.g. the database is unreachable) must NOT return 200 -
    // that would tell WooCommerce this delivery succeeded and it would never retry, silently
    // losing the event. error_log() rather than sync_log_failure(): if the database write
    // itself is what's failing, a second DB write to log the first failure can't be relied on.
    error_log('[wc_webhook] Failed to record event (topic=' . $topic . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to record event.']);
    exit;
}

http_response_code(200);
echo json_encode(['success' => true, 'duplicate' => $eventId === null]);
