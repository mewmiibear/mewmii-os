<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/customer_storage.php';
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

        // Historical (imported) supplier orders are read-only business records for every
        // action that could touch incoming/received stock - payments are still allowed,
        // since those are pure bookkeeping and never move inventory. This must be the
        // first branch of the chain below (not a separate preceding "if"), since none of
        // the other branches re-check $error before running.
        if (!empty($order['is_historical']) && in_array($action, ['receive', 'mark_arrived', 'advance_status', 'cancel'], true)) {
            $error = 'This is a historical (imported) supplier order - it cannot receive stock or change status.';
        } elseif ($action === 'receive') {
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

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1&received=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to receive item.';
                }
            }
        } elseif ($action === 'mark_arrived') {
            if (!in_array($order['status'], ['ordered', 'partially_received'], true)) {
                $error = 'Only an Ordered or Partially Received supplier order can be marked arrived.';
            } else {
                $pdo->beginTransaction();

                try {
                    supplier_order_receive_all_remaining($pdo, $orderId);
                    $pdo->commit();

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1&received=1');
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
        } elseif ($action === 'add_payment') {
            $amount = (float) ($_POST['amount'] ?? 0);
            $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
            $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
            $paymentNotes = trim((string) ($_POST['notes'] ?? ''));

            if ($amount <= 0) {
                $error = 'Enter a payment amount greater than zero.';
            } else {
                $pdo->beginTransaction();

                try {
                    supplier_order_add_payment(
                        $pdo,
                        $orderId,
                        $amount,
                        $paymentDate !== '' ? $paymentDate : null,
                        $paymentMethod !== '' ? $paymentMethod : null,
                        $paymentNotes !== '' ? $paymentNotes : null
                    );
                    activity_log($pdo, 'supplier_orders', 'payment_added', $orderId, 'Added payment of RM' . number_format($amount, 2) . ' to ' . $order['purchase_number']);
                    $pdo->commit();

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to record payment.';
                }
            }
        } elseif ($action === 'delete_payment') {
            $paymentId = (int) ($_POST['payment_id'] ?? 0);

            if ($paymentId < 1) {
                $error = 'Invalid payment record.';
            } else {
                $pdo->beginTransaction();

                try {
                    supplier_order_delete_payment($pdo, $paymentId);
                    activity_log($pdo, 'supplier_orders', 'payment_deleted', $orderId, 'Deleted a payment from ' . $order['purchase_number']);
                    $pdo->commit();

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to delete payment.';
                }
            }
        } elseif ($action === 'cancel') {
            $pdo->beginTransaction();

            try {
                supplier_order_cancel($pdo, $orderId);
                $pdo->commit();

                app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to cancel supplier order.';
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
    SELECT soi.id, soi.product_id, soi.total_quantity, soi.supplier_price, soi.subtotal, soi.variation_id,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name, p.product_type
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

// Receiving glue prompt (display-only): right after a receive/mark-arrived action, check the
// SAME existing demand functions the Reservation/Allocation Centers already use
// (inventory_unit_unreserved_demand()/inventory_unit_outstanding_demand(), both unchanged) for
// every distinct product/variation on this order, and if either shows real waiting demand,
// surface a direct link to the page that resolves it - instead of leaving that connection to
// be found manually. Never runs outside the ?received=1 redirect from this page's own
// receive/mark_arrived actions, and never touches the ledger itself.
$receivingPrompts = ['reservation' => [], 'allocation' => []];
if (isset($_GET['received'])) {
    $seenUnits = [];
    foreach ($items as $item) {
        $unitKey = $item['product_id'] . ':' . ($item['variation_id'] ?? 0);
        if (isset($seenUnits[$unitKey])) {
            continue;
        }
        $seenUnits[$unitKey] = true;

        $productId = (int) $item['product_id'];
        $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
        $label = $item['sku'] . ' - ' . $item['product_name'];

        if ($item['product_type'] === 'ready_stock') {
            if (inventory_unit_unreserved_demand($pdo, $productId, $variationId) > 0) {
                $receivingPrompts['reservation'][] = [
                    'label' => $label,
                    'url' => '/modules/inventory/reserve.php?product_id=' . $productId . ($variationId !== null ? '&variation_id=' . $variationId : ''),
                ];
            }
        } elseif (in_array($item['product_type'], ['preorder', 'early_bird'], true)) {
            if (inventory_unit_outstanding_demand($pdo, $productId, $variationId) > 0) {
                $receivingPrompts['allocation'][] = [
                    'label' => $label,
                    'url' => '/modules/inventory/allocate.php?product_id=' . $productId . ($variationId !== null ? '&variation_id=' . $variationId : ''),
                ];
            }
        }
    }
}
$canViewInventory = app_has_permission('inventory.view');
// Same reasoning: the supplier name links to modules/suppliers/view.php (suppliers.view),
// and each line item's product name links to modules/products/view.php (products.view) -
// both destination permissions, not this page's own supplier-orders.view/manage gate.
$canViewSuppliers = app_has_permission('suppliers.view');
$canViewProducts = app_has_permission('products.view');

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

$editHistoryStmt = $pdo->prepare('
    SELECT e.description, e.created_at, u.name AS user_name
    FROM supplier_order_events e
    LEFT JOIN users u ON u.id = e.created_by
    WHERE e.supplier_order_id = ?
    ORDER BY e.created_at DESC, e.id DESC
');
$editHistoryStmt->execute([$orderId]);
$editHistory = $editHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPurchaseAmount = $orderTotal + (float) $order['shipping_fee'];
$paidAmount = supplier_order_paid_amount($pdo, $orderId);
$remainingAmount = $totalPurchaseAmount - $paidAmount;
$payments = supplier_order_list_payments($pdo, $orderId);

$canManage = app_has_permission('supplier-orders.manage');
$nextStatus = supplier_order_status_next((string) $order['status']);
// Same eligibility as delete - once anything has actually been received, a supplier order
// can no longer be cancelled (see supplier_order_cancel()'s guard).
$canCancel = $canManage && empty($order['is_historical']) && !in_array($order['status'], ['cancelled', 'completed'], true) && !supplier_order_has_receiving_history($pdo, $orderId);

// Overdue flag - same definition as the dashboard's Overdue card and supplier-orders/index.php's
// ?filter=overdue, never re-derived.
$isOverdue = $order['expected_delivery_date'] !== null
    && strtotime($order['expected_delivery_date']) < strtotime('today')
    && !in_array($order['status'], ['received', 'completed', 'cancelled'], true);
$daysOverdue = $isOverdue
    ? (int) floor((strtotime('today') - strtotime($order['expected_delivery_date'])) / 86400)
    : 0;

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">
            Supplier Order <?php echo app_escape($order['purchase_number']); ?>
            <?php if (!empty($order['is_historical'])): ?>
                <span class="badge bg-secondary">Historical</span>
            <?php endif; ?>
        </h2>
        <p class="text-muted mb-0">
            <?php if ($canViewSuppliers): ?>
                <a href="/modules/suppliers/view.php?id=<?php echo (int) $order['supplier_id']; ?>"><?php echo app_escape($order['supplier_name']); ?></a>
            <?php else: ?>
                <?php echo app_escape($order['supplier_name']); ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canManage): ?>
            <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/edit.php?id=<?php echo (int) $orderId; ?>">Edit</a>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Cancel this supplier order? Any outstanding incoming stock will be reversed.');">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-outline-warning btn-sm">Cancel Order</button>
            </form>
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
<?php if (isset($_GET['delete_error'])): ?>
    <div class="alert alert-danger"><?php echo app_escape($_GET['delete_error'] === '1' ? 'Failed to delete supplier order.' : $_GET['delete_error']); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<?php if ($canViewInventory && ($receivingPrompts['reservation'] !== [] || $receivingPrompts['allocation'] !== [])): ?>
    <div class="alert alert-info">
        <div class="fw-semibold mb-2">Stock just received - some of it can be matched to waiting orders now:</div>
        <?php foreach ($receivingPrompts['reservation'] as $prompt): ?>
            <div class="mb-1">
                <?php echo app_escape($prompt['label']); ?> has orders waiting on it -
                <a href="<?php echo app_escape($prompt['url']); ?>">reserve it in the Reservation Center &rarr;</a>
            </div>
        <?php endforeach; ?>
        <?php foreach ($receivingPrompts['allocation'] as $prompt): ?>
            <div class="mb-1">
                <?php echo app_escape($prompt['label']); ?> has customers waiting on it -
                <a href="<?php echo app_escape($prompt['url']); ?>">allocate it in the Allocation Center &rarr;</a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Order Summary</h5>
            <table class="table table-borderless mb-0">
                <tr><th>Status</th><td><?php echo supplier_order_status_badge($order['status']); ?></td></tr>
                <tr><th>Payment Status</th><td><?php echo supplier_order_payment_status_badge((string) $order['payment_status']); ?></td></tr>
                <tr><th>Supplier</th><td>
                    <?php if ($canViewSuppliers): ?>
                        <a href="/modules/suppliers/view.php?id=<?php echo (int) $order['supplier_id']; ?>"><?php echo app_escape($order['supplier_name']); ?></a>
                    <?php else: ?>
                        <?php echo app_escape($order['supplier_name']); ?>
                    <?php endif; ?>
                </td></tr>
                <tr><th>Created Date</th><td><?php echo app_escape($order['order_date'] ?? '-'); ?></td></tr>
                <tr><th>Expected Delivery</th><td>
                    <?php echo app_escape($order['expected_delivery_date'] ?? '-'); ?>
                    <?php if ($isOverdue): ?>
                        <span class="badge bg-danger">Overdue by <?php echo (int) $daysOverdue; ?> day<?php echo $daysOverdue === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                </td></tr>
                <tr><th>Product Subtotal</th><td>RM <?php echo app_escape(number_format($orderTotal, 2)); ?></td></tr>
                <tr><th>Shipping Fee</th><td>RM <?php echo app_escape(number_format((float) $order['shipping_fee'], 2)); ?></td></tr>
                <tr><th>Total Purchase Amount</th><td>RM <?php echo app_escape(number_format($totalPurchaseAmount, 2)); ?></td></tr>
                <tr><th>Paid Amount</th><td>RM <?php echo app_escape(number_format($paidAmount, 2)); ?></td></tr>
                <tr><th>Remaining Amount</th><td>RM <?php echo app_escape(number_format($remainingAmount, 2)); ?></td></tr>
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
                                <?php if ($canViewProducts): ?>
                                    <a href="/modules/products/view.php?id=<?php echo (int) $item['product_id']; ?>"><?php echo app_escape($item['product_name']); ?></a>
                                <?php else: ?>
                                    <?php echo app_escape($item['product_name']); ?>
                                <?php endif; ?>
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
                                    <?php if (!empty($order['is_historical'])): ?>
                                        <span class="text-muted small">&mdash;</span>
                                    <?php elseif ($item['remaining_quantity'] > 0): ?>
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
        <?php if ($canManage && empty($order['is_historical'])): ?>
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

        <div class="card p-4 mb-4">
            <h5 class="mb-3">Payments</h5>
            <ul class="list-unstyled mb-3">
                <?php foreach ($payments as $payment): ?>
                    <li class="mb-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold">RM <?php echo app_escape(number_format((float) $payment['amount'], 2)); ?></div>
                                <div class="text-muted small">
                                    <?php echo $payment['payment_date'] !== null ? app_escape($payment['payment_date']) : app_escape(date('Y-m-d', strtotime($payment['created_at']))); ?>
                                    <?php if (!empty($payment['payment_method'])): ?>
                                        &middot; <?php echo app_escape($payment['payment_method']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['user_name'])): ?>
                                        &middot; <?php echo app_escape($payment['user_name']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($payment['notes'])): ?>
                                    <div class="small"><?php echo app_escape($payment['notes']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($canManage): ?>
                                <form method="post" onsubmit="return confirm('Delete this payment record?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete_payment">
                                    <input type="hidden" name="payment_id" value="<?php echo (int) $payment['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if ($payments === []): ?>
                    <li class="text-muted">No payments recorded yet.</li>
                <?php endif; ?>
            </ul>

            <?php if ($canManage): ?>
                <form method="post" class="row g-2 align-items-end border-top pt-3">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="add_payment">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Amount (RM)</label>
                        <input type="number" step="0.01" min="0.01" class="form-control form-control-sm" name="amount" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Payment Date</label>
                        <input type="date" class="form-control form-control-sm" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Method</label>
                        <input type="text" class="form-control form-control-sm" name="payment_method" placeholder="e.g. Bank Transfer">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Notes</label>
                        <input type="text" class="form-control form-control-sm" name="notes">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm">Add Payment</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="card p-4 mb-4">
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

        <div class="card p-4">
            <h5 class="mb-3">Edit History</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($editHistory as $entry): ?>
                    <li class="mb-3">
                        <div><?php echo app_escape($entry['description']); ?></div>
                        <div class="text-muted small">
                            <?php echo app_escape($entry['created_at']); ?>
                            <?php if (!empty($entry['user_name'])): ?>
                                &middot; <?php echo app_escape($entry['user_name']); ?>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if ($editHistory === []): ?>
                    <li class="text-muted">No edits recorded yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
