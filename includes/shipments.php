<?php

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/order_fulfillment.php';
require_once __DIR__ . '/product_variations.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/activity_log.php';

/**
 * Unified shipment/tracking system - the ONE place carrier/tracking_number live for every
 * physical package leaving the warehouse, regardless of source (see shipments.source_type in
 * database/schema.sql). Every mutating function here is either pure record-keeping (create,
 * pack, cancel, update tracking) or, at shipment_mark_shipped() only, the single point where
 * a shipment's contents are actually consumed from the ledger - reusing
 * inventory_ship_order_quantity() for ready-stock lines and the same
 * customer_storage-consumption logic ship_request_process() already uses for storage-backed
 * lines, never a third way of moving stock. order_recompute_status() is called after every
 * action that could change an order's fulfillment picture.
 */

const SHIPMENT_SOURCE_TYPES = ['order', 'ship_my_box', 'manual'];
const SHIPMENT_STATUSES = ['pending', 'packed', 'shipped', 'delivered', 'cancelled'];

function shipment_generate_number(): string
{
    return 'SHP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

function shipment_status_label(string $status): string
{
    $labels = [
        'pending' => 'Pending',
        'packed' => 'Packed',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    return $labels[$status] ?? ucfirst($status);
}

function shipment_status_badge(string $status): string
{
    $colors = [
        'pending' => 'secondary',
        'packed' => 'info text-dark',
        'shipped' => 'primary',
        'delivered' => 'success',
        'cancelled' => 'danger',
    ];
    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' . $color . '">' . htmlspecialchars(shipment_status_label($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

function shipment_log_event(PDO $pdo, int $shipmentId, string $eventType, ?string $notes = null): void
{
    $pdo->prepare('
        INSERT INTO shipment_events (shipment_id, event_type, notes, created_by)
        VALUES (?, ?, ?, ?)
    ')->execute([$shipmentId, $eventType, $notes, $_SESSION['user_id'] ?? null]);
}

function shipment_assert_order_not_historical(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare('SELECT is_historical FROM mewmii_orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $isHistorical = $stmt->fetchColumn();

    if ($isHistorical === false) {
        throw new RuntimeException('Order not found.');
    }
    if ((int) $isHistorical === 1) {
        throw new RuntimeException('This is a historical (imported) order - it cannot be shipped through this workflow.');
    }
}

/**
 * Every distinct order touched by a shipment (nullable order_id lines, e.g. a pure manual
 * line, are excluded) - used to know which orders need order_recompute_status() after this
 * shipment changes state.
 */
function shipment_order_ids(PDO $pdo, int $shipmentId): array
{
    $stmt = $pdo->prepare('SELECT DISTINCT order_id FROM shipment_items WHERE shipment_id = ? AND order_id IS NOT NULL');
    $stmt->execute([$shipmentId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Total quantity already committed to some non-cancelled shipment for one order item or
 * customer_storage lot, restricted to $statuses. Used to stop the same reserved/stored unit
 * from being double-booked into two different shipments at once.
 */
function shipment_item_committed_quantity(PDO $pdo, string $column, int $id, array $statuses): int
{
    if (!in_array($column, ['order_item_id', 'customer_storage_id'], true)) {
        throw new RuntimeException('Invalid shipment item reference column.');
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity), 0)
        FROM shipment_items si
        INNER JOIN shipments s ON s.id = si.shipment_id
        WHERE si.{$column} = ? AND s.shipping_status IN ({$placeholders})
    ");
    $stmt->execute(array_merge([$id], $statuses));

    return (int) $stmt->fetchColumn();
}

/**
 * How much of one ready-stock order item is currently reserved AND not already committed to
 * another open (pending/packed) shipment - the max a new shipment line for this item can ask
 * for. inventory_net_reserved() already excludes anything a PRIOR shipment has actually
 * shipped (see inventory_ship_order_quantity()'s 'order_ship' transactions), so only
 * still-open commitments need subtracting here.
 */
function shipment_order_item_available_to_ship(PDO $pdo, int $orderItemId): int
{
    $stmt = $pdo->prepare('SELECT order_id, product_id, variation_id FROM mewmii_order_items WHERE id = ?');
    $stmt->execute([$orderItemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new RuntimeException('Order item not found.');
    }

    $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
    $reserved = inventory_net_reserved($pdo, (int) $item['order_id'], (int) $item['product_id'], $variationId);
    $committed = shipment_item_committed_quantity($pdo, 'order_item_id', $orderItemId, ['pending', 'packed']);

    return max(0, $reserved - $committed);
}

/**
 * How much of one customer_storage lot is currently stored AND not already committed to
 * another open shipment - the preorder/early_bird (and general storage) equivalent of
 * shipment_order_item_available_to_ship().
 */
function shipment_storage_lot_available_to_ship(PDO $pdo, int $customerStorageId): int
{
    $stmt = $pdo->prepare('SELECT quantity, status FROM customer_storage WHERE id = ?');
    $stmt->execute([$customerStorageId]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lot || $lot['status'] !== 'stored') {
        return 0;
    }

    $committed = shipment_item_committed_quantity($pdo, 'customer_storage_id', $customerStorageId, ['pending', 'packed']);

    return max(0, (int) $lot['quantity'] - $committed);
}

/**
 * Creates a shipment (status 'pending') and its lines - pure record-keeping, no ledger
 * writes at all (see shipment_mark_shipped() for where consumption actually happens). Every
 * line is validated against shipment_order_item_available_to_ship()/
 * shipment_storage_lot_available_to_ship() so a unit can never be over-committed across
 * concurrent shipments.
 *
 * $items: list of ['order_item_id' => ?int, 'customer_storage_id' => ?int,
 * 'product_id' => ?int, 'variation_id' => ?int, 'quantity' => int]. At least one of
 * order_item_id/customer_storage_id/product_id must be given per line; product_id is only
 * required standalone for a 'manual' line with neither of the other two.
 */
function shipment_create(PDO $pdo, int $customerId, string $sourceType, ?int $sourceId, array $items): int
{
    if (!in_array($sourceType, SHIPMENT_SOURCE_TYPES, true)) {
        throw new RuntimeException('Invalid shipment source type.');
    }
    if ($items === []) {
        throw new RuntimeException('A shipment needs at least one item.');
    }
    if ($sourceType === 'order') {
        if ($sourceId === null) {
            throw new RuntimeException('An order-sourced shipment requires an order.');
        }
        shipment_assert_order_not_historical($pdo, $sourceId);
    }

    $shipmentNumber = shipment_generate_number();

    $shipmentStmt = $pdo->prepare("
        INSERT INTO shipments (shipment_number, customer_id, source_type, source_id, shipping_status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $shipmentStmt->execute([$shipmentNumber, $customerId, $sourceType, $sourceId]);
    $shipmentId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare('
        INSERT INTO shipment_items (shipment_id, order_id, order_item_id, customer_storage_id, product_id, variation_id, quantity)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');

    $touchedOrderIds = [];

    foreach ($items as $line) {
        $quantity = (int) ($line['quantity'] ?? 0);
        if ($quantity < 1) {
            throw new RuntimeException('Every shipment line needs a quantity of at least 1.');
        }

        $orderItemId = isset($line['order_item_id']) && $line['order_item_id'] !== null ? (int) $line['order_item_id'] : null;
        $customerStorageId = isset($line['customer_storage_id']) && $line['customer_storage_id'] !== null ? (int) $line['customer_storage_id'] : null;
        $orderId = null;
        $productId = isset($line['product_id']) && $line['product_id'] !== null ? (int) $line['product_id'] : null;
        $variationId = isset($line['variation_id']) && $line['variation_id'] !== null ? (int) $line['variation_id'] : null;

        if ($orderItemId !== null) {
            $oiStmt = $pdo->prepare('
                SELECT oi.order_id, oi.product_id, oi.variation_id, o.is_historical
                FROM mewmii_order_items oi
                INNER JOIN mewmii_orders o ON o.id = oi.order_id
                WHERE oi.id = ?
            ');
            $oiStmt->execute([$orderItemId]);
            $oiRow = $oiStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oiRow) {
                throw new RuntimeException('Order item not found.');
            }
            if (!empty($oiRow['is_historical'])) {
                throw new RuntimeException('This order item belongs to a historical (imported) order and cannot be shipped.');
            }

            $orderId = (int) $oiRow['order_id'];
            $productId = (int) $oiRow['product_id'];
            $variationId = $oiRow['variation_id'] !== null ? (int) $oiRow['variation_id'] : null;

            // The reserved-stock check only applies to a pure ready-stock line (no
            // customer_storage_id set) - a storage-backed line (preorder/early_bird, or any
            // line built from an existing lot, e.g. via Ship My Box - see
            // includes/ship_my_box.php, which always passes both ids together) is validated
            // against the lot itself below instead, since preorder/early_bird items are
            // never reserved via inventory_net_reserved().
            if ($customerStorageId === null) {
                $availableToShip = shipment_order_item_available_to_ship($pdo, $orderItemId);
                if ($quantity > $availableToShip) {
                    throw new RuntimeException(catalog_format_stock_error($pdo, 'Requested quantity exceeds this item\'s reserved, available-to-ship amount.', $productId, $variationId, 'Available to ship', $availableToShip, $quantity));
                }
            }
        }

        if ($customerStorageId !== null) {
            $storageStmt = $pdo->prepare('SELECT customer_id, product_id, variation_id, order_item_id FROM customer_storage WHERE id = ?');
            $storageStmt->execute([$customerStorageId]);
            $storageRow = $storageStmt->fetch(PDO::FETCH_ASSOC);

            if (!$storageRow) {
                throw new RuntimeException('Storage record not found.');
            }
            if ((int) $storageRow['customer_id'] !== $customerId) {
                throw new RuntimeException('Storage record does not belong to this customer.');
            }

            $productId = (int) $storageRow['product_id'];
            $variationId = $storageRow['variation_id'] !== null ? (int) $storageRow['variation_id'] : null;

            if ($orderItemId === null && $storageRow['order_item_id'] !== null) {
                $orderItemId = (int) $storageRow['order_item_id'];
                $oiOrderStmt = $pdo->prepare('
                    SELECT oi.order_id, o.is_historical
                    FROM mewmii_order_items oi
                    INNER JOIN mewmii_orders o ON o.id = oi.order_id
                    WHERE oi.id = ?
                ');
                $oiOrderStmt->execute([$orderItemId]);
                $oiOrderRow = $oiOrderStmt->fetch(PDO::FETCH_ASSOC);

                if ($oiOrderRow) {
                    if (!empty($oiOrderRow['is_historical'])) {
                        throw new RuntimeException('This storage lot belongs to a historical (imported) order and cannot be shipped.');
                    }
                    $orderId = (int) $oiOrderRow['order_id'];
                }
            }

            $availableToShip = shipment_storage_lot_available_to_ship($pdo, $customerStorageId);
            if ($quantity > $availableToShip) {
                throw new RuntimeException(catalog_format_stock_error($pdo, 'Requested quantity exceeds this storage lot\'s available-to-ship amount.', $productId, $variationId, 'Available to ship', $availableToShip, $quantity));
            }
        }

        if ($productId === null) {
            throw new RuntimeException('Every shipment line needs a product.');
        }

        $itemStmt->execute([$shipmentId, $orderId, $orderItemId, $customerStorageId, $productId, $variationId, $quantity]);

        if ($orderId !== null) {
            $touchedOrderIds[$orderId] = true;
        }
    }

    shipment_log_event($pdo, $shipmentId, 'shipment_created', count($items) . ' line(s).');
    activity_log($pdo, 'shipments', 'create', $shipmentId, 'Created shipment ' . $shipmentNumber);

    foreach (array_keys($touchedOrderIds) as $orderId) {
        order_recompute_status($pdo, (int) $orderId);
    }

    return $shipmentId;
}

function shipment_mark_packed(PDO $pdo, int $shipmentId): void
{
    $stmt = $pdo->prepare('SELECT shipping_status FROM shipments WHERE id = ? FOR UPDATE');
    $stmt->execute([$shipmentId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('Shipment not found.');
    }
    if ($status !== 'pending') {
        throw new RuntimeException('Only a Pending shipment can be marked Packed.');
    }

    $pdo->prepare("UPDATE shipments SET shipping_status = 'packed' WHERE id = ?")->execute([$shipmentId]);
    shipment_log_event($pdo, $shipmentId, 'packed');
}

/**
 * The one place a shipment's contents are actually consumed from the ledger. Storage-backed
 * lines (customer_storage_id set - preorder/early_bird, or any manual line built from an
 * existing storage lot) are consumed exactly like ship_request_process() already does:
 * decrement the lot and mewmii_inventory.customer_storage_quantity, log a 'ship_my_box'
 * transaction - stock never returns to available, it has left for good. Ready-stock lines
 * (order_item_id + order_id set, no customer_storage_id) go through the shared
 * inventory_ship_order_quantity() core. A pure manual line (neither set) has nothing to
 * consume - the shipment record itself is still created for tracking/reporting.
 */
function shipment_mark_shipped(PDO $pdo, int $shipmentId, ?string $carrier, ?string $trackingNumber, ?string $shippedAt = null): void
{
    $stmt = $pdo->prepare('SELECT * FROM shipments WHERE id = ? FOR UPDATE');
    $stmt->execute([$shipmentId]);
    $shipment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shipment) {
        throw new RuntimeException('Shipment not found.');
    }
    if (!in_array($shipment['shipping_status'], ['pending', 'packed'], true)) {
        throw new RuntimeException('This shipment has already been shipped, delivered, or cancelled.');
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM shipment_items WHERE shipment_id = ?');
    $itemsStmt->execute([$shipmentId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($items === []) {
        throw new RuntimeException('This shipment has no items.');
    }

    $touchedOrderIds = [];

    foreach ($items as $item) {
        $quantity = (int) $item['quantity'];
        $productId = (int) $item['product_id'];
        $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;

        if ($item['customer_storage_id'] !== null) {
            $storageId = (int) $item['customer_storage_id'];
            $storageStmt = $pdo->prepare('SELECT * FROM customer_storage WHERE id = ? FOR UPDATE');
            $storageStmt->execute([$storageId]);
            $storageRow = $storageStmt->fetch(PDO::FETCH_ASSOC);

            if (!$storageRow || $storageRow['status'] !== 'stored' || (int) $storageRow['quantity'] < $quantity) {
                throw new RuntimeException(catalog_format_stock_error($pdo, 'Insufficient stored quantity to ship.', $productId, $variationId, 'Customer storage quantity', $storageRow ? (int) $storageRow['quantity'] : 0, $quantity));
            }

            $remaining = (int) $storageRow['quantity'] - $quantity;
            $newStatus = $remaining > 0 ? 'stored' : 'shipped';

            $pdo->prepare('UPDATE customer_storage SET quantity = ?, status = ? WHERE id = ?')
                ->execute([$remaining, $newStatus, $storageId]);

            inventory_get_or_create_row($pdo, $productId, $variationId);
            $pdo->prepare('
                UPDATE mewmii_inventory
                SET customer_storage_quantity = GREATEST(customer_storage_quantity - ?, 0)
                WHERE product_id = ? AND variation_id <=> ?
            ')->execute([$quantity, $productId, $variationId]);

            inventory_log_transaction($pdo, $productId, 'ship_my_box', $quantity, 'shipment_item', (int) $item['id'], $variationId);
        } elseif ($item['order_item_id'] !== null && $item['order_id'] !== null) {
            inventory_ship_order_quantity($pdo, (int) $item['order_id'], $productId, $variationId, $quantity);
        }
        // else: a 'manual' shipment line with no backing inventory record - nothing to consume.

        if ($item['order_id'] !== null) {
            $touchedOrderIds[(int) $item['order_id']] = true;
        }
    }

    $shippedAt = $shippedAt !== null && $shippedAt !== '' ? $shippedAt : app_now();

    $pdo->prepare("
        UPDATE shipments SET carrier = ?, tracking_number = ?, shipping_status = 'shipped', shipped_at = ? WHERE id = ?
    ")->execute([$carrier, $trackingNumber, $shippedAt, $shipmentId]);

    shipment_log_event($pdo, $shipmentId, 'shipped', $trackingNumber !== null && $trackingNumber !== '' ? ('Tracking: ' . $trackingNumber) : null);

    foreach (array_keys($touchedOrderIds) as $orderId) {
        order_recompute_status($pdo, (int) $orderId);
    }
}

function shipment_mark_delivered(PDO $pdo, int $shipmentId): void
{
    $stmt = $pdo->prepare('SELECT shipping_status FROM shipments WHERE id = ? FOR UPDATE');
    $stmt->execute([$shipmentId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('Shipment not found.');
    }
    if ($status !== 'shipped') {
        throw new RuntimeException('Only a Shipped shipment can be marked Delivered.');
    }

    $pdo->prepare("UPDATE shipments SET shipping_status = 'delivered' WHERE id = ?")->execute([$shipmentId]);
    shipment_log_event($pdo, $shipmentId, 'delivered');

    foreach (shipment_order_ids($pdo, $shipmentId) as $orderId) {
        order_recompute_status($pdo, $orderId);
    }
}

/**
 * Only allowed before anything has physically shipped (pending/packed) - once a shipment
 * reaches 'shipped' its contents have already left the warehouse via
 * shipment_mark_shipped(), which can't safely un-happen (same eligibility rule as
 * supplier_order_cancel()). Nothing needs reversing here: shipment_create() never touched
 * reserved_quantity/customer_storage itself, so cancelling before shipping simply frees the
 * items up to be picked into a future shipment again.
 */
function shipment_cancel(PDO $pdo, int $shipmentId): void
{
    $stmt = $pdo->prepare('SELECT shipping_status FROM shipments WHERE id = ? FOR UPDATE');
    $stmt->execute([$shipmentId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('Shipment not found.');
    }
    if (!in_array($status, ['pending', 'packed'], true)) {
        throw new RuntimeException('Only a Pending or Packed shipment can be cancelled - once a shipment has shipped, its contents have physically left the warehouse.');
    }

    $pdo->prepare("UPDATE shipments SET shipping_status = 'cancelled' WHERE id = ?")->execute([$shipmentId]);
    shipment_log_event($pdo, $shipmentId, 'cancelled');

    foreach (shipment_order_ids($pdo, $shipmentId) as $orderId) {
        order_recompute_status($pdo, $orderId);
    }
}

/**
 * Edits an existing shipment's carrier/tracking number (e.g. correcting a typo, or filling
 * tracking in once known after creation) - logs 'carrier_changed'/'tracking_updated' only
 * for whichever value actually changed, same convention as supplier_order_apply_edit()'s
 * shipping-fee/payment-status change logging.
 */
function shipment_update_tracking(PDO $pdo, int $shipmentId, ?string $carrier, ?string $trackingNumber): void
{
    $stmt = $pdo->prepare('SELECT carrier, tracking_number FROM shipments WHERE id = ?');
    $stmt->execute([$shipmentId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        throw new RuntimeException('Shipment not found.');
    }

    $carrier = $carrier !== null && $carrier !== '' ? $carrier : null;
    $trackingNumber = $trackingNumber !== null && $trackingNumber !== '' ? $trackingNumber : null;

    $pdo->prepare('UPDATE shipments SET carrier = ?, tracking_number = ? WHERE id = ?')
        ->execute([$carrier, $trackingNumber, $shipmentId]);

    if ($current['carrier'] !== $carrier) {
        shipment_log_event($pdo, $shipmentId, 'carrier_changed', ($current['carrier'] ?? '(none)') . ' -> ' . ($carrier ?? '(none)'));
    }
    if ($current['tracking_number'] !== $trackingNumber) {
        shipment_log_event($pdo, $shipmentId, 'tracking_updated', ($current['tracking_number'] ?? '(none)') . ' -> ' . ($trackingNumber ?? '(none)'));
    }
}

function shipment_get(PDO $pdo, int $shipmentId): ?array
{
    $stmt = $pdo->prepare('
        SELECT s.*, c.name AS customer_name, c.email AS customer_email
        FROM shipments s
        INNER JOIN customers c ON c.id = s.customer_id
        WHERE s.id = ?
        LIMIT 1
    ');
    $stmt->execute([$shipmentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shipment_list_items(PDO $pdo, int $shipmentId): array
{
    $stmt = $pdo->prepare("
        SELECT si.*, COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name, o.order_number
        FROM shipment_items si
        INNER JOIN products p ON p.id = si.product_id
        LEFT JOIN product_variations pv ON pv.id = si.variation_id
        LEFT JOIN mewmii_orders o ON o.id = si.order_id
        WHERE si.shipment_id = ?
        ORDER BY si.id ASC
    ");
    $stmt->execute([$shipmentId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $item['variation_label'] = $item['variation_id'] !== null ? variation_build_label($pdo, (int) $item['variation_id']) : '';
    }
    unset($item);

    return $items;
}

function shipment_list_events(PDO $pdo, int $shipmentId): array
{
    $stmt = $pdo->prepare('
        SELECT se.event_type, se.notes, se.created_at, u.name AS user_name
        FROM shipment_events se
        LEFT JOIN users u ON u.id = se.created_by
        WHERE se.shipment_id = ?
        ORDER BY se.created_at DESC, se.id DESC
    ');
    $stmt->execute([$shipmentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function shipment_list_all(PDO $pdo, int $limit = 200): array
{
    $stmt = $pdo->prepare('
        SELECT s.id, s.shipment_number, s.source_type, s.carrier, s.tracking_number, s.shipping_status, s.shipped_at, s.created_at, c.name AS customer_name
        FROM shipments s
        INNER JOIN customers c ON c.id = s.customer_id
        ORDER BY s.created_at DESC
        LIMIT ' . (int) $limit . '
    ');
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every shipment belonging to one customer, with the distinct order numbers and total item
 * quantity it carries - feeds the "My Shipments" section of modules/customers/view.php.
 */
function shipment_list_for_customer(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('
        SELECT s.id, s.shipment_number, s.source_type, s.carrier, s.tracking_number, s.shipping_status, s.shipped_at, s.created_at
        FROM shipments s
        WHERE s.customer_id = ?
        ORDER BY s.created_at DESC
    ');
    $stmt->execute([$customerId]);
    $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($shipments as &$shipment) {
        $shipmentId = (int) $shipment['id'];

        $ordersStmt = $pdo->prepare('
            SELECT DISTINCT o.id, o.order_number
            FROM shipment_items si
            INNER JOIN mewmii_orders o ON o.id = si.order_id
            WHERE si.shipment_id = ?
            ORDER BY o.order_number ASC
        ');
        $ordersStmt->execute([$shipmentId]);
        $shipment['orders'] = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

        $itemCountStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM shipment_items WHERE shipment_id = ?');
        $itemCountStmt->execute([$shipmentId]);
        $shipment['item_count'] = (int) $itemCountStmt->fetchColumn();
    }
    unset($shipment);

    return $shipments;
}
