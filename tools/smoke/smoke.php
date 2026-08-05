<?php

declare(strict_types=1);

/**
 * Mewmii OS - Smoke Verification Tool
 * =============================================================================
 * A standalone structural regression harness for the V3 UI/UX phases.
 *
 * INDEPENDENCE: this file requires NOTHING from the application. It does not
 * include bootstrap.php, does not open a database connection, and does not call
 * a single application function. It talks to a running Mewmii OS over plain
 * HTTP exactly as a browser would. Nothing in the application was modified to
 * support it. Delete this directory and the application is unaffected.
 *
 * WHAT IT DOES
 *   1. Discovers routes by scanning the filesystem, then classifies each one.
 *   2. Logs in over HTTP (CSRF-aware), holding a session cookie.
 *   3. GETs every crawlable route and records a structural fingerprint.
 *   4. Writes the fingerprints to a JSON snapshot.
 *   5. Diffs two snapshots and fails loudly on structural regressions.
 *
 * SAFETY - read before extending the route rules
 *   The crawler issues GET only, never POST (beyond the single login), and
 *   works from a deny-list of endpoint classes. This matters: at least one
 *   endpoint (modules/products/ajax/delete_variation.php) performs a
 *   destructive write WITHOUT checking the request method, so a naive
 *   "GET every .php" crawler would delete data. Every exclusion is recorded
 *   with a machine-readable reason - run `php smoke.php routes` to review them.
 *
 * USAGE
 *   php smoke.php routes
 *   php smoke.php capture --base-url=http://localhost/mewmii --email=... --password=... --out=snapshots/before.json
 *   php smoke.php compare snapshots/before.json snapshots/after.json
 *
 * Exit codes: 0 = success / no regressions, 1 = regressions found, 2 = usage or transport error.
 */

const APP_ROOT = __DIR__ . '/../..';

/**
 * Endpoint classes deliberately not crawled. Each entry is [regex, reason].
 * Matched against the route path relative to the app root, using forward slashes.
 */
const EXCLUSIONS = [
    ['#(^|/)_[^/]+\.php$#',                 'partial - included by another page, not routable on its own'],
    ['#(^|/)ajax/#',                        'AJAX fragment endpoint - returns JSON/HTML fragments, not a page; some perform writes without a POST guard'],
    ['#(^|/)ajax_[^/]*\.php$#',             'AJAX fragment endpoint'],
    ['#(^|/)delete\.php$#',                 'destructive action endpoint (POST-guarded, but never worth a GET)'],
    ['#(^|/)bulk_action\.php$#',            'bulk mutation endpoint (POST-only)'],
    ['#(^|/)sync_one\.php$#',               'action endpoint - POST-only, redirects on GET'],
    ['#(^|/)reopen_preorder\.php$#',        'action endpoint - POST-only, redirects on GET'],
    ['#(^|/)create_order\.php$#',           'action endpoint - POST-only, redirects on GET'],
    ['#(^|/)retry_pending\.php$#',          'action endpoint - triggers webhook retries'],
    ['#(^|/)export_[^/]*\.php$#',           'file download - returns CSV, not an HTML page'],
    ['#_download\.php$#',                   'file download - returns a binary attachment'],
    ['#(^|/)import_template\.php$#',        'file download - returns a CSV template'],
    ['#(^|/)reset_test_data\.php$#',        'DESTRUCTIVE - deletes orders, customers, supplier orders. Never crawled.'],
    ['#(^|/)logout\.php$#',                 'would terminate the crawler session mid-run'],
    ['#(^|/)install\.php$#',                'installer - would re-seed the database'],
    ['#(^|/)test-db\.php$#',                'developer connectivity probe, not an application page'],
    ['#(^|/)generate_password\.php$#',      'developer utility, not an application page'],
    ['#(^|/)config\.example\.php$#',        'configuration template, not routable'],
];

/** Signals that a PHP diagnostic leaked into the response body. */
const PHP_ERROR_SIGNALS = [
    'Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:',
    'Uncaught exception', 'Uncaught Error', 'Uncaught TypeError',
    'Call to undefined', 'Undefined variable', 'Undefined index',
    'Undefined array key', 'Undefined property', 'SQLSTATE',
];

// =============================================================================
// Entry point
// =============================================================================

$argvLocal = $argv ?? [];
array_shift($argvLocal);
$command = $argvLocal[0] ?? '';

switch ($command) {
    case 'routes':
        exit(commandRoutes());
    case 'capture':
        exit(commandCapture(parseOptions($argvLocal)));
    case 'compare':
        exit(commandCompare($argvLocal[1] ?? '', $argvLocal[2] ?? ''));
    default:
        fwrite(STDERR, usage());
        exit(2);
}

function usage(): string
{
    return <<<TXT

Mewmii OS Smoke Verification Tool

  php smoke.php routes
      List every discovered route and why anything was excluded. No HTTP.

  php smoke.php capture --base-url=URL --email=EMAIL --password=PASS [--out=FILE] [--limit=N]
      Log in, crawl every crawlable route, write a JSON snapshot.
      --out defaults to snapshots/snapshot-<timestamp>.json

  php smoke.php compare BEFORE.json AFTER.json
      Diff two snapshots. Exits 1 if a structural regression is found.

Credentials may also be supplied via SMOKE_BASE_URL / SMOKE_EMAIL / SMOKE_PASSWORD.

TXT;
}

function parseOptions(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $options[$m[1]] = $m[2];
        }
    }

    $options['base-url'] = rtrim((string) ($options['base-url'] ?? getenv('SMOKE_BASE_URL') ?: ''), '/');
    $options['email'] = (string) ($options['email'] ?? getenv('SMOKE_EMAIL') ?: '');
    $options['password'] = (string) ($options['password'] ?? getenv('SMOKE_PASSWORD') ?: '');

    return $options;
}

// =============================================================================
// Route discovery
// =============================================================================

/**
 * @return array{crawlable: array<int, array<string, mixed>>, excluded: array<int, array<string, string>>}
 */
function discoverRoutes(): array
{
    $root = realpath(APP_ROOT);
    if ($root === false) {
        fwrite(STDERR, "Cannot resolve application root.\n");
        exit(2);
    }

    $files = [];
    foreach (glob($root . '/*.php') ?: [] as $file) {
        $files[] = $file;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/modules', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    $crawlable = [];
    $excluded = [];

    foreach ($files as $file) {
        $route = str_replace('\\', '/', substr($file, strlen($root) + 1));

        $reason = null;
        foreach (EXCLUSIONS as [$pattern, $why]) {
            if (preg_match($pattern, $route) === 1) {
                $reason = $why;
                break;
            }
        }

        if ($reason !== null) {
            $excluded[] = ['route' => $route, 'reason' => $reason];
            continue;
        }

        // A page reading $_GET['id'] cannot be crawled bare - it needs a real
        // record. Detected by reading the source, never by executing it.
        $source = (string) file_get_contents($file);
        $needsId = preg_match("/\\\$_GET\['id'\]/", $source) === 1;

        $crawlable[] = ['route' => $route, 'needs_id' => $needsId];
    }

    return ['crawlable' => $crawlable, 'excluded' => $excluded];
}

function commandRoutes(): int
{
    $discovered = discoverRoutes();

    $plain = [];
    $parameterised = [];
    foreach ($discovered['crawlable'] as $entry) {
        if ($entry['needs_id']) {
            $parameterised[] = $entry['route'];
        } else {
            $plain[] = $entry['route'];
        }
    }

    printf("CRAWLABLE - no parameters (%d)\n", count($plain));
    foreach ($plain as $route) {
        echo "  {$route}\n";
    }

    printf("\nCRAWLABLE - needs ?id= , discovered from list pages at run time (%d)\n", count($parameterised));
    foreach ($parameterised as $route) {
        echo "  {$route}\n";
    }

    printf("\nEXCLUDED (%d)\n", count($discovered['excluded']));
    foreach ($discovered['excluded'] as $entry) {
        printf("  %-52s %s\n", $entry['route'], $entry['reason']);
    }

    printf(
        "\nTotal PHP files: %d | crawlable: %d | excluded: %d\n",
        count($discovered['crawlable']) + count($discovered['excluded']),
        count($discovered['crawlable']),
        count($discovered['excluded'])
    );

    return 0;
}

// =============================================================================
// HTTP client
// =============================================================================

final class Http
{
    private string $baseUrl;
    private string $cookieJar;

    public function __construct(string $baseUrl)
    {
        if (!function_exists('curl_init')) {
            fwrite(STDERR, "ext-curl is required.\n");
            exit(2);
        }
        $this->baseUrl = $baseUrl;
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'mewmii_smoke_');
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    /** @return array{status: int, body: string, url: string, error: string} */
    public function get(string $path): array
    {
        return $this->request($path, null);
    }

    /** @param array<string, string>|null $post */
    public function request(string $path, ?array $post): array
    {
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $this->baseUrl . '/' . ltrim($path, '/'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => 'MewmiiSmoke/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        if ($post !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = curl_exec($handle);
        $result = [
            'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'body' => is_string($body) ? $body : '',
            'url' => (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
            'error' => (string) curl_error($handle),
        ];
        curl_close($handle);

        return $result;
    }

    public function login(string $email, string $password): bool
    {
        $page = $this->get('/login.php');
        if ($page['status'] !== 200) {
            fwrite(STDERR, "Cannot reach /login.php (HTTP {$page['status']}). {$page['error']}\n");
            return false;
        }

        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m) !== 1) {
            fwrite(STDERR, "Could not read the CSRF token from /login.php.\n");
            return false;
        }

        $response = $this->request('/login.php', [
            'csrf_token' => $m[1],
            'email' => $email,
            'password' => $password,
        ]);

        // The app redirects to /index.php on success; the login form is re-rendered on failure.
        if (stripos($response['body'], 'Invalid email or password') !== false) {
            fwrite(STDERR, "Login rejected: invalid email or password.\n");
            return false;
        }

        if (stripos($response['body'], 'name="password"') !== false) {
            fwrite(STDERR, "Login did not take - still on the login form.\n");
            return false;
        }

        return true;
    }
}

// =============================================================================
// Structural fingerprint
// =============================================================================

/**
 * Reduce a rendered page to the structure V3 must not change by accident.
 * Form signatures are the load-bearing part: names are sorted so that moving a
 * field is invisible, but losing one is not.
 *
 * @return array<string, mixed>
 */
function fingerprint(string $html): array
{
    $errors = [];
    foreach (PHP_ERROR_SIGNALS as $signal) {
        if (strpos($html, $signal) !== false) {
            $errors[] = $signal;
        }
    }

    // loadHTML() emits its own warning on an empty string, so short-circuit.
    if (trim($html) === '') {
        return ['empty_body' => true, 'php_errors' => $errors];
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return ['parse_failed' => true, 'php_errors' => $errors];
    }

    $xpath = new DOMXPath($document);
    $count = static fn (string $q): int => (($nodes = $xpath->query($q)) === false ? 0 : $nodes->length);

    $forms = [];
    $formNodes = $xpath->query('//form');
    if ($formNodes !== false) {
        foreach ($formNodes as $form) {
            /** @var DOMElement $form */
            $inputs = [];
            $buttons = [];

            $fields = $xpath->query('.//input | .//select | .//textarea', $form);
            if ($fields !== false) {
                foreach ($fields as $field) {
                    /** @var DOMElement $field */
                    $name = trim($field->getAttribute('name'));
                    $type = strtolower($field->getAttribute('type'));
                    if ($name === '') {
                        continue;
                    }
                    // CSRF tokens are per-session noise, not structure.
                    if ($name === 'csrf_token') {
                        continue;
                    }
                    if ($type === 'submit' || $type === 'button') {
                        $buttons[] = $name;
                        continue;
                    }
                    $inputs[] = $name;
                }
            }

            $buttonNodes = $xpath->query('.//button', $form);
            if ($buttonNodes !== false) {
                foreach ($buttonNodes as $button) {
                    /** @var DOMElement $button */
                    $name = trim($button->getAttribute('name'));
                    $buttons[] = $name !== '' ? $name : ('@' . trim($button->textContent));
                }
            }

            sort($inputs);
            sort($buttons);

            $forms[] = [
                'action' => trim($form->getAttribute('action')),
                'method' => strtolower(trim($form->getAttribute('method'))) ?: 'get',
                'inputs' => array_values(array_unique($inputs)),
                'buttons' => array_values(array_unique($buttons)),
            ];
        }
    }

    usort($forms, static function (array $a, array $b): int {
        return [$a['action'], $a['method'], implode(',', $a['inputs'])]
            <=> [$b['action'], $b['method'], implode(',', $b['inputs'])];
    });

    $tables = [];
    $tableNodes = $xpath->query('//table');
    if ($tableNodes !== false) {
        foreach ($tableNodes as $index => $table) {
            $headers = $xpath->query('.//thead//th', $table);
            $tables[] = ['index' => $index, 'columns' => $headers === false ? 0 : $headers->length];
        }
    }

    $titleNodes = $xpath->query('//title');
    $title = ($titleNodes !== false && $titleNodes->length > 0) ? trim($titleNodes->item(0)->textContent) : '';

    return [
        'php_errors' => $errors,
        'title' => $title,
        'headings' => [
            'h1' => $count('//h1'),
            'h2' => $count('//h2'),
            'h3' => $count('//h3'),
            'h5' => $count('//h5'),
        ],
        'page_header' => $count('//*[contains(@class,"page-header")]') > 0,
        'components' => [
            'cards' => $count('//*[contains(@class,"card")]'),
            'badges' => $count('//*[contains(@class,"badge")]'),
            'empty_states' => $count('//*[contains(@class,"empty-state")]'),
            'filter_cards' => $count('//*[contains(@class,"filter-card")]'),
            'modals' => $count('//*[contains(@class,"modal")]'),
            'sidebar_links' => $count('//*[contains(@class,"nav-link")]'),
        ],
        'controls' => [
            'links' => $count('//a[@href]'),
            'buttons' => $count('//button') + $count('//*[contains(@class,"btn")]'),
        ],
        'tables' => $tables,
        'forms' => $forms,
    ];
}

// =============================================================================
// capture
// =============================================================================

function commandCapture(array $options): int
{
    foreach (['base-url', 'email', 'password'] as $required) {
        if (($options[$required] ?? '') === '') {
            fwrite(STDERR, "Missing --{$required}\n" . usage());
            return 2;
        }
    }

    $http = new Http($options['base-url']);
    if (!$http->login($options['email'], $options['password'])) {
        return 2;
    }
    echo "Logged in.\n";

    $discovered = discoverRoutes();
    $limit = isset($options['limit']) ? (int) $options['limit'] : 0;

    $pages = [];
    $discoveredIds = [];

    // Pass 1 - parameterless routes. Harvest ?id= values from their markup as we go.
    $plain = array_values(array_filter($discovered['crawlable'], static fn (array $r): bool => !$r['needs_id']));
    $parameterised = array_values(array_filter($discovered['crawlable'], static fn (array $r): bool => $r['needs_id']));

    foreach ($plain as $index => $entry) {
        if ($limit > 0 && $index >= $limit) {
            break;
        }
        $pages[$entry['route']] = crawl($http, $entry['route'], $discoveredIds);
        progress($index + 1, count($plain), $entry['route']);
    }

    // Pass 2 - routes needing ?id=, using an id harvested from a list page.
    echo "\n";
    foreach ($parameterised as $index => $entry) {
        $module = dirname($entry['route']);

        // Deterministic sampling: always the lowest id, never list-page order.
        // If the before/after runs sampled different records, two legitimately
        // different states (a draft vs a shipped order) would diff as a false
        // BREAKING form change. Lowest-id is stable across runs.
        $candidates = $discoveredIds[$module] ?? [];
        sort($candidates);
        $id = $candidates[0] ?? null;

        if ($id === null) {
            $pages[$entry['route']] = [
                'skipped' => 'no id could be discovered from any list page in this module',
            ];
            continue;
        }

        $route = $entry['route'] . '?id=' . $id;
        $pages[$entry['route']] = crawl($http, $route, $discoveredIds) + ['sampled_id' => $id];
        progress($index + 1, count($parameterised), $route);
    }

    $snapshot = [
        'meta' => [
            'captured_at' => date('c'),
            'base_url' => $options['base-url'],
            'tool_version' => 1,
            'routes_crawled' => count($pages),
            'routes_excluded' => count($discovered['excluded']),
        ],
        'excluded' => $discovered['excluded'],
        'pages' => $pages,
    ];

    $out = $options['out'] ?? (__DIR__ . '/snapshots/snapshot-' . date('Ymd-His') . '.json');
    if (!is_dir(dirname($out))) {
        mkdir(dirname($out), 0775, true);
    }
    file_put_contents($out, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo "\n\nSnapshot written to {$out}\n";
    summarise($pages);

    return 0;
}

/** @param array<string, array<int, int>> $discoveredIds */
function crawl(Http $http, string $route, array &$discoveredIds): array
{
    $response = $http->get('/' . $route);

    if ($response['error'] !== '') {
        return ['status' => 0, 'transport_error' => $response['error']];
    }

    // Harvest ?id= values so detail pages can be sampled in pass 2.
    if (preg_match_all('#href="/?(modules/[a-z0-9\-/]+)/[a-z_]+\.php\?id=(\d+)#i', $response['body'], $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $module = $match[1];
            $id = (int) $match[2];
            if (!isset($discoveredIds[$module])) {
                $discoveredIds[$module] = [];
            }
            if (!in_array($id, $discoveredIds[$module], true)) {
                $discoveredIds[$module][] = $id;
            }
        }
    }

    return ['status' => $response['status'], 'bytes' => strlen($response['body'])]
        + fingerprint($response['body']);
}

function progress(int $done, int $total, string $route): void
{
    printf("\r  [%3d/%3d] %-60s", $done, $total, substr($route, 0, 60));
}

function summarise(array $pages): void
{
    $ok = $errors = $skipped = $withPhpErrors = 0;
    foreach ($pages as $page) {
        if (isset($page['skipped'])) {
            $skipped++;
            continue;
        }
        if (($page['status'] ?? 0) >= 200 && ($page['status'] ?? 0) < 400) {
            $ok++;
        } else {
            $errors++;
        }
        if (($page['php_errors'] ?? []) !== []) {
            $withPhpErrors++;
        }
    }

    echo "\nBASELINE\n";
    printf("  HTTP success       %d\n", $ok);
    printf("  HTTP failure       %d\n", $errors);
    printf("  Skipped (no id)    %d\n", $skipped);
    printf("  PHP diagnostics    %d\n", $withPhpErrors);

    foreach ($pages as $route => $page) {
        if (isset($page['skipped'])) {
            continue;
        }
        if (($page['status'] ?? 0) < 200 || ($page['status'] ?? 0) >= 400) {
            printf("  FAIL  HTTP %-4d %s\n", $page['status'] ?? 0, $route);
        }
        if (($page['php_errors'] ?? []) !== []) {
            printf("  WARN  PHP %-9s %s\n", implode(',', $page['php_errors']), $route);
        }
    }
}

// =============================================================================
// compare
// =============================================================================

function commandCompare(string $beforePath, string $afterPath): int
{
    if ($beforePath === '' || $afterPath === '') {
        fwrite(STDERR, usage());
        return 2;
    }

    $before = loadSnapshot($beforePath);
    $after = loadSnapshot($afterPath);

    $breaking = [];
    $warnings = [];
    $info = [];

    $beforePages = $before['pages'] ?? [];
    $afterPages = $after['pages'] ?? [];

    foreach ($beforePages as $route => $old) {
        if (!array_key_exists($route, $afterPages)) {
            $breaking[] = "{$route}: route disappeared from the snapshot";
            continue;
        }
        $new = $afterPages[$route];

        if (isset($old['skipped']) || isset($new['skipped'])) {
            continue;
        }

        $oldStatus = $old['status'] ?? 0;
        $newStatus = $new['status'] ?? 0;
        if ($oldStatus !== $newStatus) {
            $line = "{$route}: HTTP {$oldStatus} -> {$newStatus}";
            if ($newStatus < 200 || $newStatus >= 400) {
                $breaking[] = $line;
            } else {
                $warnings[] = $line;
            }
        }

        $newErrors = array_diff($new['php_errors'] ?? [], $old['php_errors'] ?? []);
        if ($newErrors !== []) {
            $breaking[] = "{$route}: new PHP diagnostic (" . implode(', ', $newErrors) . ')';
        }

        compareForms($route, $old, $new, $breaking, $warnings);

        foreach (['h1', 'h2', 'h3', 'h5'] as $heading) {
            $o = $old['headings'][$heading] ?? 0;
            $n = $new['headings'][$heading] ?? 0;
            if ($o !== $n) {
                $info[] = "{$route}: <{$heading}> count {$o} -> {$n}";
            }
        }

        $oldTables = array_column($old['tables'] ?? [], 'columns');
        $newTables = array_column($new['tables'] ?? [], 'columns');
        if ($oldTables !== $newTables) {
            $info[] = "{$route}: table columns [" . implode(',', $oldTables) . '] -> [' . implode(',', $newTables) . ']';
        }

        foreach (($old['components'] ?? []) as $key => $oldValue) {
            $newValue = $new['components'][$key] ?? 0;
            if ($oldValue !== $newValue) {
                $info[] = "{$route}: {$key} {$oldValue} -> {$newValue}";
            }
        }
    }

    foreach ($afterPages as $route => $_) {
        if (!array_key_exists($route, $beforePages)) {
            $warnings[] = "{$route}: new route appeared";
        }
    }

    report('BREAKING', $breaking);
    report('WARNING', $warnings);
    report('INFO', $info);

    printf(
        "\n%d breaking, %d warnings, %d informational\n",
        count($breaking),
        count($warnings),
        count($info)
    );

    if ($breaking !== []) {
        echo "\nRESULT: FAIL - structural regressions detected.\n";
        return 1;
    }

    echo "\nRESULT: PASS - no structural regressions.\n";
    return 0;
}

/**
 * Form comparison is the point of this tool. A V3 phase may restyle anything,
 * but it must never change where a form posts to, or silently drop a field.
 */
function compareForms(string $route, array $old, array $new, array &$breaking, array &$warnings): void
{
    $oldForms = $old['forms'] ?? [];
    $newForms = $new['forms'] ?? [];

    if (count($oldForms) !== count($newForms)) {
        $delta = count($oldForms) . ' -> ' . count($newForms);
        if (count($newForms) < count($oldForms)) {
            $breaking[] = "{$route}: form count dropped ({$delta})";
        } else {
            $warnings[] = "{$route}: form count grew ({$delta})";
        }
    }

    $key = static fn (array $f): string => $f['method'] . ' ' . $f['action'];

    $oldByKey = [];
    foreach ($oldForms as $form) {
        $oldByKey[$key($form)][] = $form;
    }
    $newByKey = [];
    foreach ($newForms as $form) {
        $newByKey[$key($form)][] = $form;
    }

    foreach ($oldByKey as $formKey => $forms) {
        if (!isset($newByKey[$formKey])) {
            $breaking[] = "{$route}: form [{$formKey}] no longer present";
            continue;
        }

        $oldFields = [];
        foreach ($forms as $form) {
            $oldFields = array_merge($oldFields, $form['inputs'], $form['buttons']);
        }
        $newFields = [];
        foreach ($newByKey[$formKey] as $form) {
            $newFields = array_merge($newFields, $form['inputs'], $form['buttons']);
        }

        $lost = array_unique(array_diff($oldFields, $newFields));
        if ($lost !== []) {
            $breaking[] = "{$route}: form [{$formKey}] lost field(s): " . implode(', ', $lost);
        }

        $gained = array_unique(array_diff($newFields, $oldFields));
        if ($gained !== []) {
            $warnings[] = "{$route}: form [{$formKey}] gained field(s): " . implode(', ', $gained);
        }
    }

    foreach ($newByKey as $formKey => $_) {
        if (!isset($oldByKey[$formKey])) {
            $warnings[] = "{$route}: new form [{$formKey}]";
        }
    }
}

function loadSnapshot(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "Snapshot not found: {$path}\n");
        exit(2);
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Snapshot is not valid JSON: {$path}\n");
        exit(2);
    }
    return $data;
}

function report(string $label, array $lines): void
{
    if ($lines === []) {
        return;
    }
    echo "\n{$label} (" . count($lines) . ")\n";
    foreach ($lines as $line) {
        echo "  {$line}\n";
    }
}
