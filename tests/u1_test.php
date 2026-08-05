<?php
/**
 * UX U1 - context-aware return navigation. Security + navigation + regression tests
 * against the real MySQL throwaway DB (mewmii_rrtest).
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
    printf("  [%s] %-62s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, json_encode($got));
    $ok ? $pass++ : $fail++;
}
/** href of the Back button on a rendered page. */
function backHref(string $html): ?string {
    if (preg_match('#<a[^>]*class="btn btn-outline-secondary btn-sm"[^>]*href="([^"]*)"[^>]*>\s*Back#s', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return null;
}
function backCount(string $html): int {
    return preg_match_all('#>\s*Back(?:\s|<)#', $html);
}

require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/supplier_orders.php';
$pdo = app_db();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

// ================================================================ 1. SECURITY
echo "\n=== 1. app_safe_return_url() - open-redirect resistance ===\n";
$fb = '/modules/supplier-orders/index.php';
$accept = [
    '/modules/products/view.php?id=5'          => 'plain relative path with query',
    '/modules/orders/index.php?view=active&page=3' => 'multi-param path',
    '/index.php'                               => 'site root page',
    '/modules/reports/cost_history.php'        => 'no query string',
];
foreach ($accept as $url => $why) {
    chk("ACCEPT  $why", app_safe_return_url($url, $fb), $url);
}
$reject = [
    'https://evil.com/x'        => 'absolute https URL',
    'http://evil.com'           => 'absolute http URL',
    '//evil.com'                => 'protocol-relative',
    '///evil.com'               => 'triple slash',
    'javascript:alert(1)'       => 'javascript scheme',
    'data:text/html,<script>'   => 'data scheme',
    '/\\evil.com'               => 'backslash after slash',
    '\\\\evil.com'              => 'UNC style backslashes',
    '/modules/x.php?a=1' . "\r\n" . 'Location: https://evil.com' => 'CRLF header injection',
    "/modules/x.php\x00.evil"   => 'null byte',
    'modules/products/view.php' => 'relative without leading slash',
    '../../etc/passwd'          => 'traversal without leading slash',
    'evil.com'                  => 'bare host',
    '/' . str_repeat('a', 600)  => 'over-long value',
    ''                          => 'empty string',
    '   '                       => 'whitespace only',
];
foreach ($reject as $url => $why) {
    chk("REJECT  $why", app_safe_return_url($url, $fb), $fb);
}
chk('REJECT  null (param absent) -> fallback', app_safe_return_url(null, $fb), $fb);

echo "\n=== 2. app_build_return_url() ===\n";
$_SERVER['REQUEST_URI'] = '/modules/orders/index.php?view=active&page=2&updated=1';
$_GET = ['view' => 'active', 'page' => '2', 'updated' => '1'];
chk('2a strips transient flash flags', app_build_return_url(), '/modules/orders/index.php?view=active&page=2');
$_SERVER['REQUEST_URI'] = '/modules/products/index.php?return_url=%2Fx&q=bear';
$_GET = ['return_url' => '/x', 'q' => 'bear'];
chk('2b never nests a return_url', app_build_return_url(), '/modules/products/index.php?q=bear');
$_SERVER['REQUEST_URI'] = '/modules/inventory/index.php';
$_GET = [];
chk('2c bare path when no query', app_build_return_url(), '/modules/inventory/index.php');

echo "\n=== 3. app_link_with_return() ===\n";
chk('3a appends with ? when none', app_link_with_return('/a.php', '/b.php'), '/a.php?return_url=%2Fb.php');
chk('3b appends with & when query exists', app_link_with_return('/a.php?x=1', '/b.php'), '/a.php?x=1&return_url=%2Fb.php');
chk('3c empty context is a no-op', app_link_with_return('/a.php', ''), '/a.php');

// ================================================================ seed
function seed(PDO $pdo): void {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach (['inventory_transactions','mewmii_inventory','product_cost_history','supplier_order_items','supplier_orders',
              'suppliers','shipment_items','shipment_events','shipments','ship_request_items','ship_requests',
              'customer_storage','mewmii_order_items','mewmii_orders','product_variations','products','customers',
              'users','roles','role_permissions','permissions'] as $t) { try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (Throwable $e) {} }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    $pdo->exec("INSERT INTO roles (id,name) VALUES (1,'Owner')");
    $pdo->exec("INSERT INTO users (id,name,email,password_hash,role_id) VALUES (1,'T','t@t.t','x',1)");
    foreach (['inventory.view','inventory.manage','supplier-orders.view','supplier-orders.manage','orders.view',
              'customer-storage.view','customer-storage.manage','ship-my-box.view','ship-my-box.manage',
              'products.view','suppliers.view','shipments.view','shipments.manage'] as $i => $p) {
        $pdo->prepare("INSERT INTO permissions (id,name,module) VALUES (?,?,'m')")->execute([$i+1,$p]);
        $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)")->execute([$i+1]);
    }
    $pdo->exec("INSERT INTO customers (id,name,email) VALUES (1,'Cust A','a@a.a')");
    $pdo->exec("INSERT INTO suppliers (id,name) VALUES (1,'Supplier One')");
    $pdo->exec("INSERT INTO products (id,sku,name,product_type,catalog_type,product_cost,selling_price,supplier_id) VALUES
        (1,'RS-1','Ready Item','ready_stock','simple',10.00,30.00,1),
        (2,'PO-1','Preorder Item','preorder','simple',10.00,30.00,1)");
    $pdo->exec("INSERT INTO supplier_orders (id,supplier_id,purchase_number,status,order_date,is_historical) VALUES (1,1,'PO-A','ordered',CURDATE(),0)");
    $pdo->exec("INSERT INTO supplier_order_items (id,supplier_order_id,product_id,total_quantity,supplier_price,unit_cost_myr,subtotal) VALUES (1,1,1,20,10.00,10.00,200.00)");
    supplier_order_mark_incoming($pdo, 1, 1, 20, null);
    supplier_order_receive_item($pdo, 1, 8);
    $pdo->exec("INSERT INTO mewmii_orders (id,order_number,customer_id,payment_status,order_status,order_date,is_historical) VALUES (1,'ORD-1',1,'paid','processing','2026-01-01',0)");
    // Line 1 = ready stock (drives the Reservation Center queue).
    // Line 2 = preorder, unallocated (drives the Allocation Center queue).
    $pdo->exec("INSERT INTO mewmii_order_items (id,order_id,product_id,variation_id,quantity,selling_price,subtotal) VALUES
        (1,1,1,NULL,5,30.00,150.00),
        (2,1,2,NULL,4,30.00,120.00)");
    $pdo->exec("INSERT INTO customer_storage (id,customer_id,product_id,variation_id,quantity,status,arrival_date) VALUES (1,1,2,NULL,10,'stored','2026-01-01')");
    // INSERT, not UPDATE: no mewmii_inventory row exists for product 2 yet, so an UPDATE here
    // would silently affect zero rows and leave the Allocation Center queue empty.
    $pdo->exec("INSERT INTO mewmii_inventory (product_id,variation_id,available_quantity,arrived_quantity,customer_storage_quantity)
                VALUES (2,NULL,0,5,10)
                ON DUPLICATE KEY UPDATE arrived_quantity=5, customer_storage_quantity=10");
}
function render(string $file, array $get, string $uri = null): string {
    global $root;
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $uri ?? ($file . ($get ? '?' . http_build_query($get) : ''));
    ob_start(); require $root . $file; return ob_get_clean();
}
seed($pdo);

// ================================================================ 4. NAVIGATION
echo "\n=== 4. Supplier Orders view - Back target by origin ===\n";
chk('4a no return_url -> supplier orders index (unchanged)',
    backHref(render('/modules/supplier-orders/view.php', ['id' => '1'])), '/modules/supplier-orders/index.php');
chk('4b from Products',
    backHref(render('/modules/supplier-orders/view.php', ['id' => '1', 'return_url' => '/modules/products/view.php?id=1'])),
    '/modules/products/view.php?id=1');
chk('4c from Reports',
    backHref(render('/modules/supplier-orders/view.php', ['id' => '1', 'return_url' => '/modules/reports/cost_history.php'])),
    '/modules/reports/cost_history.php');
chk('4d from a filtered Supplier Orders list (page preserved)',
    backHref(render('/modules/supplier-orders/view.php', ['id' => '1', 'return_url' => '/modules/supplier-orders/index.php?status=ordered&page=2'])),
    '/modules/supplier-orders/index.php?status=ordered&page=2');
chk('4e hostile return_url falls back safely',
    backHref(render('/modules/supplier-orders/view.php', ['id' => '1', 'return_url' => '//evil.com'])),
    '/modules/supplier-orders/index.php');

echo "\n=== 5. Customer Storage view ===\n";
chk('5a no return_url -> storage list (unchanged)',
    backHref(render('/modules/customer-storage/view.php', ['customer_id' => '1'])), '/modules/customer-storage/index.php');
chk('5b from the customer record',
    backHref(render('/modules/customer-storage/view.php', ['customer_id' => '1', 'return_url' => '/modules/customers/view.php?id=1'])),
    '/modules/customers/view.php?id=1');
chk('5c from a paged storage list',
    backHref(render('/modules/customer-storage/view.php', ['customer_id' => '1', 'return_url' => '/modules/customer-storage/index.php?page=2'])),
    '/modules/customer-storage/index.php?page=2');

echo "\n=== 6. Ship My Box create ===\n";
chk('6a direct entry -> ship my box (unchanged)',
    backHref(render('/modules/ship-my-box/create.php', [])), '/modules/ship-my-box/index.php');
chk('6b from Customer Storage',
    backHref(render('/modules/ship-my-box/create.php', ['customer_id' => '1', 'return_url' => '/modules/customer-storage/view.php?customer_id=1'])),
    '/modules/customer-storage/view.php?customer_id=1');
$h = render('/modules/ship-my-box/create.php', ['customer_id' => '1', 'return_url' => '/modules/customer-storage/view.php?customer_id=1']);
chk('6c context carried in the POST form (survives validation error)',
    str_contains($h, 'name="return_url" value="/modules/customer-storage/view.php?customer_id=1"'), true);

echo "\n=== 7. Inventory reserve / allocate - single Back ===\n";
$h = render('/modules/inventory/reserve.php', ['product_id' => '1']);
chk('7a reserve: exactly ONE Back button (was 2)', backCount($h), 1);
chk('7b reserve: defaults to Reservation Center', backHref($h), '/modules/inventory/reservation-center.php');
chk('7c reserve: honours origin',
    backHref(render('/modules/inventory/reserve.php', ['product_id' => '1', 'return_url' => '/modules/inventory/index.php?stage=arrived'])),
    '/modules/inventory/index.php?stage=arrived');
$h = render('/modules/inventory/allocate.php', ['product_id' => '2']);
chk('7d allocate: exactly ONE Back button (was 2)', backCount($h), 1);
chk('7e allocate: defaults to Allocation Center', backHref($h), '/modules/inventory/allocation-center.php');
chk('7f allocate: honours origin',
    backHref(render('/modules/inventory/allocate.php', ['product_id' => '2', 'return_url' => '/modules/inventory/index.php'])),
    '/modules/inventory/index.php');

echo "\n=== 8. Source pages emit the context ===\n";
chk('8a supplier orders list links carry return_url',
    (bool) preg_match('#view\.php\?id=1&return_url=[^"\#]+\#receive-batch-form#', render('/modules/supplier-orders/index.php', [])), true);
chk('8b storage list links carry return_url',
    str_contains(render('/modules/customer-storage/index.php', []), 'customer-storage/view.php?customer_id=1&return_url='), true);
chk('8c reservation center Manual link carries it',
    str_contains(render('/modules/inventory/reservation-center.php', []), 'reserve.php?product_id=1&return_url='), true);
chk('8d allocation center Manual link carries it',
    str_contains(render('/modules/inventory/allocation-center.php', []), 'allocate.php?product_id=2&return_url='), true);
chk('8e drawer fragment deliberately has none (AJAX, no page URL)',
    str_contains(file_get_contents($root . '/modules/inventory/views/drawer.php'), 'return_url'), false);

// Regression guard. The original U1 patch inserted $returnContext at the FIRST header include,
// which on every page carrying a 404 early-exit guard is inside that block - so the variable
// was undefined on the normal render path and the emitted return_url was empty. Six files were
// affected. Assert the declaration is at top level (brace depth 0) in every page that uses it,
// rather than trusting indentation.
echo "\n=== 9. \$returnContext is declared on the normal render path ===\n";
$declOffenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/modules'));
foreach ($it as $entry) {
    if (!$entry->isFile() || $entry->getExtension() !== 'php') {
        continue;
    }
    $code = file_get_contents($entry->getPathname());
    if (!str_contains($code, '$returnContext = app_build_return_url();')) {
        continue;
    }
    $depth = 0;
    $declDepth = null;
    $tokens = token_get_all($code);
    foreach ($tokens as $i => $tok) {
        if (is_string($tok)) {
            if ($tok === '{') { $depth++; }
            if ($tok === '}') { $depth--; }
            continue;
        }
        if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
        if ($tok[0] === T_VARIABLE && $tok[1] === '$returnContext' && $declDepth === null) {
            for ($j = $i + 1; $j < count($tokens); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { continue; }
                if ($tokens[$j] === '=') { $declDepth = $depth; }
                break;
            }
        }
    }
    if ($declDepth !== 0) {
        $declOffenders[] = basename(dirname($entry->getPathname())) . '/' . $entry->getBasename();
    }
}
chk('9a every declaration is top-level (not inside a 404 guard)', $declOffenders, []);

// And prove it end-to-end on a page that has a 404 early-exit guard: the emitted link must
// carry a real, non-empty return_url.
$h = render('/modules/customer-storage/view.php', ['customer_id' => '1']);
chk('9b customer-storage/view emits a NON-EMPTY return_url',
    (bool) preg_match('#ship-my-box/create\.php\?customer_id=1&return_url=%2F[^"&]+#', $h), true);
chk('9c ...and raises no undefined-variable warning',
    str_contains($h, 'Undefined variable'), false);

printf("\n============ U1 RESULT: %d passed, %d failed ============\n", $pass, $fail);
