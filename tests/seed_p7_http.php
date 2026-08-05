<?php
putenv('DB_HOST=127.0.0.1'); putenv('DB_DATABASE=mewmii_rrtest');
putenv('DB_USERNAME=root'); putenv('DB_PASSWORD=');

require_once __DIR__ . '/_guard.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/supplier_orders.php';
$pdo = app_db();
$withManage = ($argv[1] ?? 'yes') === 'yes';
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['inventory_transactions','mewmii_inventory','product_cost_history','supplier_order_items',
          'supplier_orders','suppliers','product_variations','products','customers',
          'users','roles','role_permissions','permissions'] as $t) { try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (Throwable $e) {} }
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
$pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
$pdo->prepare("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t',?,1)")
    ->execute([password_hash('Test1234!', PASSWORD_DEFAULT)]);
$perms = ['supplier-orders.view','inventory.view'];
if ($withManage) { $perms[] = 'supplier-orders.manage'; $perms[] = 'inventory.manage'; }
foreach ($perms as $i => $p) {
    $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'supplier-orders')")->execute([$i+1,$p]);
    $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
}
$pdo->exec("INSERT INTO suppliers (id,name) VALUES (1,'Supplier One')");
$pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price,supplier_id)
            VALUES (1,'RS-1','Ready Item','ready_stock','simple',10.00,30.00,1)");
foreach ([[1,'PO-PARTIAL','ordered',0],[2,'PO-FULL','ordered',0],[3,'PO-HIST','ordered',1]] as [$id,$n,$s,$h]) {
    $pdo->prepare("INSERT INTO supplier_orders (id,supplier_id,purchase_number,status,order_date,is_historical) VALUES (?,1,?,?,CURDATE(),?)")->execute([$id,$n,$s,$h]);
    $pdo->prepare("INSERT INTO supplier_order_items (id,supplier_order_id,product_id,total_quantity,supplier_price,unit_cost_myr,subtotal) VALUES (?,?,1,10,10.00,10.00,100.00)")->execute([$id,$id]);
    supplier_order_mark_incoming($pdo, 1, $id, 10, null);
}
supplier_order_receive_item($pdo, 1, 4);
supplier_order_receive_item($pdo, 2, 10);
echo "seeded (manage=" . ($withManage ? "yes" : "no") . ")\n";
