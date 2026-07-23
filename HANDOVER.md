# Mewmii OS Project Handover

## Current completed features
- Products CRUD, including simple vs variable products with full variation support (attributes, attribute values, variation-level SKU/price/inventory, variation templates)
- Catalog taxonomy: brands, categories, collections, tags, attributes (character is modeled as an attribute, not a separate entity)
- Suppliers CRUD
- Customers CRUD
- Orders workflow (pending -> processing -> waiting_stock -> ready_to_ship -> shipped -> completed, with cancelled as a side branch)
- Inventory workflow (available / reserved / incoming / arrived / customer_storage quantities, all product- and variation-level)
- Customer Storage
- Ship My Box
- Supplier Orders (draft -> ordered -> received/"Arrived" -> completed, with partially_received as an automatic in-flight label and cancelled as a side branch)
- Purchase Planning / Supplier Order Generation
- Historical data support (imported orders/supplier orders that never touch live inventory)
- CSV import foundation (customers, suppliers, historical customer orders, historical supplier orders, inventory opening stock)
- WooCommerce sync foundation

## Purchase Planning / Supplier Order Generation
- `includes/purchase_planning.php` computes "what needs to be bought" per sellable unit (product or variation), two separate formulas depending on `product_type`:
  - preorder/early_bird: `Need = paid customer demand (mewmii_orders.payment_status = 'paid', net of what's already gone to Customer Storage) - incoming_quantity`
  - ready_stock: `Need = products.target_stock_level - available_quantity - incoming_quantity` (skipped entirely if `target_stock_level` is NULL)
- Only units with a positive Need are surfaced; the suggested quantity is bumped up to the product's MOQ if below it (admin can still edit before generating).
- `modules/purchase-planning/generate.php` (linked from Inventory's "Generate Supplier Order" button and Supplier Orders' "Purchase Planning / Products Need Ordering" button) groups the reviewed lines by supplier and creates one `supplier_orders` row + its `supplier_order_items` per supplier via `purchase_planning_generate()`. Every line still goes through the existing `supplier_order_mark_incoming()`, so incoming stock/ledger updates happen through the one existing code path.
- `supplier_order_items.customer_quantity` / `moq_quantity` / `top_up_quantity` always sum exactly to `total_quantity`.

## Supplier Order workflow
- Linear stages: Draft -> Ordered -> Received ("Arrived") -> Completed. `partially_received` is an automatic label applied once some (not all) of an order's quantity has been received, not a manual step - "Mark Arrived" / per-line "Partial Receive" both remain available from it.
- Receiving a supplier order line moves stock from `incoming_quantity` into `available_quantity` (ready_stock) or into `arrived_quantity` pending manual allocation (preorder/early_bird) - see `supplier_order_receive_item()`.
- Cancel/Delete are only allowed before any receiving history exists.

## Historical data support
- `mewmii_orders.is_historical` and `supplier_orders.is_historical` mark records created purely for backfilling business history that predates Mewmii OS.
- Historical rows are created only via `includes/order_import.php` / `includes/supplier_order_import.php`, which never call the reservation/shipping/incoming/receiving functions - they only insert the order + item rows, so they can never move live inventory.
- Every status-changing action (receive, mark arrived, advance status, cancel, edit) is blocked server-side once `is_historical = 1`; only bookkeeping actions (e.g. supplier payments) remain available.
- Historical rows still appear in the normal Orders/Supplier Orders lists and detail pages (with a "Historical" badge) since those read straight from the same tables.

## CSV import support
- `includes/csv_import.php` is the shared CSV reader used by every import tool.
- Every import tool follows the same all-or-nothing shape: parse every row, validate every row (and cross-row rules like duplicate order/purchase numbers) into a single error list, and only call the real insert function(s) - inside one DB transaction - if there are zero errors. A file with any invalid row imports nothing.
- Import tools: `modules/customers/import.php`, `modules/suppliers/import.php`, `modules/orders/import.php` (historical customer orders), `modules/supplier-orders/import.php` (historical supplier orders), `modules/inventory/import_opening_stock.php` (opening stock baseline).

## Inventory ledger rules
- Every quantity-changing write to `mewmii_inventory` is paired, in the same function/transaction, with an `inventory_log_transaction()` call into `inventory_transactions` - there is no direct write path that bypasses the ledger (verified across every UPDATE/INSERT site in the codebase).
- `inventory_transactions.transaction_type = 'opening_stock'` is the one and only way to set a unit's historical starting balance (`inventory_import_opening_stock()`), and it refuses to run if that unit already has any transaction history, so an opening balance can never double-count real activity.
- `balance_after` is always a write-time snapshot, computed from `mewmii_inventory` inside the same transaction as the update, never reconstructed after the fact.

## Current database state
- Core tables: users, roles, permissions, customers, suppliers, products, product_variations, product_attributes, product_attribute_values, product_attribute_assignments, brands, categories, collections, product_tags, product_tag_relationships, mewmii_orders, mewmii_order_items, supplier_orders, supplier_order_items, supplier_order_payments, mewmii_inventory, inventory_transactions, customer_storage, ship_requests, sync_logs
- Important relationships:
  - products.supplier_id -> suppliers.id
  - mewmii_order_items.product_id -> products.id
  - supplier_order_items.product_id -> products.id
  - mewmii_inventory.product_id -> products.id
  - product_images.product_id -> products.id
  - product_variations.product_id -> products.id
- Known limitations:
  - WooCommerce mapping is still foundational and not yet fully aligned to the simple/variable/variation model
  - No dedicated Sales Reports, Purchase Reports, Customer Analytics, or Product Cost Timeline UI yet (see ROADMAP.md Phase 9/10) - the underlying data exists in `mewmii_orders`/`supplier_orders`/`inventory_transactions`, but there is no reporting screen built on top of it

## Files currently important
- [database/schema.sql](database/schema.sql)
- [includes/inventory.php](includes/inventory.php)
- [includes/purchase_planning.php](includes/purchase_planning.php)
- [includes/supplier_orders.php](includes/supplier_orders.php)
- [includes/orders.php](includes/orders.php)
- [includes/order_import.php](includes/order_import.php)
- [includes/supplier_order_import.php](includes/supplier_order_import.php)
- [includes/csv_import.php](includes/csv_import.php)
- [includes/wc_client.php](includes/wc_client.php)
- [includes/sync_log.php](includes/sync_log.php)
- [modules/products/create.php](modules/products/create.php)
- [modules/products/edit.php](modules/products/edit.php)
- [modules/products/index.php](modules/products/index.php)
- [modules/orders/create.php](modules/orders/create.php)
- [modules/orders/view.php](modules/orders/view.php)
- [modules/orders/import.php](modules/orders/import.php)
- [modules/purchase-planning/generate.php](modules/purchase-planning/generate.php)
- [modules/supplier-orders](modules/supplier-orders)
- [modules/inventory/index.php](modules/inventory/index.php)
- [modules/inventory/import_opening_stock.php](modules/inventory/import_opening_stock.php)
