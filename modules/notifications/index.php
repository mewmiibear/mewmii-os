<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/notifications.php';
app_require_permission('dashboard.view');

/**
 * Phase 9B - Notification & Alert Center: the full list, mark-read actions, and a manual
 * "Generate Alerts Now" button (for testing/on-demand use without waiting for the
 * cli/generate_alerts.php cron entry - the button calls the exact same
 * notification_generate_alerts() function that script does, no second generation path).
 */

$appTitle = 'Notifications';
$pdo = app_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'generate') {
            $created = notification_generate_alerts($pdo);
            $totalCreated = array_sum($created);
            app_redirect('/modules/notifications/index.php?generated=' . $totalCreated);
        } elseif ($action === 'mark_read') {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            if ($notificationId > 0) {
                notification_mark_read($pdo, $notificationId);
            }
            app_redirect('/modules/notifications/index.php');
        } elseif ($action === 'mark_all_read') {
            notification_mark_all_read($pdo);
            app_redirect('/modules/notifications/index.php?all_read=1');
        } else {
            $error = 'Unknown action.';
        }
    }
}

$filterType = in_array($_GET['type'] ?? '', NOTIFICATION_TYPES, true) ? $_GET['type'] : null;
$unreadOnly = ($_GET['unread_only'] ?? '') === '1';

$whereSql = '';
$params = [];
if ($filterType !== null) {
    $whereSql .= ' AND type = ?';
    $params[] = $filterType;
}
if ($unreadOnly) {
    $whereSql .= ' AND read_status = 0';
}

$perPage = 50;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM mewmii_notifications WHERE 1 = 1 {$whereSql}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare("
    SELECT id, title, message, type, reference_id, read_status, created_at
    FROM mewmii_notifications
    WHERE 1 = 1 {$whereSql}
    ORDER BY created_at DESC, id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$listStmt->execute($params);
$notifications = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$unreadCount = notification_unread_count($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1"><i class="bi bi-bell"></i> Notifications</h2>
        <p class="page-description">Operational alerts generated from Inventory Risk, Cost Increases, Supplier Delays, and Overdue Supplier Orders.</p>
    </div>
    <div class="action-bar">
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="generate">
            <button type="submit" class="btn btn-outline-secondary">Generate Alerts Now</button>
        </form>
        <?php if ($unreadCount > 0): ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-outline-secondary">Mark All Read</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if (isset($_GET['generated'])): ?>
    <div class="alert alert-success"><?php echo (int) $_GET['generated']; ?> new notification(s) generated.</div>
<?php endif; ?>
<?php if (isset($_GET['all_read'])): ?>
    <div class="alert alert-success">All notifications marked as read.</div>
<?php endif; ?>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Type</label>
            <select name="type" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach (NOTIFICATION_TYPES as $typeOption): ?>
                    <option value="<?php echo app_escape($typeOption); ?>" <?php echo $filterType === $typeOption ? 'selected' : ''; ?>><?php echo app_escape(NOTIFICATION_TYPE_LABELS[$typeOption]); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="unread-only-toggle" name="unread_only" value="1" <?php echo $unreadOnly ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="unread-only-toggle">Unread only</label>
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/notifications/index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <?php if ($notifications === []): ?>
        <div class="empty-state">
            <div class="empty-state-title">No Notifications</div>
            <p class="empty-state-text">Nothing matches these filters, or none have been generated yet - try "Generate Alerts Now" above.</p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($notifications as $notification): ?>
                <div class="attention-item <?php echo $notification['read_status'] ? '' : 'tone-warning'; ?> d-flex justify-content-between align-items-start p-3">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary"><?php echo app_escape(NOTIFICATION_TYPE_LABELS[$notification['type']] ?? $notification['type']); ?></span>
                            <?php if (!$notification['read_status']): ?><span class="badge bg-danger">Unread</span><?php endif; ?>
                            <strong><?php echo app_escape($notification['title']); ?></strong>
                        </div>
                        <div class="text-muted small mt-1"><?php echo app_escape($notification['message']); ?></div>
                        <div class="text-muted small mt-1"><?php echo app_escape($notification['created_at']); ?></div>
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0 ms-3">
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo app_escape(notification_url_for($notification)); ?>">Open</a>
                        <?php if (!$notification['read_status']): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Mark Read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php
        $pageUrl = static function (int $targetPage): string {
            return '/modules/notifications/index.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
        };
        $rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $rangeEnd = min($totalCount, $page * $perPage);
        ?>
        <div class="d-flex justify-content-between align-items-center mt-3">
            <p class="text-muted small mb-0">
                Showing <?php echo (int) $rangeStart; ?>&ndash;<?php echo (int) $rangeEnd; ?> of <?php echo (int) $totalCount; ?> notification<?php echo $totalCount === 1 ? '' : 's'; ?>
            </p>
            <?php if ($totalPages > 1): ?>
                <div class="d-flex gap-2 align-items-center">
                    <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(max(1, $page - 1))); ?>">&laquo; Prev</a>
                    <span class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                    <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(min($totalPages, $page + 1))); ?>">Next &raquo;</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
