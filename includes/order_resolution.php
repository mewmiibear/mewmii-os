<?php

/**
 * Customer Order Resolution System - core business logic. Genuinely new (audited first: no
 * existing refund/replacement/wallet workflow exists anywhere in this app - only a plain
 * 'refunded' payment_status label with no process behind it).
 *
 * Deliberately keeps mewmii_orders/mewmii_order_items completely untouched - every existing
 * order page, WooCommerce sync, and report keeps working exactly as before. Resolution state
 * lives entirely in the new resolution_requests/resolution_items tables (see
 * database/migrate_order_resolution.php), joined against the existing order tables for display
 * only. This is the "keep resolution status separate" decision from the spec.
 *
 * Reuses, never duplicates: activity_log() for every audit entry, mewmii_notifications for
 * admin alerts, includes/job_queue.php for background email delivery, includes/product_
 * variations.php's variation_list_for_product()/variation_effective_price() for replacement
 * options, includes/receipt_storage.php for the upload/storage half of the payment-receipt
 * flow, includes/customer_wallet.php for store credit.
 *
 * resolution_items.status lifecycle (per item, independent of every other item in the same
 * resolution or order):
 *   pending -> (customer picks an action) ->
 *     replacement, same/negative price difference   -> resolved
 *     replacement, positive price difference          -> awaiting_payment -> awaiting_payment_verification -> resolved (or back to awaiting_payment on reject)
 *     replacement, price difference offered as credit/refund -> awaiting_difference_choice -> resolved (credit) or refund_pending -> resolved (refund)
 *     store_credit                                     -> resolved (credit applied immediately)
 *     refund                                            -> refund_pending -> resolved (once the refund record is completed)
 *
 * resolution_requests.status is DERIVED from its items (see resolution_recompute_status()) -
 * never set directly by a caller other than resolution_create()'s initial value.
 */

require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/customer_wallet.php';
require_once __DIR__ . '/receipt_storage.php';
require_once __DIR__ . '/job_queue.php';
require_once __DIR__ . '/product_variations.php';

const RESOLUTION_TOKEN_TTL_DAYS = 7;
const RESOLUTION_ITEM_ACTIONS = ['replacement', 'store_credit', 'refund'];
const CUSTOMER_NOTIFICATION_JOB_TYPE = 'customer.notification.send';

// --- Tokens -----------------------------------------------------------------------------------

/** 256 bits of entropy, same generation convention as app_csrf_token(). Never stored raw - see resolution_create(). */
function resolution_generate_raw_token(): string
{
    return bin2hex(random_bytes(32));
}

function resolution_hash_token(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

/**
 * Resolves a raw token (from the customer-facing URL) to its resolution_requests row. Logs
 * every attempt (success or failure) via activity_log() - satisfies "log customer actions" and
 * gives visibility into brute-force attempts, even though the 256-bit token space makes guessing
 * computationally infeasible on its own. Returns null for: not found, expired, or already
 * resolved past the point of further action (callers decide what "expired" should show).
 */
function resolution_find_by_token(PDO $pdo, string $rawToken): ?array
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || !ctype_xdigit($rawToken) || strlen($rawToken) !== 64) {
        activity_log($pdo, 'resolution', 'token_access_invalid_format', null, 'Malformed resolution token presented.');

        return null;
    }

    $hash = resolution_hash_token($rawToken);
    $stmt = $pdo->prepare('SELECT * FROM resolution_requests WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $resolution = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($resolution === false) {
        activity_log($pdo, 'resolution', 'token_access_not_found', null, 'Resolution token not found.');

        return null;
    }

    if (strtotime((string) $resolution['token_expires_at']) < time()) {
        activity_log($pdo, 'resolution', 'token_access_expired', (int) $resolution['id'], 'Resolution token expired.');

        return null;
    }

    activity_log($pdo, 'resolution', 'token_access', (int) $resolution['id'], 'Customer opened resolution link.');

    return $resolution;
}

// --- Admin: create -----------------------------------------------------------------------------

/**
 * Admin action (modules/orders/view.php) - creates one resolution request covering the given
 * order item ids. Item-level, not order-level (Part 1): only the selected items enter
 * resolution, every other item on the order is completely untouched. Rejects an item id that's
 * already covered by an active (unresolved) resolution - "prevent duplicate submissions" at the
 * admin-creation boundary.
 *
 * @return array{resolution: array, raw_token: string, url: string}
 */
function resolution_create(PDO $pdo, int $orderId, array $orderItemIds, string $reason, ?int $adminUserId): array
{
    $orderItemIds = array_values(array_unique(array_map('intval', $orderItemIds)));
    if ($orderItemIds === []) {
        throw new RuntimeException('Select at least one item to create a resolution request for.');
    }

    $placeholders = implode(',', array_fill(0, count($orderItemIds), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT oi.id, oi.order_id, oi.product_id, oi.variation_id, oi.variation_label, oi.selling_price
        FROM mewmii_order_items oi
        WHERE oi.id IN ({$placeholders}) AND oi.order_id = ?
    ");
    $itemsStmt->execute(array_merge($orderItemIds, [$orderId]));
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($items) !== count($orderItemIds)) {
        throw new RuntimeException('One or more selected items do not belong to this order.');
    }

    // Duplicate-submission guard - an order item already covered by an unresolved resolution
    // cannot be added to a second one.
    $activeStmt = $pdo->prepare("
        SELECT ri.order_item_id
        FROM resolution_items ri
        INNER JOIN resolution_requests rr ON rr.id = ri.resolution_id
        WHERE ri.order_item_id IN ({$placeholders}) AND rr.status <> 'resolved'
    ");
    $activeStmt->execute($orderItemIds);
    $alreadyActive = array_map('intval', $activeStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($alreadyActive !== []) {
        throw new RuntimeException('Item(s) already have an active resolution request in progress.');
    }

    $rawToken = resolution_generate_raw_token();
    $tokenHash = resolution_hash_token($rawToken);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + RESOLUTION_TOKEN_TTL_DAYS * 86400);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT INTO resolution_requests (order_id, status, reason, token_hash, token_expires_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$orderId, 'awaiting_customer_choice', $reason, $tokenHash, $expiresAt, $adminUserId]);
        $resolutionId = (int) $pdo->lastInsertId();

        $itemInsertStmt = $pdo->prepare('
            INSERT INTO resolution_items (resolution_id, order_item_id, original_variation_id, original_price, status)
            VALUES (?, ?, ?, ?, ?)
        ');
        foreach ($items as $item) {
            $itemInsertStmt->execute([
                $resolutionId,
                (int) $item['id'],
                $item['variation_id'] !== null ? (int) $item['variation_id'] : null,
                (float) $item['selling_price'],
                'pending',
            ]);
        }

        activity_log($pdo, 'resolution', 'created', $resolutionId, 'Resolution request created for order #' . $orderId . ' (' . count($items) . ' item(s)). Reason: ' . $reason);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $orderStmt = $pdo->prepare('SELECT order_number, customer_id FROM mewmii_orders WHERE id = ?');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    $url = resolution_customer_url($rawToken);

    // Admin notification (reuses mewmii_notifications - see includes/notifications.php).
    resolution_notify_admin($pdo, 'Resolution request created', 'Order ' . ($order['order_number'] ?? ('#' . $orderId)) . ': resolution request created for ' . count($items) . ' item(s).', $resolutionId);

    // Customer notification (new customer_notifications log + best-effort background email).
    if ($order !== false && $order['customer_id'] !== null) {
        resolution_notify_customer(
            $pdo,
            (int) $order['customer_id'],
            $orderId,
            $resolutionId,
            'resolution_required',
            'Action needed on your order',
            'One or more items in your order (' . ($order['order_number'] ?? ('#' . $orderId)) . ') need your input. Please choose an option: ' . $url
        );
    }

    $resolutionStmt = $pdo->prepare('SELECT * FROM resolution_requests WHERE id = ?');
    $resolutionStmt->execute([$resolutionId]);

    return [
        'resolution' => $resolutionStmt->fetch(PDO::FETCH_ASSOC),
        'raw_token' => $rawToken,
        'url' => $url,
    ];
}

function resolution_customer_url(string $rawToken): string
{
    return rtrim(app_base_url(), '/') . '/resolution.php?token=' . $rawToken;
}

// --- Reads ---------------------------------------------------------------------------------

function resolution_get(PDO $pdo, int $resolutionId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM resolution_requests WHERE id = ?');
    $stmt->execute([$resolutionId]);
    $resolution = $stmt->fetch(PDO::FETCH_ASSOC);

    return $resolution !== false ? $resolution : null;
}

/** Every resolution_items row for a resolution, enriched with the order item's product/label and any receipt. */
function resolution_list_items(PDO $pdo, int $resolutionId): array
{
    $stmt = $pdo->prepare('
        SELECT ri.*, oi.product_id, oi.quantity, oi.variation_label AS order_item_variation_label, p.name AS product_name, p.sku
        FROM resolution_items ri
        INNER JOIN mewmii_order_items oi ON oi.id = ri.order_item_id
        INNER JOIN products p ON p.id = oi.product_id
        WHERE ri.resolution_id = ?
        ORDER BY ri.id ASC
    ');
    $stmt->execute([$resolutionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $receiptStmt = $pdo->prepare('SELECT * FROM payment_receipts WHERE resolution_id = ? ORDER BY id DESC LIMIT 1');
        $receiptStmt->execute([$resolutionId]);
        $row['latest_receipt'] = $receiptStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $refundStmt = $pdo->prepare('SELECT * FROM resolution_refunds WHERE resolution_item_id = ? ORDER BY id DESC LIMIT 1');
        $refundStmt->execute([(int) $row['id']]);
        $row['refund'] = $refundStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    unset($row);

    return $rows;
}

function resolution_list_for_order(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM resolution_requests WHERE order_id = ? ORDER BY id DESC');
    $stmt->execute([$orderId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Every order_item_id currently covered by an unresolved resolution, for this order - lets the order page hide "Create Resolution" for items already in progress. */
function resolution_active_order_item_ids(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare("
        SELECT ri.order_item_id
        FROM resolution_items ri
        INNER JOIN resolution_requests rr ON rr.id = ri.resolution_id
        WHERE rr.order_id = ? AND rr.status <> 'resolved'
    ");
    $stmt->execute([$orderId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Replacement candidates for one resolution item - every non-archived variation of the SAME
 * product, excluding the item's current variation, with its effective price (reuses
 * variation_effective_price() unchanged). Simple (non-variable) products have nothing to offer
 * here - an empty list means the customer's only options are store credit or refund.
 */
function resolution_available_replacement_variations(PDO $pdo, int $productId, ?int $excludeVariationId): array
{
    $productStmt = $pdo->prepare('SELECT selling_price, catalog_type FROM products WHERE id = ?');
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
    if ($product === false || $product['catalog_type'] !== 'variable') {
        return [];
    }

    $variations = variation_list_for_product($pdo, $productId);
    $options = [];
    foreach ($variations as $variation) {
        if ($variation['status'] === 'archived' || (int) $variation['id'] === (int) $excludeVariationId) {
            continue;
        }
        $options[] = [
            'id' => (int) $variation['id'],
            'label' => $variation['label'],
            'price' => variation_effective_price($variation, $product['selling_price']),
            'available_quantity' => (int) $variation['available_quantity'],
        ];
    }

    return $options;
}

// --- Item status transitions -----------------------------------------------------------------

function resolution_item_get(PDO $pdo, int $resolutionItemId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM resolution_items WHERE id = ?');
    $stmt->execute([$resolutionItemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * Customer picks a replacement variation. Branches on the price difference (Part 5):
 *   0            -> confirmed immediately, no payment/credit step.
 *   positive     -> awaiting_payment (customer must pay the difference and upload a receipt).
 *   negative     -> awaiting_difference_choice (customer separately picks credit or refund for
 *                    the difference via resolution_customer_choose_difference_credit/_refund()).
 * Idempotent against a re-submit: a resolution item whose chosen_action is already set is
 * rejected, not silently overwritten - "prevent duplicate submissions".
 */
function resolution_customer_choose_replacement(PDO $pdo, int $resolutionItemId, int $replacementVariationId): array
{
    $item = resolution_item_get($pdo, $resolutionItemId);
    if ($item === null || $item['status'] !== 'pending') {
        throw new RuntimeException('This item has already been resolved or is no longer awaiting your choice.');
    }

    $orderItemStmt = $pdo->prepare('SELECT product_id FROM mewmii_order_items WHERE id = ?');
    $orderItemStmt->execute([(int) $item['order_item_id']]);
    $productId = (int) $orderItemStmt->fetchColumn();

    $options = resolution_available_replacement_variations($pdo, $productId, (int) ($item['original_variation_id'] ?? 0));
    $chosen = null;
    foreach ($options as $option) {
        if ($option['id'] === $replacementVariationId) {
            $chosen = $option;
            break;
        }
    }
    if ($chosen === null) {
        throw new RuntimeException('That variation is not a valid replacement option for this item.');
    }

    $originalPrice = (float) $item['original_price'];
    $difference = round($chosen['price'] - $originalPrice, 2);

    $newStatus = 'resolved';
    if ($difference > 0) {
        $newStatus = 'awaiting_payment';
    } elseif ($difference < 0) {
        $newStatus = 'awaiting_difference_choice';
    }

    $pdo->prepare('
        UPDATE resolution_items
        SET chosen_action = ?, replacement_variation_id = ?, replacement_price = ?, price_difference = ?, status = ?
        WHERE id = ?
    ')->execute(['replacement', $replacementVariationId, $chosen['price'], $difference, $newStatus, $resolutionItemId]);

    activity_log($pdo, 'resolution', 'customer_chose_replacement', (int) $item['resolution_id'], 'Customer chose replacement variation #' . $replacementVariationId . ' (difference RM' . number_format($difference, 2) . ') for resolution item #' . $resolutionItemId . '.');

    resolution_recompute_status($pdo, (int) $item['resolution_id']);

    [$customerId, $orderId, $orderNumber] = resolution_item_customer_context($pdo, (int) $item['resolution_id']);

    if ($difference === 0.0) {
        resolution_notify_admin($pdo, 'Customer selected replacement', 'Order ' . $orderNumber . ': customer confirmed a same-price replacement.', (int) $item['resolution_id']);
        resolution_notify_customer($pdo, $customerId, $orderId, (int) $item['resolution_id'], 'replacement_confirmed', 'Replacement confirmed', 'Your replacement item has been confirmed - no extra payment needed.');
    } elseif ($difference > 0) {
        resolution_notify_admin($pdo, 'Customer selected replacement (payment required)', 'Order ' . $orderNumber . ': customer chose a replacement RM' . number_format($difference, 2) . ' more expensive.', (int) $item['resolution_id']);
        resolution_notify_customer($pdo, $customerId, $orderId, (int) $item['resolution_id'], 'payment_required', 'Additional payment required', 'Your replacement choice needs an additional payment of RM' . number_format($difference, 2) . '. Please upload your payment receipt on your resolution page.');
    } else {
        resolution_notify_admin($pdo, 'Customer selected replacement (credit/refund due)', 'Order ' . $orderNumber . ': customer chose a replacement RM' . number_format(abs($difference), 2) . ' cheaper - awaiting their credit/refund choice.', (int) $item['resolution_id']);
    }

    return resolution_item_get($pdo, $resolutionItemId);
}

/** Customer's chosen way to receive a NEGATIVE replacement price difference: store credit. */
function resolution_customer_choose_difference_credit(PDO $pdo, int $resolutionItemId): void
{
    $item = resolution_item_get($pdo, $resolutionItemId);
    if ($item === null || $item['status'] !== 'awaiting_difference_choice') {
        throw new RuntimeException('This item is not awaiting a credit/refund choice.');
    }

    $amount = abs((float) $item['price_difference']);
    [$customerId, $orderId, $orderNumber] = resolution_item_customer_context($pdo, (int) $item['resolution_id']);

    $pdo->beginTransaction();
    try {
        wallet_credit($pdo, $customerId, $amount, 'resolution_item', $resolutionItemId, 'Price difference credit - order ' . $orderNumber);
        $pdo->prepare("UPDATE resolution_items SET status = 'resolved' WHERE id = ?")->execute([$resolutionItemId]);
        activity_log($pdo, 'resolution', 'difference_credited', (int) $item['resolution_id'], 'Price difference of RM' . number_format($amount, 2) . ' credited to wallet for resolution item #' . $resolutionItemId . '.');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    resolution_recompute_status($pdo, (int) $item['resolution_id']);
    resolution_notify_customer($pdo, $customerId, $orderId, (int) $item['resolution_id'], 'store_credit_added', 'Store credit added', 'RM' . number_format($amount, 2) . ' has been added to your store credit wallet.');
}

/** Customer's chosen way to receive a NEGATIVE replacement price difference: cash refund. */
function resolution_customer_choose_difference_refund(PDO $pdo, int $resolutionItemId): void
{
    $item = resolution_item_get($pdo, $resolutionItemId);
    if ($item === null || $item['status'] !== 'awaiting_difference_choice') {
        throw new RuntimeException('This item is not awaiting a credit/refund choice.');
    }

    $amount = abs((float) $item['price_difference']);
    resolution_create_refund_record($pdo, $item, $amount, 'Price difference refund');
}

/** Customer picks store credit for the FULL item price (Option B - not a replacement). */
function resolution_customer_choose_store_credit(PDO $pdo, int $resolutionItemId): void
{
    $item = resolution_item_get($pdo, $resolutionItemId);
    if ($item === null || $item['status'] !== 'pending') {
        throw new RuntimeException('This item has already been resolved or is no longer awaiting your choice.');
    }

    $amount = (float) $item['original_price'];
    [$customerId, $orderId, $orderNumber] = resolution_item_customer_context($pdo, (int) $item['resolution_id']);

    $pdo->beginTransaction();
    try {
        wallet_credit($pdo, $customerId, $amount, 'resolution_item', $resolutionItemId, 'Store credit - order ' . $orderNumber);
        $pdo->prepare("UPDATE resolution_items SET chosen_action = 'store_credit', status = 'resolved' WHERE id = ?")->execute([$resolutionItemId]);
        activity_log($pdo, 'resolution', 'customer_chose_store_credit', (int) $item['resolution_id'], 'Customer chose store credit (RM' . number_format($amount, 2) . ') for resolution item #' . $resolutionItemId . '.');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    resolution_recompute_status($pdo, (int) $item['resolution_id']);
    resolution_notify_admin($pdo, 'Customer selected store credit', 'Order ' . $orderNumber . ': customer chose store credit for RM' . number_format($amount, 2) . '.', (int) $item['resolution_id']);
    resolution_notify_customer($pdo, $customerId, $orderId, (int) $item['resolution_id'], 'store_credit_added', 'Store credit added', 'RM' . number_format($amount, 2) . ' has been added to your store credit wallet.');
}

/** Customer picks a cash refund for the FULL item price (Option C - not a replacement). */
function resolution_customer_choose_refund(PDO $pdo, int $resolutionItemId): void
{
    $item = resolution_item_get($pdo, $resolutionItemId);
    if ($item === null || $item['status'] !== 'pending') {
        throw new RuntimeException('This item has already been resolved or is no longer awaiting your choice.');
    }

    $pdo->prepare("UPDATE resolution_items SET chosen_action = 'refund' WHERE id = ?")->execute([$resolutionItemId]);
    resolution_create_refund_record($pdo, $item, (float) $item['original_price'], 'Customer requested refund');
}

/** Shared "create a refund_pending resolution_refunds row" step used by both refund paths above. */
function resolution_create_refund_record(PDO $pdo, array $item, float $amount, string $note): void
{
    [$customerId, $orderId, $orderNumber] = resolution_item_customer_context($pdo, (int) $item['resolution_id']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT INTO resolution_refunds (resolution_id, resolution_item_id, order_id, customer_id, amount, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([(int) $item['resolution_id'], (int) $item['id'], $orderId, $customerId, $amount, 'pending', $note]);

        $pdo->prepare("UPDATE resolution_items SET status = 'refund_pending' WHERE id = ?")->execute([(int) $item['id']]);
        activity_log($pdo, 'resolution', 'refund_requested', (int) $item['resolution_id'], 'Refund of RM' . number_format($amount, 2) . ' requested for resolution item #' . $item['id'] . ' (' . $note . ').');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    resolution_recompute_status($pdo, (int) $item['resolution_id']);
    resolution_notify_admin($pdo, 'Customer requested refund', 'Order ' . $orderNumber . ': refund of RM' . number_format($amount, 2) . ' requested.', (int) $item['resolution_id']);
}

/** [customerId, orderId, orderNumber] for a resolution - small shared lookup used by several functions above. */
function resolution_item_customer_context(PDO $pdo, int $resolutionId): array
{
    $stmt = $pdo->prepare('
        SELECT o.id AS order_id, o.order_number, o.customer_id
        FROM resolution_requests rr
        INNER JOIN mewmii_orders o ON o.id = rr.order_id
        WHERE rr.id = ?
    ');
    $stmt->execute([$resolutionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new RuntimeException('Resolution not found.');
    }

    return [(int) $row['customer_id'], (int) $row['order_id'], (string) $row['order_number']];
}

// --- Payment receipt upload + verification -----------------------------------------------------

/**
 * Customer uploads a receipt for an item's additional payment. Ownership is enforced by the
 * caller (resolution.php only ever passes an item that belongs to the already-token-verified
 * resolution). "Prevent duplicate submissions": rejected if a receipt for this item is already
 * 'pending' verification - the customer must wait for admin action (approve/reject) before
 * uploading again, matching Part 9's reject-then-reupload flow rather than letting pending
 * receipts pile up.
 */
function resolution_upload_receipt(PDO $pdo, int $resolutionItemId, array $file): array
{
    $item = resolution_item_get($pdo, $resolutionItemId);
    if ($item === null || $item['status'] !== 'awaiting_payment') {
        throw new RuntimeException('This item is not currently awaiting a payment receipt.');
    }

    $existingStmt = $pdo->prepare("SELECT COUNT(*) FROM payment_receipts WHERE resolution_id = ? AND status = 'pending'");
    $existingStmt->execute([(int) $item['resolution_id']]);
    if ((int) $existingStmt->fetchColumn() > 0) {
        throw new RuntimeException('A receipt is already awaiting review for this item.');
    }

    $stored = receipt_upload_validate_and_store($file);
    [$customerId, $orderId, $orderNumber] = resolution_item_customer_context($pdo, (int) $item['resolution_id']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT INTO payment_receipts (resolution_id, order_id, customer_id, amount, file_path, file_name, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([(int) $item['resolution_id'], $orderId, $customerId, (float) $item['price_difference'], $stored['file_path'], $stored['file_name'], 'pending']);
        $receiptId = (int) $pdo->lastInsertId();

        $pdo->prepare("UPDATE resolution_items SET status = 'awaiting_payment_verification' WHERE id = ?")->execute([$resolutionItemId]);
        activity_log($pdo, 'resolution', 'receipt_uploaded', (int) $item['resolution_id'], 'Payment receipt uploaded for resolution item #' . $resolutionItemId . '.');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    resolution_recompute_status($pdo, (int) $item['resolution_id']);
    resolution_notify_admin($pdo, 'Customer uploaded payment receipt', 'Order ' . $orderNumber . ': payment receipt uploaded, awaiting verification.', (int) $item['resolution_id']);
    resolution_notify_customer($pdo, $customerId, $orderId, (int) $item['resolution_id'], 'receipt_received', 'Receipt received', 'We received your payment receipt and will verify it shortly.');

    return ['receipt_id' => $receiptId];
}

function resolution_admin_approve_payment(PDO $pdo, int $receiptId, int $adminUserId): void
{
    $receiptStmt = $pdo->prepare('SELECT * FROM payment_receipts WHERE id = ?');
    $receiptStmt->execute([$receiptId]);
    $receipt = $receiptStmt->fetch(PDO::FETCH_ASSOC);
    if ($receipt === false || $receipt['status'] !== 'pending') {
        throw new RuntimeException('This receipt is not awaiting verification.');
    }

    $itemStmt = $pdo->prepare("SELECT * FROM resolution_items WHERE resolution_id = ? AND status = 'awaiting_payment_verification' LIMIT 1");
    $itemStmt->execute([(int) $receipt['resolution_id']]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payment_receipts SET status = 'approved', verified_by = ?, verified_at = NOW() WHERE id = ?")
            ->execute([$adminUserId, $receiptId]);
        if ($item !== false) {
            $pdo->prepare("UPDATE resolution_items SET status = 'resolved' WHERE id = ?")->execute([(int) $item['id']]);
        }
        activity_log($pdo, 'resolution', 'payment_approved', (int) $receipt['resolution_id'], 'Payment receipt #' . $receiptId . ' approved.');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    resolution_recompute_status($pdo, (int) $receipt['resolution_id']);
    [$customerId, $orderId, $orderNumber] = resolution_item_customer_context($pdo, (int) $receipt['resolution_id']);
    resolution_notify_customer($pdo, $customerId, $orderId, (int) $receipt['resolution_id'], 'payment_approved', 'Payment approved', 'Your payment for order ' . $orderNumber . ' has been verified. Your replacement is confirmed.');
}

function resolution_admin_reject_payment(PDO $pdo, int $receiptId, int $adminUserId, ?string $reason): void
{
    $receiptStmt = $pdo->prepare('SELECT * FROM payment_receipts WHERE id = ?');
    $receiptStmt->execute([$receiptId]);
    $receipt = $receiptStmt->fetch(PDO::FETCH_ASSOC);
    if ($receipt === false || $receipt['status'] !== 'pending') {
        throw new RuntimeException('This receipt is not awaiting verification.');
    }

    $itemStmt = $pdo->prepare("SELECT * FROM resolution_items WHERE resolution_id = ? AND status = 'awaiting_payment_verification' LIMIT 1");
    $itemStmt->execute([(int) $receipt['resolution_id']]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payment_receipts SET status = 'rejected', verified_by = ?, verified_at = NOW() WHERE id = ?")
            ->execute([$adminUserId, $receiptId]);
        if ($item !== false) {
            // Back to awaiting_payment - the customer can upload again (the pending-receipt
            // guard in resolution_upload_receipt() only blocks a second PENDING receipt, and
            // this one is no longer pending).
            $pdo->prepare("UPDATE resolution_items SET status = 'awaiting_payment' WHERE id = ?")->execute([(int) $item['id']]);
        }
        activity_log($pdo, 'resolution', 'payment_rejected', (int) $receipt['resolution_id'], 'Payment receipt #' . $receiptId . ' rejected' . ($reason !== null && $reason !== '' ? (': ' . $reason) : '.'));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    resolution_recompute_status($pdo, (int) $receipt['resolution_id']);
    [$customerId, $orderId, $orderNumber] = resolution_item_customer_context($pdo, (int) $receipt['resolution_id']);
    resolution_notify_customer($pdo, $customerId, $orderId, (int) $receipt['resolution_id'], 'payment_required', 'Payment receipt rejected', 'Your payment receipt for order ' . $orderNumber . ' could not be verified' . ($reason !== null && $reason !== '' ? (' (' . $reason . ')') : '') . '. Please upload it again on your resolution page.');
}

// --- Refund management (admin) -----------------------------------------------------------------

const RESOLUTION_REFUND_STATUSES = ['pending', 'approved', 'completed', 'rejected'];

/** Admin moves a refund through pending -> approved -> completed, or -> rejected at any point before completed. */
function resolution_admin_update_refund_status(PDO $pdo, int $refundId, string $newStatus, int $adminUserId, ?string $notes): void
{
    if (!in_array($newStatus, RESOLUTION_REFUND_STATUSES, true)) {
        throw new RuntimeException('Invalid refund status.');
    }

    $refundStmt = $pdo->prepare('SELECT * FROM resolution_refunds WHERE id = ?');
    $refundStmt->execute([$refundId]);
    $refund = $refundStmt->fetch(PDO::FETCH_ASSOC);
    if ($refund === false) {
        throw new RuntimeException('Refund not found.');
    }
    if (in_array($refund['status'], ['completed', 'rejected'], true)) {
        throw new RuntimeException('This refund has already been finalized.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE resolution_refunds SET status = ?, notes = COALESCE(?, notes), processed_by = ?, processed_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $notes, $adminUserId, $refundId]);

        if ($newStatus === 'completed' && $refund['resolution_item_id'] !== null) {
            $pdo->prepare("UPDATE resolution_items SET status = 'resolved' WHERE id = ?")->execute([(int) $refund['resolution_item_id']]);
        } elseif ($newStatus === 'rejected' && $refund['resolution_item_id'] !== null) {
            $pdo->prepare("UPDATE resolution_items SET status = 'pending' WHERE id = ?")->execute([(int) $refund['resolution_item_id']]);
        }

        activity_log($pdo, 'resolution', 'refund_status_' . $newStatus, (int) $refund['resolution_id'], 'Refund #' . $refundId . ' set to ' . $newStatus . '.');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    resolution_recompute_status($pdo, (int) $refund['resolution_id']);

    if ($newStatus === 'completed') {
        [$customerId, $orderId, $orderNumber] = resolution_item_customer_context($pdo, (int) $refund['resolution_id']);
        resolution_notify_customer($pdo, $customerId, $orderId, (int) $refund['resolution_id'], 'refund_processed', 'Refund processed', 'Your refund of RM' . number_format((float) $refund['amount'], 2) . ' for order ' . $orderNumber . ' has been processed.');
    }
}

// --- Status recomputation --------------------------------------------------------------------

const RESOLUTION_ITEM_STATUS_PRIORITY = [
    'pending' => 'awaiting_customer_choice',
    'awaiting_difference_choice' => 'awaiting_customer_choice',
    'awaiting_payment' => 'awaiting_payment',
    'awaiting_payment_verification' => 'awaiting_payment_verification',
    'refund_pending' => 'refund_pending',
];

/**
 * Derives resolution_requests.status from its items - mirrors order_recompute_status()'s own
 * "always computed, never set directly by a form" pattern. Priority order (first match across
 * all items wins, most-blocking first): still needs a customer choice > awaiting payment >
 * awaiting payment verification > refund pending > everything resolved.
 */
function resolution_recompute_status(PDO $pdo, int $resolutionId): void
{
    $stmt = $pdo->prepare('SELECT status FROM resolution_items WHERE resolution_id = ?');
    $stmt->execute([$resolutionId]);
    $itemStatuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($itemStatuses === []) {
        return;
    }

    $newStatus = 'resolved';
    foreach (['pending', 'awaiting_difference_choice', 'awaiting_payment', 'awaiting_payment_verification', 'refund_pending'] as $blockingStatus) {
        if (in_array($blockingStatus, $itemStatuses, true)) {
            $newStatus = RESOLUTION_ITEM_STATUS_PRIORITY[$blockingStatus] ?? $blockingStatus;
            break;
        }
    }

    $resolvedAtSql = $newStatus === 'resolved' ? 'resolved_at = COALESCE(resolved_at, NOW())' : 'resolved_at = NULL';
    $pdo->prepare("UPDATE resolution_requests SET status = ?, {$resolvedAtSql} WHERE id = ?")->execute([$newStatus, $resolutionId]);
}

// --- Order financial summary (display only - never writes to mewmii_orders) --------------------

/**
 * Read-only summary for the order page: original total (unchanged, from mewmii_orders itself),
 * total refunded so far (completed resolution_refunds tied to this order), and the resulting
 * adjusted total. Never writes to mewmii_orders.total_amount - that column stays the original,
 * historical, WooCommerce-synced figure; this is purely a display computation layered on top.
 */
function resolution_order_financial_summary(PDO $pdo, int $orderId): array
{
    $orderStmt = $pdo->prepare('SELECT total_amount FROM mewmii_orders WHERE id = ?');
    $orderStmt->execute([$orderId]);
    $originalTotal = (float) $orderStmt->fetchColumn();

    $refundedStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM resolution_refunds WHERE order_id = ? AND status = 'completed'");
    $refundedStmt->execute([$orderId]);
    $totalRefunded = (float) $refundedStmt->fetchColumn();

    return [
        'original_total' => $originalTotal,
        'total_refunded' => $totalRefunded,
        'adjusted_total' => round($originalTotal - $totalRefunded, 2),
    ];
}

// --- Notifications --------------------------------------------------------------------------

/**
 * Admin notification - reuses mewmii_notifications directly (the SAME table/UI every other
 * staff alert already uses - modules/notifications/index.php, the dashboard summary). type
 * 'resolution' is new but the table itself needed no schema change (type is a plain VARCHAR).
 */
function resolution_notify_admin(PDO $pdo, string $title, string $message, int $resolutionId): void
{
    $pdo->prepare('INSERT INTO mewmii_notifications (title, message, type, reference_id) VALUES (?, ?, ?, ?)')
        ->execute([$title, $message, 'resolution', $resolutionId]);
}

/**
 * Customer notification - no existing customer-facing notification/email system to reuse (audit
 * confirmed this), so this is the one genuinely new piece: a log row plus a queued background
 * job (includes/job_queue.php, Phase 11A - the "existing outbound_jobs queue where background
 * processing is needed" the spec asks for) that attempts best-effort delivery. See
 * cli/job_worker.php's handler for the delivery attempt itself and its own honesty caveat about
 * PHP's built-in mail().
 */
function resolution_notify_customer(PDO $pdo, int $customerId, ?int $orderId, ?int $resolutionId, string $type, string $title, string $message): void
{
    $pdo->prepare('
        INSERT INTO customer_notifications (customer_id, order_id, resolution_id, type, title, message)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([$customerId, $orderId, $resolutionId, $type, $title, $message]);
    $notificationId = (int) $pdo->lastInsertId();

    job_enqueue($pdo, CUSTOMER_NOTIFICATION_JOB_TYPE, 'customer_notification', $notificationId);
}

function customer_notifications_list(PDO $pdo, int $customerId, int $limit = 50): array
{
    $stmt = $pdo->prepare('SELECT * FROM customer_notifications WHERE customer_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit));
    $stmt->execute([$customerId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The job handler for CUSTOMER_NOTIFICATION_JOB_TYPE, registered in cli/job_worker.php.
 * Honesty note (this app has no existing email infrastructure - confirmed by audit, nothing to
 * reuse): this uses PHP's built-in mail() as a functional-but-basic default. mail() depends on
 * the server having a working local MTA (sendmail/postfix) configured, and delivery is often
 * unreliable/spam-flagged on shared hosting without SPF/DKIM set up for the sending domain. For
 * production reliability, replace the body of this function with a real transactional email
 * provider call (Postmark/SES/Sendgrid/etc.) - every OTHER part of the resolution system
 * (the resolution link itself, the notification record, the admin-visible link on the order
 * page) works independently of whether this specific delivery succeeds, since the admin can
 * always copy/share the link manually as a fallback (see modules/orders/view.php).
 *
 * Never throws on a delivery failure (mail() failing is common/expected in many environments) -
 * only throws for a genuinely missing/malformed notification row, which SHOULD retry.
 */
function resolution_send_customer_notification_job(array $job, PDO $pdo): void
{
    $notificationId = (int) ($job['entity_id'] ?? 0);
    if ($notificationId < 1) {
        throw new RuntimeException('Job #' . $job['id'] . ' has no valid customer_notification entity_id.');
    }

    $stmt = $pdo->prepare('
        SELECT cn.*, c.email AS customer_email, c.name AS customer_name
        FROM customer_notifications cn
        INNER JOIN customers c ON c.id = cn.customer_id
        WHERE cn.id = ?
    ');
    $stmt->execute([$notificationId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($notification === false) {
        throw new RuntimeException('Customer notification #' . $notificationId . ' not found.');
    }

    if ($notification['sent_at'] !== null) {
        return; // already sent by a prior attempt - idempotent no-op, not an error.
    }

    $email = trim((string) ($notification['customer_email'] ?? ''));
    if ($email !== '' && function_exists('mail')) {
        $subject = '[Mewmii Bear] ' . $notification['title'];
        $body = "Hi " . ($notification['customer_name'] ?? 'there') . ",\n\n" . $notification['message'] . "\n\nThank you,\nMewmii Bear";
        $headers = 'Content-Type: text/plain; charset=UTF-8';

        // Best-effort - failure here is common on unconfigured hosting and must never fail the
        // job permanently or block anything else; the notification row and the admin-visible
        // link remain the reliable fallback regardless of email deliverability.
        @mail($email, $subject, $body, $headers);
    }

    $pdo->prepare('UPDATE customer_notifications SET sent_at = NOW() WHERE id = ?')->execute([$notificationId]);
}
