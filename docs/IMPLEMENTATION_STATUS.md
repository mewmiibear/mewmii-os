# Implementation Status

Tracks the status of active improvement work, per module. Status values: Not Started, Planning, In Progress, Testing, Completed, Blocked.

## Supplier Orders

| Item | Status | Notes |
|---|---|---|
| `Failed to create supplier order.` root cause diagnosis | Completed | Was a swallowed exception; real cause found via temporary raw-exception dump (since reverted) |
| `Throwable` catch + logged errors + duplicate-key-specific message | Completed | `create.php`, `edit.php` |
| Post-commit WooCommerce resync isolated from success/failure verdict | Completed | Prevents a real success being reported as a failure |
| `purchase_number` UNIQUE constraint + race protection | Completed | `database/migrate_supplier_order_purchase_number_unique.php` (idempotent, not yet confirmed run against production) |
| Client-side double-submit guard | Completed | `assets/js/supplier-order-form.js` |
| Activity Log on creation | Completed | This change |
| Exchange rate suggestion from `currency_rates` | Completed | This change |
| Shared currency/item validation (dedup `create.php`/`edit.php`) | Completed | This change |
| **`database/migrate_supplier_order_currency.php` run against production** | **Blocked — needs manual execution** | Code has been correct since this was diagnosed; the live database is still missing `supplier_orders.currency`/`exchange_rate`/`foreign_total` and `supplier_order_items.unit_cost_foreign`/`unit_cost_myr` until this idempotent, pre-existing migration is actually run. This is the one item still blocking supplier order creation in production. |
| Taxonomy-style CRUD duplication in `wc_client.php` dual data-access surface (audit §6.4) | Not Started | Identified in audit, not scoped into this round of work |

## Other modules

Not yet tracked here — `docs/CURRENT_SYSTEM_AUDIT.md` covers the full-system findings (all modules), but per-module implementation tracking has only been set up for Supplier Orders so far, since that's the module actually worked on. Add a section per module here as work begins on it, rather than backfilling status for modules untouched by an actual implementation task.
