<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('settings.manage');

$appTitle = 'Sync Logs';
$pdo = app_db();

$stmt = $pdo->query('
    SELECT id, sync_type, reference_id, status, error_message, created_at
    FROM sync_logs
    ORDER BY id DESC
    LIMIT 50
');
$syncLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Sync Logs</h2>
        <p class="text-muted mb-0">WooCommerce and other integration sync activity.</p>
    </div>
</div>
<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Type</th>
                <th>Reference</th>
                <th>Status</th>
                <th>Error</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($syncLogs as $log): ?>
                <tr>
                    <td><?php echo app_escape($log['sync_type']); ?></td>
                    <td><?php echo $log['reference_id'] !== null ? (int) $log['reference_id'] : '-'; ?></td>
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
            <?php if ($syncLogs === []): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <div class="empty-state-title">No Sync Activity Yet</div>
                            <p class="empty-state-text">WooCommerce and other integration sync events will appear here.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
