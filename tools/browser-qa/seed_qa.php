<?php
/** Seeds enough rows that pagination renders multiple pages and tables have selectable rows. */
putenv('DB_HOST=127.0.0.1'); putenv('DB_DATABASE=mewmii_rrtest');
putenv('DB_USERNAME=root'); putenv('DB_PASSWORD=');
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$pdo = app_db();

// Products: 60 -> 3 pages at 25/page
$have = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
for ($i = $have; $i < 60; $i++) {
    $pdo->prepare("INSERT INTO products (sku,name,product_type,catalog_type,product_cost,selling_price,status)
                   VALUES (?,?,'ready_stock','simple',10.00,30.00,'active')")
        ->execute(['QA-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'QA Product ' . $i]);
}
// Customers: 60
$have = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
for ($i = $have; $i < 60; $i++) {
    $pdo->prepare("INSERT INTO customers (name,email) VALUES (?,?)")
        ->execute(['QA Customer ' . $i, 'qa' . $i . '@example.com']);
}
// Orders: 60 (bulk-select + pagination)
$have = (int) $pdo->query("SELECT COUNT(*) FROM mewmii_orders")->fetchColumn();
for ($i = $have; $i < 60; $i++) {
    $pdo->prepare("INSERT INTO mewmii_orders (order_number,customer_id,payment_status,order_status,order_date,is_historical)
                   VALUES (?,1,'pending','processing',CURDATE(),0)")
        ->execute(['QA-ORD-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
}
// Suppliers + supplier orders
$have = (int) $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
for ($i = $have; $i < 5; $i++) {
    $pdo->prepare("INSERT INTO suppliers (name) VALUES (?)")->execute(['QA Supplier ' . $i]);
}
$have = (int) $pdo->query("SELECT COUNT(*) FROM supplier_orders")->fetchColumn();
for ($i = $have; $i < 30; $i++) {
    $pdo->prepare("INSERT INTO supplier_orders (supplier_id,purchase_number,status,order_date,is_historical)
                   VALUES (1,?, 'ordered', CURDATE(), 0)")
        ->execute(['QA-PO-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
}
// A brand + tag so the catalog delete dialogs have targets
$pdo->exec("INSERT IGNORE INTO brands (id,name) VALUES (900,'QA Brand')");
$pdo->exec("INSERT IGNORE INTO product_tags (id,name) VALUES (900,'QA Tag')");

foreach (['products','customers','mewmii_orders','supplier_orders'] as $t) {
    printf("  %-18s %s rows\n", $t, $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn());
}
