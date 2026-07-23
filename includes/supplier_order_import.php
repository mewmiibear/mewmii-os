<?php

require_once __DIR__ . '/supplier_orders.php';

/**
 * Historical Supplier Order import - mirrors order_import.php exactly, for Supplier Orders.
 * Deliberately NEVER calls supplier_order_mark_incoming()/supplier_order_receive_item() - an
 * imported PO already happened before Mewmii OS existed, so it must never touch
 * incoming_quantity/available_quantity or write a supplier_receive inventory_transactions
 * row. It still appears in purchase history, supplier spending, and the product cost
 * timeline, since those all read supplier_orders/supplier_order_items directly.
 *
 * $data keys: purchase_number, supplier_id, order_date, expected_delivery_date,
 * received_date, status, notes.
 * $items: list of ['product_id', 'variation_id', 'quantity', 'supplier_price'].
 * Returns the new supplier_orders id.
 */
function supplier_order_import_create(PDO $pdo, array $data, array $items): int
{
    if ($items === []) {
        throw new RuntimeException('A historical supplier order needs at least one item.');
    }

    $estimatedCost = 0.00;
    foreach ($items as $item) {
        $estimatedCost += (int) $item['quantity'] * (float) $item['supplier_price'];
    }
    $estimatedCost = round($estimatedCost, 2);

    $orderStmt = $pdo->prepare('
        INSERT INTO supplier_orders (supplier_id, purchase_number, status, is_historical, estimated_cost, order_date, expected_delivery_date, received_date, notes)
        VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)
    ');
    $orderStmt->execute([
        $data['supplier_id'],
        $data['purchase_number'],
        $data['status'],
        $estimatedCost,
        $data['order_date'] !== '' ? $data['order_date'] : null,
        $data['expected_delivery_date'] !== '' ? $data['expected_delivery_date'] : null,
        $data['received_date'] !== '' ? $data['received_date'] : null,
        $data['notes'] !== '' ? $data['notes'] : null,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare('
        INSERT INTO supplier_order_items (supplier_order_id, product_id, variation_id, total_quantity, supplier_price, subtotal)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    foreach ($items as $item) {
        $quantity = (int) $item['quantity'];
        $price = round((float) $item['supplier_price'], 2);
        $itemStmt->execute([
            $orderId,
            $item['product_id'],
            $item['variation_id'] !== null ? (int) $item['variation_id'] : null,
            $quantity,
            $price,
            round($quantity * $price, 2),
        ]);
    }

    supplier_order_log_event($pdo, $orderId, 'Imported as a historical supplier order (status: ' . supplier_order_status_label($data['status']) . ').');

    return $orderId;
}
