<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('orders.view');

/**
 * Sales Intelligence (Phase 1) - read-only reporting over mewmii_orders/mewmii_order_items.
 * "Valid order" here reuses the exact payment_status='paid' AND order_status<>'cancelled'
 * definition already established in modules/inventory/reserve.php's candidate query and
 * includes/purchase_planning.php's paid-demand calculation - not a new/guessed rule.
 * Unlike that reservation-specific query, is_historical is deliberately NOT excluded here:
 * historical (imported) orders are real past sales and belong in revenue/best-seller figures,
 * even though they're not eligible for live reservation.
 */

$appTitle = 'Sales Report';
$pdo = app_db();

$periodOptions = ['all', 'today', '7days', '30days', '90days'];
$period = in_array($_GET['period'] ?? '', $periodOptions, true) ? $_GET['period'] : '30days';

$dateCondition = '';
switch ($period) {
    case 'today':
        $dateCondition = " AND o.order_date = CURDATE()";
        break;
    case '7days':
        $dateCondition = " AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
        break;
    case '30days':
        $dateCondition = " AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
        break;
    case '90days':
        $dateCondition = " AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 89 DAY)";
        break;
    case 'all':
    default:
        $dateCondition = '';
        break;
}

// Shared "valid order" condition - see comment above.
$validOrderCondition = "o.payment_status = 'paid' AND o.order_status <> 'cancelled'{$dateCondition}";

// Summary - one aggregate query, no GROUP BY (single row).
$summaryStmt = $pdo->query("
    SELECT
        COUNT(DISTINCT o.id) AS total_orders,
        COALESCE(SUM(oi.quantity), 0) AS units_sold,
        COALESCE(SUM(oi.subtotal), 0) AS revenue
    FROM mewmii_orders o
    INNER JOIN mewmii_order_items oi ON oi.order_id = o.id
    WHERE {$validOrderCondition}
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
$summary['average_order_value'] = ((int) $summary['total_orders'] > 0)
    ? ((float) $summary['revenue'] / (int) $summary['total_orders'])
    : null;

// Best Selling Products - one GROUP BY query, capped at top 20 by revenue. Grouped by parent
// product_id (oi.product_id is always the parent product regardless of variation, per schema),
// so a variable product's sales are rolled up across all its variations into one row.
$bestSellersStmt = $pdo->query("
    SELECT
        p.id AS product_id,
        p.name AS product_name,
        p.sku,
        SUM(oi.quantity) AS units_sold,
        COUNT(DISTINCT oi.order_id) AS order_count,
        SUM(oi.subtotal) AS revenue
    FROM mewmii_order_items oi
    INNER JOIN mewmii_orders o ON o.id = oi.order_id
    INNER JOIN products p ON p.id = oi.product_id
    WHERE {$validOrderCondition}
    GROUP BY p.id, p.name, p.sku
    ORDER BY revenue DESC
    LIMIT 20
");
$bestSellers = $bestSellersStmt->fetchAll(PDO::FETCH_ASSOC);

// Product row links go to modules/products/view.php, which requires products.view - the
// destination controls permission, not this page's own orders.view gate.
$canViewProducts = app_has_permission('products.view');

$periodLabels = [
    'all' => 'All Time',
    'today' => 'Today',
    '7days' => 'Last 7 Days',
    '30days' => 'Last 30 Days',
    '90days' => 'Last 90 Days',
];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Sales Report</h2>
        <p class="text-muted mb-0">Best selling products and sales totals - paid, non-cancelled orders only.</p>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($periodLabels as $value => $label): ?>
        <a class="btn btn-sm <?php echo $period === $value ? 'btn-primary' : 'btn-outline-secondary'; ?>"
           href="/modules/reports/sales.php?period=<?php echo app_escape($value); ?>">
            <?php echo app_escape($label); ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-4">
            <div class="text-muted small">Total Orders</div>
            <div class="fs-4"><?php echo (int) $summary['total_orders']; ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-4">
            <div class="text-muted small">Units Sold</div>
            <div class="fs-4"><?php echo (int) $summary['units_sold']; ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-4">
            <div class="text-muted small">Revenue</div>
            <div class="fs-4">RM <?php echo app_escape(number_format((float) $summary['revenue'], 2)); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-4">
            <div class="text-muted small">Average Order Value</div>
            <div class="fs-4">
                <?php if ($summary['average_order_value'] !== null): ?>
                    RM <?php echo app_escape(number_format($summary['average_order_value'], 2)); ?>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card p-4">
    <h5 class="mb-3">Best Selling Products</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Units Sold</th>
                    <th>Order Count</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bestSellers as $row): ?>
                    <tr>
                        <td>
                            <?php if ($canViewProducts): ?>
                                <a href="/modules/products/view.php?id=<?php echo (int) $row['product_id']; ?>"><?php echo app_escape($row['product_name']); ?></a>
                            <?php else: ?>
                                <?php echo app_escape($row['product_name']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo app_escape($row['sku']); ?></td>
                        <td><?php echo (int) $row['units_sold']; ?></td>
                        <td><?php echo (int) $row['order_count']; ?></td>
                        <td>RM <?php echo app_escape(number_format((float) $row['revenue'], 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($bestSellers === []): ?>
                    <tr><td colspan="5" class="text-muted">No sales in this period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
