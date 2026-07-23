<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ship_my_box.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('ship-my-box.view');

$appTitle = 'Ship Request Detail';
$error = '';

$statuses = ['pending', 'processing', 'shipped', 'completed'];
$terminalStatuses = ['completed'];

$shipRequestId = (int) ($_GET['id'] ?? 0);

if ($shipRequestId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Ship request not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$requestStmt = $pdo->prepare('
    SELECT sr.*, c.name AS customer_name, c.email AS customer_email
    FROM ship_requests sr
    INNER JOIN customers c ON c.id = sr.customer_id
    WHERE sr.id = ?
    LIMIT 1
');
$requestStmt->execute([$shipRequestId]);
$shipRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);

if (!$shipRequest) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Ship request not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !app_has_permission('ship-my-box.manage')) {
        http_response_code(403);
        $error = 'You do not have permission to manage ship requests.';
    }

    if ($error === '') {
        $newStatus = (string) ($_POST['new_status'] ?? '');
        $oldStatus = (string) $shipRequest['status'];
        $carrier = trim((string) ($_POST['carrier'] ?? ''));
        $trackingNumber = trim((string) ($_POST['tracking_number'] ?? ''));

        if (!in_array($newStatus, $statuses, true)) {
            $error = 'Invalid status.';
        } elseif (in_array($oldStatus, $terminalStatuses, true)) {
            $error = 'This ship request is final and cannot be changed.';
        } elseif ($oldStatus === $newStatus) {
            $error = 'Ship request is already in that status.';
        } elseif ($newStatus === 'shipped' && ($carrier === '' || $trackingNumber === '')) {
            $error = 'Carrier and tracking number are required to mark this shipped.';
        } else {
            $pdo->beginTransaction();

            try {
                if ($newStatus === 'shipped') {
                    // Creates a unified shipment (see includes/shipments.php) and consumes
                    // the underlying customer_storage lots - the one and only place that
                    // happens, so ship_requests.status is only ever a summary label here.
                    ship_request_process($pdo, $shipRequestId, $carrier, $trackingNumber);
                }

                $pdo->prepare('UPDATE ship_requests SET status = ? WHERE id = ?')
                    ->execute([$newStatus, $shipRequestId]);

                $pdo->commit();

                app_redirect('/modules/ship-my-box/view.php?id=' . $shipRequestId . '&updated=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to update ship request.';
            }
        }
    }

    if ($error !== '') {
        $requestStmt->execute([$shipRequestId]);
        $shipRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);
    }
}

$itemsStmt = $pdo->prepare('
    SELECT sri.id, sri.quantity, cs.id AS storage_id, cs.quantity AS storage_remaining_quantity, cs.status AS storage_status, cs.arrival_date, cs.variation_id,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name
    FROM ship_request_items sri
    INNER JOIN customer_storage cs ON cs.id = sri.customer_storage_id
    INNER JOIN products p ON p.id = cs.product_id
    LEFT JOIN product_variations pv ON pv.id = cs.variation_id
    WHERE sri.ship_request_id = ?
    ORDER BY sri.id ASC
');
$itemsStmt->execute([$shipRequestId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($items as &$item) {
    $item['variation_label'] = $item['variation_id'] !== null ? variation_build_label($pdo, (int) $item['variation_id']) : '';
}
unset($item);

$shipmentStmt = $pdo->prepare("
    SELECT id, shipment_number, carrier, tracking_number, shipping_status, shipped_at
    FROM shipments
    WHERE source_type = 'ship_my_box' AND source_id = ?
    LIMIT 1
");
$shipmentStmt->execute([$shipRequestId]);
$linkedShipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// Reads the SAME transaction shipment_mark_shipped() writes (reference_type = 'shipment_item',
// reference_id = a shipment_items row) - scoped to the shipment this ship request produced,
// since inventory_transactions is never written against ship_request_items directly.
$activity = [];
if ($linkedShipment !== null) {
    $activityStmt = $pdo->prepare("
        SELECT it.quantity, it.created_at, p.sku, p.name AS product_name
        FROM inventory_transactions it
        INNER JOIN shipment_items si ON si.id = it.reference_id AND it.reference_type = 'shipment_item'
        INNER JOIN products p ON p.id = it.product_id
        WHERE si.shipment_id = ? AND it.transaction_type = 'ship_my_box'
        ORDER BY it.created_at DESC, it.id DESC
    ");
    $activityStmt->execute([(int) $linkedShipment['id']]);
    $activity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
}

$timeline = ship_request_timeline($pdo, $shipRequest, $linkedShipment);

$canManage = app_has_permission('ship-my-box.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Ship Request <?php echo app_escape($shipRequest['request_number'] ?? ('#' . $shipRequest['id'])); ?></h2>
        <p class="text-muted mb-0"><?php echo app_escape($shipRequest['customer_name']); ?> &middot; <?php echo app_escape($shipRequest['customer_email'] ?? '-'); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/ship-my-box/index.php">Back to Ship My Box</a>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Ship request created.</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Ship request updated.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Shipment Status</h5>
            <table class="table table-borderless mb-0">
                <tr>
                    <th>Status</th>
                    <td><?php echo app_escape(ship_request_status_emoji($shipRequest['status'])); ?> <?php echo app_escape(ship_request_status_label($shipRequest['status'])); ?></td>
                </tr>
                <?php if ($linkedShipment !== null && !empty($linkedShipment['carrier'])): ?>
                    <tr><th>Courier</th><td><?php echo app_escape($linkedShipment['carrier']); ?></td></tr>
                <?php endif; ?>
                <?php if ($linkedShipment !== null && !empty($linkedShipment['tracking_number'])): ?>
                    <tr><th>Tracking Number</th><td><?php echo app_escape($linkedShipment['tracking_number']); ?></td></tr>
                <?php endif; ?>
                <tr><th>Shipping Fee</th><td>RM <?php echo app_escape(number_format((float) $shipRequest['shipping_fee'], 2)); ?></td></tr>
                <?php if ($shipRequest['weight'] !== null): ?>
                    <tr><th>Weight</th><td><?php echo app_escape(number_format((float) $shipRequest['weight'], 1)); ?> KG</td></tr>
                <?php endif; ?>
                <tr><th>Requested</th><td><?php echo app_escape($shipRequest['created_at']); ?></td></tr>
                <?php if ($linkedShipment !== null): ?>
                    <tr><th>Shipment ID</th><td><a href="/modules/shipments/view.php?id=<?php echo (int) $linkedShipment['id']; ?>"><?php echo app_escape($linkedShipment['shipment_number']); ?></a></td></tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="card p-4">
            <h5 class="mb-3">Items</h5>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Qty Requested</th>
                        <th>Customer Storage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo app_escape($item['sku']); ?></td>
                            <td>
                                <?php echo app_escape($item['product_name']); ?>
                                <?php if (!empty($item['variation_label'])): ?>
                                    <div class="text-muted small"><?php echo app_escape($item['variation_label']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo app_escape((string) $item['quantity']); ?></td>
                            <td>
                                <div class="fw-semibold">Customer Storage #<?php echo (int) $item['storage_id']; ?></div>
                                <div class="text-muted small">Status: <?php echo app_escape(ucfirst($item['storage_status'])); ?></div>
                                <div class="text-muted small">Remaining: <?php echo app_escape((string) $item['storage_remaining_quantity']); ?></div>
                                <div class="text-muted small">Arrival: <?php echo app_escape($item['arrival_date'] ?? 'Pending'); ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($items === []): ?>
                        <tr><td colspan="4" class="text-muted">No items on this ship request.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($canManage): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Actions</h5>
                <?php if (in_array($shipRequest['status'], $terminalStatuses, true)): ?>
                    <span class="badge bg-secondary">Final</span>
                <?php else: ?>
                    <?php $nextAction = ship_request_next_action($shipRequest['status']); ?>

                    <?php if ($shipRequest['status'] === 'shipped' && $linkedShipment !== null): ?>
                        <a class="btn btn-primary btn-sm mb-2" href="/modules/shipments/view.php?id=<?php echo (int) $linkedShipment['id']; ?>">View Tracking</a>
                    <?php endif; ?>

                    <?php if ($nextAction !== null): ?>
                        <form method="post" class="d-flex gap-2 flex-wrap align-items-start">
                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                            <input type="hidden" name="new_status" value="<?php echo app_escape($nextAction['target_status']); ?>">

                            <?php if ($nextAction['needs_tracking']): ?>
                                <input type="text" class="form-control form-control-sm" style="max-width: 160px;" name="carrier" placeholder="Carrier" required>
                                <input type="text" class="form-control form-control-sm" style="max-width: 160px;" name="tracking_number" placeholder="Tracking #" required>
                            <?php endif; ?>

                            <button class="btn <?php echo $shipRequest['status'] === 'shipped' ? 'btn-outline-secondary' : 'btn-primary'; ?> btn-sm" type="submit"><?php echo app_escape($nextAction['label']); ?></button>
                        </form>
                        <?php if ($nextAction['needs_tracking']): ?>
                            <p class="text-muted small mt-2 mb-0">Creates a shipment record, deducts the items from customer storage, and logs inventory activity.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card p-4 mb-4">
            <h5 class="mb-3">Shipment Timeline</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($timeline as $step): ?>
                    <li class="mb-3">
                        <div>✓ <?php echo app_escape($step['label']); ?></div>
                        <?php if ($step['detail'] !== null): ?>
                            <div class="text-muted small ps-3"><?php echo app_escape($step['detail']); ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card p-4">
            <h5 class="mb-3">Inventory Activity</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($activity as $tx): ?>
                    <li class="mb-3">
                        <div class="fw-semibold"><?php echo app_escape($tx['sku']); ?> &mdash; <?php echo app_escape($tx['product_name']); ?></div>
                        <div>Shipped: <?php echo app_escape((string) $tx['quantity']); ?></div>
                        <div class="text-muted small"><?php echo app_escape($tx['created_at']); ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if ($activity === []): ?>
                    <li class="text-muted">No shipment activity yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
