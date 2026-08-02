<?php
/**
 * Finance & Accounting Phase A (docs/FINANCE_DATABASE_DESIGN.md, docs/FINANCE_ACCOUNTING_
 * ARCHITECTURE.md §16) - adds expense_categories and expense_attachments, and replaces the
 * `expenses` table shape. The old `expenses` table was pure unused scaffolding (confirmed by
 * audit: zero application code anywhere read or wrote it) - safe to drop and recreate rather
 * than ALTER, but only if it is actually still empty on this database; if it somehow already
 * has rows, this migration refuses to touch it and reports a failure instead, exactly like
 * every other migration in this codebase never silently destroys data.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * or CLI (`php database/migrate_finance_phase_a.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates the new shape
 * directly via install.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_finance_phase_a(PDO $pdo): array
{
    echo 'Mewmii OS Finance Phase A migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_table_exists($pdo, 'expense_categories')) {
        migrate_run($pdo, 'expense_categories', "
            CREATE TABLE expense_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                parent_id INT UNSIGNED NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_expense_categories_parent FOREIGN KEY (parent_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
                INDEX idx_expense_categories_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    // Replace the legacy `expenses` scaffolding - only if it's confirmed empty right now.
    $expensesAlreadyNewShape = migrate_table_exists($pdo, 'expenses') && migrate_column_exists($pdo, 'expenses', 'category_id');

    if (!$expensesAlreadyNewShape) {
        $existingExpenseRows = 0;
        if (migrate_table_exists($pdo, 'expenses')) {
            $existingExpenseRows = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
        }

        if ($existingExpenseRows > 0) {
            $failures = &migrate_failures();
            $failures['expenses.rebuild'] = "Refused to drop 'expenses' - it already has {$existingExpenseRows} row(s). "
                . 'This migration only ever expected to replace the known-dead pre-existing scaffolding table - manual review required before proceeding.';
        } else {
            migrate_run($pdo, 'expenses.drop_legacy', 'DROP TABLE IF EXISTS expenses', $applied);
            migrate_run($pdo, 'expenses.create', "
                CREATE TABLE expenses (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    category_id INT UNSIGNED NOT NULL,
                    supplier_id INT UNSIGNED NULL,
                    expense_date DATE NOT NULL,
                    description VARCHAR(255) NOT NULL,
                    amount DECIMAL(12,2) NOT NULL,
                    currency VARCHAR(10) NOT NULL DEFAULT 'MYR',
                    exchange_rate DECIMAL(12,6) NULL,
                    payment_method VARCHAR(50) NULL,
                    reference_number VARCHAR(100) NULL,
                    tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
                    status VARCHAR(20) NOT NULL DEFAULT 'draft',
                    created_by INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id),
                    CONSTRAINT fk_expenses_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
                    CONSTRAINT fk_expenses_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_expenses_date (expense_date),
                    INDEX idx_expenses_category (category_id),
                    INDEX idx_expenses_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ", $applied);
        }
    }

    if (!migrate_table_exists($pdo, 'expense_attachments')) {
        migrate_run($pdo, 'expense_attachments', "
            CREATE TABLE expense_attachments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                expense_id INT UNSIGNED NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                file_type VARCHAR(100) NULL,
                uploaded_by INT UNSIGNED NULL,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_expense_attachments_expense FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
                CONSTRAINT fk_expense_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_expense_attachments_expense (expense_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    echo count($applied) . ' migration statement(s) applied:' . PHP_EOL;
    foreach ($applied as $item) {
        echo '  - ' . $item . PHP_EOL;
    }

    if ($applied === [] && $expensesAlreadyNewShape) {
        echo 'Database was already up to date - nothing to do.' . PHP_EOL;
    }

    $failures = migrate_failures();
    if ($failures !== []) {
        echo PHP_EOL . count($failures) . ' migration statement(s) FAILED:' . PHP_EOL;
        foreach ($failures as $label => $message) {
            echo '  ! ' . $label . ' - ' . $message . PHP_EOL;
        }
    }

    echo 'Done. No existing Orders, Inventory, or Supplier Orders logic was touched - Finance reads from them, never the other way around.' . PHP_EOL;

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
    migrate_finance_phase_a(app_db());
}
