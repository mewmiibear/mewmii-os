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

## Migration Management System

| Item | Status | Notes |
|---|---|---|
| Audit of all 21 existing `database/migrate_*.php` scripts | Completed | `docs/MIGRATION_SYSTEM_AUDIT.md` — full inventory, idempotency confirmed 21/21, found 4 scripts not covered by the existing `system_health.php` detection array (including the one behind this week's incident), and found all 21 scripts are reachable via unauthenticated direct URL |
| Target system design (`schema_migrations` table, runner, naming convention, safety rules) | Completed | `docs/MIGRATION_MANAGEMENT_PLAN.md` — design only |
| `schema_migrations` table | Completed | Added to `database/schema.sql`; self-bootstraps on any existing database via `database/migrate.php` |
| `database/migrate.php` runner | Completed | CLI-only (deviation from original browser+CLI sketch — see plan §2a); discovers migrations from disk, preview-only by default, `--run` to execute, records results per migration |
| **First production `--run` attempt — crashed** | **Fixed** | Crashed immediately after `migrate_additional_costs.php` with zero output and zero `schema_migrations` rows written. Root cause: `exec()` is very likely disabled on this host (`disable_functions`), and the runner didn't defensively initialize `exec()`'s output parameters, so the no-op call led to an uncaught TypeError. See `docs/MIGRATION_MANAGEMENT_PLAN.md` §2b and `docs/CHANGELOG.md` for full root-cause and fix detail. Fix verified via local reproduction (`php -d disable_functions=exec`), not yet verified against production. |
| **Run `database/migrate.php --run` against production (retry, with fix)** | **Not started — awaiting a separate go-ahead** | Explicitly not executed as part of this task, per instruction. The pre-flight check will now report clearly if `exec()` is genuinely unavailable on this host, rather than crashing — that itself is new information worth having before the next attempt. |
| `database/migrate_supplier_order_currency.php` run against production | Blocked — needs manual execution | Unchanged from Supplier Orders section above; still the one item actually blocking a live feature today. Will be resolved by the item above once approved. |

## Other modules

Not yet tracked here — `docs/CURRENT_SYSTEM_AUDIT.md` covers the full-system findings (all modules), but per-module implementation tracking has only been set up for Supplier Orders and Migration Management so far, since those are the areas actually worked on. Add a section per module here as work begins on it, rather than backfilling status for modules untouched by an actual implementation task.
