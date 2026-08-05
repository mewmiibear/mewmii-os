<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/job_queue.php';
app_require_permission('settings.manage');

/**
 * Phase 11A (Unified Outbound Job Queue) - admin view of the outbound_jobs queue. Follows
 * modules/webhooks/events.php's exact pattern (stat cards, filter form, table with a payload/
 * error details view, manual Retry, cleanup) since it's the closest existing analog - another
 * queue table with the same reliability shape (see includes/job_queue.php's own docblock for
 * why). Read-only status page plus two POST actions (Retry, Clean Old Completed Jobs) - all the
 * actual queue logic lives in includes/job_queue.php, this file is presentation only.
 */

$appTitle = 'Job Queue';
$pdo = app_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && ($_POST['action'] ?? '') === 'retry') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId < 1) {
            $error = 'Invalid job.';
        } else {
            job_retry($pdo, $jobId);
            app_redirect('/modules/operations/job_queue.php?retried=1');
        }
    } elseif ($error === '' && ($_POST['action'] ?? '') === 'cleanup') {
        try {
            $removed = job_cleanup($pdo);
            app_redirect('/modules/operations/job_queue.php?cleaned=' . $removed);
        } catch (Throwable $exception) {
            $error = 'Cleanup failed: ' . $exception->getMessage();
        }
    }
}

$statusOptions = ['pending', 'processing', 'completed', 'failed'];
$filterStatus = in_array($_GET['status'] ?? '', $statusOptions, true) ? $_GET['status'] : null;

// Type options are read from what's actually in the table, not a fixed list - job `type` is
// deliberately an open string (see includes/job_queue.php's own docblock on extensibility), so
// this filter grows automatically as new job types are enqueued, with no code change here.
$typeOptionsStmt = $pdo->query('SELECT DISTINCT type FROM outbound_jobs ORDER BY type ASC');
$typeOptions = $typeOptionsStmt->fetchAll(PDO::FETCH_COLUMN);
$filterType = in_array($_GET['type'] ?? '', $typeOptions, true) ? $_GET['type'] : null;

$search = trim((string) ($_GET['q'] ?? ''));

$conditions = [];
$params = [];
if ($filterStatus !== null) {
    $conditions[] = 'status = ?';
    $params[] = $filterStatus;
}
if ($filterType !== null) {
    $conditions[] = 'type = ?';
    $params[] = $filterType;
}
if ($search !== '') {
    // Matches on type, last_error, or an exact entity_id (numeric search terms only) - covers
    // "find jobs about product #123" and "find jobs mentioning this error text" in one field.
    if (ctype_digit($search)) {
        $conditions[] = '(type LIKE ? OR last_error LIKE ? OR entity_id = ? OR id = ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = (int) $search;
        $params[] = (int) $search;
    } else {
        $conditions[] = '(type LIKE ? OR last_error LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
}
$whereSql = $conditions !== [] ? (' WHERE ' . implode(' AND ', $conditions)) : '';

// Same COUNT + LIMIT/OFFSET convention as modules/webhooks/events.php, modules/products/index.php.
$perPage = 50;
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM outbound_jobs' . $whereSql);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT id, type, entity_type, entity_id, payload_json, status, priority, attempts, max_attempts,
           run_after, locked_at, locked_by, completed_at, failed_at, last_error, created_at, updated_at
    FROM outbound_jobs
    {$whereSql}
    ORDER BY id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
    FROM outbound_jobs
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">Job Queue</h1>
        <p class="text-muted mb-0">Background outbound jobs (WooCommerce sync and future integrations) - queued by the app, executed by cli/job_worker.php.</p>
    </div>
    <div class="d-flex gap-2">
        <form method="post" class="d-inline" onsubmit="return confirm('Delete completed jobs older than <?php echo (int) JOB_QUEUE_CLEANUP_DAYS_DEFAULT; ?> days? Pending, processing, and failed jobs are never affected.');">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="cleanup">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Clean Old Completed Jobs</button>
        </form>
    </div>
</div>

<?php if (isset($_GET['retried'])): ?>
    <div class="alert alert-success">Job queued for retry.</div>
<?php endif; ?>
<?php if (isset($_GET['cleaned'])): ?>
    <div class="alert alert-success"><?php echo (int) $_GET['cleaned']; ?> old completed job(s) removed.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?php echo (int) ($summary['pending_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Waiting for the next worker run.</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Processing</div>
            <div class="stat-value"><?php echo (int) ($summary['processing_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Currently claimed by a worker.</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Failed</div>
            <div class="stat-value <?php echo (int) ($summary['failed_count'] ?? 0) > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) ($summary['failed_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Includes jobs still auto-retrying and ones that exhausted their attempts.</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Completed</div>
            <div class="stat-value"><?php echo (int) ($summary['completed_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Successfully processed jobs.</div>
        </div>
    </div>
</div>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Type, error, or job/entity id" value="<?php echo app_escape($search); ?>">
        </div>
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
            <label class="form-label small mb-1">Type</label>
            <select name="type" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($typeOptions as $typeOption): ?>
                    <option value="<?php echo app_escape($typeOption); ?>" <?php echo $filterType === $typeOption ? 'selected' : ''; ?>><?php echo app_escape($typeOption); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/operations/job_queue.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Job</th>
                <th>Type</th>
                <th>Entity</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Priority</th>
                <th>Created</th>
                <th>Error / Payload</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td data-label="Job">#<?php echo (int) $job['id']; ?></td>
                    <td data-label="Type"><code><?php echo app_escape($job['type']); ?></code></td>
                    <td data-label="Entity">
                        <?php if ($job['entity_type'] !== null): ?>
                            <?php echo app_escape(ucfirst($job['entity_type'])); ?> #<?php echo (int) $job['entity_id']; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Status">
                        <?php
                        $statusBadgeColor = [
                            'pending' => 'secondary',
                            'processing' => 'info text-dark',
                            'completed' => 'success',
                            'failed' => 'danger',
                        ][$job['status']] ?? 'secondary';
                        $exhausted = $job['status'] === 'failed' && (int) $job['attempts'] >= (int) $job['max_attempts'];
                        ?>
                        <span class="badge bg-<?php echo $statusBadgeColor; ?>"><?php echo app_escape($job['status']); ?></span>
                        <?php if ($exhausted): ?>
                            <span class="badge bg-dark">Attempts exhausted</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Attempts"><?php echo (int) $job['attempts']; ?> / <?php echo (int) $job['max_attempts']; ?></td>
                    <td data-label="Priority"><?php echo (int) $job['priority']; ?></td>
                    <td data-label="Created"><?php echo app_escape($job['created_at']); ?></td>
                    <td data-label="Error / Payload">
                        <?php if ($job['last_error'] !== null): ?>
                            <details>
                                <summary class="small text-danger">View error</summary>
                                <pre class="small bg-light p-2 rounded mt-2 mb-0" style="max-width: 360px; max-height: 200px; overflow: auto; white-space: pre-wrap;"><?php echo app_escape($job['last_error']); ?></pre>
                            </details>
                        <?php endif; ?>
                        <?php if ($job['payload_json'] !== null): ?>
                            <details>
                                <summary class="small">View payload</summary>
                                <pre class="small bg-light p-2 rounded mt-2 mb-0" style="max-width: 360px; max-height: 200px; overflow: auto; white-space: pre-wrap;"><?php echo app_escape(json_encode(json_decode($job['payload_json'], true), JSON_PRETTY_PRINT)); ?></pre>
                            </details>
                        <?php endif; ?>
                        <?php if ($job['last_error'] === null && $job['payload_json'] === null): ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="" class="text-end">
                        <?php if ($job['status'] !== 'completed' && $job['status'] !== 'processing'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Retry job #<?php echo (int) $job['id']; ?> now? It will run on the next worker tick with a fresh attempt count.');">
                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Retry</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($jobs === []): ?>
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <div class="empty-state-title">No Jobs<?php echo ($filterStatus !== null || $filterType !== null || $search !== '') ? ' Match' : ' Yet'; ?></div>
                            <p class="empty-state-text"><?php echo ($filterStatus !== null || $filterType !== null || $search !== '') ? 'Try adjusting or clearing your filters.' : 'Background jobs (WooCommerce sync, and future integrations) will appear here once queued.'; ?></p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    $pageUrl = static function (int $targetPage): string {
        return '/modules/operations/job_queue.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $rangeStart; ?>&ndash;<?php echo (int) $rangeEnd; ?> of <?php echo (int) $totalCount; ?> job<?php echo $totalCount === 1 ? '' : 's'; ?>
            <?php else: ?>
                0 jobs
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
