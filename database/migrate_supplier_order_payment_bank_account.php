<?php
/**
 * SO-B - supplier_order_payments.bank_account_id.
 *
 * Adds the one piece of groundwork account-level reconciliation needs and cannot get after the
 * fact without back-filling history: which account a supplier payment actually moved through.
 * Mirrors expenses.bank_account_id / manual_income.bank_account_id (Finance Phase B) exactly -
 * same type, same nullability, same ON DELETE SET NULL.
 *
 * TAGGING ONLY. No balance is stored or computed from this column. supplier_order_paid_amount()
 * still derives paid/remaining live from SUM(amount), order totals are untouched, and nothing in
 * receiving, inventory_transactions, or product_cost_history reads this table at all.
 *
 * Additive, idempotent, non-destructive. Nullable, so every existing payment stays valid with no
 * backfill. Depends on bank_accounts (created by migrate_finance_phase_b.php) - the runner's
 * alphabetical discovery puts that first ('f' < 's'); run standalone against a database without
 * it and the FK step fails cleanly, is recorded as a failure, and nothing is left half-built.
 *
 * Run via database/migrate.php, or standalone via browser or CLI
 * (`php database/migrate_supplier_order_payment_bank_account.php`) against an EXISTING database.
 * Brand-new installs don't need this - database/schema.sql already creates the column.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_supplier_order_payment_bank_account(PDO $pdo): array
{
    echo 'Mewmii OS supplier order payment bank account migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_column_exists($pdo, 'supplier_order_payments', 'bank_account_id')) {
        migrate_run($pdo, 'supplier_order_payments.add_bank_account_id', '
            ALTER TABLE supplier_order_payments
            ADD COLUMN bank_account_id INT UNSIGNED NULL AFTER payment_method
        ', $applied);
    }

    if (migrate_column_exists($pdo, 'supplier_order_payments', 'bank_account_id')
        && !migrate_index_exists($pdo, 'supplier_order_payments', 'idx_supplier_order_payments_bank_account')) {
        migrate_run($pdo, 'supplier_order_payments.add_bank_account_index', '
            ALTER TABLE supplier_order_payments
            ADD INDEX idx_supplier_order_payments_bank_account (bank_account_id)
        ', $applied);
    }

    if (migrate_column_exists($pdo, 'supplier_order_payments', 'bank_account_id')) {
        $fkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'supplier_order_payments'
              AND CONSTRAINT_NAME = 'fk_supplier_order_payments_bank_account'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");
        $fkStmt->execute();

        if ((int) $fkStmt->fetchColumn() === 0) {
            migrate_run($pdo, 'supplier_order_payments.add_bank_account_fk', '
                ALTER TABLE supplier_order_payments
                ADD CONSTRAINT fk_supplier_order_payments_bank_account
                FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
            ', $applied);
        }
    }

    echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
    foreach ($applied as $item) {
        echo '  - ' . $item . PHP_EOL;
    }

    $failures = migrate_failures();
    if ($failures !== []) {
        echo PHP_EOL . count($failures) . ' migration statement(s) FAILED:' . PHP_EOL;
        foreach ($failures as $label => $message) {
            echo '  ! ' . $label . ' - ' . $message . PHP_EOL;
        }
    }

    if ($failures !== []) {
        $resultMessage = count($failures) . ' statement(s) failed';
    } elseif ($applied === []) {
        $resultMessage = 'Already up to date';
    } else {
        $resultMessage = count($applied) . ' statement(s) applied';
    }

    echo 'Done. No existing payment row was modified; paid/remaining totals are unchanged.' . PHP_EOL;

    return [
        'success' => $failures === [],
        'applied' => $applied,
        'failures' => $failures,
        'message' => $resultMessage,
    ];
}

if (!defined('MIGRATE_RUNNER_ACTIVE')) {
    migrate_supplier_order_payment_bank_account(app_db());
}
