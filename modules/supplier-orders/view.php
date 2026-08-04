<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/currency_rates.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/customer_storage.php';
require_once __DIR__ . '/../../includes/orders.php';
// SO-B - bank_accounts_list() for the optional payment-account picker. Reused rather than
// re-querying bank_accounts here, so the account list stays defined in one place.
require_once __DIR__ . '/../../includes/finance.php';
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
        if (!empty($order['is_historical']) && in_array($action, ['receive', 'receive_batch', 'reverse_receipt', 'mark_arrived', 'advance_status', 'cancel'], true)) {
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
                    inventory_flush_woocommerce_resync($pdo);

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1&received=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    inventory_discard_pending_woocommerce_resync();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    inventory_discard_pending_woocommerce_resync();
                    $error = 'Failed to receive item.';
                }
            }
        } elseif ($action === 'reverse_receipt') {
            // Reverse Receiving. supplier_order_reverse_receipt() owns every rule - net-received
            // ceiling inside the row lock, free-stock guard, negative ledger entry, status
            // recompute. Nothing about receiving or reversal is decided here.
            $reverseItemId = (int) ($_POST['reverse_item_id'] ?? 0);
            $reverseQuantity = (int) ($_POST['reverse_quantity'] ?? 0);
            $reverseReason = trim((string) ($_POST['reverse_reason'] ?? ''));

            if ($reverseItemId < 1) {
                $error = 'Invalid order line.';
            } elseif ($reverseReason === '') {
                $error = 'Enter a reason for reversing this receipt.';
            } else {
                $pdo->beginTransaction();

                try {
                    supplier_order_reverse_receipt($pdo, $reverseItemId, $reverseQuantity, $reverseReason);
                    $pdo->commit();
                    inventory_flush_woocommerce_resync($pdo);

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1&reversed=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    inventory_discard_pending_woocommerce_resync();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    inventory_discard_pending_woocommerce_resync();
                    $error = 'Failed to reverse receipt.';
                }
            }
        } elseif ($action === 'receive_batch') {
            // Partial arrivals across several lines in ONE submit. A real shipment usually
            // arrives incomplete, and each line previously needed its own form submit and page
            // reload - eight short-shipped lines meant eight round trips.
            //
            // supplier_order_receive_item() is called per line, unchanged - the same function the
            // single-line 'receive' action above uses, and the same one
            // supplier_order_receive_all_remaining() already loops. It keeps deciding
            // ready_stock-vs-preorder routing, the remaining-quantity ceiling, order auto-advance
            // and cost-history capture. Nothing about receiving is reimplemented here.
            //
            // One transaction for the whole batch: a shipment either records as entered or not at
            // all, so a bad line can't leave inventory half-updated.
            $postedQuantities = (array) ($_POST['receive_qty'] ?? []);
            $batch = [];
            foreach ($postedQuantities as $rawItemId => $rawQuantity) {
                $itemId = (int) $rawItemId;
                $quantity = (int) $rawQuantity;
                if ($itemId > 0 && $quantity > 0) {
                    $batch[$itemId] = $quantity;
                }
            }

            if ($batch === []) {
                $error = 'Enter a quantity on at least one line.';
            } else {
                $pdo->beginTransaction();

                try {
                    foreach ($batch as $itemId => $quantity) {
                        supplier_order_receive_item($pdo, $itemId, $quantity);
                    }
                    $pdo->commit();
                    inventory_flush_woocommerce_resync($pdo);

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1&received=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    inventory_discard_pending_woocommerce_resync();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    inventory_discard_pending_woocommerce_resync();
                    $error = 'Failed to receive items.';
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
                    inventory_flush_woocommerce_resync($pdo);

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1&received=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    inventory_discard_pending_woocommerce_resync();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    inventory_discard_pending_woocommerce_resync();
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
            // SO-B - optional; supplier_order_add_payment() re-validates it exists.
            $paymentBankAccountId = isset($_POST['bank_account_id']) && (int) $_POST['bank_account_id'] > 0
                ? (int) $_POST['bank_account_id']
                : null;

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
                        $paymentNotes !== '' ? $paymentNotes : null,
                        $paymentBankAccountId
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
        } elseif ($action === 'allocate_shipping') {
            $allocationMethod = (string) ($_POST['allocation_method'] ?? '');
            $totalShippingCost = (float) ($_POST['total_shipping_cost'] ?? 0);
            $manualAllocations = [];
            foreach ($_POST['shipping_allocated'] ?? [] as $itemIdKey => $rawAmount) {
                $manualAllocations[(int) $itemIdKey] = (float) $rawAmount;
            }

            $pdo->beginTransaction();

            try {
                supplier_order_allocate_shipping($pdo, $orderId, $allocationMethod, $totalShippingCost, $manualAllocations);
                supplier_order_log_event($pdo, $orderId, 'Shipping cost allocated (' . str_replace('_', ' ', $allocationMethod) . '): RM ' . number_format($totalShippingCost, 2));
                $pdo->commit();

                app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1&shipping_allocated=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to allocate shipping cost.';
            }
        } elseif ($action === 'add_item_cost') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $costTypeInput = trim((string) ($_POST['cost_type'] ?? ''));
            $costType = $costTypeInput === '__custom__' ? trim((string) ($_POST['cost_type_other'] ?? '')) : $costTypeInput;
            $amount = (float) ($_POST['amount'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $pdo->beginTransaction();

            try {
                supplier_order_item_add_cost($pdo, $itemId, $costType, $amount, $notes !== '' ? $notes : null);
                supplier_order_log_event($pdo, $orderId, 'Additional cost added (' . $costType . '): RM ' . number_format($amount, 2));
                $pdo->commit();

                app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1&cost_added=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to add additional cost.';
            }
        } elseif ($action === 'delete_item_cost') {
            $costId = (int) ($_POST['cost_id'] ?? 0);

            if ($costId < 1) {
                $error = 'Invalid cost record.';
            } else {
                $pdo->beginTransaction();

                try {
                    supplier_order_item_delete_cost($pdo, $costId);
                    supplier_order_log_event($pdo, $orderId, 'Additional cost removed from an order line.');
                    $pdo->commit();

                    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to delete additional cost.';
                }
            }
        } elseif ($action === 'cancel') {
            $pdo->beginTransaction();

            try {
                supplier_order_cancel($pdo, $orderId);
                $pdo->commit();
                inventory_flush_woocommerce_resync($pdo);

                app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                inventory_discard_pending_woocommerce_resync();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                inventory_discard_pending_woocommerce_resync();
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
    SELECT soi.id, soi.product_id, soi.total_quantity, soi.supplier_price, soi.unit_cost_foreign, soi.subtotal, soi.variation_id,
           soi.customer_quantity, soi.moq_quantity, soi.top_up_quantity, soi.shipping_allocated,
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
$totalOrderedQty = 0;
$totalReceivedQty = 0;
foreach ($items as &$item) {
    $item['received_quantity'] = supplier_order_item_received_quantity($pdo, (int) $item['id']);
    $item['remaining_quantity'] = (int) $item['total_quantity'] - $item['received_quantity'];
    // Reverse Receiving - how much of this line can be undone right now, and what holds the rest.
    // Same function the server guard uses, so the UI can never offer a reversal the server would
    // refuse. Only computed for lines that actually have received stock.
    $item['reversible'] = $item['received_quantity'] > 0
        ? supplier_order_reversible_quantity($pdo, (int) $item['id'])
        : null;
    $item['variation_label'] = $item['variation_id'] !== null ? variation_build_label($pdo, (int) $item['variation_id']) : '';
    $orderTotal += (float) $item['subtotal'];
    $totalOrderedQty += (int) $item['total_quantity'];
    $totalReceivedQty += $item['received_quantity'];
}
unset($item);
// UI/UX Phase 5C: receiving progress bar - purely derived from the totals just computed above,
// no new query and no change to how received_quantity/remaining_quantity are calculated.
$receivingProgressPct = $totalOrderedQty > 0 ? (int) round(($totalReceivedQty / $totalOrderedQty) * 100) : 0;

// Phase 7D (Shipping Allocation) - purely derived from the shipping_allocated column already
// fetched above, no new query. Compared against the order's own shipping_fee (the existing,
// already-entered "total shipping cost for this order") rather than remembering whatever total
// was typed into the allocation form last time - shipping_allocated is the only thing this
// feature is allowed to store, so shipping_fee is the one persistent reference to check it
// against on every page load, not just right after a save.
$totalShippingAllocated = 0.0;
$anyShippingAllocated = false;
foreach ($items as $item) {
    if ($item['shipping_allocated'] !== null) {
        $anyShippingAllocated = true;
        $totalShippingAllocated += (float) $item['shipping_allocated'];
    }
}
$shippingAllocationMismatch = $anyShippingAllocated
    && abs($totalShippingAllocated - (float) $order['shipping_fee']) > 0.01;

// Phase 7F (Additional Costs Framework) - one batched query for every item's additional cost
// rows, not one query per line (supplier_order_items_list_costs_batch()).
$itemCostsByItem = supplier_order_items_list_costs_batch($pdo, array_column($items, 'id'));
$totalOtherCostsAllocated = 0.0;
foreach ($itemCostsByItem as $itemCostRows) {
    foreach ($itemCostRows as $costRow) {
        $totalOtherCostsAllocated += (float) $costRow['amount'];
    }
}

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
// Same reasoning: the blocked-order links below go to modules/orders/view.php (orders.view).
$canViewOrders = app_has_permission('orders.view');

// UI/UX Phase 5E.2: priority visibility - which open customer orders this supplier order is
// actually blocking, computed only for the two "receive this to unblock someone" statuses.
// A draft order hasn't been sent yet and a received/completed/cancelled order has nothing
// left to receive, so neither is meaningfully "blocking" anything actionable right now.
$blockedCustomerOrders = in_array($order['status'], ['ordered', 'partially_received'], true)
    ? supplier_order_blocked_customer_orders($pdo, $orderId)
    : [];

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
$paymentBankAccounts = bank_accounts_list($pdo, false);

$canManage = app_has_permission('supplier-orders.manage');
$nextStatus = supplier_order_status_next((string) $order['status']);
// Same eligibility as delete - once anything has actually been received, a supplier order
// can no longer be cancelled (see supplier_order_cancel()'s guard).
$hasReceivedStock = supplier_order_has_receiving_history($pdo, $orderId);
$canCancel = $canManage && empty($order['is_historical']) && !in_array($order['status'], ['cancelled', 'completed'], true) && !$hasReceivedStock;
// Delete was previously offered to any manager regardless of receiving history, so on a received
// order the operator confirmed a "cannot be undone" dialog and only THEN hit the server guard's
// error. supplier_order_delete_if_unreceived() has always blocked it - inventory was never at
// risk - but the button should not offer an action that can never succeed. Same condition the
// server enforces, evaluated once and shared with $canCancel above.
$canDelete = $canManage && !$hasReceivedStock;

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
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1">
            Supplier Order <?php echo app_escape($order['purchase_number']); ?>
            <?php echo supplier_order_status_badge($order['status']); ?>
            <?php if (!empty($order['is_historical'])): ?>
                <span class="badge bg-secondary">Historical</span>
            <?php endif; ?>
            <?php if ($isOverdue): ?>
                <span class="badge bg-danger">Overdue by <?php echo (int) $daysOverdue; ?> day<?php echo $daysOverdue === 1 ? '' : 's'; ?></span>
            <?php endif; ?>
        </h2>
        <p class="page-description">
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
        <?php if ($canDelete): ?>
            <form method="post" action="/modules/supplier-orders/delete.php" class="d-inline" onsubmit="return confirm('Delete this supplier order? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="order_id" value="<?php echo (int) $orderId; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
            </form>
        <?php elseif ($canManage && $hasReceivedStock): ?>
            <span class="text-muted small align-self-center" title="Deleting would discard this order's frozen landed-cost history while leaving the received stock on the shelf.">
                Received &mdash; cannot be deleted
            </span>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/index.php">Back to Supplier Orders</a>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Supplier order created.</div>
<?php endif; ?>
<?php if (isset($_GET['skipped']) && ctype_digit((string) $_GET['skipped'])): ?>
    <div class="alert alert-warning"><?php echo (int) $_GET['skipped']; ?> product(s) were skipped when this order was created because they had no valid unit cost.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Supplier order updated.</div>
<?php endif; ?>
<?php if (isset($_GET['shipping_allocated'])): ?>
    <div class="alert alert-success">Shipping cost allocated.</div>
<?php endif; ?>
<?php if (isset($_GET['cost_added'])): ?>
    <div class="alert alert-success">Additional cost added.</div>
<?php endif; ?>
<?php if (isset($_GET['delete_error'])): ?>
    <div class="alert alert-danger"><?php echo app_escape($_GET['delete_error'] === '1' ? 'Failed to delete supplier order.' : $_GET['delete_error']); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<?php if ($blockedCustomerOrders !== []): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-2">
            <i class="bi bi-exclamation-triangle"></i>
            This order is holding up <?php echo count($blockedCustomerOrders); ?> customer order<?php echo count($blockedCustomerOrders) === 1 ? '' : 's'; ?> - receiving it will unblock:
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($blockedCustomerOrders as $blocked): ?>
                <?php if ($canViewOrders): ?>
                    <a class="badge bg-danger text-decoration-none" href="/modules/orders/view.php?id=<?php echo (int) $blocked['order_id']; ?>"><?php echo app_escape(order_display_number_compact((string) $blocked['order_number'])); ?></a>
                <?php else: ?>
                    <span class="badge bg-danger"><?php echo app_escape(order_display_number_compact((string) $blocked['order_number'])); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
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
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card p-4 h-100">
                    <h5 class="mb-3"><i class="bi bi-truck"></i> Supplier</h5>
                    <table class="table table-borderless mb-0">
                        <tr>
                            <th>Supplier</th>
                            <td>
                                <?php if ($canViewSuppliers): ?>
                                    <a href="/modules/suppliers/view.php?id=<?php echo (int) $order['supplier_id']; ?>"><?php echo app_escape($order['supplier_name']); ?></a>
                                <?php else: ?>
                                    <?php echo app_escape($order['supplier_name']); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Created Date</th>
                            <td><?php echo app_escape($order['order_date'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>Expected Delivery</th>
                            <td>
                                <?php echo app_escape($order['expected_delivery_date'] ?? '-'); ?>
                                <?php if ($isOverdue): ?>
                                    <div><span class="badge bg-danger">Overdue by <?php echo (int) $daysOverdue; ?> day<?php echo $daysOverdue === 1 ? '' : 's'; ?></span></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Received Date</th>
                            <td><?php echo app_escape($order['received_date'] ?? '-'); ?></td>
                        </tr>
                        <?php if (!empty($order['notes'])): ?>
                            <tr>
                                <th>Notes</th>
                                <td><?php echo nl2br(app_escape($order['notes'])); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                    <div class="mt-2">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Receiving progress</span>
                            <span><?php echo (int) $totalReceivedQty; ?> / <?php echo (int) $totalOrderedQty; ?> units</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $receivingProgressPct; ?>%;" aria-valuenow="<?php echo $receivingProgressPct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card p-4 h-100">
                    <h5 class="mb-3"><i class="bi bi-cash-coin"></i> Payment Summary</h5>
                    <table class="table table-borderless mb-0">
                        <tr>
                            <th>Payment Status</th>
                            <td><?php echo supplier_order_payment_status_badge((string) $order['payment_status']); ?></td>
                        </tr>
                        <tr>
                            <th>Product Subtotal</th>
                            <td class="text-end">RM <?php echo app_escape(number_format($orderTotal, 2)); ?></td>
                        </tr>
                        <tr>
                            <th>Shipping Fee</th>
                            <td class="text-end">RM <?php echo app_escape(number_format((float) $order['shipping_fee'], 2)); ?></td>
                        </tr>
                        <tr class="fw-semibold">
                            <th>Total Purchase Amount</th>
                            <td class="text-end">RM <?php echo app_escape(number_format($totalPurchaseAmount, 2)); ?></td>
                        </tr>
                        <tr>
                            <th>Paid Amount</th>
                            <td class="text-end">RM <?php echo app_escape(number_format($paidAmount, 2)); ?></td>
                        </tr>
                        <tr>
                            <th>Remaining Amount</th>
                            <td class="text-end"><?php echo $remainingAmount > 0.001 ? '<span class="text-danger">RM ' . app_escape(number_format($remainingAmount, 2)) . '</span>' : 'RM ' . app_escape(number_format($remainingAmount, 2)); ?></td>
                        </tr>
                    </table>
                    <?php $paidPct = $totalPurchaseAmount > 0 ? (int) round(min(100, ($paidAmount / $totalPurchaseAmount) * 100)) : 0; ?>
                    <div class="mt-2">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Paid</span>
                            <span><?php echo $paidPct; ?>%</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $paidPct; ?>%;" aria-valuenow="<?php echo $paidPct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            // Phase 6B (Supplier Order currency) - only shown for a foreign-currency order;
            // a plain selling-currency order looks exactly as it did before this card existed.
            $supplierCurrency = (string) ($order['currency'] ?? SYSTEM_SELLING_CURRENCY);
            ?>
            <?php if ($supplierCurrency !== SYSTEM_SELLING_CURRENCY): ?>
                <?php
                $currencySymbols = ['JPY' => '¥', 'CNY' => '¥', 'USD' => '$'];
                $currencySymbol = $currencySymbols[$supplierCurrency] ?? ($supplierCurrency . ' ');
                $orderExchangeRate = $order['exchange_rate'] !== null ? (float) $order['exchange_rate'] : 1.0;
                // Small rates (e.g. JPY, where 1 unit is worth a fraction of the selling currency)
                // read clearer quoted per 100; anything else is shown per 1 - purely a display
                // choice, the stored/calculated rate itself is always "1 unit = X selling currency".
                $exchangeRateDisplay = $orderExchangeRate < 0.1
                    ? ('100 ' . $supplierCurrency . ' = RM' . number_format($orderExchangeRate * 100, 2))
                    : ('1 ' . $supplierCurrency . ' = RM' . number_format($orderExchangeRate, 2));
                ?>
                <div class="col-md-6">
                    <div class="card p-4 h-100">
                        <h5 class="mb-3"><i class="bi bi-currency-exchange"></i> Supplier Currency</h5>
                        <table class="table table-borderless mb-0">
                            <tr>
                                <th>Supplier Currency</th>
                                <td class="text-end"><?php echo app_escape($supplierCurrency); ?></td>
                            </tr>
                            <tr>
                                <th>Exchange Rate</th>
                                <td class="text-end"><?php echo app_escape($exchangeRateDisplay); ?></td>
                            </tr>
                            <tr>
                                <th>Supplier Total</th>
                                <td class="text-end"><?php echo app_escape($currencySymbol . number_format((float) ($order['foreign_total'] ?? 0), 2)); ?></td>
                            </tr>
                            <tr class="fw-semibold">
                                <th>Converted Total</th>
                                <td class="text-end"><?php echo app_escape(SYSTEM_SELLING_CURRENCY); ?> <?php echo app_escape(number_format($orderTotal, 2)); ?></td>
                            </tr>
                        </table>
                        <div class="form-text mt-2">Product cost only - shipping fee and payments are tracked in <?php echo app_escape(SYSTEM_SELLING_CURRENCY); ?>.</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card p-4">
            <h5 class="mb-3"><i class="bi bi-list-check"></i> Items</h5>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th class="text-end">Ordered</th>
                        <th class="text-end">Received</th>
                        <th class="text-end">Outstanding</th>
                        <th class="text-end">Unit Cost</th>
                        <?php if ($canManage): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php $itemPct = (int) $item['total_quantity'] > 0 ? (int) round(($item['received_quantity'] / (int) $item['total_quantity']) * 100) : 0; ?>
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
                            <td class="text-end">
                                <?php echo app_escape((string) $item['total_quantity']); ?>
                                <?php
                                // UI/UX Phase 5E.2: demand breakdown - customer_quantity/moq_quantity/
                                // top_up_quantity are already stored by purchase_planning_generate() but were
                                // never displayed. A manually created/added line (modules/supplier-orders/
                                // create.php, supplier_order_apply_edit()'s "new line" path) leaves all three
                                // at their schema default of 0, so the line is skipped entirely rather than
                                // showing a misleading "Customer: 0" next to a real ordered quantity.
                                $demandBreakdownParts = [];
                                if ((int) $item['customer_quantity'] > 0) {
                                    $demandBreakdownParts[] = 'Customer: ' . (int) $item['customer_quantity'];
                                }
                                if ((int) $item['top_up_quantity'] > 0) {
                                    $demandBreakdownParts[] = 'Stock top-up: ' . (int) $item['top_up_quantity'];
                                }
                                if ((int) $item['moq_quantity'] > 0) {
                                    $demandBreakdownParts[] = 'MOQ: ' . (int) $item['moq_quantity'];
                                }
                                ?>
                                <?php if ($demandBreakdownParts !== []): ?>
                                    <div class="text-muted small"><?php echo app_escape(implode(' + ', $demandBreakdownParts)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php echo app_escape((string) $item['received_quantity']); ?>
                                <?php if ((int) $item['total_quantity'] > 0): ?>
                                    <div class="progress ms-auto" style="height: 4px; width: 60px;">
                                        <div class="progress-bar <?php echo $itemPct >= 100 ? 'bg-success' : 'bg-primary'; ?>" role="progressbar" style="width: <?php echo $itemPct; ?>%;"></div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo app_escape((string) $item['remaining_quantity']); ?></td>
                            <td class="text-end">
                                RM <?php echo app_escape(number_format((float) $item['supplier_price'], 2)); ?>
                                <?php if ($supplierCurrency !== SYSTEM_SELLING_CURRENCY && $item['unit_cost_foreign'] !== null): ?>
                                    <div class="text-muted small"><?php echo app_escape($currencySymbol . number_format((float) $item['unit_cost_foreign'], 2)); ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if ($canManage): ?>
                                <td class="text-end">
                                    <?php if (!empty($order['is_historical'])): ?>
                                        <span class="text-muted small">&mdash;</span>
                                    <?php elseif ($item['remaining_quantity'] > 0): ?>
                                        <?php /* Belongs to the single batch form rendered below the table via the
                                                 HTML5 form="" attribute - a form cannot be nested inside another,
                                                 and one submit for the whole shipment is the point. */ ?>
                                        <input type="number" class="form-control form-control-sm receive-qty ms-auto" style="width: 90px;"
                                               form="receive-batch-form"
                                               name="receive_qty[<?php echo (int) $item['id']; ?>]"
                                               min="0" max="<?php echo (int) $item['remaining_quantity']; ?>"
                                               data-remaining="<?php echo (int) $item['remaining_quantity']; ?>"
                                               placeholder="0">
                                    <?php else: ?>
                                        <span class="badge bg-success">Complete</span>
                                    <?php endif; ?>
                                    <?php if ($item['reversible'] !== null): ?>
                                        <?php /* Compact trigger only - the quantity/reason form lives in one shared
                                                 modal below, rather than repeating a full form on every received row. */ ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning mt-1 js-reverse-receipt"
                                                data-item-id="<?php echo (int) $item['id']; ?>"
                                                data-label="<?php echo app_escape($item['sku'] . ' - ' . $item['product_name']); ?>"
                                                data-received="<?php echo (int) $item['reversible']['received']; ?>"
                                                data-reversible="<?php echo (int) $item['reversible']['reversible']; ?>"
                                                data-blocked="<?php echo (int) $item['reversible']['blocked']; ?>"
                                                data-preorder="<?php echo in_array($item['reversible']['product_type'], ['preorder', 'early_bird'], true) ? '1' : '0'; ?>">
                                            Reverse Receipt
                                        </button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($items === []): ?>
                        <tr>
                            <td colspan="<?php echo $canManage ? 7 : 6; ?>" class="text-muted">No items on this order.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        $hasOutstandingLines = false;
        foreach ($items as $itemRow) {
            if ((int) $itemRow['remaining_quantity'] > 0) {
                $hasOutstandingLines = true;
                break;
            }
        }
        ?>
        <?php if ($canManage && empty($order['is_historical']) && $hasOutstandingLines): ?>
            <form method="post" id="receive-batch-form" class="d-flex flex-wrap gap-2 align-items-center justify-content-end mt-3 border-top pt-3"
                  onsubmit="return confirm('Receive the quantities entered above?');">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="receive_batch">
                <span class="text-muted small me-auto">
                    Enter what actually arrived on each line, then receive them together.
                    Blank or 0 lines are skipped. <strong id="receive-batch-summary">0</strong> unit(s) entered.
                </span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="receive-fill-remaining">Fill Remaining</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="receive-clear">Clear</button>
                <button type="submit" class="btn btn-sm btn-primary">Receive Entered</button>
            </form>
        <?php endif; ?>

        <?php if ($items !== []): ?>
            <div class="card p-4 mt-4">
                <h5 class="mb-3"><i class="bi bi-box-seam"></i> Shipping &amp; Additional Costs</h5>

                <?php if ($shippingAllocationMismatch): ?>
                    <div class="alert alert-warning py-2">
                        Allocated total (RM <?php echo app_escape(number_format($totalShippingAllocated, 2)); ?>) does not match this order's Shipping Fee (RM <?php echo app_escape(number_format((float) $order['shipping_fee'], 2)); ?>).
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Total Shipping Allocated</span>
                    <span>
                        RM <?php echo app_escape(number_format($totalShippingAllocated, 2)); ?>
                        of RM <?php echo app_escape(number_format((float) $order['shipping_fee'], 2)); ?> shipping fee
                        <?php if (!$anyShippingAllocated): ?><span class="text-muted">(not yet allocated)</span><?php endif; ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between small text-muted mb-3">
                    <span>Total Additional Costs</span>
                    <span>RM <?php echo app_escape(number_format($totalOtherCostsAllocated, 2)); ?></span>
                </div>

                <?php if ($canManage): ?>
                    <form method="post" id="shipping-allocation-form">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="allocate_shipping">
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Total Shipment Shipping Cost (RM)</label>
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="total_shipping_cost" id="shipping-total-input" value="<?php echo app_escape(number_format((float) $order['shipping_fee'], 2, '.', '')); ?>" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1">Allocation Method</label>
                                <select class="form-select form-select-sm" name="allocation_method" id="shipping-method-select">
                                    <option value="by_quantity">By Item Quantity</option>
                                    <option value="by_cost">By Item Cost Value</option>
                                    <option value="manual">Manual Override</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Allocate Shipping</button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Product</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Cost Value</th>
                                        <th class="text-end">Shipping Allocation</th>
                                        <th class="text-end d-none" id="manual-alloc-header">Manual Amount (RM)</th>
                                        <th>Additional Costs</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td><?php echo app_escape($item['sku']); ?></td>
                                            <td><?php echo app_escape($item['product_name']); ?></td>
                                            <td class="text-end"><?php echo (int) $item['total_quantity']; ?></td>
                                            <td class="text-end">RM <?php echo app_escape(number_format((float) $item['subtotal'], 2)); ?></td>
                                            <td class="text-end"><?php echo $item['shipping_allocated'] !== null ? ('RM ' . app_escape(number_format((float) $item['shipping_allocated'], 2))) : '&mdash;'; ?></td>
                                            <td class="text-end d-none manual-alloc-cell">
                                                <input type="number" step="0.01" min="0" class="form-control form-control-sm manual-alloc-input" name="shipping_allocated[<?php echo (int) $item['id']; ?>]" value="<?php echo $item['shipping_allocated'] !== null ? app_escape(number_format((float) $item['shipping_allocated'], 2, '.', '')) : '0.00'; ?>">
                                            </td>
                                            <td>
                                                <?php foreach ($itemCostsByItem[(int) $item['id']] ?? [] as $costRow): ?>
                                                    <span class="badge bg-light text-dark border me-1 mb-1 d-inline-flex align-items-center gap-1">
                                                        <?php echo app_escape($costRow['cost_type']); ?>: RM <?php echo app_escape(number_format((float) $costRow['amount'], 2)); ?>
                                                        <?php if ($canManage): ?>
                                                            <form method="post" class="d-inline" onsubmit="return confirm('Remove this additional cost?');">
                                                                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="delete_item_cost">
                                                                <input type="hidden" name="cost_id" value="<?php echo (int) $costRow['id']; ?>">
                                                                <button type="submit" class="btn-close" style="font-size: 0.55rem;" aria-label="Remove"></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if (($itemCostsByItem[(int) $item['id']] ?? []) === []): ?>
                                                    <span class="text-muted small">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-end small text-muted mt-2 d-none" id="manual-running-total">Manual total: RM <span id="manual-running-total-value">0.00</span></div>
                    </form>

                    <hr class="my-3">
                    <div class="fw-semibold mb-2">Add Additional Cost</div>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="add_item_cost">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Order Line</label>
                            <select name="item_id" class="form-select form-select-sm" required>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?php echo (int) $item['id']; ?>"><?php echo app_escape($item['sku'] . ' - ' . $item['product_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Cost Type</label>
                            <select name="cost_type" id="item-cost-type-select" class="form-select form-select-sm">
                                <?php foreach (SUPPLIER_ORDER_ITEM_COST_TYPE_SUGGESTIONS as $costTypeSuggestion): ?>
                                    <option value="<?php echo app_escape($costTypeSuggestion); ?>"><?php echo app_escape($costTypeSuggestion); ?></option>
                                <?php endforeach; ?>
                                <option value="__custom__">Custom type&hellip;</option>
                            </select>
                            <input type="text" class="form-control form-control-sm mt-2 d-none" id="item-cost-type-other" name="cost_type_other" maxlength="50" placeholder="e.g. Insurance">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Amount (RM)</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="amount" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Notes</label>
                            <input type="text" class="form-control form-control-sm" name="notes">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100">Add Cost</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <?php if ($canManage && empty($order['is_historical'])): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3"><i class="bi bi-arrow-repeat"></i> Order Workflow</h5>
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
            <h5 class="mb-3"><i class="bi bi-cash-stack"></i> Payments</h5>
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
                                    <?php if (!empty($payment['bank_account_name'])): ?>
                                        &middot; <?php echo app_escape($payment['bank_account_name']); ?>
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
                        <label class="form-label small mb-1">Account (optional)</label>
                        <select class="form-select form-select-sm" name="bank_account_id">
                            <option value="">None</option>
                            <?php foreach ($paymentBankAccounts as $account): ?>
                                <option value="<?php echo (int) $account['id']; ?>">
                                    <?php echo app_escape($account['name']); ?> (<?php echo app_escape(strtoupper((string) $account['account_type'])); ?><?php echo !empty($account['currency']) ? ', ' . app_escape($account['currency']) : ''; ?>)<?php echo (int) $account['is_active'] === 0 ? ' [Inactive]' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
            <h5 class="mb-3"><i class="bi bi-box-seam"></i> Receiving History</h5>
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
            <h5 class="mb-3"><i class="bi bi-clock-history"></i> Edit History</h5>
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

<?php if ($canManage && $items !== []): ?>
    <script>
        (function() {
            // Phase 7D (Shipping Allocation) - same toggle-by-classList shape already used elsewhere
            // in this app (e.g. modules/products/_form.php's Enable Sale / Cost Currency toggles),
            // self-contained here since nothing else on this page needs it.
            var methodSelect = document.getElementById('shipping-method-select');
            var manualHeader = document.getElementById('manual-alloc-header');
            var manualCells = document.querySelectorAll('.manual-alloc-cell');
            var manualInputs = document.querySelectorAll('.manual-alloc-input');
            var runningTotalWrap = document.getElementById('manual-running-total');
            var runningTotalValue = document.getElementById('manual-running-total-value');
            var hasExistingAllocation = <?php echo $anyShippingAllocated ? 'true' : 'false'; ?>;

            function applyMethod() {
                var isManual = methodSelect.value === 'manual';
                if (manualHeader) {
                    manualHeader.classList.toggle('d-none', !isManual);
                }
                manualCells.forEach(function(cell) {
                    cell.classList.toggle('d-none', !isManual);
                });
                if (runningTotalWrap) {
                    runningTotalWrap.classList.toggle('d-none', !isManual);
                }
                updateRunningTotal();
            }

            function updateRunningTotal() {
                if (!runningTotalValue) {
                    return;
                }
                var total = 0;
                manualInputs.forEach(function(input) {
                    total += parseFloat(input.value) || 0;
                });
                runningTotalValue.textContent = total.toFixed(2);
            }

            if (methodSelect) {
                methodSelect.addEventListener('change', applyMethod);
                applyMethod();
            }
            manualInputs.forEach(function(input) {
                input.addEventListener('input', updateRunningTotal);
            });

            var form = document.getElementById('shipping-allocation-form');
            if (form) {
                form.addEventListener('submit', function(event) {
                    // "Do not silently overwrite existing allocations" - every method (including
                    // switching from a prior manual allocation to an automatic one, or vice versa)
                    // replaces every line's shipping_allocated, so this fires regardless of which
                    // method is selected whenever an allocation already exists.
                    if (hasExistingAllocation && !confirm('This order already has a shipping allocation. Continue and overwrite it?')) {
                        event.preventDefault();
                    }
                });
            }

            // Phase 7F (Additional Costs Framework) - same toggle-by-classList shape as the Cost
            // Currency "Other" field on modules/products/_form.php, self-contained here.
            var costTypeSelect = document.getElementById('item-cost-type-select');
            var costTypeOther = document.getElementById('item-cost-type-other');
            if (costTypeSelect && costTypeOther) {
                var applyCostType = function() {
                    costTypeOther.classList.toggle('d-none', costTypeSelect.value !== '__custom__');
                };
                costTypeSelect.addEventListener('change', applyCostType);
                applyCostType();
            }
        })();
    </script>
<?php endif; ?>
<?php if ($canManage && empty($order['is_historical'])): ?>
<div class="modal fade" id="reverseReceiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="reverse_receipt">
            <input type="hidden" name="reverse_item_id" id="reverse-item-id" value="">
            <div class="modal-header">
                <h5 class="modal-title">Reverse Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong id="reverse-item-label"></strong></p>
                <p class="text-muted small">
                    Received: <span id="reverse-received">0</span> &middot; Reversible now: <strong id="reverse-max">0</strong>
                </p>
                <div class="alert alert-warning py-2 small d-none" id="reverse-blocked-note"></div>
                <div class="mb-3">
                    <label class="form-label">Quantity to reverse</label>
                    <input type="number" class="form-control" name="reverse_quantity" id="reverse-quantity" min="1" required>
                </div>
                <div class="mb-1">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="reverse_reason" maxlength="100" required
                           placeholder="e.g. wrong product received">
                    <div class="form-text">Recorded on the inventory ledger and the order timeline.</div>
                </div>
                <p class="text-muted small mb-0 mt-3">
                    Stock returns to Incoming and the original receipt stays on the ledger &mdash; a reversing
                    entry is added instead. Nothing is deleted.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning">Reverse Receipt</button>
            </div>
        </form>
    </div>
</div>
<script>
// One shared Reverse Receipt modal, populated from the clicked row's data attributes - keeps a
// single form on the page instead of repeating one per received line. Max quantity comes from
// supplier_order_reversible_quantity(), the same function the server guard uses; the server
// re-validates it inside the row lock regardless.
(function () {
    var modalEl = document.getElementById('reverseReceiptModal');
    // Bootstrap is loaded by includes/footer.php, which is required AFTER this script - so
    // window.bootstrap does not exist yet at parse time. Gating the whole block on it here made
    // this return early, attach no listeners, and leave the button silently dead. Listeners are
    // attached unconditionally; bootstrap is resolved lazily inside the handler, by which point
    // the footer has loaded it.
    if (!modalEl) { return; }

    document.querySelectorAll('.js-reverse-receipt').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var reversible = parseInt(btn.getAttribute('data-reversible'), 10) || 0;
            var blocked = parseInt(btn.getAttribute('data-blocked'), 10) || 0;
            var isPreorder = btn.getAttribute('data-preorder') === '1';

            document.getElementById('reverse-item-id').value = btn.getAttribute('data-item-id');
            document.getElementById('reverse-item-label').textContent = btn.getAttribute('data-label');
            document.getElementById('reverse-received').textContent = btn.getAttribute('data-received');
            document.getElementById('reverse-max').textContent = String(reversible);

            var qty = document.getElementById('reverse-quantity');
            qty.max = String(reversible);
            qty.value = reversible > 0 ? String(reversible) : '';
            qty.disabled = reversible < 1;

            var note = document.getElementById('reverse-blocked-note');
            if (blocked > 0) {
                note.textContent = blocked + ' unit(s) cannot be reversed - they are '
                    + (isPreorder ? 'allocated to customer storage' : 'reserved or already shipped to customers')
                    + '. Release those first.';
                note.classList.remove('d-none');
            } else {
                note.classList.add('d-none');
            }

            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else {
                // Bootstrap genuinely unavailable (CDN blocked/offline) - fall back to showing
                // the modal directly rather than leaving the button doing nothing.
                modalEl.classList.add('show');
                modalEl.style.display = 'block';
                modalEl.removeAttribute('aria-hidden');
            }
        });
    });

    // Plain-DOM close for the fallback path above; harmless when Bootstrap is present because it
    // handles its own dismissal first.
    modalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function (closer) {
        closer.addEventListener('click', function () {
            if (!window.bootstrap || !window.bootstrap.Modal) {
                modalEl.classList.remove('show');
                modalEl.style.display = 'none';
                modalEl.setAttribute('aria-hidden', 'true');
            }
        });
    });
})();
</script>
<?php endif; ?>

<script>
// Batch-receive helpers. Progressive enhancement only - with JS off the quantity inputs and the
// Receive Entered button still work exactly the same; these just speed up the common cases.
(function () {
    var inputs = document.querySelectorAll('.receive-qty');
    if (!inputs.length) { return; }
    var summary = document.getElementById('receive-batch-summary');
    var fillBtn = document.getElementById('receive-fill-remaining');
    var clearBtn = document.getElementById('receive-clear');

    function sync() {
        if (!summary) { return; }
        var total = 0;
        for (var i = 0; i < inputs.length; i++) {
            var v = parseInt(inputs[i].value, 10);
            if (!isNaN(v) && v > 0) { total += v; }
        }
        summary.textContent = String(total);
    }

    function setAll(useRemaining) {
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].value = useRemaining ? (inputs[i].getAttribute('data-remaining') || '') : '';
        }
        sync();
    }

    if (fillBtn) { fillBtn.addEventListener('click', function () { setAll(true); }); }
    if (clearBtn) { clearBtn.addEventListener('click', function () { setAll(false); }); }
    for (var i = 0; i < inputs.length; i++) { inputs[i].addEventListener('input', sync); }
    sync();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>