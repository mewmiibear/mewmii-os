<?php

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/supplier_orders.php';

/**
 * Item-level fulfillment tracking. One order item's state is computed, never stored - see
 * order_item_get_fulfillment_status() - built from the same signals the rest of the app
 * already treats as authoritative (the inventory ledger via inventory_net_reserved(),
 * customer_storage, and shipment_items), so there is no second copy of fulfillment truth to
 * keep in sync. order_recompute_status() rolls every item's state up into a single
 * mewmii_orders.order_status - the ONE place that column is ever written; nothing else in
 * the app sets order_status directly.
 */

/**
 * Total quantity of one order item ever included in a non-cancelled shipment, split by
 * whether that shipment has actually left the warehouse yet ('shipped'/'delivered') or is
 * still being assembled ('pending'/'packed'). Reads shipment_items.order_item_id, which is
 * always populated at shipment-creation time regardless of whether the line came from a
 * ready-stock reservation or a customer_storage lot (see includes/shipments.php).
 */
function order_item_shipment_progress(PDO $pdo, int $orderItemId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN s.shipping_status IN ('shipped', 'delivered') THEN si.quantity ELSE 0 END), 0) AS shipped_quantity,
            COALESCE(SUM(CASE WHEN s.shipping_status IN ('pending', 'packed') THEN si.quantity ELSE 0 END), 0) AS packing_quantity
        FROM shipment_items si
        INNER JOIN shipments s ON s.id = si.shipment_id
        WHERE si.order_item_id = ? AND s.shipping_status <> 'cancelled'
    ");
    $stmt->execute([$orderItemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['shipped_quantity' => 0, 'packing_quantity' => 0];

    return ['shipped_quantity' => (int) $row['shipped_quantity'], 'packing_quantity' => (int) $row['packing_quantity']];
}

/**
 * Every non-cancelled shipment that has ever carried any quantity of one order item -
 * tracking/carrier/status detail for the item-level timeline (Part 8/10 of the spec).
 */
function order_item_shipments(PDO $pdo, int $orderItemId): array
{
    $stmt = $pdo->prepare("
        SELECT s.id, s.shipment_number, s.carrier, s.tracking_number, s.shipping_status, s.shipped_at, si.quantity
        FROM shipment_items si
        INNER JOIN shipments s ON s.id = si.shipment_id
        WHERE si.order_item_id = ? AND s.shipping_status <> 'cancelled'
        ORDER BY s.created_at ASC, s.id ASC
    ");
    $stmt->execute([$orderItemId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The ONE place per-item fulfillment state is derived. Returns:
 *   state: 'cancelled' | 'historical' | 'waiting_stock' | 'packing' | 'ready_to_ship' |
 *          'stored' | 'shipped'
 *   quantity, shipped_quantity, remaining_quantity, plus state-specific detail
 *   (reserved_quantity for ready_stock; allocated/stored_quantity for preorder/early_bird)
 *   and shipments (see order_item_shipments()).
 *
 * 'stored' is the preorder/early_bird equivalent of ready_stock's 'ready_to_ship' - both
 * mean "fully ready to go into a shipment, not yet in one". The spec's separate "Arrived
 * Warehouse" stage (arrived but not yet allocated to a customer) is deliberately NOT
 * surfaced per-item here: allocation is a product-level queue (see
 * inventory_allocation_queue()/modules/inventory/allocation-center.php) - before an item is
 * allocated there's nothing yet to attribute to this specific order item, so it reports as
 * 'waiting_stock' until allocation happens, same as "still with the supplier".
 */
function order_item_get_fulfillment_status(PDO $pdo, int $orderItemId): array
{
    $stmt = $pdo->prepare('
        SELECT oi.id, oi.order_id, oi.product_id, oi.variation_id, oi.quantity,
               o.order_status, o.is_historical, p.product_type
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.id = ?
        LIMIT 1
    ');
    $stmt->execute([$orderItemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new RuntimeException('Order item not found.');
    }

    $quantity = (int) $item['quantity'];
    $orderId = (int) $item['order_id'];
    $productId = (int) $item['product_id'];
    $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;

    $base = ['quantity' => $quantity, 'order_item_id' => $orderItemId];

    if ($item['order_status'] === 'cancelled') {
        return $base + ['state' => 'cancelled', 'shipped_quantity' => 0, 'remaining_quantity' => 0, 'shipments' => []];
    }

    if (!empty($item['is_historical'])) {
        return $base + ['state' => 'historical', 'shipped_quantity' => 0, 'remaining_quantity' => 0, 'shipments' => []];
    }

    $progress = order_item_shipment_progress($pdo, $orderItemId);
    $shippedQty = min($progress['shipped_quantity'], $quantity);
    $packingQty = min($progress['packing_quantity'], $quantity - $shippedQty);
    $remainingQty = $quantity - $shippedQty;
    $shipments = order_item_shipments($pdo, $orderItemId);

    if ($shippedQty >= $quantity) {
        return $base + ['state' => 'shipped', 'shipped_quantity' => $shippedQty, 'remaining_quantity' => 0, 'shipments' => $shipments];
    }

    if (in_array($item['product_type'], ['preorder', 'early_bird'], true)) {
        $storageStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM customer_storage WHERE order_item_id = ? AND status = 'stored'");
        $storageStmt->execute([$orderItemId]);
        $storedQty = min((int) $storageStmt->fetchColumn(), $remainingQty - $packingQty);
        $waitingQty = $remainingQty - $packingQty - $storedQty;

        $state = $waitingQty > 0 ? 'waiting_stock' : ($packingQty > 0 ? 'packing' : 'stored');

        return $base + [
            'state' => $state,
            'shipped_quantity' => $shippedQty,
            'remaining_quantity' => $remainingQty,
            'packing_quantity' => $packingQty,
            'stored_quantity' => $storedQty,
            'waiting_quantity' => $waitingQty,
            'shipments' => $shipments,
        ];
    }

    // ready_stock
    $reservedQty = min(inventory_net_reserved($pdo, $orderId, $productId, $variationId), $remainingQty);
    $readyQty = max(0, $reservedQty - $packingQty);
    $waitingQty = $remainingQty - $reservedQty;

    $state = $waitingQty > 0 ? 'waiting_stock' : ($packingQty > 0 ? 'packing' : 'ready_to_ship');

    return $base + [
        'state' => $state,
        'shipped_quantity' => $shippedQty,
        'remaining_quantity' => $remainingQty,
        'packing_quantity' => $packingQty,
        'reserved_quantity' => $readyQty,
        'waiting_quantity' => $waitingQty,
        'shipments' => $shipments,
    ];
}

function order_item_fulfillment_label(string $state): string
{
    $labels = [
        'cancelled' => 'Cancelled',
        'historical' => 'Historical Record',
        'waiting_stock' => 'Waiting Stock',
        'packing' => 'Packing',
        'ready_to_ship' => 'Ready To Ship',
        'stored' => 'Stored In Warehouse',
        'shipped' => 'Shipped',
    ];

    return $labels[$state] ?? ucfirst(str_replace('_', ' ', $state));
}

/**
 * Whether every non-cancelled shipment touching this order has actually been marked
 * 'delivered' - the one signal that distinguishes order_status 'shipped' (left the
 * warehouse) from 'completed' (customer has it), used only once every item is already
 * shipped. Deliberately a live query rather than a per-item field, since "delivered" is a
 * property of the shipment, not of any one item in it.
 */
function order_all_shipments_delivered(PDO $pdo, int $orderId): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM shipments s
        WHERE s.id IN (SELECT DISTINCT shipment_id FROM shipment_items WHERE order_id = ?)
          AND s.shipping_status <> 'cancelled' AND s.shipping_status <> 'delivered'
    ");
    $stmt->execute([$orderId]);

    return (int) $stmt->fetchColumn() === 0;
}

/**
 * Automatic, always-on order status engine - the ONLY function that writes
 * mewmii_orders.order_status (besides the historical importer, which sets it once at import
 * time and is then permanently excluded from this function - see the is_historical guard
 * below). No override/freeze mechanism: every call recomputes from current item state and
 * persists the result. If staff need to flag or annotate an order, that's a plain note on
 * mewmii_order_events (see modules/orders/view.php's "Add Note" action) - it never changes
 * this computed value. Call this after every mutation that could change fulfillment state:
 * payment approval, reservation, allocation, shipment creation/ship/deliver/cancel.
 *
 * Item states roll up as: any item still waiting_stock keeps the whole order at
 * waiting_stock; else any item packing (and none shipped yet) -> ready_to_ship; else if
 * every item is fully ready/stored but none packing/shipped yet -> waiting_ship_my_box;
 * mixed shipped/not-shipped -> partially_fulfilled; all shipped -> shipped or completed,
 * split by whether every shipment has reached 'delivered' (see
 * order_all_shipments_delivered()).
 */
function order_recompute_status(PDO $pdo, int $orderId): void
{
    $orderStmt = $pdo->prepare('SELECT order_status, payment_status, is_historical FROM mewmii_orders WHERE id = ? FOR UPDATE');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || !empty($order['is_historical']) || $order['order_status'] === 'cancelled') {
        return;
    }

    if ($order['payment_status'] !== 'paid') {
        $newStatus = 'pending';
    } else {
        $itemsStmt = $pdo->prepare('SELECT id FROM mewmii_order_items WHERE order_id = ?');
        $itemsStmt->execute([$orderId]);
        $itemIds = $itemsStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($itemIds === []) {
            $newStatus = 'processing';
        } else {
            $shippedCount = 0;
            $packingCount = 0;
            $waitingCount = 0;
            $readyCount = 0;

            foreach ($itemIds as $itemId) {
                $status = order_item_get_fulfillment_status($pdo, (int) $itemId);
                switch ($status['state']) {
                    case 'shipped':
                        $shippedCount++;
                        break;
                    case 'packing':
                        $packingCount++;
                        break;
                    case 'waiting_stock':
                        $waitingCount++;
                        break;
                    default: // ready_to_ship, stored
                        $readyCount++;
                }
            }

            $totalItems = count($itemIds);

            if ($shippedCount === $totalItems) {
                $newStatus = order_all_shipments_delivered($pdo, $orderId) ? 'completed' : 'shipped';
            } elseif ($shippedCount > 0) {
                $newStatus = 'partially_fulfilled';
            } elseif ($waitingCount > 0) {
                $newStatus = 'waiting_stock';
            } elseif ($packingCount > 0) {
                $newStatus = 'ready_to_ship';
            } elseif ($readyCount === $totalItems) {
                $newStatus = 'waiting_ship_my_box';
            } else {
                $newStatus = 'processing';
            }
        }
    }

    if ($newStatus === $order['order_status']) {
        return;
    }

    $pdo->prepare('UPDATE mewmii_orders SET order_status = ? WHERE id = ?')->execute([$newStatus, $orderId]);

    $pdo->prepare('
        INSERT INTO mewmii_order_events (order_id, event_type, description, created_by)
        VALUES (?, ?, ?, ?)
    ')->execute([
        $orderId,
        'order_status_change',
        sprintf("Order Status automatically changed from '%s' to '%s'.", $order['order_status'], $newStatus),
        $_SESSION['user_id'] ?? null,
    ]);
}
