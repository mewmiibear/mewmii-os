<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('supplier-orders.view');

$appTitle = 'Supplier Order Detail';
$error = '';

$statuses = ['draft', 'waiting_payment', 'ordered', 'shipping', 'received', 'completed'];
$terminalStatuses = ['completed'];

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
        } elseif ($action === 'status') {
            $newStatus = (string) ($_POST['new_status'] ?? '');
            $oldStatus = (string) $order['status'];

            if (!in_array($newStatus, $statuses, true)) {
                $error = 'Invalid status.';
            } elseif (in_array($oldStatus, $terminalStatuses, true)) {
                $error = 'This order is final and cannot be changed.';
            } elseif ($oldStatus === $newStatus) {
                $error = 'Order is already in that status.';
            } else {
                $pdo->beginTransaction();

                try {
                    $pdo->prepare('UPDATE supplier_orders SET status = ? WHERE id = ?')
                        ->execute([$newStatus, $orderId]);

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

foreach ($items as &$item) {
    $item['received_quantity'] = supplier_order_item_received_quantity($pdo, (int) $item['id']);
    $item['remaining_quantity'] = (int) $item['total_quantity'] - $item['received_quantity'];
    $item['variation_label'] = $item['variation_id'] !== null ? variation_build_label($pdo, (int) $item['variation_id']) : '';
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

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Supplier Order <?php echo app_escape($order['purchase_number']); ?></h2>
        <p class="text-muted mb-0"><?php echo app_escape($order['supplier_name']); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/index.php">Back to Supplier Orders</a>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Supplier order created.</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Supplier order updated.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Order Summary</h5>
            <table class="table table-borderless mb-0">
                <tr><th>Status</th><td><?php echo app_escape($order['status']); ?></td></tr>
                <tr><th>Estimated Cost</th><td><?php echo app_escape((string) $order['estimated_cost']); ?></td></tr>
                <tr><th>Actual Cost</th><td><?php echo app_escape((string) $order['actual_cost']); ?></td></tr>
                <tr><th>Order Date</th><td><?php echo app_escape($order['order_date'] ?? '-'); ?></td></tr>
                <tr><th>Payment Date</th><td><?php echo app_escape($order['payment_date'] ?? '-'); ?></td></tr>
                <tr><th>Received Date</th><td><?php echo app_escape($order['received_date'] ?? '-'); ?></td></tr>
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
                        <th>Remaining</th>
                        <th>Price</th>
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
                            <td><?php echo app_escape((string) $item['supplier_price']); ?></td>
                            <?php if ($canManage): ?>
                                <td class="text-end">
                                    <?php if ($item['remaining_quantity'] > 0): ?>
                                        <form method="post" class="d-flex gap-1 justify-content-end">
                                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="receive">
                                            <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                            <input type="number" class="form-control form-control-sm" style="width: 80px;" name="quantity" min="1" max="<?php echo (int) $item['remaining_quantity']; ?>" placeholder="Qty" required>
                                            <button class="btn btn-sm btn-outline-success" type="submit">Mark Arrived</button>
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
                <h5 class="mb-3">Change Status</h5>
                <?php if (in_array($order['status'], $terminalStatuses, true)): ?>
                    <span class="badge bg-secondary">Final</span>
                <?php else: ?>
                    <form method="post" class="d-flex gap-2 flex-wrap align-items-start">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="status">

                        <select class="form-select form-select-sm w-auto" name="new_status" required>
                            <?php foreach ($statuses as $statusOption): ?>
                                <?php if ($statusOption === $order['status']) { continue; } ?>
                                <option value="<?php echo app_escape($statusOption); ?>"><?php echo app_escape($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button class="btn btn-primary btn-sm" type="submit">Update</button>
                    </form>
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
