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

    inventory_queue_woocommerce_resync($productId);
}

/**
 * Request-scoped queue of product ids touched by inventory_log_transaction() since the last
 * flush/discard - the mechanism that lets ANY inventory-mutating code path (not just a
 * hand-picked list) trigger a WooCommerce stock resync, without inventory_log_transaction()
 * itself needing to know anything about WooCommerce beyond "a product's stock changed."
 * Populated only by inventory_queue_woocommerce_resync(); read/cleared only by
 * inventory_flush_woocommerce_resync() / inventory_discard_pending_woocommerce_resync().
 * A plain static array is safe here because each HTTP request is its own PHP process/
 * lifecycle - nothing in this registry persists or leaks between requests.
 */
function &inventory_pending_woocommerce_resync_registry(): array
{
    static $productIds = [];

    return $productIds;
}

function inventory_queue_woocommerce_resync(int $productId): void
{
    $registry = &inventory_pending_woocommerce_resync_registry();
    $registry[$productId] = true;
}

/** Call after $pdo->rollBack() so a failed transaction's queued product ids never leak into a later, unrelated flush() within the same request. */
function inventory_discard_pending_woocommerce_resync(): void
{
    $registry = &inventory_pending_woocommerce_resync_registry();
    $registry = [];
}

/**
 * Call once, immediately after $pdo->commit(), from any code path that mutated inventory -
 * pushes a WooCommerce stock resync for every distinct product touched since the last
 * flush/discard, then clears the queue. Reuses wc_client_auto_sync_product()
 * (includes/wc_client.php) completely unmodified - the exact function
 * modules/products/create.php and edit.php already call after their own product-save commit,
 * so Auto Sync gating, the unchanged-skip fingerprint, and failure logging
 * (sync_log_failure(), visible on modules/integrations/woocommerce.php) all behave
 * identically here. Never throws - wc_client_auto_sync_product() already catches everything
 * internally, so a WooCommerce outage can never roll back or fail the inventory change that
 * already committed. wc_client.php is required lazily, here, rather than at this file's top,
 * because wc_client.php itself requires inventory.php - requiring it eagerly at the top of
 * this file would create a load-order-dependent circular require.
 */
function inventory_flush_woocommerce_resync(PDO $pdo): void
{
    $registry = &inventory_pending_woocommerce_resync_registry();

    if ($registry === []) {
        return;
    }

    $productIds = array_keys($registry);
    $registry = [];

    require_once __DIR__ . '/wc_client.php';

    if (!wc_client_auto_sync_enabled($pdo)) {
        return;
    }

    foreach ($productIds as $productId) {
        wc_client_auto_sync_product($pdo, $productId);
    }
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
 *
 * Set-based rewrite (Production Readiness Phase 1, Critical #3): the original queried every
 * ready-stock product, then every variation of each, then ran inventory_get_or_create_row()
 * and inventory_unit_unreserved_demand() (itself a per-order-item loop calling
 * inventory_net_reserved()) per unit - a deeply nested N+1 on every dashboard load. This
 * version computes available_quantity and need_reserve for every unit in one SQL query
 * (inventory_net_reserved()'s own clamped-net-reserved logic reproduced as a correlated
 * subquery), filters to just the qualifying units, and only then builds each variation's
 * label - the one piece still requiring its own query, now only for the small qualifying set
 * rather than every ready-stock unit in the catalog. Output shape is unchanged; the read-only
 * inventory_get_or_create_row()-style auto-insert-if-missing side effect is dropped here since
 * it was never observable from a listing (a product with no inventory row has zero available
 * stock either way, which fails the "available >= 1" filter regardless).
 */
function inventory_reservation_queue(PDO $pdo): array
{
    $sql = "
        SELECT * FROM (
            SELECT
                p.id AS product_id, NULL AS variation_id, p.sku AS unit_sku,
                p.sku AS product_sku, p.name AS product_name, p.catalog_type AS catalog_type,
                COALESCE(inv.available_quantity, 0) AS available_quantity,
                (
                    SELECT COALESCE(SUM(GREATEST(oi.quantity - (
                        SELECT GREATEST(COALESCE(SUM(CASE WHEN it.transaction_type = 'order_reserve' THEN it.quantity ELSE -it.quantity END), 0), 0)
                        FROM inventory_transactions it
                        WHERE it.reference_type = 'order' AND it.reference_id = oi.order_id
                          AND it.product_id = p.id AND it.variation_id IS NULL
                          AND it.transaction_type IN ('order_reserve', 'order_release', 'order_ship')
                    ), 0)), 0)
                    FROM mewmii_order_items oi
                    INNER JOIN mewmii_orders o ON o.id = oi.order_id
                    WHERE oi.product_id = p.id AND oi.variation_id IS NULL
                      AND o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.is_historical = 0
                ) AS need_reserve
            FROM products p
            LEFT JOIN mewmii_inventory inv ON inv.product_id = p.id AND inv.variation_id IS NULL
            WHERE p.product_type = 'ready_stock' AND p.catalog_type = 'simple'

            UNION ALL

            SELECT
                p.id AS product_id, pv.id AS variation_id, pv.sku AS unit_sku,
                p.sku AS product_sku, p.name AS product_name, p.catalog_type AS catalog_type,
                COALESCE(inv.available_quantity, 0) AS available_quantity,
                (
                    SELECT COALESCE(SUM(GREATEST(oi.quantity - (
                        SELECT GREATEST(COALESCE(SUM(CASE WHEN it.transaction_type = 'order_reserve' THEN it.quantity ELSE -it.quantity END), 0), 0)
                        FROM inventory_transactions it
                        WHERE it.reference_type = 'order' AND it.reference_id = oi.order_id
                          AND it.product_id = p.id AND it.variation_id <=> pv.id
                          AND it.transaction_type IN ('order_reserve', 'order_release', 'order_ship')
                    ), 0)), 0)
                    FROM mewmii_order_items oi
                    INNER JOIN mewmii_orders o ON o.id = oi.order_id
                    WHERE oi.product_id = p.id AND oi.variation_id <=> pv.id
                      AND o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.is_historical = 0
                ) AS need_reserve
            FROM products p
            INNER JOIN product_variations pv ON pv.product_id = p.id AND pv.status <> 'archived'
            LEFT JOIN mewmii_inventory inv ON inv.product_id = p.id AND inv.variation_id = pv.id
            WHERE p.product_type = 'ready_stock' AND p.catalog_type = 'variable'
        ) AS queue
        WHERE available_quantity >= 1 AND need_reserve >= 1
        ORDER BY product_name ASC, variation_id ASC
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $byProduct = [];
    foreach ($rows as $row) {
        $productId = (int) $row['product_id'];
        $variationId = $row['variation_id'] !== null ? (int) $row['variation_id'] : null;

        if (!isset($byProduct[$productId])) {
            $byProduct[$productId] = [
                'product_id' => $productId,
                'sku' => $row['product_sku'],
                'name' => $row['product_name'],
                'catalog_type' => $row['catalog_type'],
                'units' => [],
            ];
        }

        $byProduct[$productId]['units'][] = [
            'variation_id' => $variationId,
            'sku' => $row['unit_sku'],
            'label' => $variationId !== null ? variation_build_full_label($pdo, $variationId) : null,
            'available' => (int) $row['available_quantity'],
            'need_reserve' => (int) $row['need_reserve'],
        ];
    }

    return array_values($byProduct);
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
