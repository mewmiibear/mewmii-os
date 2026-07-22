<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pdo = app_db();
$pdo->exec(file_get_contents(__DIR__ . '/database/schema.sql'));

// Canonical permission list. Kept here (not just inside the fresh-install branch)
// so re-running this file also syncs any permissions added since the last run.
$permissionNames = [
    'dashboard.view',
    'products.view',
    'products.manage',
    'orders.view',
    'orders.manage',
    'suppliers.view',
    'suppliers.manage',
    'supplier-orders.view',
    'supplier-orders.manage',
    'inventory.view',
    'inventory.manage',
    'customers.view',
    'customer-storage.view',
    'customer-storage.manage',
    'ship-my-box.view',
    'ship-my-box.manage',
    'settings.manage'
];

// Ensure the Owner role exists. Idempotent: looked up by name first.
$roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
$roleStmt->execute(['Owner']);
$roleId = (int) $roleStmt->fetchColumn();

if ($roleId === 0) {
    $pdo->prepare('INSERT INTO roles (name, description) VALUES (?, ?)')->execute(['Owner', 'Full access']);
    $roleId = (int) $pdo->lastInsertId();
}

// Sync permissions and Owner's role_permissions links. Only ever adds missing rows,
// never touches users, and is safe to run on every deploy.
$permissionsAdded = 0;
$linksAdded = 0;

foreach ($permissionNames as $permissionName) {
    $module = explode('.', $permissionName)[0];

    $permStmt = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
    $permStmt->execute([$permissionName]);
    $permissionId = (int) $permStmt->fetchColumn();

    if ($permissionId === 0) {
        $pdo->prepare('INSERT INTO permissions (name, module) VALUES (?, ?)')->execute([$permissionName, $module]);
        $permissionId = (int) $pdo->lastInsertId();
        $permissionsAdded++;
    }

    $linkStmt = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?');
    $linkStmt->execute([$roleId, $permissionId]);

    if ((int) $linkStmt->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$roleId, $permissionId]);
        $linksAdded++;
    }
}

echo "Permission sync: {$permissionsAdded} permission(s) added, {$linksAdded} Owner role link(s) added." . PHP_EOL;

$existing = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($existing === 0) {
    $email = getenv('APP_ADMIN_EMAIL') ?: 'mewmiibear@gmail.com';
    $password = getenv('APP_ADMIN_PASSWORD');
    if (empty($password)) {
        $password = '270701';
    }

    $defaultPassword = password_hash($password, PASSWORD_DEFAULT);

    $pdo->prepare('INSERT INTO membership_tiers (name, upgrade_points, duration_months, monthly_voucher_amount, birthday_voucher_amount, free_shipping_threshold, early_bird_access, early_bird_discount, birthday_gift_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['Baby Bear', 0, 1, 0.00, 0.00, 0.00, 0, 0.00, 0]);
    $pdo->prepare('INSERT INTO membership_tiers (name, upgrade_points, duration_months, monthly_voucher_amount, birthday_voucher_amount, free_shipping_threshold, early_bird_access, early_bird_discount, birthday_gift_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['Silver Bear', 200, 1, 10.00, 5.00, 0.00, 1, 5.00, 0]);
    $pdo->prepare('INSERT INTO membership_tiers (name, upgrade_points, duration_months, monthly_voucher_amount, birthday_voucher_amount, free_shipping_threshold, early_bird_access, early_bird_discount, birthday_gift_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['Gold Bear', 400, 1, 20.00, 10.00, 0.00, 1, 10.00, 1]);
    $pdo->prepare('INSERT INTO membership_tiers (name, upgrade_points, duration_months, monthly_voucher_amount, birthday_voucher_amount, free_shipping_threshold, early_bird_access, early_bird_discount, birthday_gift_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['VIP Bear', 1000, 1, 50.00, 20.00, 0.00, 1, 15.00, 1]);

    $pdo->prepare('INSERT INTO users (name, email, password_hash, role_id, status) VALUES (?, ?, ?, ?, ?)')->execute(['Owner', $email, $defaultPassword, $roleId, 'active']);

    echo 'Admin account created. Email: ' . $email . ' Password: ' . $password . PHP_EOL;
} else {
    echo 'Installation complete. Existing users detected.' . PHP_EOL;
}
