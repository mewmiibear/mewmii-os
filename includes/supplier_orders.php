<?php

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/customer_storage.php';
require_once __DIR__ . '/product_variations.php';
require_once __DIR__ . '/catalog.php';

// --- Workflow: Draft -> Ordered -> Arrived -> Completed --------------------------------
// "Arrived" is a display label for the existing status = 'received' value (set
// automatically by supplier_order_receive_item() once every line is fully received) -
// there is no separate database value for it, so this never duplicates that logic.

const SUPPLIER_ORDER_WORKFLOW = ['draft', 'ordered', 'received', 'completed'];

function supplier_order_status_label(string $status): string
{
    $labels = [
        'draft' => 'Draft',
        'ordered' => 'Ordered',
        'received' => 'Arrived',
        'completed' => 'Completed',
        'waiting_payment' => 'Waiting Payment',
        'shipping' => 'Shipping',
    ];

    return $labels[$status] ?? ucfirst($status);
}

function supplier_order_status_badge(string $status): string
{
    $colors = [
        'draft' => 'secondary',
        'ordered' => 'info text-dark',
        'received' => 'warning text-dark',
        'completed' => 'success',
        'waiting_payment' => 'secondary',
        'shipping' => 'info text-dark',
    ];
    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' . $color . '">' . htmlspecialchars(supplier_order_status_label($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Next step in the linear Draft->Ordered->Arrived->Completed workflow, or null if there
 * is none (already Completed, or an older waiting_payment/shipping status that predates
 * this simplified workflow and isn't part of it - those are left as-is, displayed with no
 * action button, rather than guessed into the new flow).
 */
function supplier_order_status_next(string $status): ?string
{
    $index = array_search($status, SUPPLIER_ORDER_WORKFLOW, true);
    if ($index === false || !isset(SUPPLIER_ORDER_WORKFLOW[$index + 1])) {
        return null;
    }

    return SUPPLIER_ORDER_WORKFLOW[$index + 1];
}

function supplier_order_status_next_action_label(string $status): ?string
{
    $labels = [
        'draft' => 'Submit Order',
        'ordered' => 'Mark Arrived',
        'received' => 'Complete Order',
    ];

    return $labels[$status] ?? null;
}

/**
 * Whether any inventory has ever actually been received against this order - the one
 * fact that gates both editing (11.7: "remain available until inventory has been
 * received") and deletion (11.8: blocked once receiving history exists). Derived from the
 * ledger itself (inventory_transactions), never a status flag, so it can't drift out of
 * sync with what actually happened.
 */
function supplier_order_has_receiving_history(PDO $pdo, int $orderId): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM inventory_transactions it
        INNER JOIN supplier_order_items soi ON soi.id = it.reference_id AND it.reference_type = 'supplier_order_item'
        WHERE soi.supplier_order_id = ? AND it.transaction_type = 'supplier_receive'
    ");
    $stmt->execute([$orderId]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Units received so far for one supplier order line item, derived from the
 * inventory_transactions ledger rather than a dedicated column (schema has none),
 * so partial/multiple receiving sessions stay correct without new columns.
 */
function supplier_order_item_received_quantity(PDO $pdo, int $itemId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0)
        FROM inventory_transactions
        WHERE reference_type = 'supplier_order_item' AND reference_id = ? AND transaction_type = 'supplier_receive'
    ");
    $stmt->execute([$itemId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Mark stock as incoming for a freshly created supplier order line item. $variationId is
 * null for a simple product's line item, or the specific variation SKU being ordered.
 */
function supplier_order_mark_incoming(PDO $pdo, int $productId, int $itemId, int $quantity, ?int $variationId = null): void
{
    if ($quantity < 1) {
        return;
    }

    inventory_get_or_create_row($pdo, $productId, $variationId);

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET incoming_quantity = incoming_quantity + ?
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$quantity, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'supplier_order_placed', $quantity, 'supplier_order_item', $itemId, $variationId);
}

/**
 * Total quantity already routed into customer storage against one order item, derived
 * from the ledger (customer_storage.order_item_id) rather than a counter, so partial/
 * multiple receiving sessions stay correct and an order is never matched twice.
 */
function supplier_order_item_customer_storage_allocated(PDO $pdo, int $orderItemId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM customer_storage WHERE order_item_id = ?');
    $stmt->execute([$orderItemId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Marks received preorder/early-bird stock as arrived but not yet allocated - it moves
 * from incoming_quantity into arrived_quantity, never touching available_quantity or
 * customer_storage directly. A human then manually allocates it to specific outstanding
 * customer orders (or releases it to available stock) via modules/inventory/allocate.php.
 */
function supplier_order_receive_preorder_quantity(PDO $pdo, int $productId, ?int $variationId, int $supplierItemId, int $quantity): void
{
    inventory_get_or_create_row($pdo, $productId, $variationId);

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET incoming_quantity = GREATEST(incoming_quantity - ?, 0),
            arrived_quantity = arrived_quantity + ?
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$quantity, $quantity, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'supplier_receive', $quantity, 'supplier_order_item', $supplierItemId, $variationId);
}

/**
 * Receive units for one supplier order line item: for ready_stock, moves stock from
 * incoming into available as before. For preorder/early_bird, moves it into
 * arrived_quantity pending manual allocation (see supplier_order_receive_preorder_quantity()).
 * Once every item on the parent order has been fully received, the order is auto-advanced
 * to status 'received'. The line item's own variation_id (set at PO creation time)
 * determines which inventory row moves.
 */
function supplier_order_receive_item(PDO $pdo, int $itemId, int $quantity): void
{
    if ($quantity < 1) {
        throw new RuntimeException('Quantity must be at least 1.');
    }

    $stmt = $pdo->prepare('SELECT * FROM supplier_order_items WHERE id = ? FOR UPDATE');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new RuntimeException('Supplier order item not found.');
    }

    $productId = (int) $item['product_id'];
    $variationId = isset($item['variation_id']) && $item['variation_id'] !== null ? (int) $item['variation_id'] : null;
    $orderId = (int) $item['supplier_order_id'];
    $totalQuantity = (int) $item['total_quantity'];
    $alreadyReceived = supplier_order_item_received_quantity($pdo, $itemId);
    $remaining = $totalQuantity - $alreadyReceived;

    if ($quantity > $remaining) {
        throw new RuntimeException(catalog_format_stock_error($pdo, 'Cannot receive more than the remaining ordered quantity.', $productId, $variationId, 'Remaining ordered quantity', $remaining, $quantity));
    }

    $productTypeStmt = $pdo->prepare('SELECT product_type FROM products WHERE id = ?');
    $productTypeStmt->execute([$productId]);
    $productType = (string) $productTypeStmt->fetchColumn();

    if (in_array($productType, ['preorder', 'early_bird'], true)) {
        supplier_order_receive_preorder_quantity($pdo, $productId, $variationId, $itemId, $quantity);
    } else {
        inventory_get_or_create_row($pdo, $productId, $variationId);

        $pdo->prepare('
            UPDATE mewmii_inventory
            SET incoming_quantity = GREATEST(incoming_quantity - ?, 0),
                available_quantity = available_quantity + ?
            WHERE product_id = ? AND variation_id <=> ?
        ')->execute([$quantity, $quantity, $productId, $variationId]);

        inventory_log_transaction($pdo, $productId, 'supplier_receive', $quantity, 'supplier_order_item', $itemId, $variationId);
    }

    $itemsStmt = $pdo->prepare('SELECT id, total_quantity FROM supplier_order_items WHERE supplier_order_id = ?');
    $itemsStmt->execute([$orderId]);
    $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $fullyReceived = true;
    foreach ($allItems as $orderItem) {
        $received = supplier_order_item_received_quantity($pdo, (int) $orderItem['id']);
        if ($received < (int) $orderItem['total_quantity']) {
            $fullyReceived = false;
            break;
        }
    }

    if ($fullyReceived) {
        $orderStmt = $pdo->prepare('SELECT status FROM supplier_orders WHERE id = ?');
        $orderStmt->execute([$orderId]);
        $currentStatus = (string) $orderStmt->fetchColumn();

        if (!in_array($currentStatus, ['received', 'completed'], true)) {
            $pdo->prepare("UPDATE supplier_orders SET status = 'received', received_date = CURDATE() WHERE id = ?")
                ->execute([$orderId]);
        }
    }
}

/**
 * Receives every remaining unit on every line of an order in one action ("Mark Arrived") -
 * the default, fast path for a shipment that arrived complete, instead of entering each
 * line's quantity by hand. Reuses supplier_order_receive_item() per line unchanged, so it
 * carries the exact same ready_stock/preorder handling and auto-completion check; lines
 * that are already fully received are simply skipped. "Partial Receive" (entering a
 * smaller quantity for one line) remains available separately via
 * supplier_order_receive_item() directly - this is purely a bulk convenience wrapper.
 */
function supplier_order_receive_all_remaining(PDO $pdo, int $orderId): void
{
    $itemsStmt = $pdo->prepare('SELECT id, total_quantity FROM supplier_order_items WHERE supplier_order_id = ?');
    $itemsStmt->execute([$orderId]);

    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemId = (int) $item['id'];
        $remaining = (int) $item['total_quantity'] - supplier_order_item_received_quantity($pdo, $itemId);
        if ($remaining > 0) {
            supplier_order_receive_item($pdo, $itemId, $remaining);
        }
    }
}

/**
 * Reverses one line item's still-outstanding incoming contribution (the flip side of
 * supplier_order_mark_incoming()) - used only when editing or deleting an order that has
 * never actually received anything (see supplier_order_has_receiving_history()), so this
 * only ever touches incoming_quantity, never available/reserved/arrived. Logged as its own
 * transaction type so it's never confused with a real receiving event in the ledger.
 */
function supplier_order_reverse_incoming(PDO $pdo, int $productId, ?int $variationId, int $itemId, int $quantity): void
{
    if ($quantity < 1) {
        return;
    }

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET incoming_quantity = GREATEST(incoming_quantity - ?, 0)
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$quantity, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'supplier_order_cancelled', -$quantity, 'supplier_order_item', $itemId, $variationId);
}

/**
 * Applies a signed delta to one line item's incoming_quantity - the general-purpose
 * version of supplier_order_mark_incoming() (delta = full quantity, on creation) and
 * supplier_order_reverse_incoming() (delta = negative full quantity, on delete/removal).
 * Used when an existing order's line quantity is edited (see supplier_order_apply_edit()):
 * increasing the ordered quantity adds to incoming, decreasing it removes the difference -
 * never touches arrived_quantity/available_quantity, so already-received stock is
 * completely unaffected either way.
 */
function supplier_order_adjust_incoming(PDO $pdo, int $productId, ?int $variationId, int $itemId, int $delta): void
{
    if ($delta === 0) {
        return;
    }

    inventory_get_or_create_row($pdo, $productId, $variationId);

    $pdo->prepare('
        UPDATE mewmii_inventory
        SET incoming_quantity = GREATEST(incoming_quantity + ?, 0)
        WHERE product_id = ? AND variation_id <=> ?
    ')->execute([$delta, $productId, $variationId]);

    inventory_log_transaction($pdo, $productId, 'supplier_order_adjusted', $delta, 'supplier_order_item', $itemId, $variationId);
}

function supplier_order_log_event(PDO $pdo, int $orderId, string $description): void
{
    $pdo->prepare('
        INSERT INTO supplier_order_events (supplier_order_id, event_type, description, created_by)
        VALUES (?, ?, ?, ?)
    ')->execute([$orderId, 'edit', $description, $_SESSION['user_id'] ?? null]);
}

/**
 * Full add/edit/remove reconciliation for an existing supplier order - the only way its
 * line items change after creation (see modules/supplier-orders/edit.php). $newLines is
 * the same per-line shape modules/supplier-orders/create.php builds: a list of
 * ['product_id', 'variation_id', 'quantity', 'supplier_price'], keyed internally by
 * "productId:variationId" (the same sellable-unit key used everywhere else in this app).
 *
 * Receiving history is never rewritten: a line that already has ANY received quantity
 * cannot be removed, and its quantity cannot be reduced below what's already been
 * received - both throw with an admin-facing message naming the product. Only the
 * still-outstanding incoming_quantity moves, via supplier_order_adjust_incoming() (a
 * signed delta = new_quantity - old_quantity, which is correct regardless of how much has
 * already been received: incoming = ordered - received, so the received term cancels out
 * of the delta). Every add/remove/quantity-change/cost-change is logged individually to
 * supplier_order_events. Caller is responsible for the surrounding transaction and for
 * blocking this entirely once the order is 'completed' (see modules/supplier-orders/edit.php).
 */
function supplier_order_apply_edit(PDO $pdo, int $orderId, int $supplierId, string $notes, array $newLines): void
{
    $existingStmt = $pdo->prepare('SELECT id, product_id, variation_id, total_quantity, supplier_price FROM supplier_order_items WHERE supplier_order_id = ?');
    $existingStmt->execute([$orderId]);
    $existingByKey = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0);
        $existingByKey[$key] = $row;
    }

    $newByKey = [];
    foreach ($newLines as $line) {
        $key = $line['product_id'] . ':' . (int) ($line['variation_id'] ?? 0);
        $newByKey[$key] = $line;
    }

    // Removed lines: only safe if nothing has been received against them yet.
    foreach ($existingByKey as $key => $existing) {
        if (isset($newByKey[$key])) {
            continue;
        }

        $itemId = (int) $existing['id'];
        $received = supplier_order_item_received_quantity($pdo, $itemId);
        $unit = catalog_describe_unit($pdo, (int) $existing['product_id'], $existing['variation_id'] !== null ? (int) $existing['variation_id'] : null);
        $label = $unit['product_name'] . (!empty($unit['variation_label']) ? ' (' . $unit['variation_label'] . ')' : '');

        if ($received > 0) {
            throw new RuntimeException($label . ' already has received quantity and cannot be removed from this order.');
        }

        supplier_order_reverse_incoming($pdo, (int) $existing['product_id'], $existing['variation_id'] !== null ? (int) $existing['variation_id'] : null, $itemId, (int) $existing['total_quantity']);
        $pdo->prepare('DELETE FROM supplier_order_items WHERE id = ?')->execute([$itemId]);
        supplier_order_log_event($pdo, $orderId, 'Removed ' . $label . ' x' . (int) $existing['total_quantity']);
    }

    // New and updated lines.
    foreach ($newByKey as $key => $line) {
        $productId = (int) $line['product_id'];
        $variationId = $line['variation_id'] !== null ? (int) $line['variation_id'] : null;
        $quantity = (int) $line['quantity'];
        $price = round((float) $line['supplier_price'], 2);
        $subtotal = round($quantity * $price, 2);

        $unit = catalog_describe_unit($pdo, $productId, $variationId);
        $label = $unit['product_name'] . (!empty($unit['variation_label']) ? ' (' . $unit['variation_label'] . ')' : '');

        if (!isset($existingByKey[$key])) {
            // New line.
            $insertStmt = $pdo->prepare('
                INSERT INTO supplier_order_items (supplier_order_id, product_id, variation_id, total_quantity, supplier_price, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $insertStmt->execute([$orderId, $productId, $variationId, $quantity, $price, $subtotal]);
            $itemId = (int) $pdo->lastInsertId();

            supplier_order_mark_incoming($pdo, $productId, $itemId, $quantity, $variationId);
            supplier_order_log_event($pdo, $orderId, 'Added ' . $label . ' x' . $quantity);

            continue;
        }

        // Existing line - reconcile quantity/cost.
        $existing = $existingByKey[$key];
        $itemId = (int) $existing['id'];
        $oldQuantity = (int) $existing['total_quantity'];
        $oldPrice = (float) $existing['supplier_price'];
        $received = supplier_order_item_received_quantity($pdo, $itemId);

        if ($quantity < $received) {
            throw new RuntimeException($label . ' has already received ' . $received . ' unit(s) and cannot be reduced below that.');
        }

        $delta = $quantity - $oldQuantity;
        if ($delta !== 0) {
            supplier_order_adjust_incoming($pdo, $productId, $variationId, $itemId, $delta);
            supplier_order_log_event($pdo, $orderId, 'Changed ' . $label . ' quantity ' . $oldQuantity . ' -> ' . $quantity);
        }

        if (abs($oldPrice - $price) > 0.001) {
            supplier_order_log_event($pdo, $orderId, 'Changed ' . $label . ' unit cost RM' . number_format($oldPrice, 2) . ' -> RM' . number_format($price, 2));
        }

        $pdo->prepare('UPDATE supplier_order_items SET total_quantity = ?, supplier_price = ?, subtotal = ? WHERE id = ?')
            ->execute([$quantity, $price, $subtotal, $itemId]);
    }

    // Totals + supplier/notes always refreshed, even if nothing else changed this pass.
    $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(subtotal), 0) FROM supplier_order_items WHERE supplier_order_id = ?');
    $totalStmt->execute([$orderId]);
    $estimatedCost = round((float) $totalStmt->fetchColumn(), 2);

    $pdo->prepare('UPDATE supplier_orders SET supplier_id = ?, estimated_cost = ?, notes = ? WHERE id = ?')
        ->execute([$supplierId, $estimatedCost, $notes !== '' ? $notes : null, $orderId]);
}

/**
 * Deletes a supplier order entirely, but only if nothing has ever actually been received
 * against it (see supplier_order_has_receiving_history()) - otherwise throws with the
 * admin-facing message so the caller can show it instead of a raw SQL/FK error. Every
 * line's still-outstanding incoming contribution is reversed first (see
 * supplier_order_reverse_incoming()) so no phantom "incoming" stock is left behind;
 * deleting the order row then cascades to its line items.
 */
function supplier_order_delete_if_unreceived(PDO $pdo, int $orderId): void
{
    if (supplier_order_has_receiving_history($pdo, $orderId)) {
        throw new RuntimeException('This supplier order has receiving history and cannot be deleted.');
    }

    $itemsStmt = $pdo->prepare('SELECT id, product_id, variation_id, total_quantity FROM supplier_order_items WHERE supplier_order_id = ?');
    $itemsStmt->execute([$orderId]);

    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        supplier_order_reverse_incoming(
            $pdo,
            (int) $item['product_id'],
            $item['variation_id'] !== null ? (int) $item['variation_id'] : null,
            (int) $item['id'],
            (int) $item['total_quantity']
        );
    }

    $pdo->prepare('DELETE FROM supplier_orders WHERE id = ?')->execute([$orderId]);
}

/**
 * Every supplier order still eligible for delete (see supplier_order_delete_if_unreceived()
 * / supplier_order_has_receiving_history()) - the "safe to delete" list for the Data
 * Cleanup tool. Same NOT EXISTS approach as catalog_list_deletable_products() for the same
 * reason; the delete action itself still re-validates via supplier_order_has_receiving_history().
 */
function supplier_order_list_deletable(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT so.id, so.purchase_number, so.status, so.created_at
        FROM supplier_orders so
        WHERE NOT EXISTS (
            SELECT 1 FROM inventory_transactions it
            INNER JOIN supplier_order_items soi ON soi.id = it.reference_id AND it.reference_type = 'supplier_order_item'
            WHERE soi.supplier_order_id = so.id AND it.transaction_type = 'supplier_receive'
        )
        ORDER BY so.created_at DESC
        LIMIT 500
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every product for the "+ Add Product" picker (11.2), grouped exactly like
 * modules/inventory/index.php groups its listing: a simple product is one selectable
 * unit, a variable product is a container whose active (non-archived) variations are
 * each their own selectable unit - a variable product's parent is never itself orderable
 * from a supplier, same rule as catalog_sellable_units(). Includes supplier/category so
 * the picker's filters don't need a separate query. Capped at 500 products, consistent
 * with this app's other admin-scale listings.
 */
function supplier_order_picker_products(PDO $pdo): array
{
    $productsStmt = $pdo->query("
        SELECT p.id, p.sku, p.name, p.catalog_type, p.product_type, p.product_cost, p.moq,
               p.supplier_id, s.name AS supplier_name,
               (SELECT cat.id FROM product_category_relationships pcr
                   INNER JOIN categories cat ON cat.id = pcr.category_id
                   WHERE pcr.product_id = p.id ORDER BY pcr.category_id ASC LIMIT 1) AS category_id,
               (SELECT cat.name FROM product_category_relationships pcr
                   INNER JOIN categories cat ON cat.id = pcr.category_id
                   WHERE pcr.product_id = p.id ORDER BY pcr.category_id ASC LIMIT 1) AS category_name
        FROM products p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.status <> 'archived'
        ORDER BY p.name ASC
        LIMIT 500
    ");
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

    $variableIds = [];
    foreach ($products as $product) {
        if ($product['catalog_type'] === 'variable') {
            $variableIds[] = (int) $product['id'];
        }
    }

    $variationsByProduct = [];
    if ($variableIds !== []) {
        $placeholders = implode(',', array_fill(0, count($variableIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, product_id, sku, cost_price
            FROM product_variations
            WHERE product_id IN ({$placeholders}) AND status <> 'archived'
            ORDER BY id ASC
        ");
        $stmt->execute($variableIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variationsByProduct[(int) $row['product_id']][] = $row;
        }
    }

    $result = [];
    foreach ($products as $product) {
        $productId = (int) $product['id'];
        $isVariable = $product['catalog_type'] === 'variable';

        $units = [];
        if ($isVariable) {
            foreach ($variationsByProduct[$productId] ?? [] as $variation) {
                $variationId = (int) $variation['id'];
                // A variation's own cost_price is what Unit Cost auto-fills from when it's a
                // real, explicitly-entered positive value - it overrides the parent since a
                // variation can genuinely cost more/less to source than its siblings (e.g. a
                // limited-edition colorway). See variation_effective_cost() for why 0.00
                // counts as "not set" here, not just NULL. MOQ, however, belongs only to the
                // parent product - there is no separate variation-level MOQ.
                $units[] = [
                    'key' => $productId . ':' . $variationId,
                    'sku' => $variation['sku'],
                    'label' => variation_build_label($pdo, $variationId),
                    'cost_price' => variation_effective_cost($variation['cost_price'], $product['product_cost']),
                    'moq' => $product['moq'] !== null ? (int) $product['moq'] : null,
                ];
            }
        } else {
            $units[] = [
                'key' => $productId . ':0',
                'sku' => $product['sku'],
                'label' => null,
                'cost_price' => (float) $product['product_cost'],
                'moq' => $product['moq'] !== null ? (int) $product['moq'] : null,
            ];
        }

        if ($units === []) {
            continue;
        }

        $result[] = [
            'product_id' => $productId,
            'name' => $product['name'],
            'sku' => $product['sku'],
            'catalog_type' => $product['catalog_type'],
            'product_type' => $product['product_type'],
            'supplier_id' => $product['supplier_id'] !== null ? (int) $product['supplier_id'] : null,
            'supplier_name' => $product['supplier_name'],
            'category_id' => $product['category_id'] !== null ? (int) $product['category_id'] : null,
            'category_name' => $product['category_name'],
            'units' => $units,
        ];
    }

    return $result;
}
