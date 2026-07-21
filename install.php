<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pdo = app_db();
$pdo->exec(file_get_contents(__DIR__ . '/database/schema.sql'));

$existing = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($existing === 0) {
    $email = getenv('APP_ADMIN_EMAIL') ?: 'owner@mewmii.com';
    $password = getenv('APP_ADMIN_PASSWORD');
    if (empty($password)) {
        $password = bin2hex(random_bytes(8));
    }

    $defaultPassword = password_hash($password, PASSWORD_DEFAULT);

    $roleStmt = $pdo->prepare('INSERT INTO roles (name, description) VALUES (?, ?)');
    $roleStmt->execute(['Owner', 'Full access']);
    $roleId = (int) $pdo->lastInsertId();

    $permissionNames = [
        'dashboard.view',
        'products.view',
        'products.manage',
        'orders.view',
        'orders.manage',
        'suppliers.view',
        'inventory.view',
        'customers.view',
        'settings.manage'
    ];

    foreach ($permissionNames as $permissionName) {
        $module = explode('.', $permissionName)[0];
        $pdo->prepare('INSERT INTO permissions (name, module) VALUES (?, ?)')->execute([$permissionName, $module]);
    }

    $permissionRows = $pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($permissionRows as $permissionId) {
        $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$roleId, (int) $permissionId]);
    }

    $pdo->prepare('INSERT INTO membership_tiers (name, upgrade_points, duration_months, monthly_voucher_amount, birthday_voucher_amount, free_shipping_threshold, early_bird_access, early_bird_discount, birthday_gift_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['Baby Bear', 0, 1, 0.00, 0.00, 0.00, 0, 0.00, 0]);
    $pdo->prepare('INSERT INTO membership_tiers (name, upgrade_points, duration_months, monthly_voucher_amount, birthday_voucher_amount, free_shipping_threshold, early_bird_access, early_bird_discount, birthday_gift_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['Silver Bear', 200, 1, 10.00, 5.00, 0.00, 1, 5.00, 0]);
    $pdo->prepare('INSERT INTO membership_tiers (name, upgrade_points, duration_months, monthly_voucher_amount, birthday_voucher_amount, free_shipping_threshold, early_bird_access, early_bird_discount, birthday_gift_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['Gold Bear', 400, 1, 20.00, 10.00, 0.00, 1, 10.00, 1]);
    $pdo->prepare('INSERT INTO membership_tiers (name, upgrade_points, duration_months, monthly_voucher_amount, birthday_voucher_amount, free_shipping_threshold, early_bird_access, early_bird_discount, birthday_gift_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['VIP Bear', 1000, 1, 50.00, 20.00, 0.00, 1, 15.00, 1]);

    $pdo->prepare('INSERT INTO users (name, email, password_hash, role_id, status) VALUES (?, ?, ?, ?, ?)')->execute(['Owner', $email, $defaultPassword, $roleId, 'active']);

    echo 'Admin account created. Email: ' . $email . ' Password: ' . $password . PHP_EOL;
} else {
    echo 'Installation complete. Existing users detected.' . PHP_EOL;
}
