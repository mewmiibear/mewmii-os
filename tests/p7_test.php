<?php
/**
 * P7 Supplier Orders Receive shortcut - runtime tests against real MySQL (mewmii_rrtest).
 * Renders the REAL index/view pages and exercises the real receiving action.
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
/** Supplier order ids that render a Receive button on the list. */
function receiveButtons(string $html): array {
    // Tolerates extra query params between id and the fragment - UX U1 appends &return_url=
    // there, and the fragment must remain last for the anchor to work.
    preg_match_all('#href="/modules/supplier-orders/view\.php\?id=(\d+)[^"\#]*\#receive-batch-form"#', $html, $m);
    return array_values(array_unique(array_map('intval', $m[1])));
}

require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/supplier_orders.php';
$pdo = app_db();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

function seed(PDO $pdo, bool $withManage = true): void {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach (['inventory_transactions','mewmii_inventory','product_cost_history','supplier_order_items',
              'supplier_orders','suppliers','product_variations','products','customers',
              'users','roles','role_permissions','permissions'] as $t) {
        try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (Throwable $e) {}
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    $pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
    $pdo->exec("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t','x',1)");
    $perms = ['supplier-orders.view','inventory.view'];
    if ($withManage) { $perms[] = 'supplier-orders.manage'; $perms[] = 'inventory.manage'; }
    foreach ($perms as $i => $p) {
        $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'supplier-orders')")->execute([$i+1,$p]);
        $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
    }
    $pdo->exec("INSERT INTO suppliers (id,name) VALUES (1,'Supplier One')");
    $pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price,supplier_id)
                VALUES (1,'RS-1','Ready Item','ready_stock','simple',10.00,30.00,1)");

    // 1 untouched  2 partially received  3 fully received  4 cancelled  5 historical  6 completed
    $specs = [
        [1, 'PO-OPEN',      'ordered',   0, 0],
        [2, 'PO-PARTIAL',   'ordered',   0, 0],
        [3, 'PO-FULL',      'ordered',   0, 0],
        [4, 'PO-CANCELLED', 'cancelled', 0, 0],
        [5, 'PO-HIST',      'ordered',   1, 0],
        [6, 'PO-DONE',      'completed', 0, 0],
    ];
    foreach ($specs as [$id, $num, $status, $hist, $_]) {
        $pdo->prepare("INSERT INTO supplier_orders (id,supplier_id,purchase_number,status,order_date,is_historical)
                       VALUES (?,1,?,?,CURDATE(),?)")->execute([$id, $num, $status, $hist]);
        $pdo->prepare("INSERT INTO supplier_order_items (id,supplier_order_id,product_id,total_quantity,supplier_price,unit_cost_myr,subtotal)
                       VALUES (?,?,1,10,10.00,10.00,100.00)")->execute([$id, $id]);
        supplier_order_mark_incoming($pdo, 1, $id, 10, null);
    }
    supplier_order_receive_item($pdo, 2, 4);    // partial
    supplier_order_receive_item($pdo, 3, 10);   // full
}

function render(string $file, array $get): string {
    global $root;
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start(); require $root . $file; return ob_get_clean();
}

// ------------------------------------------------------------ 1. visibility
echo "\n=== 1. which orders show a Receive shortcut ===\n";
seed($pdo);
$html = render('/modules/supplier-orders/index.php', []);
$btns = receiveButtons($html);
sort($btns);
chk('1a untouched PO (id 1) shows Receive', in_array(1, $btns, true), true);
chk('1b partially received PO (id 2) shows Receive', in_array(2, $btns, true), true);
chk('1c fully received PO (id 3) hidden', in_array(3, $btns, true), false);
chk('1d cancelled PO (id 4) hidden', in_array(4, $btns, true), false);
chk('1e historical PO (id 5) hidden', in_array(5, $btns, true), false);
chk('1f completed PO (id 6) hidden', in_array(6, $btns, true), false);
chk('1g exactly the two receivable orders', $btns, [1, 2]);
chk('1h View still present for every row', substr_count($html, 'supplier-orders/view.php?id=') >= 6, true);

// ------------------------------------------------------------ 2. the anchor resolves
echo "\n=== 2. the link points at the existing receiving UI ===\n";
$view = render('/modules/supplier-orders/view.php', ['id' => '2']);
chk('2a receive-batch-form anchor exists on that page', str_contains($view, 'id="receive-batch-form"'), true);
chk('2b it is the existing batch action', str_contains($view, 'name="action" value="receive_batch"'), true);
chk('2c Fill Remaining control present', str_contains($view, 'id="receive-fill-remaining"'), true);
$viewFull = render('/modules/supplier-orders/view.php', ['id' => '3']);
chk('2d fully received order renders no receive form', str_contains($viewFull, 'id="receive-batch-form"'), false);
chk('2e ...and no button pointed at it either', in_array(3, receiveButtons($html), true), false);
$viewHist = render('/modules/supplier-orders/view.php', ['id' => '5']);
chk('2f historical order renders no receive form', str_contains($viewHist, 'id="receive-batch-form"'), false);

// ------------------------------------------------------------ 3. permissions
echo "\n=== 3. permissions ===\n";
seed($pdo, false);   // supplier-orders.view only
$htmlNoManage = render('/modules/supplier-orders/index.php', []);
chk('3a no Receive buttons without manage', receiveButtons($htmlNoManage), []);
chk('3b list itself still renders', str_contains($htmlNoManage, 'PO-PARTIAL'), true);
chk('3c Edit/Delete also absent (unchanged gating)', str_contains($htmlNoManage, 'supplier-orders/edit.php'), false);
$viewNoManage = render('/modules/supplier-orders/view.php', ['id' => '2']);
chk('3d detail page refuses the receive form without manage', str_contains($viewNoManage, 'id="receive-batch-form"'), false);

// ------------------------------------------------------------ 4. receiving unchanged
echo "\n=== 4. existing receiving still works ===\n";
seed($pdo);
$before = supplier_order_item_received_quantity($pdo, 2);
$view = render('/modules/supplier-orders/view.php', ['id' => '2']);
preg_match('/name="csrf_token" value="([^"]+)"/', $view, $m);
$_GET = ['id' => '2']; $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => $m[1], 'action' => 'receive_batch', 'receive_qty' => [2 => '6']];
register_shutdown_function(static function () use ($pdo, $before) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    global $pass, $fail;
    $after = supplier_order_item_received_quantity($pdo, 2);
    chk('4a batch receive still works (4 -> 10)', [$before, $after], [4, 10]);
    chk('4b order auto-advanced to received', $pdo->query("SELECT status FROM supplier_orders WHERE id=2")->fetchColumn(), 'received');
    chk('4c stock landed in available', (int) $pdo->query("SELECT available_quantity FROM mewmii_inventory WHERE product_id=1")->fetchColumn() > 0, true);
    chk('4d no transaction left open', $pdo->inTransaction(), false);
    // Now fully received, the shortcut must disappear on the next list render.
    $_GET = []; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start(); require dirname(__DIR__) . '/modules/supplier-orders/index.php'; $h = ob_get_clean();
    chk('4e Receive shortcut gone once fully received', in_array(2, receiveButtons($h), true), false);
    printf("\n============ P7 RESULT: %d passed, %d failed ============\n", $pass, $fail);
});
ob_start(); require $root . '/modules/supplier-orders/view.php';
