<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/wc_webhook.php';
app_require_permission('settings.manage');

/**
 * Admin view of the webhook_events queue (Phase 6E) - follows modules/sync-logs/index.php's
 * pattern (pagination, filters, status badges), plus what a queue additionally needs: a
 * status/resource filter, the raw payload for debugging, and a manual Retry action that
 * calls wc_webhook_retry_event_now() (includes/wc_webhook.php) - the exact same claim/
 * dispatch/bookkeeping the automatic cron processor uses, just run synchronously so the
 * admin gets an immediate result instead of waiting for the next tick.
 */

$appTitle = 'Webhook Events';
$pdo = app_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && ($_POST['action'] ?? '') === 'retry') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId < 1) {
            $error = 'Invalid event.';
        } else {
            try {
                wc_webhook_retry_event_now($pdo, $eventId);
                app_redirect('/modules/webhooks/events.php?retried=1');
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                // wc_webhook_retry_event_now() already logged the real reason to sync_logs
                // before re-throwing - this is just the inline banner for this page.
                $error = 'Retry failed: ' . $exception->getMessage();
            }
        }
    }
}

$statusOptions = ['pending', 'processing', 'completed', 'failed'];
$resourceOptions = ['product', 'order', 'customer'];
$filterStatus = in_array($_GET['status'] ?? '', $statusOptions, true) ? $_GET['status'] : null;
$filterResource = in_array($_GET['resource'] ?? '', $resourceOptions, true) ? $_GET['resource'] : null;

$conditions = [];
$params = [];
if ($filterStatus !== null) {
    $conditions[] = 'status = ?';
    $params[] = $filterStatus;
}
if ($filterResource !== null) {
    $conditions[] = 'resource = ?';
    $params[] = $filterResource;
}
$whereSql = $conditions !== [] ? (' WHERE ' . implode(' AND ', $conditions)) : '';

// Same COUNT + LIMIT/OFFSET convention as modules/products/index.php, modules/orders/index.php,
// and modules/sync-logs/index.php (Phase 6D).
$perPage = 50;
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM webhook_events' . $whereSql);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT id, woocommerce_delivery_id, topic, resource, resource_id, payload_json, status, attempts, last_error, next_retry_at, created_at, processed_at
    FROM webhook_events
    {$whereSql}
    ORDER BY id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pending + failed counts for the summary strip - same shape modules/integrations/
// woocommerce.php's stat cards already use elsewhere on this page.
$summaryStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
    FROM webhook_events
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Webhook Events</h2>
        <p class="text-muted mb-0">Inbound WooCommerce webhook deliveries and their processing status.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/integrations/woocommerce.php">Back to WooCommerce Sync</a>
</div>

<?php if (isset($_GET['retried'])): ?>
    <div class="alert alert-success">Event retried successfully.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?php echo (int) ($summary['pending_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Waiting for the next processing run.</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Failed</div>
            <div class="stat-value <?php echo (int) ($summary['failed_count'] ?? 0) > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) ($summary['failed_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Includes events still auto-retrying and ones that exhausted their attempts.</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Completed</div>
            <div class="stat-value"><?php echo (int) ($summary['completed_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Successfully processed events.</div>
        </div>
    </div>
</div>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($statusOptions as $statusOption): ?>
                    <option value="<?php echo app_escape($statusOption); ?>" <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>><?php echo app_escape(ucfirst($statusOption)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Resource</label>
            <select name="resource" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($resourceOptions as $resourceOption): ?>
                    <option value="<?php echo app_escape($resourceOption); ?>" <?php echo $filterResource === $resourceOption ? 'selected' : ''; ?>><?php echo app_escape(ucfirst($resourceOption)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/webhooks/events.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Topic</th>
                <th>Resource</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Last Error</th>
                <th>Received</th>
                <th>Payload</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td data-label="Topic"><?php echo app_escape($event['topic']); ?></td>
                    <td data-label="Resource">
                        <?php echo app_escape(ucfirst($event['resource'])); ?>
                        <?php if ($event['resource_id'] !== null): ?>
                            <span class="text-muted small">(WC #<?php echo (int) $event['resource_id']; ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Status">
                        <?php
                        $statusBadgeColor = [
                            'pending' => 'secondary',
                            'processing' => 'info text-dark',
                            'completed' => 'success',
                            'failed' => 'danger',
                        ][$event['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?php echo $statusBadgeColor; ?>"><?php echo app_escape($event['status']); ?></span>
                        <?php if ($event['status'] === 'failed' && (int) $event['attempts'] >= WC_WEBHOOK_MAX_ATTEMPTS): ?>
                            <span class="badge bg-dark">Attempts exhausted</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Attempts"><?php echo (int) $event['attempts']; ?> / <?php echo WC_WEBHOOK_MAX_ATTEMPTS; ?></td>
                    <td data-label="Last Error"><?php echo $event['last_error'] !== null ? app_escape($event['last_error']) : '-'; ?></td>
                    <td data-label="Received"><?php echo app_escape($event['created_at']); ?></td>
                    <td data-label="Payload">
                        <details>
                            <summary class="small">View</summary>
                            <pre class="small bg-light p-2 rounded mt-2 mb-0" style="max-width: 360px; max-height: 240px; overflow: auto; white-space: pre-wrap;"><?php echo app_escape(json_encode(json_decode($event['payload_json'], true), JSON_PRETTY_PRINT)); ?></pre>
                        </details>
                    </td>
                    <td data-label="" class="text-end">
                        <?php if ($event['status'] !== 'completed'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Retry this webhook event now?');">
                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Retry</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($events === []): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <div class="empty-state-title">No Webhook Events<?php echo ($filterStatus !== null || $filterResource !== null) ? ' Match' : ' Yet'; ?></div>
                            <p class="empty-state-text"><?php echo ($filterStatus !== null || $filterResource !== null) ? 'Try adjusting or clearing your filters.' : 'Inbound WooCommerce webhook deliveries will appear here once configured - see WooCommerce Sync for the receiver URL.'; ?></p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    $pageUrl = static function (int $targetPage): string {
        return '/modules/webhooks/events.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $rangeStart; ?>&ndash;<?php echo (int) $rangeEnd; ?> of <?php echo (int) $totalCount; ?> event<?php echo $totalCount === 1 ? '' : 's'; ?>
            <?php else: ?>
                0 events
            <?php endif; ?>
        </p>
        <?php if ($totalPages > 1): ?>
            <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(max(1, $page - 1))); ?>">&laquo; Prev</a>
                <span class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(min($totalPages, $page + 1))); ?>">Next &raquo;</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
