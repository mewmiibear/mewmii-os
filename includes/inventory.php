<?php

/**
 * Every function here now accepts an optional trailing $variationId (default null).
 * null means "this is a simple product" (or, historically, any pre-existing call site
 * that doesn't know about variations yet) - all existing callers keep working exactly
 * as before. A real variation id means the operation targets that variation's own
 * inventory row rather than the parent product's.
 *
 * Comparisons against variation_id use the NULL-safe equals operator (<=>) so that
 * "variation_id IS NULL" and "variation_id = 5" both work through the same bound
 * parameter without a separate branch.
 */

function inventory_get_or_create_row(PDO $pdo, int $productId, ?int $variationId = null): array
{
    $stmt = $pdo->prepare('SELECT * FROM mewmii_inventory WHERE product_id = ? AND variation_id <=> ? FOR UPDATE');
    $stmt->execute([$productId, $variationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        if ($variationId === null) {
            // A variable product's own stock is always computed from its variations
            // (see product_effective_stock()) - it must never get a directly-stored row.
            $typeStmt = $pdo->prepare('SELECT catalog_type FROM products WHERE id = ?');
            $typeStmt->execute([$productId]);
            if ($typeStmt->fetchColumn() === 'variable') {
                throw new RuntimeException('Cannot store inventory directly on a variable product - adjust its variations instead.');
            }
        }

        $pdo->prepare('INSERT INTO mewmii_inventory (product_id, variation_id) VALUES (?, ?)')->execute([$productId, $variationId]);
        $stmt->execute([$productId, $variationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $row;
}

function inventory_log_transaction(PDO $pdo, int $productId, string $type, int $quantity, string $referenceType, ?int $referenceId, ?int $variationId = null): void
{
    $stmt = $pdo->prepare('
        INSERT INTO inventory_transactions (product_id, variation_id, transaction_type, quantity, reference_type, reference_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$productId, $variationId, $type, $quantity, $referenceType, $referenceId]);
}

function inventory_order_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT product_id, variation_id, quantity FROM mewmii_order_items WHERE order_id = ?');
    $stmt->execute([$orderId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Net units still reserved (and not yet shipped or released) for one product/variation on
 * one order, derived from the inventory_transactions ledger rather than order status, so
 * it stays correct even if a reserve step was skipped or repeated.
 */
function inventory_net_reserved(PDO $pdo, int $orderId, int $productId, ?int $variationId = null): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN transaction_type = 'order_reserve' THEN quantity ELSE -quantity END), 0)
        FROM inventory_transactions
        WHERE reference_type = 'order'
          AND reference_id = ?
          AND product_id = ?
          AND variation_id <=> ?
          AND transaction_type IN ('order_reserve', 'order_release', 'order_ship')
    ");
    $stmt->execute([$orderId, $productId, $variationId]);

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
        $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
        $qty = (int) $item['quantity'];

        if ($qty < 1) {
            continue;
        }

        $row = inventory_get_or_create_row($pdo, $productId, $variationId);

        if ((int) $row['available_quantity'] < $qty) {
            throw new RuntimeException('Insufficient available stock for product #' . $productId . ($variationId !== null ? (' (variation #' . $variationId . ')') : '') . '.');
        }

        $pdo->prepare('
            UPDATE mewmii_inventory
            SET available_quantity = available_quantity - ?, reserved_quantity = reserved_quantity + ?
            WHERE product_id = ? AND variation_id <=> ?
        ')->execute([$qty, $qty, $productId, $variationId]);

        inventory_log_transaction($pdo, $productId, 'order_reserve', $qty, 'order', $orderId, $variationId);
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
        $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
        $qty = (int) $item['quantity'];

        if ($qty < 1) {
            continue;
        }

        $row = inventory_get_or_create_row($pdo, $productId, $variationId);
        $netReserved = inventory_net_reserved($pdo, $orderId, $productId, $variationId);
        $fromReserved = min($netReserved, $qty, (int) $row['reserved_quantity']);
        $shortfall = $qty - $fromReserved;

        if ($shortfall > 0 && (int) $row['available_quantity'] < $shortfall) {
            throw new RuntimeException('Insufficient stock to ship product #' . $productId . ($variationId !== null ? (' (variation #' . $variationId . ')') : '') . '.');
        }

        $pdo->prepare('
            UPDATE mewmii_inventory
            SET reserved_quantity = reserved_quantity - ?,
                available_quantity = available_quantity - ?
            WHERE product_id = ? AND variation_id <=> ?
        ')->execute([$fromReserved, $shortfall, $productId, $variationId]);

        inventory_log_transaction($pdo, $productId, 'order_ship', $qty, 'order', $orderId, $variationId);
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
        $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
        $qty = (int) $item['quantity'];

        $netReserved = inventory_net_reserved($pdo, $orderId, $productId, $variationId);
        $releaseQty = min($netReserved, $qty);

        if ($releaseQty < 1) {
            continue;
        }

        inventory_get_or_create_row($pdo, $productId, $variationId);

        $pdo->prepare('
            UPDATE mewmii_inventory
            SET reserved_quantity = GREATEST(reserved_quantity - ?, 0),
                available_quantity = available_quantity + ?
            WHERE product_id = ? AND variation_id <=> ?
        ')->execute([$releaseQty, $releaseQty, $productId, $variationId]);

        inventory_log_transaction($pdo, $productId, 'order_release', $releaseQty, 'order', $orderId, $variationId);
    }
}

/**
 * Stock for one product as shown to staff: a simple product reads its own direct row;
 * a variable product's stock is never stored on the product itself - it is always the
 * sum across its (non-archived) variations.
 */
function product_effective_stock(PDO $pdo, int $productId): array
{
    $productStmt = $pdo->prepare('SELECT catalog_type FROM products WHERE id = ?');
    $productStmt->execute([$productId]);
    $catalogType = $productStmt->fetchColumn();

    if ($catalogType === 'variable') {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(inv.available_quantity), 0) AS available_quantity,
                COALESCE(SUM(inv.reserved_quantity), 0) AS reserved_quantity,
                COALESCE(SUM(inv.incoming_quantity), 0) AS incoming_quantity
            FROM mewmii_inventory inv
            INNER JOIN product_variations pv ON pv.id = inv.variation_id
            WHERE inv.product_id = ? AND pv.status <> 'archived'
        ");
        $stmt->execute([$productId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['available_quantity' => 0, 'reserved_quantity' => 0, 'incoming_quantity' => 0];
    }

    $stmt = $pdo->prepare('
        SELECT available_quantity, reserved_quantity, incoming_quantity
        FROM mewmii_inventory
        WHERE product_id = ? AND variation_id IS NULL
    ');
    $stmt->execute([$productId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['available_quantity' => 0, 'reserved_quantity' => 0, 'incoming_quantity' => 0];
}
