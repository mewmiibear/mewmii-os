<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('settings.manage');

$appTitle = 'Sync Logs';
$pdo = app_db();

// Phase 6D (Production Hardening audit) - real pagination, replacing the previous hardcoded
// LIMIT 50 with no way to reach anything beyond it. Same COUNT + LIMIT/OFFSET convention as
// modules/products/index.php and modules/orders/index.php.
$perPage = 50;
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;

$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM sync_logs')->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT id, sync_type, reference_id, status, error_message, created_at
    FROM sync_logs
    ORDER BY id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute();
$syncLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Bugfix pass: reference_id is a bare product id for these two sync_types (see
// includes/wc_client.php/includes/wc_product_import.php) - batched into one lookup rather
// than a query per row, then shown as "SKU - Name" instead of a number nobody can act on.
// Other sync_types (order tracking/import) reference a different entity, so this only
// annotates rows where the id is actually known to mean "a product".
$productReferenceIds = array_values(array_unique(array_map(
    static fn (array $log): int => (int) $log['reference_id'],
    array_filter($syncLogs, static fn (array $log): bool => in_array($log['sync_type'], ['woocommerce_product_sync', 'woocommerce_product_import'], true) && $log['reference_id'] !== null)
)));
$productLabelsById = [];
if ($productReferenceIds !== []) {
    $placeholders = implode(',', array_fill(0, count($productReferenceIds), '?'));
    $productLookupStmt = $pdo->prepare("SELECT id, sku, name FROM products WHERE id IN ({$placeholders})");
    $productLookupStmt->execute($productReferenceIds);
    foreach ($productLookupStmt->fetchAll(PDO::FETCH_ASSOC) as $productRow) {
        $productLabelsById[(int) $productRow['id']] = $productRow['sku'] . ' - ' . $productRow['name'];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">Sync Logs</h1>
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
                    <td>
                        <?php if ($log['reference_id'] === null): ?>
                            -
                        <?php elseif (isset($productLabelsById[(int) $log['reference_id']])): ?>
                            <?php echo app_escape($productLabelsById[(int) $log['reference_id']]); ?>
                            <span class="text-muted small">(#<?php echo (int) $log['reference_id']; ?>)</span>
                        <?php else: ?>
                            #<?php echo (int) $log['reference_id']; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($log['status'] === 'success'): ?>
                            <span class="badge bg-success">success</span>
                        <?php elseif ($log['status'] === 'warning'): ?>
                            <span class="badge bg-warning text-dark">warning</span>
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

    <?php
    $pageUrl = static function (int $targetPage): string {
        return '/modules/sync-logs/index.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $rangeStart; ?>&ndash;<?php echo (int) $rangeEnd; ?> of <?php echo (int) $totalCount; ?> log entr<?php echo $totalCount === 1 ? 'y' : 'ies'; ?>
            <?php else: ?>
                0 log entries
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
