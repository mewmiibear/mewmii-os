<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_login();

$appTitle = 'Customers';
require_once __DIR__ . '/../../includes/header.php';

$stmt = app_db()->query('
    SELECT
        c.id,
        c.name,
        c.email,
        c.phone,
        mt.name AS membership_tier,
        COALESCE(SUM(pt.amount), 0) AS points
    FROM customers c
    LEFT JOIN customer_memberships cm ON cm.customer_id = c.id AND cm.status = \'active\'
    LEFT JOIN membership_tiers mt ON mt.id = cm.membership_tier_id
    LEFT JOIN point_transactions pt ON pt.customer_id = c.id
    GROUP BY c.id, c.name, c.email, c.phone, mt.name
    ORDER BY c.id DESC
    LIMIT 20
');
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Customers</h2>
        <p class="text-muted mb-0">CRM foundation for memberships, loyalty, and customer storage.</p>
    </div>
</div>
<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Tier</th>
                <th>Points</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $customer): ?>
                <tr>
                    <td><?php echo app_escape($customer['name']); ?></td>
                    <td><?php echo app_escape($customer['email'] ?? '-'); ?></td>
                    <td><?php echo app_escape($customer['phone'] ?? '-'); ?></td>
                    <td><?php echo app_escape($customer['membership_tier']); ?></td>
                    <td><?php echo app_escape((string) $customer['points']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>