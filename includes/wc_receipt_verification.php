<?php

/**
 * Mewmii OS -> WordPress receipt verification write-back. Calls the custom
 * POST /wp-json/mewmii/v1/receipt-status endpoint added to mewmii-preorder.php.
 *
 * Authenticated via a shared token (X-Mewmii-Token header), not the WooCommerce REST consumer
 * key/secret wc_client.php uses for wc/v3 calls - this isn't a WooCommerce-core endpoint, so it
 * has its own auth scheme. Reuses config.php's woocommerce.webhook_secret as that shared token:
 * that value has existed since the original WooCommerce integration work but was never read by
 * any code until now.
 *
 * This function only ever performs the outbound HTTP call - it never writes to mewmii_orders.
 * Callers (see modules/orders/view.php) must only update local receipt_status/
 * receipt_reject_reason after this returns successfully, and must never touch local state if it
 * throws.
 */

require_once __DIR__ . '/wc_client.php';

/**
 * @throws RuntimeException on any failure - missing config, network error, non-2xx response,
 * or a response body that isn't a recognizable success payload. The message is safe to show
 * directly to an admin.
 */
function wc_receipt_verification_send(int $woocommerceOrderId, string $receiptStatus, ?string $rejectReason): array
{
    if ($woocommerceOrderId < 1) {
        throw new RuntimeException('This order has no linked WooCommerce order to notify.');
    }

    $config = wc_client_config();
    if (empty($config['url']) || empty($config['webhook_secret'])) {
        throw new RuntimeException('WooCommerce URL or webhook secret is not configured.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP curl extension is required for receipt verification requests.');
    }

    $url = rtrim($config['url'], '/') . '/wp-json/mewmii/v1/receipt-status';
    $payload = [
        'order_id' => $woocommerceOrderId,
        'receipt_status' => $receiptStatus,
        'receipt_reject_reason' => $rejectReason ?? '',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Mewmii-Token: ' . $config['webhook_secret'],
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $responseBody = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        throw new RuntimeException('Could not reach the WooCommerce site: ' . $curlError);
    }

    $decoded = json_decode((string) $responseBody, true);

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : 'HTTP ' . $statusCode;
        throw new RuntimeException('WooCommerce rejected the request (' . $statusCode . '): ' . $message);
    }

    if (!is_array($decoded) || empty($decoded['success'])) {
        throw new RuntimeException('WooCommerce returned an unexpected response.');
    }

    return $decoded;
}
