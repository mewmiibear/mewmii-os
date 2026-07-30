#!/usr/bin/env php
<?php

/**
 * Migration Management System v1 - the runner (see docs/MIGRATION_MANAGEMENT_PLAN.md for the
 * approved design, docs/MIGRATION_SYSTEM_AUDIT.md for the incident this closes).
 *
 * Discovers every database/migrate_*.php file from disk - never a hand-maintained list. That's
 * the direct fix for the actual root cause found in the audit: includes/system_health.php's
 * migration-detection array is hand-maintained and was missing 4 of 21 scripts, including the
 * one behind a real production outage. There is nothing to remember to update here - drop a
 * new migrate_*.php file in this directory and this runner sees it on its next check.
 *
 * Usage:
 *   php database/migrate.php            Preview only (default) - shows completed/pending/
 *                                        modified-since-applied migrations. Runs nothing.
 *   php database/migrate.php --run       Actually executes every pending migration, in
 *                                        filename order, and records the result of each.
 *
 * Execution model: each pending migration is run as its own separate `php` subprocess (via
 * PHP_BINARY), NOT `require`-d into this process. This is not a stylistic choice - it's
 * required. 20 of the 21 existing migrate_*.php scripts independently define an identical
 * migrate_run() helper function (and 18 define migrate_column_exists(), etc.) at global scope;
 * `require`-ing two of them into the same PHP process would fatal-error with "Cannot redeclare
 * function migrate_run()" on the second one. Running each as its own process sidesteps this
 * completely and requires zero changes to any of the 21 existing scripts.
 *
 * A migration is recorded 'success' if its subprocess exits 0, 'failed' otherwise (PHP CLI
 * exits non-zero on an uncaught error/exception) - the full captured output is stored either
 * way (in error_message on failure) so a human can always see exactly what a script printed,
 * matching how these scripts have always been read when run manually.
 *
 * CLI-only, same protection as every script in cli/ (see cli/job_worker.php's identical
 * PHP_SAPI check) - a direct web request 403s before bootstrap.php or the database are ever
 * touched. This closes, for this one new script, the "reachable via unauthenticated direct
 * URL" finding from docs/MIGRATION_SYSTEM_AUDIT.md §5. It does NOT fix the other 21 existing
 * migrate_*.php scripts, which remain exposed the same way they always have been - that is a
 * separate, not-yet-approved follow-up, not silently bundled into this change.
 *
 * Does NOT implement: rollback/down migrations, a dependency graph, or CI integration - see
 * docs/MIGRATION_MANAGEMENT_PLAN.md §6 for why none of those are needed at Mewmii OS's current
 * scale (all 21 existing migrations are purely additive; only one real dependency pair exists
 * in the whole set, and it already fails safely/informatively if run out of order).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/activity_log.php';

function migrate_runner_log(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function migrate_runner_ensure_tracking_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          migration VARCHAR(191) NOT NULL UNIQUE,
          status ENUM('success', 'failed') NOT NULL DEFAULT 'success',
          checksum CHAR(64) NULL,
          executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          execution_time_ms INT UNSIGNED NULL,
          executed_by VARCHAR(100) NULL,
          error_message TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Every database/migrate_*.php file on disk, sorted by filename - discovery, never a
 * maintained list. This runner's own filename ('migrate.php', no underscore) never matches
 * the 'migrate_*.php' glob pattern, so no explicit self-exclusion is needed.
 */
function migrate_runner_discover(): array
{
    $files = glob(__DIR__ . '/migrate_*.php') ?: [];
    sort($files, SORT_STRING);

    return array_map('basename', $files);
}

/** Every row already recorded, keyed by filename - one query, not one per discovered file. */
function migrate_runner_recorded(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT migration, status, checksum, executed_at FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[$row['migration']] = $row;
    }

    return $rows;
}

/**
 * Insert-or-update by migration name (UNIQUE) - a retried migration updates its existing row
 * (failed -> success) rather than accumulating duplicate rows for the same script.
 */
function migrate_runner_record(PDO $pdo, string $migration, string $status, string $checksum, int $executionTimeMs, ?string $errorMessage): void
{
    $pdo->prepare('
        INSERT INTO schema_migrations (migration, status, checksum, execution_time_ms, executed_by, error_message)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            checksum = VALUES(checksum),
            executed_at = CURRENT_TIMESTAMP,
            execution_time_ms = VALUES(execution_time_ms),
            executed_by = VALUES(executed_by),
            error_message = VALUES(error_message)
    ')->execute([$migration, $status, $checksum, $executionTimeMs, 'cli', $errorMessage]);
}

/**
 * Whether exec() is actually usable, or null if so. Some shared hosts (Hostinger included, on
 * some plans) disable exec()/shell_exec()/system()/passthru()/proc_open() via disable_functions
 * in php.ini - when that happens, calling exec() does NOT throw; it silently no-ops and never
 * populates its by-reference output parameters. That previously caused an uncaught TypeError
 * (implode() given an unset variable) that crashed this whole runner on the first pending
 * migration, before a single schema_migrations row could be written - a real production
 * incident this check exists to prevent from recurring. Checked once, up front, before
 * attempting anything - not per-migration - so a hosting restriction is reported once, clearly,
 * instead of failing silently N times.
 */
function migrate_runner_check_exec_available(): ?string
{
    if (!function_exists('exec')) {
        return 'exec() does not exist in this PHP build.';
    }

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        return 'exec() is disabled via disable_functions in php.ini on this server.';
    }

    return null;
}

/**
 * Best-effort guess at why a subprocess failed, shown alongside the raw captured output - not
 * a substitute for reading the output, just a head start for the most common cases.
 */
function migrate_runner_guess_cause(?int $exitCode, string $output): string
{
    if ($exitCode === null) {
        return 'No exit code was captured at all - exec() likely did not actually run (see the disable_functions check this runner performs before starting; if that passed, PHP_BINARY may not resolve to a valid executable on this server).';
    }
    if ($output === '' && $exitCode !== 0) {
        return 'The subprocess produced no output at all despite a non-zero exit code - check that PHP_BINARY (' . PHP_BINARY . ') resolves correctly and the migration file is readable.';
    }
    if (stripos($output, 'Fatal error') !== false || stripos($output, 'Uncaught') !== false) {
        return 'PHP fatal error inside the migration script itself - see the captured output above for the exact error and line.';
    }
    if ($exitCode === 255) {
        return 'Exit code 255 typically means an uncaught PHP error/exception in the migration script.';
    }

    return 'See the captured output above for detail.';
}

/** Runs one migration file as its own subprocess - see this file's own docblock for why. */
function migrate_runner_execute(string $path): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1';
    // Explicitly initialized before the call, not left to exec() to populate - exec() normally
    // does populate these by reference, but if it's disabled it silently no-ops and never
    // touches them at all. Initializing here means a disabled exec() degrades into a normal,
    // recorded 'failed' result (caught by the exec-availability check below, in practice) rather
    // than an uncaught TypeError from implode()/=== on an unset variable further down.
    $outputLines = [];
    $exitCode = null;
    $start = microtime(true);
    exec($command, $outputLines, $exitCode);
    $elapsedMs = (int) round((microtime(true) - $start) * 1000);

    return [
        'success' => $exitCode === 0,
        'exit_code' => $exitCode,
        'output' => implode(PHP_EOL, $outputLines),
        'elapsed_ms' => $elapsedMs,
    ];
}

$pdo = app_db();
migrate_runner_ensure_tracking_table($pdo);

$discovered = migrate_runner_discover();
$recorded = migrate_runner_recorded($pdo);

$pending = [];
$modified = [];
$completed = [];

foreach ($discovered as $filename) {
    $checksum = hash('sha256', (string) file_get_contents(__DIR__ . '/' . $filename));
    $existing = $recorded[$filename] ?? null;

    if ($existing === null || $existing['status'] !== 'success') {
        $pending[$filename] = $checksum;
        continue;
    }

    if ($existing['checksum'] !== null && $existing['checksum'] !== $checksum) {
        $modified[] = $filename;
        continue;
    }

    $completed[] = $filename;
}

$runMode = in_array('--run', $argv, true);

migrate_runner_log('Mewmii OS Migration Runner');
migrate_runner_log('===========================');
migrate_runner_log('');

migrate_runner_log('Completed (' . count($completed) . '):');
if ($completed === []) {
    migrate_runner_log('  (none)');
} else {
    foreach ($completed as $filename) {
        migrate_runner_log('  [OK] ' . $filename . ' (applied ' . $recorded[$filename]['executed_at'] . ')');
    }
}
migrate_runner_log('');

if ($modified !== []) {
    migrate_runner_log('MODIFIED SINCE APPLIED (' . count($modified) . ') - will NOT be auto-re-run, review manually:');
    foreach ($modified as $filename) {
        migrate_runner_log('  [!] ' . $filename . ' (checksum no longer matches the applied record)');
    }
    migrate_runner_log('');
}

migrate_runner_log('Pending (' . count($pending) . '):');
if ($pending === []) {
    migrate_runner_log('  (none - database is up to date)');
} else {
    foreach (array_keys($pending) as $filename) {
        $previouslyFailed = isset($recorded[$filename]) && $recorded[$filename]['status'] === 'failed';
        migrate_runner_log('  [ ] ' . $filename . ($previouslyFailed ? ' (previously failed - will retry)' : ''));
    }
}
migrate_runner_log('');

if ($pending === []) {
    migrate_runner_log('Nothing to do.');
    exit(0);
}

if (!$runMode) {
    migrate_runner_log('Preview only - no migration was executed. Re-run with --run to apply the ' . count($pending) . ' pending migration(s) above.');
    exit(0);
}

// Environment validation - checked once, before attempting anything. See
// migrate_runner_check_exec_available()'s own docblock for the incident this prevents.
$execUnavailableReason = migrate_runner_check_exec_available();
if ($execUnavailableReason !== null) {
    migrate_runner_log('ERROR: Cannot run migrations - ' . $execUnavailableReason);
    migrate_runner_log('');
    migrate_runner_log('This migration runner requires subprocess execution (exec()) to run each of the 21');
    migrate_runner_log('existing migration scripts safely in isolation - 20 of them independently define an');
    migrate_runner_log('identical migrate_run() function at global scope, so require()-ing more than one into');
    migrate_runner_log('this same process would fatal-error with "Cannot redeclare function". See this file\'s');
    migrate_runner_log('own docblock and docs/MIGRATION_MANAGEMENT_PLAN.md section 2a for the full reasoning.');
    migrate_runner_log('');
    migrate_runner_log('Ask your hosting provider whether exec() can be enabled for CLI-invoked scripts');
    migrate_runner_log('specifically - some hosts (Hostinger included, on some plans) allow it from SSH/CLI');
    migrate_runner_log('while blocking it for web-triggered PHP. Run `php -i | grep disable_functions` to see');
    migrate_runner_log('the exact current setting.');
    migrate_runner_log('');
    migrate_runner_log('No migration was executed and no schema_migrations row was written.');
    exit(1);
}

migrate_runner_log('Running ' . count($pending) . ' pending migration(s)...');
migrate_runner_log('');

$successCount = 0;
$failedCount = 0;
$appliedNames = [];

foreach ($pending as $filename => $checksum) {
    migrate_runner_log('-> ' . $filename);

    $result = migrate_runner_execute(__DIR__ . '/' . $filename);

    if ($result['success']) {
        try {
            migrate_runner_record($pdo, $filename, 'success', $checksum, $result['elapsed_ms'], null);
        } catch (Throwable $exception) {
            migrate_runner_log('   WARNING: migration succeeded but recording it to schema_migrations failed: ' . $exception->getMessage());
        }
        migrate_runner_log('   OK (' . $result['elapsed_ms'] . 'ms)');
        $successCount++;
        $appliedNames[] = $filename;
    } else {
        // Record a failed result when possible - a DB hiccup while recording must never mask
        // the real failure or crash the batch; the failure is still reported either way.
        try {
            migrate_runner_record($pdo, $filename, 'failed', $checksum, $result['elapsed_ms'], $result['output']);
        } catch (Throwable $exception) {
            migrate_runner_log('   WARNING: could not record this failure to schema_migrations: ' . $exception->getMessage());
        }

        migrate_runner_log('   FAILED (' . $result['elapsed_ms'] . 'ms)');
        migrate_runner_log('   Migration:     ' . $filename);
        migrate_runner_log('   Exit code:     ' . ($result['exit_code'] === null ? '(none captured)' : $result['exit_code']));
        migrate_runner_log('   Possible cause: ' . migrate_runner_guess_cause($result['exit_code'], $result['output']));
        migrate_runner_log('   Output:');
        if ($result['output'] === '') {
            migrate_runner_log('     (no output captured)');
        } else {
            foreach (explode(PHP_EOL, $result['output']) as $line) {
                migrate_runner_log('     ' . $line);
            }
        }
        $failedCount++;
    }
}

migrate_runner_log('');
migrate_runner_log('Run finished - ' . $successCount . ' succeeded, ' . $failedCount . ' failed.');

if ($failedCount > 0) {
    migrate_runner_log('Fix the error(s) above, then re-run `php database/migrate.php --run` - already-successful migrations will be skipped automatically.');
}

if ($appliedNames !== []) {
    activity_log($pdo, 'schema_migrations', 'run', null, $successCount . ' migration(s) applied via database/migrate.php: ' . implode(', ', $appliedNames));
}

exit($failedCount > 0 ? 1 : 0);
