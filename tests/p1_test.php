<?php
/**
 * P1 Reservation Center Auto Reserve - runtime tests against the throwaway mewmii_rrtest DB.
 * Drives the REAL pages (reserve.php + reservation-center.php), not just the helper.
 */
putenv('DB_HOST=127.0.0.1');
putenv('DB_DATABASE=mewmii_rrtest');
putenv('DB_USERNAME=root');
putenv('DB_PASSWORD=');

require_once __DIR__ . '/_guard.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function chk(string $label, $got, $want): void {
    global $pass, $fail;
    $ok = ($got === $want);
    printf("  [%s] %-58s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, json_encode($got));
    $ok ? $pass++ : $fail++;
}

require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/inventory.php';
require_once $root . '/includes/order_fulfillment.php';
$pdo = app_db();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

function seed(PDO $pdo): void {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach (['inventory_transactions','mewmii_inventory','mewmii_order_items','mewmii_orders',
              'product_variations','products','customers','users','roles','role_permissions','permissions'] as $t) {
        $pdo->exec("TRUNCATE TABLE {$t}");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    $pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
    $pdo->exec("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t','x',1)");
    $perms = ['inventory.view','inventory.manage','orders.view','supplier-orders.manage'];
    foreach ($perms as $i => $perm) {
        $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'inventory')")->execute([$i+1,$perm]);
        $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
    }
    $pdo->exec("INSERT INTO customers (id,name,email) VALUES (1,'Cust A','a@a.a')");
    // Simple ready-stock product with 10 available.
    $pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price)
                VALUES (1,'RS-1','Ready Simple','ready_stock','simple',5.00,20.00)");
    $pdo->exec("INSERT INTO mewmii_inventory (product_id,variation_id,available_quantity) VALUES (1,NULL,10)");
    // Two paid orders: older wants 6, newer wants 8 -> FIFO gives 6 then 4.
    $pdo->exec("INSERT INTO mewmii_orders (id,order_number,customer_id,payment_status,order_status,order_date,is_historical)
                VALUES (1,'ORD-1',1,'paid','processing','2026-01-01',0),
                       (2,'ORD-2',1,'paid','processing','2026-02-01',0)");
    $pdo->exec("INSERT INTO mewmii_order_items (id,order_id,product_id,variation_id,quantity,selling_price,subtotal)
                VALUES (1,1,1,NULL,6,20.00,120.00),(2,2,1,NULL,8,20.00,160.00)");
}

// ---------------------------------------------------------------- 1. helper behaviour
echo "\n=== 1. inventory_reserve_fifo_apply() ===\n";
seed($pdo);
$before = (int) $pdo->query("SELECT available_quantity FROM mewmii_inventory WHERE product_id=1")->fetchColumn();
$result = inventory_reserve_fifo_apply($pdo, 1, null);
chk('1a available 10 before', $before, 10);
chk('1b returns reservations + order_ids keys', array_keys($result), ['reservations','order_ids']);
chk('1c FIFO: oldest order first, 6 then 4', array_map(static fn($r) => [$r['order_id'],$r['quantity']], $result['reservations']), [[1,6],[2,4]]);
chk('1d order_ids distinct', $result['order_ids'], [1,2]);
$inv = $pdo->query("SELECT available_quantity, reserved_quantity FROM mewmii_inventory WHERE product_id=1")->fetch(PDO::FETCH_ASSOC);
chk('1e available 0 / reserved 10', [(int)$inv['available_quantity'], (int)$inv['reserved_quantity']], [0,10]);
chk('1f never over-reserves past available', (int) $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory_transactions WHERE transaction_type='order_reserve'")->fetchColumn(), 10);
chk('1g no transaction left open', $pdo->inTransaction(), false);

echo "\n=== 2. empty case rolls back cleanly ===\n";
$err = null;
try { inventory_reserve_fifo_apply($pdo, 1, null); } catch (RuntimeException $e) { $err = $e->getMessage(); }
chk('2a throws RuntimeException', str_contains((string) $err, 'Nothing to reserve'), true);
chk('2b transaction rolled back, none open', $pdo->inTransaction(), false);
chk('2c inventory unchanged after failed attempt', (int) $pdo->query("SELECT reserved_quantity FROM mewmii_inventory WHERE product_id=1")->fetchColumn(), 10);

// ---------------------------------------------------------------- 3. reserve.php unchanged
echo "\n=== 3. reserve.php reserve_fifo (regression) ===\n";
seed($pdo);
$_GET = ['product_id' => '1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); require $root . '/modules/inventory/reserve.php'; $html = ob_get_clean();
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$token = $m[1] ?? '';
chk('3a page renders Option A FIFO button', str_contains($html, 'Reserve Automatically (FIFO)'), true);

chk('3b page still calls the FIFO action', str_contains($html, 'name="action" value="reserve_fifo"'), true);
// reserve.php's POST ends in app_redirect(), which exits the process - so its POST path and
// redirect are asserted over real HTTP (php -S) instead of here. See the report.

// ---------------------------------------------------------------- 4. reservation-center.php
echo "\n=== 4. reservation-center.php in-place Auto Reserve ===\n";
seed($pdo);
$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); require $root . '/modules/inventory/reservation-center.php'; $html = ob_get_clean();
chk('4a queue row rendered', str_contains($html, 'Ready Simple'), true);
chk('4b Auto Reserve button present', str_contains($html, 'Auto Reserve'), true);
chk('4c Manual link still present', str_contains($html, '/modules/inventory/reserve.php?product_id=1'), true);
chk('4d posts action=reserve_fifo', str_contains($html, 'name="action" value="reserve_fifo"'), true);
chk('4e need_reserve shown as 14', (bool) preg_match('/badge bg-warning text-dark">14</', $html), true);
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$token = $m[1] ?? '';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => $token, 'action' => 'reserve_fifo', 'product_id' => '1'];
ob_start(); require $root . '/modules/inventory/reservation-center.php'; $html = ob_get_clean();
chk('4f success message rendered in place', str_contains($html, 'Reserved for 2 orders.'), true);
$inv = $pdo->query("SELECT available_quantity, reserved_quantity FROM mewmii_inventory WHERE product_id=1")->fetch(PDO::FETCH_ASSOC);
chk('4g ledger updated without leaving the page', [(int)$inv['available_quantity'], (int)$inv['reserved_quantity']], [0,10]);
chk('4h queue recomputed after handler - row gone', str_contains($html, 'Nothing needs reserving right now'), true);
chk('4i order status recomputed (not left processing)',
    $pdo->query("SELECT COUNT(*) FROM mewmii_orders WHERE order_status='processing'")->fetchColumn() !== '2', true);

echo "\n=== 5. guards ===\n";
seed($pdo);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => 'bogus', 'action' => 'reserve_fifo', 'product_id' => '1'];
ob_start(); require $root . '/modules/inventory/reservation-center.php'; $html = ob_get_clean();
chk('5a bad CSRF rejected, nothing reserved', (int) $pdo->query("SELECT reserved_quantity FROM mewmii_inventory WHERE product_id=1")->fetchColumn(), 0);
chk('5b CSRF error surfaced', str_contains($html, 'alert-warning'), true);

$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
ob_start(); require $root . '/modules/inventory/reservation-center.php'; $html = ob_get_clean();
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => $m[1] ?? '', 'action' => 'reserve_fifo', 'product_id' => '0'];
ob_start(); require $root . '/modules/inventory/reservation-center.php'; $html = ob_get_clean();
chk('5c invalid product id rejected', str_contains($html, 'Invalid product.'), true);
chk('5d nothing reserved by invalid id', (int) $pdo->query("SELECT reserved_quantity FROM mewmii_inventory WHERE product_id=1")->fetchColumn(), 0);

// unlink disabled for debug
printf("\n============ P1 RESULT: %d passed, %d failed ============\n", $pass, $fail);
