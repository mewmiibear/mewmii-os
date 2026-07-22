<?php

require_once __DIR__ . '/inventory.php';

/**
 * Move stock from general available inventory into a customer's storage.
 * Creates a new customer_storage lot rather than merging into an existing one,
 * so each add stays traceable as its own history entry.
 */
function customer_storage_add(PDO $pdo, int $customerId, int $productId, int $quantity, ?string $arrivalDate): int
{
    if ($quantity < 1) {
        throw new RuntimeException('Quantity must be at least 1.');
    }

    $row = inventory_get_or_create_row($pdo, $productId);

    if ((int) $row['available_quantity'] < $quantity) {
        throw new RuntimeException('Insufficient available stock to move into customer storage.');
    }

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET available_quantity = available_quantity - ?, customer_storage_quantity = customer_storage_quantity + ?
        WHERE product_id = ?
    ')->execute([$quantity, $quantity, $productId]);

    $stmt = $pdo->prepare("
        INSERT INTO customer_storage (customer_id, product_id, quantity, status, arrival_date)
        VALUES (?, ?, ?, 'stored', ?)
    ");
    $stmt->execute([$customerId, $productId, $quantity, $arrivalDate ?: null]);
    $storageId = (int) $pdo->lastInsertId();

    inventory_log_transaction($pdo, $productId, 'customer_storage_add', $quantity, 'customer_storage', $storageId);

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

    if ($storageRow['status'] !== 'stored') {
        throw new RuntimeException('This storage record is no longer active.');
    }

    if ($quantity > (int) $storageRow['quantity']) {
        throw new RuntimeException('Cannot remove more than the stored quantity.');
    }

    $productId = (int) $storageRow['product_id'];
    $remaining = (int) $storageRow['quantity'] - $quantity;
    $newStatus = $remaining > 0 ? 'stored' : 'shipped';

    $pdo->prepare('UPDATE customer_storage SET quantity = ?, status = ? WHERE id = ?')
        ->execute([$remaining, $newStatus, $storageId]);

    inventory_get_or_create_row($pdo, $productId);

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET customer_storage_quantity = GREATEST(customer_storage_quantity - ?, 0),
            available_quantity = available_quantity + ?
        WHERE product_id = ?
    ')->execute([$quantity, $quantity, $productId]);

    inventory_log_transaction($pdo, $productId, 'customer_storage_remove', $quantity, 'customer_storage', $storageId);
}
