<?php
/**
 * Customer Order Resolution System - adds 7 new tables. Purely additive: no existing table
 * (mewmii_orders, mewmii_order_items, customers, mewmii_notifications) is altered. Resolution
 * state lives entirely in these new tables, joined against existing ones where needed - this is
 * the "keep resolution status separate" decision (see includes/order_resolution.php's own
 * docblock).
 *
 * Run via database/migrate.php (discovers and runs this in-process), or standalone via browser
 * (https://yourdomain/database/migrate_order_resolution.php) or CLI
 * (`php database/migrate_order_resolution.php`) against an EXISTING Mewmii OS database.
 * Brand-new installs don't need this - database/schema.sql already creates these tables via
 * install.php. Safe to run multiple times: CREATE TABLE IF NOT EXISTS is a no-op if already there.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_order_resolution(PDO $pdo): array
{
    echo 'Mewmii OS order resolution system migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_column_exists($pdo, 'resolution_requests', 'id')) {
        migrate_run($pdo, 'resolution_requests.create', "
            CREATE TABLE IF NOT EXISTS resolution_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'awaiting_customer_choice',
                reason TEXT NULL,
                token_hash CHAR(64) NOT NULL,
                token_expires_at DATETIME NOT NULL,
                created_by INT UNSIGNED NULL,
                resolved_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_resolution_requests_token_hash (token_hash),
                CONSTRAINT fk_resolution_requests_order FOREIGN KEY (order_id) REFERENCES mewmii_orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_resolution_requests_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_resolution_requests_order (order_id),
                INDEX idx_resolution_requests_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    if (!migrate_column_exists($pdo, 'resolution_items', 'id')) {
        migrate_run($pdo, 'resolution_items.create', "
            CREATE TABLE IF NOT EXISTS resolution_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                resolution_id INT UNSIGNED NOT NULL,
                order_item_id INT UNSIGNED NOT NULL,
                original_variation_id INT UNSIGNED NULL,
                original_price DECIMAL(12,2) NOT NULL,
                chosen_action VARCHAR(20) NULL,
                replacement_variation_id INT UNSIGNED NULL,
                replacement_price DECIMAL(12,2) NULL,
                price_difference DECIMAL(12,2) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_resolution_items_resolution FOREIGN KEY (resolution_id) REFERENCES resolution_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_resolution_items_order_item FOREIGN KEY (order_item_id) REFERENCES mewmii_order_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_resolution_items_original_variation FOREIGN KEY (original_variation_id) REFERENCES product_variations(id) ON DELETE SET NULL,
                CONSTRAINT fk_resolution_items_replacement_variation FOREIGN KEY (replacement_variation_id) REFERENCES product_variations(id) ON DELETE SET NULL,
                INDEX idx_resolution_items_resolution (resolution_id),
                INDEX idx_resolution_items_order_item (order_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    if (!migrate_column_exists($pdo, 'customer_wallets', 'id')) {
        migrate_run($pdo, 'customer_wallets.create', "
            CREATE TABLE IF NOT EXISTS customer_wallets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(10) NOT NULL DEFAULT 'MYR',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_customer_wallets_customer (customer_id),
                CONSTRAINT fk_customer_wallets_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    if (!migrate_column_exists($pdo, 'customer_wallet_transactions', 'id')) {
        migrate_run($pdo, 'customer_wallet_transactions.create', "
            CREATE TABLE IF NOT EXISTS customer_wallet_transactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                reference_type VARCHAR(50) NULL,
                reference_id INT UNSIGNED NULL,
                note TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_wallet_tx_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                INDEX idx_wallet_tx_customer (customer_id),
                INDEX idx_wallet_tx_reference (reference_type, reference_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    if (!migrate_column_exists($pdo, 'payment_receipts', 'id')) {
        migrate_run($pdo, 'payment_receipts.create', "
            CREATE TABLE IF NOT EXISTS payment_receipts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                resolution_id INT UNSIGNED NOT NULL,
                order_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                verified_by INT UNSIGNED NULL,
                verified_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_payment_receipts_resolution FOREIGN KEY (resolution_id) REFERENCES resolution_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_payment_receipts_order FOREIGN KEY (order_id) REFERENCES mewmii_orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_payment_receipts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                CONSTRAINT fk_payment_receipts_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_payment_receipts_resolution (resolution_id),
                INDEX idx_payment_receipts_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    if (!migrate_column_exists($pdo, 'resolution_refunds', 'id')) {
        migrate_run($pdo, 'resolution_refunds.create', "
            CREATE TABLE IF NOT EXISTS resolution_refunds (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                resolution_id INT UNSIGNED NOT NULL,
                resolution_item_id INT UNSIGNED NULL,
                order_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                notes TEXT NULL,
                processed_by INT UNSIGNED NULL,
                processed_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_resolution_refunds_resolution FOREIGN KEY (resolution_id) REFERENCES resolution_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_resolution_refunds_item FOREIGN KEY (resolution_item_id) REFERENCES resolution_items(id) ON DELETE SET NULL,
                CONSTRAINT fk_resolution_refunds_order FOREIGN KEY (order_id) REFERENCES mewmii_orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_resolution_refunds_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                CONSTRAINT fk_resolution_refunds_processor FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_resolution_refunds_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    if (!migrate_column_exists($pdo, 'customer_notifications', 'id')) {
        migrate_run($pdo, 'customer_notifications.create', "
            CREATE TABLE IF NOT EXISTS customer_notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                order_id INT UNSIGNED NULL,
                resolution_id INT UNSIGNED NULL,
                type VARCHAR(50) NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NULL,
                sent_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_customer_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                INDEX idx_customer_notifications_customer (customer_id)
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

    echo 'Done. No existing order/customer/notification table was altered.' . PHP_EOL;

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
    migrate_order_resolution(app_db());
}
