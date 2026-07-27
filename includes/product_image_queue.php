<?php

/**
 * Background product image processing - the domain glue between includes/job_queue.php (the
 * generic Phase 11A queue, which knows nothing about images) and includes/image_upload.php /
 * includes/product_images.php (the existing, unmodified image pipeline and DB-write functions).
 * Reused, not duplicated: this file contains no image resize/WebP/GD code of its own - it only
 * decides WHEN to call image_upload_process_from_path() and product_image_set_main_from_path()/
 * product_image_add_gallery_from_paths() (see includes/product_images.php's own docblocks for
 * why those two "_from_path(s)" variants exist).
 *
 * Save-time flow (modules/products/create.php/edit.php): a new upload is validated and staged
 * (image_upload_stage() - fast, no GD) instead of fully processed, then queued here.
 * Worker flow (cli/job_worker.php): product_image_process_pending_job() reads the staged
 * path(s), runs the real (unchanged) resize+WebP pipeline, writes the product_images row(s),
 * cleans up the staged file, and re-queues a WooCommerce product sync so the new image(s)
 * actually reach the storefront once they exist.
 */

require_once __DIR__ . '/job_queue.php';
require_once __DIR__ . '/image_upload.php';
require_once __DIR__ . '/product_images.php';

const PRODUCT_IMAGE_PROCESS_JOB_TYPE = 'product.image.process';

/**
 * Queues one or more staged images for background processing. $pendingImages is a list of
 * ['role' => 'main'|'gallery', 'staged_path' => 'uploads/_pending/xxx.jpg'] entries (see
 * image_upload_stage()'s return value).
 *
 * Requirement: never create duplicate image jobs for the same product - if this product already
 * has a 'pending' (not yet claimed) job of this type, the new entries are merged into its
 * existing payload instead of inserting a second job row. Deliberately does NOT match a
 * 'processing' job (a worker may already be reading its payload right now - merging into it
 * risks the worker's own end-of-run payload write silently discarding the merge) or a 'failed'
 * one (its own backoff/attempts history stays isolated to what it originally covered; a new
 * upload gets a clean, fresh job rather than inheriting another job's retry count).
 *
 * @return int the job id (new or reused).
 */
function product_image_enqueue_processing(PDO $pdo, int $productId, array $pendingImages): int
{
    if ($pendingImages === []) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT id, payload_json FROM outbound_jobs
        WHERE type = ? AND entity_type = 'product' AND entity_id = ? AND status = 'pending'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([PRODUCT_IMAGE_PROCESS_JOB_TYPE, $productId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing !== false) {
        $existingPayload = json_decode((string) $existing['payload_json'], true);
        $existingImages = is_array($existingPayload['images'] ?? null) ? $existingPayload['images'] : [];

        $pdo->prepare('UPDATE outbound_jobs SET payload_json = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([json_encode(['images' => array_merge($existingImages, $pendingImages)]), (int) $existing['id']]);

        return (int) $existing['id'];
    }

    return job_enqueue($pdo, PRODUCT_IMAGE_PROCESS_JOB_TYPE, 'product', $productId, ['images' => $pendingImages]);
}

/**
 * The job handler, registered in cli/job_worker.php's $handlers map. Processes every staged
 * image entry still in the job's payload; each one that succeeds is immediately removed from
 * the payload and the SHRUNK payload is persisted back to the job row before any exception is
 * (re)thrown - this is what makes a retried job idempotent: if entry #2 of 3 fails, entries #1
 * and #3 are never reprocessed (never re-inserted into product_images, never re-staged) on the
 * next attempt, only #2 is retried.
 *
 * @throws Throwable the first error encountered, after every entry has been attempted and the
 * successful ones already persisted/removed - job_fail() (includes/job_queue.php) turns this
 * into the queue's existing retry/backoff, unchanged.
 */
function product_image_process_pending_job(array $job, PDO $pdo): void
{
    $productId = (int) ($job['entity_id'] ?? 0);
    if ($productId < 1) {
        throw new RuntimeException('Job #' . $job['id'] . ' has no valid product entity_id.');
    }

    $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
    $images = is_array($payload['images'] ?? null) ? $payload['images'] : [];

    if ($images === []) {
        // Nothing left to do - either an empty enqueue, or every entry already succeeded on a
        // prior attempt and this is a stray re-claim. Not an error.
        return;
    }

    $remaining = $images;
    $anyProcessed = false;
    $firstError = null;

    foreach ($images as $index => $entry) {
        $role = (string) ($entry['role'] ?? '');
        $stagedRelativePath = (string) ($entry['staged_path'] ?? '');
        $stagedFullPath = dirname(__DIR__) . '/' . ltrim($stagedRelativePath, '/');

        try {
            if ($stagedRelativePath === '' || !in_array($role, ['main', 'gallery'], true)) {
                throw new RuntimeException('Malformed image job entry (job #' . $job['id'] . ').');
            }

            $finalImagePath = image_upload_process_from_path($stagedFullPath, 'products');

            if ($role === 'main') {
                product_image_set_main_from_path($pdo, $productId, $finalImagePath);
            } else {
                product_image_add_gallery_from_paths($pdo, $productId, [$finalImagePath]);
            }

            image_upload_delete($stagedRelativePath);
            unset($remaining[$index]);
            $anyProcessed = true;
        } catch (Throwable $e) {
            if ($firstError === null) {
                $firstError = $e;
            }
        }
    }

    if ($anyProcessed) {
        $pdo->prepare('UPDATE outbound_jobs SET payload_json = ? WHERE id = ?')
            ->execute([json_encode(['images' => array_values($remaining)]), (int) $job['id']]);
    }

    if ($firstError !== null) {
        throw $firstError;
    }

    // Every staged image for this job is now a real product_images row - re-sync to
    // WooCommerce so the new image(s) actually reach the storefront (Phase 11A's existing
    // enqueue, reused unchanged; a no-op if Auto Sync is off, same as every other save-time
    // sync trigger in this app).
    require_once __DIR__ . '/wc_client.php';
    if (wc_client_auto_sync_enabled($pdo)) {
        wc_client_enqueue_product_sync($pdo, $productId);
    }
}
