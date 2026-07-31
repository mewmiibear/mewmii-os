<?php

/**
 * Shared helpers for database/migrate_*.php scripts - previously duplicated verbatim across up
 * to 20 of the 21 migration files (see docs/MIGRATION_SYSTEM_AUDIT.md for the full inventory).
 * Every migration now requires this file instead of locally re-declaring these functions - that
 * de-duplication is what makes it safe for database/migrate.php to run multiple migrations in
 * one PHP process (previously impossible: `require`-ing two files that both declared
 * `function migrate_run()` fatal-errored with "Cannot redeclare function").
 *
 * Verified byte-for-byte identical (or, for migrate_index_exists(), identical apart from one
 * cosmetic parameter name) across every file that had its own copy before this refactor -
 * nothing here changed behavior for any migration. The one real fix is migrate_failures_reset()
 * (see its own docblock) - a plumbing correction needed only because these functions now run
 * in-process back to back, never a change to what any migration itself does.
 *
 * FUTURE ROLLBACK CONVENTION (not implemented, documented for forward compatibility only - see
 * docs/MIGRATION_MANAGEMENT_PLAN.md for the full reasoning): a future migration MAY optionally
 * define a matching `rollback_<migration_name>()` function (e.g. `migrate_foo()` paired with
 * `rollback_foo()`) in its own file. Nothing here or in database/migrate.php currently looks for
 * or calls such a function - this is purely a naming reservation so that, if rollback support is
 * ever built, existing and new migrations that follow this convention won't need restructuring.
 * Do not add a rollback_*() function speculatively; only add one when a migration genuinely
 * needs to be reversible and rollback execution has actually been implemented in the runner.
 */

function migrate_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrate_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ');
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrate_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ');
    $stmt->execute([$table, $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Accumulates failed statement labels/messages across migrate_run() calls via a static
 * variable, returned by reference - unchanged from every existing copy of this function.
 */
function &migrate_failures(): array
{
    static $failures = [];

    return $failures;
}

/**
 * Clears the shared failures registry. The static variable inside migrate_failures() persists
 * for the lifetime of the PHP process - harmless when each migration ran in its own subprocess
 * (a fresh process = a fresh static), but a real bug once multiple migrations run in-process
 * back to back in the same runner invocation: without this reset, migration B could inherit
 * migration A's stale failures. database/migrate.php calls this immediately before invoking
 * each migration's function. No migration file calls this itself, and none needs to.
 */
function migrate_failures_reset(): void
{
    $failures = &migrate_failures();
    $failures = [];
}

function migrate_run(PDO $pdo, string $label, string $sql, array &$applied): void
{
    try {
        $pdo->exec($sql);
        $applied[] = $label;
    } catch (PDOException $exception) {
        echo '  ! FAILED: ' . $label . ' - ' . $exception->getMessage() . PHP_EOL;
        $failures = &migrate_failures();
        $failures[$label] = $exception->getMessage();
    }
}
