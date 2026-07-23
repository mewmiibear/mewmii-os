<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/order_fulfillment.php';
require_once __DIR__ . '/../../includes/shipments.php';
app_require_permission('orders.view');

$appTitle = 'Order Detail';

/**
 * order_status is now always computed by order_recompute_status() (see
 * includes/order_fulfillment.php) - it is never set through this page's status dropdown
 * anymore. payment_status keeps its own dropdown further down (the Approve/Reject Payment
 * buttons above it cover the common path; the dropdown remains for the refunded/failed edge
 * cases those buttons don't handle). shipping_status (the legacy single-tracking-number
 * field) is no longer driven from this page at all - shipping now happens per item through
 * the Shipments module (see modules/shipments/create.php).
 */
$statusFields = [
    'payment_status' => [
        'label' => 'Payment Status',
        'values' => ['pending', 'paid', 'refunded', 'failed'],
        'terminal' => ['refunded'],
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
 * payment_status changes only - order_status is never written directly anymore (see
 * order_recompute_status()). Used by Approve Payment and the generic Change Payment Status
 * dropdown below.
 */
function apply_payment_status_change(PDO $pdo, int $orderId, string $oldStatus, string $newStatus, string $notes): void
{
    $pdo->prepare('UPDATE mewmii_orders SET payment_status = ? WHERE id = ?')->execute([$newStatus, $orderId]);

    $description = sprintf("Payment Status changed from '%s' to '%s'.", $oldStatus, $newStatus);
    if ($notes !== '') {
        $description .= ' Notes: ' . $notes;
    }

    $pdo->prepare('
        INSERT INTO mewmii_order_events (order_id, event_type, description, created_by)
        VALUES (?, ?, ?, ?)
    ')->execute([$orderId, 'payment_status_change', $description, $_SESSION['user_id'] ?? null]);
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

    if ($error === '' && !empty($_POST['add_note'])) {
        // A plain annotation on the order timeline - never changes order_status/payment
        // status or touches the ledger, so this is the one action allowed even on a
        // historical (imported) order.
        $noteText = trim((string) ($_POST['note_text'] ?? ''));
        if ($noteText === '') {
            $error = 'Enter a note.';
        } else {
            $pdo->prepare('
                INSERT INTO mewmii_order_events (order_id, event_type, description, created_by)
                VALUES (?, ?, ?, ?)
            ')->execute([$orderId, 'admin_note', $noteText, $_SESSION['user_id'] ?? null]);

            app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
        }
    } else {
        // Historical (imported) orders are read-only business records for everything below -
        // every action here can trigger inventory reservation/release or order_recompute_status
        // writing order_status, which an imported order must never touch.
        if ($error === '' && !empty($order['is_historical'])) {
            $error = 'This is a historical (imported) order - its status cannot be changed.';
        }

        if ($error === '' && !empty($_POST['approve_payment'])) {
            if ($order['payment_status'] !== 'pending') {
                $error = 'This order is not awaiting payment approval.';
            } else {
                $pdo->beginTransaction();

                try {
                    apply_payment_status_change($pdo, $orderId, (string) $order['payment_status'], 'paid', 'Approved via receipt review.');
                    // Reserves whatever ready-stock items can be reserved right now; any that
                    // can't (out of stock) simply stay unreserved rather than blocking payment
                    // approval - order_recompute_status() will reflect that as Waiting Stock.
                    inventory_reserve_for_order_partial($pdo, $orderId);
                    order_recompute_status($pdo, $orderId);

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

                    order_recompute_status($pdo, $orderId);

                    $pdo->commit();

                    app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to reject payment.';
                }
            }
        } elseif ($error === '' && !empty($_POST['cancel_order'])) {
            if (in_array((string) $order['order_status'], ['completed', 'cancelled'], true)) {
                $error = 'This order can no longer be cancelled.';
            } else {
                $notes = trim((string) ($_POST['notes'] ?? ''));

                $pdo->beginTransaction();

                try {
                    $oldStatus = (string) $order['order_status'];
                    $pdo->prepare("UPDATE mewmii_orders SET order_status = 'cancelled' WHERE id = ?")->execute([$orderId]);

                    $description = sprintf("Order Status changed from '%s' to 'cancelled'.", $oldStatus);
                    if ($notes !== '') {
                        $description .= ' Notes: ' . $notes;
                    }
                    $pdo->prepare('
                        INSERT INTO mewmii_order_events (order_id, event_type, description, created_by)
                        VALUES (?, ?, ?, ?)
                    ')->execute([$orderId, 'order_status_change', $description, $_SESSION['user_id'] ?? null]);

                    inventory_release_for_order($pdo, $orderId);

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
                        apply_payment_status_change($pdo, $orderId, $oldStatus, $newStatus, $notes);
                        order_recompute_status($pdo, $orderId);

                        $pdo->commit();

                        app_redirect('/modules/orders/view.php?id=' . $orderId . '&updated=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to update payment status.';
                    }
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
    SELECT oi.id, oi.product_id, oi.variation_id, oi.quantity, oi.selling_price, oi.discount, oi.subtotal, oi.cost_snapshot, oi.variation_label,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name, p.product_type
    FROM mewmii_order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    LEFT JOIN product_variations pv ON pv.id = oi.variation_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
');
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($items as &$item) {
    $item['fulfillment'] = order_item_get_fulfillment_status($pdo, (int) $item['id']);
}
unset($item);

// "Resolve Stock Issue" link target for a waiting_stock item - same product-type split as
// the receiving prompt on modules/supplier-orders/view.php: ready-stock resolves via the
// Reservation Center, preorder/early-bird via the Allocation Center. Display-only; doesn't
// change what order_item_get_fulfillment_status() computes.
$canViewInventory = app_has_permission('inventory.view');

$shipmentsStmt = $pdo->prepare('
    SELECT DISTINCT s.id, s.shipment_number, s.carrier, s.tracking_number, s.shipping_status, s.shipped_at, s.created_at
    FROM shipments s
    INNER JOIN shipment_items si ON si.shipment_id = s.id
    WHERE si.order_id = ?
    ORDER BY s.created_at DESC
');
$shipmentsStmt->execute([$orderId]);
$orderShipments = $shipmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Customer Status Message (v1) - pure display, built entirely from $order/$items/
// $orderShipments already fetched above. See order_build_status_messages()
// (includes/orders.php) - no new queries, no writes.
$statusMessages = order_build_status_messages($order, $items, $orderShipments);

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
        <h2 class="mb-1">
            Order <?php echo app_escape($order['order_number']); ?>
            <?php if (!empty($order['is_historical'])): ?>
                <span class="badge bg-secondary">Historical</span>
            <?php endif; ?>
        </h2>
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

        <?php if (!empty($order['customer_note'])): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Customer Note</h5>
                <p class="mb-0"><?php echo nl2br(app_escape($order['customer_note'])); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($canManage && !empty($order['internal_note'])): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Internal Note <span class="badge bg-secondary">Staff Only</span></h5>
                <p class="mb-0"><?php echo nl2br(app_escape($order['internal_note'])); ?></p>
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
                        <th>Fulfillment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php $fulfillment = $item['fulfillment']; ?>
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
                            <td>
                                <?php if ($fulfillment['state'] === 'waiting_stock' && $canViewInventory): ?>
                                    <?php
                                    $resolveUrl = $item['product_type'] === 'ready_stock'
                                        ? '/modules/inventory/reserve.php?product_id=' . (int) $item['product_id'] . ($item['variation_id'] !== null ? '&variation_id=' . (int) $item['variation_id'] : '')
                                        : '/modules/inventory/allocate.php?product_id=' . (int) $item['product_id'] . ($item['variation_id'] !== null ? '&variation_id=' . (int) $item['variation_id'] : '');
                                    ?>
                                    <a href="<?php echo app_escape($resolveUrl); ?>">Resolve Stock Issue &rarr;</a>
                                <?php else: ?>
                                    <div><?php echo app_escape(order_item_fulfillment_label($fulfillment['state'])); ?></div>
                                <?php endif; ?>
                                <?php foreach ($fulfillment['shipments'] as $itemShipment): ?>
                                    <div class="text-muted small">
                                        <a href="/modules/shipments/view.php?id=<?php echo (int) $itemShipment['id']; ?>"><?php echo app_escape($itemShipment['shipment_number']); ?></a>
                                        <?php if (!empty($itemShipment['tracking_number'])): ?>
                                            &middot; <?php echo app_escape($itemShipment['tracking_number']); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($items === []): ?>
                        <tr><td colspan="7" class="text-muted">No items recorded for this order.</td></tr>
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
            <?php if ($canManage && empty($order['is_historical']) && $order['payment_status'] === 'pending'): ?>
                <div class="d-flex gap-2">
                    <form method="post" onsubmit="return confirm('Approve this payment? Ready-stock items will be reserved where possible and the order status will update automatically.');">
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

        <?php if ($canManage && empty($order['is_historical'])): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Order Workflow</h5>
                <div class="mb-3"><?php echo order_status_badge($order['order_status']); ?></div>
                <p class="text-muted small mb-3">Order Status is calculated automatically from payment status, item fulfillment, and shipments - see the Items table and Shipments below. Use Create Shipment to move items forward.</p>

                <?php if (!in_array($order['order_status'], ['completed', 'cancelled'], true)): ?>
                    <form method="post" onsubmit="return confirm('Cancel this order? Any reserved stock will be released back to available.');">
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
            <h5 class="mb-3">Customer Status Message</h5>
            <p class="text-muted small mb-2">Current order status: <?php echo app_escape(order_status_label($order['order_status'])); ?>. Pick the message that matches, review it, then copy and send it yourself (Instagram/WhatsApp/etc.) - nothing here is sent automatically.</p>

            <select class="form-select form-select-sm mb-2" id="statusMessageType">
                <option value="payment_confirmed">Payment Confirmed</option>
                <option value="waiting_supplier">Waiting Supplier</option>
                <option value="arrived_warehouse">Arrived Warehouse</option>
                <option value="ready_for_shipment">Ready for Shipment</option>
                <option value="shipped">Shipped</option>
            </select>

            <textarea class="form-control form-control-sm mb-2" id="statusMessageText" rows="5" readonly></textarea>

            <button type="button" class="btn btn-outline-secondary btn-sm" id="statusMessageCopyBtn">Copy Message</button>
            <span class="text-success small ms-2 d-none" id="statusMessageCopied">Copied!</span>
        </div>

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

        <div class="card p-4 mb-4">
            <h5 class="mb-3">Shipments</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($orderShipments as $shipment): ?>
                    <li class="mb-3">
                        <div class="fw-semibold">
                            <a href="/modules/shipments/view.php?id=<?php echo (int) $shipment['id']; ?>"><?php echo app_escape($shipment['shipment_number']); ?></a>
                            <?php echo shipment_status_badge($shipment['shipping_status']); ?>
                        </div>
                        <?php if (!empty($shipment['tracking_number'])): ?>
                            <div>Tracking: <?php echo app_escape($shipment['tracking_number']); ?><?php if (!empty($shipment['carrier'])): ?> (<?php echo app_escape($shipment['carrier']); ?>)<?php endif; ?></div>
                        <?php endif; ?>
                        <div class="text-muted small">
                            <?php echo $shipment['shipped_at'] !== null ? 'Shipped ' . app_escape(date('j F Y', strtotime($shipment['shipped_at']))) : 'Created ' . app_escape($shipment['created_at']); ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if ($orderShipments === []): ?>
                    <li class="text-muted">No shipments created for this order yet.</li>
                <?php endif; ?>
            </ul>
            <?php if ($canManage && empty($order['is_historical'])): ?>
                <a class="small" href="/modules/shipments/create.php?order_id=<?php echo (int) $orderId; ?>">Create Shipment &rarr;</a>
            <?php endif; ?>
        </div>

        <div class="card p-4 mb-4">
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

        <?php if ($canManage): ?>
            <div class="card p-4">
                <h5 class="mb-3">Add Note</h5>
                <p class="text-muted small mb-2">A plain annotation on the order timeline - never changes the automatically computed order status.</p>
                <form method="post" class="d-flex gap-2 align-items-start">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="add_note" value="1">
                    <input type="text" class="form-control form-control-sm" name="note_text" placeholder="e.g. Customer requested delivery instructions" required>
                    <button class="btn btn-outline-secondary btn-sm" type="submit">Add</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var messages = <?php echo json_encode($statusMessages, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var select = document.getElementById('statusMessageType');
    var textarea = document.getElementById('statusMessageText');
    var copyBtn = document.getElementById('statusMessageCopyBtn');
    var copiedLabel = document.getElementById('statusMessageCopied');

    function renderMessage() {
        textarea.value = messages[select.value] || '';
        copiedLabel.classList.add('d-none');
    }

    function showCopied() {
        copiedLabel.classList.remove('d-none');
        setTimeout(function () { copiedLabel.classList.add('d-none'); }, 2000);
    }

    select.addEventListener('change', renderMessage);

    copyBtn.addEventListener('click', function () {
        textarea.focus();
        textarea.select();

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textarea.value).then(showCopied, function () {
                document.execCommand('copy');
                showCopied();
            });
        } else {
            document.execCommand('copy');
            showCopied();
        }
    });

    renderMessage();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
