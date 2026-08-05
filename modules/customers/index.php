<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/saved_views_widget.php';
app_require_permission('customers.view');

$appTitle = 'Customers';
$pdo = app_db();

// UI/UX Sprint 10: search + real pagination, matching the convention already established by
// modules/products/index.php and modules/supplier-orders/index.php - replaces the previous
// hardcoded LIMIT 20 with no way to see the rest of the customer list.
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$perPage = 20;
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;

$whereSql = '';
$params = [];
if ($searchTerm !== '') {
    $whereSql = ' WHERE (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params = [$likeTerm, $likeTerm, $likeTerm];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c{$whereSql}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Order metrics come from a pre-aggregated derived table (one row per customer_id), not a
// raw LEFT JOIN mewmii_orders - joining the raw table directly alongside the existing
// point_transactions join would fan out both aggregates (a customer with 3 orders and 5
// point transactions would get COUNT/SUM figures inflated by the other join's row count).
// This is the same "aggregate first, then LEFT JOIN the single-row-per-key result" technique
// already used for the stock rollup in modules/products/index.php. Existing points logic
// (cm/mt/pt joins) is untouched.
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.email,
        c.phone,
        c.instagram_username,
        mt.name AS membership_tier,
        COALESCE(SUM(pt.amount), 0) AS points,
        COALESCE(orders_agg.total_orders, 0) AS total_orders,
        COALESCE(orders_agg.lifetime_spend, 0) AS lifetime_spend,
        orders_agg.last_order_date AS last_order_date
    FROM customers c
    LEFT JOIN customer_memberships cm ON cm.customer_id = c.id AND cm.status = 'active'
    LEFT JOIN membership_tiers mt ON mt.id = cm.membership_tier_id
    LEFT JOIN point_transactions pt ON pt.customer_id = c.id
    LEFT JOIN (
        SELECT customer_id, COUNT(*) AS total_orders, SUM(total_amount) AS lifetime_spend, MAX(order_date) AS last_order_date
        FROM mewmii_orders
        GROUP BY customer_id
    ) orders_agg ON orders_agg.customer_id = c.id
    {$whereSql}
    GROUP BY c.id, c.name, c.email, c.phone, c.instagram_username, mt.name, orders_agg.total_orders, orders_agg.lifetime_spend, orders_agg.last_order_date
    ORDER BY c.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canManage = app_has_permission('customers.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">Customers</h1>
        <p class="text-muted mb-0">CRM foundation for memberships, loyalty, and customer storage.</p>
    </div>
    <?php if ($canManage): ?>
        <div class="action-bar">
            <a class="btn btn-primary" href="/modules/customers/create.php">Add Customer</a>
            <a class="btn btn-outline-secondary" href="/modules/customers/import.php">Import CSV</a>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Customer created.</div>
<?php endif; ?>

<?php render_saved_views_widget($pdo, 'customers'); ?>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Name, email, or phone">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/customers/index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle responsive-stack-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Tier</th>
                    <th>Points</th>
                    <th>Total Orders</th>
                    <th>Lifetime Spend</th>
                    <th>Last Order</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td data-label="Name"><?php echo app_escape(app_customer_display_name($customer)); ?></td>
                        <td data-label="Email"><?php echo app_escape($customer['email'] ?? '-'); ?></td>
                        <td data-label="Phone"><?php echo app_escape($customer['phone'] ?? '-'); ?></td>
                        <td data-label="Tier"><?php echo app_escape($customer['membership_tier']); ?></td>
                        <td data-label="Points"><?php echo app_escape((string) $customer['points']); ?></td>
                        <td data-label="Total Orders"><?php echo (int) $customer['total_orders']; ?></td>
                        <td data-label="Lifetime Spend">RM <?php echo app_escape(number_format((float) $customer['lifetime_spend'], 2)); ?></td>
                        <td data-label="Last Order"><?php echo $customer['last_order_date'] !== null ? app_escape($customer['last_order_date']) : 'Never'; ?></td>
                        <td data-label="" class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="/modules/customers/view.php?id=<?php echo (int) $customer['id']; ?>">History</a>
                            <?php if ($canManage): ?>
                                <a class="btn btn-sm btn-outline-primary" href="/modules/customers/edit.php?id=<?php echo (int) $customer['id']; ?>">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($customers === []): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <div class="empty-state-title"><?php echo $searchTerm !== '' ? 'No Customers Match This Search' : 'No Customers Yet'; ?></div>
                                <p class="empty-state-text"><?php echo $searchTerm !== '' ? 'Try adjusting or clearing your search.' : 'Customer profiles will appear here once added.'; ?></p>
                                <?php if ($canManage && $searchTerm === ''): ?>
                                    <a class="btn btn-primary btn-sm" href="/modules/customers/create.php">Add Customer</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    $pageUrl = static function (int $targetPage): string {
        return '/modules/customers/index.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $rangeStart; ?>&ndash;<?php echo (int) $rangeEnd; ?> of <?php echo (int) $totalCount; ?> customer<?php echo $totalCount === 1 ? '' : 's'; ?>
            <?php else: ?>
                0 customers
            <?php endif; ?>
        </p>
        <?php if ($totalPages > 1): ?>
            <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(max(1, $page - 1))); ?>">&laquo; Prev</a>
                <span class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(min($totalPages, $page + 1))); ?>">Next &raquo;</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>