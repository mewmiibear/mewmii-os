<?php
/**
 * P3 Ship My Box quantity defaults - runtime tests against the throwaway mewmii_rrtest DB.
 * Renders the REAL create.php and asserts on the emitted quantity inputs.
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
    printf("  [%s] %-56s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, json_encode($got));
    $ok ? $pass++ : $fail++;
}
/** [storage_id => ['value'=>..,'max'=>..,'available'=>..,'disabled'=>bool]] from rendered HTML. */
function qtyInputs(string $html): array {
    preg_match_all('/<input[^>]*class="[^"]*ship-request-qty[^"]*"[^>]*>/', $html, $m);
    $out = [];
    foreach ($m[0] as $tag) {
        preg_match('/name="quantity\[(\d+)\]"/', $tag, $id);
        preg_match('/value="(\d+)"/', $tag, $v);
        preg_match('/max="(\d+)"/', $tag, $mx);
        preg_match('/data-available="(\d+)"/', $tag, $av);
        $out[(int) $id[1]] = [
            'value' => (int) ($v[1] ?? -1),
            'max' => (int) ($mx[1] ?? -1),
            'available' => (int) ($av[1] ?? -1),
            'disabled' => str_contains($tag, 'disabled'),
        ];
    }
    return $out;
}

require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/ship_my_box.php';
$pdo = app_db();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

function seed(PDO $pdo): void {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach (['inventory_transactions','mewmii_inventory','shipment_items','shipment_events','shipments',
              'ship_request_items','ship_requests','customer_storage','mewmii_order_items','mewmii_orders',
              'product_variations','products','customers','users','roles','role_permissions','permissions'] as $t) {
        try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (Throwable $e) {}
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    $pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
    $pdo->exec("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t','x',1)");
    foreach (['ship-my-box.view','ship-my-box.manage','customer-storage.view'] as $i => $p) {
        $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'ship-my-box')")->execute([$i+1,$p]);
        $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
    }
    $pdo->exec("INSERT INTO customers (id,name,email) VALUES (1,'Cust A','a@a.a')");
    $pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price)
                VALUES (1,'PO-1','Preorder Item','preorder','simple',5.00,20.00)");
    // Three stored lots for the same customer.
    $pdo->exec("INSERT INTO customer_storage (id,customer_id,product_id,variation_id,quantity,status,arrival_date)
                VALUES (1,1,1,NULL,10,'stored','2026-01-01'),
                       (2,1,1,NULL,6,'stored','2026-01-02'),
                       (3,1,1,NULL,4,'stored','2026-01-03')");
}

function render(string $file, array $get): string {
    global $root;
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start(); require $root . $file; return ob_get_clean();
}

// ------------------------------------------------------------- 1. clean lots
echo "\n=== 1. no competing ship request ===\n";
seed($pdo);
$html = render('/modules/ship-my-box/create.php', ['customer_id' => '1']);
$inputs = qtyInputs($html);
chk('1a three rows rendered', count($inputs), 3);
chk('1b lot 1 value = available = 10', [$inputs[1]['value'], $inputs[1]['max'], $inputs[1]['available']], [10,10,10]);
chk('1c lot 2 value = available = 6', [$inputs[2]['value'], $inputs[2]['max'], $inputs[2]['available']], [6,6,6]);
chk('1d lot 3 value = available = 4', [$inputs[3]['value'], $inputs[3]['max'], $inputs[3]['available']], [4,4,4]);
chk('1e none disabled', array_column($inputs, 'disabled'), [false,false,false]);
chk('1f toolbar total = 20', (bool) preg_match('/ship-request-selected-total">20</', $html), true);
chk('1g Ship All button present', str_contains($html, 'id="ship-request-select-all-qty"'), true);
chk('1h Clear button present', str_contains($html, 'id="ship-request-clear-qty"'), true);
chk('1i Available To Ship column added', str_contains($html, '<th>Available To Ship</th>'), true);

// ------------------------------------------------------------- 2. partly committed
echo "\n=== 2. lot partly committed to an OPEN ship request ===\n";
$pdo->exec("INSERT INTO ship_requests (id,request_number,customer_id,status) VALUES (10,'SB-OPEN',1,'pending')");
$pdo->exec("INSERT INTO ship_request_items (ship_request_id,customer_storage_id,quantity) VALUES (10,1,4)");
$html = render('/modules/ship-my-box/create.php', ['customer_id' => '1']);
$inputs = qtyInputs($html);
chk('2a helper agrees', ship_request_storage_lot_available($pdo, 1), 6);
chk('2b lot 1 now defaults to 6, max 6', [$inputs[1]['value'], $inputs[1]['max']], [6,6]);
chk('2c other lots unaffected', [$inputs[2]['value'], $inputs[3]['value']], [6,4]);
chk('2d committed note shown', str_contains($html, '4 committed to a pending ship request'), true);
chk('2e toolbar total drops to 16', (bool) preg_match('/ship-request-selected-total">16</', $html), true);

// ------------------------------------------------------------- 3. fully committed
echo "\n=== 3. lot fully committed ===\n";
$pdo->exec("INSERT INTO ship_request_items (ship_request_id,customer_storage_id,quantity) VALUES (10,3,4)");
$html = render('/modules/ship-my-box/create.php', ['customer_id' => '1']);
$inputs = qtyInputs($html);
chk('3a lot 3 available 0', $inputs[3]['available'], 0);
chk('3b lot 3 input disabled', $inputs[3]['disabled'], true);
chk('3c lot 3 max 0 / value 0', [$inputs[3]['value'], $inputs[3]['max']], [0,0]);
chk('3d still listed (lot is real stock)', count($inputs), 3);

// ------------------------------------------------------------- 4. shipped request does not hold stock
echo "\n=== 4. a SHIPPED ship request releases its claim ===\n";
$pdo->exec("UPDATE ship_requests SET status='shipped' WHERE id=10");
$html = render('/modules/ship-my-box/create.php', ['customer_id' => '1']);
$inputs = qtyInputs($html);
chk('4a lot 1 back to full 10', [$inputs[1]['value'], $inputs[1]['max']], [10,10]);
chk('4b lot 3 re-enabled', $inputs[3]['disabled'], false);
chk('4c toolbar total back to 20', (bool) preg_match('/ship-request-selected-total">20</', $html), true);

// ------------------------------------------------------------- 5. POST still validated
echo "\n=== 5. validation unchanged ===\n";
seed($pdo);
$html = render('/modules/ship-my-box/create.php', ['customer_id' => '1']);
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$token = $m[1];
$pdo->exec("INSERT INTO ship_requests (id,request_number,customer_id,status) VALUES (20,'SB-OPEN2',1,'processing')");
$pdo->exec("INSERT INTO ship_request_items (ship_request_id,customer_storage_id,quantity) VALUES (20,1,8)");
// Ask for 10 on a lot with only 2 free - the pre-existing guard must still refuse.
$_GET = ['customer_id' => '1']; $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => $token, 'customer_id' => '1', 'quantity' => [1 => '10']];
ob_start(); require $root . '/modules/ship-my-box/create.php'; $html = ob_get_clean();
chk('5a over-commit still rejected', str_contains($html, 'exceeds what is still available'), true);
chk('5b no ship request created', (int) $pdo->query("SELECT COUNT(*) FROM ship_requests WHERE id<>20")->fetchColumn(), 0);

echo "\n=== 6. submitting the defaults works ===\n";
seed($pdo);
$html = render('/modules/ship-my-box/create.php', ['customer_id' => '1']);
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$inputs = qtyInputs($html);
$post = [];
foreach ($inputs as $id => $meta) { $post[$id] = (string) $meta['value']; }
// The page lists lots newest-first, so compare by key, not by render order.
$postSorted = $post; ksort($postSorted);
chk('6a defaults are the full available set', $postSorted, [1 => '10', 2 => '6', 3 => '4']);
$_GET = ['customer_id' => '1']; $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => $m[1], 'customer_id' => '1', 'quantity' => $post];
register_shutdown_function(static function () use ($pdo) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    global $pass, $fail;
    $rid = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM ship_requests")->fetchColumn();
    chk('6b ship request created', $rid > 0, true);
    $rows = $pdo->query("SELECT customer_storage_id, quantity FROM ship_request_items ORDER BY customer_storage_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    chk('6c one line per lot at full available', array_map('intval', $rows), [1 => 10, 2 => 6, 3 => 4]);
    printf("\n============ P3 RESULT: %d passed, %d failed ============\n", $pass, $fail);
});
ob_start(); require $root . '/modules/ship-my-box/create.php';
