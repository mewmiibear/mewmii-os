<?php
/**
 * P4 Customer Storage remove-quantity defaults - runtime tests against the real MySQL
 * throwaway DB (mewmii_rrtest). Renders the REAL view.php and drives its POST handler.
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
/** Remove-quantity inputs keyed by the storage_id of their own form. */
function removeInputs(string $html): array {
    preg_match_all('/<form[^>]*method="post"[^>]*>.*?<\/form>/s', $html, $forms);
    $out = [];
    foreach ($forms[0] as $form) {
        if (!str_contains($form, 'value="remove"')) { continue; }
        preg_match('/name="storage_id" value="(\d+)"/', $form, $sid);
        preg_match('/<input[^>]*name="quantity".*?>/s', $form, $inp);
        $tag = preg_replace('/\s+/', ' ', $inp[0] ?? '');
        preg_match('/max="(\d+)"/', $tag, $mx);
        preg_match('/value="(\d*)"/', $tag, $v);
        preg_match('/<button[^>]*>.*?Remove/s', $form, $btn);
        $out[(int) $sid[1]] = [
            'value' => $v[1] ?? null,
            'max' => (int) ($mx[1] ?? -1),
            'input_disabled' => str_contains($tag, 'disabled'),
            'button_disabled' => str_contains(preg_replace('/\s+/', ' ', $btn[0] ?? ''), 'disabled'),
        ];
    }
    return $out;
}
/** True when the update_location form for $storageId is still usable. */
function locationFormEnabled(string $html, int $storageId): bool {
    preg_match_all('/<form[^>]*method="post"[^>]*>.*?<\/form>/s', $html, $forms);
    foreach ($forms[0] as $form) {
        if (!str_contains($form, 'value="update_location"')) { continue; }
        if (!preg_match('/name="storage_id" value="' . $storageId . '"/', $form)) { continue; }
        return !str_contains($form, 'disabled');
    }
    return false;
}

require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/ship_my_box.php';
$pdo = app_db();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

function seed(PDO $pdo, bool $withManage = true): void {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach (['inventory_transactions','mewmii_inventory','shipment_items','shipment_events','shipments',
              'ship_request_items','ship_requests','customer_storage','mewmii_order_items','mewmii_orders',
              'product_variations','products','customers','users','roles','role_permissions','permissions'] as $t) {
        try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (Throwable $e) {}
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    $pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
    $pdo->exec("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t','x',1)");
    $perms = ['customer-storage.view','ship-my-box.manage','orders.view'];
    if ($withManage) { $perms[] = 'customer-storage.manage'; }
    foreach ($perms as $i => $p) {
        $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'customer-storage')")->execute([$i+1,$p]);
        $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
    }
    $pdo->exec("INSERT INTO customers (id,name,email,phone) VALUES (1,'Cust A','a@a.a','012')");
    $pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price)
                VALUES (1,'PO-1','Preorder Item','preorder','simple',5.00,20.00)");
    $pdo->exec("INSERT INTO mewmii_inventory (product_id,variation_id,available_quantity,customer_storage_quantity)
                VALUES (1,NULL,0,20)");
    $pdo->exec("INSERT INTO customer_storage (id,customer_id,product_id,variation_id,quantity,status,arrival_date,storage_location)
                VALUES (1,1,1,NULL,10,'stored','2026-01-01','Shelf A1'),
                       (2,1,1,NULL,6,'stored','2026-01-02',NULL),
                       (3,1,1,NULL,4,'stored','2026-01-03',NULL)");
}

function render(array $get): string {
    global $root;
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start(); require $root . '/modules/customer-storage/view.php'; return ob_get_clean();
}

// -------------------------------------------------------------- 1. clean lots
echo "\n=== 1. no competing ship request ===\n";
seed($pdo);
$html = render(['customer_id' => '1']);
$inputs = removeInputs($html);
chk('1a three remove forms rendered', count($inputs), 3);
chk('1b lot 1 defaults to its full 10', [$inputs[1]['value'], $inputs[1]['max']], ['10',10]);
chk('1c lot 2 defaults to 6', [$inputs[2]['value'], $inputs[2]['max']], ['6',6]);
chk('1d lot 3 defaults to 4', [$inputs[3]['value'], $inputs[3]['max']], ['4',4]);
chk('1e nothing disabled', [$inputs[1]['input_disabled'], $inputs[1]['button_disabled']], [false,false]);
chk('1f no committed note when nothing is held', str_contains($html, 'committed to a pending ship request'), false);

// -------------------------------------------------------------- 2. partly committed
echo "\n=== 2. lot partly committed to an OPEN ship request ===\n";
$pdo->exec("INSERT INTO ship_requests (id,request_number,customer_id,status) VALUES (10,'SB-OPEN',1,'pending')");
$pdo->exec("INSERT INTO ship_request_items (ship_request_id,customer_storage_id,quantity) VALUES (10,1,7)");
$html = render(['customer_id' => '1']);
$inputs = removeInputs($html);
chk('2a helper agrees', ship_request_storage_lot_available($pdo, 1), 3);
chk('2b lot 1 defaults to 3, max 3', [$inputs[1]['value'], $inputs[1]['max']], ['3',3]);
chk('2c still enabled', $inputs[1]['input_disabled'], false);
chk('2d committed note shown', str_contains($html, '7 committed to a pending ship request'), true);
chk('2e other lots unaffected', [$inputs[2]['value'], $inputs[3]['value']], ['6','4']);

// -------------------------------------------------------------- 3. fully committed
echo "\n=== 3. lot fully committed ===\n";
$pdo->exec("INSERT INTO ship_request_items (ship_request_id,customer_storage_id,quantity) VALUES (10,3,4)");
$html = render(['customer_id' => '1']);
$inputs = removeInputs($html);
chk('3a lot 3 value blank, max 0', [$inputs[3]['value'], $inputs[3]['max']], ['',0]);
chk('3b input disabled', $inputs[3]['input_disabled'], true);
chk('3c Remove button disabled', $inputs[3]['button_disabled'], true);
chk('3d lot still listed', count($inputs), 3);
chk('3e Location form for that lot still usable', locationFormEnabled($html, 3), true);

// -------------------------------------------------------------- 4. shipped request releases claim
echo "\n=== 4. a SHIPPED ship request releases its claim ===\n";
$pdo->exec("UPDATE ship_requests SET status='shipped' WHERE id=10");
$html = render(['customer_id' => '1']);
$inputs = removeInputs($html);
chk('4a lot 1 back to full 10', [$inputs[1]['value'], $inputs[1]['max']], ['10',10]);
chk('4b lot 3 re-enabled', $inputs[3]['input_disabled'], false);
chk('4c note gone', str_contains($html, 'committed to a pending ship request'), false);

// -------------------------------------------------------------- 5. validation unchanged
echo "\n=== 5. server validation unchanged ===\n";
seed($pdo);
$html = render(['customer_id' => '1']);
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$token = $m[1];
$pdo->exec("INSERT INTO ship_requests (id,request_number,customer_id,status) VALUES (20,'SB-P',1,'processing')");
$pdo->exec("INSERT INTO ship_request_items (ship_request_id,customer_storage_id,quantity) VALUES (20,1,8)");
$_GET = ['customer_id' => '1']; $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => $token, 'action' => 'remove', 'storage_id' => '1', 'quantity' => '10'];
ob_start(); require $root . '/modules/customer-storage/view.php'; $html = ob_get_clean();
chk('5a over-removal still refused', str_contains($html, 'already committed to a pending ship request'), true);
chk('5b lot quantity untouched', (int) $pdo->query("SELECT quantity FROM customer_storage WHERE id=1")->fetchColumn(), 10);
chk('5c no transaction left open', $pdo->inTransaction(), false);
chk('5d no ledger row written', (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type='customer_storage_remove'")->fetchColumn(), 0);

echo "\n=== 6. CSRF ===\n";
$_POST = ['csrf_token' => 'bogus', 'action' => 'remove', 'storage_id' => '2', 'quantity' => '6'];
ob_start(); require $root . '/modules/customer-storage/view.php'; $html = ob_get_clean();
chk('6a bad CSRF refused', (int) $pdo->query("SELECT quantity FROM customer_storage WHERE id=2")->fetchColumn(), 6);
chk('6b error surfaced', str_contains($html, 'alert-danger'), true);

echo "\n=== 7. permissions ===\n";
seed($pdo, false);   // no customer-storage.manage
$html = render(['customer_id' => '1']);
chk('7a no remove forms without manage', count(removeInputs($html)), 0);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => app_csrf_token(), 'action' => 'remove', 'storage_id' => '1', 'quantity' => '5'];
ob_start(); require $root . '/modules/customer-storage/view.php'; $html = ob_get_clean();
chk('7b POST refused without manage', (int) $pdo->query("SELECT quantity FROM customer_storage WHERE id=1")->fetchColumn(), 10);
chk('7c permission error shown', str_contains($html, 'do not have permission'), true);

// -------------------------------------------------------------- 8. the default actually works
echo "\n=== 8. submitting the default removes the whole lot ===\n";
seed($pdo);
$html = render(['customer_id' => '1']);
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$inputs = removeInputs($html);
$_GET = ['customer_id' => '1']; $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => $m[1], 'action' => 'remove', 'storage_id' => '2', 'quantity' => $inputs[2]['value']];
register_shutdown_function(static function () use ($pdo) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    global $pass, $fail;
    $lot = $pdo->query("SELECT quantity, status FROM customer_storage WHERE id=2")->fetch(PDO::FETCH_ASSOC);
    chk('8a lot emptied and closed', [(int) $lot['quantity'], $lot['status']], [0, 'shipped']);
    $inv = $pdo->query("SELECT available_quantity, customer_storage_quantity FROM mewmii_inventory WHERE product_id=1")->fetch(PDO::FETCH_ASSOC);
    chk('8b stock returned to available', [(int) $inv['available_quantity'], (int) $inv['customer_storage_quantity']], [6, 14]);
    chk('8c ledger row written', (int) $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory_transactions WHERE transaction_type='customer_storage_remove'")->fetchColumn(), 6);
    chk('8d no transaction left open', $pdo->inTransaction(), false);
    printf("\n============ P4 RESULT: %d passed, %d failed ============\n", $pass, $fail);
});
ob_start(); require $root . '/modules/customer-storage/view.php';
