<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/wc_client.php';
require_once __DIR__ . '/../../includes/wc_order_import.php';
app_require_permission('settings.manage');

/**
 * WooCommerce order import diagnostics + manual trigger (Phase 1: polling only, no webhook).
 * Read-only status page plus two POST actions (Test Connection, Import Orders Now) - all the
 * actual import logic lives in includes/wc_order_import.php, this file is presentation only.
 */

$appTitle = 'WooCommerce Orders';
$pdo = app_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !empty($_POST['test_connection'])) {
        try {
            wc_client_get('orders', ['per_page' => 1]);
            app_redirect('/modules/integrations/woocommerce.php?tested=1&ok=1');
        } catch (Throwable $exception) {
            app_redirect('/modules/integrations/woocommerce.php?tested=1&ok=0&message=' . urlencode($exception->getMessage()));
        }
    } elseif ($error === '' && !empty($_POST['import_orders'])) {
        try {
            $summary = wc_order_import_run($pdo, 20);
            app_redirect('/modules/integrations/woocommerce.php?imported=1&created=' . $summary['created'] . '&updated=' . $summary['updated'] . '&skipped=' . $summary['skipped'] . '&failed=' . $summary['failed']);
        } catch (Throwable $exception) {
            app_redirect('/modules/integrations/woocommerce.php?imported=1&created=0&updated=0&skipped=0&failed=0&message=' . urlencode($exception->getMessage()));
        }
    }
}

$isConfigured = wc_client_is_configured();

$statsStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        MAX(created_at) AS last_sync_at
    FROM sync_logs
    WHERE sync_type = ?
");
$statsStmt->execute([WC_ORDER_IMPORT_SYNC_TYPE]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$recentLogsStmt = $pdo->prepare('
    SELECT id, reference_id, status, error_message, created_at
    FROM sync_logs
    WHERE sync_type = ?
    ORDER BY id DESC
    LIMIT 10
');
$recentLogsStmt->execute([WC_ORDER_IMPORT_SYNC_TYPE]);
$recentLogs = $recentLogsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">WooCommerce Orders</h2>
        <p class="text-muted mb-0">Import orders from mewmiibear.com into Mewmii OS. Manual, admin-triggered - no webhook yet.</p>
    </div>
    <div class="action-bar">
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="test_connection" value="1">
            <button type="submit" class="btn btn-outline-secondary" <?php echo $isConfigured ? '' : 'disabled'; ?>>Test Connection</button>
        </form>
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="import_orders" value="1">
            <button type="submit" class="btn btn-primary" <?php echo $isConfigured ? '' : 'disabled'; ?>>Import Orders Now</button>
        </form>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if (isset($_GET['tested'])): ?>
    <?php if (($_GET['ok'] ?? '') === '1'): ?>
        <div class="alert alert-success">Connection test succeeded - WooCommerce API responded.</div>
    <?php else: ?>
        <div class="alert alert-danger">Connection test failed<?php echo isset($_GET['message']) ? ': ' . app_escape($_GET['message']) : '.'; ?></div>
    <?php endif; ?>
<?php endif; ?>

<?php if (isset($_GET['imported'])): ?>
    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-danger">Import failed: <?php echo app_escape($_GET['message']); ?></div>
    <?php else: ?>
        <div class="alert alert-success">
            Import finished - <?php echo (int) $_GET['created']; ?> created, <?php echo (int) $_GET['updated']; ?> updated,
            <?php echo (int) $_GET['skipped']; ?> skipped, <?php echo (int) $_GET['failed']; ?> failed.
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Connection</div>
            <div class="stat-value <?php echo $isConfigured ? '' : 'stat-value-alert'; ?>" style="font-size: 1.5rem;">
                <?php echo $isConfigured ? 'Configured' : 'Not Configured'; ?>
            </div>
            <div class="stat-helper mb-0"><?php echo $isConfigured ? 'WooCommerce API credentials are set.' : 'Set WC_URL / WC_CONSUMER_KEY / WC_CONSUMER_SECRET.'; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Last Sync</div>
            <div class="stat-value" style="font-size: 1.5rem;"><?php echo app_escape($stats['last_sync_at'] ?? 'Never'); ?></div>
            <div class="stat-helper mb-0">Most recent order import activity.</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Imported Orders</div>
            <div class="stat-value"><?php echo (int) ($stats['success_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Successful create/update events.</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Failed Sync</div>
            <div class="stat-value <?php echo (int) ($stats['failed_count'] ?? 0) > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) ($stats['failed_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Orders that failed to import.</div>
        </div>
    </div>
</div>

<div class="card p-4">
    <h5 class="mb-3">Recent Sync Activity</h5>
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Order</th>
                <th>Status</th>
                <th>Error</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td>
                        <?php if ($log['reference_id'] !== null): ?>
                            <a href="/modules/orders/view.php?id=<?php echo (int) $log['reference_id']; ?>">#<?php echo (int) $log['reference_id']; ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($log['status'] === 'success'): ?>
                            <span class="badge bg-success">success</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?php echo app_escape($log['status']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($log['error_message'] ?? '-'); ?></td>
                    <td><?php echo app_escape($log['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($recentLogs === []): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-state-title">No Import Activity Yet</div>
                            <p class="empty-state-text">Click "Import Orders Now" to pull recent orders from WooCommerce.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
