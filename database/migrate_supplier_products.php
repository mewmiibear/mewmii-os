<?php
/**
 * SO-C (Multi-Supplier Sourcing) - supplier_products.
 *
 * Adds the sourcing catalogue that lets one product have several suppliers, each with their own
 * SKU, quoted price, currency and priority. See database/schema.sql's supplier_products comment
 * for the three boundaries this respects (products.supplier_id stays the preferred supplier;
 * unit_cost is quotation data that never enters costing; purchase history stays derived).
 *
 * Additive and non-destructive. The seed step is INSERT-only: it creates one catalogue row per
 * product that already has a supplier assigned, copying that product's existing supplier_sku /
 * product_cost / cost_currency / exchange_rate at priority 0. It NEVER updates or deletes a
 * products, product_variations, or suppliers row, and it skips any (product, supplier) pair
 * already present, so re-running changes nothing.
 *
 * Run via database/migrate.php, or standalone via browser or CLI
 * (`php database/migrate_supplier_products.php`) against an EXISTING database. Brand-new installs
 * don't need this - database/schema.sql already creates the table (the seed is a no-op on an
 * empty catalogue).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_supplier_products(PDO $pdo): array
{
    echo 'Mewmii OS supplier_products (multi-supplier sourcing) migration starting...' . PHP_EOL;

    $applied = [];

    if (!migrate_table_exists($pdo, 'supplier_products')) {
        migrate_run($pdo, 'supplier_products.create', "
            CREATE TABLE supplier_products (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                variation_id INT UNSIGNED NULL,
                supplier_id INT UNSIGNED NOT NULL,
                supplier_sku VARCHAR(100) NULL,
                unit_cost DECIMAL(12,2) NULL,
                currency VARCHAR(10) NULL,
                exchange_rate DECIMAL(12,6) NULL,
                priority INT NOT NULL DEFAULT 0,
                moq INT UNSIGNED NULL,
                notes VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                variation_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(variation_id, 0)) STORED,
                CONSTRAINT fk_supplier_products_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                CONSTRAINT fk_supplier_products_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE CASCADE,
                CONSTRAINT fk_supplier_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
                CONSTRAINT fk_supplier_products_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE KEY uq_supplier_products_unit_supplier (product_id, variation_key, supplier_id),
                INDEX idx_supplier_products_supplier (supplier_id),
                INDEX idx_supplier_products_product_priority (product_id, priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", $applied);
    }

    // Seed - INSERT only, idempotent via NOT EXISTS. One product-level row (variation_id NULL)
    // per product that already has a preferred supplier. Deliberately does NOT create
    // per-variation rows: a variation's supplier SKU/cost already falls back to its parent via
    // variation_effective_supplier_sku()/variation_effective_cost(), so seeding one row per
    // variation would manufacture data nobody entered.
    if (migrate_table_exists($pdo, 'supplier_products')) {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM supplier_products')->fetchColumn();

        migrate_run($pdo, 'supplier_products.seed_from_products', "
            INSERT INTO supplier_products
                (product_id, variation_id, supplier_id, supplier_sku, unit_cost, currency, exchange_rate, priority, is_active)
            SELECT p.id, NULL, p.supplier_id,
                   NULLIF(TRIM(COALESCE(p.supplier_sku, '')), ''),
                   CASE WHEN p.product_cost > 0 THEN p.product_cost ELSE NULL END,
                   p.cost_currency, p.exchange_rate, 0, 1
            FROM products p
            WHERE p.supplier_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM supplier_products sp
                  WHERE sp.product_id = p.id AND sp.variation_key = 0 AND sp.supplier_id = p.supplier_id
              )
        ", $applied);

        $after = (int) $pdo->query('SELECT COUNT(*) FROM supplier_products')->fetchColumn();
        echo '  seeded ' . ($after - $before) . ' catalogue row(s) from products.supplier_id' . PHP_EOL;
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

    echo 'Done. No products/suppliers/costing row was modified - products.supplier_id remains the preferred supplier.' . PHP_EOL;

    return [
        'success' => $failures === [],
        'applied' => $applied,
        'failures' => $failures,
        'message' => $resultMessage,
    ];
}

if (!defined('MIGRATE_RUNNER_ACTIVE')) {
    migrate_supplier_products(app_db());
}
