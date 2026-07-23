<?php

require_once __DIR__ . '/orders.php';

/**
 * Historical Customer Order import - the one and only way to create a
 * mewmii_orders row with is_historical = 1. Deliberately NEVER calls
 * inventory_reserve_for_order()/inventory_ship_for_order()/inventory_release_for_order() -
 * an imported order is a business record of something that already happened before Mewmii
 * OS existed, so it must never move current stock. It still appears in order history,
 * customer purchase history, and every sales report, because those all read straight from
 * mewmii_orders/mewmii_order_items - never from the inventory ledger.
 *
 * $data keys: order_number, customer_id, order_date, payment_date, fulfillment_date,
 * order_status, payment_status, shipping_status, shipping_fee, discount, customer_note,
 * internal_note.
 * $items: list of ['product_id', 'variation_id', 'quantity', 'selling_price', 'discount',
 * 'cost_snapshot'].
 * Returns the new order id.
 */
function order_import_create(PDO $pdo, array $data, array $items): int
{
    if ($items === []) {
        throw new RuntimeException('A historical order needs at least one item.');
    }

    $subtotal = 0.00;
    $discountTotal = (float) ($data['discount'] ?? 0);
    foreach ($items as $item) {
        $subtotal += (int) $item['quantity'] * (float) $item['selling_price'];
        $discountTotal += (float) ($item['discount'] ?? 0);
    }
    $subtotal = round($subtotal, 2);
    $discountTotal = round($discountTotal, 2);
    $shippingFee = round((float) ($data['shipping_fee'] ?? 0), 2);
    $totalAmount = round($subtotal - $discountTotal + $shippingFee, 2);

    // shipped_at only has meaning once the order actually reached "shipped" - an imported
    // order sitting at an earlier historical stage (e.g. "processing") has no ship date.
    $shippedAt = null;
    if (in_array($data['order_status'], ['shipped', 'completed'], true) && !empty($data['fulfillment_date'])) {
        $shippedAt = $data['fulfillment_date'] . ' 00:00:00';
    }

    $orderStmt = $pdo->prepare('
        INSERT INTO mewmii_orders (
            order_number, customer_id, payment_status, payment_date, order_status, is_historical,
            shipping_status, shipped_at, fulfillment_date, customer_note, internal_note,
            subtotal, discount, shipping_fee, total_amount, order_date
        ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $orderStmt->execute([
        $data['order_number'],
        $data['customer_id'],
        $data['payment_status'],
        $data['payment_date'] !== '' ? $data['payment_date'] : null,
        $data['order_status'],
        $data['shipping_status'],
        $shippedAt,
        $data['fulfillment_date'] !== '' ? $data['fulfillment_date'] : null,
        $data['customer_note'] !== '' ? $data['customer_note'] : null,
        $data['internal_note'] !== '' ? $data['internal_note'] : null,
        $subtotal,
        $discountTotal,
        $shippingFee,
        $totalAmount,
        $data['order_date'] !== '' ? $data['order_date'] : null,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare('
        INSERT INTO mewmii_order_items (order_id, product_id, variation_id, variation_label, quantity, selling_price, discount, subtotal, cost_snapshot)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    foreach ($items as $item) {
        $quantity = (int) $item['quantity'];
        $price = round((float) $item['selling_price'], 2);
        $lineDiscount = round((float) ($item['discount'] ?? 0), 2);
        $lineSubtotal = round(($quantity * $price) - $lineDiscount, 2);
        $variationId = $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
        $variationLabel = $variationId !== null ? variation_build_label($pdo, $variationId) : null;

        $itemStmt->execute([
            $orderId,
            $item['product_id'],
            $variationId,
            $variationLabel,
            $quantity,
            $price,
            $lineDiscount,
            $lineSubtotal,
            round((float) ($item['cost_snapshot'] ?? 0), 2),
        ]);
    }

    $pdo->prepare('
        INSERT INTO mewmii_order_events (order_id, event_type, description, created_by)
        VALUES (?, ?, ?, ?)
    ')->execute([$orderId, 'imported', 'Imported as a historical order (status: ' . order_status_label($data['order_status']) . ').', $_SESSION['user_id'] ?? null]);

    return $orderId;
}
