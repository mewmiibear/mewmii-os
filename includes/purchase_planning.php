<?php

require_once __DIR__ . '/product_variations.php';
require_once __DIR__ . '/supplier_orders.php';
require_once __DIR__ . '/inventory.php';

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
 * Whichever raw quantity (shortage) is calculated, the DEFAULT suggested order quantity is
 * rounded UP to the next whole multiple of the product's MOQ - not just bumped up to MOQ
 * itself - so a supplier order is never placed below their minimum lot size for any shortage
 * beyond one lot (shortage=7, MOQ=3 -> 9, not 7). This rounding is purely a supplier-order-
 * generation concern: the shortage itself (raw_need/demand_quantity) is left exactly as
 * calculated, unrounded, so anything reading "how much is actually needed" (this function's
 * own return value included) still sees the true customer-demand-driven shortage; only the
 * suggested_quantity fed into supplier order generation is MOQ-rounded. The admin can still
 * edit it down before generating, same non-blocking convention as the Supplier Order
 * picker/create page.
 * Every row is tagged with its product's supplier_id so purchase_planning_generate() can
 * group by supplier without re-querying.
 */
function purchase_planning_needs(PDO $pdo): array
{
    $units = catalog_sellable_units($pdo);

    $productIds = array_values(array_unique(array_column($units, 'product_id')));
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

        $invStmt = $pdo->prepare('SELECT available_quantity, incoming_quantity, arrived_quantity FROM mewmii_inventory WHERE product_id = ? AND variation_id <=> ?');
        $invStmt->execute([$productId, $variationId]);
        $inv = $invStmt->fetch(PDO::FETCH_ASSOC) ?: ['available_quantity' => 0, 'incoming_quantity' => 0, 'arrived_quantity' => 0];
        $available = (int) $inv['available_quantity'];
        $incoming = (int) $inv['incoming_quantity'];
        $arrived = (int) $inv['arrived_quantity'];

        $isPreorder = in_array($unit['product_type'], ['preorder', 'early_bird'], true);

        if ($isPreorder) {
            // arrived_quantity is supplier stock that HAS been received but is sitting
            // unallocated pending a human action in the Allocation Center (see
            // includes/customer_storage.php) - it is not yet in customer_storage, so
            // purchase_planning_paid_demand() (which only nets against customer_storage)
            // doesn't know about it. Without subtracting it here too, every preorder receiving
            // event would make Purchase Planning briefly (and wrongly) show a bigger shortage
            // than before that same stock arrived, since incoming_quantity drops but nothing
            // offsets it until someone manually allocates. Subtracting it directly fixes that
            // without touching the Allocation Center or any inventory-mutation logic.
            $paidDemand = purchase_planning_paid_demand($pdo, $productId, $variationId);
            $customerDemand = $paidDemand;
            $rawNeed = $paidDemand - $incoming - $arrived;
            $demandBasis = 'customer';
        } else {
            if ($product['target_stock_level'] === null) {
                continue;
            }
            $customerDemand = (int) $product['target_stock_level'];
            $rawNeed = $customerDemand - $available - $incoming;
            $demandBasis = 'topup';
        }

        if ($rawNeed <= 0) {
            continue;
        }

        // Recommended Order Qty: shortage rounded UP to the next whole multiple of MOQ (see
        // function docblock). $moq <= 0 (unset) means no lot-size constraint, so the
        // recommendation is just the shortage itself.
        $moq = $unit['moq'] !== null ? (int) $unit['moq'] : 0;
        $suggestedQty = $moq > 0 ? (int) (ceil($rawNeed / $moq) * $moq) : $rawNeed;
        $moqTopUp = $suggestedQty - $rawNeed;

        $needs[] = [
            'key' => $unit['key'],
            'product_id' => $productId,
            'variation_id' => $variationId,
            'sku' => $unit['sku'],
            'label' => $unit['label'],
            'product_type' => $unit['product_type'],
            'supplier_id' => $product['supplier_id'] !== null ? (int) $product['supplier_id'] : null,
            'customer_demand' => $customerDemand,
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

/**
 * Standalone admin warning, completely separate from purchase_planning_needs() and never
 * called by it: ready-stock units with real outstanding (unreserved) demand
 * (inventory_unit_unreserved_demand(), unchanged - the same function the Reservation Center
 * itself uses) but no target_stock_level configured at all. purchase_planning_needs()'s
 * ready-stock formula requires a target to compute a shortage in the first place ("not
 * planned" is deliberately not the same as "target is 0"), so a unit in this state can NEVER
 * appear in the main shortage list, no matter how many orders are waiting on it - this is the
 * one blind spot that formula can't see by design. This function doesn't change that
 * formula, doesn't feed into supplier order generation, and computes nothing
 * purchase_planning_needs() doesn't already compute elsewhere for other units - it only
 * surfaces units the main list structurally cannot.
 */
function purchase_planning_untargeted_demand(PDO $pdo): array
{
    $units = catalog_sellable_units($pdo);

    $productIds = array_values(array_unique(array_column($units, 'product_id')));
    if ($productIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $productsStmt = $pdo->prepare("SELECT id, target_stock_level FROM products WHERE id IN ({$placeholders})");
    $productsStmt->execute($productIds);
    $targetByProduct = [];
    foreach ($productsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $targetByProduct[(int) $row['id']] = $row['target_stock_level'];
    }

    $warnings = [];
    foreach ($units as $unit) {
        if ($unit['product_type'] !== 'ready_stock' || ($unit['status'] ?? 'draft') === 'archived') {
            continue;
        }

        $productId = (int) $unit['product_id'];
        if (!array_key_exists($productId, $targetByProduct) || $targetByProduct[$productId] !== null) {
            continue;
        }

        $variationId = $unit['variation_id'];
        $demand = inventory_unit_unreserved_demand($pdo, $productId, $variationId);
        if ($demand < 1) {
            continue;
        }

        $warnings[] = [
            'key' => $unit['key'],
            'product_id' => $productId,
            'sku' => $unit['sku'],
            'label' => $unit['label'],
            'demand' => $demand,
        ];
    }

    return $warnings;
}
