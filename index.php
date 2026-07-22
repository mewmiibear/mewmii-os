<?php
require_once __DIR__ . '/includes/bootstrap.php';

app_require_permission('dashboard.view');

$appTitle = 'Dashboard';

require_once __DIR__ . '/includes/header.php';


$pdo = app_db();

$stats = [
    'products' => 0,
    'customers' => 0,
    'orders' => 0,
    'users' => 0,
];


// Check tables before loading stats
try {
    $stats['products'] = (int) $pdo
        ->query('SELECT COUNT(*) FROM products')
        ->fetchColumn();
} catch (Exception $e) {
    $stats['products'] = 0;
}


try {
    $stats['customers'] = (int) $pdo
        ->query('SELECT COUNT(*) FROM customers')
        ->fetchColumn();
} catch (Exception $e) {
    $stats['customers'] = 0;
}


try {
    $stats['orders'] = (int) $pdo
        ->query('SELECT COUNT(*) FROM mewmii_orders')
        ->fetchColumn();
} catch (Exception $e) {
    $stats['orders'] = 0;
}


try {
    $stats['users'] = (int) $pdo
        ->query('SELECT COUNT(*) FROM users')
        ->fetchColumn();
} catch (Exception $e) {
    $stats['users'] = 0;
}

?>

<div class="row g-4">

    <div class="col-md-3">
        <div class="card p-4">
            <h6 class="text-muted">Products</h6>
            <h2 class="fw-bold">
                <?php echo app_escape((string)$stats['products']); ?>
            </h2>
        </div>
    </div>


    <div class="col-md-3">
        <div class="card p-4">
            <h6 class="text-muted">Customers</h6>
            <h2 class="fw-bold">
                <?php echo app_escape((string)$stats['customers']); ?>
            </h2>
        </div>
    </div>


    <div class="col-md-3">
        <div class="card p-4">
            <h6 class="text-muted">Orders</h6>
            <h2 class="fw-bold">
                <?php echo app_escape((string)$stats['orders']); ?>
            </h2>
        </div>
    </div>


    <div class="col-md-3">
        <div class="card p-4">
            <h6 class="text-muted">Users</h6>
            <h2 class="fw-bold">
                <?php echo app_escape((string)$stats['users']); ?>
            </h2>
        </div>
    </div>

</div>


<div class="row g-4 mt-1">

    <div class="col-lg-8">

        <div class="card p-4">

            <h4 class="mb-3">
                Welcome to Mewmii OS
            </h4>

            <ul class="mb-0">

                <li>
                    Secure admin login
                </li>

                <li>
                    Product management ready
                </li>

                <li>
                    Inventory system ready for development
                </li>

                <li>
                    Customer order management ready for development
                </li>

            </ul>

        </div>

    </div>


    <div class="col-lg-4">

        <div class="card p-4">

            <h4 class="mb-3">
                Next Upgrades
            </h4>

            <p class="text-muted mb-0">
                Products, inventory, supplier orders, customer orders and WooCommerce sync will be added here.
            </p>

        </div>

    </div>

</div>


<?php require_once __DIR__ . '/includes/footer.php'; ?>