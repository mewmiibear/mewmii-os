<?php

/**
 * WooCommerce -> Mewmii OS order import (Phase 1: manual, admin-triggered polling only).
 *
 * Mewmii OS remains the sole owner of order_status - order_recompute_status() (see
 * includes/order_fulfillment.php) is the only place that ever writes it for a non-historical
 * order, and this importer never sets is_historical = 1, so every imported order stays inside
 * the normal fulfillment workflow. WooCommerce's own order status is used only as an input
 * signal for payment_status mapping, never persisted verbatim.
 *
 * modules/orders/view.php's apply_payment_status_change() is page-scoped (not includable
 * without executing that whole page), so wc_order_import_apply_payment_upgrade() below
 * reproduces its exact two writes (payment_status UPDATE + mewmii_order_events row) rather
 * than requiring the page file - includes/orders.php and the payment approval workflow itself
 * are left untouched.
 */

require_once __DIR__ . '/wc_client.php';
require_once __DIR__ . '/sync_log.php';
require_once __DIR__ . '/order_fulfillment.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/product_variations.php';

const WC_ORDER_IMPORT_SYNC_TYPE = 'woocommerce_order_import';

/**
 * Reads one key out of a WooCommerce REST order's meta_data array
 * ([['id' => .., 'key' => .., 'value' => ..], ...]). Returns null if the key isn't present -
 * covers both "WooCommerce never exposed it" and "this order genuinely has no value yet".
 */
function wc_order_import_get_meta(array $wcOrder, string $key): ?string
{
    foreach (($wcOrder['meta_data'] ?? []) as $meta) {
        if (is_array($meta) && ($meta['key'] ?? null) === $key) {
            $value = $meta['value'] ?? null;
            return $value !== null && $value !== '' ? (string) $value : null;
        }
    }

    return null;
}

/**
 * Receipt visibility fields for the Mewmii Preorder WordPress plugin's workflow - purely
 * informational display data, deliberately independent of payment_status/order_status (see
 * wc_order_import_map_payment_status()'s docblock). Returns
 * ['is_preorder_request', 'receipt_url', 'receipt_status', 'receipt_reject_reason'].
 * receipt_status is only ever non-null for a preorder order, and defaults to 'pending' when
 * WooCommerce didn't report receipt_upload_status at all (no receipt uploaded yet, or the
 * field isn't exposed) - it is never left unset for a preorder order.
 */
function wc_order_import_extract_receipt_fields(array $wcOrder): array
{
    $isPreorder = wc_order_import_get_meta($wcOrder, '_mewmii_is_preorder') === 'yes';
    $receiptUrl = wc_order_import_get_meta($wcOrder, '_pepro_receipt_url');
    $rawStatus = wc_order_import_get_meta($wcOrder, 'receipt_upload_status');
    $rejectReason = wc_order_import_get_meta($wcOrder, '_mewmii_reject_reason');

    $receiptStatus = null;
    if ($isPreorder) {
        $receiptStatus = in_array($rawStatus, ['approved', 'rejected'], true) ? $rawStatus : 'pending';
    }

    return [
        'is_preorder_request' => $isPreorder ? 1 : 0,
        'receipt_url' => $isPreorder ? $receiptUrl : null,
        'receipt_status' => $receiptStatus,
        'receipt_reject_reason' => $isPreorder ? $rejectReason : null,
    ];
}

/**
 * WooCommerce order status -> Mewmii payment_status. Any WooCommerce status not listed here
 * (cancelled, trash, checkout-draft, ...) means "do not import this order at all".
 *
 * rcpt-review/rcpt-rejected are custom statuses added by the Mewmii Preorder WordPress plugin
 * for its receipt-approval workflow (awaiting admin review / receipt rejected, re-upload
 * pending) - in both cases the customer has not had payment confirmed, so they map to
 * 'pending' exactly like every other not-yet-paid WooCommerce status. WooCommerce remains the
 * source of truth for which of the two the order is actually in; Mewmii OS only needs to know
 * "not paid yet" to run its own operational workflow correctly.
 */
function wc_order_import_map_payment_status(string $wcStatus): ?string
{
    $map = [
        'processing' => 'paid',
        'completed' => 'paid',
        'pending' => 'pending',
        'on-hold' => 'pending',
        'rcpt-review' => 'pending',
        'rcpt-rejected' => 'pending',
        'refunded' => 'refunded',
        'failed' => 'failed',
    ];

    return $map[$wcStatus] ?? null;
}

/**
 * Flattens WooCommerce's structured billing address into the single customers.address TEXT
 * column - Mewmii OS has no structured address fields to map into.
 */
function wc_order_import_format_address(array $billing): string
{
    $cityLine = implode(', ', array_filter([
        trim((string) ($billing['city'] ?? '')),
        trim((string) ($billing['state'] ?? '')),
        trim((string) ($billing['postcode'] ?? '')),
    ]));

    $lines = array_filter([
        trim((string) ($billing['address_1'] ?? '')),
        trim((string) ($billing['address_2'] ?? '')),
        $cityLine,
        trim((string) ($billing['country'] ?? '')),
    ], static fn ($line) => $line !== '');

    return implode("\n", $lines);
}

/**
 * 3-tier customer match: woocommerce_customer_id -> email -> create new. An email match
 * backfills woocommerce_customer_id onto the existing row so later imports of the same
 * WooCommerce customer hit tier 1 instead of creating a duplicate customer.
 */
function wc_order_import_match_customer(PDO $pdo, array $wcOrder): int
{
    $wcCustomerId = (int) ($wcOrder['customer_id'] ?? 0);
    $billing = $wcOrder['billing'] ?? [];
    $email = trim((string) ($billing['email'] ?? ''));
    $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
    $phone = trim((string) ($billing['phone'] ?? ''));
    $address = wc_order_import_format_address($billing);

    if ($wcCustomerId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE woocommerce_customer_id = ?');
        $stmt->execute([$wcCustomerId]);
        $existingId = $stmt->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }
    }

    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id, woocommerce_customer_id FROM customers WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) {
            if ($wcCustomerId > 0 && $existing['woocommerce_customer_id'] === null) {
                $pdo->prepare('UPDATE customers SET woocommerce_customer_id = ? WHERE id = ?')
                    ->execute([$wcCustomerId, (int) $existing['id']]);
            }

            return (int) $existing['id'];
        }
    }

    $stmt = $pdo->prepare('
        INSERT INTO customers (woocommerce_customer_id, name, email, phone, address)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $wcCustomerId > 0 ? $wcCustomerId : null,
        $name !== '' ? $name : 'WooCommerce Customer',
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
        $address !== '' ? $address : null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Resolves one WooCommerce line item to a Mewmii product/variation by SKU. Returns null when
 * the SKU doesn't match anything in the catalog - callers must skip and log the item rather
 * than failing the whole order, per the "never trust incoming data" requirement.
 */
function wc_order_import_resolve_line_item(PDO $pdo, array $lineItem): ?array
{
    $sku = trim((string) ($lineItem['sku'] ?? ''));
    if ($sku === '') {
        return null;
    }

    $variationStmt = $pdo->prepare('SELECT id AS variation_id, product_id, cost_price FROM product_variations WHERE sku = ?');
    $variationStmt->execute([$sku]);
    $variation = $variationStmt->fetch(PDO::FETCH_ASSOC);
    if ($variation !== false) {
        return [
            'product_id' => (int) $variation['product_id'],
            'variation_id' => (int) $variation['variation_id'],
            'cost_snapshot' => (float) ($variation['cost_price'] ?? 0),
        ];
    }

    $productStmt = $pdo->prepare('SELECT id, product_cost FROM products WHERE sku = ?');
    $productStmt->execute([$sku]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
    if ($product !== false) {
        return [
            'product_id' => (int) $product['id'],
            'variation_id' => null,
            'cost_snapshot' => (float) $product['product_cost'],
        ];
    }

    return null;
}

/**
 * Applies payment_status only in the upgrade direction (pending/failed -> paid -> refunded),
 * mirroring modules/orders/view.php's Approve Payment sequence exactly: payment_status write +
 * event log, then (only when newly paid) the same non-throwing partial stock reservation
 * Approve Payment uses, then order_recompute_status(). An equal or backward transition (e.g. a
 * re-poll of an order already paid) skips the payment_status write and reservation entirely,
 * but still recomputes order_status so a fresh insert's item/reservation state is reflected.
 */
function wc_order_import_apply_payment_upgrade(PDO $pdo, int $orderId, string $currentStatus, string $mappedStatus): void
{
    static $rank = ['pending' => 0, 'failed' => 0, 'paid' => 1, 'refunded' => 2];

    $isUpgrade = $mappedStatus !== $currentStatus && ($rank[$mappedStatus] ?? 0) > ($rank[$currentStatus] ?? 0);

    if ($isUpgrade) {
        $pdo->prepare('UPDATE mewmii_orders SET payment_status = ? WHERE id = ?')->execute([$mappedStatus, $orderId]);
        $pdo->prepare('
            INSERT INTO mewmii_order_events (order_id, event_type, description)
            VALUES (?, ?, ?)
        ')->execute([$orderId, 'payment_status_change', "Payment Status changed from '{$currentStatus}' to '{$mappedStatus}' (WooCommerce import)."]);

        if ($mappedStatus === 'paid') {
            inventory_reserve_for_order_partial($pdo, $orderId);
        }
    }

    order_recompute_status($pdo, $orderId);
}

/**
 * Imports or updates one WooCommerce order. Returns ['action' => 'created'|'updated'|'skipped',
 * 'order_id' => int|null, 'reason' => string|null].
 *
 * On update (existing woocommerce_order_id), only order_date and the financial totals are
 * overwritten, plus an upward-only payment_status transition - items and order_status are never
 * touched, so a re-poll can never undo manual work already done on the order in Mewmii OS.
 */
function wc_order_import_single(PDO $pdo, array $wcOrder): array
{
    $wcOrderId = (int) ($wcOrder['id'] ?? 0);
    if ($wcOrderId < 1) {
        return ['action' => 'skipped', 'order_id' => null, 'reason' => 'Missing WooCommerce order ID.'];
    }

    $wcStatus = (string) ($wcOrder['status'] ?? '');
    $mappedPaymentStatus = wc_order_import_map_payment_status($wcStatus);
    if ($mappedPaymentStatus === null) {
        return ['action' => 'skipped', 'order_id' => null, 'reason' => "WooCommerce order #{$wcOrderId}: status '{$wcStatus}' is not imported."];
    }

    $orderDateRaw = substr((string) ($wcOrder['date_created'] ?? ''), 0, 10);
    $orderDate = $orderDateRaw !== '' ? $orderDateRaw : null;
    $shippingFee = round((float) ($wcOrder['shipping_total'] ?? 0), 2);
    $discount = round((float) ($wcOrder['discount_total'] ?? 0), 2);
    $totalAmount = round((float) ($wcOrder['total'] ?? 0), 2);
    $subtotal = round($totalAmount - $shippingFee + $discount, 2);

    $receiptFields = wc_order_import_extract_receipt_fields($wcOrder);

    $existingStmt = $pdo->prepare('SELECT id, payment_status FROM mewmii_orders WHERE woocommerce_order_id = ?');
    $existingStmt->execute([$wcOrderId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing !== false) {
        $orderId = (int) $existing['id'];

        $pdo->prepare('
            UPDATE mewmii_orders
            SET order_date = ?, subtotal = ?, discount = ?, shipping_fee = ?, total_amount = ?,
                is_preorder_request = ?, receipt_url = ?, receipt_status = ?, receipt_reject_reason = ?
            WHERE id = ?
        ')->execute([
            $orderDate, $subtotal, $discount, $shippingFee, $totalAmount,
            $receiptFields['is_preorder_request'], $receiptFields['receipt_url'], $receiptFields['receipt_status'], $receiptFields['receipt_reject_reason'],
            $orderId,
        ]);

        wc_order_import_apply_payment_upgrade($pdo, $orderId, (string) $existing['payment_status'], $mappedPaymentStatus);

        return ['action' => 'updated', 'order_id' => $orderId, 'reason' => null];
    }

    $customerId = wc_order_import_match_customer($pdo, $wcOrder);

    $orderStmt = $pdo->prepare('
        INSERT INTO mewmii_orders (
            woocommerce_order_id, order_number, customer_id, subtotal, discount, shipping_fee, total_amount, order_date,
            is_preorder_request, receipt_url, receipt_status, receipt_reject_reason
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $orderStmt->execute([
        $wcOrderId, 'WC-' . $wcOrderId, $customerId, $subtotal, $discount, $shippingFee, $totalAmount, $orderDate,
        $receiptFields['is_preorder_request'], $receiptFields['receipt_url'], $receiptFields['receipt_status'], $receiptFields['receipt_reject_reason'],
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare('
        INSERT INTO mewmii_order_items (order_id, product_id, variation_id, variation_label, quantity, selling_price, discount, subtotal, cost_snapshot)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $skippedItems = [];
    foreach (($wcOrder['line_items'] ?? []) as $lineItem) {
        if (!is_array($lineItem)) {
            continue;
        }

        $resolved = wc_order_import_resolve_line_item($pdo, $lineItem);
        if ($resolved === null) {
            $skippedItems[] = trim((string) ($lineItem['sku'] ?? '(no sku)')) . ' - ' . trim((string) ($lineItem['name'] ?? 'unknown item'));
            continue;
        }

        $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));
        $lineTotal = round((float) ($lineItem['total'] ?? 0), 2);
        $unitPrice = round($lineTotal / $quantity, 2);
        $variationLabel = $resolved['variation_id'] !== null ? variation_build_label($pdo, $resolved['variation_id']) : null;

        $itemStmt->execute([
            $orderId,
            $resolved['product_id'],
            $resolved['variation_id'],
            $variationLabel,
            $quantity,
            $unitPrice,
            0.00,
            $lineTotal,
            $resolved['cost_snapshot'],
        ]);
    }

    $eventDescription = 'Imported from WooCommerce order #' . $wcOrderId . ' (status: ' . $wcStatus . ').';
    if ($skippedItems !== []) {
        $eventDescription .= ' Unresolved SKU(s), item(s) skipped: ' . implode('; ', $skippedItems) . '.';
    }
    $pdo->prepare('
        INSERT INTO mewmii_order_events (order_id, event_type, description)
        VALUES (?, ?, ?)
    ')->execute([$orderId, 'wc_import', $eventDescription]);

    wc_order_import_apply_payment_upgrade($pdo, $orderId, 'pending', $mappedPaymentStatus);

    return [
        'action' => 'created',
        'order_id' => $orderId,
        'reason' => $skippedItems !== [] ? ('Skipped item(s): ' . implode('; ', $skippedItems)) : null,
    ];
}

/**
 * Batch entrypoint for the manual "Import Orders Now" admin action (see
 * modules/integrations/woocommerce.php). Each order is imported inside its own transaction so
 * one bad order never rolls back the rest of the batch. Polling only - no webhook in Phase 1.
 */
function wc_order_import_run(PDO $pdo, int $limit = 20): array
{
    $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

    $orders = wc_client_get('orders', ['per_page' => $limit, 'orderby' => 'date', 'order' => 'desc']);

    foreach ($orders as $wcOrder) {
        if (!is_array($wcOrder)) {
            continue;
        }

        $wcOrderId = (int) ($wcOrder['id'] ?? 0);

        // =====================================================================================
        // TEMPORARY DEBUG LOGGING - added to diagnose why is_preorder_request/receipt_status/
        // receipt_url are coming back empty despite a successful import. Logs exactly what
        // WooCommerce's REST API returned for this order, before any Mewmii-side processing
        // touches it. Remove this whole block once the cause is confirmed - it is not part of
        // the import logic and does not affect it.
        // =====================================================================================
        $debugMetaFlat = [];
        foreach (($wcOrder['meta_data'] ?? []) as $debugMetaEntry) {
            if (is_array($debugMetaEntry) && isset($debugMetaEntry['key'])) {
                $debugMetaFlat[$debugMetaEntry['key']] = $debugMetaEntry['value'] ?? null;
            }
        }
        $debugMewmiiKeys = [];
        $debugPeproKeys = [];
        foreach ($debugMetaFlat as $debugKey => $debugValue) {
            if (strpos($debugKey, '_mewmii') === 0) {
                $debugMewmiiKeys[$debugKey] = $debugValue;
            }
            if (strpos($debugKey, '_pepro') === 0 || strpos($debugKey, 'pepro') === 0 || strpos($debugKey, 'peprodev') === 0) {
                $debugPeproKeys[$debugKey] = $debugValue;
            }
        }

        error_log('[Mewmii WC Import DEBUG] ===== Order #' . $wcOrderId . ' =====');
        error_log('[Mewmii WC Import DEBUG] Order #' . $wcOrderId . ' meta_data key count: ' . count($debugMetaFlat) . ' | keys: ' . implode(', ', array_keys($debugMetaFlat)));
        error_log('[Mewmii WC Import DEBUG] Order #' . $wcOrderId . ' _mewmii_* keys: ' . json_encode($debugMewmiiKeys));
        error_log('[Mewmii WC Import DEBUG] Order #' . $wcOrderId . ' pepro/_pepro/peprodev keys: ' . json_encode($debugPeproKeys));
        error_log('[Mewmii WC Import DEBUG] Order #' . $wcOrderId . ' receipt_upload_status: ' . (array_key_exists('receipt_upload_status', $debugMetaFlat) ? json_encode($debugMetaFlat['receipt_upload_status']) : '(key not present in meta_data)'));
        error_log('[Mewmii WC Import DEBUG] Order #' . $wcOrderId . ' full meta_data: ' . json_encode($debugMetaFlat));
        error_log('[Mewmii WC Import DEBUG] Order #' . $wcOrderId . ' full raw order payload: ' . json_encode($wcOrder));
        // ===================================================== END TEMPORARY DEBUG LOGGING ====

        $pdo->beginTransaction();
        try {
            $result = wc_order_import_single($pdo, $wcOrder);
            $pdo->commit();

            $summary[$result['action']]++;

            if ($result['action'] !== 'skipped') {
                sync_log_success($pdo, WC_ORDER_IMPORT_SYNC_TYPE, $result['order_id']);
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            $summary['failed']++;
            sync_log_failure($pdo, WC_ORDER_IMPORT_SYNC_TYPE, 'WooCommerce order #' . $wcOrderId . ': ' . $e->getMessage());
        }
    }

    return $summary;
}
