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

        if (!in_array($newStatus, $statuses, true)) {
            $error = 'Invalid status.';
        } elseif (in_array($oldStatus, $terminalStatuses, true)) {
            $error = 'This ship request is final and cannot be changed.';
        } elseif ($oldStatus === $newStatus) {
            $error = 'Ship request is already in that status.';
        } else {
            $pdo->beginTransaction();

            try {
                if ($newStatus === 'shipped') {
                    ship_request_process($pdo, $shipRequestId);
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

$activityStmt = $pdo->prepare("
    SELECT it.quantity, it.created_at, p.sku, p.name AS product_name
    FROM inventory_transactions it
    INNER JOIN ship_request_items sri ON sri.id = it.reference_id AND it.reference_type = 'ship_request_item'
    INNER JOIN products p ON p.id = it.product_id
    WHERE sri.ship_request_id = ? AND it.transaction_type = 'ship_my_box'
    ORDER BY it.created_at DESC, it.id DESC
");
$activityStmt->execute([$shipRequestId]);
$activity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = app_has_permission('ship-my-box.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Ship Request #<?php echo (int) $shipRequest['id']; ?></h2>
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
                <tr><th>Status</th><td><?php echo app_escape($shipRequest['status']); ?></td></tr>
                <tr><th>Shipping Fee</th><td><?php echo app_escape((string) $shipRequest['shipping_fee']); ?></td></tr>
                <tr><th>Weight</th><td><?php echo app_escape($shipRequest['weight'] !== null ? (string) $shipRequest['weight'] : '-'); ?></td></tr>
                <tr><th>Requested</th><td><?php echo app_escape($shipRequest['created_at']); ?></td></tr>
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
                        <th>Storage Source</th>
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
                                Lot #<?php echo (int) $item['storage_id']; ?>
                                (<?php echo app_escape($item['storage_status']); ?>,
                                <?php echo app_escape((string) $item['storage_remaining_quantity']); ?> left,
                                arrived <?php echo app_escape($item['arrival_date'] ?? '-'); ?>)
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
                <h5 class="mb-3">Change Status</h5>
                <?php if (in_array($shipRequest['status'], $terminalStatuses, true)): ?>
                    <span class="badge bg-secondary">Final</span>
                <?php else: ?>
                    <form method="post" class="d-flex gap-2 flex-wrap align-items-start">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

                        <select class="form-select form-select-sm w-auto" name="new_status" required>
                            <?php foreach ($statuses as $statusOption): ?>
                                <?php if ($statusOption === $shipRequest['status']) { continue; } ?>
                                <option value="<?php echo app_escape($statusOption); ?>"><?php echo app_escape($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button class="btn btn-primary btn-sm" type="submit">Update</button>
                    </form>
                    <p class="text-muted small mt-2 mb-0">Moving to "shipped" deducts the items from customer storage and logs inventory activity.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

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
