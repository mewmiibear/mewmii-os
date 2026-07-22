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
        $currentQtyLabel = $debitFrom === 'available' ? 'Available quantity' : (ucfirst($debitFrom) . ' quantity available');
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
        throw new RuntimeException(catalog_format_stock_error($pdo, 'Cannot remove more than the stored quantity.', $productId, $variationId, 'Stored quantity', (int) $storageRow['quantity'], $quantity));
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
