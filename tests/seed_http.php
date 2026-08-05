<?php
putenv('DB_HOST=127.0.0.1'); putenv('DB_DATABASE=mewmii_rrtest');
putenv('DB_USERNAME=root'); putenv('DB_PASSWORD=');

require_once __DIR__ . '/_guard.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$pdo = app_db();
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['inventory_transactions','mewmii_inventory','mewmii_order_items','mewmii_orders',
          'product_variations','products','customers','users','roles','role_permissions','permissions'] as $t) {
    $pdo->exec("TRUNCATE TABLE {$t}");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
$pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
$hash = password_hash('Test1234!', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t',?,1)")->execute([$hash]);
foreach (['inventory.view','inventory.manage','orders.view'] as $i => $perm) {
    $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'inventory')")->execute([$i+1,$perm]);
    $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
}
$pdo->exec("INSERT INTO customers (id,name,email) VALUES (1,'Cust A','a@a.a')");
$pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price)
            VALUES (1,'RS-1','Ready Simple','ready_stock','simple',5.00,20.00)");
$pdo->exec("INSERT INTO mewmii_inventory (product_id,variation_id,available_quantity) VALUES (1,NULL,10)");
$pdo->exec("INSERT INTO mewmii_orders (id,order_number,customer_id,payment_status,order_status,order_date,is_historical)
            VALUES (1,'ORD-1',1,'paid','processing','2026-01-01',0),(2,'ORD-2',1,'paid','processing','2026-02-01',0)");
$pdo->exec("INSERT INTO mewmii_order_items (id,order_id,product_id,variation_id,quantity,selling_price,subtotal)
            VALUES (1,1,1,NULL,6,20.00,120.00),(2,2,1,NULL,8,20.00,160.00)");
echo "seeded\n";
