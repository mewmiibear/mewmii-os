<?php

require_once __DIR__ . '/wc_client.php';
require_once __DIR__ . '/sync_log.php';
require_once __DIR__ . '/supplier_orders.php';
require_once __DIR__ . '/order_fulfillment.php';

/**
 * order_status is now ALWAYS computed by order_recompute_status() (see
 * includes/order_fulfillment.php) - this array is no longer a manual-advance sequence (there
 * is no "next status" button anymore), just the full set of values the column can hold, used
 * for labels/badges and for the historical-order CSV importer's allowed-value list (a
 * historical order's status is set once at import time and never recomputed - see
 * includes/order_import.php). 'cancelled' is a side branch reachable from any non-terminal
 * stage (see the Cancel Order action), not a step in the automatic progression.
 */
const ORDER_STATUS_WORKFLOW = ['pending', 'processing', 'waiting_stock', 'waiting_ship_my_box', 'ready_to_ship', 'partially_fulfilled', 'shipped', 'completed'];

function order_status_label(string $status): string
{
    $labels = [
        'pending' => 'Pending Payment',
        'processing' => 'Processing',
        'waiting_stock' => 'Waiting Stock',
        'waiting_ship_my_box' => 'Waiting Ship My Box',
        'ready_to_ship' => 'Ready To Ship',
        'partially_fulfilled' => 'Partially Fulfilled',
        'shipped' => 'Shipped',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    return $labels[$status] ?? ucfirst($status);
}

function order_status_badge(string $status): string
{
    $colors = [
        'pending' => 'secondary',
        'processing' => 'info text-dark',
        'waiting_stock' => 'warning text-dark',
        'waiting_ship_my_box' => 'warning text-dark',
        'ready_to_ship' => 'primary',
        'partially_fulfilled' => 'primary',
        'shipped' => 'dark',
        'completed' => 'success',
        'cancelled' => 'danger',
    ];
    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' . $color . '">' . htmlspecialchars(order_status_label($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Receipt visibility for a WooCommerce preorder order (see includes/wc_order_import.php) -
 * purely informational display, entirely independent of payment_status/order_status. Returns
 * null for a non-preorder order (is_preorder_request = 0), meaning there is nothing to show.
 * Expects a row with at least is_preorder_request, receipt_url, receipt_status.
 */
function order_receipt_status_label(array $order): ?string
{
    if (empty($order['is_preorder_request'])) {
        return null;
    }

    if ($order['receipt_status'] === 'approved') {
        return 'Approved';
    }
    if ($order['receipt_status'] === 'rejected') {
        return 'Rejected';
    }

    return !empty($order['receipt_url']) ? 'Receipt Submitted' : 'Awaiting Receipt Upload';
}

function order_receipt_status_badge(array $order): string
{
    $label = order_receipt_status_label($order);
    if ($label === null) {
        return '';
    }

    $colors = [
        'Approved' => 'success',
        'Rejected' => 'danger',
        'Receipt Submitted' => 'info text-dark',
        'Awaiting Receipt Upload' => 'secondary',
    ];

    return '<span class="badge bg-' . $colors[$label] . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Customer Status Message Generator (v1) - builds the full set of copy-paste message
 * templates for one order, keyed by message type, for the "Customer Status Message" card on
 * modules/orders/view.php. Pure string composition over data the caller already fetched
 * (the order row, its items, its shipments) - no new queries, no writes, and no order/
 * inventory/fulfillment logic of its own. Deliberately does NOT decide which template
 * matches the order's actual current state - that stays an admin judgment call via the
 * page's message-type selector, since a message that reads correctly is only useful if a
 * human confirms it against the order before sending it anywhere. $items is the same
 * per-item array modules/orders/view.php already builds (product_name/variation_label from
 * mewmii_order_items); $orderShipments is the same shipments list already fetched there,
 * newest first, so $orderShipments[0] is the most recent shipment for the Shipped template.
 */
function order_build_status_messages(array $order, array $items, array $orderShipments): array
{
    $customerName = $order['customer_name'] ?? 'there';
    $orderNumber = $order['order_number'];

    $productNames = [];
    foreach ($items as $item) {
        $label = $item['product_name'];
        if (!empty($item['variation_label'])) {
            $label .= ' (' . $item['variation_label'] . ')';
        }
        $productNames[] = $label;
    }
    $productList = $productNames !== [] ? implode(', ', $productNames) : 'your item(s)';

    $latestShipment = $orderShipments[0] ?? null;
    $trackingLine = '';
    if ($latestShipment !== null && !empty($latestShipment['tracking_number'])) {
        $trackingLine = ' Tracking: ' . $latestShipment['tracking_number'];
        if (!empty($latestShipment['carrier'])) {
            $trackingLine .= ' (' . $latestShipment['carrier'] . ')';
        }
        $trackingLine .= '.';
    }

    return [
        'payment_confirmed' => "Hi {$customerName}, thank you! Your payment for order {$orderNumber} ({$productList}) has been confirmed and your preorder is now being processed.",
        'waiting_supplier' => "Hi {$customerName}, a quick update on order {$orderNumber} ({$productList}) - your item(s) are still on order from our supplier. We'll keep you posted as soon as they arrive!",
        'arrived_warehouse' => "Good news, {$customerName}! Your item(s) from order {$orderNumber} ({$productList}) have arrived at our warehouse and are being processed.",
        'ready_for_shipment' => "Hi {$customerName}, your item(s) from order {$orderNumber} ({$productList}) are packed and ready to ship whenever you'd like - let us know if you'd like to combine with other preorders first!",
        'shipped' => "Your order {$orderNumber} ({$productList}) has shipped!{$trackingLine}",
    ];
}

/**
 * Best-effort push of tracking info to the linked WooCommerce order, if one exists.
 * Mewmii OS stays the source of truth either way - this never blocks or rolls back the
 * local save (see modules/orders/view.php's mark_shipped handler), it only logs success/
 * failure via sync_log.php, the same convention modules/products/sync.php uses.
 *
 * NOTE: there is currently no code path anywhere in this app that populates
 * mewmii_orders.woocommerce_order_id, so today this is a no-op for every order (the column
 * only exists as a placeholder). The `_tracking_number`/`_shipping_provider` meta keys
 * below match the WooCommerce Shipment Tracking plugin's convention, which is the most
 * common one - if a different tracking plugin/theme is used on the live store, these key
 * names will need to be adjusted to match whatever it actually reads.
 */
function order_sync_tracking_to_woocommerce(PDO $pdo, int $orderId): void
{
    if (!wc_client_is_configured()) {
        return;
    }

    $stmt = $pdo->prepare('SELECT woocommerce_order_id, tracking_number, shipping_carrier, shipped_at FROM mewmii_orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || empty($order['woocommerce_order_id'])) {
        return;
    }

    try {
        wc_client_put('orders/' . (int) $order['woocommerce_order_id'], [
            'meta_data' => [
                ['key' => '_tracking_number', 'value' => (string) $order['tracking_number']],
                ['key' => '_tracking_provider', 'value' => (string) $order['shipping_carrier']],
                ['key' => '_date_shipped', 'value' => $order['shipped_at']],
            ],
        ]);
        sync_log_success($pdo, 'woocommerce_order_tracking_sync', $orderId);
    } catch (Throwable $exception) {
        sync_log_failure($pdo, 'woocommerce_order_tracking_sync', $exception->getMessage(), $orderId);
    }
}

// --- Development cleanup: relationship-based delete eligibility, no test-flag column ----

/**
 * Hard-deletes a customer order, but only if it's still in its very first (Draft-
 * equivalent) state and nothing has actually happened to it yet: order_status/
 * payment_status both still 'pending' (this app has no separate literal "draft" status -
 * 'pending' before any payment approval or workflow advance IS that state), no shipment
 * recorded, and - checked directly against the ledger rather than trusting the status
 * fields alone - no inventory_transactions logged against it (order_reserve/order_ship).
 * Once any of that has happened, the order must be Cancelled (see modules/orders/view.php's
 * Cancel Order action) instead of deleted - it's real business history from that point on.
 */
function order_delete_if_unused(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare('SELECT order_status, payment_status, shipped_at FROM mewmii_orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    if ($order['order_status'] !== 'pending' || $order['payment_status'] !== 'pending' || $order['shipped_at'] !== null) {
        throw new RuntimeException('This order has already been processed and cannot be deleted. Cancel it instead.');
    }

    $txStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE reference_type = 'order' AND reference_id = ?");
    $txStmt->execute([$orderId]);
    if ((int) $txStmt->fetchColumn() > 0) {
        throw new RuntimeException('This order has inventory history and cannot be deleted. Cancel it instead.');
    }

    $pdo->prepare('DELETE FROM mewmii_orders WHERE id = ?')->execute([$orderId]);
}

/**
 * Every customer order still eligible for a real delete (see order_delete_if_unused()) -
 * the "safe to delete" list for the Data Cleanup tool. Same NOT EXISTS approach as
 * catalog_list_deletable_products() for the same reason; the delete action itself still
 * re-validates via order_delete_if_unused().
 */
function order_list_deletable(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT o.id, o.order_number, o.order_status, o.payment_status, o.created_at
        FROM mewmii_orders o
        WHERE o.order_status = 'pending' AND o.payment_status = 'pending' AND o.shipped_at IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM inventory_transactions x WHERE x.reference_type = 'order' AND x.reference_id = o.id
          )
        ORDER BY o.created_at DESC
        LIMIT 500
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Order Management UX Upgrade: Product Picker + full edit reconciliation -------------

/**
 * Order statuses in which items/quantities/pricing can still be freely changed - nothing has
 * been packed into a shipment yet. 'pending' has no reservation at all; 'processing',
 * 'waiting_stock', and 'waiting_ship_my_box' may already have reserved/allocated stock for
 * the CURRENT item set, so order_apply_edit() releases and re-reserves around the edit
 * rather than patching reserved_quantity directly. Once order_status reaches
 * 'ready_to_ship' a shipment already exists referencing specific order items (see
 * includes/shipments.php) - editing at that point could desync the shipment's item list, so
 * 'ready_to_ship'/'partially_fulfilled'/'shipped'/'completed' (and 'cancelled') are locked to
 * notes-only - see modules/orders/edit.php.
 */
const ORDER_EDITABLE_STATUSES = ['pending', 'processing', 'waiting_stock', 'waiting_ship_my_box'];

/**
 * Every product for the customer Order "+ Add Product" picker, grouped exactly like
 * supplier_order_picker_products(): a simple product is one selectable unit, a variable
 * product is a container whose active variations are each their own selectable unit - the
 * parent is never itself orderable (matches catalog_sellable_units()'s existing rule, so
 * this never introduces a second way to "buy" a variable product). Only status = 'active'
 * products are offered here (draft/hidden/archived are not customer-facing).
 */
function order_picker_products(PDO $pdo): array
{
    $productsStmt = $pdo->query("
        SELECT p.id, p.sku, p.name, p.catalog_type, p.product_type, p.selling_price,
               p.sale_enabled, p.sale_price, p.sale_start_date, p.preorder_closing_date,
               p.preorder_reopened_at, p.availability_override, p.status,
               p.brand_id, b.name AS brand_name, p.supplier_id, s.name AS supplier_name,
               (SELECT cat.id FROM product_category_relationships pcr
                   INNER JOIN categories cat ON cat.id = pcr.category_id
                   WHERE pcr.product_id = p.id ORDER BY pcr.category_id ASC LIMIT 1) AS category_id,
               (SELECT cat.name FROM product_category_relationships pcr
                   INNER JOIN categories cat ON cat.id = pcr.category_id
                   WHERE pcr.product_id = p.id ORDER BY pcr.category_id ASC LIMIT 1) AS category_name,
               (SELECT image_path FROM product_images pi
                   WHERE pi.product_id = p.id AND pi.variation_id IS NULL AND pi.image_type = 'main'
                   ORDER BY pi.id DESC LIMIT 1) AS thumb_path
        FROM products p
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.status = 'active'
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
            SELECT pv.id, pv.product_id, pv.sku, pv.price_mode, pv.custom_price,
                   COALESCE(inv.available_quantity, 0) AS available_quantity
            FROM product_variations pv
            LEFT JOIN mewmii_inventory inv ON inv.variation_id = pv.id
            WHERE pv.product_id IN ({$placeholders}) AND pv.status <> 'archived'
            ORDER BY pv.id ASC
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
        $stage = catalog_product_lifecycle_stage($product);
        $override = $product['availability_override'] ?? 'auto';

        $units = [];
        if ($isVariable) {
            foreach ($variationsByProduct[$productId] ?? [] as $variation) {
                $variationId = (int) $variation['id'];
                $available = (int) $variation['available_quantity'];
                $isAvailable = order_unit_is_available($product, $stage, $override, $product['product_type'], $available);
                $effectiveParentPrice = catalog_product_effective_price($product);
                $price = variation_effective_price($variation, $effectiveParentPrice);

                $units[] = [
                    'key' => $productId . ':' . $variationId,
                    'sku' => $variation['sku'],
                    'label' => variation_build_label($pdo, $variationId),
                    'price' => $price,
                    'is_available' => $isAvailable,
                ];
            }
        } else {
            $available = 0;
            $invStmt = $pdo->prepare('SELECT available_quantity FROM mewmii_inventory WHERE product_id = ? AND variation_id IS NULL');
            $invStmt->execute([$productId]);
            $available = (int) $invStmt->fetchColumn();

            $units[] = [
                'key' => $productId . ':0',
                'sku' => $product['sku'],
                'label' => null,
                'price' => catalog_product_effective_price($product),
                'is_available' => order_unit_is_available($product, $stage, $override, $product['product_type'], $available),
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
            'brand_id' => $product['brand_id'] !== null ? (int) $product['brand_id'] : null,
            'supplier_id' => $product['supplier_id'] !== null ? (int) $product['supplier_id'] : null,
            'category_id' => $product['category_id'] !== null ? (int) $product['category_id'] : null,
            'thumb_path' => $product['thumb_path'],
            'stage' => $stage,
            'units' => $units,
        ];
    }

    return $result;
}

/**
 * Whether one sellable unit is currently purchasable - the exact same priority already
 * used in modules/orders/create.php's stock-availability check (override, then lifecycle
 * for Preorder/Early Bird, then quantity for Ready Stock), factored out so the picker never
 * re-derives it a second way. $availableQuantity is only consulted for ready_stock.
 */
function order_unit_is_available(array $product, string $stage, string $override, string $productType, int $availableQuantity): bool
{
    if ($override === 'out_of_stock') {
        return false;
    }
    if (in_array($productType, ['preorder', 'early_bird'], true)) {
        return $override === 'available' || catalog_product_is_orderable($product);
    }

    return catalog_product_availability_status($product, $availableQuantity) === 'available';
}

/**
 * Full add/edit/remove reconciliation for an existing customer order - mirrors
 * supplier_order_apply_edit()'s shape exactly, adapted for the two things unique to
 * customer orders: (1) inventory reservation, handled ONLY through the existing
 * inventory_release_for_order()/inventory_reserve_for_order() (never a new partial-
 * reservation mechanism - see ORDER_EDITABLE_STATUSES's doc comment), and (2) a line
 * already allocated into Customer Storage (supplier_order_item_customer_storage_allocated()
 * - a generic order_item_id lookup despite its supplier-order-sounding name) can never be
 * removed or reduced below what's already been allocated, exactly like a received supplier
 * order line can't be reduced below what's arrived.
 *
 * $newLines is a list of ['unit' => catalog_sellable_units() row, 'quantity', 'discount'].
 * Caller (modules/orders/create.php's counterpart in edit.php) is responsible for the
 * surrounding transaction and for confirming order_status is in ORDER_EDITABLE_STATUSES
 * before calling this at all.
 */
function order_apply_edit(PDO $pdo, int $orderId, int $customerId, array $newLines, float $shippingFee, string $customerNote, string $internalNote): void
{
    $orderStmt = $pdo->prepare('SELECT order_status, is_historical FROM mewmii_orders WHERE id = ? FOR UPDATE');
    $orderStmt->execute([$orderId]);
    $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if ($orderRow === false) {
        throw new RuntimeException('Order not found.');
    }
    // A historical (imported) order must never go through the release-then-reserve cycle
    // below - see includes/order_import.php's doc comment. Editing one is a data-cleanup
    // operation (SKU/name typo, wrong date, etc.), not covered here.
    if (!empty($orderRow['is_historical'])) {
        throw new RuntimeException('This is a historical (imported) order and cannot be edited through this workflow.');
    }
    $orderStatus = $orderRow['order_status'];
    if (!in_array($orderStatus, ORDER_EDITABLE_STATUSES, true)) {
        throw new RuntimeException('This order can no longer be edited - use Cancel or the adjustment workflow instead.');
    }

    $existingStmt = $pdo->prepare('SELECT id, product_id, variation_id, quantity FROM mewmii_order_items WHERE order_id = ?');
    $existingStmt->execute([$orderId]);
    $existingByKey = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0);
        $existingByKey[$key] = $row;
    }

    $newByKey = [];
    foreach ($newLines as $line) {
        $unit = $line['unit'];
        $key = $unit['product_id'] . ':' . (int) ($unit['variation_id'] ?? 0);
        $newByKey[$key] = $line;
    }

    // Removed lines: only safe if nothing has been allocated to Customer Storage yet.
    foreach ($existingByKey as $key => $existing) {
        if (isset($newByKey[$key])) {
            continue;
        }

        $itemId = (int) $existing['id'];
        $allocated = supplier_order_item_customer_storage_allocated($pdo, $itemId);
        if ($allocated > 0) {
            $unit = catalog_describe_unit($pdo, (int) $existing['product_id'], $existing['variation_id'] !== null ? (int) $existing['variation_id'] : null);
            throw new RuntimeException($unit['product_name'] . ' has already been allocated to Customer Storage and cannot be removed from this order.');
        }
    }

    // Updated lines: quantity can never drop below what's already allocated.
    foreach ($newByKey as $key => $line) {
        if (!isset($existingByKey[$key])) {
            continue;
        }

        $existing = $existingByKey[$key];
        $itemId = (int) $existing['id'];
        $allocated = supplier_order_item_customer_storage_allocated($pdo, $itemId);
        $newQuantity = (int) $line['quantity'];

        if ($newQuantity < $allocated) {
            $unit = catalog_describe_unit($pdo, (int) $existing['product_id'], $existing['variation_id'] !== null ? (int) $existing['variation_id'] : null);
            throw new RuntimeException($unit['product_name'] . ' has already allocated ' . $allocated . ' unit(s) to Customer Storage and cannot be reduced below that.');
        }
    }

    // Reservation is a whole-order concept (see inventory_reserve_for_order()) - release
    // against the CURRENT item set first (a no-op if nothing was ever reserved, e.g.
    // status is still 'pending'), apply every item change, then re-reserve against the NEW
    // set. If the new set can't be fully reserved (insufficient stock), this throws and the
    // whole edit rolls back - the order is left exactly as it was before the edit attempt.
    $needsReservationCycle = $orderStatus !== 'pending';
    if ($needsReservationCycle) {
        inventory_release_for_order($pdo, $orderId);
    }

    foreach ($existingByKey as $key => $existing) {
        if (!isset($newByKey[$key])) {
            $pdo->prepare('DELETE FROM mewmii_order_items WHERE id = ?')->execute([(int) $existing['id']]);
        }
    }

    foreach ($newByKey as $key => $line) {
        $unit = $line['unit'];
        $quantity = (int) $line['quantity'];
        $discount = round((float) $line['discount'], 2);
        $subtotal = round(($quantity * (float) $unit['selling_price']) - $discount, 2);

        if (isset($existingByKey[$key])) {
            $pdo->prepare('UPDATE mewmii_order_items SET quantity = ?, selling_price = ?, discount = ?, subtotal = ? WHERE id = ?')
                ->execute([$quantity, $unit['selling_price'], $discount, $subtotal, (int) $existingByKey[$key]['id']]);
        } else {
            $variationLabel = $unit['variation_id'] !== null ? variation_build_label($pdo, (int) $unit['variation_id']) : null;
            $pdo->prepare('
                INSERT INTO mewmii_order_items (order_id, product_id, variation_id, variation_label, quantity, selling_price, discount, subtotal, cost_snapshot)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $orderId, $unit['product_id'], $unit['variation_id'], $variationLabel,
                $quantity, $unit['selling_price'], $discount, $subtotal, $unit['cost_price'],
            ]);
        }
    }

    if ($needsReservationCycle) {
        inventory_reserve_for_order($pdo, $orderId);
    }

    $totalsStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity * selling_price), 0) AS gross, COALESCE(SUM(discount), 0) AS total_discount FROM mewmii_order_items WHERE order_id = ?');
    $totalsStmt->execute([$orderId]);
    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
    $subtotal = round((float) $totals['gross'], 2);
    $discountTotal = round((float) $totals['total_discount'], 2);
    $totalAmount = round($subtotal - $discountTotal + $shippingFee, 2);

    $pdo->prepare('UPDATE mewmii_orders SET customer_id = ?, subtotal = ?, discount = ?, shipping_fee = ?, total_amount = ?, customer_note = ?, internal_note = ? WHERE id = ?')
        ->execute([$customerId, $subtotal, $discountTotal, round($shippingFee, 2), $totalAmount, $customerNote !== '' ? $customerNote : null, $internalNote !== '' ? $internalNote : null, $orderId]);

    // Item changes can change what's reserved (e.g. removing the item that was blocking
    // reservation) - resync the automatically computed order_status to match.
    order_recompute_status($pdo, $orderId);
}
