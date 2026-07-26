<?php
/**
 * Phase 8C (Product Cost History) - adds the product_cost_history table backing frozen Landed
 * Cost snapshots captured when a supplier order is received or completed - see
 * database/schema.sql for the full rationale. Safe to run multiple times: CREATE TABLE IF NOT
 * EXISTS is a no-op if it already exists.
 *
 * Run once via browser (https://yourdomain/database/migrate_product_cost_history.php) or CLI
 * (`php database/migrate_product_cost_history.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates this table via
 * install.php.
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

function &migrate_failures(): array
{
    static $failures = [];

    return $failures;
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

echo 'Mewmii OS product cost history migration starting...' . PHP_EOL;

$applied = [];

if (!migrate_column_exists($pdo, 'product_cost_history', 'id')) {
    migrate_run($pdo, 'product_cost_history.create', "
        CREATE TABLE IF NOT EXISTS product_cost_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            variation_id INT UNSIGNED NULL,
            supplier_id INT UNSIGNED NULL,
            supplier_order_id INT UNSIGNED NOT NULL,
            supplier_order_item_id INT UNSIGNED NOT NULL,
            supplier_cost DECIMAL(12,2) NOT NULL,
            cost_currency VARCHAR(10) NULL,
            exchange_rate DECIMAL(10,4) NULL,
            converted_cost DECIMAL(12,2) NOT NULL,
            shipping_cost DECIMAL(12,2) NULL,
            other_costs DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            landed_cost DECIMAL(12,2) NOT NULL,
            captured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_product_cost_history_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT fk_product_cost_history_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            CONSTRAINT fk_product_cost_history_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_product_cost_history_item FOREIGN KEY (supplier_order_item_id) REFERENCES supplier_order_items(id) ON DELETE CASCADE,
            INDEX idx_product_cost_history_product (product_id, captured_at),
            INDEX idx_product_cost_history_supplier (supplier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", $applied);
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

echo 'Done. No existing cost calculation, receiving, or supplier order logic was touched.' . PHP_EOL;
