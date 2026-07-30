# Supplier Orders / Purchasing

Purpose

Convert purchasing demand (from Purchase Planning or manual entry) into tracked purchase orders, in a supplier's own currency where needed, through to receiving and payment.

Related Tables

- `supplier_orders`
- `supplier_order_items`
- `supplier_order_events`
- `supplier_order_item_costs`
- `supplier_order_payments`
- `suppliers`
- `mewmii_inventory` / `inventory_transactions` (receiving)
- `currency_rates` (exchange rate suggestion only — a PO's actual rate is captured on the order itself, not looked up live)
- `activity_logs`

Key files

- `modules/supplier-orders/{index,create,edit,view,delete,import}.php`
- `includes/supplier_orders.php` — all business logic, including the shared `supplier_order_validate_form()` used by both create and edit
- `assets/js/supplier-order-form.js`

Status: see `docs/IMPLEMENTATION_STATUS.md` for current work-in-progress detail. Full workflow description in `docs/CURRENT_SYSTEM_AUDIT.md` §5.2.
