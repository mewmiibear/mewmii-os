<?php

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/product_variations.php';

/**
 * Ship every item on a ship request: consumes the underlying customer_storage lots
 * (marking a lot 'shipped' once its quantity reaches zero), decrements
 * mewmii_inventory.customer_storage_quantity for the correct product/variation, and logs
 * inventory_transactions. Unlike the generic customer-storage "Remove" action, stock does
 * not return to available_quantity here - it has left the warehouse for good.
 */
function ship_request_process(PDO $pdo, int $shipRequestId): void
{
    $itemsStmt = $pdo->prepare('SELECT id, customer_storage_id, quantity FROM ship_request_items WHERE ship_request_id = ?');
    $itemsStmt->execute([$shipRequestId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($items === []) {
        throw new RuntimeException('This ship request has no items.');
    }

    foreach ($items as $item) {
        $requestedQty = (int) $item['quantity'];
        $storageId = (int) $item['customer_storage_id'];

        $storageStmt = $pdo->prepare('SELECT * FROM customer_storage WHERE id = ? FOR UPDATE');
        $storageStmt->execute([$storageId]);
        $storageRow = $storageStmt->fetch(PDO::FETCH_ASSOC);

        if (!$storageRow) {
            throw new RuntimeException('One of the items on this ship request no longer has a matching storage record.');
        }

        $productId = (int) $storageRow['product_id'];
        $variationId = isset($storageRow['variation_id']) && $storageRow['variation_id'] !== null ? (int) $storageRow['variation_id'] : null;

        if ($storageRow['status'] !== 'stored' || (int) $storageRow['quantity'] < $requestedQty) {
            throw new RuntimeException(catalog_format_stock_error($pdo, 'Insufficient stored quantity.', $productId, $variationId, 'Stored quantity', (int) $storageRow['quantity'], $requestedQty));
        }

        $remaining = (int) $storageRow['quantity'] - $requestedQty;
        $newStatus = $remaining > 0 ? 'stored' : 'shipped';

        $pdo->prepare('UPDATE customer_storage SET quantity = ?, status = ? WHERE id = ?')
            ->execute([$remaining, $newStatus, $storageId]);

        inventory_get_or_create_row($pdo, $productId, $variationId);

        $pdo->prepare('
            UPDATE mewmii_inventory
            SET customer_storage_quantity = GREATEST(customer_storage_quantity - ?, 0)
            WHERE product_id = ? AND variation_id <=> ?
        ')->execute([$requestedQty, $productId, $variationId]);

        inventory_log_transaction($pdo, $productId, 'ship_my_box', $requestedQty, 'ship_request_item', (int) $item['id'], $variationId);
    }
}
