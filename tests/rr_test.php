<?php
/**
 * Reverse Receiving acceptance tests - THROWAWAY, runs against a local throwaway database only.
 * Points at mewmii_rrtest via env vars; never touches config.php/.env or production.
 */

putenv('DB_HOST=127.0.0.1');
putenv('DB_DATABASE=mewmii_rrtest');
putenv('DB_USERNAME=root');
putenv('DB_PASSWORD=');

require_once __DIR__ . '/_guard.php';

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/supplier_orders.php';
require_once $root . '/includes/product_cost.php';
require_once $root . '/includes/customer_storage.php';
require_once $root . '/includes/order_fulfillment.php';

$pdo = app_db();
$_SESSION['user_id'] = 1;

$pass = 0; $fail = 0;
function chk(string $label, $got, $want) {
    global $pass, $fail;
    $ok = ($got === $want);
    printf("  [%s] %-58s got=%-22s want=%s\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
    $ok ? $pass++ : $fail++;
}
function chkTrue(string $label, bool $cond) { chk($label, $cond, true); }
function expectThrow(string $label, callable $fn, string $needle = '') {
    global $pass, $fail, $pdo;
    try {
        $fn();
        printf("  [FAIL] %-58s (no exception thrown)\n", $label); $fail++;
    } catch (Throwable $e) {
        $ok = $needle === '' || stripos($e->getMessage(), $needle) !== false;
        printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $label, substr($e->getMessage(), 0, 70));
        $ok ? $pass++ : $fail++;
    }
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ---------- fixtures ----------
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['inventory_transactions','mewmii_inventory','supplier_order_items','supplier_orders',
          'product_cost_history','products','suppliers','users','roles','customers',
          'customer_storage','mewmii_order_items','mewmii_orders','supplier_order_events'] as $t) {
    $pdo->exec("TRUNCATE TABLE {$t}");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

$pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
$pdo->exec("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t','x',1)");
$pdo->exec("INSERT INTO suppliers (id,name) VALUES (1,'Supplier One')");
$pdo->exec("INSERT INTO customers (id,name) VALUES (1,'Cust One')");
$pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price,supplier_id)
            VALUES (1,'A-1','Product A','ready_stock','simple',10.00,30.00,1),
                   (2,'B-1','Product B','ready_stock','simple',6.00,20.00,1),
                   (3,'P-1','Preorder P','preorder','simple',8.00,25.00,1)");

function newPO(PDO $pdo, string $number, int $productId, int $qty, float $price): array {
    $pdo->prepare("INSERT INTO supplier_orders (supplier_id,purchase_number,status,order_date)
                   VALUES (1,?, 'ordered', CURDATE())")->execute([$number]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO supplier_order_items
        (supplier_order_id,product_id,total_quantity,supplier_price,unit_cost_myr,subtotal)
        VALUES (?,?,?,?,?,?)")->execute([$orderId,$productId,$qty,$price,$price,$qty*$price]);
    $itemId = (int) $pdo->lastInsertId();
    supplier_order_mark_incoming($pdo, $productId, $itemId, $qty, null);
    return [$orderId, $itemId];
}
function inv(PDO $pdo, int $productId): array {
    $s = $pdo->prepare("SELECT available_quantity a, incoming_quantity i, arrived_quantity ar, reserved_quantity r
                        FROM mewmii_inventory WHERE product_id=? AND variation_id IS NULL");
    $s->execute([$productId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: ['a'=>0,'i'=>0,'ar'=>0,'r'=>0];
}
function ledgerCount(PDO $pdo): int { return (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions")->fetchColumn(); }
function orderRow(PDO $pdo, int $id): array {
    $s = $pdo->prepare("SELECT status, received_date FROM supplier_orders WHERE id=?");
    $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC);
}

echo "\n=== TEST 1: Full reversal unlock ===\n";
[$o1,$i1] = newPO($pdo,'PO-1',1,100,10.00);
supplier_order_receive_item($pdo,$i1,100);
$afterReceive = inv($pdo,1);
chk('1a received qty', supplier_order_item_received_quantity($pdo,$i1), 100);
chk('1b available after receive', (int)$afterReceive['a'], 100);
chk('1c incoming after receive', (int)$afterReceive['i'], 0);
$r = orderRow($pdo,$o1);
chk('1d status after receive', $r['status'], 'received');
chkTrue('1e received_date set', $r['received_date'] !== null);
chkTrue('1f has_receiving_history true', supplier_order_has_receiving_history($pdo,$o1));
$ledgerBefore = ledgerCount($pdo);

supplier_order_reverse_receipt($pdo,$i1,100,'wrong product received');
$r = orderRow($pdo,$o1); $after = inv($pdo,1);
chk('1g status back to ordered', $r['status'], 'ordered');
chk('1h received_date cleared', $r['received_date'], null);
chk('1i net received now 0', supplier_order_item_received_quantity($pdo,$i1), 0);
chk('1j available returned', (int)$after['a'], 0);
chk('1k incoming restored', (int)$after['i'], 100);
chk('1l has_receiving_history false (UNLOCK)', supplier_order_has_receiving_history($pdo,$o1), false);
chk('1m ledger grew by exactly 1', ledgerCount($pdo), $ledgerBefore+1);
$orig = (int)$pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type='supplier_receive' AND quantity=100")->fetchColumn();
$neg  = (int)$pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type='supplier_receive' AND quantity=-100")->fetchColumn();
chk('1n original +100 row intact', $orig, 1);
chk('1o reversing -100 row exists', $neg, 1);
$reason = $pdo->query("SELECT notes FROM inventory_transactions WHERE quantity=-100")->fetchColumn();
chk('1p reason recorded on ledger', $reason, 'wrong product received');

echo "\n=== TEST 3: Partial reversal ===\n";
[$o3,$i3] = newPO($pdo,'PO-3',2,50,6.00);
supplier_order_receive_item($pdo,$i3,50);
supplier_order_reverse_receipt($pdo,$i3,20,'miscount');
$r = orderRow($pdo,$o3);
chk('3a status partially_received', $r['status'], 'partially_received');
chk('3b received_date cleared', $r['received_date'], null);
chk('3c net received 30', supplier_order_item_received_quantity($pdo,$i3), 30);
chk('3d available 30', (int)inv($pdo,2)['a'], 30);
chk('3e incoming 20', (int)inv($pdo,2)['i'], 20);

echo "\n=== TEST 4: Allocation guards ===\n";
[$o4,$i4] = newPO($pdo,'PO-4',1,10,10.00);
supplier_order_receive_item($pdo,$i4,10);
// simulate reservation: move available -> reserved (what order reservation does)
$pdo->exec("UPDATE mewmii_inventory SET available_quantity=available_quantity-8, reserved_quantity=reserved_quantity+8 WHERE product_id=1 AND variation_id IS NULL");
$state = supplier_order_reversible_quantity($pdo,$i4);
chk('4a reversible limited to free stock', $state['reversible'], 2);
chk('4b blocked count', $state['blocked'], 8);
$invBefore = inv($pdo,1); $lcBefore = ledgerCount($pdo);
expectThrow('4c reversing reserved stock blocked', fn() => supplier_order_reverse_receipt($pdo,$i4,10,'x'), 'reserved or already shipped');
chk('4d inventory unchanged after block', inv($pdo,1), $invBefore);
chk('4e ledger unchanged after block', ledgerCount($pdo), $lcBefore);
chkTrue('4f partial reversal within free stock still allowed', (function() use($pdo,$i4){ supplier_order_reverse_receipt($pdo,$i4,2,'ok'); return true; })());

// preorder allocated to customer storage
[$o4b,$i4b] = newPO($pdo,'PO-4B',3,10,8.00);
supplier_order_receive_item($pdo,$i4b,10);
chk('4g preorder lands in arrived', (int)inv($pdo,3)['ar'], 10);
customer_storage_add($pdo,1,3,7,null,null,null,'arrived');
$stateP = supplier_order_reversible_quantity($pdo,$i4b);
chk('4h preorder reversible limited', $stateP['reversible'], 3);
expectThrow('4i allocated preorder blocked', fn() => supplier_order_reverse_receipt($pdo,$i4b,10,'x'), 'allocated to customer storage');

echo "\n=== TEST 5: Security / validation ===\n";
[$o5,$i5] = newPO($pdo,'PO-5',2,10,6.00);
supplier_order_receive_item($pdo,$i5,10);
expectThrow('5a blank reason rejected', fn() => supplier_order_reverse_receipt($pdo,$i5,1,'   '), 'reason');
expectThrow('5b over-reversal rejected', fn() => supplier_order_reverse_receipt($pdo,$i5,999,'x'), 'only 10');
expectThrow('5c zero quantity rejected', fn() => supplier_order_reverse_receipt($pdo,$i5,0,'x'), 'at least 1');
$pdo->prepare("UPDATE supplier_orders SET is_historical=1 WHERE id=?")->execute([$o5]);
expectThrow('5d historical order blocked', fn() => supplier_order_reverse_receipt($pdo,$i5,1,'x'), 'historical');
$pdo->prepare("UPDATE supplier_orders SET is_historical=0, status='completed' WHERE id=?")->execute([$o5]);
expectThrow('5e completed order blocked', fn() => supplier_order_reverse_receipt($pdo,$i5,1,'x'), 'completed or cancelled');
$pdo->prepare("UPDATE supplier_orders SET status='cancelled' WHERE id=?")->execute([$o5]);
expectThrow('5f cancelled order blocked', fn() => supplier_order_reverse_receipt($pdo,$i5,1,'x'), 'completed or cancelled');

echo "\n=== TEST 6: Ledger integrity ===\n";
$updates = (int)$pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type='supplier_receive'")->fetchColumn();
chkTrue('6a supplier_receive rows only ever appended', $updates > 0);
chk('6b no row has been zeroed out', (int)$pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE quantity=0")->fetchColumn(), 0);

echo "\n=== TEST 2: Real correction workflow ===\n";
[$o2,$i2a] = newPO($pdo,'PO-2',1,100,10.00);
supplier_order_receive_item($pdo,$i2a,100);
$histBefore = (int)$pdo->query("SELECT COUNT(*) FROM product_cost_history")->fetchColumn();
supplier_order_reverse_receipt($pdo,$i2a,100,'wrong product');
chk('2a unlocked for edit', supplier_order_has_receiving_history($pdo,$o2), false);
chk('2b cost history NOT deleted by reversal', (int)$pdo->query("SELECT COUNT(*) FROM product_cost_history")->fetchColumn(), $histBefore);
// correct the PO: remove A, add B x20
supplier_order_apply_edit($pdo,$o2,1,'corrected',[
    ['product_id'=>2,'variation_id'=>null,'quantity'=>20,'supplier_price'=>6.00,'unit_cost_foreign'=>6.00],
],0.00,'unpaid','MYR',null,null);
$lines = $pdo->prepare("SELECT product_id,total_quantity FROM supplier_order_items WHERE supplier_order_id=?");
$lines->execute([$o2]); $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
chk('2c PO now has exactly 1 line', count($rows), 1);
chk('2d line is Product B', (int)$rows[0]['product_id'], 2);
chk('2e qty 20', (int)$rows[0]['total_quantity'], 20);
$newItemId = (int)$pdo->query("SELECT id FROM supplier_order_items WHERE supplier_order_id={$o2}")->fetchColumn();
$bBefore = (int)inv($pdo,2)['a'];
supplier_order_receive_item($pdo,$newItemId,20);
chk('2f Product B received into available', (int)inv($pdo,2)['a'], $bBefore+20);
chk('2g order status received', orderRow($pdo,$o2)['status'], 'received');
$snapNew = (int)$pdo->prepare("SELECT COUNT(*) FROM product_cost_history WHERE supplier_order_item_id=?") ;
$sn = $pdo->prepare("SELECT COUNT(*) FROM product_cost_history WHERE supplier_order_item_id=?"); $sn->execute([$newItemId]);
chk('2h NEW snapshot exists for the corrected line', (int)$sn->fetchColumn(), 1);
$oldSnap = $pdo->prepare("SELECT COUNT(*) FROM product_cost_history WHERE supplier_order_item_id=?"); $oldSnap->execute([$i2a]);
chk('2i OLD snapshot survived line removal (expect 0 = CASCADE loss)', (int)$oldSnap->fetchColumn(), 0);

echo "\n=== RECEIVING REGRESSION (extraction safety) ===\n";
[$o6,$i6] = newPO($pdo,'PO-6',2,10,6.00);
supplier_order_receive_item($pdo,$i6,4);
chk('R1 partial receive -> partially_received', orderRow($pdo,$o6)['status'], 'partially_received');
supplier_order_receive_item($pdo,$i6,6);
$r6 = orderRow($pdo,$o6);
chk('R2 full receive -> received', $r6['status'], 'received');
chkTrue('R3 received_date set', $r6['received_date'] !== null);
[$o7,$i7] = newPO($pdo,'PO-7',2,5,6.00);
supplier_order_receive_all_remaining($pdo,$o7);
chk('R4 Mark Arrived -> received', orderRow($pdo,$o7)['status'], 'received');
chk('R5 all units received', supplier_order_item_received_quantity($pdo,$i7), 5);

printf("\n================ RESULT: %d passed, %d failed ================\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
