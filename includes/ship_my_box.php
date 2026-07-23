<?php

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/product_variations.php';
require_once __DIR__ . '/shipments.php';

function ship_request_generate_number(): string
{
    return 'SB-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

/**
 * Ship every item on a ship request by creating a unified shipment (source_type =
 * 'ship_my_box') from its lines and handing it to shipment_create()/shipment_mark_shipped()
 * (includes/shipments.php) - the actual storage-lot consumption (decrementing the lot,
 * mewmii_inventory.customer_storage_quantity, and logging inventory_transactions) lives
 * there, in exactly one place, rather than a second copy of that ledger math here. Returns
 * the new shipment's id.
 */
function ship_request_process(PDO $pdo, int $shipRequestId, ?string $carrier = null, ?string $trackingNumber = null): int
{
    $requestStmt = $pdo->prepare('SELECT customer_id FROM ship_requests WHERE id = ?');
    $requestStmt->execute([$shipRequestId]);
    $customerId = $requestStmt->fetchColumn();

    if ($customerId === false) {
        throw new RuntimeException('Ship request not found.');
    }

    $itemsStmt = $pdo->prepare('SELECT customer_storage_id, order_item_id, quantity FROM ship_request_items WHERE ship_request_id = ?');
    $itemsStmt->execute([$shipRequestId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($items === []) {
        throw new RuntimeException('This ship request has no items.');
    }

    $lines = [];
    foreach ($items as $item) {
        $storageId = (int) $item['customer_storage_id'];
        $storageStmt = $pdo->prepare('SELECT product_id, variation_id FROM customer_storage WHERE id = ?');
        $storageStmt->execute([$storageId]);
        $storageRow = $storageStmt->fetch(PDO::FETCH_ASSOC);

        if (!$storageRow) {
            throw new RuntimeException('One of the items on this ship request no longer has a matching storage record.');
        }

        $lines[] = [
            'order_item_id' => $item['order_item_id'] !== null ? (int) $item['order_item_id'] : null,
            'customer_storage_id' => $storageId,
            'product_id' => (int) $storageRow['product_id'],
            'variation_id' => $storageRow['variation_id'] !== null ? (int) $storageRow['variation_id'] : null,
            'quantity' => (int) $item['quantity'],
        ];
    }

    $shipmentId = shipment_create($pdo, (int) $customerId, 'ship_my_box', $shipRequestId, $lines);
    shipment_mark_shipped($pdo, $shipmentId, $carrier, $trackingNumber);

    return $shipmentId;
}
