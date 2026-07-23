<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/shipments.php';
app_require_permission('shipments.view');

$appTitle = 'Shipments';
$pdo = app_db();

$shipments = shipment_list_all($pdo);
$canManage = app_has_permission('shipments.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Shipments</h2>
        <p class="text-muted mb-0">Every physical package leaving the warehouse - from orders, Ship My Box requests, or manual (replacement/warranty).</p>
    </div>
    <?php if ($canManage): ?>
        <div class="action-bar">
            <a class="btn btn-primary" href="/modules/shipments/create.php">New Manual Shipment</a>
        </div>
    <?php endif; ?>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Shipment</th>
                <th>Customer</th>
                <th>Source</th>
                <th>Tracking</th>
                <th>Status</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($shipments as $shipment): ?>
                <tr>
                    <td><?php echo app_escape($shipment['shipment_number']); ?></td>
                    <td><?php echo app_escape($shipment['customer_name']); ?></td>
                    <td><?php echo app_escape(ucfirst(str_replace('_', ' ', $shipment['source_type']))); ?></td>
                    <td>
                        <?php if (!empty($shipment['tracking_number'])): ?>
                            <?php echo app_escape($shipment['tracking_number']); ?>
                            <?php if (!empty($shipment['carrier'])): ?><div class="text-muted small"><?php echo app_escape($shipment['carrier']); ?></div><?php endif; ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td><?php echo shipment_status_badge($shipment['shipping_status']); ?></td>
                    <td><?php echo app_escape($shipment['created_at']); ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/modules/shipments/view.php?id=<?php echo (int) $shipment['id']; ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($shipments === []): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="empty-state-title">No Shipments Yet</div>
                            <p class="empty-state-text">Packages leaving the warehouse - from orders, Ship My Box, or manual - will appear here.</p>
                            <?php if ($canManage): ?>
                                <a class="btn btn-primary btn-sm" href="/modules/shipments/create.php">New Manual Shipment</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
