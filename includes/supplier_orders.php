<?php

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/customer_storage.php';

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
 * Routes received preorder/early-bird stock straight to the customers who ordered it
 * (oldest order first) instead of crediting available_quantity - "do not request
 * available stock" for these types means the matched portion must never touch it. Any
 * portion beyond outstanding customer demand (MOQ/top-up buffer) falls back to
 * available_quantity exactly like ready_stock. Reuses customer_storage_add() with
 * debitFrom='incoming' so the matched portion moves incoming -> customer storage directly.
 */
function supplier_order_receive_preorder_quantity(PDO $pdo, int $productId, ?int $variationId, int $supplierItemId, int $quantity): void
{
    inventory_get_or_create_row($pdo, $productId, $variationId);

    $outstandingStmt = $pdo->prepare("
        SELECT oi.id, oi.quantity, o.customer_id
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE oi.product_id = ? AND oi.variation_id <=> ?
          AND o.order_status <> 'cancelled'
        ORDER BY o.order_date ASC, o.id ASC, oi.id ASC
    ");
    $outstandingStmt->execute([$productId, $variationId]);
    $orderItems = $outstandingStmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $quantity;

    foreach ($orderItems as $orderItem) {
        if ($remaining < 1) {
            break;
        }

        $allocated = supplier_order_item_customer_storage_allocated($pdo, (int) $orderItem['id']);
        $outstanding = (int) $orderItem['quantity'] - $allocated;
        $customerId = (int) $orderItem['customer_id'];

        if ($outstanding < 1 || $customerId < 1) {
            continue;
        }

        $toAllocate = min($outstanding, $remaining);

        customer_storage_add($pdo, $customerId, $productId, $toAllocate, null, $variationId, (int) $orderItem['id'], 'incoming');
        $remaining -= $toAllocate;
    }

    // Leftover beyond matched customer demand (MOQ/top-up buffer) becomes ordinary
    // available stock, same as ready_stock receiving.
    if ($remaining > 0) {
        $pdo->prepare('
            UPDATE mewmii_inventory
            SET incoming_quantity = GREATEST(incoming_quantity - ?, 0),
                available_quantity = available_quantity + ?
            WHERE product_id = ? AND variation_id <=> ?
        ')->execute([$remaining, $remaining, $productId, $variationId]);
    }

    inventory_log_transaction($pdo, $productId, 'supplier_receive', $quantity, 'supplier_order_item', $supplierItemId, $variationId);
}

/**
 * Receive units for one supplier order line item: for ready_stock, moves stock from
 * incoming into available as before. For preorder/early_bird, routes it to outstanding
 * customer orders first (see supplier_order_receive_preorder_quantity()). Once every item
 * on the parent order has been fully received, the order is auto-advanced to status
 * 'received'. The line item's own variation_id (set at PO creation time) determines which
 * inventory row moves.
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
        throw new RuntimeException('Cannot receive more than the remaining ordered quantity.');
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

    $itemsStmt = $pdo->prepare('SELECT id, total_quantity FROM supplier_order_items WHERE supplier_order_id = ?');
    $itemsStmt->execute([$orderId]);
    $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $fullyReceived = true;
    foreach ($allItems as $orderItem) {
        $received = supplier_order_item_received_quantity($pdo, (int) $orderItem['id']);
        if ($received < (int) $orderItem['total_quantity']) {
            $fullyReceived = false;
            break;
        }
    }

    if ($fullyReceived) {
        $orderStmt = $pdo->prepare('SELECT status FROM supplier_orders WHERE id = ?');
        $orderStmt->execute([$orderId]);
        $currentStatus = (string) $orderStmt->fetchColumn();

        if (!in_array($currentStatus, ['received', 'completed'], true)) {
            $pdo->prepare("UPDATE supplier_orders SET status = 'received', received_date = CURDATE() WHERE id = ?")
                ->execute([$orderId]);
        }
    }
}
