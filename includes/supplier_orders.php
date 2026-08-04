<?php

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/customer_storage.php';
require_once __DIR__ . '/product_variations.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/product_cost.php';

// --- Workflow: Draft -> Ordered -> Arrived -> Completed --------------------------------
// "Arrived" is a display label for the existing status = 'received' value (set
// automatically by supplier_order_receive_item() once every line is fully received) -
// there is no separate database value for it, so this never duplicates that logic.
//
// 'partially_received' and 'cancelled' are NOT part of this linear next-step sequence:
// - partially_received is an automatic label supplier_order_receive_item() sets in place
//   of 'ordered' once SOME (but not all) quantity has arrived - supplier_order_status_next()
//   special-cases it below to still point at 'received', so the "Mark Arrived" button for
//   the remainder keeps showing exactly as it does for 'ordered'.
// - cancelled is a terminal side-branch reachable only from draft/ordered (never once any
//   receiving history exists) via supplier_order_cancel() - the exact same eligibility
//   guard already used by supplier_order_delete_if_unreceived().
const SUPPLIER_ORDER_WORKFLOW = ['draft', 'ordered', 'received', 'completed'];

function supplier_order_status_label(string $status): string
{
    $labels = [
        'draft' => 'Draft',
        'ordered' => 'Ordered',
        'partially_received' => 'Partially Received',
        'received' => 'Arrived',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'waiting_payment' => 'Waiting Payment',
        'shipping' => 'Shipping',
    ];

    return $labels[$status] ?? ucfirst($status);
}

function supplier_order_status_badge(string $status): string
{
    $colors = [
        'draft' => 'secondary',
        'ordered' => 'info text-dark',
        'partially_received' => 'primary',
        'received' => 'warning text-dark',
        'completed' => 'success',
        'cancelled' => 'danger',
        'waiting_payment' => 'secondary',
        'shipping' => 'info text-dark',
    ];
    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' . $color . '">' . htmlspecialchars(supplier_order_status_label($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Next step in the linear Draft->Ordered->Arrived->Completed workflow, or null if there
 * is none (already Completed/Cancelled, or an older waiting_payment/shipping status that
 * predates this simplified workflow and isn't part of it - those are left as-is, displayed
 * with no action button, rather than guessed into the new flow).
 *
 * 'partially_received' is treated exactly like 'ordered' here (next = 'received') since
 * it's the same "still waiting on the rest of the shipment" state - see SUPPLIER_ORDER_WORKFLOW's
 * doc comment for why it isn't a member of the array itself.
 */
function supplier_order_status_next(string $status): ?string
{
    if ($status === 'partially_received') {
        return 'received';
    }

    $index = array_search($status, SUPPLIER_ORDER_WORKFLOW, true);
    if ($index === false || !isset(SUPPLIER_ORDER_WORKFLOW[$index + 1])) {
        return null;
    }

    return SUPPLIER_ORDER_WORKFLOW[$index + 1];
}

function supplier_order_status_next_action_label(string $status): ?string
{
    $labels = [
        'draft' => 'Submit Order',
        'ordered' => 'Mark Arrived',
        'partially_received' => 'Mark Arrived',
        'received' => 'Complete Order',
    ];

    return $labels[$status] ?? null;
}

// --- Payment tracking: independent of the Draft->Ordered->Arrived->Completed status ------
// workflow above - a Completed order can still be unpaid, a Draft order could in theory be
// prepaid. payment_status is a plain admin-set field (see modules/supplier-orders/create.php/
// edit.php); Paid/Remaining Amount are always derived live from supplier_order_payments,
// never a stored/cached total, so they can never drift from the actual payment entries.

const SUPPLIER_ORDER_PAYMENT_STATUSES = ['unpaid', 'partial', 'paid'];

function supplier_order_payment_status_label(string $status): string
{
    $labels = [
        'unpaid' => 'Unpaid',
        'partial' => 'Partially Paid',
        'paid' => 'Paid',
    ];

    return $labels[$status] ?? ucfirst($status);
}

function supplier_order_payment_status_badge(string $status): string
{
    $colors = [
        'unpaid' => 'danger',
        'partial' => 'warning text-dark',
        'paid' => 'success',
    ];
    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' . $color . '">' . htmlspecialchars(supplier_order_payment_status_label($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Total ever recorded against this order in supplier_order_payments - computed live (never
 * cached) so Paid Amount/Remaining Amount can never drift from the actual add/delete history.
 */
function supplier_order_paid_amount(PDO $pdo, int $orderId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM supplier_order_payments WHERE supplier_order_id = ?');
    $stmt->execute([$orderId]);

    return (float) $stmt->fetchColumn();
}

/**
 * Every payment recorded against this order, newest first - for the Payments card on
 * modules/supplier-orders/view.php.
 */
function supplier_order_list_payments(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('
        SELECT sop.id, sop.amount, sop.payment_date, sop.payment_method, sop.notes, sop.created_at,
               sop.bank_account_id, ba.name AS bank_account_name, u.name AS user_name
        FROM supplier_order_payments sop
        LEFT JOIN bank_accounts ba ON ba.id = sop.bank_account_id
        LEFT JOIN users u ON u.id = sop.created_by
        WHERE sop.supplier_order_id = ?
        ORDER BY sop.payment_date DESC, sop.id DESC
    ');
    $stmt->execute([$orderId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Records one payment against a supplier order - purely additive, never touches/overwrites
 * supplier_orders' own total (estimated_cost/shipping_fee). Caller is responsible for the
 * surrounding transaction.
 */
/**
 * SO-B - $bankAccountId is an OPTIONAL trailing parameter (which account the money left from),
 * defaulted to null so every existing caller behaves exactly as before. Validated here rather
 * than trusted: a non-existent id is stored as NULL instead of raising an FK error mid-payment,
 * since a mistyped account must never block recording a real payment that actually happened.
 * Tagging only - nothing derives a balance from it.
 */
function supplier_order_add_payment(PDO $pdo, int $orderId, float $amount, ?string $paymentDate, ?string $paymentMethod, ?string $notes, ?int $bankAccountId = null): void
{
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    if ($bankAccountId !== null) {
        $accountStmt = $pdo->prepare('SELECT COUNT(*) FROM bank_accounts WHERE id = ?');
        $accountStmt->execute([$bankAccountId]);
        if ((int) $accountStmt->fetchColumn() === 0) {
            $bankAccountId = null;
        }
    }

    $pdo->prepare('
        INSERT INTO supplier_order_payments (supplier_order_id, amount, payment_date, payment_method, bank_account_id, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$orderId, round($amount, 2), $paymentDate, $paymentMethod, $bankAccountId, $notes, $_SESSION['user_id'] ?? null]);
}

/**
 * Deletes one payment record. This only removes a bookkeeping entry - it never touches
 * inventory/receiving/ledger data, so no reversal logic is needed here.
 */
function supplier_order_delete_payment(PDO $pdo, int $paymentId): void
{
    $pdo->prepare('DELETE FROM supplier_order_payments WHERE id = ?')->execute([$paymentId]);
}

/**
 * Whether any inventory has ever actually been received against this order - the one
 * fact that gates both editing (11.7: "remain available until inventory has been
 * received") and deletion (11.8: blocked once receiving history exists). Derived from the
 * ledger itself (inventory_transactions), never a status flag, so it can't drift out of
 * sync with what actually happened.
 */
function supplier_order_has_receiving_history(PDO $pdo, int $orderId): bool
{
    // SUM, not COUNT. Reverse Receiving records a NEGATIVE 'supplier_receive' row rather than
    // deleting the original, so both rows survive - counting them would leave a fully reversed
    // order permanently locked from edit/cancel/delete and the reversal would unlock nothing.
    // Summing asks the real question: is any stock still received against this order?
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(it.quantity), 0)
        FROM inventory_transactions it
        INNER JOIN supplier_order_items soi ON soi.id = it.reference_id AND it.reference_type = 'supplier_order_item'
        WHERE soi.supplier_order_id = ? AND it.transaction_type = 'supplier_receive'
    ");
    $stmt->execute([$orderId]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Units received so far for one supplier order line item, derived from the
 * inventory_transactions ledger rather than a dedicated column (schema has none),
 * so partial/multiple receiving sessions stay correct without new columns.
 */
function supplier_order_item_received_quantity(PDO $pdo, int $itemId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0)
        FROM inventory_transactions
        WHERE reference_type = 'supplier_order_item' AND reference_id = ? AND transaction_type = 'supplier_receive'
    ");
    $stmt->execute([$itemId]);

    return (int) $stmt->fetchColumn();
}

/**
 * SO-D - ordered/received/outstanding unit totals for many supplier orders in ONE query.
 *
 * Per-order backorder visibility already exists on modules/supplier-orders/view.php (each line
 * shows received and remaining); what was missing is the cross-order answer - "what am I still
 * waiting on, across every supplier" - which previously meant opening each PO one at a time.
 *
 * Received is derived from the inventory_transactions ledger, exactly like
 * supplier_order_item_received_quantity() does for a single line - the same authoritative source,
 * just aggregated set-based instead of one query per line. Nothing is cached or stored, so this
 * can never drift from actual receiving history.
 *
 * Read-only. Does not change receiving, status transitions, or any total.
 *
 * @return array<int, array{ordered:int, received:int, outstanding:int}> keyed by supplier_order_id
 */
function supplier_orders_outstanding_batch(PDO $pdo, array $supplierOrderIds): array
{
    $supplierOrderIds = array_values(array_unique(array_map('intval', $supplierOrderIds)));
    if ($supplierOrderIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($supplierOrderIds), '?'));
    $stmt = $pdo->prepare("
        SELECT soi.supplier_order_id,
               COALESCE(SUM(soi.total_quantity), 0) AS ordered_qty,
               COALESCE(SUM(COALESCE(received.received_qty, 0)), 0) AS received_qty
        FROM supplier_order_items soi
        LEFT JOIN (
            SELECT reference_id, SUM(quantity) AS received_qty
            FROM inventory_transactions
            WHERE reference_type = 'supplier_order_item' AND transaction_type = 'supplier_receive'
            GROUP BY reference_id
        ) received ON received.reference_id = soi.id
        WHERE soi.supplier_order_id IN ({$placeholders})
        GROUP BY soi.supplier_order_id
    ");
    $stmt->execute($supplierOrderIds);

    $byOrder = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ordered = (int) $row['ordered_qty'];
        $received = (int) $row['received_qty'];
        $byOrder[(int) $row['supplier_order_id']] = [
            'ordered' => $ordered,
            'received' => $received,
            // Clamped: an over-receive would otherwise show as negative outstanding.
            'outstanding' => max($ordered - $received, 0),
        ];
    }

    return $byOrder;
}

/**
 * Mark stock as incoming for a freshly created supplier order line item. $variationId is
 * null for a simple product's line item, or the specific variation SKU being ordered.
 */
function supplier_order_mark_incoming(PDO $pdo, int $productId, int $itemId, int $quantity, ?int $variationId = null): void
{
    if ($quantity < 1) {
        return;
    }

    inventory_get_or_create_row($pdo, $productId, $variationId);

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET incoming_quantity = incoming_quantity + ?
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$quantity, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'supplier_order_placed', $quantity, 'supplier_order_item', $itemId, $variationId);
}

/**
 * Total quantity already routed into customer storage against one order item, derived
 * from the ledger (customer_storage.order_item_id) rather than a counter, so partial/
 * multiple receiving sessions stay correct and an order is never matched twice.
 */
function supplier_order_item_customer_storage_allocated(PDO $pdo, int $orderItemId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM customer_storage WHERE order_item_id = ?');
    $stmt->execute([$orderItemId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Marks received preorder/early-bird stock as arrived but not yet allocated - it moves
 * from incoming_quantity into arrived_quantity, never touching available_quantity or
 * customer_storage directly. A human then manually allocates it to specific outstanding
 * customer orders (or releases it to available stock) via modules/inventory/allocate.php.
 */
function supplier_order_receive_preorder_quantity(PDO $pdo, int $productId, ?int $variationId, int $supplierItemId, int $quantity): void
{
    inventory_get_or_create_row($pdo, $productId, $variationId);

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET incoming_quantity = GREATEST(incoming_quantity - ?, 0),
            arrived_quantity = arrived_quantity + ?
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$quantity, $quantity, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'supplier_receive', $quantity, 'supplier_order_item', $supplierItemId, $variationId);
}

/**
 * Receive units for one supplier order line item: for ready_stock, moves stock from
 * incoming into available as before. For preorder/early_bird, moves it into
 * arrived_quantity pending manual allocation (see supplier_order_receive_preorder_quantity()).
 * Once every item on the parent order has been fully received, the order is auto-advanced
 * to status 'received'. The line item's own variation_id (set at PO creation time)
 * determines which inventory row moves.
 */
function supplier_order_receive_item(PDO $pdo, int $itemId, int $quantity): void
{
    if ($quantity < 1) {
        throw new RuntimeException('Quantity must be at least 1.');
    }

    $stmt = $pdo->prepare('SELECT * FROM supplier_order_items WHERE id = ? FOR UPDATE');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new RuntimeException('Supplier order item not found.');
    }

    $productId = (int) $item['product_id'];
    $variationId = isset($item['variation_id']) && $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
    $orderId = (int) $item['supplier_order_id'];
    $totalQuantity = (int) $item['total_quantity'];
    $alreadyReceived = supplier_order_item_received_quantity($pdo, $itemId);
    $remaining = $totalQuantity - $alreadyReceived;

    if ($quantity > $remaining) {
        throw new RuntimeException(catalog_format_stock_error($pdo, 'Cannot receive more than the remaining ordered quantity.', $productId, $variationId, 'Remaining ordered quantity', $remaining, $quantity));
    }

    $productTypeStmt = $pdo->prepare('SELECT product_type FROM products WHERE id = ?');
    $productTypeStmt->execute([$productId]);
    $productType = (string) $productTypeStmt->fetchColumn();

    if (in_array($productType, ['preorder', 'early_bird'], true)) {
        supplier_order_receive_preorder_quantity($pdo, $productId, $variationId, $itemId, $quantity);
    } else {
        inventory_get_or_create_row($pdo, $productId, $variationId);

        $pdo->prepare('
            UPDATE mewmii_inventory
            SET incoming_quantity = GREATEST(incoming_quantity - ?, 0),
                available_quantity = available_quantity + ?
            WHERE product_id = ? AND variation_id <=> ?
        ')->execute([$quantity, $quantity, $productId, $variationId]);

        inventory_log_transaction($pdo, $productId, 'supplier_receive', $quantity, 'supplier_order_item', $itemId, $variationId);
    }

    supplier_order_recompute_receiving_status($pdo, $orderId);
}

/**
 * Rolls every line's net received quantity up into the order's status. Extracted verbatim from
 * supplier_order_receive_item() so Reverse Receiving can reuse the identical rule instead of a
 * second copy - both directions of the same decision belong in one place.
 *
 * The two UPWARD branches are byte-identical to the original, including their exact status
 * exclusion lists, so receiving behaves exactly as before (a cancelled order that becomes fully
 * received still flips to 'received', as it always did - preserved deliberately rather than
 * "fixed" here, since that would be a behaviour change smuggled into an extraction).
 *
 * Two DOWNWARD branches are new and are unreachable from receiving (which only ever increases
 * received quantity) - they exist for reversal:
 *   - fully received -> something reversed  => 'partially_received', received_date cleared
 *   - everything reversed                   => 'ordered',            received_date cleared
 *
 * product_cost_history_capture() stays here, still gated on the transition INTO 'received', so a
 * reversal never captures a snapshot and a later re-receive captures a fresh one.
 */
function supplier_order_recompute_receiving_status(PDO $pdo, int $orderId): void
{
    $itemsStmt = $pdo->prepare('SELECT id, total_quantity FROM supplier_order_items WHERE supplier_order_id = ?');
    $itemsStmt->execute([$orderId]);
    $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $fullyReceived = true;
    $anyReceived = false;
    foreach ($allItems as $orderItem) {
        $received = supplier_order_item_received_quantity($pdo, (int) $orderItem['id']);
        if ($received > 0) {
            $anyReceived = true;
        }
        if ($received < (int) $orderItem['total_quantity']) {
            $fullyReceived = false;
        }
    }

    $orderStmt = $pdo->prepare('SELECT status FROM supplier_orders WHERE id = ?');
    $orderStmt->execute([$orderId]);
    $currentStatus = (string) $orderStmt->fetchColumn();

    if ($fullyReceived) {
        if (!in_array($currentStatus, ['received', 'completed'], true)) {
            $pdo->prepare("UPDATE supplier_orders SET status = 'received', received_date = CURDATE() WHERE id = ?")
                ->execute([$orderId]);
            // Phase 8C - freeze this order's Landed Cost breakdown right as it becomes fully
            // received, exactly once (this branch only runs the one time the order transitions
            // into 'received'/'completed', never again on a later call).
            product_cost_history_capture($pdo, $orderId);
        }
    } elseif ($anyReceived) {
        if (!in_array($currentStatus, ['partially_received', 'received', 'completed', 'cancelled'], true)) {
            // Some lines/quantities have arrived but not all - auto-label the order
            // 'partially_received' (see SUPPLIER_ORDER_WORKFLOW's doc comment). Only ever
            // moves it OUT of draft/ordered; never overrides a status already further along.
            $pdo->prepare("UPDATE supplier_orders SET status = 'partially_received' WHERE id = ?")
                ->execute([$orderId]);
        } elseif ($currentStatus === 'received') {
            // Reversal only: the order is no longer fully received.
            $pdo->prepare("UPDATE supplier_orders SET status = 'partially_received', received_date = NULL WHERE id = ?")
                ->execute([$orderId]);
        }
    } elseif (in_array($currentStatus, ['received', 'partially_received'], true)) {
        // Reversal only: nothing is received any more, so the order returns to 'ordered' and
        // supplier_order_has_receiving_history() goes false - which is what re-opens edit,
        // cancel and delete for the correction.
        $pdo->prepare("UPDATE supplier_orders SET status = 'ordered', received_date = NULL WHERE id = ?")
            ->execute([$orderId]);
    }
}

/**
 * Receives every remaining unit on every line of an order in one action ("Mark Arrived") -
 * the default, fast path for a shipment that arrived complete, instead of entering each
 * line's quantity by hand. Reuses supplier_order_receive_item() per line unchanged, so it
 * carries the exact same ready_stock/preorder handling and auto-completion check; lines
 * that are already fully received are simply skipped. "Partial Receive" (entering a
 * smaller quantity for one line) remains available separately via
 * supplier_order_receive_item() directly - this is purely a bulk convenience wrapper.
 */
function supplier_order_receive_all_remaining(PDO $pdo, int $orderId): void
{
    $itemsStmt = $pdo->prepare('SELECT id, total_quantity FROM supplier_order_items WHERE supplier_order_id = ?');
    $itemsStmt->execute([$orderId]);

    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemId = (int) $item['id'];
        $remaining = (int) $item['total_quantity'] - supplier_order_item_received_quantity($pdo, $itemId);
        if ($remaining > 0) {
            supplier_order_receive_item($pdo, $itemId, $remaining);
        }
    }
}

/**
 * How much of one line's received quantity can be reversed right now, and what is holding the
 * rest. Shared by supplier_order_reverse_receipt()'s guard and the UI, so the button and the
 * server can never disagree about what is reversible.
 *
 * "Free" means the units are still sitting where receiving put them and have not been promised
 * to a customer:
 *   - ready_stock          -> mewmii_inventory.available_quantity (reserved/shipped units left it)
 *   - preorder/early_bird  -> mewmii_inventory.arrived_quantity   (allocated units left it)
 *
 * Customer allocations are NEVER unwound automatically - the caller is told what blocks the
 * reversal and releases it deliberately.
 *
 * @return array{received:int, free:int, reversible:int, blocked:int, product_type:string}
 */
function supplier_order_reversible_quantity(PDO $pdo, int $itemId): array
{
    $stmt = $pdo->prepare('
        SELECT soi.product_id, soi.variation_id, p.product_type
        FROM supplier_order_items soi
        INNER JOIN products p ON p.id = soi.product_id
        WHERE soi.id = ?
    ');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($item === false) {
        throw new RuntimeException('Supplier order item not found.');
    }

    $productId = (int) $item['product_id'];
    $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
    $productType = (string) $item['product_type'];
    $received = supplier_order_item_received_quantity($pdo, $itemId);

    $column = in_array($productType, ['preorder', 'early_bird'], true) ? 'arrived_quantity' : 'available_quantity';
    $freeStmt = $pdo->prepare("SELECT {$column} FROM mewmii_inventory WHERE product_id = ? AND variation_id <=> ?");
    $freeStmt->execute([$productId, $variationId]);
    $freeRaw = $freeStmt->fetchColumn();
    $free = $freeRaw !== false ? (int) $freeRaw : 0;

    $reversible = max(0, min($received, $free));

    return [
        'received' => $received,
        'free' => $free,
        'reversible' => $reversible,
        'blocked' => max(0, $received - $reversible),
        'product_type' => $productType,
    ];
}

/**
 * Reverse Receiving - undoes a receipt that should not have happened, WITHOUT deleting or editing
 * any receiving history.
 *
 * A reversal is a negative-quantity 'supplier_receive' ledger row. That specific shape is
 * deliberate: received quantity is DERIVED everywhere by SUM(quantity) over that transaction_type
 * (supplier_order_item_received_quantity(), inventory_unit_arrived_total(),
 * inventory_allocation_queue()), so a negative row is picked up correctly by every consumer with
 * no change to any of them. A distinct transaction_type would be invisible to all three sums.
 *
 * Inventory moves back exactly the way receiving moved it forward, returning stock to INCOMING
 * (not removing it) because the purchase order line still exists and can be received again.
 *
 * Refuses when the units are no longer free - reserved to a customer order, shipped, or allocated
 * into customer storage. Those are never unwound automatically; the operator releases them first.
 *
 * Caller owns the surrounding transaction. Throws RuntimeException with an admin-facing message.
 */
function supplier_order_reverse_receipt(PDO $pdo, int $itemId, int $quantity, string $reason): void
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Enter a reason for reversing this receipt.');
    }
    if (strlen($reason) > 100) {
        throw new RuntimeException('Reason must be 100 characters or fewer.');
    }
    if ($quantity < 1) {
        throw new RuntimeException('Reversal quantity must be at least 1.');
    }

    // FOR UPDATE first: everything below - the net-received ceiling and the free-stock guard -
    // must be evaluated and acted on atomically, so two concurrent reversals cannot each pass
    // their own check and together reverse more than was ever received.
    $itemStmt = $pdo->prepare('SELECT * FROM supplier_order_items WHERE id = ? FOR UPDATE');
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if ($item === false) {
        throw new RuntimeException('Supplier order item not found.');
    }

    $orderId = (int) $item['supplier_order_id'];
    $productId = (int) $item['product_id'];
    $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;

    $orderStmt = $pdo->prepare('SELECT status, purchase_number, is_historical FROM supplier_orders WHERE id = ? FOR UPDATE');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if ($order === false) {
        throw new RuntimeException('Supplier order not found.');
    }
    if (!empty($order['is_historical'])) {
        throw new RuntimeException('This is a historical (imported) supplier order - its receiving cannot be reversed.');
    }
    if (in_array((string) $order['status'], ['completed', 'cancelled'], true)) {
        throw new RuntimeException('A completed or cancelled supplier order cannot have its receiving reversed.');
    }

    $state = supplier_order_reversible_quantity($pdo, $itemId);

    // Explicit net-received ceiling, re-evaluated inside the lock.
    if ($quantity > $state['received']) {
        throw new RuntimeException('Cannot reverse ' . $quantity . ' unit(s) - only ' . $state['received'] . ' are currently received on this line.');
    }
    if ($quantity > $state['reversible']) {
        $where = in_array($state['product_type'], ['preorder', 'early_bird'], true)
            ? 'allocated to customer storage'
            : 'reserved or already shipped to customers';
        throw new RuntimeException(
            'Only ' . $state['reversible'] . ' of ' . $state['received'] . ' received unit(s) can be reversed - the other '
            . $state['blocked'] . ' are ' . $where . '. Release those first, then reverse.'
        );
    }

    // Mirror image of receiving: stock returns to incoming, because the line can be received again.
    if (in_array($state['product_type'], ['preorder', 'early_bird'], true)) {
        $pdo->prepare('
            UPDATE mewmii_inventory
            SET arrived_quantity = GREATEST(arrived_quantity - ?, 0),
                incoming_quantity = incoming_quantity + ?
            WHERE product_id = ? AND variation_id <=> ?
        ')->execute([$quantity, $quantity, $productId, $variationId]);
    } else {
        $pdo->prepare('
            UPDATE mewmii_inventory
            SET available_quantity = GREATEST(available_quantity - ?, 0),
                incoming_quantity = incoming_quantity + ?
            WHERE product_id = ? AND variation_id <=> ?
        ')->execute([$quantity, $quantity, $productId, $variationId]);
    }

    // The reversing entry. Append-only: the original receipt row is never updated or deleted.
    inventory_log_transaction(
        $pdo,
        $productId,
        'supplier_receive',
        -$quantity,
        'supplier_order_item',
        $itemId,
        $variationId,
        'Reverse receipt',
        $reason
    );

    supplier_order_recompute_receiving_status($pdo, $orderId);

    supplier_order_log_event($pdo, $orderId, 'Reversed receipt of ' . $quantity . ' unit(s) on line #' . $itemId . ' - ' . $reason);
    activity_log($pdo, 'supplier_orders', 'reverse_receipt', $orderId, 'Reversed ' . $quantity . ' received unit(s) on ' . $order['purchase_number'] . ' - ' . $reason);
}

/**
 * Reverses one line item's still-outstanding incoming contribution (the flip side of
 * supplier_order_mark_incoming()) - used only when editing or deleting an order that has
 * never actually received anything (see supplier_order_has_receiving_history()), so this
 * only ever touches incoming_quantity, never available/reserved/arrived. Logged as its own
 * transaction type so it's never confused with a real receiving event in the ledger.
 */
function supplier_order_reverse_incoming(PDO $pdo, int $productId, ?int $variationId, int $itemId, int $quantity): void
{
    if ($quantity < 1) {
        return;
    }

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET incoming_quantity = GREATEST(incoming_quantity - ?, 0)
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$quantity, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'supplier_order_cancelled', -$quantity, 'supplier_order_item', $itemId, $variationId);
}

/**
 * Applies a signed delta to one line item's incoming_quantity - the general-purpose
 * version of supplier_order_mark_incoming() (delta = full quantity, on creation) and
 * supplier_order_reverse_incoming() (delta = negative full quantity, on delete/removal).
 * Used when an existing order's line quantity is edited (see supplier_order_apply_edit()):
 * increasing the ordered quantity adds to incoming, decreasing it removes the difference -
 * never touches arrived_quantity/available_quantity, so already-received stock is
 * completely unaffected either way.
 */
function supplier_order_adjust_incoming(PDO $pdo, int $productId, ?int $variationId, int $itemId, int $delta): void
{
    if ($delta === 0) {
        return;
    }

    inventory_get_or_create_row($pdo, $productId, $variationId);

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET incoming_quantity = GREATEST(incoming_quantity + ?, 0)
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$delta, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'supplier_order_adjusted', $delta, 'supplier_order_item', $itemId, $variationId);
}

/**
 * Every distinct, currently-open customer order blocked (at least partially) on one of this
 * supplier order's product/variation lines - "priority visibility" (UI/UX Phase 5E.2): lets
 * admin see which open supplier orders are actually holding up a waiting customer, not just
 * which are overdue. Reuses the exact same outstanding-demand definitions already established
 * for ready_stock (inventory_unit_unreserved_demand()'s own WHERE clause + inventory_net_
 * reserved()) and preorder/early_bird (inventory_unit_outstanding_demand()'s own WHERE clause
 * + supplier_order_item_customer_storage_allocated()) in includes/inventory.php and
 * includes/customer_storage.php - copied verbatim rather than re-derived, just resolved down
 * to which distinct order_id each total actually comes from, which neither existing function
 * returns on its own. Purely additive/read-only - no receiving, status, or inventory-mutation
 * logic is touched, and neither reused function is modified.
 */
/**
 * Batch/list-page version of supplier_order_blocked_customer_orders() (Production
 * Hardening Phase 2): modules/supplier-orders/index.php used to call the singular function
 * once per row just to keep count() of its result - which itself ran a query per supplier
 * order line unit, then a query per matching customer order item, on every page load. This
 * computes the same "is a paid/outstanding customer order waiting on this supplier order"
 * answer for every supplier order id given, in two set-based queries (mirroring the
 * singular function's own two branches - ready_stock and preorder/early_bird - with
 * inventory_net_reserved()'s and supplier_order_item_customer_storage_allocated()'s exact
 * clamped-per-line arithmetic reproduced as correlated subqueries instead of a PHP loop
 * issuing one query per row). Returns the exact same per-order shape
 * ([{order_id, order_number}, ...]) as the singular function, keyed by supplier_order_id,
 * so modules/supplier-orders/index.php can read count($batch[$id] ?? []) per row.
 * modules/supplier-orders/view.php keeps calling the singular function unchanged - it's a
 * single lookup for one order, not a loop, so it never had an N+1 problem to begin with.
 */
function supplier_order_blocked_customer_orders_batch(PDO $pdo, array $supplierOrderIds): array
{
    $supplierOrderIds = array_values(array_unique(array_map('intval', $supplierOrderIds)));
    if ($supplierOrderIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($supplierOrderIds), '?'));

    $sql = "
        SELECT soi.supplier_order_id, o.id AS order_id, o.order_number
        FROM supplier_order_items soi
        INNER JOIN products p ON p.id = soi.product_id AND p.product_type = 'ready_stock'
        INNER JOIN mewmii_order_items oi ON oi.product_id = soi.product_id AND oi.variation_id <=> soi.variation_id
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE soi.supplier_order_id IN ({$placeholders})
          AND o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.is_historical = 0
          AND (oi.quantity - (
                SELECT GREATEST(COALESCE(SUM(CASE WHEN it.transaction_type = 'order_reserve' THEN it.quantity ELSE -it.quantity END), 0), 0)
                FROM inventory_transactions it
                WHERE it.reference_type = 'order' AND it.reference_id = oi.order_id
                  AND it.product_id = soi.product_id AND it.variation_id <=> soi.variation_id
                  AND it.transaction_type IN ('order_reserve', 'order_release', 'order_ship')
              )) > 0

        UNION ALL

        SELECT soi.supplier_order_id, o.id AS order_id, o.order_number
        FROM supplier_order_items soi
        INNER JOIN products p ON p.id = soi.product_id AND p.product_type IN ('preorder', 'early_bird')
        INNER JOIN mewmii_order_items oi ON oi.product_id = soi.product_id AND oi.variation_id <=> soi.variation_id
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE soi.supplier_order_id IN ({$placeholders})
          AND o.order_status <> 'cancelled' AND o.is_historical = 0
          AND (oi.quantity - (
                SELECT COALESCE(SUM(cs.quantity), 0) FROM customer_storage cs WHERE cs.order_item_id = oi.id
              )) > 0
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($supplierOrderIds, $supplierOrderIds));

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $supplierOrderId = (int) $row['supplier_order_id'];
        $orderId = (int) $row['order_id'];
        // Keyed by order_id per supplier order, same dedup as the singular function - a
        // supplier order can have both a ready_stock and a preorder line blocking the same
        // customer order, and that order must still only be counted/listed once.
        $result[$supplierOrderId][$orderId] = ['order_id' => $orderId, 'order_number' => $row['order_number']];
    }

    return array_map('array_values', $result);
}

function supplier_order_blocked_customer_orders(PDO $pdo, int $supplierOrderId): array
{
    $unitsStmt = $pdo->prepare('
        SELECT DISTINCT soi.product_id, soi.variation_id, p.product_type
        FROM supplier_order_items soi
        INNER JOIN products p ON p.id = soi.product_id
        WHERE soi.supplier_order_id = ?
    ');
    $unitsStmt->execute([$supplierOrderId]);
    $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);

    $blockedOrders = [];

    foreach ($units as $unit) {
        $productId = (int) $unit['product_id'];
        $variationId = $unit['variation_id'] !== null ? (int) $unit['variation_id'] : null;

        if ($unit['product_type'] === 'ready_stock') {
            // Same WHERE clause as inventory_unit_unreserved_demand().
            $stmt = $pdo->prepare("
                SELECT o.id AS order_id, o.order_number, oi.quantity
                FROM mewmii_order_items oi
                INNER JOIN mewmii_orders o ON o.id = oi.order_id
                WHERE oi.product_id = ? AND oi.variation_id <=> ?
                  AND o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.is_historical = 0
            ");
            $stmt->execute([$productId, $variationId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $outstanding = (int) $item['quantity'] - inventory_net_reserved($pdo, (int) $item['order_id'], $productId, $variationId);
                if ($outstanding > 0) {
                    $blockedOrders[(int) $item['order_id']] = ['order_id' => (int) $item['order_id'], 'order_number' => $item['order_number']];
                }
            }
        } elseif (in_array($unit['product_type'], ['preorder', 'early_bird'], true)) {
            // Same WHERE clause as inventory_unit_outstanding_demand().
            $stmt = $pdo->prepare("
                SELECT oi.id, o.id AS order_id, o.order_number, oi.quantity
                FROM mewmii_order_items oi
                INNER JOIN mewmii_orders o ON o.id = oi.order_id
                WHERE oi.product_id = ? AND oi.variation_id <=> ? AND o.order_status <> 'cancelled' AND o.is_historical = 0
            ");
            $stmt->execute([$productId, $variationId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $outstanding = (int) $item['quantity'] - supplier_order_item_customer_storage_allocated($pdo, (int) $item['id']);
                if ($outstanding > 0) {
                    $blockedOrders[(int) $item['order_id']] = ['order_id' => (int) $item['order_id'], 'order_number' => $item['order_number']];
                }
            }
        }
    }

    return array_values($blockedOrders);
}

function supplier_order_log_event(PDO $pdo, int $orderId, string $description): void
{
    $pdo->prepare('
        INSERT INTO supplier_order_events (supplier_order_id, event_type, description, created_by)
        VALUES (?, ?, ?, ?)
    ')->execute([$orderId, 'edit', $description, $_SESSION['user_id'] ?? null]);
}

/**
 * Phase 6B (Supplier Order currency) - the one formula "foreign amount x exchange rate = MYR
 * amount" is computed with, so modules/supplier-orders/create.php and
 * supplier_order_apply_edit() below can never drift into two different roundings/fallbacks
 * of the same conversion. A null/non-positive rate (a plain MYR order, or a rate not yet
 * entered) is treated as 1 - same "NULL means no conversion needed" convention already
 * established by products.exchange_rate.
 */
function supplier_order_convert_to_myr(float $foreignAmount, ?float $exchangeRate): float
{
    $rate = ($exchangeRate !== null && $exchangeRate > 0) ? $exchangeRate : 1.0;

    return round($foreignAmount * $rate, 2);
}

/**
 * Shared currency + line-item validation for modules/supplier-orders/create.php and edit.php -
 * both pages posted an identical copy of this block. Extraction only; no behavior change -
 * $error is taken as an input (may already be set by an earlier CSRF failure) and only ever
 * added to, never cleared, exactly matching what each page did inline before. Requires
 * SUPPLIER_ORDER_CURRENCY_OPTIONS to already be defined by the caller (both pages declare it
 * themselves - it's page-level form configuration, not validation logic, so it stays put).
 *
 * Returns ['error', 'currency', 'exchange_rate', 'valid_items', 'existing_items'] - the exact
 * same five outputs both pages already assigned individually.
 */
function supplier_order_validate_form(PDO $pdo, array $form, array $postedUnitKeys, array $postedQuantities, array $postedPrices, string $error): array
{
    $currency = $form['currency'] === 'OTHER' ? strtoupper($form['currency_other']) : strtoupper($form['currency']);
    $exchangeRate = null;

    if ($error === '') {
        if ($form['currency'] === 'OTHER' && ($currency === '' || strlen($currency) > 10)) {
            $error = 'Enter a valid currency code (up to 10 characters).';
        } elseif ($form['currency'] !== 'OTHER' && !in_array($form['currency'], SUPPLIER_ORDER_CURRENCY_OPTIONS, true)) {
            $error = 'Invalid currency.';
        }
    }
    if ($error === '' && $currency !== SYSTEM_SELLING_CURRENCY) {
        if ($form['exchange_rate'] === '' || !is_numeric($form['exchange_rate']) || (float) $form['exchange_rate'] <= 0) {
            $error = 'Enter a valid exchange rate (1 ' . $currency . ' = ? ' . SYSTEM_SELLING_CURRENCY . ').';
        } else {
            $exchangeRate = (float) $form['exchange_rate'];
        }
    }

    $sellableUnits = catalog_sellable_units($pdo);
    $unitsByKey = array_column($sellableUnits, null, 'key');

    $validItems = [];
    $existingItems = [];

    for ($i = 0; $i < count($postedUnitKeys); $i++) {
        $rowUnitKey = trim((string) ($postedUnitKeys[$i] ?? ''));
        $rowQuantity = trim((string) ($postedQuantities[$i] ?? ''));
        // Unit cost as entered, in the order's own currency - converted to MYR below via
        // supplier_order_convert_to_myr(), never re-derived a second way.
        $rowPrice = trim((string) ($postedPrices[$i] ?? ''));

        if ($rowUnitKey === '') {
            continue;
        }

        $existingItems[] = [
            'unit_key' => $rowUnitKey,
            'label' => isset($unitsByKey[$rowUnitKey]) ? $unitsByKey[$rowUnitKey]['label'] : $rowUnitKey,
            'sku' => isset($unitsByKey[$rowUnitKey]) ? $unitsByKey[$rowUnitKey]['sku'] : '',
            'moq' => isset($unitsByKey[$rowUnitKey]) ? $unitsByKey[$rowUnitKey]['moq'] : null,
            'quantity' => $rowQuantity,
            'supplier_price' => $rowPrice,
        ];

        if ($error === '') {
            if (!ctype_digit($rowQuantity) || (int) $rowQuantity < 1) {
                $error = 'Enter a whole number quantity of at least 1 for every line.';
            } elseif ($rowPrice !== '' && (!is_numeric($rowPrice) || (float) $rowPrice < 0)) {
                $error = 'Unit cost must be a valid non-negative number.';
            } elseif (!isset($unitsByKey[$rowUnitKey])) {
                $error = 'A selected product no longer exists.';
            } else {
                $unit = $unitsByKey[$rowUnitKey];
                $unitCostForeign = $rowPrice !== '' ? round((float) $rowPrice, 2) : 0.00;
                $validItems[] = [
                    'product_id' => $unit['product_id'],
                    'variation_id' => $unit['variation_id'],
                    'quantity' => (int) $rowQuantity,
                    'unit_cost_foreign' => $unitCostForeign,
                    'supplier_price' => supplier_order_convert_to_myr($unitCostForeign, $exchangeRate),
                ];
            }
        }
    }

    return [
        'error' => $error,
        'currency' => $currency,
        'exchange_rate' => $exchangeRate,
        'valid_items' => $validItems,
        'existing_items' => $existingItems,
    ];
}

/**
 * Full add/edit/remove reconciliation for an existing supplier order - the only way its
 * line items change after creation (see modules/supplier-orders/edit.php). $newLines is
 * the same per-line shape modules/supplier-orders/create.php builds: a list of
 * ['product_id', 'variation_id', 'quantity', 'supplier_price'], keyed internally by
 * "productId:variationId" (the same sellable-unit key used everywhere else in this app).
 *
 * Receiving history is never rewritten: a line that already has ANY received quantity
 * cannot be removed, and its quantity cannot be reduced below what's already been
 * received - both throw with an admin-facing message naming the product. Only the
 * still-outstanding incoming_quantity moves, via supplier_order_adjust_incoming() (a
 * signed delta = new_quantity - old_quantity, which is correct regardless of how much has
 * already been received: incoming = ordered - received, so the received term cancels out
 * of the delta). Every add/remove/quantity-change/cost-change is logged individually to
 * supplier_order_events. Caller is responsible for the surrounding transaction and for
 * blocking this entirely once the order is 'completed' (see modules/supplier-orders/edit.php).
 */
function supplier_order_apply_edit(PDO $pdo, int $orderId, int $supplierId, string $notes, array $newLines, float $shippingFee = 0.00, ?string $paymentStatus = null, ?string $currency = null, ?float $exchangeRate = null, ?string $orderDate = null): void
{
    // supplier_id is selected for the P7a guard below - the previous value is needed to detect a
    // reassignment attempt on an order that already has received stock.
    $oldRowStmt = $pdo->prepare('SELECT supplier_id, shipping_fee, payment_status, purchase_number, is_historical, currency, exchange_rate, order_date FROM supplier_orders WHERE id = ?');
    $oldRowStmt->execute([$orderId]);
    $oldRow = $oldRowStmt->fetch(PDO::FETCH_ASSOC);
    // A historical (imported) supplier order must never go through
    // supplier_order_mark_incoming()/supplier_order_adjust_incoming() below - see
    // includes/supplier_order_import.php's doc comment.
    if (!empty($oldRow['is_historical'])) {
        throw new RuntimeException('This is a historical (imported) supplier order and cannot be edited through this workflow.');
    }
    $oldShippingFee = (float) $oldRow['shipping_fee'];
    $oldPaymentStatus = (string) $oldRow['payment_status'];
    if ($paymentStatus === null || !in_array($paymentStatus, SUPPLIER_ORDER_PAYMENT_STATUSES, true)) {
        $paymentStatus = $oldPaymentStatus;
    }

    // Phase 6B (Supplier Order currency) - $currency/$exchangeRate null (caller didn't pass
    // them) falls back to whatever the order already had, same "unchanged unless explicitly
    // posted" convention as $paymentStatus above.
    $oldCurrency = (string) ($oldRow['currency'] ?? 'MYR');
    $oldExchangeRate = $oldRow['exchange_rate'] !== null ? (float) $oldRow['exchange_rate'] : null;
    if ($currency === null || trim($currency) === '') {
        $currency = $oldCurrency;
    }
    if ($currency === 'MYR') {
        $exchangeRate = null;
    } elseif ($exchangeRate === null) {
        $exchangeRate = $oldExchangeRate;
    }

    // P7a - once ANY stock has been received against this order, three header fields become
    // read-only. This is a data-integrity guard, not a workflow restriction:
    //
    //   currency / exchange_rate - this function recomputes supplier_price and unit_cost_myr for
    //     EVERY line, including already-received ones. Since SO-A1, unit_cost_myr is the
    //     authoritative landed-cost input, so changing the rate here would silently rewrite the
    //     cost of stock already on the shelf, and the product_cost_history snapshot frozen at
    //     receiving would no longer reconcile with the live figure.
    //
    //   supplier_id - product_cost_history.supplier_id was captured from this order at receiving
    //     time and is never updated, so reassigning the supplier would leave the order claiming a
    //     different supplier than its own cost history. It would also misattribute delivery
    //     performance in supplier_lead_time_stats_batch().
    //
    // Everything else stays editable exactly as before: notes, payment status, shipping fee,
    // adding lines, increasing quantities, and editing/removing lines with nothing received.
    $hasReceiving = supplier_order_has_receiving_history($pdo, $orderId);

    if ($hasReceiving) {
        $oldSupplierId = (int) $oldRow['supplier_id'];
        if ($supplierId !== $oldSupplierId) {
            throw new RuntimeException('This order already has received stock, so its supplier can no longer be changed. Cancel the remaining quantity and raise a new order instead.');
        }

        if ($currency !== $oldCurrency) {
            throw new RuntimeException('This order already has received stock, so its currency can no longer be changed - doing so would rewrite the recorded cost of stock already received.');
        }

        // order_date is locked for the same reason the cost fields are: includes/product_cost.php
        // picks BOTH the purchase-cost line (SO-A1 Lookup A) and the shipping/additional-cost
        // reference line (Lookup B) with ORDER BY so.order_date DESC. Re-dating a received order
        // can therefore silently change which line supplies a product's landed cost. It also
        // feeds supplier_lead_time_stats_batch()'s DATEDIFF(received_date, order_date).
        if ($orderDate !== null && $orderDate !== (string) ($oldRow['order_date'] ?? '')) {
            throw new RuntimeException('This order already has received stock, so its order date can no longer be changed - it determines which purchase line supplies landed cost.');
        }

        $rateChanged = ($exchangeRate === null) !== ($oldExchangeRate === null)
            || ($exchangeRate !== null && $oldExchangeRate !== null && abs($exchangeRate - $oldExchangeRate) > 0.000001);
        if ($rateChanged) {
            throw new RuntimeException('This order already has received stock, so its exchange rate can no longer be changed - doing so would rewrite the recorded cost of stock already received.');
        }
    }

    $existingStmt = $pdo->prepare('SELECT id, product_id, variation_id, total_quantity, supplier_price FROM supplier_order_items WHERE supplier_order_id = ?');
    $existingStmt->execute([$orderId]);
    $existingByKey = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0);
        $existingByKey[$key] = $row;
    }

    $newByKey = [];
    foreach ($newLines as $line) {
        $key = $line['product_id'] . ':' . (int) ($line['variation_id'] ?? 0);
        $newByKey[$key] = $line;
    }

    // Removed lines: only safe if nothing has been received against them yet.
    foreach ($existingByKey as $key => $existing) {
        if (isset($newByKey[$key])) {
            continue;
        }

        $itemId = (int) $existing['id'];
        $received = supplier_order_item_received_quantity($pdo, $itemId);
        $unit = catalog_describe_unit($pdo, (int) $existing['product_id'], $existing['variation_id'] !== null ? (int) $existing['variation_id'] : null);
        $label = $unit['product_name'] . (!empty($unit['variation_label']) ? ' (' . $unit['variation_label'] . ')' : '');

        if ($received > 0) {
            throw new RuntimeException($label . ' already has received quantity and cannot be removed from this order.');
        }

        supplier_order_reverse_incoming($pdo, (int) $existing['product_id'], $existing['variation_id'] !== null ? (int) $existing['variation_id'] : null, $itemId, (int) $existing['total_quantity']);
        $pdo->prepare('DELETE FROM supplier_order_items WHERE id = ?')->execute([$itemId]);
        supplier_order_log_event($pdo, $orderId, 'Removed ' . $label . ' x' . (int) $existing['total_quantity']);
    }

    // New and updated lines.
    foreach ($newByKey as $key => $line) {
        $productId = (int) $line['product_id'];
        $variationId = $line['variation_id'] !== null ? (int) $line['variation_id'] : null;
        $quantity = (int) $line['quantity'];
        // Phase 6B (Supplier Order currency) - $line['supplier_price'] is always the already-
        // converted MYR unit cost (the caller, modules/supplier-orders/edit.php, does the
        // foreign x rate = MYR conversion via supplier_order_convert_to_myr() before building
        // $newLines) - unchanged in meaning from before this feature. unit_cost_foreign falls
        // back to the same MYR value when the caller doesn't supply it (a plain MYR line).
        $price = round((float) $line['supplier_price'], 2);
        $unitCostForeign = isset($line['unit_cost_foreign']) ? round((float) $line['unit_cost_foreign'], 2) : $price;
        $subtotal = round($quantity * $price, 2);

        $unit = catalog_describe_unit($pdo, $productId, $variationId);
        $label = $unit['product_name'] . (!empty($unit['variation_label']) ? ' (' . $unit['variation_label'] . ')' : '');

        if (!isset($existingByKey[$key])) {
            // New line.
            $insertStmt = $pdo->prepare('
                INSERT INTO supplier_order_items (supplier_order_id, product_id, variation_id, total_quantity, supplier_price, unit_cost_foreign, unit_cost_myr, subtotal)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insertStmt->execute([$orderId, $productId, $variationId, $quantity, $price, $unitCostForeign, $price, $subtotal]);
            $itemId = (int) $pdo->lastInsertId();

            supplier_order_mark_incoming($pdo, $productId, $itemId, $quantity, $variationId);
            supplier_order_log_event($pdo, $orderId, 'Added ' . $label . ' x' . $quantity);

            continue;
        }

        // Existing line - reconcile quantity/cost.
        $existing = $existingByKey[$key];
        $itemId = (int) $existing['id'];
        $oldQuantity = (int) $existing['total_quantity'];
        $oldPrice = (float) $existing['supplier_price'];
        $received = supplier_order_item_received_quantity($pdo, $itemId);

        if ($quantity < $received) {
            throw new RuntimeException($label . ' has already received ' . $received . ' unit(s) and cannot be reduced below that.');
        }

        $delta = $quantity - $oldQuantity;
        if ($delta !== 0) {
            supplier_order_adjust_incoming($pdo, $productId, $variationId, $itemId, $delta);
            supplier_order_log_event($pdo, $orderId, 'Changed ' . $label . ' quantity ' . $oldQuantity . ' -> ' . $quantity);
        }

        if (abs($oldPrice - $price) > 0.001) {
            // P7a - a line that has already received stock has its cost locked. supplier_price /
            // unit_cost_myr on this row is the authoritative landed-cost input since SO-A1, and
            // product_cost_history froze a snapshot from it at receiving time; changing it now
            // would retroactively restate what that received stock cost. Lines with nothing
            // received yet remain fully editable.
            if ($received > 0) {
                throw new RuntimeException($label . ' has already received ' . $received . ' unit(s), so its unit cost can no longer be changed.');
            }

            supplier_order_log_event($pdo, $orderId, 'Changed ' . $label . ' unit cost RM' . number_format($oldPrice, 2) . ' -> RM' . number_format($price, 2));
        }

        $pdo->prepare('UPDATE supplier_order_items SET total_quantity = ?, supplier_price = ?, unit_cost_foreign = ?, unit_cost_myr = ?, subtotal = ? WHERE id = ?')
            ->execute([$quantity, $price, $unitCostForeign, $price, $subtotal, $itemId]);
    }

    if (abs($oldShippingFee - $shippingFee) > 0.001) {
        supplier_order_log_event($pdo, $orderId, 'Shipping fee changed RM' . number_format($oldShippingFee, 2) . ' -> RM' . number_format($shippingFee, 2));
    }
    if ($paymentStatus !== $oldPaymentStatus) {
        supplier_order_log_event($pdo, $orderId, 'Payment status changed ' . supplier_order_payment_status_label($oldPaymentStatus) . ' -> ' . supplier_order_payment_status_label($paymentStatus));
    }
    if ($currency !== $oldCurrency || ($exchangeRate !== $oldExchangeRate && ($exchangeRate === null || $oldExchangeRate === null || abs($exchangeRate - $oldExchangeRate) > 0.000001))) {
        supplier_order_log_event($pdo, $orderId, 'Currency changed ' . $oldCurrency . ' (rate ' . ($oldExchangeRate ?? 1) . ') -> ' . $currency . ' (rate ' . ($exchangeRate ?? 1) . ')');
    }

    // Totals + supplier/notes always refreshed, even if nothing else changed this pass.
    // estimated_cost stays the pure product subtotal (SUM of line subtotals) - shipping is
    // never split into products or blended into it; the Total Purchase Amount shown to
    // admins (view.php/index.php/create.php) is always estimated_cost + shipping_fee,
    // computed at display time rather than stored as a third column.
    $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(subtotal), 0) FROM supplier_order_items WHERE supplier_order_id = ?');
    $totalStmt->execute([$orderId]);
    $estimatedCost = round((float) $totalStmt->fetchColumn(), 2);

    // Phase 6B (Supplier Order currency) - same SUM-of-lines shape as estimated_cost above,
    // just in the order's own currency instead of MYR.
    $foreignTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(COALESCE(unit_cost_foreign, supplier_price) * total_quantity), 0) FROM supplier_order_items WHERE supplier_order_id = ?');
    $foreignTotalStmt->execute([$orderId]);
    $foreignTotal = round((float) $foreignTotalStmt->fetchColumn(), 2);

    // order_date null = caller didn't post it, so keep whatever the order already had - the same
    // "unchanged unless explicitly posted" convention $paymentStatus/$currency use above.
    $oldOrderDate = (string) ($oldRow['order_date'] ?? '');
    if ($orderDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
        $orderDate = $oldOrderDate !== '' ? $oldOrderDate : null;
    }
    if ($orderDate !== null && $orderDate !== $oldOrderDate) {
        supplier_order_log_event($pdo, $orderId, 'Order date changed ' . ($oldOrderDate !== '' ? $oldOrderDate : 'not set') . ' -> ' . $orderDate);
    }

    $pdo->prepare('UPDATE supplier_orders SET supplier_id = ?, estimated_cost = ?, shipping_fee = ?, payment_status = ?, notes = ?, currency = ?, exchange_rate = ?, foreign_total = ?, order_date = ? WHERE id = ?')
        ->execute([$supplierId, $estimatedCost, round($shippingFee, 2), $paymentStatus, $notes !== '' ? $notes : null, $currency, $exchangeRate, $foreignTotal, $orderDate, $orderId]);

    activity_log($pdo, 'supplier_orders', 'edit', $orderId, 'Edited supplier order ' . $oldRow['purchase_number']);
}

/**
 * Deletes a supplier order entirely, but only if nothing has ever actually been received
 * against it (see supplier_order_has_receiving_history()) - otherwise throws with the
 * admin-facing message so the caller can show it instead of a raw SQL/FK error. Every
 * line's still-outstanding incoming contribution is reversed first (see
 * supplier_order_reverse_incoming()) so no phantom "incoming" stock is left behind;
 * deleting the order row then cascades to its line items.
 */
function supplier_order_delete_if_unreceived(PDO $pdo, int $orderId): void
{
    if (supplier_order_has_receiving_history($pdo, $orderId)) {
        throw new RuntimeException('This supplier order has receiving history and cannot be deleted.');
    }

    $itemsStmt = $pdo->prepare('SELECT id, product_id, variation_id, total_quantity FROM supplier_order_items WHERE supplier_order_id = ?');
    $itemsStmt->execute([$orderId]);

    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        supplier_order_reverse_incoming(
            $pdo,
            (int) $item['product_id'],
            $item['variation_id'] !== null ? (int) $item['variation_id'] : null,
            (int) $item['id'],
            (int) $item['total_quantity']
        );
    }

    $pdo->prepare('DELETE FROM supplier_orders WHERE id = ?')->execute([$orderId]);
}

/**
 * Soft-cancels a supplier order: same eligibility guard as
 * supplier_order_delete_if_unreceived() (nothing may have ever been received against it -
 * once any receiving history exists, Cancel is blocked exactly like Delete is, since the
 * arrived portion is real physical stock and cancelling can't safely un-happen that), and
 * reverses every line's outstanding incoming contribution via the same, unmodified
 * supplier_order_reverse_incoming(). Unlike delete, the row itself is kept (status =
 * 'cancelled') so it still appears in history/reporting instead of disappearing.
 */
function supplier_order_cancel(PDO $pdo, int $orderId): void
{
    if (supplier_order_has_receiving_history($pdo, $orderId)) {
        throw new RuntimeException('This supplier order has receiving history and cannot be cancelled.');
    }

    $itemsStmt = $pdo->prepare('SELECT id, product_id, variation_id, total_quantity FROM supplier_order_items WHERE supplier_order_id = ?');
    $itemsStmt->execute([$orderId]);

    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        supplier_order_reverse_incoming(
            $pdo,
            (int) $item['product_id'],
            $item['variation_id'] !== null ? (int) $item['variation_id'] : null,
            (int) $item['id'],
            (int) $item['total_quantity']
        );
    }

    $pdo->prepare("UPDATE supplier_orders SET status = 'cancelled' WHERE id = ?")->execute([$orderId]);
    activity_log($pdo, 'supplier_orders', 'cancel', $orderId, 'Cancelled supplier order #' . $orderId);
}

/**
 * Every supplier order still eligible for delete (see supplier_order_delete_if_unreceived()
 * / supplier_order_has_receiving_history()) - the "safe to delete" list for the Data
 * Cleanup tool. Same NOT EXISTS approach as catalog_list_deletable_products() for the same
 * reason; the delete action itself still re-validates via supplier_order_has_receiving_history().
 */
function supplier_order_list_deletable(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT so.id, so.purchase_number, so.status, so.created_at
        FROM supplier_orders so
        WHERE NOT EXISTS (
            SELECT 1 FROM inventory_transactions it
            INNER JOIN supplier_order_items soi ON soi.id = it.reference_id AND it.reference_type = 'supplier_order_item'
            WHERE soi.supplier_order_id = so.id AND it.transaction_type = 'supplier_receive'
        )
        ORDER BY so.created_at DESC
        LIMIT 500
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every product for the "+ Add Product" picker (11.2), grouped exactly like
 * modules/inventory/index.php groups its listing: a simple product is one selectable
 * unit, a variable product is a container whose active (non-archived) variations are
 * each their own selectable unit - a variable product's parent is never itself orderable
 * from a supplier, same rule as catalog_sellable_units(). Includes supplier/category so
 * the picker's filters don't need a separate query. Capped at 500 products, consistent
 * with this app's other admin-scale listings.
 */
function supplier_order_picker_products(PDO $pdo): array
{
    $productsStmt = $pdo->query("
        SELECT p.id, p.sku, p.name, p.supplier_sku, p.catalog_type, p.product_type, p.product_cost, p.moq,
               p.supplier_id, s.name AS supplier_name,
               (SELECT cat.id FROM product_category_relationships pcr
                   INNER JOIN categories cat ON cat.id = pcr.category_id
                   WHERE pcr.product_id = p.id ORDER BY pcr.category_id ASC LIMIT 1) AS category_id,
               (SELECT cat.name FROM product_category_relationships pcr
                   INNER JOIN categories cat ON cat.id = pcr.category_id
                   WHERE pcr.product_id = p.id ORDER BY pcr.category_id ASC LIMIT 1) AS category_name
        FROM products p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.status <> 'archived'
        ORDER BY p.name ASC
        LIMIT 500
    ");
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

    $variableIds = [];
    foreach ($products as $product) {
        if ($product['catalog_type'] === 'variable') {
            $variableIds[] = (int) $product['id'];
        }
    }

    $variationsByProduct = [];
    if ($variableIds !== []) {
        $placeholders = implode(',', array_fill(0, count($variableIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, product_id, sku, supplier_sku, cost_price
            FROM product_variations
            WHERE product_id IN ({$placeholders}) AND status <> 'archived'
            ORDER BY id ASC
        ");
        $stmt->execute($variableIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variationsByProduct[(int) $row['product_id']][] = $row;
        }
    }

    // BUGFIX - per-supplier prices for the picker.
    //
    // cost_price below is products.product_cost / product_variations.cost_price, which IS the
    // preferred supplier's price by construction (SO-C's migration seeded supplier_products from
    // exactly those columns for products.supplier_id). The picker therefore offered Supplier A's
    // price even when the purchase order was being raised against Supplier B.
    //
    // Every supplier's saved price is embedded here, keyed by supplier id, and the form picks the
    // one matching the PO's selected supplier when a line is added (assets/js/supplier-order-form.js).
    // It has to be embedded for ALL suppliers rather than looked up for one, because the picker
    // JSON is built before a supplier is chosen and the operator can change that choice.
    //
    // Pure lookup over the existing supplier_products table - no new pricing rule, and nothing
    // here writes anything. includes/product_cost.php is untouched: the costing engine still reads
    // only actual PO lines and products.product_cost, so a quote never enters landed cost except
    // via a purchase order the operator actually saved.
    $supplierCostsByUnit = [];
    if ($products !== []) {
        $pickerProductIds = array_map(static fn (array $row): int => (int) $row['id'], $products);
        $placeholders = implode(',', array_fill(0, count($pickerProductIds), '?'));
        $supplierPriceStmt = $pdo->prepare("
            SELECT supplier_id, product_id, variation_id, unit_cost, supplier_sku, moq
            FROM supplier_products
            WHERE is_active = 1 AND product_id IN ({$placeholders})
        ");
        $supplierPriceStmt->execute($pickerProductIds);
        foreach ($supplierPriceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $unitKey = (int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0);
            $supplierCostsByUnit[$unitKey][(int) $row['supplier_id']] = [
                'unit_cost' => $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null,
                'supplier_sku' => $row['supplier_sku'],
                'moq' => $row['moq'] !== null ? (int) $row['moq'] : null,
            ];
        }
    }

    /**
     * A unit's supplier map, merging any product-level entry (variation_id NULL) with the
     * variation's own - the variation wins where both exist, mirroring how
     * variation_effective_cost()/variation_effective_supplier_sku() already resolve
     * variation-over-parent everywhere else.
     */
    $resolveSupplierCosts = static function (int $productId, ?int $variationId) use ($supplierCostsByUnit): array {
        $productLevel = $supplierCostsByUnit[$productId . ':0'] ?? [];
        if ($variationId === null) {
            return $productLevel;
        }

        return array_replace($productLevel, $supplierCostsByUnit[$productId . ':' . $variationId] ?? []);
    };

    $result = [];
    foreach ($products as $product) {
        $productId = (int) $product['id'];
        $isVariable = $product['catalog_type'] === 'variable';

        $units = [];
        if ($isVariable) {
            foreach ($variationsByProduct[$productId] ?? [] as $variation) {
                $variationId = (int) $variation['id'];
                // A variation's own cost_price is what Unit Cost auto-fills from when it's a
                // real, explicitly-entered positive value - it overrides the parent since a
                // variation can genuinely cost more/less to source than its siblings (e.g. a
                // limited-edition colorway). See variation_effective_cost() for why 0.00
                // counts as "not set" here, not just NULL. MOQ, however, belongs only to the
                // parent product - there is no separate variation-level MOQ.
                $units[] = [
                    'key' => $productId . ':' . $variationId,
                    'sku' => $variation['sku'],
                    // Phase 9E - variation's own Supplier SKU wins if set, otherwise falls
                    // back to the parent product's - see variation_effective_supplier_sku().
                    'supplier_sku' => variation_effective_supplier_sku($variation['supplier_sku'], $product['supplier_sku']),
                    'label' => variation_build_label($pdo, $variationId),
                    'cost_price' => variation_effective_cost($variation['cost_price'], $product['product_cost']),
                    'moq' => $product['moq'] !== null ? (int) $product['moq'] : null,
                    // Per-supplier saved prices; the form prefers the PO's selected supplier and
                    // falls back to cost_price above when that supplier has none on file.
                    'supplier_costs' => $resolveSupplierCosts($productId, $variationId),
                ];
            }
        } else {
            $units[] = [
                'key' => $productId . ':0',
                'sku' => $product['sku'],
                'supplier_sku' => $product['supplier_sku'],
                'label' => null,
                'cost_price' => (float) $product['product_cost'],
                'moq' => $product['moq'] !== null ? (int) $product['moq'] : null,
                'supplier_costs' => $resolveSupplierCosts($productId, null),
            ];
        }

        if ($units === []) {
            continue;
        }

        $result[] = [
            'product_id' => $productId,
            'name' => $product['name'],
            'sku' => $product['sku'],
            'catalog_type' => $product['catalog_type'],
            'product_type' => $product['product_type'],
            'supplier_id' => $product['supplier_id'] !== null ? (int) $product['supplier_id'] : null,
            'supplier_name' => $product['supplier_name'],
            'category_id' => $product['category_id'] !== null ? (int) $product['category_id'] : null,
            'category_name' => $product['category_name'],
            'units' => $units,
        ];
    }

    return $result;
}

/**
 * Phase 7D (Shipping Allocation) - writes ONLY supplier_order_items.shipping_allocated (the
 * Sprint 13 costing-prep column that has existed since Phase 7C's migration but had no writer
 * anywhere in the app until now). Never touches supplier_orders.shipping_fee, subtotal, or any
 * receiving/inventory logic - this is pure cost-allocation bookkeeping, the same class of
 * action as supplier_order_add_payment() (bookkeeping only, allowed on historical orders too).
 *
 * $method:
 *   - 'by_quantity': each line's share of $totalShippingCost is proportional to its
 *     total_quantity (already-stored ordered quantity, not re-derived).
 *   - 'by_cost': each line's share is proportional to its subtotal (already-stored line cost).
 *   - 'manual': $manualAllocations (item_id => amount) is used as-is; every item on the order
 *     must have an entry, so a line can never be left silently unallocated by accident.
 *
 * For 'by_quantity'/'by_cost', every line's share is rounded to 2dp and the last line (by id)
 * absorbs the rounding remainder, so SUM(shipping_allocated) always equals $totalShippingCost
 * exactly for the two automatic methods - only 'manual' entry can end up not matching, which is
 * why the caller (modules/supplier-orders/view.php) always displays Total Allocated next to the
 * order's shipping_fee and warns on a mismatch rather than silently accepting or blocking it.
 *
 * Throws RuntimeException on any invalid input - the caller is expected to catch it and show
 * the message, same convention as every other mutating function in this file.
 */
function supplier_order_allocate_shipping(PDO $pdo, int $orderId, string $method, float $totalShippingCost, array $manualAllocations = []): void
{
    if (!in_array($method, ['by_quantity', 'by_cost', 'manual'], true)) {
        throw new RuntimeException('Invalid shipping allocation method.');
    }
    if ($totalShippingCost < 0) {
        throw new RuntimeException('Shipping cost cannot be negative.');
    }

    $itemsStmt = $pdo->prepare('SELECT id, total_quantity, subtotal FROM supplier_order_items WHERE supplier_order_id = ? ORDER BY id ASC');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($items === []) {
        throw new RuntimeException('This order has no items to allocate shipping to.');
    }

    $allocations = [];

    if ($method === 'manual') {
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            if (!array_key_exists($itemId, $manualAllocations)) {
                throw new RuntimeException('Enter a shipping allocation for every line item.');
            }
            $amount = (float) $manualAllocations[$itemId];
            if ($amount < 0) {
                throw new RuntimeException('Shipping allocation cannot be negative.');
            }
            $allocations[$itemId] = round($amount, 2);
        }
    } else {
        $basisColumn = $method === 'by_quantity' ? 'total_quantity' : 'subtotal';
        $basisTotal = array_sum(array_map(static fn (array $item): float => (float) $item[$basisColumn], $items));

        if ($basisTotal <= 0) {
            throw new RuntimeException($method === 'by_quantity'
                ? 'Cannot allocate by quantity - this order has no ordered quantity.'
                : 'Cannot allocate by cost value - this order has no cost value.');
        }

        $itemCount = count($items);
        $runningTotal = 0.0;
        foreach ($items as $index => $item) {
            $itemId = (int) $item['id'];
            if ($index === $itemCount - 1) {
                // Last line absorbs whatever's left, so the stored allocations always sum to
                // exactly $totalShippingCost regardless of rounding on the earlier lines.
                $allocations[$itemId] = round($totalShippingCost - $runningTotal, 2);
            } else {
                $share = round(((float) $item[$basisColumn] / $basisTotal) * $totalShippingCost, 2);
                $allocations[$itemId] = $share;
                $runningTotal += $share;
            }
        }
    }

    $updateStmt = $pdo->prepare('UPDATE supplier_order_items SET shipping_allocated = ? WHERE id = ? AND supplier_order_id = ?');
    foreach ($allocations as $itemId => $amount) {
        $updateStmt->execute([$amount, $itemId, $orderId]);
    }
}

// Phase 7F (Additional Costs Framework) - a suggested starting list only, offered on the form
// alongside a free-text "Other" option (same dropdown-plus-"Other" convention as
// PRODUCT_COST_CURRENCY_OPTIONS/SUPPLIER_ORDER_CURRENCY_OPTIONS) - cost_type itself stays a
// plain VARCHAR, so typing any other value here is just as valid and needs no schema change.
const SUPPLIER_ORDER_ITEM_COST_TYPE_SUGGESTIONS = ['Customs', 'Handling', 'Packaging', 'Other'];

/**
 * One row per named additional cost on a specific supplier order line - see
 * database/schema.sql's supplier_order_item_costs comment for why this lives here rather than
 * on `products`. Reused as-is by includes/product_cost.php's batch calculation (never a second
 * "read the additional costs" query shape).
 */
function supplier_order_item_list_costs(PDO $pdo, int $itemId): array
{
    $stmt = $pdo->prepare('
        SELECT id, cost_type, amount, notes, created_at
        FROM supplier_order_item_costs
        WHERE supplier_order_item_id = ?
        ORDER BY id ASC
    ');
    $stmt->execute([$itemId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Batched version of supplier_order_item_list_costs() for a whole order's items at once (the
 * Shipping Allocation/Additional Costs section on modules/supplier-orders/view.php needs every
 * line's costs, not just one) - one query regardless of how many line items the order has.
 */
function supplier_order_items_list_costs_batch(PDO $pdo, array $itemIds): array
{
    $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
    if ($itemIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, supplier_order_item_id, cost_type, amount, notes, created_at
        FROM supplier_order_item_costs
        WHERE supplier_order_item_id IN ({$placeholders})
        ORDER BY id ASC
    ");
    $stmt->execute($itemIds);

    $byItem = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byItem[(int) $row['supplier_order_item_id']][] = $row;
    }

    return $byItem;
}

function supplier_order_item_add_cost(PDO $pdo, int $itemId, string $costType, float $amount, ?string $notes): void
{
    $costType = trim($costType);
    if ($costType === '' || strlen($costType) > 50) {
        throw new RuntimeException('Enter a cost type (up to 50 characters).');
    }
    if ($amount < 0) {
        throw new RuntimeException('Cost amount cannot be negative.');
    }

    $itemCheck = $pdo->prepare('SELECT COUNT(*) FROM supplier_order_items WHERE id = ?');
    $itemCheck->execute([$itemId]);
    if ((int) $itemCheck->fetchColumn() === 0) {
        throw new RuntimeException('Invalid order item.');
    }

    $pdo->prepare('
        INSERT INTO supplier_order_item_costs (supplier_order_item_id, cost_type, amount, notes)
        VALUES (?, ?, ?, ?)
    ')->execute([$itemId, $costType, round($amount, 2), $notes !== null && $notes !== '' ? $notes : null]);
}

function supplier_order_item_delete_cost(PDO $pdo, int $costId): void
{
    $pdo->prepare('DELETE FROM supplier_order_item_costs WHERE id = ?')->execute([$costId]);
}

/**
 * Phase 8D (Demand Forecasting) / Phase 8E (Control Center) - the exact "Average Delivery
 * Time" and "Late Orders" formulas modules/reports/suppliers.php / supplier_detail.php already
 * compute (AVG(DATEDIFF(received_date, order_date)) and COUNT(received_date >
 * expected_delivery_date), both over orders where the relevant dates are on file), factored out
 * here so modules/reports/forecast.php and modules/purchasing/control-center.php can both reuse
 * this one definition instead of a second/third copy. Batched for every supplier a caller
 * needs, not one query per supplier. Returns supplier_id => [
 *   'avg_lead_time_days' => float|null, 'sample_count' => int,
 *   'late_orders_count' => int|null, 'late_eligible_count' => int,
 * ] - a null figure (its own sample/eligible count is 0) means this supplier has no order with
 * the dates that figure needs, never guessed as 0 or any other default.
 */
function supplier_lead_time_stats_batch(PDO $pdo, array $supplierIds): array
{
    $supplierIds = array_values(array_unique(array_map('intval', $supplierIds)));
    if ($supplierIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            supplier_id,
            SUM(CASE WHEN order_date IS NOT NULL AND received_date IS NOT NULL THEN DATEDIFF(received_date, order_date) ELSE 0 END) AS delivery_days_sum,
            SUM(CASE WHEN order_date IS NOT NULL AND received_date IS NOT NULL THEN 1 ELSE 0 END) AS sample_count,
            SUM(CASE WHEN received_date IS NOT NULL AND expected_delivery_date IS NOT NULL AND received_date > expected_delivery_date THEN 1 ELSE 0 END) AS late_orders_count,
            SUM(CASE WHEN received_date IS NOT NULL AND expected_delivery_date IS NOT NULL THEN 1 ELSE 0 END) AS late_eligible_count
        FROM supplier_orders
        WHERE supplier_id IN ({$placeholders})
        GROUP BY supplier_id
    ");
    $stmt->execute($supplierIds);

    $bySupplier = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sampleCount = (int) $row['sample_count'];
        $lateEligibleCount = (int) $row['late_eligible_count'];
        $bySupplier[(int) $row['supplier_id']] = [
            'avg_lead_time_days' => $sampleCount > 0 ? ((float) $row['delivery_days_sum'] / $sampleCount) : null,
            'sample_count' => $sampleCount,
            'late_orders_count' => $lateEligibleCount > 0 ? (int) $row['late_orders_count'] : null,
            'late_eligible_count' => $lateEligibleCount,
        ];
    }

    return $bySupplier;
}
