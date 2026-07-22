<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('inventory.view');

$appTitle = 'Stock Movement Report';
$pdo = app_db();

$pageSize = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

$total = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
$lastPage = max(1, (int) ceil($total / $pageSize));
$page = min($page, $lastPage);
$offset = ($page - 1) * $pageSize;

$txStmt = $pdo->prepare("
    SELECT it.id, it.transaction_type, it.quantity, it.reason, it.notes, it.balance_after,
           it.reference_type, it.reference_id, it.created_at, it.variation_id,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name
    FROM inventory_transactions it
    INNER JOIN products p ON p.id = it.product_id
    LEFT JOIN product_variations pv ON pv.id = it.variation_id
    ORDER BY it.created_at DESC, it.id DESC
    LIMIT {$pageSize} OFFSET {$offset}
");
$txStmt->execute();
$transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($transactions as &$txRow) {
    $txRow['variation_label'] = $txRow['variation_id'] !== null ? variation_build_label($pdo, (int) $txRow['variation_id']) : '';
}
unset($txRow);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Stock Movement Report</h2>
        <p class="text-muted mb-0">Complete inventory transaction history across every product.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Back to Inventory</a>
</div>

<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Product</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Balance</th>
                <th>Reason / Notes</th>
                <th>Reference</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $tx): ?>
                <tr>
                    <td>
                        <?php echo app_escape($tx['sku']); ?> &mdash; <?php echo app_escape($tx['product_name']); ?>
                        <?php if (!empty($tx['variation_label'])): ?>
                            <div class="text-muted small"><?php echo app_escape($tx['variation_label']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($tx['transaction_type']); ?></td>
                    <td><?php echo app_escape((string) $tx['quantity']); ?></td>
                    <td><?php echo $tx['balance_after'] !== null ? app_escape((string) $tx['balance_after']) : '&mdash;'; ?></td>
                    <td class="text-muted small">
                        <?php echo app_escape(implode(' — ', array_filter([$tx['reason'], $tx['notes']]))) ?: '&mdash;'; ?>
                    </td>
                    <td>
                        <?php if ($tx['reference_type'] === 'order' && $tx['reference_id']): ?>
                            <a href="/modules/orders/view.php?id=<?php echo (int) $tx['reference_id']; ?>">Order #<?php echo (int) $tx['reference_id']; ?></a>
                        <?php else: ?>
                            <?php echo app_escape($tx['reference_type'] ?? '-'); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($tx['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($transactions === []): ?>
                <tr><td colspan="7" class="text-muted">No inventory transactions yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <?php if ($page > 1): ?>
            <a class="btn btn-sm btn-outline-secondary" href="?page=<?php echo $page - 1; ?>">&larr; Newer</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <span class="text-muted small">Page <?php echo $page; ?> of <?php echo $lastPage; ?> (<?php echo $total; ?> total)</span>
        <?php if ($page < $lastPage): ?>
            <a class="btn btn-sm btn-outline-secondary" href="?page=<?php echo $page + 1; ?>">Older &rarr;</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
