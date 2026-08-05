<?php
/**
 * P6 customer dropdown deduplication - runtime tests against real MySQL (mewmii_rrtest).
 * Renders the REAL pages and inspects the emitted <option> list.
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
/** [customer_id => trimmed option text] for the customer_id select. */
function options(string $html): array {
    if (!preg_match('/<select[^>]*name="customer_id".*?<\/select>/s', $html, $sel)) { return []; }
    preg_match_all('/<option value="(\d+)"([^>]*)>(.*?)<\/option>/s', $sel[0], $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $o) { $out[(int) $o[1]] = trim(html_entity_decode($o[3], ENT_QUOTES, 'UTF-8')); }
    return $out;
}
function selectedIds(string $html): array {
    if (!preg_match('/<select[^>]*name="customer_id".*?<\/select>/s', $html, $sel)) { return []; }
    preg_match_all('/<option value="(\d+)"[^>]*\bselected\b/', $sel[0], $m);
    return array_map('intval', $m[1]);
}

require_once $root . '/includes/bootstrap.php';
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
    foreach (['customer-storage.view','customer-storage.manage','shipments.manage','ship-my-box.manage','ship-my-box.view'] as $i => $p) {
        $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'m')")->execute([$i+1,$p]);
        $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
    }
    // Four identity shapes, named so they sort to the front.
    $pdo->exec("INSERT INTO customers (id,name,email,instagram_username) VALUES
        (1,'AAA Full','full@x.com','fullig'),
        (2,'AAB NoEmail',NULL,'noemailig'),
        (3,'',NULL,'onlyig'),
        (4,'',NULL,NULL)");
    $pdo->exec("UPDATE customers SET email='onlyemail@x.com', instagram_username=NULL WHERE id=4");
    $pdo->exec("INSERT INTO customers (id,name) VALUES (5,'')");
    // 205 filler customers sorting AFTER the above, to exercise the 200-row cap.
    $vals = [];
    for ($i = 100; $i < 305; $i++) { $vals[] = "({$i},'ZZ Filler " . str_pad((string) ($i - 99), 3, '0', STR_PAD_LEFT) . "','f{$i}@x.com')"; }
    $pdo->exec("INSERT INTO customers (id,name,email) VALUES " . implode(',', $vals));
    $pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price)
                VALUES (1,'PO-1','Item','preorder','simple',5.00,20.00)");
}

function render(string $file, array $get): string {
    global $root;
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start(); require $root . $file; $html = ob_get_clean();
    // Capture the page's own $allCustomers so tests can assert on it directly.
    $GLOBALS['__allCustomers'] = $allCustomers ?? null;
    return $html;
}

seed($pdo);

// --------------------------------------------------------- 1. label semantics
echo "\n=== 1. label rendering (customer-storage/index.php) ===\n";
$html = render('/modules/customer-storage/index.php', []);
$opts = options($html);
chk('1a name + email unchanged', $opts[1] ?? null, 'AAA Full (full@x.com)');
chk('1b name only, no email', $opts[2] ?? null, 'AAB NoEmail');
chk('1c no name -> @instagram (was blank before)', $opts[3] ?? null, '@onlyig');
chk('1d no name/instagram -> email', $opts[4] ?? null, 'onlyemail@x.com');
chk('1e nothing at all -> Unknown Customer', $opts[5] ?? null, 'Unknown Customer');

echo "\n=== 2. helper is the single source ===\n";
chk('2a matches app_customer_dropdown_label() exactly', [
    app_customer_dropdown_label(['name' => 'AAA Full', 'email' => 'full@x.com', 'instagram_username' => 'fullig']),
    app_customer_dropdown_label(['name' => '', 'email' => null, 'instagram_username' => 'onlyig']),
], ['AAA Full (full@x.com)', '@onlyig']);

// --------------------------------------------------------- 3. the 200 cap
echo "\n=== 3. 200-row cap preserved ===\n";
chk('3a total customers in table', (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(), 210);
chk('3b dropdown capped at 200 (plus the blank option)', count($opts), 200);
chk('3c helper returns the same 200', count(app_customer_options($pdo)), 200);

// --------------------------------------------------------- 4. pinned customer
echo "\n=== 4. pinned customer (ship-my-box/create.php) ===\n";
// id 304 is 'ZZ Filler 205' - alphabetically last, so well beyond the 200 cap.
chk('4a pinned id is genuinely outside the cap', isset(options(render('/modules/ship-my-box/create.php', []))[304]), false);
$html = render('/modules/ship-my-box/create.php', ['customer_id' => '304']);
$opts = options($html);
chk('4b pinned customer present when requested', isset($opts[304]), true);
chk('4c pinned customer label correct', $opts[304] ?? null, 'ZZ Filler 205 (f304@x.com)');
chk('4d pinned customer is selected', selectedIds($html), [304]);
chk('4e list still capped (200 + the pinned one)', count($opts), 201);

echo "\n=== 5. no pinning where there was none ===\n";
$html = render('/modules/customer-storage/index.php', []);
chk('5a customer-storage: nothing preselected', selectedIds($html), []);
$html = render('/modules/shipments/create.php', []);
chk('5b shipments manual: nothing preselected', selectedIds($html), []);

// --------------------------------------------------------- 6. shipments modes
echo "\n=== 6. shipments/create.php order vs manual mode ===\n";
$pdo->exec("INSERT INTO mewmii_orders (id,order_number,customer_id,payment_status,order_status,order_date,is_historical)
            VALUES (1,'ORD-1',1,'paid','ready_to_ship','2026-01-01',0)");
$html = render('/modules/shipments/create.php', []);
chk('6a manual mode renders the dropdown', count(options($html)) > 0, true);
chk('6b manual mode actually loaded customers', count($GLOBALS['__allCustomers'] ?? []) > 0, true);
$html = render('/modules/shipments/create.php', ['order_id' => '1']);
chk('6c order mode renders no dropdown', options($html), []);
// Real check that the (unused) customer fetch is skipped in order mode, measured from the
// server's own SELECT counter rather than asserted from reading the source.
$selects = static function (PDO $pdo): int {
    return (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
};
$b = $selects($pdo); render('/modules/shipments/create.php', ['order_id' => '1']);
$orderModeSelects = $selects($pdo) - $b - 1;   // -1 for the SHOW STATUS read itself
chk('6d order mode leaves $allCustomers empty', $GLOBALS['__allCustomers'], []);
$b = $selects($pdo); render('/modules/shipments/create.php', []);
$manualModeSelects = $selects($pdo) - $b - 1;
chk('6e manual mode issues more SELECTs than order mode',
    [$manualModeSelects > $orderModeSelects, $manualModeSelects - $orderModeSelects >= 1], [true, true]);

printf("\n============ P6 RESULT: %d passed, %d failed ============\n", $pass, $fail);
