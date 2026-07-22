<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('supplier-orders.view');

$appTitle = 'Supplier Order Detail';
$error = '';

$orderId = (int) ($_GET['id'] ?? 0);

if ($orderId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$orderStmt = $pdo->prepare('
    SELECT so.*, s.name AS supplier_name
    FROM supplier_orders so
    INNER JOIN suppliers s ON s.id = so.supplier_id
    WHERE so.id = ?
    LIMIT 1
');
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !app_has_permission('supplier-orders.manage')) {
        http_response_code(403);
        $error = 'You do not have permission to manage supplier orders.';
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'receive') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $quantity = (int) ($_POST['quantity'] ?? 0);

            if ($itemId < 1) {
                $error = 'Invalid order item.';
            } elseif ($quantity < 1) {
                $error = 'Enter a quantity of at least 1.';
            } else {
                $pdo->beginTransaction();

                try {
                    supplier_order_receive_item($pdo, $itemId, $quantity);
                    $pdo->commit();

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to receive item.';
                }
            }
        } elseif ($action === 'mark_arrived') {
            if ($order['status'] !== 'ordered') {
                $error = 'Only an Ordered supplier order can be marked arrived.';
            } else {
                $pdo->beginTransaction();

                try {
                    supplier_order_receive_all_remaining($pdo, $orderId);
                    $pdo->commit();

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to mark order as arrived.';
                }
            }
        } elseif ($action === 'advance_status') {
            $targetStatus = (string) ($_POST['target_status'] ?? '');
            $expectedNext = supplier_order_status_next((string) $order['status']);

            if ($expectedNext === null || $targetStatus !== $expectedNext) {
                $error = 'Invalid status transition.';
            } elseif ($targetStatus === 'received') {
                $error = 'Use "Mark Arrived" to receive this order.';
            } else {
                $pdo->beginTransaction();

                try {
                    $pdo->prepare('UPDATE supplier_orders SET status = ? WHERE id = ?')->execute([$targetStatus, $orderId]);
                    $pdo->commit();

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to update status.';
                }
            }
        } else {
            $error = 'Unknown action.';
        }
    }

    if ($error !== '') {
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    }
}

$itemsStmt = $pdo->prepare('
    SELECT soi.id, soi.total_quantity, soi.supplier_price, soi.subtotal, soi.variation_id,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name
    FROM supplier_order_items soi
    INNER JOIN products p ON p.id = soi.product_id
    LEFT JOIN product_variations pv ON pv.id = soi.variation_id
    WHERE soi.supplier_order_id = ?
    ORDER BY soi.id ASC
');
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$orderTotal = 0.0;
foreach ($items as &$item) {
    $item['received_quantity'] = supplier_order_item_received_quantity($pdo, (int) $item['id']);
    $item['remaining_quantity'] = (int) $item['total_quantity'] - $item['received_quantity'];
    $item['variation_label'] = $item['variation_id'] !== null ? variation_build_label($pdo, (int) $item['variation_id']) : '';
    $orderTotal += (float) $item['subtotal'];
}
unset($item);

$historyStmt = $pdo->prepare("
    SELECT it.quantity, it.created_at, p.sku, p.name AS product_name
    FROM inventory_transactions it
    INNER JOIN supplier_order_items soi ON soi.id = it.reference_id AND it.reference_type = 'supplier_order_item'
    INNER JOIN products p ON p.id = it.product_id
    WHERE soi.supplier_order_id = ? AND it.transaction_type = 'supplier_receive'
    ORDER BY it.created_at DESC, it.id DESC
");
$historyStmt->execute([$orderId]);
$receivingHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = app_has_permission('supplier-orders.manage');
$nextStatus = supplier_order_status_next((string) $order['status']);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Supplier Order <?php echo app_escape($order['purchase_number']); ?></h2>
        <p class="text-muted mb-0"><?php echo app_escape($order['supplier_name']); ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canManage && $order['status'] === 'draft'): ?>
            <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/edit.php?id=<?php echo (int) $orderId; ?>">Edit</a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <form method="post" action="/modules/supplier-orders/delete.php" class="d-inline" onsubmit="return confirm('Delete this supplier order? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="order_id" value="<?php echo (int) $orderId; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
            </form>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/index.php">Back to Supplier Orders</a>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Supplier order created.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Supplier order updated.</div>
<?php endif; ?>
<?php if (isset($_GET['edit_blocked'])): ?>
    <div class="alert alert-warning">Only a Draft supplier order can be edited.</div>
<?php endif; ?>
<?php if (isset($_GET['delete_error'])): ?>
    <div class="alert alert-danger"><?php echo app_escape($_GET['delete_error'] === '1' ? 'Failed to delete supplier order.' : $_GET['delete_error']); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Order Summary</h5>
            <table class="table table-borderless mb-0">
                <tr><th>Status</th><td><?php echo supplier_order_status_badge($order['status']); ?></td></tr>
                <tr><th>Supplier</th><td><?php echo app_escape($order['supplier_name']); ?></td></tr>
                <tr><th>Created Date</th><td><?php echo app_escape($order['order_date'] ?? '-'); ?></td></tr>
                <tr><th>Estimated Cost</th><td>RM <?php echo app_escape(number_format((float) $order['estimated_cost'], 2)); ?></td></tr>
                <tr><th>Order Total</th><td>RM <?php echo app_escape(number_format($orderTotal, 2)); ?></td></tr>
                <tr><th>Received Date</th><td><?php echo app_escape($order['received_date'] ?? '-'); ?></td></tr>
                <?php if (!empty($order['notes'])): ?>
                    <tr><th>Notes</th><td><?php echo nl2br(app_escape($order['notes'])); ?></td></tr>
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
                        <th>Ordered</th>
                        <th>Received</th>
                        <th>Outstanding</th>
                        <th>Unit Cost</th>
                        <?php if ($canManage): ?><th></th><?php endif; ?>
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
                            <td><?php echo app_escape((string) $item['total_quantity']); ?></td>
                            <td><?php echo app_escape((string) $item['received_quantity']); ?></td>
                            <td><?php echo app_escape((string) $item['remaining_quantity']); ?></td>
                            <td>RM <?php echo app_escape(number_format((float) $item['supplier_price'], 2)); ?></td>
                            <?php if ($canManage): ?>
                                <td class="text-end">
                                    <?php if ($item['remaining_quantity'] > 0): ?>
                                        <form method="post" class="d-flex gap-1 justify-content-end" onsubmit="return confirm('Record a partial receipt for this line?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="receive">
                                            <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                            <input type="number" class="form-control form-control-sm" style="width: 80px;" name="quantity" min="1" max="<?php echo (int) $item['remaining_quantity']; ?>" placeholder="Qty" required>
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Partial Receive</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-success">Complete</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($items === []): ?>
                        <tr><td colspan="<?php echo $canManage ? 7 : 6; ?>" class="text-muted">No items on this order.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($canManage): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Order Workflow</h5>
                <div class="mb-3"><?php echo supplier_order_status_badge($order['status']); ?></div>

                <?php if ($nextStatus === 'received'): ?>
                    <form method="post" onsubmit="return confirm('Mark this entire order as arrived? Every remaining ordered quantity will be received in full.');">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="mark_arrived">
                        <button type="submit" class="btn btn-primary btn-sm">Mark Arrived</button>
                    </form>
                    <div class="form-text">Only a partial shipment? Use Partial Receive on the specific line below instead.</div>
                <?php elseif ($nextStatus !== null): ?>
                    <form method="post" onsubmit="return confirm('<?php echo app_escape((string) supplier_order_status_next_action_label((string) $order['status'])); ?>?');">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="advance_status">
                        <input type="hidden" name="target_status" value="<?php echo app_escape($nextStatus); ?>">
                        <button type="submit" class="btn btn-primary btn-sm"><?php echo app_escape((string) supplier_order_status_next_action_label((string) $order['status'])); ?></button>
                    </form>
                <?php else: ?>
                    <span class="badge bg-secondary">Final</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card p-4">
            <h5 class="mb-3">Receiving History</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($receivingHistory as $entry): ?>
                    <li class="mb-3">
                        <div class="fw-semibold"><?php echo app_escape($entry['sku']); ?> &mdash; <?php echo app_escape($entry['product_name']); ?></div>
                        <div>Received: <?php echo app_escape((string) $entry['quantity']); ?></div>
                        <div class="text-muted small"><?php echo app_escape($entry['created_at']); ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if ($receivingHistory === []): ?>
                    <li class="text-muted">No items received yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
