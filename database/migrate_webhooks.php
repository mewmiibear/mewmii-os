<?php
/**
 * Phase 6E (WooCommerce webhook integration) - adds the webhook_events table backing
 * modules/webhooks/woocommerce.php (the receiver) and cli/wc_webhook_process.php (the
 * queue processor). Purely additive: no existing table/column is touched, and nothing about
 * the existing poll-based importers (includes/wc_product_import.php,
 * includes/wc_order_import.php) changes. Safe to run multiple times: CREATE TABLE IF NOT
 * EXISTS is a no-op if it already exists.
 *
 * Run once via browser (https://yourdomain/database/migrate_webhooks.php) or CLI
 * (`php database/migrate_webhooks.php`) against an EXISTING Mewmii OS database. Brand-new
 * installs don't need this - database/schema.sql already creates this table via install.php.
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

echo 'Mewmii OS webhook integration migration starting...' . PHP_EOL;

$applied = [];

// webhook_events: one row per inbound WooCommerce webhook delivery that passed signature
// verification (see includes/wc_webhook.php). Deliberately NOT the same table as sync_logs -
// this is the received-event queue (what still needs processing, or already has been),
// sync_logs remains the per-item outcome log every sync entry point already writes to
// (webhook processing also writes there, under sync_type='woocommerce_webhook' - see
// includes/wc_webhook.php's docblock). woocommerce_delivery_id is nullable (InnoDB allows
// any number of NULLs in a UNIQUE index, same convention as customers.
// uq_customers_woocommerce_customer_id) - if WooCommerce ever omits the delivery-id header,
// wc_webhook_record_event() falls back to payload_hash for duplicate detection instead of
// relying solely on this constraint.
if (!migrate_column_exists($pdo, 'webhook_events', 'id')) {
    migrate_run($pdo, 'webhook_events.create', "
        CREATE TABLE IF NOT EXISTS webhook_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            woocommerce_delivery_id VARCHAR(100) NULL,
            topic VARCHAR(50) NOT NULL,
            resource VARCHAR(20) NOT NULL,
            resource_id BIGINT UNSIGNED NULL,
            payload_hash CHAR(64) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            next_retry_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL,
            UNIQUE KEY uq_webhook_events_delivery_id (woocommerce_delivery_id),
            INDEX idx_webhook_events_status_retry (status, next_retry_at),
            INDEX idx_webhook_events_resource (resource, resource_id),
            INDEX idx_webhook_events_created_at (created_at)
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

echo 'Done. Existing WooCommerce polling/import logic is completely unaffected by this table.' . PHP_EOL;
