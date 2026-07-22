<?php

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/product_variations.php';

/**
 * Move stock into a customer's storage. Creates a new customer_storage lot rather than
 * merging into an existing one, so each add stays traceable as its own history entry.
 * $variationId is null for a simple product, or the specific variation being stored.
 *
 * $debitFrom controls which mewmii_inventory bucket the quantity is taken from:
 * 'available' (default) is the normal manual "move ready stock into storage" flow used by
 * the Customer Storage page. 'incoming' pulls from stock still marked as ordered-but-not-
 * received. 'arrived' pulls from stock that has been received but not yet allocated (see
 * modules/inventory/allocate.php - the manual customer-order allocation step for received
 * preorder/early-bird stock). $orderItemId, when set, records which order this lot
 * fulfilled so it's never matched a second time.
 */
function customer_storage_add(PDO $pdo, int $customerId, int $productId, int $quantity, ?string $arrivalDate, ?int $variationId = null, ?int $orderItemId = null, string $debitFrom = 'available'): int
{
    if ($quantity < 1) {
        throw new RuntimeException('Quantity must be at least 1.');
    }

    if (!in_array($debitFrom, ['available', 'incoming', 'arrived'], true)) {
        throw new RuntimeException('Invalid inventory source.');
    }

    $sourceColumn = match ($debitFrom) {
        'incoming' => 'incoming_quantity',
        'arrived' => 'arrived_quantity',
        default => 'available_quantity',
    };
    $row = inventory_get_or_create_row($pdo, $productId, $variationId);

    if ((int) $row[$sourceColumn] < $quantity) {
        $currentQtyLabel = ucfirst($debitFrom) . ' quantity';
        throw new RuntimeException(catalog_format_stock_error($pdo, 'Insufficient ' . $debitFrom . ' stock.', $productId, $variationId, $currentQtyLabel, (int) $row[$sourceColumn], $quantity));
    }

    $pdo->prepare("
        UPDATE mewmii_inventory
        SET {$sourceColumn} = {$sourceColumn} - ?, customer_storage_quantity = customer_storage_quantity + ?
        WHERE product_id = ? AND variation_id <=> ?
    ")->execute([$quantity, $quantity, $productId, $variationId]);

    $stmt = $pdo->prepare("
        INSERT INTO customer_storage (customer_id, product_id, variation_id, order_item_id, quantity, status, arrival_date)
        VALUES (?, ?, ?, ?, ?, 'stored', ?)
    ");
    $stmt->execute([$customerId, $productId, $variationId, $orderItemId, $quantity, $arrivalDate ?: null]);
    $storageId = (int) $pdo->lastInsertId();

    inventory_log_transaction($pdo, $productId, 'customer_storage_add', $quantity, 'customer_storage', $storageId, $variationId);

    return $storageId;
}

// --- Preorder Allocation Center: read-only queue queries + FIFO allocator ----------------
// Everything below is a workflow layer on top of the existing ledger - it only ever reads
// inventory_transactions/mewmii_order_items, and the one write path (inventory_allocate_fifo())
// is a thin orchestration loop around the unchanged customer_storage_add(), never a new way
// of moving stock. See modules/inventory/allocation-center.php and modules/inventory/allocate.php.

/**
 * Total units ever received into the "arrived" bucket for one unit (product or specific
 * variation) - i.e. every supplier_receive transaction logged against it (see
 * supplier_order_receive_preorder_quantity()). A historical total, distinct from the
 * current live arrived_quantity balance (which shrinks as stock gets allocated/released).
 */
function inventory_unit_arrived_total(PDO $pdo, int $productId, ?int $variationId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0) FROM inventory_transactions
        WHERE product_id = ? AND variation_id <=> ? AND transaction_type = 'supplier_receive'
    ");
    $stmt->execute([$productId, $variationId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Total units ever moved into Customer Storage for one unit (every customer_storage_add
 * transaction) - the historical "Allocated" total shown in the Allocation Center, as
 * opposed to what's still sitting unallocated (mewmii_inventory.arrived_quantity).
 */
function inventory_unit_allocated_total(PDO $pdo, int $productId, ?int $variationId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0) FROM inventory_transactions
        WHERE product_id = ? AND variation_id <=> ? AND transaction_type = 'customer_storage_add'
    ");
    $stmt->execute([$productId, $variationId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Total outstanding demand for one unit: the sum, across every non-cancelled order item
 * for it, of (ordered quantity - already allocated) - reusing
 * supplier_order_item_customer_storage_allocated() (unchanged) per item rather than
 * re-deriving that math. This is the "Need Allocate" figure in the Allocation Center.
 */
function inventory_unit_outstanding_demand(PDO $pdo, int $productId, ?int $variationId): int
{
    $stmt = $pdo->prepare("
        SELECT oi.id, oi.quantity
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE oi.product_id = ? AND oi.variation_id <=> ? AND o.order_status <> 'cancelled'
    ");
    $stmt->execute([$productId, $variationId]);

    $total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $outstanding = (int) $item['quantity'] - supplier_order_item_customer_storage_allocated($pdo, (int) $item['id']);
        if ($outstanding > 0) {
            $total += $outstanding;
        }
    }

    return $total;
}

/**
 * The full Preorder Allocation Center queue (see modules/inventory/allocation-center.php):
 * every preorder/early_bird unit that currently has BOTH arrived stock sitting unallocated
 * AND outstanding customer demand for it - the exact three-part definition from the spec
 * ("supplier stock received AND customers waiting AND not yet received"). Ready Stock never
 * appears here at all, by construction: it never routes supplier receiving into
 * arrived_quantity in the first place (see supplier_order_receive_item()), only
 * preorder/early_bird does.
 */
function inventory_allocation_queue(PDO $pdo): array
{
    $productsStmt = $pdo->query("
        SELECT id, sku, name, catalog_type, product_type
        FROM products
        WHERE product_type IN ('preorder', 'early_bird')
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
            $remaining = (int) $inventoryRow['arrived_quantity'];

            if ($remaining < 1) {
                continue;
            }

            $needAllocate = inventory_unit_outstanding_demand($pdo, $productId, $variationId);
            if ($needAllocate < 1) {
                continue;
            }

            $units[] = [
                'variation_id' => $variationId,
                'sku' => $unitRow['sku'],
                'label' => $unitRow['label'],
                'arrived_total' => inventory_unit_arrived_total($pdo, $productId, $variationId),
                'allocated_total' => inventory_unit_allocated_total($pdo, $productId, $variationId),
                'need_allocate' => $needAllocate,
                'remaining' => $remaining,
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
 * Records that a preorder/early-bird item has arrived and been stored, on the order's own
 * timeline (mewmii_order_events - already shown on modules/orders/view.php) - the closest
 * thing this app has today to a customer-facing notification channel. There is no SMS/
 * email/push delivery mechanism anywhere in this codebase yet, so this is deliberately a
 * visible log entry an admin can see and act on, not an actual message sent to the
 * customer - see modules/inventory/allocate.php for where this gets called, and the
 * "Future Ready" note in the Allocation Center spec this implements.
 */
function inventory_log_allocation_event(PDO $pdo, int $orderId, string $description): void
{
    $pdo->prepare('
        INSERT INTO mewmii_order_events (order_id, event_type, description, created_by)
        VALUES (?, ?, ?, ?)
    ')->execute([$orderId, 'preorder_allocated', $description, $_SESSION['user_id'] ?? null]);
}

/**
 * Automatic FIFO allocation (Allocation Center's "Option A"): fills the oldest outstanding
 * orders first from the unit's current arrived_quantity, until either demand or supply
 * runs out. Pure orchestration around the unchanged customer_storage_add() - allocates
 * nothing itself, just decides in what order to call it. Returns the list of allocations
 * made (order id/number/quantity) so the caller can log a per-order event and show a
 * summary. Caller is responsible for the surrounding transaction.
 */
function inventory_allocate_fifo(PDO $pdo, int $productId, ?int $variationId): array
{
    $row = inventory_get_or_create_row($pdo, $productId, $variationId);
    $budget = (int) $row['arrived_quantity'];

    if ($budget < 1) {
        return [];
    }

    $candidatesStmt = $pdo->prepare("
        SELECT oi.id AS order_item_id, oi.quantity, o.id AS order_id, o.order_number, o.customer_id, o.order_status
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE oi.product_id = ? AND oi.variation_id <=> ? AND o.order_status <> 'cancelled'
        ORDER BY o.order_date ASC, o.id ASC, oi.id ASC
    ");
    $candidatesStmt->execute([$productId, $variationId]);

    $allocations = [];
    foreach ($candidatesStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if ($budget < 1) {
            break;
        }
        if ((int) $candidate['customer_id'] < 1) {
            continue;
        }

        $outstanding = (int) $candidate['quantity'] - supplier_order_item_customer_storage_allocated($pdo, (int) $candidate['order_item_id']);
        if ($outstanding < 1) {
            continue;
        }

        $qty = min($outstanding, $budget);
        customer_storage_add($pdo, (int) $candidate['customer_id'], $productId, $qty, null, $variationId, (int) $candidate['order_item_id'], 'arrived');

        $allocations[] = ['order_id' => (int) $candidate['order_id'], 'order_number' => $candidate['order_number'], 'quantity' => $qty];
        $budget -= $qty;
    }

    return $allocations;
}

/**
 * Take units out of an existing stored lot, returning them to available inventory.
 * Marks the lot 'shipped' once its quantity reaches zero, preserving it as history
 * rather than deleting it.
 */
function customer_storage_remove(PDO $pdo, int $storageId, int $quantity): void
{
    if ($quantity < 1) {
        throw new RuntimeException('Quantity must be at least 1.');
    }

    $stmt = $pdo->prepare('SELECT * FROM customer_storage WHERE id = ? FOR UPDATE');
    $stmt->execute([$storageId]);
    $storageRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$storageRow) {
        throw new RuntimeException('Storage record not found.');
    }

    $productId = (int) $storageRow['product_id'];
    $variationId = isset($storageRow['variation_id']) && $storageRow['variation_id'] !== null ? (int) $storageRow['variation_id'] : null;

    if ($storageRow['status'] !== 'stored') {
        $unit = catalog_describe_unit($pdo, $productId, $variationId);
        throw new RuntimeException('This storage record is no longer active: ' . $unit['product_name'] . ($unit['product_sku'] !== null ? (' (SKU: ' . $unit['product_sku'] . ')') : '') . (!empty($unit['variation_label']) ? (', variation ' . $unit['variation_label']) : '') . '.');
    }

    if ($quantity > (int) $storageRow['quantity']) {
        throw new RuntimeException(catalog_format_stock_error($pdo, 'Cannot remove more than the stored quantity.', $productId, $variationId, 'Customer storage quantity', (int) $storageRow['quantity'], $quantity));
    }

    $remaining = (int) $storageRow['quantity'] - $quantity;
    $newStatus = $remaining > 0 ? 'stored' : 'shipped';

    $pdo->prepare('UPDATE customer_storage SET quantity = ?, status = ? WHERE id = ?')
        ->execute([$remaining, $newStatus, $storageId]);

    inventory_get_or_create_row($pdo, $productId, $variationId);

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET customer_storage_quantity = GREATEST(customer_storage_quantity - ?, 0),
            available_quantity = available_quantity + ?
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$quantity, $quantity, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'customer_storage_remove', $quantity, 'customer_storage', $storageId, $variationId);
}
