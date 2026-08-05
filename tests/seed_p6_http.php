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
foreach (['customer-storage.view','customer-storage.manage','shipments.view','shipments.manage',
          'ship-my-box.view','ship-my-box.manage','inventory.view','inventory.manage'] as $i => $p) {
    $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'m')")->execute([$i+1,$p]);
    $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
}
$pdo->exec("INSERT INTO customers (id,name,email,instagram_username) VALUES
    (1,'AAA Full','full@x.com','fullig'),(2,'AAB NoEmail',NULL,'noemailig'),(3,'',NULL,'onlyig')");
$vals=[]; for($i=100;$i<305;$i++){$vals[]="({$i},'ZZ Filler ".str_pad((string)($i-99),3,'0',STR_PAD_LEFT)."','f{$i}@x.com')";}
$pdo->exec("INSERT INTO customers (id,name,email) VALUES ".implode(',',$vals));
$pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price)
            VALUES (1,'PO-1','Item','preorder','simple',5.00,20.00)");
echo "seeded\n";
