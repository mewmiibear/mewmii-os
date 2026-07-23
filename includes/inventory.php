<?php

require_once __DIR__ . '/product_variations.php';

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

/**
 * $reason/$notes are only ever populated by the manual Adjust Stock modal today - every
 * other caller leaves them null. balance_after is a write-time snapshot of
 * available_quantity, computed here (not passed in by callers) since every caller already
 * updates mewmii_inventory before logging, inside the same transaction, so this read-back
 * is always the correct resulting balance regardless of transaction_type - a generic
 * retroactive reconstruction from the ledger alone isn't reliable, since quantity's effect
 * on available_quantity isn't uniform across every transaction_type.
 */
function inventory_log_transaction(PDO $pdo, int $productId, string $type, int $quantity, string $referenceType, ?int $referenceId, ?int $variationId = null, ?string $reason = null, ?string $notes = null): void
{
    $balanceStmt = $pdo->prepare('SELECT available_quantity FROM mewmii_inventory WHERE product_id = ? AND variation_id <=> ?');
    $balanceStmt->execute([$productId, $variationId]);
    $balanceAfter = $balanceStmt->fetchColumn();
    $balanceAfter = $balanceAfter !== false ? (int) $balanceAfter : null;

    $stmt = $pdo->prepare('
        INSERT INTO inventory_transactions (product_id, variation_id, transaction_type, quantity, reason, notes, balance_after, reference_type, reference_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$productId, $variationId, $type, $quantity, $reason, $notes, $balanceAfter, $referenceType, $referenceId]);
}

function inventory_order_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('
        SELECT oi.id AS order_item_id, oi.product_id, oi.variation_id, oi.quantity, p.product_type
        FROM mewmii_order_items oi
        INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ');
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
 * Throws RuntimeException (caller should roll back) if stock is insufficient. preorder/
 * early_bird items are skipped entirely - they're never drawn from available_quantity,
 * even at 0 stock (see catalog_product_is_orderable()); their real fulfillment happens
 * later via arrived_quantity -> customer_storage allocation instead.
 */
function inventory_reserve_for_order(PDO $pdo, int $orderId): void
{
    foreach (inventory_order_items($pdo, $orderId) as $item) {
        if (in_array($item['product_type'], ['preorder', 'early_bird'], true)) {
            continue;
        }

        $productId = (int) $item['product_id'];
        $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
        $qty = (int) $item['quantity'];

        if ($qty < 1) {
            continue;
        }

        $row = inventory_get_or_create_row($pdo, $productId, $variationId);

        if ((int) $row['available_quantity'] < $qty) {
            throw new RuntimeException(catalog_format_stock_error($pdo, 'Insufficient available stock.', $productId, $variationId, 'Available quantity', (int) $row['available_quantity'], $qty));
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
 * Consumes exactly $quantity of reserved (falling back to available) stock for one
 * product/variation against one order. The shared core both inventory_ship_for_order()
 * (whole-order, legacy/back-compat path) and item-scoped shipment shipping (see
 * shipment_mark_shipped() in includes/shipments.php, which may ship less than an order
 * item's full quantity when it's split across multiple shipments) call - one place this math
 * lives regardless of whether an order ships in one shipment or several.
 */
function inventory_ship_order_quantity(PDO $pdo, int $orderId, int $productId, ?int $variationId, int $quantity): void
{
    if ($quantity < 1) {
        return;
    }

    $row = inventory_get_or_create_row($pdo, $productId, $variationId);
    $netReserved = inventory_net_reserved($pdo, $orderId, $productId, $variationId);
    $fromReserved = min($netReserved, $quantity, (int) $row['reserved_quantity']);
    $shortfall = $quantity - $fromReserved;

    if ($shortfall > 0 && (int) $row['available_quantity'] < $shortfall) {
        throw new RuntimeException(catalog_format_stock_error($pdo, 'Insufficient available stock to ship.', $productId, $variationId, 'Available quantity', (int) $row['available_quantity'], $shortfall));
    }

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET reserved_quantity = reserved_quantity - ?,
            available_quantity = available_quantity - ?
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$fromReserved, $shortfall, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'order_ship', $quantity, 'order', $orderId, $variationId);
}

/**
 * shipping_status -> shipped: consume reserved stock for every ready-stock item on the
 * order, in full. preorder/early_bird items are skipped entirely - they were never reserved
 * here, and their physical fulfillment ships via customer_storage instead (see
 * ship_my_box.php/shipments.php), not this order-level mechanism. Kept for any caller that
 * still wants to ship an order's items in one go; item-scoped/partial shipping goes through
 * inventory_ship_order_quantity() directly instead (see includes/shipments.php).
 */
function inventory_ship_for_order(PDO $pdo, int $orderId): void
{
    foreach (inventory_order_items($pdo, $orderId) as $item) {
        if (in_array($item['product_type'], ['preorder', 'early_bird'], true)) {
            continue;
        }

        $qty = (int) $item['quantity'];
        if ($qty < 1) {
            continue;
        }

        inventory_ship_order_quantity($pdo, $orderId, (int) $item['product_id'], $item['variation_id'] !== null ? (int) $item['variation_id'] : null, $qty);
    }
}

/**
 * Payment-approval variant of inventory_reserve_for_order(): reserves whatever it can per
 * ready-stock item and NEVER throws - an item that can't be fully (or at all) reserved just
 * stays partially/un-reserved, surfacing as fulfillment state 'waiting_stock' (see
 * order_item_get_fulfillment_status()) instead of blocking payment approval itself for
 * reasons unrelated to the payment. The throwing inventory_reserve_for_order() is kept
 * unchanged for order_apply_edit()'s release-then-reserve cycle, where failing an edit
 * outright (rolling back to the pre-edit state) is still correct. Returns the list of items
 * that could not be fully reserved, for an optional admin-facing summary.
 */
function inventory_reserve_for_order_partial(PDO $pdo, int $orderId): array
{
    $shortages = [];

    foreach (inventory_order_items($pdo, $orderId) as $item) {
        if (in_array($item['product_type'], ['preorder', 'early_bird'], true)) {
            continue;
        }

        $productId = (int) $item['product_id'];
        $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
        $qty = (int) $item['quantity'];

        if ($qty < 1) {
            continue;
        }

        $alreadyReserved = inventory_net_reserved($pdo, $orderId, $productId, $variationId);
        $need = $qty - $alreadyReserved;

        if ($need < 1) {
            continue;
        }

        $row = inventory_get_or_create_row($pdo, $productId, $variationId);
        $reservable = min($need, (int) $row['available_quantity']);

        if ($reservable > 0) {
            $pdo->prepare('
                UPDATE mewmii_inventory
                SET available_quantity = available_quantity - ?, reserved_quantity = reserved_quantity + ?
                WHERE product_id = ? AND variation_id <=> ?
            ')->execute([$reservable, $reservable, $productId, $variationId]);

            inventory_log_transaction($pdo, $productId, 'order_reserve', $reservable, 'order', $orderId, $variationId);
        }

        if ($reservable < $need) {
            $shortages[] = [
                'order_item_id' => (int) $item['order_item_id'],
                'product_id' => $productId,
                'variation_id' => $variationId,
                'shortfall' => $need - $reservable,
            ];
        }
    }

    return $shortages;
}

// --- Ready-Stock Reservation Center: backorder top-up once stock arrives ----------------
// Mirrors the Preorder Allocation Center in includes/customer_storage.php exactly (same
// queue/FIFO/manual shape), for the ready_stock case: inventory_reserve_for_order_partial()
// may leave an item under-reserved at payment-approval time if stock wasn't available yet;
// these functions are how staff top it up later, once more stock is on hand.

/**
 * Total outstanding (unreserved) demand for one ready-stock unit, across every paid,
 * non-cancelled, non-historical order item for it - the "Need Reserve" figure in the
 * Reservation Center. Mirrors inventory_unit_outstanding_demand() in customer_storage.php,
 * using inventory_net_reserved() in place of customer-storage allocation.
 */
function inventory_unit_unreserved_demand(PDO $pdo, int $productId, ?int $variationId): int
{
    $stmt = $pdo->prepare("
        SELECT oi.id AS order_item_id, oi.quantity, o.id AS order_id
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE oi.product_id = ? AND oi.variation_id <=> ?
          AND o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.is_historical = 0
    ");
    $stmt->execute([$productId, $variationId]);

    $total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $outstanding = (int) $item['quantity'] - inventory_net_reserved($pdo, (int) $item['order_id'], $productId, $variationId);
        if ($outstanding > 0) {
            $total += $outstanding;
        }
    }

    return $total;
}

/**
 * The full Reservation Center queue: every ready-stock unit that currently has BOTH
 * available stock on hand AND outstanding unreserved demand for it.
 */
function inventory_reservation_queue(PDO $pdo): array
{
    $productsStmt = $pdo->query("
        SELECT id, sku, name, catalog_type, product_type
        FROM products
        WHERE product_type = 'ready_stock'
        ORDER BY name ASC
    ");
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($products as $product) {
        $productId = (int) $product['id'];
        $isVariable = $product['catalog_type'] === 'variable';

        $unitRows = [];
        if ($isVariable) {
            $variationsStmt = $pdo->prepare("SELECT id, sku FROM product_variations WHERE product_id = ? AND status <> 'archived' ORDER BY id ASC");
            $variationsStmt->execute([$productId]);
            foreach ($variationsStmt->fetchAll(PDO::FETCH_ASSOC) as $variation) {
                $unitRows[] = [
                    'variation_id' => (int) $variation['id'],
                    'sku' => $variation['sku'],
                    'label' => variation_build_full_label($pdo, (int) $variation['id']),
                ];
            }
        } else {
            $unitRows[] = ['variation_id' => null, 'sku' => $product['sku'], 'label' => null];
        }

        $units = [];
        foreach ($unitRows as $unitRow) {
            $variationId = $unitRow['variation_id'];
            $inventoryRow = inventory_get_or_create_row($pdo, $productId, $variationId);
            $available = (int) $inventoryRow['available_quantity'];

            if ($available < 1) {
                continue;
            }

            $needReserve = inventory_unit_unreserved_demand($pdo, $productId, $variationId);
            if ($needReserve < 1) {
                continue;
            }

            $units[] = [
                'variation_id' => $variationId,
                'sku' => $unitRow['sku'],
                'label' => $unitRow['label'],
                'available' => $available,
                'need_reserve' => $needReserve,
            ];
        }

        if ($units === []) {
            continue;
        }

        $result[] = [
            'product_id' => $productId,
            'sku' => $product['sku'],
            'name' => $product['name'],
            'catalog_type' => $product['catalog_type'],
            'units' => $units,
        ];
    }

    return $result;
}

/**
 * Manually reserves an additional quantity for one specific order item - the ready-stock
 * mirror of the Allocation Center's manual per-order quantity entry. Only ever tops up
 * (moves available -> reserved); never exceeds the item's own outstanding (unreserved)
 * amount or the unit's available stock.
 */
function inventory_reserve_order_item(PDO $pdo, int $orderItemId, int $quantity): void
{
    if ($quantity < 1) {
        throw new RuntimeException('Quantity must be at least 1.');
    }

    $stmt = $pdo->prepare('
        SELECT oi.id, oi.order_id, oi.product_id, oi.variation_id, oi.quantity,
               o.payment_status, o.order_status, o.is_historical
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE oi.id = ?
        FOR UPDATE
    ');
    $stmt->execute([$orderItemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new RuntimeException('Order item not found.');
    }
    if (!empty($item['is_historical']) || $item['order_status'] === 'cancelled' || $item['payment_status'] !== 'paid') {
        throw new RuntimeException('This order item is not eligible for reservation.');
    }

    $orderId = (int) $item['order_id'];
    $productId = (int) $item['product_id'];
    $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;

    $alreadyReserved = inventory_net_reserved($pdo, $orderId, $productId, $variationId);
    $outstanding = (int) $item['quantity'] - $alreadyReserved;

    if ($quantity > $outstanding) {
        throw new RuntimeException("Requested quantity exceeds this item's outstanding (unreserved) amount.");
    }

    $row = inventory_get_or_create_row($pdo, $productId, $variationId);
    if ((int) $row['available_quantity'] < $quantity) {
        throw new RuntimeException(catalog_format_stock_error($pdo, 'Insufficient available stock.', $productId, $variationId, 'Available quantity', (int) $row['available_quantity'], $quantity));
    }

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET available_quantity = available_quantity - ?, reserved_quantity = reserved_quantity + ?
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$quantity, $quantity, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'order_reserve', $quantity, 'order', $orderId, $variationId);
}

/**
 * Automatic FIFO top-up (Reservation Center's "Option A"): fills the oldest outstanding
 * paid orders first from the unit's current available stock, until either demand or supply
 * runs out. Pure orchestration around inventory_reserve_order_item() - reserves nothing
 * itself, just decides in what order to call it. Returns the list of reservations made.
 */
function inventory_reserve_fifo(PDO $pdo, int $productId, ?int $variationId): array
{
    $row = inventory_get_or_create_row($pdo, $productId, $variationId);
    $budget = (int) $row['available_quantity'];

    if ($budget < 1) {
        return [];
    }

    $candidatesStmt = $pdo->prepare("
        SELECT oi.id AS order_item_id, oi.quantity, o.id AS order_id, o.order_number
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE oi.product_id = ? AND oi.variation_id <=> ?
          AND o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.is_historical = 0
        ORDER BY o.order_date ASC, o.id ASC, oi.id ASC
    ");
    $candidatesStmt->execute([$productId, $variationId]);

    $reservations = [];
    foreach ($candidatesStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if ($budget < 1) {
            break;
        }

        $orderId = (int) $candidate['order_id'];
        $outstanding = (int) $candidate['quantity'] - inventory_net_reserved($pdo, $orderId, $productId, $variationId);
        if ($outstanding < 1) {
            continue;
        }

        $qty = min($outstanding, $budget);
        inventory_reserve_order_item($pdo, (int) $candidate['order_item_id'], $qty);

        $reservations[] = ['order_id' => $orderId, 'order_number' => $candidate['order_number'], 'quantity' => $qty];
        $budget -= $qty;
    }

    return $reservations;
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
 * Historical Inventory import - sets a unit's Opening Stock, the one and only place
 * transaction_type = 'opening_stock' is ever written. Deliberately refuses to run if this
 * unit already has ANY inventory_transactions history - an opening balance only makes
 * sense as the very first entry in a unit's ledger; applying it after real activity has
 * already happened would silently double-count stock. Goes straight to available_quantity
 * (through the normal ledger-paired update, never a raw column write) since an opening
 * balance is, by definition, stock already physically on hand and unreserved.
 */
function inventory_import_opening_stock(PDO $pdo, int $productId, ?int $variationId, int $quantity, ?string $notes = null): void
{
    if ($quantity < 1) {
        throw new RuntimeException('Opening stock quantity must be at least 1.');
    }

    $historyStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE product_id = ? AND variation_id <=> ?');
    $historyStmt->execute([$productId, $variationId]);
    if ((int) $historyStmt->fetchColumn() > 0) {
        throw new RuntimeException(catalog_format_stock_error($pdo, 'This unit already has inventory history - Opening Stock can only be set once, before any other activity.', $productId, $variationId, 'Existing transaction count', (int) $historyStmt->fetchColumn(), 0));
    }

    inventory_get_or_create_row($pdo, $productId, $variationId);

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET available_quantity = available_quantity + ?
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$quantity, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'opening_stock', $quantity, 'inventory_import', null, $variationId, 'Opening Stock', $notes);
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
                COALESCE(SUM(inv.incoming_quantity), 0) AS incoming_quantity,
                COALESCE(SUM(inv.arrived_quantity), 0) AS arrived_quantity
            FROM mewmii_inventory inv
            INNER JOIN product_variations pv ON pv.id = inv.variation_id
            WHERE inv.product_id = ? AND pv.status <> 'archived'
        ");
        $stmt->execute([$productId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['available_quantity' => 0, 'reserved_quantity' => 0, 'incoming_quantity' => 0, 'arrived_quantity' => 0];
    }

    $stmt = $pdo->prepare('
        SELECT available_quantity, reserved_quantity, incoming_quantity, arrived_quantity
        FROM mewmii_inventory
        WHERE product_id = ? AND variation_id IS NULL
    ');
    $stmt->execute([$productId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['available_quantity' => 0, 'reserved_quantity' => 0, 'incoming_quantity' => 0, 'arrived_quantity' => 0];
}
