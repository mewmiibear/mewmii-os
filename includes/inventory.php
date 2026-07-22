<?php

function inventory_get_or_create_row(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('SELECT * FROM mewmii_inventory WHERE product_id = ? FOR UPDATE');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->prepare('INSERT INTO mewmii_inventory (product_id) VALUES (?)')->execute([$productId]);
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $row;
}

function inventory_log_transaction(PDO $pdo, int $productId, string $type, int $quantity, string $referenceType, ?int $referenceId): void
{
    $stmt = $pdo->prepare('
        INSERT INTO inventory_transactions (product_id, transaction_type, quantity, reference_type, reference_id)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$productId, $type, $quantity, $referenceType, $referenceId]);
}

function inventory_order_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT product_id, quantity FROM mewmii_order_items WHERE order_id = ?');
    $stmt->execute([$orderId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Net units still reserved (and not yet shipped or released) for one product on one order,
 * derived from the inventory_transactions ledger rather than order status, so it stays
 * correct even if a reserve step was skipped or repeated.
 */
function inventory_net_reserved(PDO $pdo, int $orderId, int $productId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN transaction_type = 'order_reserve' THEN quantity ELSE -quantity END), 0)
        FROM inventory_transactions
        WHERE reference_type = 'order'
          AND reference_id = ?
          AND product_id = ?
          AND transaction_type IN ('order_reserve', 'order_release', 'order_ship')
    ");
    $stmt->execute([$orderId, $productId]);

    return max(0, (int) $stmt->fetchColumn());
}

/**
 * pending -> processing: move stock from available into reserved for every item on the order.
 * Throws RuntimeException (caller should roll back) if stock is insufficient.
 */
function inventory_reserve_for_order(PDO $pdo, int $orderId): void
{
    foreach (inventory_order_items($pdo, $orderId) as $item) {
        $productId = (int) $item['product_id'];
        $qty = (int) $item['quantity'];

        if ($qty < 1) {
            continue;
        }

        $row = inventory_get_or_create_row($pdo, $productId);

        if ((int) $row['available_quantity'] < $qty) {
            throw new RuntimeException('Insufficient available stock for product #' . $productId . '.');
        }

        $pdo->prepare('
            UPDATE mewmii_inventory
            SET available_quantity = available_quantity - ?, reserved_quantity = reserved_quantity + ?
            WHERE product_id = ?
        ')->execute([$qty, $qty, $productId]);

        inventory_log_transaction($pdo, $productId, 'order_reserve', $qty, 'order', $orderId);
    }
}

/**
 * shipping_status -> shipped: consume reserved stock for the order. Falls back to pulling
 * straight from available stock for any quantity that was never reserved (e.g. processing
 * was skipped), still refusing to go negative.
 */
function inventory_ship_for_order(PDO $pdo, int $orderId): void
{
    foreach (inventory_order_items($pdo, $orderId) as $item) {
        $productId = (int) $item['product_id'];
        $qty = (int) $item['quantity'];

        if ($qty < 1) {
            continue;
        }

        $row = inventory_get_or_create_row($pdo, $productId);
        $netReserved = inventory_net_reserved($pdo, $orderId, $productId);
        $fromReserved = min($netReserved, $qty, (int) $row['reserved_quantity']);
        $shortfall = $qty - $fromReserved;

        if ($shortfall > 0 && (int) $row['available_quantity'] < $shortfall) {
            throw new RuntimeException('Insufficient stock to ship product #' . $productId . '.');
        }

        $pdo->prepare('
            UPDATE mewmii_inventory
            SET reserved_quantity = reserved_quantity - ?,
                available_quantity = available_quantity - ?
            WHERE product_id = ?
        ')->execute([$fromReserved, $shortfall, $productId]);

        inventory_log_transaction($pdo, $productId, 'order_ship', $qty, 'order', $orderId);
    }
}

/**
 * order_status -> cancelled: return any still-outstanding reserved stock to available.
 * No-op per item if nothing was ever reserved for it.
 */
function inventory_release_for_order(PDO $pdo, int $orderId): void
{
    foreach (inventory_order_items($pdo, $orderId) as $item) {
        $productId = (int) $item['product_id'];
        $qty = (int) $item['quantity'];

        $netReserved = inventory_net_reserved($pdo, $orderId, $productId);
        $releaseQty = min($netReserved, $qty);

        if ($releaseQty < 1) {
            continue;
        }

        inventory_get_or_create_row($pdo, $productId);

        $pdo->prepare('
            UPDATE mewmii_inventory
            SET reserved_quantity = GREATEST(reserved_quantity - ?, 0),
                available_quantity = available_quantity + ?
            WHERE product_id = ?
        ')->execute([$releaseQty, $releaseQty, $productId]);

        inventory_log_transaction($pdo, $productId, 'order_release', $releaseQty, 'order', $orderId);
    }
}
