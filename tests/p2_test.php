<?php
/**
 * P2 shared last-used carrier - runtime tests against the throwaway mewmii_rrtest DB.
 * Renders the REAL pages and asserts on the emitted carrier inputs.
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
/** Value of the Nth name="carrier" input in the rendered HTML (null when it has no value attr). */
function carrierInputs(string $html): array {
    preg_match_all('/<input[^>]*name="carrier"[^>]*>/', $html, $m);
    return array_map(static function (string $tag): ?string {
        return preg_match('/value="([^"]*)"/', $tag, $v) ? $v[1] : null;
    }, $m[0]);
}

require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/shipments.php';
$pdo = app_db();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

function seed(PDO $pdo): void {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach (['inventory_transactions','mewmii_inventory','shipment_items','shipment_events','shipments',
              'ship_request_items','ship_requests','customer_storage','mewmii_order_items','mewmii_orders',
              'product_variations','products','customers','users','roles','role_permissions','permissions'] as $t) {
        try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (Throwable $e) { }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    $pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
    $pdo->exec("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t','x',1)");
    $perms = ['shipments.view','shipments.manage','ship-my-box.view','ship-my-box.manage','orders.view','customers.view'];
    foreach ($perms as $i => $perm) {
        $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'shipments')")->execute([$i+1,$perm]);
        $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
    }
    $pdo->exec("INSERT INTO customers (id,name,email) VALUES (1,'Cust A','a@a.a')");
    $pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price)
                VALUES (1,'RS-1','Ready Simple','ready_stock','simple',5.00,20.00)");
    // A pending shipment (the one whose Confirm Shipped form we inspect) and a processing ship request.
    $pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,shipping_status)
                VALUES (1,'SHP-PENDING',1,'manual','pending')");
    $pdo->exec("INSERT INTO ship_requests (id,request_number,customer_id,status) VALUES (1,'SB-1',1,'processing')");
}

function render(string $file, array $get): string {
    global $root;
    $GLOBALS['_saveGet'] = $_GET;
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start(); require $root . $file; $html = ob_get_clean();
    $_GET = $GLOBALS['_saveGet'];
    return $html;
}

// ------------------------------------------------------------------ 1. no history yet
echo "\n=== 1. nothing shipped yet ===\n";
seed($pdo);
chk('1a helper returns empty string', shipment_last_used_carrier($pdo), '');
$html = render('/modules/shipments/view.php', ['id' => '1']);
chk('1b Confirm Shipped carrier renders empty', carrierInputs($html), ['']);
chk('1c field still required', (bool) preg_match('/name="carrier"[^>]*required/', $html), true);
$html = render('/modules/ship-my-box/view.php', ['id' => '1']);
chk('1d Ship My Box carrier renders empty', carrierInputs($html), ['']);
chk('1e field still required', (bool) preg_match('/name="carrier"[^>]*required/', $html), true);

// ------------------------------------------------------------------ 2. after a dispatch
echo "\n=== 2. after a real dispatch ===\n";
$pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,carrier,tracking_number,shipping_status,shipped_at)
            VALUES (2,'SHP-A',1,'manual','Pos Laju','TRK-A','shipped','2026-03-01 09:00:00')");
chk('2a helper returns that carrier', shipment_last_used_carrier($pdo), 'Pos Laju');
chk('2b shipments/index.php prefilled', in_array('Pos Laju', carrierInputs(render('/modules/shipments/index.php', [])), true), true);
chk('2c shipments/view.php prefilled', carrierInputs(render('/modules/shipments/view.php', ['id' => '1'])), ['Pos Laju']);
chk('2d ship-my-box/view.php prefilled', carrierInputs(render('/modules/ship-my-box/view.php', ['id' => '1'])), ['Pos Laju']);

// ------------------------------------------------------------------ 3. most recent wins
echo "\n=== 3. most recent dispatch wins ===\n";
$pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,carrier,tracking_number,shipping_status,shipped_at)
            VALUES (3,'SHP-B',1,'manual','Ninja Van','TRK-B','shipped','2026-04-01 09:00:00')");
chk('3a newest shipped_at wins', shipment_last_used_carrier($pdo), 'Ninja Van');
$pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,carrier,tracking_number,shipping_status)
            VALUES (4,'SHP-PEND2',1,'manual','SHOULD NOT WIN','TRK-C','pending')");
chk('3b a pending shipment is ignored', shipment_last_used_carrier($pdo), 'Ninja Van');
$pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,carrier,tracking_number,shipping_status,shipped_at)
            VALUES (5,'SHP-CANC',1,'manual','ALSO NOT','TRK-D','cancelled','2026-05-01 09:00:00')");
chk('3c a cancelled shipment is ignored', shipment_last_used_carrier($pdo), 'Ninja Van');
$pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,carrier,shipping_status,shipped_at)
            VALUES (6,'SHP-EMPTY',1,'manual','','delivered','2026-06-01 09:00:00')");
chk('3d an empty carrier is ignored', shipment_last_used_carrier($pdo), 'Ninja Van');

// ------------------------------------------------------------------ 4. edit-tracking regression
echo "\n=== 4. edit tracking keeps the shipment's OWN carrier ===\n";
$html = render('/modules/shipments/view.php', ['id' => '2']);   // SHP-A, shipped with Pos Laju
$inputs = carrierInputs($html);
chk('4a only the edit-tracking field is shown once shipped', count($inputs), 1);
chk('4b shows its own carrier, NOT the last-used one', $inputs[0], 'Pos Laju');
chk('4c last-used really is different right now', shipment_last_used_carrier($pdo), 'Ninja Van');

// ------------------------------------------------------------------ 5. escaping
echo "\n=== 5. escaping ===\n";
$pdo->exec("INSERT INTO shipments (id,shipment_number,customer_id,source_type,carrier,tracking_number,shipping_status,shipped_at)
            VALUES (7,'SHP-Q',1,'manual','J&T \"Express\"','TRK-E','shipped','2026-07-01 09:00:00')");
chk('5a helper returns raw value', shipment_last_used_carrier($pdo), 'J&T "Express"');
$html = render('/modules/shipments/view.php', ['id' => '1']);
chk('5b quote/ampersand escaped in the attribute', str_contains($html, 'value="J&amp;T &quot;Express&quot;"'), true);
chk('5c attribute not broken open', carrierInputs($html), ['J&amp;T &quot;Express&quot;']);

printf("\n============ P2 RESULT: %d passed, %d failed ============\n", $pass, $fail);
