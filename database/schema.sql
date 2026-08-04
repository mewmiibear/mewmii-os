CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  module VARCHAR(80) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_role_permission (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  INDEX idx_users_role (role_id),
  INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_user (user_id),
  INDEX idx_audit_action (action),
  -- Security Hardening Phase 4D: this table is now read on every login attempt (see
  -- app_login_throttle_delay() in includes/bootstrap.php), not just written to - an index on
  -- ip_address keeps that query cheap as the table grows.
  INDEX idx_audit_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reusable, module-agnostic activity log for future auditing - separate from the
-- pre-existing (currently unused) audit_logs table above, which this does not replace or
-- touch. Append-only; nothing in this app ever updates or deletes a row here.
CREATE TABLE IF NOT EXISTS activity_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  module VARCHAR(50) NOT NULL,
  action VARCHAR(50) NOT NULL,
  record_id INT UNSIGNED NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_activity_logs_module (module),
  INDEX idx_activity_logs_record (module, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  woocommerce_customer_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  instagram_username VARCHAR(100) NULL,
  birthday DATE NULL,
  address TEXT NULL,
  notes TEXT NULL,
  -- WooCommerce customer.deleted webhook support - reuses the same nullable-timestamp
  -- "archived" convention as product_variations.archived_at (NULL = active, set = archived).
  -- See includes/wc_customer_import.php's wc_customer_import_archive_deleted(). The row and
  -- its id are always kept (never hard-deleted) so mewmii_orders.customer_id never dangles -
  -- name/email/phone/address/instagram_username/birthday/notes are anonymised in place instead.
  archived_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customers_email (email),
  -- NULL-tolerant (InnoDB allows any number of NULL values in a unique index) - a customer
  -- never created from a WooCommerce order simply never sets this column. See
  -- database/migrate_production_hardening.php for how an existing install with dirty data
  -- (duplicate values) gets merged before this constraint can be added.
  UNIQUE KEY uq_customers_woocommerce_customer_id (woocommerce_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_addresses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'default',
  address_line VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  postcode VARCHAR(30) NULL,
  country VARCHAR(100) NULL,
  CONSTRAINT fk_customer_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  contact VARCHAR(120) NULL,
  country VARCHAR(100) NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(190) NULL,
  currency VARCHAR(10) NULL,
  payment_terms VARCHAR(100) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 9D (Pricing Engine) - configurable RM/gram international shipping rate per origin
-- country, managed from modules/settings/shipping_rates.php. Never hardcoded in PHP. Defined
-- before `products` because products.shipping_origin_country_id references it below.
CREATE TABLE IF NOT EXISTS shipping_rate_countries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  country_name VARCHAR(100) NOT NULL UNIQUE,
  rate_per_gram DECIMAL(10,4) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 9F.1/9F.2 (Multi-Purpose Currency Rate Settings) - the centrally-managed "1 unit = ?
-- MYR" rate per currency, managed from modules/settings/currency_rates.php. Replaces manual
-- per-product exchange rate entry - see includes/currency_rates.php for how this feeds
-- includes/pricing_engine.php and keeps products.exchange_rate (still read as-is by the actual
-- Landed Cost engine, includes/product_cost.php) in sync automatically. rate_type
-- ('supplier'/'original'/'market') is part of the key because the SAME currency code needs a
-- different rate per calculation purpose (e.g. JPY's supplier rate, original rate, and market
-- rate are three independent numbers, not one) - see Phase 9F.2.
CREATE TABLE IF NOT EXISTS currency_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_type VARCHAR(20) NOT NULL DEFAULT 'supplier',
  currency_code VARCHAR(10) NOT NULL,
  exchange_rate DECIMAL(12,6) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_currency_rates_type_code (rate_type, currency_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catalog taxonomies. Defined before `products` because products.brand_id references brands.
CREATE TABLE IF NOT EXISTS brands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  slug VARCHAR(140) NOT NULL UNIQUE,
  description TEXT NULL,
  logo_path VARCHAR(500) NULL,
  woocommerce_term_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  slug VARCHAR(140) NOT NULL UNIQUE,
  parent_id INT UNSIGNED NULL,
  description TEXT NULL,
  image_path VARCHAR(500) NULL,
  woocommerce_term_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS collections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  slug VARCHAR(140) NOT NULL UNIQUE,
  start_date DATE NULL,
  end_date DATE NULL,
  description TEXT NULL,
  image_path VARCHAR(500) NULL,
  woocommerce_term_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attributes (Character, Color, Size, ...). Character is an attribute like any other -
-- there is no separate "characters" table. Defined before `products`/`product_variations`
-- so later FKs can reference them.
CREATE TABLE IF NOT EXISTS product_attributes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  slug VARCHAR(120) NOT NULL UNIQUE,
  woocommerce_attribute_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_attribute_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attribute_id INT UNSIGNED NOT NULL,
  value VARCHAR(150) NOT NULL,
  code VARCHAR(10) NULL,
  slug VARCHAR(170) NOT NULL,
  brand_id INT UNSIGNED NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  woocommerce_term_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attribute_values_attribute FOREIGN KEY (attribute_id) REFERENCES product_attributes(id) ON DELETE CASCADE,
  CONSTRAINT fk_attribute_values_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
  UNIQUE KEY uq_attribute_value (attribute_id, value),
  UNIQUE KEY uq_attribute_value_code (attribute_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  woocommerce_product_id BIGINT UNSIGNED NULL,
  sku VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  short_description VARCHAR(500) NULL,
  description TEXT NULL,
  product_type VARCHAR(50) NOT NULL DEFAULT 'ready_stock',
  catalog_type VARCHAR(20) NOT NULL DEFAULT 'simple',
  brand_id INT UNSIGNED NULL,
  barcode VARCHAR(64) NULL,
  supplier_sku VARCHAR(100) NULL,
  internal_code VARCHAR(100) NULL,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  product_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  -- Costing prep (Sprint 13) - no calculation logic reads these yet; product_cost stays
  -- authoritative everywhere. See database/migrate_product_costing.php for the intended
  -- converted-cost/landed-cost formula this is scaffolding for.
  cost_currency VARCHAR(10) NULL,
  exchange_rate DECIMAL(10,4) NULL,
  -- Phase 9D (Pricing Engine) - Original Price (brand/official retail reference, e.g. Sanrio's
  -- own price) and Market Price (competitor/reseller reference) are both read-only comparison
  -- figures - see includes/pricing_engine.php. They are never read by includes/product_cost.php's
  -- actual Landed Cost engine. product_cost/cost_currency/exchange_rate above remain the
  -- Supplier Price fields, reused as-is (not duplicated).
  original_price DECIMAL(12,2) NULL,
  original_currency VARCHAR(10) NULL,
  original_exchange_rate DECIMAL(10,4) NULL,
  market_price DECIMAL(12,2) NULL,
  market_currency VARCHAR(10) NULL,
  market_exchange_rate DECIMAL(10,4) NULL,
  -- Recommended Selling Price = Original Price (MYR) x selling_multiplier - a live, computed
  -- display only (pricing_calculate_recommended_selling_price()). selling_price above remains
  -- the single stored/editable final price; there is no selling_price_override column.
  selling_multiplier DECIMAL(6,2) NULL,
  -- Simple products only - variable products keep using product_variations.weight per
  -- variation (includes/pricing_engine.php falls back to that instead).
  weight_grams DECIMAL(10,2) NULL,
  shipping_origin_country_id INT UNSIGNED NULL,
  sale_enabled TINYINT(1) NOT NULL DEFAULT 0,
  sale_price DECIMAL(12,2) NULL,
  min_stock_threshold INT UNSIGNED NULL,
  target_stock_level INT UNSIGNED NULL,
  supplier_id INT UNSIGNED NULL,
  moq INT UNSIGNED NOT NULL DEFAULT 1,
  sale_start_date DATE NULL,
  sale_end_date DATE NULL,
  official_release_date DATE NULL,
  estimated_arrival_date DATE NULL,
  estimated_release_month VARCHAR(7) NULL,
  preorder_closing_date DATE NULL,
  preorder_reopened_at DATETIME NULL,
  expiry_date DATE NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'coming_soon',
  availability_override VARCHAR(20) NOT NULL DEFAULT 'auto',
  published_to_woocommerce TINYINT(1) NOT NULL DEFAULT 0,
  -- Fingerprint of every field the last successful "Sync to WooCommerce" push actually sent
  -- (see wc_client_product_sync_fingerprint() in includes/wc_client.php) - lets a bulk sync
  -- skip a product whose WooCommerce-relevant fields haven't changed since then, without a
  -- WooCommerce API call. NULL for a product that has never been pushed.
  woocommerce_sync_hash VARCHAR(64) NULL,
  -- WooCommerce's own `date_modified` as of the last time Mewmii OS confirmed its local
  -- state matched WooCommerce's (a successful import or a successful push) - see
  -- wc_client_sync_if_changed()'s staleness check in includes/wc_client.php. NULL for a
  -- product never imported from or pushed to WooCommerce.
  woocommerce_last_seen_modified_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_shipping_origin_country FOREIGN KEY (shipping_origin_country_id) REFERENCES shipping_rate_countries(id) ON DELETE SET NULL,
  INDEX idx_products_catalog_type (catalog_type),
  INDEX idx_products_status (status),
  INDEX idx_products_product_type (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_tag_relationships (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_product_tag_relationships_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_tag_relationships_tag FOREIGN KEY (tag_id) REFERENCES product_tags(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_tag (product_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_category_relationships (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_product_category_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_category_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_category (product_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_collection_relationships (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  collection_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_product_collection_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_collection_collection FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_collection (product_id, collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which attributes (and which of their global values) apply to a given product.
-- is_variation_attribute = 1 means the attribute participates in variation generation;
-- = 0 means it's descriptive/filter-only and does not drive SKUs.
CREATE TABLE IF NOT EXISTS product_attribute_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  attribute_id INT UNSIGNED NOT NULL,
  is_variation_attribute TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  source_template_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attr_assignments_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_attr_assignments_attribute FOREIGN KEY (attribute_id) REFERENCES product_attributes(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_attribute (product_id, attribute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_attribute_assignment_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assignment_id INT UNSIGNED NOT NULL,
  attribute_value_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_assignment_values_assignment FOREIGN KEY (assignment_id) REFERENCES product_attribute_assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_values_value FOREIGN KEY (attribute_value_id) REFERENCES product_attribute_values(id) ON DELETE CASCADE,
  UNIQUE KEY uq_assignment_value (assignment_id, attribute_value_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sellable variation of a variable product. The parent `products` row holds shared
-- catalog info only; each variation is its own sellable SKU with its own price/inventory.
CREATE TABLE IF NOT EXISTS product_variations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  sku VARCHAR(100) NOT NULL UNIQUE,
  barcode VARCHAR(64) NULL,
  supplier_sku VARCHAR(100) NULL,
  weight DECIMAL(10,3) NULL,
  -- Phase 9E (Product Weight & Variation SKU Logic) - 'inherit' (default) uses the parent
  -- product's weight_grams; 'custom' uses this row's own `weight` above (reused, not a new
  -- weight_grams column) - same shape as price_mode/custom_price below. See
  -- variation_effective_weight() in includes/product_variations.php.
  weight_mode VARCHAR(20) NOT NULL DEFAULT 'inherit',
  price_mode VARCHAR(20) NOT NULL DEFAULT 'inherit',
  custom_price DECIMAL(12,2) NULL,
  cost_price DECIMAL(12,2) NULL DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  is_system_generated TINYINT(1) NOT NULL DEFAULT 1,
  archived_at TIMESTAMP NULL,
  woocommerce_variation_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_variations_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  INDEX idx_product_variations_product (product_id),
  INDEX idx_product_variations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The generated attribute combination for one variation, e.g. Character=Hello Kitty + Color=Pink.
CREATE TABLE IF NOT EXISTS product_variation_attribute_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variation_id INT UNSIGNED NOT NULL,
  attribute_id INT UNSIGNED NOT NULL,
  attribute_value_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_variation_attr_values_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE CASCADE,
  CONSTRAINT fk_variation_attr_values_attribute FOREIGN KEY (attribute_id) REFERENCES product_attributes(id) ON DELETE CASCADE,
  CONSTRAINT fk_variation_attr_values_value FOREIGN KEY (attribute_value_id) REFERENCES product_attribute_values(id) ON DELETE CASCADE,
  UNIQUE KEY uq_variation_attribute (variation_id, attribute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- image_type distinguishes the product's single main image, its gallery images
-- (both variation_id IS NULL), and a variation's own image (variation_id IS NOT NULL).
-- image_path stores a path relative to the app root (e.g. "uploads/products/xxxx.webp"),
-- written by includes/image_upload.php - never a manually-typed URL.
CREATE TABLE IF NOT EXISTS product_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  image_type VARCHAR(20) NOT NULL DEFAULT 'gallery',
  image_path VARCHAR(500) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_images_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE CASCADE,
  INDEX idx_product_images_lookup (product_id, variation_id, image_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reusable attribute/value presets (e.g. "Sanrio Character Collection" = Character:
-- Hello Kitty, My Melody, Kuromi). Applying a template COPIES its selections into the
-- product's own product_attribute_assignments/product_attribute_assignment_values rows -
-- it is never a live link, so editing a template later never reshuffles products that
-- already used it.
CREATE TABLE IF NOT EXISTS variation_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS variation_template_attributes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  attribute_id INT UNSIGNED NOT NULL,
  is_variation_attribute TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_template_attributes_template FOREIGN KEY (template_id) REFERENCES variation_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_template_attributes_attribute FOREIGN KEY (attribute_id) REFERENCES product_attributes(id) ON DELETE CASCADE,
  UNIQUE KEY uq_template_attribute (template_id, attribute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS variation_template_attribute_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_attribute_id INT UNSIGNED NOT NULL,
  attribute_value_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_template_attr_values_template_attribute FOREIGN KEY (template_attribute_id) REFERENCES variation_template_attributes(id) ON DELETE CASCADE,
  CONSTRAINT fk_template_attr_values_value FOREIGN KEY (attribute_value_id) REFERENCES product_attribute_values(id) ON DELETE CASCADE,
  UNIQUE KEY uq_template_attribute_value (template_attribute_id, attribute_value_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_memberships (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  membership_tier_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  expiry_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_customer_memberships_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS membership_tiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  upgrade_points INT UNSIGNED NOT NULL DEFAULT 0,
  duration_months INT UNSIGNED NOT NULL DEFAULT 1,
  monthly_voucher_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  birthday_voucher_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  free_shipping_threshold DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  early_bird_access TINYINT(1) NOT NULL DEFAULT 0,
  early_bird_discount DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  birthday_gift_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS point_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  transaction_type VARCHAR(30) NOT NULL,
  amount INT NOT NULL DEFAULT 0,
  order_id INT UNSIGNED NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_point_transactions_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS birthday_rewards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  membership_tier_id INT UNSIGNED NOT NULL,
  days_before_birthday INT UNSIGNED NOT NULL DEFAULT 14,
  valid_days INT UNSIGNED NOT NULL DEFAULT 30,
  voucher_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  gift_enabled TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_birthday_rewards_tier FOREIGN KEY (membership_tier_id) REFERENCES membership_tiers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS birthday_reward_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  reward_type VARCHAR(30) NOT NULL,
  issued_date DATE NOT NULL,
  expiry_date DATE NOT NULL,
  used_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_birthday_reward_logs_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_credit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_store_credit_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  UNIQUE KEY uq_store_credit_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_credit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  type VARCHAR(30) NOT NULL,
  reference VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_store_credit_logs_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(100) NOT NULL UNIQUE,
  order_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Finance & Accounting Phase A (docs/FINANCE_DATABASE_DESIGN.md) - replaces the `expenses`
-- shape below, which was pure unused scaffolding (confirmed by audit: zero application code
-- ever read/wrote it). One level of self-referencing nesting by default (e.g. "Operations" as
-- parent of "Packaging"/"Shipping"/etc.) - see install.php for the seeded default list.
CREATE TABLE IF NOT EXISTS expense_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  parent_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_expense_categories_parent FOREIGN KEY (parent_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
  INDEX idx_expense_categories_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Finance & Accounting Phase B (docs/FINANCE_DATABASE_DESIGN.md) - reference accounts only.
-- This is NOT statement import/reconciliation/sync; just explicit transaction-account tagging.
CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  account_type VARCHAR(20) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'MYR',
  notes VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bank_accounts_active_name (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lifecycle: draft -> paid -> archived (docs/FINANCE_ACCOUNTING_ARCHITECTURE.md §15). Draft and
-- Paid both count toward Profit & Loss (accrual, by expense_date); only Paid counts toward Cash
-- Flow (cash basis). Archived is a visibility state only, never a financial exclusion.
-- Phase B adds bank_account_id once bank_accounts exists; nullable by design so every
-- pre-Phase-B expense remains valid without data backfill.
CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NULL,
  bank_account_id INT UNSIGNED NULL,
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
  CONSTRAINT fk_expenses_bank_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_expenses_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_expenses_date (expense_date),
  INDEX idx_expenses_category (category_id),
  INDEX idx_expenses_bank_account (bank_account_id),
  INDEX idx_expenses_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Storage mechanics reuse includes/receipt_storage.php unchanged (private directory,
-- .htaccess-denied, finfo-validated MIME, random on-disk filename) - this table only records
-- the metadata that function already expects a caller to persist, same as payment_receipts.
CREATE TABLE IF NOT EXISTS expense_attachments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manual Income is intentionally narrow: non-order income only.
CREATE TABLE IF NOT EXISTS manual_income (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Finance & Accounting Phase C (docs/FINANCE_DATABASE_DESIGN.md) - operational asset register.
-- An asset is NOT an expense: a purchase that retains value beyond the current period lives
-- here instead of in `expenses`, never in both (docs/FINANCE_ACCOUNTING_ARCHITECTURE.md §6).
-- Deliberately NOT an accounting module - no depreciation, no capital allowance, no ledger
-- entries. This tracks what the business owns, where it is, and who holds it.
--
-- asset_code is optional and user-entered - no numbering engine, by explicit decision. The
-- UNIQUE key permits many NULL rows (MariaDB does not treat NULLs as equal) while preventing
-- two assets from sharing the same non-NULL code, which is exactly the "optional but unique
-- when present" semantic wanted here. It is also the race-condition backstop behind the
-- application's own pre-insert duplicate check (same belt-and-braces approach already used
-- for supplier_orders.purchase_number).
--
-- assigned_to is the CUSTODIAN - which user currently holds/is responsible for the asset -
-- not legal ownership. Every label in the UI says "Assigned To" for that reason.
CREATE TABLE IF NOT EXISTS assets (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Same shape and storage mechanics as expense_attachments - includes/receipt_storage.php is
-- reused unchanged (private directory, .htaccess-denied, finfo-validated MIME, random on-disk
-- filename); this table only records the metadata that function expects a caller to persist.
-- Two small single-purpose tables rather than one polymorphic attachments table, matching this
-- codebase's total absence of polymorphic-FK precedent (docs/FINANCE_DATABASE_DESIGN.md §8).
CREATE TABLE IF NOT EXISTS asset_attachments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 9B (Notification & Alert Center) added reference_id below - this table already
-- existed (scaffolded, never wired up anywhere in the app until now) with everything else
-- Phase 9B needed: type, message, read_status, created_at. reference_id is a plain nullable
-- INT UNSIGNED with NO foreign key, deliberately - the entity it points to depends on `type`
-- (a product for inventory_risk/cost_increase, a supplier for supplier_delay, a supplier_order
-- for supplier_order_overdue), so it can never reference a single table. Same polymorphic-
-- reference convention already established by sync_logs.reference_id above.
-- Phase 9C (Notification Actions & Lifecycle) added resolved_status/resolved_at - the
-- three-state lifecycle (Active/Acknowledged/Resolved) is derived from the existing
-- read_status combined with these two new columns (Active = unread+unresolved, Acknowledged =
-- read+unresolved, Resolved = resolved regardless of read_status) - read_status itself is
-- unchanged. Old notifications are never deleted; resolving only sets these two columns.
CREATE TABLE IF NOT EXISTS mewmii_notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'info',
  reference_id INT UNSIGNED NULL,
  read_status TINYINT(1) NOT NULL DEFAULT 0,
  resolved_status TINYINT(1) NOT NULL DEFAULT 0,
  resolved_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mewmii_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_mewmii_notifications_type_reference_read (type, reference_id, read_status),
  INDEX idx_mewmii_notifications_type_reference_resolved (type, reference_id, resolved_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sync_type VARCHAR(50) NOT NULL,
  reference_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'success',
  error_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  -- Phase 6D (Production Hardening audit) - matches wc_client_get_last_sync_log()'s own
  -- WHERE sync_type = ? AND reference_id = ? ... ORDER BY id DESC, and the Sync Logs list's
  -- ORDER BY created_at - previously a full table scan on both.
  INDEX idx_sync_logs_type_reference_created (sync_type, reference_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration Management System v1 (see docs/MIGRATION_MANAGEMENT_PLAN.md) - tracks which of
-- database/migrate_*.php has actually been run against THIS database, closing the gap that
-- caused a real production incident (see docs/MIGRATION_SYSTEM_AUDIT.md): migration scripts
-- existing in the repo were being treated as equivalent to having actually been executed.
-- Keyed by filename (not a generated sequence) so none of the existing migrate_*.php scripts
-- ever need renaming. Populated/read exclusively by database/migrate.php - nothing else
-- writes to this table. A fresh install already has this table via this file; an existing
-- database gets it the first time database/migrate.php runs (CREATE TABLE IF NOT EXISTS,
-- same idempotent pattern every migrate_*.php script already uses).
CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  migration VARCHAR(191) NOT NULL UNIQUE,
  status ENUM('success', 'failed') NOT NULL DEFAULT 'success',
  -- SHA-256 of the migration file's contents at the time it was run - lets database/migrate.php
  -- detect and warn if an already-applied script is edited afterward, rather than silently
  -- trusting a stale record (a script that changes after being marked applied is never
  -- auto-re-run; it's flagged for human review instead - see the design doc).
  checksum CHAR(64) NULL,
  executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  execution_time_ms INT UNSIGNED NULL,
  -- 'cli' for this v1 (CLI-only) runner - reserved for a future admin identity if a browser
  -- path is ever added.
  executed_by VARCHAR(100) NULL,
  -- Populated only when status = 'failed' - the captured output of the failed run.
  error_message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 6E (WooCommerce webhook integration) - the received-event queue for
-- modules/webhooks/woocommerce.php (receiver) / cli/wc_webhook_process.php (processor).
-- Distinct from sync_logs, which stays the per-item outcome log every sync entry point
-- (including webhook processing) already writes to. woocommerce_delivery_id is nullable -
-- InnoDB allows any number of NULLs in a UNIQUE index (same convention as
-- customers.uq_customers_woocommerce_customer_id) - if WooCommerce ever omits that header,
-- duplicate detection falls back to payload_hash instead of relying solely on this column.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 11A (Unified Outbound Job Queue) - generic background job queue for outbound
-- integration work (WooCommerce push today; email/notifications/supplier sync/purchase order
-- generation/AI tasks are future job `type` values - see includes/job_queue.php). Same
-- reliability shape as webhook_events above (row-locked claim, stale-processing recovery,
-- exponential backoff, cleanup), generalized so no future job type ever needs a schema change -
-- `type` is a plain string, `payload_json` carries whatever that type's handler needs.
CREATE TABLE IF NOT EXISTS outbound_jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- Dotted namespace, e.g. 'woocommerce.product.sync' - a handler is registered for each type
  -- by the worker (cli/job_worker.php), never by this table/library.
  type VARCHAR(100) NOT NULL,
  -- entity_type/entity_id identify the LOCAL Mewmii OS record this job is about (e.g.
  -- 'product'/123) - optional, since not every future job type is about one specific record
  -- (e.g. a cleanup or digest job).
  entity_type VARCHAR(50) NULL,
  entity_id INT UNSIGNED NULL,
  payload_json LONGTEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  -- Lower runs first (same convention as Unix `nice`) - default 100 is the neutral middle,
  -- leaving room both above and below for future callers to de/prioritize without a rescale.
  priority INT NOT NULL DEFAULT 100,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  -- NULL or a past timestamp = claimable now; a future timestamp = not until then (initial
  -- scheduling AND exponential backoff after a failed attempt both use this one column).
  run_after TIMESTAMP NULL,
  locked_at TIMESTAMP NULL,
  -- Worker identifier (e.g. hostname:pid) - diagnostic only, so a stuck/crashed job's last
  -- claimant is visible; job_recover_stale_processing() uses locked_at, not this, to decide
  -- what is abandoned.
  locked_by VARCHAR(100) NULL,
  completed_at TIMESTAMP NULL,
  failed_at TIMESTAMP NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_outbound_jobs_status_priority_run_after (status, priority, run_after),
  INDEX idx_outbound_jobs_type (type),
  INDEX idx_outbound_jobs_entity (entity_type, entity_id),
  INDEX idx_outbound_jobs_locked_at (locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS saved_views (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module VARCHAR(40) NOT NULL,
  name VARCHAR(100) NOT NULL,
  query_string VARCHAR(1000) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_saved_views_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_saved_views_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mewmii_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  woocommerce_order_id BIGINT UNSIGNED NULL,
  order_number VARCHAR(100) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NULL,
  payment_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  payment_date DATE NULL,
  payment_method VARCHAR(50) NULL,
  receipt_url VARCHAR(500) NULL,
  order_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  is_preorder_request TINYINT(1) NOT NULL DEFAULT 0,
  receipt_status VARCHAR(20) NULL,
  receipt_reject_reason TEXT NULL,
  is_historical TINYINT(1) NOT NULL DEFAULT 0,
  shipping_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  tracking_number VARCHAR(100) NULL,
  shipping_carrier VARCHAR(50) NULL,
  shipped_at DATETIME NULL,
  fulfillment_date DATE NULL,
  notes TEXT NULL,
  customer_note TEXT NULL,
  internal_note TEXT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  order_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mewmii_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  INDEX idx_mewmii_orders_payment_status (payment_status),
  INDEX idx_mewmii_orders_order_status (order_status),
  INDEX idx_mewmii_orders_order_date (order_date),
  INDEX idx_mewmii_orders_created_at (created_at),
  -- Dedup key for the WooCommerce importer's "does this order already exist" lookup (see
  -- includes/wc_order_import.php). InnoDB unique indexes allow any number of NULL values -
  -- manually created orders (order_number 'ORD-...') never set this column and are unaffected.
  UNIQUE KEY uq_mewmii_orders_woocommerce_order_id (woocommerce_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mewmii_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  variation_label VARCHAR(255) NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  cost_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_mewmii_order_items_order FOREIGN KEY (order_id) REFERENCES mewmii_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_mewmii_order_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_mewmii_order_items_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mewmii_order_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mewmii_order_events_order FOREIGN KEY (order_id) REFERENCES mewmii_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_mewmii_order_events_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer Order Resolution System - purely additive, no existing table altered. See
-- includes/order_resolution.php's own docblock for the "keep resolution status separate"
-- reasoning (mewmii_orders.order_status/mewmii_order_items are untouched).
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_wallets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency VARCHAR(10) NOT NULL DEFAULT 'MYR',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_wallets_customer (customer_id),
  CONSTRAINT fk_customer_wallets_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  purchase_number VARCHAR(100) NOT NULL UNIQUE,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  is_historical TINYINT(1) NOT NULL DEFAULT 0,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
  estimated_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  actual_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  -- Phase 6B (Supplier Order currency) - the supplier's own quoted currency and the rate used
  -- to convert it into MYR, mirroring products.cost_currency/exchange_rate's existing
  -- convention. estimated_cost/actual_cost/shipping_fee/supplier_order_items.supplier_price
  -- stay MYR exactly as before (see supplier_order_items.unit_cost_myr below) - this is the
  -- audit trail for how that MYR figure was derived, not a second source of truth for it.
  -- exchange_rate NULL (default for a fresh 'MYR' order) means "no conversion needed", same as
  -- products.exchange_rate - read as COALESCE(exchange_rate, 1) everywhere it's used.
  currency VARCHAR(10) NOT NULL DEFAULT 'MYR',
  exchange_rate DECIMAL(12,6) NULL,
  foreign_total DECIMAL(12,2) NULL,
  payment_date DATE NULL,
  order_date DATE NULL,
  expected_delivery_date DATE NULL,
  received_date DATE NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_supplier_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  INDEX idx_supplier_orders_status (status),
  INDEX idx_supplier_orders_payment_status (payment_status),
  INDEX idx_supplier_orders_expected_delivery_date (expected_delivery_date),
  -- SO-D - order_date is sorted on by every batch landed-cost calculation
  -- (includes/product_cost.php's reference-line and purchase-cost lookups, which run on the
  -- Margin Report, Inventory Report, Purchasing, and cost-increase alerts) and by the supplier
  -- purchase-history views. It was the only one of those sort keys without an index.
  INDEX idx_supplier_orders_order_date (order_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_order_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_order_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_supplier_order_events_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_order_events_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  customer_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  moq_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  top_up_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  total_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  supplier_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  -- Phase 6B (Supplier Order currency) - unit_cost_foreign is this line's unit cost exactly as
  -- entered, in the parent order's supplier_orders.currency; unit_cost_myr is that same cost
  -- converted to MYR (unit_cost_foreign * COALESCE(supplier_orders.exchange_rate, 1)) and is
  -- always equal to supplier_price - kept as its own column purely so a line's currency
  -- conversion is visible without re-deriving it, never a second value supplier_price could
  -- drift from. Both NULL for a plain MYR line (supplier_price alone remains authoritative
  -- everywhere else - receiving, inventory valuation, cost-change logging - exactly as before).
  unit_cost_foreign DECIMAL(12,2) NULL,
  unit_cost_myr DECIMAL(12,2) NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  -- Costing prep (Sprint 13) - this line's share of its PO's shipping_fee; not yet
  -- computed/used anywhere. See products.exchange_rate's comment above for the intended
  -- landed-cost formula.
  shipping_allocated DECIMAL(12,2) NULL,
  CONSTRAINT fk_supplier_order_items_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_order_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_supplier_order_items_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 7F (Additional Costs Framework) - arbitrary named costs (Customs, Handling,
-- Packaging, Other, or anything else an operator types) allocated to a specific supplier
-- order line, the same grain shipping_allocated already uses above - a customs/handling fee
-- is tied to a specific shipment/import event, not a fixed property of the product itself,
-- so it lives here rather than as new columns on `products`. cost_type is a free VARCHAR, not
-- an ENUM/lookup table, so a new cost type never needs a schema change - "do not hardcode
-- only one type" is satisfied by the row shape itself (many rows per item, one per cost),
-- not by a fixed list of columns. One item can have several rows (Customs AND Handling on
-- the same line), unlike shipping_allocated which is a single value per item.
CREATE TABLE IF NOT EXISTS supplier_order_item_costs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_order_item_id INT UNSIGNED NOT NULL,
  cost_type VARCHAR(50) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_supplier_order_item_costs_item FOREIGN KEY (supplier_order_item_id) REFERENCES supplier_order_items(id) ON DELETE CASCADE,
  INDEX idx_supplier_order_item_costs_item (supplier_order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 8C (Product Cost History) - a FROZEN snapshot of includes/product_cost.php's Landed
-- Cost breakdown at the moment a supplier order is received or completed. Required because
-- every input to that formula can change AFTER the fact and product_cost_calculate_batch() is
-- always a LIVE recalculation, never a stored value:
--   - products.cost_currency/exchange_rate can be edited later (Phase 7C.1).
--   - supplier_order_items.shipping_allocated can be entered/changed after receiving, since
--     Shipping Allocation (Phase 7D) has no ordering dependency on the receiving flow.
--   - supplier_order_item_costs rows (Phase 7F) can likewise be added/removed at any time.
-- Without a snapshot, "what did this cost land at when we received it" would silently drift
-- every time any of those change - this table exists purely so a past receiving event's cost
-- stays exactly what it was, forever. Never updated after insert, never read by
-- product_cost_calculate_batch()/product_cost_calculate() themselves (those two remain pure,
-- live, unchanged) - only modules/reports/cost_history.php and modules/products/view.php's
-- Cost History section read this table.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment history against a supplier order - purely additive (add/delete rows), never
-- overwrites supplier_orders' own total. Paid Amount is always SUM(amount) over this table
-- computed live, never a cached column, so it can never drift from the actual entries.
CREATE TABLE IF NOT EXISTS supplier_order_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_order_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_date DATE NULL,
  payment_method VARCHAR(50) NULL,
  -- SO-B - which account the money actually left, for reconciliation-readiness. Same shape and
  -- rationale as expenses.bank_account_id / manual_income.bank_account_id (Finance Phase B):
  -- nullable so no historical payment needs backfilling, ON DELETE SET NULL so removing an
  -- account never destroys a payment record. Complementary to payment_method above, not a
  -- replacement - bank_account_id points at a known account, payment_method stays as the
  -- lighter free-text fallback for a one-off not worth registering. This is TAGGING ONLY: no
  -- balance is stored or derived from it, and paid/remaining are still computed live from
  -- SUM(amount) exactly as before.
  bank_account_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_supplier_order_payments_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_order_payments_bank_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_supplier_order_payments_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_supplier_order_payments_order (supplier_order_id),
  INDEX idx_supplier_order_payments_bank_account (bank_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SO-C (Multi-Supplier Sourcing) - a SOURCING CATALOGUE: who can supply a product/variation, at
-- what quoted price, under what supplier SKU, in what currency. One row per
-- (product, variation, supplier).
--
-- Three boundaries this table deliberately respects:
--
--  1. products.supplier_id remains the PREFERRED supplier and is unchanged. Purchase planning
--     still groups reorder needs by it (includes/purchase_planning.php), the product form still
--     sets it, and nothing here overrides it. `priority` ranks ALTERNATIVES for a human choosing
--     where to buy; it is not a second source of truth for "the" supplier.
--
--  2. unit_cost is QUOTATION DATA ONLY. It must never enter the landed-cost chain, which stays
--     exactly as SO-A1/SO-A2 established it: actual PO line cost -> landed cost ->
--     product_cost_history. Nothing in includes/product_cost.php reads this table, by design -
--     otherwise there would be two competing answers to "what does this product cost".
--
--  3. Purchase HISTORY is not stored here. It is already derivable from supplier_order_items
--     joined to supplier_orders (real supplier, real price, real currency, real date), so a
--     history table would duplicate data that exists - see supplier_product_purchase_history().
--
-- moq is supplier-specific and intentionally NOT wired into purchasing logic yet (products.moq
-- remains authoritative everywhere); the column exists now because retrofitting it later would
-- mean touching data rather than just schema.
CREATE TABLE IF NOT EXISTS supplier_products (
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
  -- variation_key collapses NULL to 0 so the unique key still enforces "at most one row per
  -- (product, variation, supplier)" for simple products too - same generated-column technique
  -- mewmii_inventory already uses for exactly this reason.
  variation_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(variation_id, 0)) STORED,
  CONSTRAINT fk_supplier_products_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_products_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_products_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_supplier_products_unit_supplier (product_id, variation_key, supplier_id),
  INDEX idx_supplier_products_supplier (supplier_id),
  INDEX idx_supplier_products_product_priority (product_id, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Simple product: inventory row has variation_id = NULL (one row per product).
-- Variable product: inventory lives on each variation's own row (variation_id set);
-- the parent product never gets its own directly-stored row - its "stock" is always
-- computed as a SUM across its variations (see product_effective_stock() in
-- includes/inventory.php). variation_key collapses NULL to 0 so the unique key still
-- enforces "at most one row per simple product" the same way the old product-only
-- unique key did.
CREATE TABLE IF NOT EXISTS mewmii_inventory (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  variation_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(variation_id, 0)) STORED,
  available_quantity INT NOT NULL DEFAULT 0,
  reserved_quantity INT NOT NULL DEFAULT 0,
  incoming_quantity INT NOT NULL DEFAULT 0,
  arrived_quantity INT NOT NULL DEFAULT 0,
  customer_storage_quantity INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mewmii_inventory_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_mewmii_inventory_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE CASCADE,
  UNIQUE KEY uq_inventory_product_variation (product_id, variation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  transaction_type VARCHAR(50) NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  reason VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  balance_after INT NULL,
  reference_type VARCHAR(50) NULL,
  reference_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_transactions_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_transactions_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE SET NULL,
  INDEX idx_inventory_transactions_reference (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_storage (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  order_item_id INT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'stored',
  arrival_date DATE NULL,
  storage_location VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_customer_storage_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_storage_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_customer_storage_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id),
  CONSTRAINT fk_customer_storage_order_item FOREIGN KEY (order_item_id) REFERENCES mewmii_order_items(id) ON DELETE SET NULL,
  INDEX idx_customer_storage_customer (customer_id),
  INDEX idx_customer_storage_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- request_number ("SB-0001") is the customer-facing box-request reference - distinct from
-- a shipment_number, which identifies the physical package created once this request ships
-- (see shipments.source_type = 'ship_my_box'). Nullable/UNIQUE (MySQL allows multiple NULLs
-- in a unique index) so it never blocks pre-migration rows on an existing install.
CREATE TABLE IF NOT EXISTS ship_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_number VARCHAR(100) NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  shipping_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  weight DECIMAL(10,2) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ship_requests_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  INDEX idx_ship_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- order_id/order_item_id are denormalized from customer_storage.order_item_id at insert
-- time, purely for query/reporting convenience (e.g. "which orders does this ship request
-- touch") - customer_storage_id remains the one authoritative link every write path uses.
CREATE TABLE IF NOT EXISTS ship_request_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ship_request_id INT UNSIGNED NOT NULL,
  customer_storage_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL,
  order_item_id INT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_ship_request_items_request FOREIGN KEY (ship_request_id) REFERENCES ship_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_ship_request_items_storage FOREIGN KEY (customer_storage_id) REFERENCES customer_storage(id),
  CONSTRAINT fk_ship_request_items_order FOREIGN KEY (order_id) REFERENCES mewmii_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_ship_request_items_order_item FOREIGN KEY (order_item_id) REFERENCES mewmii_order_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Unified shipment/tracking record - the ONE place carrier/tracking_number live for every
-- physical package leaving the warehouse, regardless of how it was assembled. source_type
-- distinguishes what produced it:
--   'order'       - source_id = mewmii_orders.id (ready-stock/preorder items shipped
--                    straight off one order, see modules/shipments/create.php)
--   'ship_my_box' - source_id = ship_requests.id (a customer's combined-storage request)
--   'manual'      - source_id NULL (replacement/warranty shipment not tied to any existing
--                    order or ship request)
-- No FK on source_id since it's polymorphic - same convention as
-- inventory_transactions.reference_type/reference_id elsewhere in this schema.
CREATE TABLE IF NOT EXISTS shipments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_number VARCHAR(100) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  source_type VARCHAR(20) NOT NULL,
  source_id INT UNSIGNED NULL,
  carrier VARCHAR(50) NULL,
  tracking_number VARCHAR(100) NULL,
  shipping_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  shipped_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_shipments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  INDEX idx_shipments_source (source_type, source_id),
  INDEX idx_shipments_customer (customer_id),
  INDEX idx_shipments_status (shipping_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contents of one shipment. order_id/order_item_id/customer_storage_id are all nullable
-- since a 'manual' shipment has none of them, an 'order'-sourced ready-stock line has no
-- customer_storage_id, and a 'ship_my_box'-sourced line has no order_id if that storage lot
-- was never tied to an order (see customer_storage.order_item_id being nullable already).
CREATE TABLE IF NOT EXISTS shipment_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL,
  order_item_id INT UNSIGNED NULL,
  customer_storage_id INT UNSIGNED NULL,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_shipment_items_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
  CONSTRAINT fk_shipment_items_order FOREIGN KEY (order_id) REFERENCES mewmii_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_shipment_items_order_item FOREIGN KEY (order_item_id) REFERENCES mewmii_order_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_shipment_items_storage FOREIGN KEY (customer_storage_id) REFERENCES customer_storage(id) ON DELETE SET NULL,
  CONSTRAINT fk_shipment_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_shipment_items_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id),
  INDEX idx_shipment_items_shipment (shipment_id),
  INDEX idx_shipment_items_order_item (order_item_id),
  INDEX idx_shipment_items_storage (customer_storage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shipment lifecycle history, mirroring mewmii_order_events/supplier_order_events exactly -
-- append-only, one row per transition (shipment_created, packed, shipped, delivered,
-- cancelled, tracking_updated, carrier_changed).
CREATE TABLE IF NOT EXISTS shipment_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_shipment_events_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
  CONSTRAINT fk_shipment_events_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_shipment_events_shipment (shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
