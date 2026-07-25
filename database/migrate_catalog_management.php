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
 * Run once via browser (https://yourdomain/database/migrate_catalog_management.php) or CLI
 * (`php database/migrate_catalog_management.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates the full final
 * table shape via install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = app_db();

function migrate_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrate_run(PDO $pdo, string $label, string $sql, array &$applied): void
{
    try {
        $pdo->exec($sql);
        $applied[] = $label;
    } catch (PDOException $exception) {
        echo '  ! FAILED: ' . $label . ' - ' . $exception->getMessage() . PHP_EOL;
    }
}

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
