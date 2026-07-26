<?php

/**
 * Phase 6E - WooCommerce webhook integration (additive; does not replace the existing
 * poll-based importers in includes/wc_product_import.php / includes/wc_order_import.php).
 *
 * Architecture: modules/webhooks/woocommerce.php (the receiver) does only two things -
 * verify the HMAC signature, then call wc_webhook_record_event() below to queue the raw
 * payload into webhook_events and respond 200 immediately. No product/order/customer
 * processing happens in that request. cli/wc_webhook_process.php, ticked by cron every 1-2
 * minutes, calls wc_webhook_process_pending_events() below, which claims due rows and
 * dispatches each to the SAME per-item functions the existing importers already use
 * (wc_product_import_process_one_product(), wc_order_import_single(),
 * wc_customer_import_upsert()) - this file adds no new product/order parsing or upsert logic
 * of its own for those two resource types.
 *
 * Duplicate prevention is layered: wc_webhook_record_event() dedupes by WooCommerce's own
 * delivery id first (or payload content hash if that header is ever missing), so a genuinely
 * re-sent delivery never gets queued twice; independently, every downstream upsert function
 * this file dispatches to is already idempotent (matched by WooCommerce id, SKU, or order/
 * customer id), so even a duplicate that slipped through is still safe to process again.
 */

require_once __DIR__ . '/sync_log.php';

const WC_WEBHOOK_SYNC_TYPE = 'woocommerce_webhook';

// Matches wc_order_import_apply_payment_upgrade()/wc_product_import's own per-run cap
// philosophy: bounded, logged, never silently infinite. A row that exhausts this many
// attempts stays status='failed' but attempts >= this cap, so the processor's own polling
// query (see wc_webhook_process_pending_events_body()) simply stops selecting it - no
// separate "permanently failed" status value is needed, matching the fixed pending/
// processing/completed/failed status set.
const WC_WEBHOOK_MAX_ATTEMPTS = 5;

// MySQL advisory lock name - distinct from WC_ORDER_IMPORT_LOCK_NAME/WC_PRODUCT_IMPORT_LOCK_NAME
// (a webhook-processing run and a poll-import run are independent activities and may run
// concurrently without contending), but still needed so two overlapping cron ticks (or cron +
// a manual "Process Now" trigger) never claim the same event rows at once.
const WC_WEBHOOK_LOCK_NAME = 'mewmii_wc_webhook_process';
const WC_WEBHOOK_LOCK_BUSY_CODE = 42303;

// A claimed ('processing') row whose updated_at is older than this is treated as abandoned
// (the PHP process that claimed it died before reaching either the success or failure
// bookkeeping - e.g. a fatal error not caught by any try/catch) and becomes eligible for
// another attempt, so a crash can never leave an event stuck forever.
const WC_WEBHOOK_STALE_PROCESSING_MINUTES = 10;

/**
 * WooCommerce signs the RAW request body (before any JSON decoding) as
 * base64_encode(hash_hmac('sha256', $rawBody, $secret, true)) in the X-WC-Webhook-Signature
 * header. hash_equals() for a timing-safe comparison - a plain === here would leak how many
 * leading bytes matched via response-time differences.
 */
function wc_webhook_verify_signature(string $rawBody, string $signatureHeader, string $secret): bool
{
    if ($secret === '' || $signatureHeader === '') {
        return false;
    }

    $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

    return hash_equals($expected, $signatureHeader);
}

/**
 * Records one signature-verified webhook delivery into the queue - the ONLY write the
 * receiver (modules/webhooks/woocommerce.php) performs. Returns the new event's id, or null
 * if this delivery was a duplicate (a legitimate, expected outcome - WooCommerce redelivers
 * on a slow/ambiguous response, and the receiver responding 200 either way is what stops it
 * from retrying further).
 *
 * Duplicate detection: WooCommerce's own delivery id (X-WC-Webhook-Delivery-ID) when present
 * - the strongest signal, since a genuine redelivery reuses it. Falls back to
 * (resource, resource_id, payload_hash) when that header is missing/empty. The UNIQUE
 * constraint on woocommerce_delivery_id is the authoritative guard either way (see the catch
 * block below) - the SELECT checks above are just the fast, common-case path.
 */
function wc_webhook_record_event(PDO $pdo, string $topic, string $resource, ?int $resourceId, ?string $deliveryId, string $rawPayload): ?int
{
    $payloadHash = hash('sha256', $rawPayload);

    if ($deliveryId !== null && $deliveryId !== '') {
        $existingStmt = $pdo->prepare('SELECT id FROM webhook_events WHERE woocommerce_delivery_id = ?');
        $existingStmt->execute([$deliveryId]);
        if ($existingStmt->fetchColumn() !== false) {
            return null;
        }
    } else {
        $existingStmt = $pdo->prepare('SELECT id FROM webhook_events WHERE resource = ? AND resource_id <=> ? AND payload_hash = ? LIMIT 1');
        $existingStmt->execute([$resource, $resourceId, $payloadHash]);
        if ($existingStmt->fetchColumn() !== false) {
            return null;
        }
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO webhook_events (woocommerce_delivery_id, topic, resource, resource_id, payload_hash, payload_json)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$deliveryId !== '' ? $deliveryId : null, $topic, $resource, $resourceId, $payloadHash, $rawPayload]);

        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        // Race: a concurrent delivery inserted the same delivery_id between the SELECT check
        // above and this INSERT. SQLSTATE 23000 = integrity constraint violation - anything
        // else is a real failure and must still surface to the caller.
        if ($e->getCode() === '23000') {
            return null;
        }

        throw $e;
    }
}

/**
 * Backoff schedule for a failed processing attempt: 1, 2, 4, 8, 16 minutes for attempts
 * 1 through WC_WEBHOOK_MAX_ATTEMPTS, capped at 30 - short enough that a transient failure
 * (e.g. a momentary DB blip) recovers within one or two cron ticks, long enough that a
 * genuinely broken delivery doesn't hammer the same failing path every tick.
 */
function wc_webhook_calculate_next_retry(int $attempts): string
{
    $minutes = min(30, (int) (2 ** max(0, $attempts - 1)));

    return gmdate('Y-m-d H:i:s', time() + $minutes * 60);
}

/**
 * Routes one already-claimed event to the existing per-item processing function for its
 * resource type - reuses wc_product_import_process_one_product()/wc_order_import_single()
 * unchanged (see this file's own docblock), and the new wc_customer_import_upsert() for the
 * genuinely-new customer path. Throws on any failure - the caller
 * (wc_webhook_process_pending_events_body()) is what turns that into the retry/failed
 * bookkeeping, this function only does the dispatch + reuse.
 */
function wc_webhook_process_one_event(PDO $pdo, array $event): ?int
{
    $payload = json_decode((string) $event['payload_json'], true);
    if (!is_array($payload)) {
        throw new RuntimeException('Stored webhook payload is not valid JSON.');
    }

    switch ($event['resource']) {
        case 'product':
            return wc_webhook_dispatch_product($pdo, $payload);
        case 'order':
            return wc_webhook_dispatch_order($pdo, $payload);
        case 'customer':
            return wc_webhook_dispatch_customer($pdo, $payload);
        default:
            // Any topic/resource WooCommerce sends still gets queued by the receiver (it
            // isn't the receiver's job to judge topics - see modules/webhooks/woocommerce.php),
            // but only product/order/customer have a processing path today (matches this
            // phase's explicit scope - product.created/updated, order.created/updated,
            // customer.created/updated). Anything else (e.g. a *.deleted topic, if a webhook
            // were ever configured for one) fails clearly here rather than being silently
            // dropped or guessed at - deletion semantics were explicitly out of scope for
            // this phase.
            throw new RuntimeException('No processing handler for resource type "' . $event['resource'] . '".');
    }
}

/**
 * Reuses wc_product_import_process_one_product() (includes/wc_product_import.php)
 * completely unmodified - the exact function the existing poll importer already calls per
 * product. That function manages its own internal transactions (product upsert, then each
 * variation), so this dispatch does not wrap it in one of its own. A webhook product payload
 * with no SKU is skipped the same way the poll importer already skips one (see
 * wc_product_import_run_body()'s own $sku === '' check) - not treated as a hard failure.
 *
 * @return int|null the LOCAL Mewmii product id (never the WooCommerce id) - used as
 * sync_logs.reference_id, matching wc_product_import_run_body()'s own convention exactly, so
 * a webhook-triggered sync_logs row links to the same kind of id every other
 * woocommerce_product_import/woocommerce_product_sync row already does. Null when skipped
 * (no SKU) or, in dry-run-equivalent terms, when the import returned 0.
 */
function wc_webhook_dispatch_product(PDO $pdo, array $wcProduct): ?int
{
    require_once __DIR__ . '/wc_product_import.php';

    $sku = trim((string) ($wcProduct['sku'] ?? ''));
    if ($sku === '') {
        return null;
    }

    $stats = [
        'products_created' => 0, 'products_updated' => 0, 'products_skipped' => 0, 'products_failed' => 0,
        'variations_created' => 0, 'variations_updated' => 0,
        'opening_stock_set' => 0, 'opening_stock_skipped' => 0,
        'images_downloaded' => 0, 'images_failed' => 0,
    ];

    $localProductId = wc_product_import_process_one_product($pdo, $wcProduct, false, $stats);

    return $localProductId > 0 ? $localProductId : null;
}

/**
 * Reuses wc_order_import_single() (includes/wc_order_import.php) completely unmodified.
 * Unlike the product function, wc_order_import_single() does NOT manage its own transaction
 * (its existing caller, wc_order_import_run_body(), wraps each call itself) - this dispatch
 * replicates that exact wrapping, including the same inventory_flush_woocommerce_resync()/
 * inventory_discard_pending_woocommerce_resync() pairing, so a webhook-triggered order
 * import behaves identically to a poll-triggered one.
 *
 * @return int|null the LOCAL mewmii_orders.id, matching wc_order_import_run_body()'s own
 * sync_log_success($result['order_id']) convention. Null when the order was skipped (e.g. a
 * WooCommerce status this app doesn't import - see wc_order_import_map_payment_status()) -
 * not an error, just nothing to attribute the log entry to.
 */
function wc_webhook_dispatch_order(PDO $pdo, array $wcOrder): ?int
{
    require_once __DIR__ . '/wc_order_import.php';
    require_once __DIR__ . '/inventory.php';

    $pdo->beginTransaction();
    try {
        $result = wc_order_import_single($pdo, $wcOrder);
        $pdo->commit();
        inventory_flush_woocommerce_resync($pdo);

        return $result['order_id'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        inventory_discard_pending_woocommerce_resync();
        throw $e;
    }
}

/**
 * New functionality (see includes/wc_customer_import.php's own docblock - no customer sync
 * existed before this phase). Wrapped in its own transaction for the same atomicity reasons
 * as the order dispatch above, even though today it's a single statement.
 *
 * @return int the LOCAL customers.id.
 */
function wc_webhook_dispatch_customer(PDO $pdo, array $wcCustomer): ?int
{
    require_once __DIR__ . '/wc_customer_import.php';

    $pdo->beginTransaction();
    try {
        $result = wc_customer_import_upsert($pdo, $wcCustomer);
        $pdo->commit();

        return $result['id'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Batch entrypoint for cli/wc_webhook_process.php (cron, every 1-2 minutes). Wraps
 * wc_webhook_process_pending_events_body() in a MySQL advisory lock, identical reasoning to
 * wc_order_import_run()/wc_product_import_run() - GET_LOCK(name, 0) never waits, so an
 * overlapping tick fails fast rather than queuing up behind a slow run.
 *
 * @throws RuntimeException with code WC_WEBHOOK_LOCK_BUSY_CODE if another processing run is
 * already in progress - callers should treat this as benign/expected, not a real failure.
 */
function wc_webhook_process_pending_events(PDO $pdo, int $batchSize = 20): array
{
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockStmt->execute([WC_WEBHOOK_LOCK_NAME]);

    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException(
            'Another webhook processing run is already in progress - skipped this run.',
            WC_WEBHOOK_LOCK_BUSY_CODE
        );
    }

    try {
        return wc_webhook_process_pending_events_body($pdo, $batchSize);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([WC_WEBHOOK_LOCK_NAME]);
    }
}

/**
 * The actual queue processor, only ever called by wc_webhook_process_pending_events() above,
 * which holds the advisory lock for its whole duration.
 *
 * Selects: pending/failed rows still under the attempt cap and due (next_retry_at is null or
 * already past), PLUS any 'processing' row stuck past WC_WEBHOOK_STALE_PROCESSING_MINUTES
 * (an abandoned claim from a crashed prior run). Each selected row is claimed via an
 * optimistic UPDATE ... WHERE status IN (...) - defends against a second overlapping run
 * despite the advisory lock (e.g. two processes racing inside GET_LOCK's non-blocking
 * window); if the claim affects zero rows, another run already took it and this one moves on.
 *
 * One bad event never stops the batch: each dispatch is its own try/catch, mirroring every
 * other per-item loop in this codebase (wc_order_import_run_body(), the products bulk sync,
 * etc). Every attempt - success or failure - also gets a sync_logs row under sync_type
 * WC_WEBHOOK_SYNC_TYPE, so webhook activity shows up in modules/sync-logs/index.php using
 * the same table/index/pagination every other sync type already does.
 */
function wc_webhook_process_pending_events_body(PDO $pdo, int $batchSize): array
{
    $summary = ['processed' => 0, 'completed' => 0, 'retrying' => 0, 'failed' => 0];

    $stmt = $pdo->prepare("
        SELECT id, woocommerce_delivery_id, topic, resource, resource_id, payload_json, status, attempts
        FROM webhook_events
        WHERE
            (status IN ('pending', 'failed') AND attempts < ? AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP()))
            OR (status = 'processing' AND updated_at < (UTC_TIMESTAMP() - INTERVAL " . WC_WEBHOOK_STALE_PROCESSING_MINUTES . " MINUTE))
        ORDER BY created_at ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, WC_WEBHOOK_MAX_ATTEMPTS, PDO::PARAM_INT);
    $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($events as $event) {
        $eventId = (int) $event['id'];

        $claimStmt = $pdo->prepare("
            UPDATE webhook_events
            SET status = 'processing', attempts = attempts + 1
            WHERE id = ? AND status IN ('pending', 'failed', 'processing')
        ");
        $claimStmt->execute([$eventId]);
        if ($claimStmt->rowCount() === 0) {
            continue;
        }

        $summary['processed']++;
        $newAttempts = (int) $event['attempts'] + 1;

        try {
            // Local id (never the WooCommerce id in $event['resource_id']) - see each
            // dispatch function's own docblock for why this is what sync_logs.reference_id
            // must carry to match every other sync entry point's convention.
            $localId = wc_webhook_process_one_event($pdo, $event);

            $pdo->prepare("UPDATE webhook_events SET status = 'completed', processed_at = UTC_TIMESTAMP(), last_error = NULL, next_retry_at = NULL WHERE id = ?")
                ->execute([$eventId]);
            sync_log_success($pdo, WC_WEBHOOK_SYNC_TYPE, $localId);
            $summary['completed']++;
        } catch (Throwable $e) {
            $nextRetryAt = $newAttempts < WC_WEBHOOK_MAX_ATTEMPTS ? wc_webhook_calculate_next_retry($newAttempts) : null;

            $pdo->prepare("UPDATE webhook_events SET status = 'failed', last_error = ?, next_retry_at = ? WHERE id = ?")
                ->execute([$e->getMessage(), $nextRetryAt, $eventId]);

            // No local id is known at a failure point (the dispatch threw before returning
            // one) - the WooCommerce resource id still goes into the message text itself for
            // human context, just not into the reference_id column, which is reserved for a
            // real local record id elsewhere in this app.
            sync_log_failure($pdo, WC_WEBHOOK_SYNC_TYPE, $event['resource'] . ' (WC #' . ($event['resource_id'] ?? '?') . ', ' . $event['topic'] . '): ' . $e->getMessage());

            if ($newAttempts >= WC_WEBHOOK_MAX_ATTEMPTS) {
                $summary['failed']++;
            } else {
                $summary['retrying']++;
            }
        }
    }

    return $summary;
}

/**
 * Manual "Retry" (modules/webhooks/events.php) - processes one event synchronously so the
 * admin gets an immediate result, instead of waiting for the next cron tick. Bypasses the
 * attempt cap deliberately (a human explicitly asked for this one more try) but still goes
 * through the exact same claim/dispatch/bookkeeping as the automatic path, and still writes
 * to sync_logs the same way - this is not a second, separate retry mechanism.
 *
 * @throws RuntimeException if the event is not currently in a retryable state (e.g. already
 * completed, or another run has it claimed).
 */
function wc_webhook_retry_event_now(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare("
        SELECT id, woocommerce_delivery_id, topic, resource, resource_id, payload_json, status, attempts
        FROM webhook_events WHERE id = ?
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($event === false) {
        throw new RuntimeException('Webhook event not found.');
    }
    if ($event['status'] === 'completed') {
        throw new RuntimeException('This event already completed successfully - nothing to retry.');
    }

    $claimStmt = $pdo->prepare("UPDATE webhook_events SET status = 'processing', attempts = attempts + 1 WHERE id = ? AND status IN ('pending', 'failed', 'processing')");
    $claimStmt->execute([$eventId]);
    if ($claimStmt->rowCount() === 0) {
        throw new RuntimeException('This event is currently being processed by another run - try again shortly.');
    }

    try {
        $localId = wc_webhook_process_one_event($pdo, $event);

        $pdo->prepare("UPDATE webhook_events SET status = 'completed', processed_at = UTC_TIMESTAMP(), last_error = NULL, next_retry_at = NULL WHERE id = ?")
            ->execute([$eventId]);
        sync_log_success($pdo, WC_WEBHOOK_SYNC_TYPE, $localId);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE webhook_events SET status = 'failed', last_error = ?, next_retry_at = NULL WHERE id = ?")
            ->execute([$e->getMessage(), $eventId]);
        sync_log_failure($pdo, WC_WEBHOOK_SYNC_TYPE, $event['resource'] . ' (WC #' . ($event['resource_id'] ?? '?') . ', ' . $event['topic'] . '): ' . $e->getMessage());

        throw $e;
    }
}
