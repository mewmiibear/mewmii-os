<?php

/**
 * WooCommerce -> Mewmii OS order import. Delta-based (see wc_order_import_run()'s docblock) -
 * still manual, admin-triggered only for now (Sync Automation Phase 1: no cron/webhook yet).
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

// Reuses the existing, previously-unused generic `settings` key-value table - no schema
// change needed for these. LAST_SYNCED_AT is the delta cursor (only ever advanced after a
// full run succeeds - see wc_order_import_run()); LAST_RUN_SUMMARY is display-only stats for
// modules/integrations/woocommerce.php, updated after every run attempt regardless of outcome
// so staff can see "did this actually run" separately from "how far does coverage reach".
const WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT = 'wc_order_import_last_synced_at';
const WC_ORDER_IMPORT_SETTING_LAST_RUN_SUMMARY = 'wc_order_import_last_run_summary';

// Orders per WooCommerce REST page, and a hard ceiling on how many pages one run will walk.
// The ceiling exists for the very first run (no stored cursor yet -> fetches full order
// history) and for any unexpectedly large backlog - it protects shared hosting from an
// unbounded request chain. Hitting it does NOT advance the cursor (see wc_order_import_run()),
// so a re-run simply continues instead of silently losing whatever was past the ceiling.
const WC_ORDER_IMPORT_PAGE_SIZE = 50;
const WC_ORDER_IMPORT_MAX_PAGES = 25;

// MySQL advisory lock name (Sync Automation Phase 4A) - server-wide, so it's deliberately
// specific rather than a generic "sync" name to avoid ever colliding with anything else on
// shared hosting. Prevents cron and the manual "Import Orders Now" button (or two overlapping
// cron ticks) from ever running wc_order_import_single() against the same orders at once.
const WC_ORDER_IMPORT_LOCK_NAME = 'mewmii_wc_order_import';

// Distinct RuntimeException code for "another sync is already running" (see
// wc_order_import_run() below) - lets callers (cli/wc_order_sync.php) tell this benign,
// expected condition apart from a real failure without string-matching the message.
const WC_ORDER_IMPORT_LOCK_BUSY_CODE = 42301;

// Sync health thresholds in minutes, measured against the delta cursor (only ever advanced
// after a fully successful run - see wc_order_import_run()'s docblock), used by
// wc_order_import_sync_health() below.
const WC_ORDER_IMPORT_HEALTH_WARNING_MINUTES = 30;
const WC_ORDER_IMPORT_HEALTH_CRITICAL_MINUTES = 120;

/**
 * Thin key-value helpers over the `settings` table - generic and app-wide, but this is
 * currently their only caller. `INSERT ... ON DUPLICATE KEY UPDATE` relies on
 * settings.setting_key being UNIQUE (see database/schema.sql), so this is always a single
 * upsert, never a duplicate row.
 */
function wc_order_import_get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : null;
}

function wc_order_import_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('
        INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ')->execute([$key, $value]);
}

/**
 * Time-based sync health (Sync Automation Phase 4A) - purely a read of how long ago the delta
 * cursor last advanced, measured against WC_ORDER_IMPORT_HEALTH_WARNING_MINUTES/
 * _CRITICAL_MINUTES. Deliberately independent of sync_logs' per-order success/failure counts
 * (a different, already-visible signal on both callers of this function) - this answers "is
 * the sync loop itself still running on schedule", not "did every order import cleanly".
 * Pure function, no DB access - callers pass in whatever they already read via
 * wc_order_import_get_setting(WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT), so this is never a
 * second query for the same value.
 *
 * Returns ['level' => 'unknown'|'healthy'|'warning'|'critical', 'message' => string,
 * 'minutes_ago' => int|null].
 */
function wc_order_import_sync_health(?string $lastSyncedAtGmt): array
{
    if ($lastSyncedAtGmt === null || $lastSyncedAtGmt === '') {
        return [
            'level' => 'unknown',
            'message' => 'No successful sync has completed yet.',
            'minutes_ago' => null,
        ];
    }

    try {
        $lastSynced = new DateTime($lastSyncedAtGmt, new DateTimeZone('UTC'));
    } catch (Exception) {
        return [
            'level' => 'unknown',
            'message' => 'Sync cursor value could not be read.',
            'minutes_ago' => null,
        ];
    }

    $now = new DateTime('now', new DateTimeZone('UTC'));
    // Guards against clock skew between the PHP server and whatever wrote the stored
    // timestamp (should never happen - both are written by this same codebase - but a
    // negative "minutes ago" would be a confusing thing to display either way).
    $minutesAgo = max(0, (int) floor(($now->getTimestamp() - $lastSynced->getTimestamp()) / 60));

    if ($minutesAgo > WC_ORDER_IMPORT_HEALTH_CRITICAL_MINUTES) {
        $level = 'critical';
        $message = 'Sync has not completed for more than 2 hours (last completed ' . $minutesAgo . ' minutes ago).';
    } elseif ($minutesAgo > WC_ORDER_IMPORT_HEALTH_WARNING_MINUTES) {
        $level = 'warning';
        $message = 'Sync has not completed for more than 30 minutes (last completed ' . $minutesAgo . ' minutes ago).';
    } else {
        $level = 'healthy';
        $message = 'Last sync completed ' . $minutesAgo . ' minute' . ($minutesAgo === 1 ? '' : 's') . ' ago.';
    }

    return ['level' => $level, 'message' => $message, 'minutes_ago' => $minutesAgo];
}

/**
 * Reads one key out of a WooCommerce REST order's meta_data array
 * ([['id' => .., 'key' => .., 'value' => ..], ...]). Returns null if the key isn't present -
 * covers both "WooCommerce never exposed it" and "this order genuinely has no value yet".
 *
 * AUDIT NOTE: this operates purely on the already-fetched REST response array. There is no
 * $order object here, no get_post_meta(), and no direct SQL - this function runs inside Mewmii
 * OS's own PHP process, which has no WordPress bootstrap at all. Whatever WooCommerce's REST
 * API included in meta_data for this order - private "_"-prefixed keys and normal keys alike,
 * WooCommerce's own order REST controller does not apply WordPress core's is_protected_meta()
 * hiding to order meta_data - is the entire universe this function can see. HPOS vs legacy
 * postmeta storage is irrelevant here too, for the same reason: that distinction only matters
 * on the WordPress side, before the HTTP response was ever built.
 *
 * If the same key appears more than once in meta_data, the last non-empty match wins rather
 * than returning on the first match found, so a leading empty/legacy duplicate entry can never
 * shadow a real value that appears later in the array.
 */
function wc_order_import_get_meta(array $wcOrder, string $key): ?string
{
    $found = null;

    foreach (($wcOrder['meta_data'] ?? []) as $meta) {
        if (!is_array($meta) || ($meta['key'] ?? null) !== $key) {
            continue;
        }

        $value = $meta['value'] ?? null;
        $resolved = $value !== null && $value !== '' ? (string) $value : null;
        if ($resolved !== null) {
            $found = $resolved;
        }
    }

    return $found;
}

/**
 * Receipt visibility fields for the Mewmii Preorder WordPress plugin's workflow - purely
 * informational display data, deliberately independent of payment_status/order_status (see
 * wc_order_import_map_payment_status()'s docblock). Returns
 * ['is_preorder_request', 'receipt_url', 'receipt_status', 'receipt_reject_reason'].
 * receipt_status is only ever non-null for a preorder order, and defaults to 'pending' when
 * WooCommerce didn't report receipt_upload_status at all (no receipt uploaded yet, or the
 * field isn't exposed) - it is never left unset for a preorder order.
 *
 * Source keys confirmed against real data across multiple debug traces - several receipt-upload
 * generations/mirrors coexist on real orders, so each field uses a priority chain rather than a
 * single key:
 *   receipt_url:    _pepro_receipt_url -> _receipt_url -> pepro_receipt_url
 *                    -> (unresolved) _receipt_attachment_id / _pepro_receipt
 *   receipt_status: receipt_upload_status -> pepro_receipt_status -> _pepro_status
 *   reject reason:  receipt_upload_admin_note -> _mewmii_reject_reason
 * The attachment-ID tier is a last resort and deliberately never becomes receipt_url: Mewmii OS
 * has no WordPress access and cannot turn a numeric attachment ID into a real file URL itself
 * (that resolution already happens WordPress-side, in mewmii-preorder.php's REST filter, via
 * wp_get_attachment_url() - see _receipt_url). Storing a bare ID in a URL column would just be
 * wrong data, so this tier only feeds $hasReceiptSignal below, never receipt_url.
 *
 * is_preorder_request keeps using _mewmii_is_preorder exactly as before - unchanged.
 * IMPORTANT: receipt_url/receipt_status/receipt_reject_reason are NOT gated behind that flag
 * (they used to be, via `$isPreorder ? $x : null`). _mewmii_is_preorder is stale for effectively
 * every order placed since checkout moved off the old gateway to Bank Transfer/QR, so gating on
 * it was silently discarding real, present receipt data - confirmed directly: debug output has
 * repeatedly shown is_preorder_request:0 alongside genuinely-populated raw meta. The presence of
 * receipt data is now its own independent signal for whether to populate these fields.
 */
function wc_order_import_extract_receipt_fields(array $wcOrder): array
{
    $isPreorder = wc_order_import_get_meta($wcOrder, '_mewmii_is_preorder') === 'yes';

    $receiptUrl = wc_order_import_get_meta($wcOrder, '_pepro_receipt_url')
        ?? wc_order_import_get_meta($wcOrder, '_receipt_url')
        ?? wc_order_import_get_meta($wcOrder, 'pepro_receipt_url');

    $unresolvedAttachmentId = $receiptUrl === null
        ? (wc_order_import_get_meta($wcOrder, '_receipt_attachment_id')
            ?? wc_order_import_get_meta($wcOrder, '_pepro_receipt'))
        : null;

    $rawStatus = wc_order_import_get_meta($wcOrder, 'receipt_upload_status')
        ?? wc_order_import_get_meta($wcOrder, 'pepro_receipt_status')
        ?? wc_order_import_get_meta($wcOrder, '_pepro_status');

    $rejectReason = wc_order_import_get_meta($wcOrder, 'receipt_upload_admin_note')
        ?? wc_order_import_get_meta($wcOrder, '_mewmii_reject_reason');

    $hasReceiptSignal = $receiptUrl !== null || $unresolvedAttachmentId !== null || $rawStatus !== null;

    $receiptStatus = null;
    if ($hasReceiptSignal) {
        $receiptStatus = in_array($rawStatus, ['approved', 'rejected'], true) ? $rawStatus : 'pending';
    }

    return [
        'is_preorder_request' => $isPreorder ? 1 : 0,
        'receipt_url' => $receiptUrl,
        'receipt_status' => $receiptStatus,
        'receipt_reject_reason' => $hasReceiptSignal ? $rejectReason : null,
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
 *
 * 'shipped' is a custom order status added by a shipping/tracking plugin (e.g. Advanced
 * Shipment Tracking) - not a default WooCommerce status. An order cannot reach "shipped"
 * without having been paid first, so it maps to 'paid' exactly like 'processing'/'completed'.
 * This is a payment_status mapping fix only: it does not feed mewmii_orders.order_status,
 * which remains entirely computed by order_recompute_status() (includes/order_fulfillment.php,
 * unchanged) from Mewmii OS's own fulfillment state - WooCommerce's status is never consulted
 * for that column, before or after this change. Before this fix, an order WooCommerce moved to
 * 'shipped' matched no key here, returned null, and was skipped entirely on every subsequent
 * sync (see wc_order_import_single()) - not just its status but its totals/receipt fields too
 * stopped updating the moment WooCommerce marked it shipped.
 */
function wc_order_import_map_payment_status(string $wcStatus): ?string
{
    $map = [
        'processing' => 'paid',
        'completed' => 'paid',
        'shipped' => 'paid',
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
        // Production Hardening Phase 2: case-insensitive, matching every other
        // customer-creation path in the app (modules/customers/create.php,
        // modules/customers/import.php, the Quick Add endpoint) - this tier used to be an
        // exact-case match, so a WooCommerce billing email differing only in case from an
        // existing customer record would silently fall through to creating a duplicate.
        $stmt = $pdo->prepare('SELECT id, woocommerce_customer_id FROM customers WHERE LOWER(email) = LOWER(?) LIMIT 1');
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
 * modules/integrations/woocommerce.php) and the scheduled cron trigger (see
 * cli/wc_order_sync.php) - both call this exact function, unchanged from either caller's point
 * of view. Wraps wc_order_import_run_body() in a MySQL advisory lock (Sync Automation Phase
 * 4A) so cron and the manual button (or two overlapping cron ticks) can never run
 * wc_order_import_single() against the same orders concurrently.
 *
 * GET_LOCK(name, 0) never waits - if another sync already holds the lock, this fails fast and
 * throws immediately rather than queuing up behind it, so a slow first-time backfill can never
 * cause a pile-up of blocked cron processes. The lock is released in both the success and
 * failure paths via `finally`, and MySQL also auto-releases it if this PHP process dies before
 * reaching the `finally` at all (the lock is scoped to this request's own DB session, and this
 * app never uses persistent PDO connections - see config/database.php) - so a crashed run can
 * never leave the lock stuck.
 *
 * @throws RuntimeException with code WC_ORDER_IMPORT_LOCK_BUSY_CODE if another sync is already
 * running - callers (see cli/wc_order_sync.php) should treat this as benign/expected, not a
 * real failure.
 */
function wc_order_import_run(PDO $pdo): array
{
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockStmt->execute([WC_ORDER_IMPORT_LOCK_NAME]);

    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException(
            'Another WooCommerce sync is already in progress - skipped this run.',
            WC_ORDER_IMPORT_LOCK_BUSY_CODE
        );
    }

    try {
        return wc_order_import_run_body($pdo);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([WC_ORDER_IMPORT_LOCK_NAME]);
    }
}

/**
 * The actual delta-sync logic - unchanged from Sync Automation Phase 1/2, only ever called by
 * wc_order_import_run() above, which holds the advisory lock for the entire duration of this
 * function. Fetches only orders WooCommerce reports as modified after the last successfully
 * completed run's start time (`modified_after`, `dates_are_gmt` to avoid any WordPress-vs-
 * server timezone mismatch), walking pages oldest-modified-first until WooCommerce returns an
 * empty page or the safety ceiling is hit. This replaces the old "always latest 20 by
 * date_created" fetch, which could never see an update to an order that had already fallen out
 * of that top-20 window (e.g. a receipt uploaded days after the order was placed, once 20+
 * newer orders existed) - the entire reason this rework exists.
 *
 * Each order is still imported inside its own transaction (via wc_order_import_single(),
 * completely unchanged) so one bad order never rolls back the rest of the batch - identical
 * per-order behavior to before, only the fetch/pagination strategy around it is new.
 *
 * The delta cursor only advances if every page was walked successfully (reached a page with
 * fewer than WC_ORDER_IMPORT_PAGE_SIZE results, i.e. no more pages remain). A fetch failure
 * partway through, or hitting the page-count safety ceiling, leaves the cursor untouched - the
 * next run simply re-covers the same ground from the old cursor. Already-imported orders in
 * that re-covered range are harmless no-op updates (matched via woocommerce_order_id, see
 * wc_order_import_single()), so this is always safe to retry, never duplicates.
 */
function wc_order_import_run_body(PDO $pdo): array
{
    $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

    // Captured before the first fetch, not after the last one - an order modified while this
    // run is still in progress must be picked up on the NEXT run, never silently skipped
    // because it looked "already covered" by a cursor stamped after the run finished.
    $syncStartedAt = gmdate('Y-m-d\TH:i:s');
    $previousCursor = wc_order_import_get_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT);

    $baseQuery = [
        'per_page' => WC_ORDER_IMPORT_PAGE_SIZE,
        'orderby' => 'modified',
        'order' => 'asc',
        'dates_are_gmt' => true,
    ];
    if ($previousCursor !== null) {
        $baseQuery['modified_after'] = $previousCursor;
    }

    $page = 1;
    $fullyCompleted = false;

    try {
        while (true) {
            if ($page > WC_ORDER_IMPORT_MAX_PAGES) {
                sync_log_write($pdo, WC_ORDER_IMPORT_SYNC_TYPE, 'failed', null,
                    'Stopped after ' . WC_ORDER_IMPORT_MAX_PAGES . ' pages (safety limit) - more orders may remain. Cursor not advanced; re-run to continue.');
                break;
            }

            $orders = wc_client_get('orders', array_merge($baseQuery, ['page' => $page]));

            foreach ($orders as $wcOrder) {
                if (!is_array($wcOrder)) {
                    continue;
                }

                $wcOrderId = (int) ($wcOrder['id'] ?? 0);

                $pdo->beginTransaction();
                try {
                    $result = wc_order_import_single($pdo, $wcOrder);
                    $pdo->commit();
                    inventory_flush_woocommerce_resync($pdo);

                    $summary[$result['action']]++;

                    if ($result['action'] !== 'skipped') {
                        sync_log_success($pdo, WC_ORDER_IMPORT_SYNC_TYPE, $result['order_id']);
                    }
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    inventory_discard_pending_woocommerce_resync();
                    $summary['failed']++;
                    sync_log_failure($pdo, WC_ORDER_IMPORT_SYNC_TYPE, 'WooCommerce order #' . $wcOrderId . ': ' . $e->getMessage());
                }
            }

            if (count($orders) < WC_ORDER_IMPORT_PAGE_SIZE) {
                $fullyCompleted = true;
                break;
            }

            $page++;
        }

        if ($fullyCompleted) {
            wc_order_import_set_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT, $syncStartedAt);
        }
    } catch (Throwable $e) {
        // A fetch itself failed (connection/API error) - whatever was processed on earlier
        // pages this run stays committed (each order's own transaction already closed), but
        // the cursor is deliberately NOT advanced, so the next run re-walks from the old
        // cursor rather than risk skipping a page this run never reached.
        sync_log_failure($pdo, WC_ORDER_IMPORT_SYNC_TYPE, 'WooCommerce order fetch failed: ' . $e->getMessage());
    }

    wc_order_import_set_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_RUN_SUMMARY, json_encode([
        'ran_at' => $syncStartedAt,
        'created' => $summary['created'],
        'updated' => $summary['updated'],
        'skipped' => $summary['skipped'],
        'failed' => $summary['failed'],
    ]));

    return $summary;
}
