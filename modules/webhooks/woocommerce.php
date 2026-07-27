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

// --- TEMPORARY DIAGNOSTIC (production "Webhook receiving is not configured" audit) ------
// Reachable only via ?wc_webhook_diagnose=1 (never on a normal WooCommerce delivery, which
// never sets this param). Reports ONLY booleans/lengths/paths - never the secret value
// itself - to distinguish between the candidate causes without creating a real disclosure
// risk on this unauthenticated, internet-facing endpoint. Remove this whole block once the
// mismatch is found.
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
            // opcache_get_status() keys scripts by their resolved path - checked against both
            // the raw and realpath()'d form since either can be the key depending on PHP/OS.
            $cachedEntry = $status['scripts'][$realConfigPath] ?? $status['scripts'][$configPath] ?? null;
            $opcacheInfo = [
                'opcache_enabled' => true,
                'config_php_cached' => $cachedEntry !== null,
                // If cached, compares the timestamp opcache captured at compile time against
                // config.php's CURRENT on-disk mtime - a mismatch means opcache is serving a
                // stale compiled copy from before the last edit (needs a PHP-FPM/opcache
                // reset, not just a file save) - this directly answers check #6.
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
            // isset() specifically - distinguishes "key missing from the array entirely"
            // (stale config.php predating this mapping) from "key present but empty string"
            // (the mapping exists, but getenv() returned nothing at the time config.php ran).
            'webhook_receive_secret_key_present' => array_key_exists('webhook_receive_secret', $config),
            'webhook_receive_secret_length' => strlen($secret),
        ],
        'opcache' => $opcacheInfo,
    ], JSON_PRETTY_PRINT);
    exit;
}
// --- END TEMPORARY DIAGNOSTIC -------------------------------------------------------------

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
    // --- TEMPORARY DIAGNOSTIC (Invalid signature audit) --------------------------------
    // error_log only - the response below is byte-for-byte unchanged from before this line,
    // so this changes nothing about what WooCommerce (or anything else) observes. Logs
    // lengths/hashes only, never the secret or the raw body content: a signature is a
    // one-way HMAC output, safe to log in full (it can't be used to derive the secret or
    // forge a signature for a different body), and a hash of the body proves whether the
    // bytes this script received match what WooCommerce actually sent (get that from the
    // WooCommerce webhook delivery log's own "Body" panel and hash it the same way to
    // compare) without logging the body's contents. Remove this block once the mismatch is
    // found.
    $expectedForLog = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    error_log(sprintf(
        '[wc_webhook][signature-diagnostic] remote_addr=%s method=%s content_length_header=%s content_type=%s topic=%s delivery_id=%s body_bytes=%d body_sha256=%s received_signature=%s (len=%d) expected_signature=%s (len=%d) secret_sha256_fingerprint=%s',
        // Not an HTTP_* header, so the inbound_headers dump below misses it - this is the
        // actual TCP connection source PHP sees. If Cloudflare is proxying, this will be a
        // Cloudflare edge IP, never WooCommerce's own server - the clearest single proof of
        // whether a proxy is terminating the connection in front of this script at all.
        $_SERVER['REMOTE_ADDR'] ?? '(unknown)',
        // The one unambiguous signal: WooCommerce always delivers via POST; a browser
        // visiting the URL directly is always GET. Distinguishes "this was just someone
        // loading the URL" from "this was a real delivery whose body/headers went missing"
        // - topic/delivery_id below can't do this alone, since LiteSpeed stripping
        // WooCommerce's custom X-WC-Webhook-* headers on a genuine POST would ALSO leave
        // them blank, identical to a GET.
        $_SERVER['REQUEST_METHOD'] ?? '(unknown)',
        // What the server itself says the incoming body size was, independent of whether
        // PHP's php://input actually delivered it - if this is >0 but body_bytes above is 0,
        // the body was lost somewhere between the web server and PHP (LiteSpeed/php://input
        // territory), not by WooCommerce failing to send one.
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
        // NOT the secret - a one-way fingerprint of it, so you can independently hash
        // whatever you pasted into WooCommerce's webhook Secret field the same way
        // (hash('sha256', $secret)) and compare fingerprints without ever printing the
        // real secret anywhere.
        hash('sha256', $secret)
    ));

    // Full inbound header dump - proves exactly which headers reached PHP at all. None of
    // these are secret (they're request metadata, not credentials), so logging them in full
    // is safe. If User-Agent/Host/etc. show up but every X-WC-Webhook-* header is missing,
    // that's LiteSpeed (or something in front of PHP) selectively dropping WooCommerce's
    // custom headers specifically, not a general header outage.
    $inboundHeaders = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $inboundHeaders[$key] = $value;
        }
    }
    error_log('[wc_webhook][signature-diagnostic] inbound_headers=' . json_encode($inboundHeaders));
    if (function_exists('getallheaders')) {
        // Cross-check against $_SERVER's HTTP_*-derived view above - some SAPIs (LiteSpeed's
        // LSAPI among them) have historically had discrepancies between the two, so if one
        // shows the signature header and the other doesn't, that split itself is the finding.
        error_log('[wc_webhook][signature-diagnostic] getallheaders=' . json_encode(getallheaders()));
    }
    // --- END TEMPORARY DIAGNOSTIC -------------------------------------------------------

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

if (!wc_webhook_verify_signature($rawBody, $signatureHeader, $secret)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid signature.']);
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
