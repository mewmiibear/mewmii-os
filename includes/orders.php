<?php

require_once __DIR__ . '/wc_client.php';
require_once __DIR__ . '/sync_log.php';

/**
 * Shared order-workflow config for the linear order_status progression (see
 * modules/orders/view.php and modules/orders/index.php) - one place both pages read
 * stage labels/colors/next-step from, so they can never drift out of sync with each other.
 * 'cancelled' is deliberately NOT part of this list - it's a side branch reachable from any
 * non-terminal stage (see the Cancel Order action), not a step in the forward progression.
 */
const ORDER_STATUS_WORKFLOW = ['pending', 'processing', 'ready_to_ship', 'shipped', 'completed'];

function order_status_label(string $status): string
{
    $labels = [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'ready_to_ship' => 'Ready to Ship',
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
        'ready_to_ship' => 'warning text-dark',
        'shipped' => 'primary',
        'completed' => 'success',
        'cancelled' => 'danger',
    ];
    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' . $color . '">' . htmlspecialchars(order_status_label($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * The single valid next step for $status in the linear workflow, or null if there is none
 * (already at/after 'completed', or 'cancelled'). Used to render exactly one action button
 * and, server-side, to reject any status jump that isn't this exact value.
 */
function order_status_next(string $status): ?string
{
    $index = array_search($status, ORDER_STATUS_WORKFLOW, true);
    if ($index === false || !isset(ORDER_STATUS_WORKFLOW[$index + 1])) {
        return null;
    }

    return ORDER_STATUS_WORKFLOW[$index + 1];
}

function order_status_next_action_label(string $status): ?string
{
    $labels = [
        'pending' => 'Start Processing',
        'processing' => 'Mark Ready to Ship',
        'ready_to_ship' => 'Mark Shipped',
        'shipped' => 'Mark Completed',
    ];

    return $labels[$status] ?? null;
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
