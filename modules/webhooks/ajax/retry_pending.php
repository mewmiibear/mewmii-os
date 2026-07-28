<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/wc_webhook.php';

ajax_require_permission('settings.manage');
ajax_require_csrf();

$pdo = app_db();
$batchSize = WC_WEBHOOK_BATCH_SIZE_DEFAULT;
$processedBefore = max(0, (int) ($_POST['processed_before'] ?? 0));
$successBefore = max(0, (int) ($_POST['success_before'] ?? 0));
$failureBefore = max(0, (int) ($_POST['failure_before'] ?? 0));

$totalPending = (int) $pdo->query("SELECT COUNT(*) FROM webhook_events WHERE status = 'pending'")->fetchColumn();

if ($totalPending === 0) {
    ajax_json([
        'status' => 'ok',
        'total_pending' => 0,
        'processed_in_batch' => 0,
        'success_in_batch' => 0,
        'failure_in_batch' => 0,
        'total_processed' => 0,
        'total_success' => 0,
        'total_failure' => 0,
        'remaining_pending' => 0,
        'complete' => true,
        'errors' => [],
    ]);
}

$stmt = $pdo->prepare('SELECT id FROM webhook_events WHERE status = ? ORDER BY created_at ASC, id ASC LIMIT ?');
$stmt->bindValue(1, 'pending', PDO::PARAM_STR);
$stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
$stmt->execute();
$eventIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$batchProcessed = 0;
$batchSucceeded = 0;
$batchFailed = 0;
$errors = [];

foreach ($eventIds as $eventId) {
    $batchProcessed++;

    try {
        wc_webhook_retry_event_now($pdo, $eventId);
        $batchSucceeded++;
    } catch (Throwable $exception) {
        $batchFailed++;
        $errors[] = 'Event #' . $eventId . ': ' . $exception->getMessage();
    }
}

$totalProcessed = $processedBefore + $batchProcessed;
$totalSucceeded = $successBefore + $batchSucceeded;
$totalFailed = $failureBefore + $batchFailed;

$remainingPending = (int) $pdo->query("SELECT COUNT(*) FROM webhook_events WHERE status = 'pending'")->fetchColumn();
$complete = $remainingPending === 0;

if ($complete) {
    app_log_action(
        (int) ($_SESSION['user_id'] ?? 0),
        'Retry All Pending Webhooks',
        'retried=' . $totalProcessed . ';success=' . $totalSucceeded . ';failed=' . $totalFailed
    );
}

ajax_json([
    'status' => 'ok',
    'total_pending' => $totalPending,
    'processed_in_batch' => $batchProcessed,
    'success_in_batch' => $batchSucceeded,
    'failure_in_batch' => $batchFailed,
    'total_processed' => $totalProcessed,
    'total_success' => $totalSucceeded,
    'total_failure' => $totalFailed,
    'remaining_pending' => $remainingPending,
    'complete' => $complete,
    'errors' => $errors,
]);
