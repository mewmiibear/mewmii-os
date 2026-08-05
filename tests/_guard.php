<?php

/**
 * Safety guard for every suite in this directory.
 *
 * These are BEHAVIOURAL suites, not unit tests: they TRUNCATE tables and write real rows so
 * they can assert on the actual inventory ledger rather than on mocks. That makes them
 * destructive by design, and pointing one at a real database would wipe it.
 *
 * So nothing in tests/ is allowed to run unless the configured database name is on the
 * throwaway allow-list below. Each suite sets DB_DATABASE via putenv() before requiring this
 * file; the guard re-reads the value that config.php will actually use, so it cannot be
 * bypassed by a suite forgetting its own putenv().
 *
 * To use a different throwaway database, add its name here - deliberately an explicit list
 * rather than a pattern, so a typo fails closed instead of matching something real.
 */

const TEST_ALLOWED_DATABASES = [
    'mewmii_rrtest',
    'mewmii_test',
];

$configuredDatabase = (string) (getenv('DB_DATABASE') ?: '');

if (!in_array($configuredDatabase, TEST_ALLOWED_DATABASES, true)) {
    fwrite(STDERR, PHP_EOL
        . "REFUSING TO RUN" . PHP_EOL
        . "  Target database: " . ($configuredDatabase === '' ? '(not set)' : $configuredDatabase) . PHP_EOL
        . "  Allowed:         " . implode(', ', TEST_ALLOWED_DATABASES) . PHP_EOL
        . PHP_EOL
        . "  These suites truncate tables. They only run against a throwaway database." . PHP_EOL
        . "  See tests/README.md." . PHP_EOL . PHP_EOL);
    exit(1);
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'Test suites are CLI-only.' . PHP_EOL);
    exit(1);
}
