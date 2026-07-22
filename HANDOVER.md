# Mewmii OS Project Handover

## Current completed features
- Products CRUD
- Suppliers CRUD
- Customers CRUD
- Orders workflow
- Inventory workflow
- Customer Storage
- Ship My Box
- Supplier Orders
- WooCommerce sync foundation

## Current database state
- Core tables created: users, roles, permissions, customers, suppliers, products, product_images, product_tags, product_tag_relationships, mewmii_orders, mewmii_order_items, supplier_orders, supplier_order_items, mewmii_inventory, inventory_transactions, customer_storage, sync_logs
- Important relationships:
  - products.supplier_id -> suppliers.id
  - mewmii_order_items.product_id -> products.id
  - supplier_order_items.product_id -> products.id
  - mewmii_inventory.product_id -> products.id
  - product_images.product_id -> products.id
- Known limitations:
  - Product model is still flat and does not yet support variable products or variations
  - Inventory is product-level, not variation-level
  - Catalog entities such as brands, characters, collections, categories, and tags are not yet modeled as first-class structures
  - WooCommerce mapping is still foundational and not yet aligned to simple/variable/variation models

## Product architecture problem
The current product structure is too simple for the intended catalog model. Next architecture work must cover:
- simple vs variable products
- brands
- characters (separate entity, linked to brand)
- collections/series
- categories
- tags
- variation-level SKU, price, and inventory
- WooCommerce mapping for simple products, variable products, and variations

## Exact next steps
- Start with migration planning first
- Do not make destructive schema changes
- Preserve existing product data while introducing the new catalog structure gradually
- Design parent/child product and variation model before implementation

## Files currently important
- [database/schema.sql](database/schema.sql)
- [includes/inventory.php](includes/inventory.php)
- [includes/wc_client.php](includes/wc_client.php)
- [includes/sync_log.php](includes/sync_log.php)
- [modules/products/create.php](modules/products/create.php)
- [modules/products/edit.php](modules/products/edit.php)
- [modules/products/index.php](modules/products/index.php)
- [modules/orders/create.php](modules/orders/create.php)
- [modules/orders/view.php](modules/orders/view.php)
- [modules/supplier-orders](modules/supplier-orders)
- [modules/inventory/index.php](modules/inventory/index.php)
