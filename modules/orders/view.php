<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/orders.php';
app_require_permission('orders.view');

$appTitle = 'Order Detail';

/**
 * order_status's real values now include the two new workflow stages (ready_to_ship,
 * shipped) - see includes/orders.php's ORDER_STATUS_WORKFLOW. payment_status keeps its own
 * dropdown further down (the Approve/Reject Payment buttons above it cover the common
 * path; the dropdown remains for the refunded/failed edge cases those buttons don't
 * handle) - shipping_status no longer gets a standalone dropdown since it's now driven
 * entirely by the Mark Shipped action below, but its config stays here because
 * apply_order_status_change() still needs it for the shipping_status update inside that
 * handler.
 */
$statusFields = [
    'order_status' => [
        'label' => 'Order Status',
        'values' => ['pending', 'processing', 'ready_to_ship', 'shipped', 'completed', 'cancelled'],
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

/**
 * Shared by the generic per-field status dropdown AND the Approve/Reject Payment
 * buttons, so both paths trigger the exact same inventory reserve/ship/release
 * conditions - no duplicated business logic between the two entry points.
 */
function apply_order_status_change(PDO $pdo, int $orderId, array $order, string $statusField, string $newStatus, string $notes, array $statusFieldsConfig): void
{
    $oldStatus = (string) $order[$statusField];

    $updateStmt = $pdo->prepare("UPDATE mewmii_orders SET {$statusField} = ? WHERE id = ?");
    $updateStmt->execute([$newStatus, $orderId]);

    $description = sprintf(
        "%s changed from '%s' to '%s'.",
        $statusFieldsConfig[$statusField]['label'],
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
    $eventStmt->execute([$orderId, $statusField . '_change', $description, $_SESSION['user_id'] ?? null]);

    if ($statusField === 'order_status' && $oldStatus === 'pending' && $newStatus === 'processing') {
        inventory_reserve_for_order($pdo, $orderId);
    } elseif ($statusField === 'shipping_status' && $newStatus === 'shipped' && in_array($oldStatus, ['pending', 'packed'], true)) {
        inventory_ship_for_order($pdo, $orderId);
    } elseif ($statusField === 'order_status' && $newStatus === 'cancelled') {
        inventory_release_for_order($pdo, $orderId);
    }
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

    if ($error === '' && !empty($_POST['approve_payment'])) {
        if ($order['payment_status'] !== 'pending') {
            $error = 'This order is not awaiting payment approval.';
        } else {
            $pdo->beginTransaction();

            try {
                apply_order_status_change($pdo, $orderId, $order, 'payment_status', 'paid', 'Approved via receipt review.', $statusFields);

                $refreshed = $order;
                $refreshed['payment_status'] = 'paid';
                if ($refreshed['order_status'] === 'pending') {
                    apply_order_status_change($pdo, $orderId, $refreshed, 'order_status', 'processing', 'Auto-advanced after payment approval.', $statusFields);
                }

                $pdo->commit();

                app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to approve payment.';
            }
        }
    } elseif ($error === '' && !empty($_POST['reject_payment'])) {
        if ($order['payment_status'] !== 'pending') {
            $error = 'This order is not awaiting payment approval.';
        } else {
            $pdo->beginTransaction();

            try {
                $pdo->prepare("UPDATE mewmii_orders SET payment_status = 'pending', receipt_url = NULL WHERE id = ?")
                    ->execute([$orderId]);

                $eventStmt = $pdo->prepare('
                    INSERT INTO mewmii_order_events (order_id, event_type, description, created_by)
                    VALUES (?, ?, ?, ?)
                ');
                $eventStmt->execute([$orderId, 'payment_status_change', 'Payment receipt rejected - awaiting a new receipt.', $_SESSION['user_id'] ?? null]);

                $pdo->commit();

                app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to reject payment.';
            }
        }
    } elseif ($error === '' && !empty($_POST['advance_status'])) {
        // Covers Start Processing / Mark Ready to Ship / Mark Completed - never Mark
        // Shipped, which always goes through the mark_shipped branch below so shipping
        // details are never skipped. $expectedNext is looked up server-side (not trusted
        // from the posted target_status) so a crafted request can't skip a stage.
        $targetStatus = (string) ($_POST['target_status'] ?? '');
        $expectedNext = order_status_next((string) $order['order_status']);

        if ($expectedNext === null || $targetStatus !== $expectedNext) {
            $error = 'Invalid status transition.';
        } elseif ($targetStatus === 'shipped') {
            $error = 'Use "Mark Shipped" to record shipping details.';
        } else {
            $pdo->beginTransaction();

            try {
                apply_order_status_change($pdo, $orderId, $order, 'order_status', $targetStatus, '', $statusFields);

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
    } elseif ($error === '' && !empty($_POST['mark_shipped'])) {
        if ((string) $order['order_status'] !== 'ready_to_ship') {
            $error = 'Order must be Ready to Ship before it can be marked as shipped.';
        } else {
            $carrier = trim((string) ($_POST['shipping_carrier'] ?? ''));
            $tracking = trim((string) ($_POST['tracking_number'] ?? ''));
            $shippedDateInput = trim((string) ($_POST['shipped_date'] ?? ''));

            if ($carrier === '' || $tracking === '') {
                $error = 'Shipping carrier and tracking number are required.';
            } else {
                $shippedAt = $shippedDateInput !== '' ? $shippedDateInput . ' ' . date('H:i:s') : date('Y-m-d H:i:s');

                $pdo->beginTransaction();

                try {
                    apply_order_status_change($pdo, $orderId, $order, 'order_status', 'shipped', '', $statusFields);

                    // Also flips shipping_status -> shipped so inventory_ship_for_order()'s
                    // existing trigger condition in apply_order_status_change() still fires
                    // exactly as it did under the old shipping_status dropdown - only the UI
                    // entry point changed, not the ledger side effect. Skipped if
                    // shipping_status is already past 'packed' (nothing to reconcile).
                    if (in_array((string) $order['shipping_status'], ['pending', 'packed'], true)) {
                        $refreshedForShipping = $order;
                        $refreshedForShipping['order_status'] = 'shipped';
                        apply_order_status_change($pdo, $orderId, $refreshedForShipping, 'shipping_status', 'shipped', '', $statusFields);
                    }

                    $pdo->prepare('UPDATE mewmii_orders SET tracking_number = ?, shipping_carrier = ?, shipped_at = ? WHERE id = ?')
                        ->execute([$tracking, $carrier, $shippedAt, $orderId]);

                    $pdo->commit();

                    order_sync_tracking_to_woocommerce($pdo, $orderId);

                    app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to mark order as shipped.';
                }
            }
        }
    } elseif ($error === '' && !empty($_POST['cancel_order'])) {
        if (in_array((string) $order['order_status'], ['completed', 'cancelled'], true)) {
            $error = 'This order can no longer be cancelled.';
        } else {
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $pdo->beginTransaction();

            try {
                apply_order_status_change($pdo, $orderId, $order, 'order_status', 'cancelled', $notes, $statusFields);

                $pdo->commit();

                app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to cancel order.';
            }
        }
    } elseif ($error === '') {
        $statusField = (string) ($_POST['status_field'] ?? '');
        $newStatus = trim((string) ($_POST['new_status'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        // Only payment_status still uses this generic path (see the Change Payment
        // Status card below) - order_status/shipping_status are handled by the branches
        // above.
        if ($statusField !== 'payment_status') {
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
                    apply_order_status_change($pdo, $orderId, $order, $statusField, $newStatus, $notes, $statusFields);

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
    SELECT oi.id, oi.quantity, oi.selling_price, oi.discount, oi.subtotal, oi.cost_snapshot, oi.variation_label,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name
    FROM mewmii_order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    LEFT JOIN product_variations pv ON pv.id = oi.variation_id
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
    <div class="d-flex gap-2">
        <?php
        // Only offered while still eligible per order_delete_if_unused() - avoids showing
        // a button that would always fail once the order has actually been processed.
        $orderDeletable = $canManage && $order['order_status'] === 'pending' && $order['payment_status'] === 'pending' && $order['shipped_at'] === null;
        ?>
        <?php if ($orderDeletable): ?>
            <form method="post" action="/modules/orders/delete.php" class="d-inline" onsubmit="return confirm('Permanently delete this order? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="order_id" value="<?php echo (int) $orderId; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
            </form>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a class="btn btn-outline-primary btn-sm" href="/modules/orders/edit.php?id=<?php echo (int) $orderId; ?>">Edit Order</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/orders/index.php">Back to Orders</a>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Order created.</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Order status updated.</div>
<?php endif; ?>

<?php if (isset($_GET['delete_error'])): ?>
    <div class="alert alert-danger"><?php echo app_escape($_GET['delete_error'] === '1' ? 'Failed to delete order.' : $_GET['delete_error']); ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Order Summary</h5>
            <table class="table table-borderless mb-0">
                <tr><th>Status</th><td><?php echo order_status_badge($order['order_status']); ?></td></tr>
                <tr><th>Payment Status</th><td><?php echo app_escape($order['payment_status']); ?></td></tr>
                <tr><th>Subtotal</th><td>RM <?php echo app_escape(number_format((float) $order['subtotal'], 2)); ?></td></tr>
                <tr><th>Discount</th><td>RM <?php echo app_escape(number_format((float) $order['discount'], 2)); ?></td></tr>
                <tr><th>Shipping Fee</th><td>RM <?php echo app_escape(number_format((float) $order['shipping_fee'], 2)); ?></td></tr>
                <tr><th>Total</th><td>RM <?php echo app_escape(number_format((float) $order['total_amount'], 2)); ?></td></tr>
                <tr><th>Order Date</th><td><?php echo app_escape($order['order_date'] ?? '-'); ?></td></tr>
            </table>
        </div>

        <?php if (!empty($order['notes'])): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Notes</h5>
                <p class="mb-0"><?php echo nl2br(app_escape($order['notes'])); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($order['tracking_number'])): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Shipping</h5>
                <table class="table table-borderless mb-0">
                    <tr><th>Carrier</th><td><?php echo $order['shipping_carrier'] !== null ? app_escape($order['shipping_carrier']) : '&mdash;'; ?></td></tr>
                    <tr><th>Tracking Number</th><td><?php echo app_escape($order['tracking_number']); ?></td></tr>
                    <tr><th>Shipped Date</th><td><?php echo $order['shipped_at'] !== null ? app_escape(date('j F Y', strtotime($order['shipped_at']))) : '&mdash;'; ?></td></tr>
                </table>
            </div>
        <?php endif; ?>

        <div class="card p-4">
            <h5 class="mb-3">Items</h5>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Discount</th>
                        <th>Subtotal</th>
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
                            <td>RM <?php echo app_escape(number_format((float) $item['selling_price'], 2)); ?></td>
                            <td>RM <?php echo app_escape(number_format((float) ($item['discount'] ?? 0), 2)); ?></td>
                            <td>RM <?php echo app_escape(number_format((float) ($item['subtotal'] ?? ($item['quantity'] * $item['selling_price'])), 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($items === []): ?>
                        <tr><td colspan="6" class="text-muted">No items recorded for this order.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Receipt</h5>
            <?php if (!empty($order['receipt_url'])): ?>
                <img src="<?php echo app_escape($order['receipt_url']); ?>" alt="Payment receipt" class="img-fluid rounded border mb-3" style="max-height: 320px;">
            <?php else: ?>
                <p class="text-muted mb-3">No receipt uploaded.</p>
            <?php endif; ?>
            <?php if ($canManage && $order['payment_status'] === 'pending'): ?>
                <div class="d-flex gap-2">
                    <form method="post" onsubmit="return confirm('Approve this payment? Order status will advance to Processing.');">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="approve_payment" value="1">
                        <button type="submit" class="btn btn-success btn-sm">Approve Payment</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Reject this payment receipt? The order will remain pending.');">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="reject_payment" value="1">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Reject Payment</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($canManage): ?>
            <?php $nextOrderStatus = order_status_next((string) $order['order_status']); ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Order Workflow</h5>
                <div class="mb-3"><?php echo order_status_badge($order['order_status']); ?></div>

                <?php if ($nextOrderStatus === 'shipped'): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#shipOrderModal">Mark Shipped</button>
                <?php elseif ($nextOrderStatus !== null): ?>
                    <form method="post" onsubmit="return confirm('<?php echo app_escape((string) order_status_next_action_label((string) $order['order_status'])); ?>?');">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="advance_status" value="1">
                        <input type="hidden" name="target_status" value="<?php echo app_escape($nextOrderStatus); ?>">
                        <button type="submit" class="btn btn-primary btn-sm"><?php echo app_escape((string) order_status_next_action_label((string) $order['order_status'])); ?></button>
                    </form>
                <?php else: ?>
                    <span class="badge bg-secondary">Final</span>
                <?php endif; ?>

                <?php if (!in_array($order['order_status'], ['completed', 'cancelled'], true)): ?>
                    <form method="post" class="mt-2" onsubmit="return confirm('Cancel this order? Any reserved stock will be released back to available.');">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="cancel_order" value="1">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Cancel Order</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card p-4 mb-4">
                <h5 class="mb-3">Payment Status</h5>
                <div class="text-muted small mb-2">Current: <?php echo app_escape((string) $order['payment_status']); ?></div>

                <?php if (in_array($order['payment_status'], $statusFields['payment_status']['terminal'], true)): ?>
                    <span class="badge bg-secondary">Final</span>
                <?php else: ?>
                    <form method="post" class="d-flex gap-2 flex-wrap align-items-start">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="status_field" value="payment_status">

                        <select class="form-select form-select-sm w-auto" name="new_status" required>
                            <?php foreach ($statusFields['payment_status']['values'] as $value): ?>
                                <?php if ($value === $order['payment_status']) { continue; } ?>
                                <option value="<?php echo app_escape($value); ?>"><?php echo app_escape($value); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" class="form-control form-control-sm" name="notes" placeholder="Notes (optional)" style="max-width: 220px;">

                        <button class="btn btn-primary btn-sm" type="submit">Update</button>
                    </form>
                <?php endif; ?>
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

<?php if ($canManage): ?>
<div class="modal fade" id="shipOrderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="mark_shipped" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Mark Shipped</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Shipping Carrier</label>
                        <input type="text" class="form-control" name="shipping_carrier" placeholder="e.g. Ninja Van, J&amp;T Express, Pos Laju" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tracking Number</label>
                        <input type="text" class="form-control" name="tracking_number" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Shipped Date</label>
                        <input type="date" class="form-control" name="shipped_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm Shipped</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
