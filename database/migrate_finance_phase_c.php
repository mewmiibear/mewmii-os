<?php
/**
 * Finance & Accounting Phase C (docs/FINANCE_ACCOUNTING_ARCHITECTURE.md §16):
 * - assets
 * - asset_attachments
 *
 * Purely additive: two CREATE TABLEs, no ALTER against any existing table, so unlike
 * migrate_finance_phase_a.php this needs no data-safety guard - there is no legacy `assets`
 * scaffolding anywhere in the schema to replace or protect.
 *
 * Depends on Phase B: assets.bank_account_id references bank_accounts. Run order is handled
 * automatically by database/migrate.php (glob + sort puts _phase_a before _phase_b before
 * _phase_c). If run standalone against a database that never got Phase B, the CREATE TABLE
 * fails on the missing FK target, is recorded as a failure, and nothing is left half-built.
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * or CLI (`php database/migrate_finance_phase_c.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates both tables.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_finance_phase_c(PDO $pdo): array
{
    echo 'Mewmii OS Finance Phase C migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_table_exists($pdo, 'assets')) {
        migrate_run($pdo, 'assets.create', "
            CREATE TABLE assets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_code VARCHAR(30) NULL,
                name VARCHAR(120) NOT NULL,
                category VARCHAR(30) NOT NULL,
                supplier_id INT UNSIGNED NULL,
                bank_account_id INT UNSIGNED NULL,
                assigned_to INT UNSIGNED NULL,
                location VARCHAR(100) NULL,
                purchase_date DATE NOT NULL,
                purchase_amount DECIMAL(12,2) NOT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'MYR',
                exchange_rate DECIMAL(12,6) NULL,
                warranty_expiry DATE NULL,
                description VARCHAR(255) NOT NULL,
                notes TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'in_use',
                disposal_date DATE NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_assets_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
                CONSTRAINT fk_assets_bank_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
                CONSTRAINT fk_assets_assigned_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_assets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE KEY uq_assets_asset_code (asset_code),
                INDEX idx_assets_status (status),
                INDEX idx_assets_purchase_date (purchase_date),
                INDEX idx_assets_category (category),
                INDEX idx_assets_supplier (supplier_id),
                INDEX idx_assets_bank_account (bank_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    if (!migrate_table_exists($pdo, 'asset_attachments')) {
        migrate_run($pdo, 'asset_attachments.create', "
            CREATE TABLE asset_attachments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_id INT UNSIGNED NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                file_type VARCHAR(100) NULL,
                uploaded_by INT UNSIGNED NULL,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_asset_attachments_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
                CONSTRAINT fk_asset_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_asset_attachments_asset (asset_id)
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
    migrate_finance_phase_c(app_db());
}
