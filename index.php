<?php
require_once __DIR__ . '/includes/bootstrap.php';
app_require_login();
app_require_permission('dashboard.view');

$appTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$pdo = app_db();
$stats = [
    'products' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'customers' => (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
    'orders' => (int) $pdo->query('SELECT COUNT(*) FROM mewmii_orders')->fetchColumn(),
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
];
?>
<div class="row g-4">
    <div class="col-md-3">
        <div class="card p-4">
            <h6 class="text-muted">Products</h6>
            <h2 class="fw-bold"><?php echo app_escape((string) $stats['products']); ?></h2>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-4">
            <h6 class="text-muted">Customers</h6>
            <h2 class="fw-bold"><?php echo app_escape((string) $stats['customers']); ?></h2>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-4">
            <h6 class="text-muted">Orders</h6>
            <h2 class="fw-bold"><?php echo app_escape((string) $stats['orders']); ?></h2>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-4">
            <h6 class="text-muted">Users</h6>
            <h2 class="fw-bold"><?php echo app_escape((string) $stats['users']); ?></h2>
        </div>
    </div>
</div>
<div class="row g-4 mt-1">
    <div class="col-lg-8">
        <div class="card p-4">
            <h4 class="mb-3">Phase 1 foundation</h4>
            <ul class="mb-0">
                <li>Secure authentication flow and role-aware access</li>
                <li>Database schema aligned with Mewmii OS specifications</li>
                <li>Dashboard and modular navigation prepared for future modules</li>
            </ul>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-4">
            <h4 class="mb-3">Next upgrades</h4>
            <p class="text-muted mb-0">Products, customers, orders, and supplier flows will be implemented after the foundation is fully approved.</p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>