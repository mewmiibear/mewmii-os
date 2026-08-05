<?php
/**
 * Reverse Receipt UI wiring test - renders the REAL view.php against the throwaway DB and
 * inspects the produced HTML, then drives the real POST handler. Throwaway.
 */
putenv('DB_HOST=127.0.0.1');
putenv('DB_DATABASE=mewmii_rrtest');
putenv('DB_USERNAME=root');
putenv('DB_PASSWORD=');

require_once __DIR__ . '/_guard.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function chk($label, $got, $want) {
    global $pass, $fail;
    $ok = ($got === $want);
    printf("  [%s] %-56s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true));
    $ok ? $pass++ : $fail++;
}

// --- seed a received PO we can render ---
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/supplier_orders.php';
$pdo = app_db();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['inventory_transactions','mewmii_inventory','supplier_order_items','supplier_orders',
          'product_cost_history','products','suppliers','users','roles','role_permissions','permissions'] as $t) {
    $pdo->exec("TRUNCATE TABLE {$t}");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
$pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
$pdo->exec("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t','x',1)");
foreach (['supplier-orders.view','supplier-orders.manage'] as $i => $perm) {
    $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?, 'supplier-orders')")->execute([$i+1,$perm]);
    $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
}
$pdo->exec("INSERT INTO suppliers (id,name) VALUES (1,'Supplier One')");
$pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price,supplier_id)
            VALUES (1,'A-1','Product A','ready_stock','simple',10.00,30.00,1)");
$pdo->prepare("INSERT INTO supplier_orders (id,supplier_id,purchase_number,status,order_date)
               VALUES (1,1,'PO-UI','ordered',CURDATE())")->execute();
$pdo->prepare("INSERT INTO supplier_order_items (id,supplier_order_id,product_id,total_quantity,supplier_price,unit_cost_myr,subtotal)
               VALUES (1,1,1,50,10.00,10.00,500.00)")->execute();
supplier_order_mark_incoming($pdo, 1, 1, 50, null);
supplier_order_receive_item($pdo, 1, 50);

// --- render the real page ---
$_GET['id'] = '1';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require $root . '/modules/supplier-orders/view.php';
$html = ob_get_clean();

echo "\n=== 1. BUTTON RENDERING ===\n";
chk('1a Reverse Receipt button present', str_contains($html, 'js-reverse-receipt'), true);
chk('1b data-item-id present', (bool) preg_match('/data-item-id="1"/', $html), true);
chk('1c data-received=50', (bool) preg_match('/data-received="50"/', $html), true);
chk('1d data-reversible=50', (bool) preg_match('/data-reversible="50"/', $html), true);
chk('1e data-blocked=0', (bool) preg_match('/data-blocked="0"/', $html), true);
chk('1f button is type=button (never submits table form)', (bool) preg_match('/type="button"[^>]*js-reverse-receipt/', $html), true);

echo "\n=== 2. MODAL + SCRIPT ===\n";
chk('2a modal markup rendered', str_contains($html, 'id="reverseReceiptModal"'), true);
chk('2b modal form action=reverse_receipt', str_contains($html, 'name="action" value="reverse_receipt"'), true);
chk('2c csrf token in modal form', str_contains($html, 'name="csrf_token"'), true);
chk('2d reason field required', (bool) preg_match('/name="reverse_reason"[^>]*required/', $html), true);
chk('2e handler script present', str_contains($html, "querySelectorAll('.js-reverse-receipt')"), true);
chk('2f NO parse-time bootstrap early-return', str_contains($html, '!modalEl || !window.bootstrap'), false);
chk('2g lazy bootstrap resolve present', str_contains($html, 'window.bootstrap && window.bootstrap.Modal'), true);

// script must appear AFTER the buttons in source order, else querySelectorAll finds nothing
$btnPos = strpos($html, 'js-reverse-receipt');
$scriptPos = strpos($html, "querySelectorAll('.js-reverse-receipt')");
chk('2h script runs after buttons exist in DOM', $scriptPos > $btnPos, true);

echo "\n=== 3. POST PATH ===\n";
$token = null;
if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) { $token = $m[1]; }
chk('3a csrf token extractable', is_string($token) && $token !== '', true);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'csrf_token' => $token,
    'action' => 'reverse_receipt',
    'reverse_item_id' => '1',
    'reverse_quantity' => '20',
    'reverse_reason' => 'ui wiring test',
];
$before = supplier_order_item_received_quantity($pdo, 1);

// view.php ends the POST with app_redirect(), which calls exit() - assert from a shutdown handler.
register_shutdown_function(static function () use ($pdo, $before) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $after = supplier_order_item_received_quantity($pdo, 1);
    chk('3b POST reduced received 50 -> 30', [$before, $after], [50, 30]);
    $neg = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE quantity=-20 AND transaction_type='supplier_receive'")->fetchColumn();
    chk('3c reversing ledger row written', $neg, 1);
    $st = $pdo->query("SELECT status FROM supplier_orders WHERE id=1")->fetchColumn();
    chk('3d status now partially_received', $st, 'partially_received');
    global $pass, $fail;
    printf("\n============ UI RESULT: %d passed, %d failed ============\n", $pass, $fail);
});

ob_start();
require $root . '/modules/supplier-orders/view.php';
