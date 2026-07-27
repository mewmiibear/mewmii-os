<?php

/**
 * Phase 11A - Unified Outbound Job Queue.
 *
 * A generic, type-agnostic background job queue over the `outbound_jobs` table (see
 * database/schema.sql / database/migrate_outbound_jobs.php). This file has NO knowledge of
 * WooCommerce, email, or any other concrete job type - it only knows how to enqueue, claim,
 * complete, fail, retry, release, and clean up rows. What a job of a given `type` actually DOES
 * is supplied by the caller as a callable (see job_queue_process_batch() below) - typically
 * built in cli/job_worker.php, which is the one place that knows "type X means call function Y"
 * and always calls an EXISTING, unmodified business-logic function to do the real work (e.g.
 * wc_client_auto_sync_product() for 'woocommerce.product.sync' - see that job type's handler in
 * cli/job_worker.php). This file never duplicates any sync/import/business logic itself.
 *
 * Reliability shape is deliberately the same as includes/wc_webhook.php's webhook_events queue:
 * - job_claim() locks one row under SELECT ... FOR UPDATE inside its own short transaction (not
 *   just an optimistic status-check UPDATE), same as wc_webhook_claim_event().
 * - job_recover_stale_processing() returns an abandoned 'processing' row (a crashed worker) back
 *   to 'pending', same as wc_webhook_recover_stale_processing().
 * - job_fail() schedules an exponential-backoff retry (or marks the job permanently failed once
 *   max_attempts is exhausted), same shape as wc_webhook_process_pending_events_body()'s own
 *   backoff handling.
 * - job_cleanup() only ever deletes status='completed' rows past the retention window, same as
 *   wc_webhook_cleanup_completed_events().
 * - job_queue_process_batch() wraps a whole run in a MySQL advisory lock (GET_LOCK(name, 0),
 *   never blocks), same as wc_webhook_process_pending_events().
 *
 * One deliberate difference from webhook_events' claim/attempts convention: job_claim() does
 * NOT increment `attempts` (webhook's wc_webhook_claim_event() does). Here, `attempts` is
 * incremented only in job_fail() - i.e. only a genuine failed EXECUTION counts against
 * max_attempts, not merely being claimed. This is what makes job_release() (a job claimed but
 * deliberately not executed - e.g. a graceful worker shutdown mid-batch) a clean, side-effect-
 * free operation: releasing a job can never leave attempts artificially inflated.
 */

require_once __DIR__ . '/sync_log.php';

const JOB_QUEUE_SYNC_TYPE = 'outbound_job_queue';

const JOB_QUEUE_DEFAULT_MAX_ATTEMPTS = 5;
const JOB_QUEUE_DEFAULT_PRIORITY = 100;

// Same reasoning as WC_WEBHOOK_PROCESSING_TIMEOUT_DEFAULT/WC_WEBHOOK_CLEANUP_DAYS_DEFAULT - a
// 'processing' row whose locked_at is older than this is treated as abandoned (the worker
// process that claimed it died before reaching job_complete()/job_fail()).
const JOB_QUEUE_PROCESSING_TIMEOUT_MINUTES = 15;
const JOB_QUEUE_CLEANUP_DAYS_DEFAULT = 90;

// MySQL advisory lock name for a whole batch run - distinct from every other lock name already
// used in this app (WC_ORDER_IMPORT_LOCK_NAME/WC_PRODUCT_IMPORT_LOCK_NAME/WC_WEBHOOK_LOCK_NAME/
// WC_CLIENT_PRODUCT_SYNC_LOCK_NAME) so a job-queue run never contends with any of them.
const JOB_QUEUE_LOCK_NAME = 'mewmii_outbound_job_queue';
const JOB_QUEUE_LOCK_BUSY_CODE = 42307;

/**
 * Queues one job. Never executes anything itself - purely an INSERT. $payload is whatever the
 * eventual handler for $type needs (see cli/job_worker.php); stored as JSON, opaque to this
 * file. $entityType/$entityId are optional (not every job is about one specific local record).
 *
 * @param array{priority?: int, max_attempts?: int, run_after?: ?string} $options
 * @return int the new job's id.
 */
function job_enqueue(PDO $pdo, string $type, ?string $entityType = null, ?int $entityId = null, array $payload = [], array $options = []): int
{
    $priority = (int) ($options['priority'] ?? JOB_QUEUE_DEFAULT_PRIORITY);
    $maxAttempts = (int) ($options['max_attempts'] ?? JOB_QUEUE_DEFAULT_MAX_ATTEMPTS);
    $runAfter = $options['run_after'] ?? null;

    $stmt = $pdo->prepare('
        INSERT INTO outbound_jobs (type, entity_type, entity_id, payload_json, status, priority, max_attempts, run_after)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $type,
        $entityType,
        $entityId,
        $payload !== [] ? json_encode($payload) : null,
        'pending',
        $priority,
        $maxAttempts,
        $runAfter,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Claims exactly one due job under a row lock, same two-step shape as
 * wc_webhook_claim_event(): a short transaction does SELECT ... FOR UPDATE, verifies the row is
 * still claimable, then marks it 'processing' - all before any actual job handler runs (which
 * happens outside this transaction, in the caller). Does NOT increment attempts - see this
 * file's own docblock for why.
 *
 * "Due" = status IN ('pending','failed') AND attempts < max_attempts AND (run_after IS NULL OR
 * run_after <= NOW()), ordered by priority ASC then id ASC (lower priority number first, oldest
 * job first within the same priority).
 *
 * @param string[] $types restrict claiming to these job types only (e.g. a worker that only
 * knows how to handle a subset of types); empty array means "any type".
 * @return array|null the claimed row, or null if nothing is currently claimable.
 */
function job_claim(PDO $pdo, string $workerId, array $types = []): ?array
{
    $typeCondition = '';
    $typeParams = [];
    if ($types !== []) {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $typeCondition = " AND type IN ({$placeholders})";
        $typeParams = $types;
    }

    $candidateStmt = $pdo->prepare("
        SELECT id
        FROM outbound_jobs
        WHERE status IN ('pending', 'failed') AND attempts < max_attempts
          AND (run_after IS NULL OR run_after <= UTC_TIMESTAMP())
          {$typeCondition}
        ORDER BY priority ASC, id ASC
        LIMIT 1
    ");
    $candidateStmt->execute($typeParams);
    $candidateId = $candidateStmt->fetchColumn();

    if ($candidateId === false) {
        return null;
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('
            SELECT id, type, entity_type, entity_id, payload_json, status, priority, attempts, max_attempts, run_after
            FROM outbound_jobs WHERE id = ? FOR UPDATE
        ');
        $stmt->execute([(int) $candidateId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job === false || !in_array($job['status'], ['pending', 'failed'], true) || (int) $job['attempts'] >= (int) $job['max_attempts']) {
            // Already claimed by another worker/run since the candidate scan above, or no
            // longer eligible - not an error, the caller simply tries the next candidate.
            $pdo->rollBack();

            return null;
        }

        $pdo->prepare("UPDATE outbound_jobs SET status = 'processing', locked_at = UTC_TIMESTAMP(), locked_by = ? WHERE id = ?")
            ->execute([$workerId, (int) $job['id']]);
        $pdo->commit();

        $job['status'] = 'processing';

        return $job;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/** Marks a claimed job as successfully completed. */
function job_complete(PDO $pdo, int $jobId): void
{
    $pdo->prepare("
        UPDATE outbound_jobs
        SET status = 'completed', completed_at = UTC_TIMESTAMP(), locked_at = NULL, locked_by = NULL, last_error = NULL
        WHERE id = ?
    ")->execute([$jobId]);
}

/**
 * Backoff schedule for a failed attempt - same 1/2/4/8/16-minute-capped-at-30 curve as
 * wc_webhook_calculate_next_retry(), for the same reasoning: short enough that a transient
 * failure recovers within a couple of worker ticks, long enough that a genuinely broken job
 * doesn't hammer the same failing path every tick.
 */
function job_calculate_next_retry(int $attempts): string
{
    $minutes = min(30, (int) (2 ** max(0, $attempts - 1)));

    return gmdate('Y-m-d H:i:s', time() + $minutes * 60);
}

/**
 * Records a failed execution attempt. This is the ONE place attempts is incremented (see this
 * file's own docblock). If the incremented attempts count is still under max_attempts, the job
 * goes back to 'failed'-but-claimable with an exponential-backoff run_after (same "status can be
 * 'failed' and still retryable" convention webhook_events already uses - distinguished from
 * "permanently exhausted" by comparing attempts to max_attempts, exactly as
 * modules/webhooks/events.php's UI already does for that table). Once attempts reaches
 * max_attempts, the job is terminally 'failed' (failed_at set, job_claim() will never select it
 * again) - modules/operations/job_queue.php's own "Attempts exhausted" badge uses the same
 * comparison.
 */
function job_fail(PDO $pdo, int $jobId, string $errorMessage): void
{
    $stmt = $pdo->prepare('SELECT attempts, max_attempts FROM outbound_jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return;
    }

    $newAttempts = (int) $row['attempts'] + 1;
    $exhausted = $newAttempts >= (int) $row['max_attempts'];

    if ($exhausted) {
        $pdo->prepare("
            UPDATE outbound_jobs
            SET status = 'failed', attempts = ?, last_error = ?, failed_at = UTC_TIMESTAMP(),
                locked_at = NULL, locked_by = NULL, run_after = NULL
            WHERE id = ?
        ")->execute([$newAttempts, $errorMessage, $jobId]);
    } else {
        $pdo->prepare("
            UPDATE outbound_jobs
            SET status = 'failed', attempts = ?, last_error = ?, run_after = ?,
                locked_at = NULL, locked_by = NULL
            WHERE id = ?
        ")->execute([$newAttempts, $errorMessage, job_calculate_next_retry($newAttempts), $jobId]);
    }
}

/**
 * Manual "Retry" (modules/operations/job_queue.php) - human-triggered, so unlike an automatic
 * backoff retry (job_fail() above), this gives the job a genuinely fresh attempt budget:
 * attempts resets to 0, run_after clears immediately, status goes back to 'pending'. Works
 * whether the job was mid-backoff or had already permanently exhausted its attempts - a human
 * asking for one more try should never be blocked by max_attempts. last_error is left in place
 * (not cleared) so the "what went wrong last time" context survives until a new attempt
 * overwrites it.
 */
function job_retry(PDO $pdo, int $jobId): void
{
    $pdo->prepare("
        UPDATE outbound_jobs
        SET status = 'pending', attempts = 0, run_after = NULL, locked_at = NULL, locked_by = NULL,
            failed_at = NULL
        WHERE id = ?
    ")->execute([$jobId]);
}

/**
 * Releases a claimed-but-not-executed job back to 'pending' with NO bookkeeping side effects -
 * attempts/run_after/last_error are all left exactly as they were. For a worker that claimed a
 * job but, for a reason unrelated to the job itself (e.g. a graceful shutdown signal mid-batch),
 * decided not to execute it this run. This is why job_claim() deliberately does not increment
 * attempts - releasing a job can never look like a failed attempt.
 */
function job_release(PDO $pdo, int $jobId): void
{
    $pdo->prepare("UPDATE outbound_jobs SET status = 'pending', locked_at = NULL, locked_by = NULL WHERE id = ?")
        ->execute([$jobId]);
}

/**
 * Same abandoned-processing-row recovery as wc_webhook_recover_stale_processing(): a
 * 'processing' row whose locked_at is older than JOB_QUEUE_PROCESSING_TIMEOUT_MINUTES means the
 * worker that claimed it died before reaching job_complete()/job_fail() - returned to 'pending'
 * so the normal claim flow simply picks it up again.
 *
 * @return int how many rows were recovered.
 */
function job_recover_stale_processing(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        UPDATE outbound_jobs
        SET status = 'pending', locked_at = NULL, locked_by = NULL
        WHERE status = 'processing' AND locked_at < (UTC_TIMESTAMP() - INTERVAL ? MINUTE)
    ");
    $stmt->bindValue(1, JOB_QUEUE_PROCESSING_TIMEOUT_MINUTES, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
}

/**
 * Same retention-only cleanup as wc_webhook_cleanup_completed_events() - only ever deletes
 * status='completed' rows past the window; pending/processing/failed rows are never touched by
 * this, regardless of age.
 *
 * @return int how many rows were deleted.
 */
function job_cleanup(PDO $pdo, ?int $days = null): int
{
    $days = $days ?? JOB_QUEUE_CLEANUP_DAYS_DEFAULT;

    $stmt = $pdo->prepare("
        DELETE FROM outbound_jobs
        WHERE status = 'completed' AND completed_at IS NOT NULL AND completed_at < (UTC_TIMESTAMP() - INTERVAL ? DAY)
    ");
    $stmt->bindValue(1, $days, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
}

/**
 * The actual batch loop, only ever called by job_queue_process_batch() below, which holds the
 * advisory lock for its whole duration. $handlers maps job `type` => callable(array $job, PDO
 * $pdo): void - the callable should throw on failure (caught here and turned into job_fail())
 * and simply return on success (turned into job_complete()). A type with no registered handler
 * is treated as a per-job failure (not a whole-batch abort), same "one bad item never stops the
 * batch" convention as wc_webhook_process_pending_events_body().
 *
 * Every attempt - success or failure - also gets a sync_logs row under JOB_QUEUE_SYNC_TYPE (see
 * job_queue_log_result() below), reusing the existing sync_logs table/UI (modules/sync-logs)
 * rather than a second logging path.
 *
 * @param array<string, callable> $handlers
 * @return array{recovered: int, processed: int, completed: int, retrying: int, failed: int}
 */
function job_queue_process_batch_body(PDO $pdo, array $handlers, int $batchSize, string $workerId): array
{
    $summary = ['recovered' => 0, 'processed' => 0, 'completed' => 0, 'retrying' => 0, 'failed' => 0];

    $summary['recovered'] = job_recover_stale_processing($pdo);

    for ($i = 0; $i < $batchSize; $i++) {
        $job = job_claim($pdo, $workerId, array_keys($handlers));
        if ($job === null) {
            break;
        }

        $summary['processed']++;
        $startedAt = microtime(true);
        $attemptNumber = (int) $job['attempts'] + 1;

        $handler = $handlers[$job['type']] ?? null;

        try {
            if ($handler === null) {
                throw new RuntimeException('No handler registered for job type "' . $job['type'] . '".');
            }

            $handler($job, $pdo);

            job_complete($pdo, (int) $job['id']);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            job_queue_log_result($pdo, $job, 'success', $attemptNumber, $durationMs, null);
            $summary['completed']++;
        } catch (Throwable $e) {
            job_fail($pdo, (int) $job['id'], $e->getMessage());
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            job_queue_log_result($pdo, $job, 'failed', $attemptNumber, $durationMs, $e->getMessage());

            if ($attemptNumber >= (int) $job['max_attempts']) {
                $summary['failed']++;
            } else {
                $summary['retrying']++;
            }
        }
    }

    return $summary;
}

/**
 * Requirement: every job execution logs job id, entity, action (type), duration, attempt, and
 * result - reuses sync_logs (same table modules/sync-logs/index.php already renders) rather
 * than a second logging system. reference_id carries entity_id (the local record the job was
 * about) when present, falling back to the job's own id so a job with no entity_id still links
 * to something identifiable, matching every other sync_log_* caller's own reference_id
 * convention in this codebase.
 */
function job_queue_log_result(PDO $pdo, array $job, string $result, int $attemptNumber, int $durationMs, ?string $errorMessage): void
{
    $description = sprintf(
        'job_id=%d action=%s entity=%s attempt=%d/%d duration_ms=%d result=%s%s',
        (int) $job['id'],
        $job['type'],
        $job['entity_type'] !== null ? ($job['entity_type'] . '#' . ($job['entity_id'] ?? '?')) : '(none)',
        $attemptNumber,
        (int) $job['max_attempts'],
        $durationMs,
        $result,
        $errorMessage !== null ? (': ' . $errorMessage) : ''
    );

    $referenceId = $job['entity_id'] !== null ? (int) $job['entity_id'] : (int) $job['id'];

    sync_log_write($pdo, JOB_QUEUE_SYNC_TYPE, $result === 'success' ? 'success' : 'failed', $referenceId, $description);
}

/**
 * Locked entrypoint - GET_LOCK(name, 0)-then-finally-RELEASE_LOCK, same shape as
 * wc_webhook_process_pending_events()/wc_client_sync_all_products(), so cli/job_worker.php and
 * any future second caller (there is none today) can never run overlapping batches against each
 * other. $workerId defaults to a reasonably unique per-process identifier (hostname:pid) if the
 * caller doesn't supply one - stored per-claimed-job in locked_by purely for diagnostics.
 *
 * @param array<string, callable> $handlers job type => callable(array $job, PDO $pdo): void
 * @throws RuntimeException with code JOB_QUEUE_LOCK_BUSY_CODE if another run already holds the
 * lock - callers should treat this as benign/expected, not a real failure.
 */
function job_queue_process_batch(PDO $pdo, array $handlers, int $batchSize = 50, ?string $workerId = null): array
{
    $workerId = $workerId ?? (gethostname() ?: 'worker') . ':' . getmypid();

    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockStmt->execute([JOB_QUEUE_LOCK_NAME]);

    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException(
            'Another job queue run is already in progress - skipped this run.',
            JOB_QUEUE_LOCK_BUSY_CODE
        );
    }

    try {
        return job_queue_process_batch_body($pdo, $handlers, $batchSize, $workerId);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([JOB_QUEUE_LOCK_NAME]);
    }
}
