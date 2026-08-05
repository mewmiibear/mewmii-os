<?php
putenv('DB_HOST=127.0.0.1'); putenv('DB_DATABASE=mewmii_rrtest');
putenv('DB_USERNAME=root'); putenv('DB_PASSWORD=');

require_once __DIR__ . '/_guard.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/supplier_orders.php';
$pdo = app_db();
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['inventory_transactions','mewmii_inventory','product_cost_history','supplier_order_items','supplier_orders',
          'suppliers','shipment_items','shipment_events','shipments','ship_request_items','ship_requests',
          'customer_storage','mewmii_order_items','mewmii_orders','product_variations','products','customers',
          'users','roles','role_permissions','permissions'] as $t) { try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (Throwable $e) {} }
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
$pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
$pdo->prepare("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t',?,1)")
    ->execute([password_hash('Test1234!', PASSWORD_DEFAULT)]);
foreach (['inventory.view','inventory.manage','supplier-orders.view','supplier-orders.manage','orders.view',
          'shipments.view','shipments.manage','ship-my-box.view','ship-my-box.manage',
          'customer-storage.view','customer-storage.manage','products.view'] as $i => $p) {
    $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'m')")->execute([$i+1,$p]);
    $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
}
// four customer identity shapes for the label chain
$pdo->exec("INSERT INTO customers (id,name,email,instagram_username) VALUES
    (1,'AAA Named','named@x.com','namedig'),(2,'',NULL,'onlyig'),(3,'',NULL,NULL),(4,'',NULL,NULL)");
$pdo->exec("UPDATE customers SET email='onlyemail@x.com' WHERE id=3");
$pdo->exec("INSERT INTO suppliers (id,name) VALUES (1,'Supplier One')");
$pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price,supplier_id) VALUES
    (1,'RS-1','Ready Item','ready_stock','simple',10.00,30.00,1),
    (2,'PO-1','Preorder Item','preorder','simple',10.00,30.00,1)");
// supplier order: partially received ready stock (drives Receive shortcut + Reverse Receipt)
$pdo->exec("INSERT INTO supplier_orders (id,supplier_id,purchase_number,status,order_date,is_historical) VALUES (1,1,'PO-A','ordered',CURDATE(),0)");
$pdo->exec("INSERT INTO supplier_order_items (id,supplier_order_id,product_id,total_quantity,supplier_price,unit_cost_myr,subtotal) VALUES (1,1,1,20,10.00,10.00,200.00)");
supplier_order_mark_incoming($pdo, 1, 1, 20, null);
supplier_order_receive_item($pdo, 1, 8);
// paid order creating unreserved demand -> Reservation Center queue row
$pdo->exec("INSERT INTO mewmii_orders (id,order_number,customer_id,payment_status,order_status,order_date,is_historical) VALUES (1,'ORD-1',1,'paid','processing','2026-01-01',0)");
$pdo->exec("INSERT INTO mewmii_order_items (id,order_id,product_id,variation_id,quantity,selling_price,subtotal) VALUES (1,1,1,NULL,5,30.00,150.00)");
// customer storage lot + open ship request -> storage remove defaults
$pdo->exec("INSERT INTO customer_storage (id,customer_id,product_id,variation_id,quantity,status,arrival_date) VALUES (1,1,2,NULL,10,'stored','2026-01-01')");
$pdo->exec("INSERT INTO ship_requests (id,request_number,customer_id,status) VALUES (1,'SB-1',1,'pending')");
$pdo->exec("INSERT INTO ship_request_items (ship_request_id,customer_storage_id,quantity) VALUES (1,1,4)");
$pdo->exec("UPDATE mewmii_inventory SET customer_storage_quantity=10 WHERE product_id=2");
// a dispatched shipment -> last-used carrier
$pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,carrier,tracking_number,shipping_status,shipped_at) VALUES (1,'SHP-1',1,'manual','Ninja Van','NV-1','shipped','2026-08-01 09:00:00')");
$pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,shipping_status) VALUES (2,'SHP-2',1,'manual','pending')");
echo "seeded\n";
