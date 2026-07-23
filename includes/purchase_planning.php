<?php

require_once __DIR__ . '/product_variations.php';
require_once __DIR__ . '/supplier_orders.php';

/**
 * Purchase Planning: turns Inventory from a tracking system into a "what do I need to buy"
 * system. Every function here is read-only aggregation over existing tables, or thin
 * orchestration around existing mutation functions (supplier_order_mark_incoming(), etc.) -
 * no new stock-quantity math, no new ledger semantics.
 */

/**
 * Outstanding demand for one unit from PAID customer orders only, net of whatever has
 * already been allocated to Customer Storage against each order line - the "Paid Customer
 * Orders" side of the Preorder/Early Bird ordering formula. Deliberately NOT the same as
 * inventory_unit_outstanding_demand() (Allocation Center), which counts every non-cancelled
 * order regardless of payment - that function is untouched; this is a separate, narrower
 * question ("how much do I need to go BUY", not "how much can I allocate right now").
 * Reuses supplier_order_item_customer_storage_allocated() (unchanged) for the per-item
 * already-allocated subtraction, same pattern as the Allocation Center function.
 */
function purchase_planning_paid_demand(PDO $pdo, int $productId, ?int $variationId): int
{
    $stmt = $pdo->prepare("
        SELECT oi.id, oi.quantity
        FROM mewmii_order_items oi
        INNER JOIN mewmii_orders o ON o.id = oi.order_id
        WHERE oi.product_id = ? AND oi.variation_id <=> ?
          AND o.payment_status = 'paid' AND o.order_status <> 'cancelled'
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
 * Every sellable unit that currently needs purchasing (Order Quantity > 0), one row per
 * unit, built on catalog_sellable_units() (unchanged) rather than re-deriving what "one
 * sellable unit" means. Two completely separate formulas depending on product_type - never
 * blended into one calculation (per the explicit "keep preorder and ready stock workflows
 * separated" rule):
 *
 * - preorder/early_bird: Order Qty = purchase_planning_paid_demand() - incoming_quantity
 * - ready_stock: Order Qty = target_stock_level - available_quantity - incoming_quantity
 *   (skipped entirely if target_stock_level is NULL - "not planned" is not the same as
 *   "target is 0")
 *
 * Whichever raw quantity is calculated, the DEFAULT suggested order quantity is bumped up
 * to the product's MOQ if it's below it (same non-blocking convention already used by the
 * Supplier Order picker/create page - the admin can still edit it down before generating).
 * Every row is tagged with its product's supplier_id so purchase_planning_generate() can
 * group by supplier without re-querying.
 */
function purchase_planning_needs(PDO $pdo): array
{
    $units = catalog_sellable_units($pdo);

    $productIds = array_unique(array_column($units, 'product_id'));
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $productsStmt = $pdo->prepare("SELECT id, supplier_id, target_stock_level FROM products WHERE id IN ({$placeholders})");
    $productsStmt->execute($productIds);
    $productsById = [];
    foreach ($productsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productsById[(int) $row['id']] = $row;
    }

    $needs = [];
    foreach ($units as $unit) {
        if (($unit['status'] ?? 'draft') === 'archived') {
            continue;
        }

        $productId = (int) $unit['product_id'];
        $variationId = $unit['variation_id'];
        $product = $productsById[$productId] ?? null;
        if ($product === null) {
            continue;
        }

        $invStmt = $pdo->prepare('SELECT available_quantity, incoming_quantity FROM mewmii_inventory WHERE product_id = ? AND variation_id <=> ?');
        $invStmt->execute([$productId, $variationId]);
        $inv = $invStmt->fetch(PDO::FETCH_ASSOC) ?: ['available_quantity' => 0, 'incoming_quantity' => 0];
        $available = (int) $inv['available_quantity'];
        $incoming = (int) $inv['incoming_quantity'];

        $isPreorder = in_array($unit['product_type'], ['preorder', 'early_bird'], true);

        if ($isPreorder) {
            $paidDemand = purchase_planning_paid_demand($pdo, $productId, $variationId);
            $rawNeed = $paidDemand - $incoming;
            $demandBasis = 'customer';
        } else {
            if ($product['target_stock_level'] === null) {
                continue;
            }
            $rawNeed = (int) $product['target_stock_level'] - $available - $incoming;
            $demandBasis = 'topup';
        }

        if ($rawNeed <= 0) {
            continue;
        }

        $moq = $unit['moq'] !== null ? (int) $unit['moq'] : 0;
        $suggestedQty = max($rawNeed, $moq);
        $moqTopUp = $suggestedQty - $rawNeed;

        $needs[] = [
            'key' => $unit['key'],
            'product_id' => $productId,
            'variation_id' => $variationId,
            'sku' => $unit['sku'],
            'label' => $unit['label'],
            'product_type' => $unit['product_type'],
            'supplier_id' => $product['supplier_id'] !== null ? (int) $product['supplier_id'] : null,
            'available_quantity' => $available,
            'incoming_quantity' => $incoming,
            'raw_need' => $rawNeed,
            'moq' => $moq,
            'suggested_quantity' => $suggestedQty,
            // Feeds supplier_order_items.customer_quantity/top_up_quantity/moq_quantity -
            // see purchase_planning_generate().
            'demand_basis' => $demandBasis,
            'demand_quantity' => $rawNeed,
            'moq_top_up' => $moqTopUp,
            'cost_price' => $unit['cost_price'],
        ];
    }

    return $needs;
}

/**
 * Groups the admin-selected/edited lines by supplier and creates one supplier_orders
 * (status='draft') + its supplier_order_items per supplier - "Each supplier must receive
 * their own purchase order." Every line still goes through the exact same
 * supplier_order_mark_incoming() already used by the manual Supplier Order create page, so
 * incoming_quantity/inventory_transactions are updated through the one existing code path,
 * never a second one.
 *
 * $selectedLines: list of ['product_id', 'variation_id', 'quantity', 'supplier_price',
 * 'supplier_id', 'demand_basis' ('customer'|'topup'), 'demand_quantity', 'moq_top_up'] -
 * the shape purchase_planning_needs() produces, after the admin's review-screen edits.
 * Returns the list of newly created supplier_order ids (one per supplier).
 */
function purchase_planning_generate(PDO $pdo, array $selectedLines): array
{
    $bySupplier = [];
    foreach ($selectedLines as $line) {
        $supplierId = $line['supplier_id'] ?? null;
        if ($supplierId === null || (int) $line['quantity'] < 1) {
            continue;
        }
        $bySupplier[(int) $supplierId][] = $line;
    }

    if ($bySupplier === []) {
        throw new RuntimeException('No purchasable lines with a supplier were selected.');
    }

    $createdOrderIds = [];

    foreach ($bySupplier as $supplierId => $lines) {
        $purchaseNumber = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        $estimatedCost = 0.00;
        foreach ($lines as $line) {
            $estimatedCost += (int) $line['quantity'] * (float) $line['supplier_price'];
        }

        $orderStmt = $pdo->prepare("
            INSERT INTO supplier_orders (supplier_id, purchase_number, status, estimated_cost, order_date)
            VALUES (?, ?, 'draft', ?, CURDATE())
        ");
        $orderStmt->execute([$supplierId, $purchaseNumber, round($estimatedCost, 2)]);
        $orderId = (int) $pdo->lastInsertId();
        $createdOrderIds[] = $orderId;

        $itemStmt = $pdo->prepare('
            INSERT INTO supplier_order_items (supplier_order_id, product_id, variation_id, customer_quantity, moq_quantity, top_up_quantity, total_quantity, supplier_price, subtotal)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        foreach ($lines as $line) {
            $quantity = (int) $line['quantity'];
            $price = round((float) $line['supplier_price'], 2);
            $subtotal = round($quantity * $price, 2);

            // demand_quantity/moq_top_up are the ORIGINAL calculated figures - if the admin
            // raised the quantity further on the review screen, that extra goes to
            // moq_quantity too (it's still "extra beyond calculated demand", regardless of
            // why the admin chose to add it).
            $demandQuantity = (int) ($line['demand_quantity'] ?? 0);
            $moqTopUp = (int) ($line['moq_top_up'] ?? 0);
            $adminExtra = max(0, $quantity - $demandQuantity - $moqTopUp);

            $customerQuantity = ($line['demand_basis'] ?? 'topup') === 'customer' ? $demandQuantity : 0;
            $topUpQuantity = ($line['demand_basis'] ?? 'topup') === 'topup' ? $demandQuantity : 0;
            $moqQuantity = $moqTopUp + $adminExtra;

            $itemStmt->execute([
                $orderId,
                $line['product_id'],
                $line['variation_id'],
                $customerQuantity,
                $moqQuantity,
                $topUpQuantity,
                $quantity,
                $price,
                $subtotal,
            ]);
            $itemId = (int) $pdo->lastInsertId();

            supplier_order_mark_incoming($pdo, (int) $line['product_id'], $itemId, $quantity, $line['variation_id'] !== null ? (int) $line['variation_id'] : null);
        }

        supplier_order_log_event($pdo, $orderId, 'Generated from Purchase Planning (' . count($lines) . ' line(s)).');
        activity_log($pdo, 'supplier_orders', 'generate', $orderId, 'Generated supplier order ' . $purchaseNumber . ' from Purchase Planning');
    }

    return $createdOrderIds;
}
