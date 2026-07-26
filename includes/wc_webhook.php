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
 *
 * Phase 6F (production hardening) - additive on top of all of the above, same architecture:
 * - wc_webhook_claim_event() adds an explicit SELECT ... FOR UPDATE row lock around the
 *   pending->processing transition, reused by both the batch processor and manual retry, so
 *   claiming is never just an optimistic status-check UPDATE on its own.
 * - wc_webhook_recover_stale_processing() runs first on every processor invocation, returning
 *   an abandoned 'processing' row (a crashed prior run) to 'pending' so it re-enters the
 *   normal claim flow rather than needing a separate "abandoned" code path.
 * - Batch size, the stale-processing timeout, and cleanup retention are now all configurable
 *   (config.php's woocommerce.webhook_batch_size/webhook_processing_timeout/
 *   webhook_cleanup_days - see wc_webhook_config_int()), each with a sensible default.
 * - wc_webhook_cleanup_completed_events() deletes old 'completed' rows only - pending/
 *   processing/failed are never touched by it, regardless of age.
 */

require_once __DIR__ . '/sync_log.php';
require_once __DIR__ . '/wc_client.php';

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

// Phase 6F - defaults for the three config.php-overridable values below (see
// wc_webhook_config_int()). Each stays this constant's value when config.php doesn't set
// (or blanks out) the corresponding woocommerce.webhook_* key.
const WC_WEBHOOK_BATCH_SIZE_DEFAULT = 50;
const WC_WEBHOOK_PROCESSING_TIMEOUT_DEFAULT = 15; // minutes
const WC_WEBHOOK_CLEANUP_DAYS_DEFAULT = 90;

/**
 * Shared reader for the three optional config.php overrides (woocommerce.webhook_batch_size/
 * webhook_processing_timeout/webhook_cleanup_days - see config.example.php) - one helper
 * instead of three near-identical blank/invalid-value-falls-back-to-default checks. Blank,
 * missing, or non-numeric config values are silently treated as "not set" (falls back to
 * $default) rather than a startup error - these are tuning knobs, not required
 * configuration, matching the existing woocommerce.webhook_receive_secret's own
 * empty-string-default convention in wc_client_config().
 */
function wc_webhook_config_int(string $key, int $default): int
{
    $config = wc_client_config();
    $value = trim((string) ($config[$key] ?? ''));

    if ($value === '' || !ctype_digit($value)) {
        return $default;
    }

    return max(1, (int) $value);
}

function wc_webhook_batch_size(): int
{
    return wc_webhook_config_int('webhook_batch_size', WC_WEBHOOK_BATCH_SIZE_DEFAULT);
}

/**
 * A claimed ('processing') row whose updated_at is older than this is treated as abandoned
 * (the PHP process that claimed it died before reaching either the success or failure
 * bookkeeping - e.g. a fatal error not caught by any try/catch, or the host killed a
 * long-running request) and becomes eligible for another attempt, so a crash can never leave
 * an event stuck forever - see wc_webhook_recover_stale_processing().
 */
function wc_webhook_processing_timeout_minutes(): int
{
    return wc_webhook_config_int('webhook_processing_timeout', WC_WEBHOOK_PROCESSING_TIMEOUT_DEFAULT);
}

function wc_webhook_cleanup_days(): int
{
    return wc_webhook_config_int('webhook_cleanup_days', WC_WEBHOOK_CLEANUP_DAYS_DEFAULT);
}

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
 * (topic, resource_id, payload_hash) when that header is missing/empty - topic rather than
 * the coarser resource, since "product.updated" and "product.created" for the same
 * resource_id are genuinely different events and must not be deduped against each other.
 * The UNIQUE constraint on woocommerce_delivery_id is the authoritative guard either way
 * (see the catch block below) - the SELECT checks above are just the fast, common-case path.
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
        $existingStmt = $pdo->prepare('SELECT id FROM webhook_events WHERE topic = ? AND resource_id <=> ? AND payload_hash = ? LIMIT 1');
        $existingStmt->execute([$topic, $resourceId, $payloadHash]);
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
 * Phase 6F hardening - claims one event row for processing under an explicit row lock
 * (SELECT ... FOR UPDATE inside its own short transaction), not just the optimistic
 * status-check UPDATE this used to rely on alone. The transaction commits immediately after
 * marking the row 'processing' (releasing the row lock) - actual dispatch/processing happens
 * afterward, outside this transaction, since wc_webhook_dispatch_order()/_customer() already
 * open their own transactions and PDO/MySQL has no true nested transaction support.
 *
 * Reused by both the automatic batch processor (wc_webhook_process_pending_events_body())
 * and the manual "Retry" action (wc_webhook_retry_event_now()) so there is exactly one place
 * this locking logic lives, per the "avoid duplicated SQL" requirement.
 *
 * @return array|null the freshly-locked row (attempts already incremented in the returned
 * value), or null if the row no longer exists or isn't in a claimable state (already
 * completed, or genuinely still being processed by another still-live run - a *stale*
 * 'processing' row is never claimable here directly; it must go through
 * wc_webhook_recover_stale_processing() back to 'pending' first).
 */
function wc_webhook_claim_event(PDO $pdo, int $eventId): ?array
{
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT id, woocommerce_delivery_id, topic, resource, resource_id, payload_json, status, attempts
            FROM webhook_events WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($event === false || !in_array($event['status'], ['pending', 'failed'], true)) {
            $pdo->rollBack();

            return null;
        }

        $pdo->prepare("UPDATE webhook_events SET status = 'processing', attempts = attempts + 1 WHERE id = ?")
            ->execute([$eventId]);
        $pdo->commit();

        $event['attempts'] = (int) $event['attempts'] + 1;

        return $event;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Phase 6F hardening - explicit stale-processing recovery, run first on every processor
 * invocation (see wc_webhook_process_pending_events_body() below). A row stuck in
 * 'processing' past wc_webhook_processing_timeout_minutes() means the PHP process that
 * claimed it died before reaching either the success or failure bookkeeping - returning it
 * to 'pending' lets the normal claim/dispatch flow simply pick it up again, rather than a
 * separate "abandoned row" code path. Deliberately does not touch attempts here -
 * wc_webhook_claim_event() increments it when this recovered row is claimed again, exactly
 * like any other pending row.
 *
 * @return int how many rows were recovered, for the run summary/log line.
 */
function wc_webhook_recover_stale_processing(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        UPDATE webhook_events
        SET status = 'pending'
        WHERE status = 'processing' AND updated_at < (UTC_TIMESTAMP() - INTERVAL ? MINUTE)
    ");
    $stmt->bindValue(1, wc_webhook_processing_timeout_minutes(), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
}

/**
 * Phase 6F hardening - deletes 'completed' events older than the configured retention
 * window (default WC_WEBHOOK_CLEANUP_DAYS_DEFAULT days - see wc_webhook_cleanup_days()).
 * The WHERE clause only ever matches status = 'completed': pending/processing/failed rows
 * are never deleted by this, regardless of age, since they still represent unfinished or
 * unresolved work. Callable safely from cron (cli/wc_webhook_process.php's --cleanup flag)
 * or the manual "Clean Old Completed Events" button (modules/webhooks/events.php) - both
 * call this exact function, no separate cleanup logic anywhere else.
 *
 * @return int how many rows were deleted.
 */
function wc_webhook_cleanup_completed_events(PDO $pdo, ?int $days = null): int
{
    $days = $days ?? wc_webhook_cleanup_days();

    $stmt = $pdo->prepare("
        DELETE FROM webhook_events
        WHERE status = 'completed' AND processed_at IS NOT NULL AND processed_at < (UTC_TIMESTAMP() - INTERVAL ? DAY)
    ");
    $stmt->bindValue(1, $days, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
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
function wc_webhook_process_pending_events(PDO $pdo, int $batchSize = WC_WEBHOOK_BATCH_SIZE_DEFAULT): array
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
 * Phase 6F hardening flow: recover any abandoned 'processing' row first (see
 * wc_webhook_recover_stale_processing()), then select up to $batchSize pending/failed
 * candidate ids still under the attempt cap and due (next_retry_at null or already past),
 * then claim + lock each one individually via wc_webhook_claim_event() (SELECT ... FOR
 * UPDATE, not just an optimistic status-check UPDATE) - if a claim returns null, another run
 * already took that row (or it's no longer eligible) and this one moves on.
 *
 * One bad event never stops the batch: each dispatch is its own try/catch, mirroring every
 * other per-item loop in this codebase (wc_order_import_run_body(), the products bulk sync,
 * etc). Every attempt - success or failure - also gets a sync_logs row under sync_type
 * WC_WEBHOOK_SYNC_TYPE, so webhook activity shows up in modules/sync-logs/index.php using
 * the same table/index/pagination every other sync type already does.
 */
function wc_webhook_process_pending_events_body(PDO $pdo, int $batchSize): array
{
    $summary = ['recovered' => 0, 'processed' => 0, 'completed' => 0, 'retrying' => 0, 'failed' => 0];

    $summary['recovered'] = wc_webhook_recover_stale_processing($pdo);

    $stmt = $pdo->prepare("
        SELECT id
        FROM webhook_events
        WHERE status IN ('pending', 'failed') AND attempts < ? AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())
        ORDER BY created_at ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, WC_WEBHOOK_MAX_ATTEMPTS, PDO::PARAM_INT);
    $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $candidateIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($candidateIds as $eventId) {
        try {
            $event = wc_webhook_claim_event($pdo, $eventId);
        } catch (Throwable $e) {
            // The claim/lock step itself failed (e.g. an InnoDB lock-wait-timeout under real
            // concurrency, or a transient connection error) - logged, then move on to the
            // next candidate rather than aborting the rest of the batch over one row. This
            // event is untouched (still 'pending'/'failed', attempts unchanged), so it's
            // simply retried on the next run. The logging call itself is also guarded - if
            // the connection is what's actually broken, a second write can't be relied on
            // either, but that must never mask/replace this exception with a new one.
            try {
                sync_log_failure($pdo, WC_WEBHOOK_SYNC_TYPE, 'Failed to claim webhook event #' . $eventId . ': ' . $e->getMessage());
            } catch (Throwable $loggingFailure) {
            }
            continue;
        }

        if ($event === null) {
            // Already claimed by another run since the candidate scan above, or no longer
            // eligible - not an error, just move on to the next candidate.
            continue;
        }

        $summary['processed']++;
        // wc_webhook_claim_event() already incremented this and returned the new value.
        $newAttempts = (int) $event['attempts'];

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
 * through the exact same row-locked claim (wc_webhook_claim_event() - Phase 6F hardening,
 * shared with the automatic path rather than a second, duplicated claim query) and the same
 * dispatch/bookkeeping, and still writes to sync_logs the same way.
 *
 * @throws RuntimeException if the event is not currently in a retryable state (e.g. already
 * completed, or another run has it claimed).
 */
function wc_webhook_retry_event_now(PDO $pdo, int $eventId): void
{
    // wc_webhook_claim_event() only ever claims a 'pending'/'failed' row (by design, it's the
    // same helper the attempt-cap-respecting automatic path uses) - checked separately here
    // first so "already completed" gets its own clear message rather than the generic
    // "being processed by another run" one a failed claim would otherwise imply.
    $checkStmt = $pdo->prepare('SELECT status FROM webhook_events WHERE id = ?');
    $checkStmt->execute([$eventId]);
    $currentStatus = $checkStmt->fetchColumn();

    if ($currentStatus === false) {
        throw new RuntimeException('Webhook event not found.');
    }
    if ($currentStatus === 'completed') {
        throw new RuntimeException('This event already completed successfully - nothing to retry.');
    }

    $event = wc_webhook_claim_event($pdo, $eventId);
    if ($event === null) {
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
