<?php

/**
 * Runs every behavioural suite in this directory and reports one combined result.
 *
 * This is the functional baseline for the V2 branch: the suites assert on real ledger
 * outcomes (inventory_transactions, mewmii_inventory, order status, customer_storage) and on
 * rendered page output - not on markup structure or CSS. That is deliberate, so a UI/UX
 * redesign can be checked against them: if business behaviour is unchanged, these stay green
 * no matter how the pages are restyled.
 *
 * Usage:
 *   php tests/run_all.php
 *
 * Requires a running MySQL and a throwaway database (see tests/README.md). Each suite seeds
 * its own fixture, so they must run sequentially - never in parallel against one database.
 */

require_once __DIR__ . '/_guard.php';

$suites = [
    'rr_test.php' => 'Reverse Receiving',
    'ui_test.php' => 'Reverse Receipt modal wiring',
    'p1_test.php' => 'P1 Reservation Center Auto Reserve',
    'p2_test.php' => 'P2 Shared last-used carrier',
    'p3_test.php' => 'P3 Ship My Box quantity defaults',
    'p4_test.php' => 'P4 Customer Storage remove defaults',
    'p6_test.php' => 'P6 Customer dropdown standardisation',
    'p7_test.php' => 'P7 Supplier Orders Receive shortcut',
    'u1_test.php' => 'U1 Context-aware return navigation',
];

$php = PHP_BINARY;
$totalPass = 0;
$totalFail = 0;
$broken = [];

echo PHP_EOL . 'Mewmii OS - behavioural baseline' . PHP_EOL;
echo 'Database: ' . getenv('DB_DATABASE') . PHP_EOL . PHP_EOL;

foreach ($suites as $file => $label) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $file;

    if (!is_file($path)) {
        printf("  %-38s %s\n", $label, 'MISSING');
        $broken[] = $file;
        continue;
    }

    $output = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($path) . ' 2>&1');

    if (preg_match('/(\d+) passed, (\d+) failed/', $output, $m) !== 1) {
        printf("  %-38s %s\n", $label, 'NO RESULT LINE (suite crashed?)');
        $broken[] = $file;
        continue;
    }

    $pass = (int) $m[1];
    $fail = (int) $m[2];
    $totalPass += $pass;
    $totalFail += $fail;

    printf("  %-38s %3d passed, %d failed%s\n", $label, $pass, $fail, $fail > 0 ? '   <-- FAILURES' : '');

    if ($fail > 0) {
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, '[FAIL]')) {
                echo '        ' . trim($line) . PHP_EOL;
            }
        }
    }
}

echo PHP_EOL . str_repeat('-', 62) . PHP_EOL;
printf("  TOTAL: %d passed, %d failed%s\n", $totalPass, $totalFail, $broken !== [] ? (', ' . count($broken) . ' suite(s) did not report') : '');
echo str_repeat('-', 62) . PHP_EOL . PHP_EOL;

exit(($totalFail === 0 && $broken === []) ? 0 : 1);
