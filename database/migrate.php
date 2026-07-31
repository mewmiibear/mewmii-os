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
 * EXECUTION MODEL (v2 - in-process, no subprocess): each pending migration's file is
 * `require_once`-d (declaring its function, e.g. migrate_additional_costs()) and then that
 * function is called directly, in this same PHP process. This replaced an earlier subprocess-
 * based design (`exec(PHP_BINARY ...)`) that could never work here - production confirmed
 * exec()/shell_exec()/system()/passthru()/popen() are ALL disabled (standard on this class of
 * shared hosting). See docs/MIGRATION_MANAGEMENT_PLAN.md for the full comparison of approaches
 * considered and why this one was chosen.
 *
 * This only works because every one of the 21 existing migrate_*.php files was mechanically
 * refactored (see docs/MIGRATION_SYSTEM_AUDIT.md's inventory) to:
 *   1. require database/migrate_helpers.php instead of locally re-declaring migrate_run() /
 *      migrate_column_exists() / etc. (up to 20 files declared identical copies of these -
 *      requiring two such files into one process used to fatal-error with "Cannot redeclare
 *      function").
 *   2. Wrap the migration's own top-level logic in a uniquely-named function (migrate_<name>(),
 *      derived directly from the filename) instead of running at require-time - so requiring
 *      a file only DECLARES its function, never executes it as a side effect.
 *   3. Guard standalone execution behind `if (!defined('MIGRATE_RUNNER_ACTIVE'))` at the
 *      bottom, so `php database/migrate_X.php` run directly (browser or CLI) still works
 *      exactly as before this refactor - MIGRATE_RUNNER_ACTIVE is defined below, before any
 *      migration file is required, specifically so that auto-run guard is skipped here.
 * No SQL statement, table/column name, or control-flow condition changed in any of the 21
 * files as part of this refactor - see each file's own docblock and
 * docs/MIGRATION_SYSTEM_AUDIT.md for confirmation.
 *
 * A migration is recorded 'success' if its function returns ['success' => true, ...] AND
 * nothing thrown; 'failed' otherwise. Every migration's function call is individually wrapped
 * in its own try/catch(Throwable), so one migration's genuine bug (an uncaught error, not just
 * a caught-and-logged failed SQL statement) can never crash the rest of the batch - matching
 * the resilience the old per-subprocess model had implicitly, now provided explicitly.
 *
 * CLI-only, same protection as every script in cli/ (see cli/job_worker.php's identical
 * PHP_SAPI check) - a direct web request 403s before bootstrap.php or the database are ever
 * touched. This closes, for this one new script, the "reachable via unauthenticated direct
 * URL" finding from docs/MIGRATION_SYSTEM_AUDIT.md §5. It does NOT fix the other 21 existing
 * migrate_*.php scripts, which remain exposed the same way they always have been - that is a
 * separate, not-yet-approved follow-up, not silently bundled into this change.
 *
 * Does NOT implement: rollback/down migrations, a dependency graph, or CI integration - see
 * docs/MIGRATION_MANAGEMENT_PLAN.md for why none of those are needed at Mewmii OS's current
 * scale (all 21 existing migrations are purely additive; only one real dependency pair exists
 * in the whole set, and it already fails safely/informatively if run out of order).
 *
 * FUTURE ROLLBACK CONVENTION (not implemented - see database/migrate_helpers.php's own
 * docblock and docs/MIGRATION_MANAGEMENT_PLAN.md for the full reasoning): a future migration
 * MAY optionally define a matching rollback_<migration_name>() function. This runner does not
 * currently look for or call any such function - reserving the name is purely so that, if
 * rollback support is ever built, no existing or new migration needs restructuring to adopt it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('MIGRATE_RUNNER_ACTIVE', true);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/migrate_helpers.php';

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
 * the 'migrate_*.php' glob pattern, so it needs no exclusion - but 'migrate_helpers.php' DOES
 * match the pattern (it also starts with 'migrate_') despite not being a real migration (it
 * declares shared helper functions, not a migrate_<name>() entry point), so it's excluded
 * explicitly here.
 */
function migrate_runner_discover(): array
{
    $files = glob(__DIR__ . '/migrate_*.php') ?: [];
    $files = array_filter($files, static fn (string $path): bool => basename($path) !== 'migrate_helpers.php');
    sort($files, SORT_STRING);

    return array_map('basename', $files);
}

/**
 * The function a migration file is expected to declare, derived directly from its filename
 * (migrate_foo.php -> migrate_foo()) - see this file's own docblock for why every one of the
 * 21 existing migrations follows this convention exactly.
 */
function migrate_runner_function_name(string $filename): string
{
    return substr($filename, 0, -4);
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
 * Runs one migration in-process: requires its file (declares migrate_<name>(), MIGRATE_RUNNER_
 * ACTIVE already stops it from auto-running), resets the shared failures registry first (see
 * migrate_failures_reset()'s own docblock for why that's necessary now that migrations run
 * back to back in one process), calls the function, and captures everything it echoes via
 * output buffering - the in-process equivalent of the old subprocess model's captured stdout.
 */
function migrate_runner_execute(PDO $pdo, string $filename): array
{
    $path = __DIR__ . '/' . $filename;
    $functionName = migrate_runner_function_name($filename);

    migrate_failures_reset();

    $start = microtime(true);
    ob_start();

    try {
        require_once $path;

        if (!function_exists($functionName)) {
            ob_end_clean();

            return [
                'success' => false,
                'output' => '',
                'error' => "Expected function {$functionName}() was not declared after requiring {$filename} - this migration doesn't follow the migrate_<name>() naming convention.",
                'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }

        $result = $functionName($pdo);
        $output = ob_get_clean();

        return [
            'success' => (bool) ($result['success'] ?? false),
            'output' => $output,
            'error' => null,
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    } catch (Throwable $exception) {
        // A genuine bug in one migration (not just a caught-and-logged failed SQL statement,
        // which the migration's own function already handles internally) must never take down
        // the rest of the batch - caught here, recorded, and the runner's own loop continues.
        $output = ob_get_clean();

        return [
            'success' => false,
            'output' => $output,
            'error' => get_class($exception) . ': ' . $exception->getMessage(),
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }
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

migrate_runner_log('Running ' . count($pending) . ' pending migration(s)...');
migrate_runner_log('');

$successCount = 0;
$failedCount = 0;
$appliedNames = [];

foreach ($pending as $filename => $checksum) {
    migrate_runner_log('-> ' . $filename);

    $result = migrate_runner_execute($pdo, $filename);
    $diagnostic = trim($result['output'] . ($result['error'] !== null ? PHP_EOL . '! ' . $result['error'] : ''));

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
            migrate_runner_record($pdo, $filename, 'failed', $checksum, $result['elapsed_ms'], $diagnostic);
        } catch (Throwable $exception) {
            migrate_runner_log('   WARNING: could not record this failure to schema_migrations: ' . $exception->getMessage());
        }

        migrate_runner_log('   FAILED (' . $result['elapsed_ms'] . 'ms)');
        migrate_runner_log('   Migration: ' . $filename);
        if ($result['error'] !== null) {
            migrate_runner_log('   Error:     ' . $result['error']);
        }
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
    try {
        activity_log($pdo, 'schema_migrations', 'run', null, $successCount . ' migration(s) applied via database/migrate.php: ' . implode(', ', $appliedNames));
    } catch (Throwable $exception) {
        // Never let activity logging block the runner from exiting cleanly.
    }
}

exit($failedCount > 0 ? 1 : 0);
