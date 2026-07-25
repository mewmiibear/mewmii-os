<?php
/**
 * Production Hardening Phase 2 - High-priority audit findings. Safe to run multiple
 * times: every step checks INFORMATION_SCHEMA before altering anything, and the customer
 * merge (below) only ever reassigns/consolidates existing rows - nothing is deleted unless
 * every one of its child records was first successfully re-pointed at the customer it's
 * being merged into.
 *
 * Run once via browser (https://yourdomain/database/migrate_production_hardening.php) or
 * CLI (`php database/migrate_production_hardening.php`) against an EXISTING Mewmii OS
 * database. Brand-new installs don't need this - database/schema.sql already creates the
 * final table shape (including these indexes) via install.php.
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
 * Every migration statement that has failed so far this run, label => error message -
 * shared (by reference) between migrate_run() and the customer-merge step below, and
 * printed once at the very end.
 */
function &migrate_failures(): array
{
    static $failures = [];

    return $failures;
}

/**
 * Runs one migration statement in isolation: a failure here must never abort the rest of
 * the script - every block below is independently guarded by its own existence check, so
 * later, unrelated steps still get their chance even if one earlier statement fails.
 */
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

/**
 * Merges every group of `customers` rows that share the same non-null
 * woocommerce_customer_id - a real possibility today since the Quick Add endpoint
 * (modules/customers/ajax/create_customer.php) previously did no duplicate checking at
 * all, and the WooCommerce order importer's email match was case-sensitive. This MUST run
 * before the UNIQUE KEY on woocommerce_customer_id is added below, or that ALTER TABLE
 * would simply fail against any dirty data.
 *
 * Canonical = the lowest id in each duplicate group (the oldest record). Every other row
 * in the group has its child records (orders, storage, shipments, points, memberships,
 * addresses, invoices, birthday rewards, store-credit logs) re-pointed at the canonical
 * customer, and store_credit itself (unique per customer) is combined by balance rather
 * than re-pointed if the canonical customer already has a store-credit row of its own.
 * A duplicate customer row is only ever deleted once EVERY one of its child records was
 * confirmed reassigned - if any reassignment step fails, that duplicate is left in place
 * (with a warning) rather than risk an ON DELETE CASCADE silently destroying data that
 * didn't get moved first.
 */
function migrate_merge_duplicate_customers(PDO $pdo, array &$applied): void
{
    $groupsStmt = $pdo->query("
        SELECT woocommerce_customer_id, GROUP_CONCAT(id ORDER BY id ASC) AS ids
        FROM customers
        WHERE woocommerce_customer_id IS NOT NULL
        GROUP BY woocommerce_customer_id
        HAVING COUNT(*) > 1
    ");
    $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($groups === []) {
        echo 'No duplicate woocommerce_customer_id groups found - nothing to merge.' . PHP_EOL;

        return;
    }

    echo count($groups) . ' duplicate woocommerce_customer_id group(s) found - merging before adding the unique constraint...' . PHP_EOL;

    // Every table with a plain customer_id FK, reassigned by a direct UPDATE. store_credit
    // (UNIQUE KEY per customer_id) is handled separately below since it can't simply be
    // re-pointed if the canonical customer already has a row of its own.
    $reassignTables = [
        'customer_addresses', 'customer_memberships', 'point_transactions',
        'birthday_reward_logs', 'store_credit_logs', 'customer_storage',
        'ship_requests', 'shipments', 'invoices', 'mewmii_orders',
    ];

    foreach ($groups as $group) {
        $ids = array_map('intval', explode(',', (string) $group['ids']));
        $canonicalId = array_shift($ids);

        foreach ($ids as $duplicateId) {
            $duplicateSucceeded = true;

            foreach ($reassignTables as $table) {
                $label = "customers.merge_{$table}_{$duplicateId}_into_{$canonicalId}";
                try {
                    $pdo->prepare("UPDATE {$table} SET customer_id = ? WHERE customer_id = ?")->execute([$canonicalId, $duplicateId]);
                    $applied[] = $label;
                } catch (PDOException $exception) {
                    $duplicateSucceeded = false;
                    echo '  ! FAILED: ' . $label . ' - ' . $exception->getMessage() . PHP_EOL;
                    $failures = &migrate_failures();
                    $failures[$label] = $exception->getMessage();
                }
            }

            try {
                $canonicalCreditStmt = $pdo->prepare('SELECT id FROM store_credit WHERE customer_id = ?');
                $canonicalCreditStmt->execute([$canonicalId]);
                $canonicalCreditId = $canonicalCreditStmt->fetchColumn();

                $dupCreditStmt = $pdo->prepare('SELECT id, balance FROM store_credit WHERE customer_id = ?');
                $dupCreditStmt->execute([$duplicateId]);
                $dupCredit = $dupCreditStmt->fetch(PDO::FETCH_ASSOC);

                if ($dupCredit !== false) {
                    if ($canonicalCreditId !== false) {
                        $pdo->prepare('UPDATE store_credit SET balance = balance + ? WHERE customer_id = ?')
                            ->execute([$dupCredit['balance'], $canonicalId]);
                        $pdo->prepare('DELETE FROM store_credit WHERE id = ?')->execute([$dupCredit['id']]);
                        $applied[] = "customers.merge_store_credit_balance_{$duplicateId}_into_{$canonicalId}";
                    } else {
                        $pdo->prepare('UPDATE store_credit SET customer_id = ? WHERE id = ?')
                            ->execute([$canonicalId, $dupCredit['id']]);
                        $applied[] = "customers.merge_store_credit_reassign_{$duplicateId}_into_{$canonicalId}";
                    }
                }
            } catch (PDOException $exception) {
                $duplicateSucceeded = false;
                $label = "customers.merge_store_credit_{$duplicateId}";
                echo '  ! FAILED: ' . $label . ' - ' . $exception->getMessage() . PHP_EOL;
                $failures = &migrate_failures();
                $failures[$label] = $exception->getMessage();
            }

            if ($duplicateSucceeded) {
                $label = "customers.delete_duplicate_{$duplicateId}";
                try {
                    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$duplicateId]);
                    $applied[] = $label;
                } catch (PDOException $exception) {
                    echo '  ! FAILED: ' . $label . ' - ' . $exception->getMessage() . PHP_EOL;
                    $failures = &migrate_failures();
                    $failures[$label] = $exception->getMessage();
                }
            } else {
                echo '  ! Skipping delete of duplicate customer #' . $duplicateId . ' - not every child record was reassigned successfully. Re-run this script after fixing the cause above.' . PHP_EOL;
            }
        }
    }
}

echo 'Mewmii OS Production Hardening (Phase 2) migration starting...' . PHP_EOL;

// Step 1: make sure every table this migration touches already exists (a no-op against an
// installation that's already up to date).
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
echo 'Step 1: base tables ensured (CREATE TABLE IF NOT EXISTS).' . PHP_EOL;

$applied = [];

// --- Customer integrity: merge duplicates, then the columns/constraint that prevent new
// ones. Must run before the UNIQUE KEY below. ---

migrate_merge_duplicate_customers($pdo, $applied);

if (!migrate_column_exists($pdo, 'customers', 'woocommerce_customer_id')) {
    // Only relevant for an installation old enough to predate this column entirely -
    // schema.sql (Step 1 above) already added it for anyone upgrading through
    // migrate_woocommerce_sync.php first.
    migrate_run($pdo, 'customers.woocommerce_customer_id', 'ALTER TABLE customers ADD COLUMN woocommerce_customer_id BIGINT UNSIGNED NULL AFTER id', $applied);
}

if (!migrate_index_exists($pdo, 'customers', 'idx_customers_email')) {
    migrate_run($pdo, 'customers.idx_email', 'ALTER TABLE customers ADD INDEX idx_customers_email (email)', $applied);
}

// Gated on there being no duplicate group left - migrate_merge_duplicate_customers() above
// leaves a duplicate in place (rather than deleting it) if any of its child records failed
// to reassign, so this ALTER must not be attempted against still-dirty data.
if (!migrate_index_exists($pdo, 'customers', 'uq_customers_woocommerce_customer_id')) {
    $remainingDupesStmt = $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT woocommerce_customer_id FROM customers
            WHERE woocommerce_customer_id IS NOT NULL
            GROUP BY woocommerce_customer_id
            HAVING COUNT(*) > 1
        ) AS still_duplicated
    ");
    if ((int) $remainingDupesStmt->fetchColumn() === 0) {
        migrate_run($pdo, 'customers.uq_woocommerce_customer_id', 'ALTER TABLE customers ADD UNIQUE KEY uq_customers_woocommerce_customer_id (woocommerce_customer_id)', $applied);
    } else {
        echo '  ! Skipping UNIQUE KEY on customers.woocommerce_customer_id - duplicate groups remain after merging. Re-run this script once the failures above are resolved.' . PHP_EOL;
    }
}

// --- WooCommerce conflict protection: baseline timestamp for staleness detection ---

if (!migrate_column_exists($pdo, 'products', 'woocommerce_last_seen_modified_at')) {
    migrate_run($pdo, 'products.woocommerce_last_seen_modified_at', 'ALTER TABLE products ADD COLUMN woocommerce_last_seen_modified_at DATETIME NULL AFTER woocommerce_sync_hash', $applied);
}

// --- Missing indexes (Production Readiness Audit, High findings) ---

if (!migrate_index_exists($pdo, 'mewmii_orders', 'idx_mewmii_orders_order_status')) {
    migrate_run($pdo, 'mewmii_orders.idx_order_status', 'ALTER TABLE mewmii_orders ADD INDEX idx_mewmii_orders_order_status (order_status)', $applied);
}
if (!migrate_index_exists($pdo, 'mewmii_orders', 'idx_mewmii_orders_order_date')) {
    migrate_run($pdo, 'mewmii_orders.idx_order_date', 'ALTER TABLE mewmii_orders ADD INDEX idx_mewmii_orders_order_date (order_date)', $applied);
}
if (!migrate_index_exists($pdo, 'mewmii_orders', 'idx_mewmii_orders_created_at')) {
    migrate_run($pdo, 'mewmii_orders.idx_created_at', 'ALTER TABLE mewmii_orders ADD INDEX idx_mewmii_orders_created_at (created_at)', $applied);
}

if (!migrate_index_exists($pdo, 'inventory_transactions', 'idx_inventory_transactions_reference')) {
    migrate_run($pdo, 'inventory_transactions.idx_reference', 'ALTER TABLE inventory_transactions ADD INDEX idx_inventory_transactions_reference (reference_type, reference_id)', $applied);
}

if (!migrate_index_exists($pdo, 'supplier_orders', 'idx_supplier_orders_status')) {
    migrate_run($pdo, 'supplier_orders.idx_status', 'ALTER TABLE supplier_orders ADD INDEX idx_supplier_orders_status (status)', $applied);
}
if (!migrate_index_exists($pdo, 'supplier_orders', 'idx_supplier_orders_payment_status')) {
    migrate_run($pdo, 'supplier_orders.idx_payment_status', 'ALTER TABLE supplier_orders ADD INDEX idx_supplier_orders_payment_status (payment_status)', $applied);
}
if (!migrate_index_exists($pdo, 'supplier_orders', 'idx_supplier_orders_expected_delivery_date')) {
    migrate_run($pdo, 'supplier_orders.idx_expected_delivery_date', 'ALTER TABLE supplier_orders ADD INDEX idx_supplier_orders_expected_delivery_date (expected_delivery_date)', $applied);
}

if (!migrate_index_exists($pdo, 'products', 'idx_products_status')) {
    migrate_run($pdo, 'products.idx_status', 'ALTER TABLE products ADD INDEX idx_products_status (status)', $applied);
}
if (!migrate_index_exists($pdo, 'products', 'idx_products_product_type')) {
    migrate_run($pdo, 'products.idx_product_type', 'ALTER TABLE products ADD INDEX idx_products_product_type (product_type)', $applied);
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
    echo PHP_EOL . count($failures) . ' migration statement(s) FAILED - re-run this script after fixing the cause; every other step above still applied normally:' . PHP_EOL;
    foreach ($failures as $label => $message) {
        echo '  ! ' . $label . ' - ' . $message . PHP_EOL;
    }
}

echo 'Done.' . PHP_EOL;
