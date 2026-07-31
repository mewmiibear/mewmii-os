<?php
/**
 * One-time upgrade script for the Catalog Management consolidation (single admin section for
 * Attributes/Collections/Categories/Brands/Tags - see modules/attributes,
 * modules/categories, modules/brands, modules/collections, modules/tags). Adds
 * description/image fields to brands/categories/collections so they can carry the same
 * metadata attributes already do. Safe to run multiple times: every step checks
 * INFORMATION_SCHEMA before altering anything, and no existing row is ever updated or
 * deleted. Mirrors database/migrate_catalog.php's exact structure/conventions.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_catalog_management.php) or CLI
 * (`php database/migrate_catalog_management.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates the full final
 * table shape via install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_catalog_management(PDO $pdo): array
{
    echo 'Mewmii OS Catalog Management migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_column_exists($pdo, 'brands', 'description')) {
        migrate_run($pdo, 'brands.description', 'ALTER TABLE brands ADD COLUMN description TEXT NULL AFTER slug', $applied);
    }
    if (!migrate_column_exists($pdo, 'brands', 'logo_path')) {
        migrate_run($pdo, 'brands.logo_path', 'ALTER TABLE brands ADD COLUMN logo_path VARCHAR(500) NULL AFTER description', $applied);
    }

    if (!migrate_column_exists($pdo, 'categories', 'description')) {
        migrate_run($pdo, 'categories.description', 'ALTER TABLE categories ADD COLUMN description TEXT NULL AFTER parent_id', $applied);
    }
    if (!migrate_column_exists($pdo, 'categories', 'image_path')) {
        migrate_run($pdo, 'categories.image_path', 'ALTER TABLE categories ADD COLUMN image_path VARCHAR(500) NULL AFTER description', $applied);
    }

    if (!migrate_column_exists($pdo, 'collections', 'description')) {
        migrate_run($pdo, 'collections.description', 'ALTER TABLE collections ADD COLUMN description TEXT NULL AFTER end_date', $applied);
    }
    if (!migrate_column_exists($pdo, 'collections', 'image_path')) {
        migrate_run($pdo, 'collections.image_path', 'ALTER TABLE collections ADD COLUMN image_path VARCHAR(500) NULL AFTER description', $applied);
    }

    echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
    foreach ($applied as $item) {
        echo '  - ' . $item . PHP_EOL;
    }

    if ($applied === []) {
        echo 'Database was already up to date - nothing to do.' . PHP_EOL;
    }

    // This file historically didn't call migrate_failures() at all (its own migrate_run()
    // variant didn't record into the shared registry) - now that migrate_run() is shared and
    // always records, we read it here too so the returned result is accurate. See
    // database/migrate_helpers.php's own docblock for why this is safe: it only adds
    // visibility this file never had before, never changes what it does.
    $failures = migrate_failures();

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
    migrate_catalog_management(app_db());
}
