<?php
putenv('DB_HOST=127.0.0.1'); putenv('DB_DATABASE=mewmii_rrtest');
putenv('DB_USERNAME=root'); putenv('DB_PASSWORD=');

require_once __DIR__ . '/_guard.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$pdo = app_db();
$withInventory = ($argv[1] ?? 'yes') === 'yes';
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['inventory_transactions','mewmii_inventory','customer_storage','mewmii_order_items','mewmii_orders',
          'product_variations','products','customers','users','roles','role_permissions','permissions'] as $t) {
    try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (Throwable $e) {}
}
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
$pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
$pdo->prepare("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t',?,1)")
    ->execute([password_hash('Test1234!', PASSWORD_DEFAULT)]);
$perms = ['orders.view','products.view'];
if ($withInventory) { $perms[] = 'inventory.view'; $perms[] = 'inventory.manage'; }
foreach ($perms as $i => $p) {
    $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'inventory')")->execute([$i+1,$p]);
    $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
}
echo "seeded (inventory.view=" . ($withInventory ? "yes" : "no") . ")\n";
