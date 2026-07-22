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
  INDEX idx_audit_action (action)
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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catalog taxonomies. Defined before `products` because products.brand_id references brands.
CREATE TABLE IF NOT EXISTS brands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  slug VARCHAR(140) NOT NULL UNIQUE,
  woocommerce_term_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  slug VARCHAR(140) NOT NULL UNIQUE,
  parent_id INT UNSIGNED NULL,
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
  slug VARCHAR(170) NOT NULL,
  brand_id INT UNSIGNED NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  woocommerce_term_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attribute_values_attribute FOREIGN KEY (attribute_id) REFERENCES product_attributes(id) ON DELETE CASCADE,
  CONSTRAINT fk_attribute_values_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
  UNIQUE KEY uq_attribute_value (attribute_id, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  woocommerce_product_id BIGINT UNSIGNED NULL,
  sku VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  product_type VARCHAR(50) NOT NULL DEFAULT 'ready_stock',
  catalog_type VARCHAR(20) NOT NULL DEFAULT 'simple',
  brand_id INT UNSIGNED NULL,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  product_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier_id INT UNSIGNED NULL,
  moq INT UNSIGNED NOT NULL DEFAULT 1,
  sale_start_date DATE NULL,
  sale_end_date DATE NULL,
  official_release_date DATE NULL,
  estimated_arrival_date DATE NULL,
  expiry_date DATE NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'coming_soon',
  published_to_woocommerce TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
  INDEX idx_products_catalog_type (catalog_type)
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
  weight DECIMAL(10,3) NULL,
  price_mode VARCHAR(20) NOT NULL DEFAULT 'inherit',
  custom_price DECIMAL(12,2) NULL,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
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

CREATE TABLE IF NOT EXISTS product_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  image_url VARCHAR(500) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_images_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(100) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  description TEXT NULL,
  receipt_file VARCHAR(500) NULL,
  expense_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mewmii_notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'info',
  read_status TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mewmii_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sync_type VARCHAR(50) NOT NULL,
  reference_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'success',
  error_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mewmii_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  woocommerce_order_id BIGINT UNSIGNED NULL,
  order_number VARCHAR(100) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NULL,
  payment_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  payment_method VARCHAR(50) NULL,
  order_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  shipping_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  order_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mewmii_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mewmii_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  variation_label VARCHAR(255) NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
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

CREATE TABLE IF NOT EXISTS supplier_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  purchase_number VARCHAR(100) NOT NULL UNIQUE,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  estimated_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  actual_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_date DATE NULL,
  order_date DATE NULL,
  received_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_supplier_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
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
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_supplier_order_items_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_order_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_supplier_order_items_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id)
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
  reference_type VARCHAR(50) NULL,
  reference_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_transactions_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_transactions_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_storage (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'stored',
  arrival_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_customer_storage_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_storage_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_customer_storage_variation FOREIGN KEY (variation_id) REFERENCES product_variations(id),
  INDEX idx_customer_storage_customer (customer_id),
  INDEX idx_customer_storage_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ship_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  shipping_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  weight DECIMAL(10,2) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ship_requests_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  INDEX idx_ship_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ship_request_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ship_request_id INT UNSIGNED NOT NULL,
  customer_storage_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_ship_request_items_request FOREIGN KEY (ship_request_id) REFERENCES ship_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_ship_request_items_storage FOREIGN KEY (customer_storage_id) REFERENCES customer_storage(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
