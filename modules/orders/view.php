<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
app_require_permission('orders.view');

$appTitle = 'Order Detail';

$statusFields = [
    'order_status' => [
        'label' => 'Order Status',
        'values' => ['pending', 'processing', 'completed', 'cancelled'],
        'terminal' => ['completed', 'cancelled'],
    ],
    'payment_status' => [
        'label' => 'Payment Status',
        'values' => ['pending', 'paid', 'refunded', 'failed'],
        'terminal' => ['refunded'],
    ],
    'shipping_status' => [
        'label' => 'Shipping Status',
        'values' => ['pending', 'packed', 'shipped', 'delivered'],
        'terminal' => ['delivered'],
    ],
];

$orderId = (int) ($_GET['id'] ?? 0);
$error = '';

if ($orderId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$orderStmt = $pdo->prepare('
    SELECT o.*, c.name AS customer_name, c.email AS customer_email
    FROM mewmii_orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.id = ?
    LIMIT 1
');
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !app_has_permission('orders.manage')) {
        http_response_code(403);
        $error = 'You do not have permission to change order status.';
    }

    if ($error === '') {
        $statusField = (string) ($_POST['status_field'] ?? '');
        $newStatus = trim((string) ($_POST['new_status'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (!isset($statusFields[$statusField])) {
            $error = 'Invalid status field.';
        } elseif (!in_array($newStatus, $statusFields[$statusField]['values'], true)) {
            $error = 'Invalid status value.';
        } else {
            $oldStatus = (string) $order[$statusField];

            if (in_array($oldStatus, $statusFields[$statusField]['terminal'], true)) {
                $error = 'This status is final and cannot be changed.';
            } elseif ($oldStatus === $newStatus) {
                $error = 'Order is already in that status.';
            } else {
                $pdo->beginTransaction();

                try {
                    $updateStmt = $pdo->prepare("UPDATE mewmii_orders SET {$statusField} = ? WHERE id = ?");
                    $updateStmt->execute([$newStatus, $orderId]);

                    $description = sprintf(
                        "%s changed from '%s' to '%s'.",
                        $statusFields[$statusField]['label'],
                        $oldStatus,
                        $newStatus
                    );

                    if ($notes !== '') {
                        $description .= ' Notes: ' . $notes;
                    }

                    $eventStmt = $pdo->prepare('
                        INSERT INTO mewmii_order_events (order_id, event_type, description, created_by)
                        VALUES (?, ?, ?, ?)
                    ');
                    $eventStmt->execute([
                        $orderId,
                        $statusField . '_change',
                        $description,
                        $_SESSION['user_id'] ?? null,
                    ]);

                    if ($statusField === 'order_status' && $oldStatus === 'pending' && $newStatus === 'processing') {
                        inventory_reserve_for_order($pdo, $orderId);
                    } elseif ($statusField === 'shipping_status' && $newStatus === 'shipped' && in_array($oldStatus, ['pending', 'packed'], true)) {
                        inventory_ship_for_order($pdo, $orderId);
                    } elseif ($statusField === 'order_status' && $newStatus === 'cancelled') {
                        inventory_release_for_order($pdo, $orderId);
                    }

                    $pdo->commit();

                    app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to update order status.';
                }
            }
        }
    }

    if ($error !== '') {
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    }
}

$itemsStmt = $pdo->prepare('
    SELECT oi.id, oi.quantity, oi.selling_price, oi.cost_snapshot, p.sku, p.name AS product_name
    FROM mewmii_order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
');
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$eventsStmt = $pdo->prepare('
    SELECT e.id, e.event_type, e.description, e.created_at, u.name AS user_name
    FROM mewmii_order_events e
    LEFT JOIN users u ON u.id = e.created_by
    WHERE e.order_id = ?
    ORDER BY e.created_at DESC, e.id DESC
');
$eventsStmt->execute([$orderId]);
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

$inventoryTxStmt = $pdo->prepare("
    SELECT it.id, it.transaction_type, it.quantity, it.created_at, p.sku, p.name AS product_name
    FROM inventory_transactions it
    INNER JOIN products p ON p.id = it.product_id
    WHERE it.reference_type = 'order' AND it.reference_id = ?
    ORDER BY it.created_at DESC, it.id DESC
");
$inventoryTxStmt->execute([$orderId]);
$inventoryTx = $inventoryTxStmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = app_has_permission('orders.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Order <?php echo app_escape($order['order_number']); ?></h2>
        <p class="text-muted mb-0"><?php echo app_escape($order['customer_name'] ?? 'Unknown customer'); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/orders/index.php">Back to Orders</a>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Order created.</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Order status updated.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Order Summary</h5>
            <table class="table table-borderless mb-0">
                <tr><th>Payment Status</th><td><?php echo app_escape($order['payment_status']); ?></td></tr>
                <tr><th>Order Status</th><td><?php echo app_escape($order['order_status']); ?></td></tr>
                <tr><th>Shipping Status</th><td><?php echo app_escape($order['shipping_status']); ?></td></tr>
                <tr><th>Subtotal</th><td><?php echo app_escape((string) $order['subtotal']); ?></td></tr>
                <tr><th>Discount</th><td><?php echo app_escape((string) $order['discount']); ?></td></tr>
                <tr><th>Shipping Fee</th><td><?php echo app_escape((string) $order['shipping_fee']); ?></td></tr>
                <tr><th>Total</th><td><?php echo app_escape((string) $order['total_amount']); ?></td></tr>
                <tr><th>Order Date</th><td><?php echo app_escape($order['order_date'] ?? '-'); ?></td></tr>
            </table>
        </div>

        <div class="card p-4">
            <h5 class="mb-3">Items</h5>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo app_escape($item['sku']); ?></td>
                            <td><?php echo app_escape($item['product_name']); ?></td>
                            <td><?php echo app_escape((string) $item['quantity']); ?></td>
                            <td><?php echo app_escape((string) $item['selling_price']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($items === []): ?>
                        <tr><td colspan="4" class="text-muted">No items recorded for this order.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($canManage): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Change Status</h5>
                <?php foreach ($statusFields as $field => $config): ?>
                    <div class="mb-4 pb-3 border-bottom">
                        <div class="fw-semibold mb-1"><?php echo app_escape($config['label']); ?></div>
                        <div class="text-muted small mb-2">Current: <?php echo app_escape((string) $order[$field]); ?></div>

                        <?php if (in_array($order[$field], $config['terminal'], true)): ?>
                            <span class="badge bg-secondary">Final</span>
                        <?php else: ?>
                            <form method="post" class="d-flex gap-2 flex-wrap align-items-start">
                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                <input type="hidden" name="status_field" value="<?php echo app_escape($field); ?>">

                                <select class="form-select form-select-sm w-auto" name="new_status" required>
                                    <?php foreach ($config['values'] as $value): ?>
                                        <?php if ($value === $order[$field]) { continue; } ?>
                                        <option value="<?php echo app_escape($value); ?>"><?php echo app_escape($value); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <input type="text" class="form-control form-control-sm" name="notes" placeholder="Notes (optional)" style="max-width: 220px;">

                                <button class="btn btn-primary btn-sm" type="submit">Update</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card p-4 mb-4">
            <h5 class="mb-3">Inventory Activity</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($inventoryTx as $tx): ?>
                    <li class="mb-3">
                        <div class="fw-semibold">
                            <?php echo app_escape($tx['transaction_type']); ?>
                            &middot; <?php echo app_escape($tx['sku']); ?> (<?php echo app_escape($tx['product_name']); ?>)
                        </div>
                        <div>Qty: <?php echo app_escape((string) $tx['quantity']); ?></div>
                        <div class="text-muted small"><?php echo app_escape($tx['created_at']); ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if ($inventoryTx === []): ?>
                    <li class="text-muted">No inventory activity for this order yet.</li>
                <?php endif; ?>
            </ul>
            <a class="small" href="/modules/inventory/index.php">View full inventory transaction history &rarr;</a>
        </div>

        <div class="card p-4">
            <h5 class="mb-3">Order Timeline</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($events as $event): ?>
                    <li class="mb-3">
                        <div class="fw-semibold"><?php echo app_escape($event['event_type']); ?></div>
                        <div><?php echo app_escape($event['description'] ?? ''); ?></div>
                        <div class="text-muted small">
                            <?php echo app_escape($event['created_at']); ?>
                            <?php if (!empty($event['user_name'])): ?>
                                &middot; <?php echo app_escape($event['user_name']); ?>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if ($events === []): ?>
                    <li class="text-muted">No events recorded yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
