<?php
putenv('DB_HOST=127.0.0.1'); putenv('DB_DATABASE=mewmii_rrtest');
putenv('DB_USERNAME=root'); putenv('DB_PASSWORD=');

require_once __DIR__ . '/_guard.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$pdo = app_db();
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['inventory_transactions','mewmii_inventory','shipment_items','shipment_events','shipments',
          'ship_request_items','ship_requests','customer_storage','mewmii_order_items','mewmii_orders',
          'product_variations','products','customers','users','roles','role_permissions','permissions'] as $t) {
    try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (Throwable $e) {}
}
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
$pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
$pdo->prepare("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t',?,1)")
    ->execute([password_hash('Test1234!', PASSWORD_DEFAULT)]);
foreach (['shipments.view','shipments.manage','ship-my-box.view','ship-my-box.manage','orders.view','customers.view'] as $i => $p) {
    $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'shipments')")->execute([$i+1,$p]);
    $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
}
$pdo->exec("INSERT INTO customers (id,name,email) VALUES (1,'Cust A','a@a.a')");
$pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price)
            VALUES (1,'RS-1','Ready Simple','ready_stock','simple',5.00,20.00)");
$pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,shipping_status)
            VALUES (1,'SHP-PENDING',1,'manual','pending')");
$pdo->exec("INSERT INTO ship_requests (id,request_number,customer_id,status) VALUES (1,'SB-1',1,'processing')");
echo "seeded\n";
$pdo->exec("INSERT INTO mewmii_inventory (product_id,variation_id,available_quantity,reserved_quantity) VALUES (1,NULL,0,5)");
$pdo->exec("INSERT INTO shipment_items (shipment_id,product_id,variation_id,quantity) VALUES (1,1,NULL,5)");
echo "seeded with items\n";
