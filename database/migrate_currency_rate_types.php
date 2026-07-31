<?php
/**
 * Phase 9F.2 (Multi-Purpose Currency Rate Settings) - a single rate per currency code turned
 * out to be wrong: Supplier, Original, and Market Price conversion each need their OWN rate
 * even for the same currency (e.g. JPY's supplier rate, original rate, and market rate are
 * three independent numbers, not one). This adds currency_rates.rate_type and widens the
 * uniqueness constraint from (currency_code) alone to (rate_type, currency_code), so the same
 * code can have up to three rows - one per rate_type.
 *
 * Every pre-existing row (seeded by database/migrate_currency_rates.php as a single generic
 * rate) is backfilled to rate_type = 'supplier' - the one category that's actually load-bearing
 * today via currency_rates_sync_product_exchange_rate() feeding includes/product_cost.php's
 * actual Landed Cost engine (still not modified). 'original' and 'market' rates start empty
 * for the admin to fill in via modules/settings/currency_rates.php - never guessed here.
 *
 * Safe to run multiple times: every step checks INFORMATION_SCHEMA before altering anything.
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_currency_rate_types.php) or CLI
 * (`php database/migrate_currency_rate_types.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates this shape via
 * install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

/**
 * Finds the name of whatever single-column UNIQUE index currently covers currency_code alone
 * (MySQL auto-names an inline `col UNIQUE` constraint after the column, but this looks it up
 * rather than assuming that name, in case it was ever created differently). Returns null if
 * currency_code is no longer covered by a lone unique index (e.g. this migration already ran).
 * Unique to this migration - not shared with any other file.
 */
function migrate_find_single_column_unique_index(PDO $pdo, string $table, string $column): ?string
{
    $stmt = $pdo->prepare("
        SELECT s.INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS s
        WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = ? AND s.NON_UNIQUE = 0
          AND s.INDEX_NAME != 'PRIMARY'
          AND (
              SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS s2
              WHERE s2.TABLE_SCHEMA = DATABASE() AND s2.TABLE_NAME = s.TABLE_NAME AND s2.INDEX_NAME = s.INDEX_NAME
          ) = 1
          AND s.COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    $name = $stmt->fetchColumn();

    return $name !== false ? (string) $name : null;
}

function migrate_currency_rate_types(PDO $pdo): array
{
    echo 'Mewmii OS currency rate types migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_column_exists($pdo, 'currency_rates', 'rate_type')) {
        migrate_run($pdo, 'currency_rates.rate_type', "ALTER TABLE currency_rates ADD COLUMN rate_type VARCHAR(20) NOT NULL DEFAULT 'supplier' AFTER id", $applied);
    }

    // Widen uniqueness from (currency_code) to (rate_type, currency_code) - only if the old
    // lone-column unique index is still there (never re-run once already widened).
    $oldUniqueIndex = migrate_find_single_column_unique_index($pdo, 'currency_rates', 'currency_code');
    if ($oldUniqueIndex !== null) {
        migrate_run($pdo, 'currency_rates.drop_old_unique', "ALTER TABLE currency_rates DROP INDEX `{$oldUniqueIndex}`", $applied);
    }
    if (!migrate_index_exists($pdo, 'currency_rates', 'uq_currency_rates_type_code')) {
        migrate_run($pdo, 'currency_rates.add_composite_unique', 'ALTER TABLE currency_rates ADD UNIQUE KEY uq_currency_rates_type_code (rate_type, currency_code)', $applied);
    }

    echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
    foreach ($applied as $item) {
        echo '  - ' . $item . PHP_EOL;
    }

    if ($applied === []) {
        echo 'Database was already up to date - nothing to do.' . PHP_EOL;
    }

    $failures = migrate_failures();
    if ($failures !== []) {
        echo PHP_EOL . count($failures) . ' migration statement(s) FAILED:' . PHP_EOL;
        foreach ($failures as $label => $message) {
            echo '  ! ' . $label . ' - ' . $message . PHP_EOL;
        }
    }

    echo 'Done. Every pre-existing rate row is now rate_type = supplier (the default). No existing product_cost.php actual Landed Cost, cost history, supplier order costing, or selling_price logic was touched.' . PHP_EOL;

    if ($failures !== []) {
        $resultMessage = count($failures) . ' statement(s) failed';
    } elseif ($applied === []) {
        $resultMessage = 'Already up to date';
    } else {
        $resultMessage = count($applied) . ' statement(s) applied';
    }

    return [
        'success' => $failures === [],
        'applied' => $applied,
        'failures' => $failures,
        'message' => $resultMessage,
    ];
}

if (!defined('MIGRATE_RUNNER_ACTIVE')) {
    migrate_currency_rate_types(app_db());
}
