<?php
/**
 * Finance & Accounting Phase B (docs/FINANCE_ACCOUNTING_ARCHITECTURE.md §16):
 * - bank_accounts
 * - manual_income
 * - expenses.bank_account_id linkage
 *
 * Additive, idempotent, and non-destructive only.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_finance_phase_b(PDO $pdo): array
{
    echo 'Mewmii OS Finance Phase B migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_table_exists($pdo, 'bank_accounts')) {
        migrate_run($pdo, 'bank_accounts.create', "
            CREATE TABLE bank_accounts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                account_type VARCHAR(20) NOT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'MYR',
                notes VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bank_accounts_active_name (is_active, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    if (!migrate_column_exists($pdo, 'expenses', 'bank_account_id')) {
        migrate_run($pdo, 'expenses.add_bank_account_id', '
            ALTER TABLE expenses
            ADD COLUMN bank_account_id INT UNSIGNED NULL AFTER supplier_id
        ', $applied);
    }

    if (migrate_column_exists($pdo, 'expenses', 'bank_account_id') && !migrate_index_exists($pdo, 'expenses', 'idx_expenses_bank_account')) {
        migrate_run($pdo, 'expenses.add_bank_account_index', '
            ALTER TABLE expenses
            ADD INDEX idx_expenses_bank_account (bank_account_id)
        ', $applied);
    }

    if (migrate_column_exists($pdo, 'expenses', 'bank_account_id')) {
        $fkExistsStmt = $pdo->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'expenses'
              AND CONSTRAINT_NAME = 'fk_expenses_bank_account'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");
        $fkExistsStmt->execute();
        $fkExists = (int) $fkExistsStmt->fetchColumn() > 0;

        if (!$fkExists) {
            migrate_run($pdo, 'expenses.add_bank_account_fk', '
                ALTER TABLE expenses
                ADD CONSTRAINT fk_expenses_bank_account
                FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
            ', $applied);
        }
    }

    if (!migrate_table_exists($pdo, 'manual_income')) {
        migrate_run($pdo, 'manual_income.create', "
            CREATE TABLE manual_income (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                income_date DATE NOT NULL,
                description VARCHAR(255) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'MYR',
                exchange_rate DECIMAL(12,6) NULL,
                category VARCHAR(30) NOT NULL,
                bank_account_id INT UNSIGNED NULL,
                reference_number VARCHAR(100) NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_manual_income_bank_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
                CONSTRAINT fk_manual_income_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_manual_income_date (income_date),
                INDEX idx_manual_income_category (category),
                INDEX idx_manual_income_bank_account (bank_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
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

    return [
        'success' => $failures === [],
        'applied' => $applied,
        'failures' => $failures,
        'message' => $resultMessage,
    ];
}

if (!defined('MIGRATE_RUNNER_ACTIVE')) {
    migrate_finance_phase_b(app_db());
}
