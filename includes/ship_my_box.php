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

/**
 * Total quantity already committed to an OPEN (not yet shipped) ship request for one
 * customer_storage lot - the pre-shipment mirror of shipment_item_committed_quantity()
 * (includes/shipments.php), which only sees a commitment once a real shipment exists. 'Open'
 * means ship_requests.status IN ('pending', 'processing') - the two statuses before
 * ship_request_process() actually creates+ships a shipment and consumes the lot for real
 * (see modules/ship-my-box/view.php). A 'shipped'/'completed' ship request has already
 * decremented customer_storage.quantity directly via shipment_mark_shipped(), so counting it
 * here too would double-subtract the same units.
 */
function ship_request_lot_committed_quantity(PDO $pdo, int $customerStorageId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(sri.quantity), 0)
        FROM ship_request_items sri
        INNER JOIN ship_requests sr ON sr.id = sri.ship_request_id
        WHERE sri.customer_storage_id = ? AND sr.status IN ('pending', 'processing')
    ");
    $stmt->execute([$customerStorageId]);

    return (int) $stmt->fetchColumn();
}

/**
 * How much of one customer_storage lot is still free to commit to a NEW ship request (or to
 * remove via customer_storage_remove()) - the lot's own quantity minus whatever other open
 * ship requests already have a claim on it. Same pattern as
 * shipment_storage_lot_available_to_ship() (includes/shipments.php), one stage earlier in
 * the pipeline, before a shipment exists at all. Returns 0 for a lot that no longer exists or
 * isn't 'stored' - same convention as shipment_storage_lot_available_to_ship().
 */
function ship_request_storage_lot_available(PDO $pdo, int $customerStorageId): int
{
    $stmt = $pdo->prepare('SELECT quantity, status FROM customer_storage WHERE id = ?');
    $stmt->execute([$customerStorageId]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lot || $lot['status'] !== 'stored') {
        return 0;
    }

    $committed = ship_request_lot_committed_quantity($pdo, $customerStorageId);

    return max(0, (int) $lot['quantity'] - $committed);
}

function ship_request_status_label(string $status): string
{
    $labels = [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'completed' => 'Completed',
    ];

    return $labels[$status] ?? ucfirst($status);
}

function ship_request_status_emoji(string $status): string
{
    $emoji = [
        'pending' => '🟡',
        'processing' => '🔵',
        'shipped' => '🟢',
        'completed' => '✅',
    ];

    return $emoji[$status] ?? '⚪';
}

/**
 * The single next status-changing action available from $status - drives the one-button
 * workflow on modules/ship-my-box/view.php so the system controls the transition instead of
 * an admin picking freely from a dropdown. Returns null once there's nothing left to change
 * (a terminal/unrecognised status). 'needs_tracking' marks the one transition
 * (processing -> shipped) that requires carrier/tracking number - matches the validation
 * already enforced in view.php/ship_request_process(), not a new rule.
 */
function ship_request_next_action(string $status): ?array
{
    $actions = [
        'pending' => ['label' => 'Review Request', 'target_status' => 'processing', 'needs_tracking' => false],
        'processing' => ['label' => 'Create Shipment', 'target_status' => 'shipped', 'needs_tracking' => true],
        'shipped' => ['label' => 'Mark Completed', 'target_status' => 'completed', 'needs_tracking' => false],
    ];

    return $actions[$status] ?? null;
}

/**
 * Chronological "what already happened" entries for the Shipment Timeline section on
 * modules/ship-my-box/view.php - built entirely from data that already exists
 * (ship_requests.created_at and the linked shipment's own columns), never a new log of its
 * own. Only lists steps that have actually happened - there is no separate "pending future
 * step" concept here since the ship_requests status model doesn't track granular sub-stages
 * beyond pending/processing/shipped/completed.
 */
function ship_request_timeline(PDO $pdo, array $shipRequest, ?array $linkedShipment): array
{
    $timeline = [
        ['label' => 'Ship request created', 'detail' => date('d M Y H:i', strtotime((string) $shipRequest['created_at']))],
        ['label' => 'Items allocated from customer storage', 'detail' => null],
    ];

    if ($linkedShipment === null) {
        return $timeline;
    }

    $timeline[] = ['label' => 'Shipment created', 'detail' => $linkedShipment['shipment_number']];

    if ($linkedShipment['tracking_number'] !== null && $linkedShipment['tracking_number'] !== '') {
        $trackingDetail = $linkedShipment['tracking_number'];
        if (!empty($linkedShipment['carrier'])) {
            $trackingDetail = $linkedShipment['carrier'] . ' ' . $trackingDetail;
        }
        $timeline[] = ['label' => 'Tracking added', 'detail' => $trackingDetail];
    }

    return $timeline;
}
