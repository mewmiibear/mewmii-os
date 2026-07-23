<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/shipments.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('customers.view');

/**
 * Admin-side customer detail page: Orders / Customer Storage / Shipments, per the approved
 * Part 9 spec - internal admin view only, no customer login/session/public page. Every
 * query here is read-only aggregation over existing tables (mewmii_orders, customer_storage,
 * shipments) - the same data a future customer self-service portal would read, so no
 * restructuring is needed to add one later.
 */

$appTitle = 'Customer Detail';

$customerId = (int) ($_GET['id'] ?? 0);

if ($customerId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Customer not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$customerStmt = $pdo->prepare('SELECT id, name, email, phone, instagram_username FROM customers WHERE id = ? LIMIT 1');
$customerStmt->execute([$customerId]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Customer not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Section 1: Orders
$ordersStmt = $pdo->prepare('
    SELECT id, order_number, order_date, order_status, payment_status, total_amount, is_historical
    FROM mewmii_orders
    WHERE customer_id = ?
    ORDER BY id DESC
');
$ordersStmt->execute([$customerId]);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Section 2: Customer Storage (currently stored only - shipped lots show up under Shipments)
$storageStmt = $pdo->prepare("
    SELECT cs.id, cs.quantity, cs.status, cs.arrival_date, cs.storage_location, cs.variation_id,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name, o.order_number
    FROM customer_storage cs
    INNER JOIN products p ON p.id = cs.product_id
    LEFT JOIN product_variations pv ON pv.id = cs.variation_id
    LEFT JOIN mewmii_order_items oi ON oi.id = cs.order_item_id
    LEFT JOIN mewmii_orders o ON o.id = oi.order_id
    WHERE cs.customer_id = ? AND cs.status = 'stored' AND cs.quantity > 0
    ORDER BY cs.created_at DESC
");
$storageStmt->execute([$customerId]);
$storageItems = $storageStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($storageItems as &$storageItem) {
    $storageItem['variation_label'] = $storageItem['variation_id'] !== null ? variation_build_label($pdo, (int) $storageItem['variation_id']) : '';
}
unset($storageItem);

// Section 3: Shipments
$shipments = shipment_list_for_customer($pdo, $customerId);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1"><?php echo app_escape($customer['name']); ?></h2>
        <p class="text-muted mb-0">
            <?php echo app_escape($customer['email'] ?? '-'); ?> &middot; <?php echo app_escape($customer['phone'] ?? '-'); ?>
            <?php if (!empty($customer['instagram_username'])): ?> &middot; @<?php echo app_escape($customer['instagram_username']); ?><?php endif; ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/customers/index.php">Back to Customers</a>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Orders</h5>
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Order</th>
                <th>Date</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td>
                        <a href="/modules/orders/view.php?id=<?php echo (int) $order['id']; ?>"><?php echo app_escape($order['order_number']); ?></a>
                        <?php if (!empty($order['is_historical'])): ?>
                            <span class="badge bg-secondary">Historical</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($order['order_date'] ?? '-'); ?></td>
                    <td><?php echo order_status_badge($order['order_status']); ?></td>
                    <td><?php echo app_escape($order['payment_status']); ?></td>
                    <td>RM <?php echo app_escape(number_format((float) $order['total_amount'], 2)); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($orders === []): ?>
                <tr><td colspan="5" class="text-muted">No orders yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Customer Storage</h5>
    <p class="text-muted small mb-3">Products currently stored in the Mewmii warehouse for this customer.</p>
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Order</th>
                <th>Product</th>
                <th>Qty</th>
                <th>Arrival</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($storageItems as $item): ?>
                <tr>
                    <td>
                        <?php if (!empty($item['order_number'])): ?>
                            <?php echo app_escape($item['order_number']); ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo app_escape($item['sku']); ?> &mdash; <?php echo app_escape($item['product_name']); ?>
                        <?php if (!empty($item['variation_label'])): ?>
                            <div class="text-muted small"><?php echo app_escape($item['variation_label']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int) $item['quantity']; ?></td>
                    <td><?php echo app_escape($item['arrival_date'] ?? '-'); ?></td>
                    <td><span class="badge bg-info text-dark">Stored</span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($storageItems === []): ?>
                <tr><td colspan="5" class="text-muted">No items currently stored.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <a class="small" href="/modules/customer-storage/view.php?customer_id=<?php echo (int) $customerId; ?>">Full storage history &rarr;</a>
</div>

<div class="card p-4">
    <h5 class="mb-3">Shipments</h5>
    <p class="text-muted small mb-3">Physical packages sent to this customer, from orders or Ship My Box requests.</p>
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Shipment</th>
                <th>Orders</th>
                <th>Items</th>
                <th>Tracking</th>
                <th>Status</th>
                <th>Shipped</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($shipments as $shipment): ?>
                <tr>
                    <td><a href="/modules/shipments/view.php?id=<?php echo (int) $shipment['id']; ?>"><?php echo app_escape($shipment['shipment_number']); ?></a></td>
                    <td>
                        <?php foreach ($shipment['orders'] as $shipmentOrder): ?>
                            <div><a href="/modules/orders/view.php?id=<?php echo (int) $shipmentOrder['id']; ?>"><?php echo app_escape($shipmentOrder['order_number']); ?></a></div>
                        <?php endforeach; ?>
                        <?php if ($shipment['orders'] === []): ?>&mdash;<?php endif; ?>
                    </td>
                    <td><?php echo (int) $shipment['item_count']; ?></td>
                    <td>
                        <?php if (!empty($shipment['tracking_number'])): ?>
                            <?php echo app_escape($shipment['tracking_number']); ?>
                            <?php if (!empty($shipment['carrier'])): ?><div class="text-muted small"><?php echo app_escape($shipment['carrier']); ?></div><?php endif; ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td><?php echo shipment_status_badge($shipment['shipping_status']); ?></td>
                    <td><?php echo $shipment['shipped_at'] !== null ? app_escape(date('j F Y', strtotime($shipment['shipped_at']))) : '&mdash;'; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($shipments === []): ?>
                <tr><td colspan="6" class="text-muted">No shipments yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
