<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('supplier-orders.view');

/**
 * Phase 8B - Supplier Performance & Purchase Intelligence. Read-only reporting built entirely
 * from supplier_orders/supplier_order_items - the same tables/columns modules/supplier-orders/
 * view.php and modules/products/control-center.php's Purchase History already read, never a
 * new calculation shape. No new tables/columns.
 *
 * Every metric below is scoped consistently:
 *   - "Total Supplier Orders" counts every order ever placed with that supplier (any status) -
 *     a plain historical fact.
 *   - "Total Purchase Spending", "Number of Products Purchased", and "Average Order Value" all
 *     exclude cancelled orders - a cancelled order can never have receiving history (see
 *     supplier_order_cancel()'s own guard), so nothing was ever actually bought on it, and
 *     counting its stored subtotal would overstate real spend.
 *   - Spending per order = SUM(supplier_order_items.subtotal) + supplier_orders.shipping_fee,
 *     the exact same "Total Purchase Amount" formula modules/supplier-orders/view.php already
 *     shows per order - never a second definition of "how much did this order cost."
 *   - "Average Delivery Time" and "Late Orders Count" only ever consider orders where BOTH
 *     order_date and (for lateness) expected_delivery_date/received_date are actually on file.
 *     received_date is only ever set by supplier_order_receive_item()/receive_all_remaining()
 *     when an order becomes fully received - it is real, not inferred. A supplier with no
 *     orders carrying the dates a metric needs shows "Not available" for that metric rather
 *     than a guessed or zeroed value.
 */

$appTitle = 'Supplier Performance';
$pdo = app_db();

// One batched query for every supplier's order-level facts (count, non-cancelled shipping
// total, delivery-time/lateness samples) - not one query per supplier.
$orderStatsStmt = $pdo->query("
    SELECT
        supplier_id,
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status <> 'cancelled' THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN status <> 'cancelled' THEN shipping_fee ELSE 0 END) AS active_shipping_total,
        MAX(CASE WHEN status <> 'cancelled' THEN order_date ELSE NULL END) AS latest_order_date,
        SUM(CASE WHEN order_date IS NOT NULL AND received_date IS NOT NULL THEN DATEDIFF(received_date, order_date) ELSE 0 END) AS delivery_days_sum,
        SUM(CASE WHEN order_date IS NOT NULL AND received_date IS NOT NULL THEN 1 ELSE 0 END) AS delivery_sample_count,
        SUM(CASE WHEN received_date IS NOT NULL AND expected_delivery_date IS NOT NULL AND received_date > expected_delivery_date THEN 1 ELSE 0 END) AS late_orders_count,
        SUM(CASE WHEN received_date IS NOT NULL AND expected_delivery_date IS NOT NULL THEN 1 ELSE 0 END) AS late_eligible_count
    FROM supplier_orders
    GROUP BY supplier_id
");
$orderStatsBySupplier = [];
foreach ($orderStatsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $orderStatsBySupplier[(int) $row['supplier_id']] = $row;
}

// One batched query for every supplier's item-level facts (items subtotal, distinct product
// count) - not one query per supplier. Cancelled orders excluded (see docblock above).
$itemStatsStmt = $pdo->query("
    SELECT
        so.supplier_id,
        SUM(soi.subtotal) AS items_subtotal,
        COUNT(DISTINCT soi.product_id) AS product_count
    FROM supplier_order_items soi
    INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
    WHERE so.status <> 'cancelled'
    GROUP BY so.supplier_id
");
$itemStatsBySupplier = [];
foreach ($itemStatsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $itemStatsBySupplier[(int) $row['supplier_id']] = $row;
}

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$searchTerm = trim((string) ($_GET['q'] ?? ''));

$rows = [];
foreach ($suppliers as $supplier) {
    if ($searchTerm !== '' && stripos($supplier['name'], $searchTerm) === false) {
        continue;
    }

    $supplierId = (int) $supplier['id'];
    $orderStats = $orderStatsBySupplier[$supplierId] ?? null;
    $itemStats = $itemStatsBySupplier[$supplierId] ?? null;

    $totalOrders = $orderStats !== null ? (int) $orderStats['total_orders'] : 0;
    $activeOrders = $orderStats !== null ? (int) $orderStats['active_orders'] : 0;
    $itemsSubtotal = $itemStats !== null ? (float) $itemStats['items_subtotal'] : 0.0;
    $shippingTotal = $orderStats !== null ? (float) $orderStats['active_shipping_total'] : 0.0;
    $totalSpending = $activeOrders > 0 ? ($itemsSubtotal + $shippingTotal) : null;
    $productCount = $itemStats !== null ? (int) $itemStats['product_count'] : 0;
    $averageOrderValue = ($totalSpending !== null && $activeOrders > 0) ? ($totalSpending / $activeOrders) : null;

    $deliverySampleCount = $orderStats !== null ? (int) $orderStats['delivery_sample_count'] : 0;
    $averageDeliveryDays = $deliverySampleCount > 0
        ? ((float) $orderStats['delivery_days_sum'] / $deliverySampleCount)
        : null;

    $lateEligibleCount = $orderStats !== null ? (int) $orderStats['late_eligible_count'] : 0;
    $lateOrdersCount = $lateEligibleCount > 0 ? (int) $orderStats['late_orders_count'] : null;

    $rows[] = [
        'id' => $supplierId,
        'name' => $supplier['name'],
        'total_orders' => $totalOrders,
        'total_spending' => $totalSpending,
        'product_count' => $productCount,
        'average_order_value' => $averageOrderValue,
        'average_delivery_days' => $averageDeliveryDays,
        'late_orders_count' => $lateOrdersCount,
        'late_eligible_count' => $lateEligibleCount,
        'latest_order_date' => $orderStats['latest_order_date'] ?? null,
    ];
}

usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

$naBadge = '<span class="text-muted">Not available</span>';

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1"><i class="bi bi-truck"></i> Supplier Performance</h2>
        <p class="page-description">Order volume, spending, and delivery reliability per supplier - built entirely from existing Supplier Order records.</p>
    </div>
</div>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Supplier name">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/reports/suppliers.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Supplier</th>
                <th class="text-end">Total Orders</th>
                <th class="text-end">Total Spending</th>
                <th class="text-end">Products Purchased</th>
                <th class="text-end">Avg Order Value</th>
                <th class="text-end">Avg Delivery Time</th>
                <th class="text-end">Late Orders</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td data-label="Supplier"><?php echo app_escape($row['name']); ?></td>
                    <td data-label="Total Orders" class="text-end"><?php echo (int) $row['total_orders']; ?></td>
                    <td data-label="Total Spending" class="text-end">
                        <?php echo $row['total_spending'] !== null ? ('RM ' . app_escape(number_format($row['total_spending'], 2))) : $naBadge; ?>
                    </td>
                    <td data-label="Products Purchased" class="text-end"><?php echo (int) $row['product_count']; ?></td>
                    <td data-label="Avg Order Value" class="text-end">
                        <?php echo $row['average_order_value'] !== null ? ('RM ' . app_escape(number_format($row['average_order_value'], 2))) : $naBadge; ?>
                    </td>
                    <td data-label="Avg Delivery Time" class="text-end">
                        <?php echo $row['average_delivery_days'] !== null ? (app_escape(number_format($row['average_delivery_days'], 1)) . ' day' . ($row['average_delivery_days'] == 1 ? '' : 's')) : $naBadge; ?>
                    </td>
                    <td data-label="Late Orders" class="text-end">
                        <?php if ($row['late_orders_count'] === null): ?>
                            <?php echo $naBadge; ?>
                        <?php else: ?>
                            <?php echo (int) $row['late_orders_count']; ?>
                            <span class="text-muted small">/ <?php echo (int) $row['late_eligible_count']; ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="" class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="/modules/reports/supplier_detail.php?id=<?php echo (int) $row['id']; ?>">View Detail</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <div class="empty-state-title">No Suppliers Match</div>
                            <p class="empty-state-text">Try adjusting or clearing your search.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
