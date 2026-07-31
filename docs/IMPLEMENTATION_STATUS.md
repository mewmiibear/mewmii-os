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
| `database/migrate.php` runner (v1, subprocess-based) | Superseded | CLI-only subprocess design (§2a) — replaced entirely, see v2 rows below. Kept in `docs/MIGRATION_MANAGEMENT_PLAN.md` §2/§2a/§2b as historical record only. |
| First production `--run` attempt — crashed | Root cause diagnosed | Crashed immediately after `migrate_additional_costs.php`, zero output, zero `schema_migrations` rows written. Root cause: `exec()`'s output parameters weren't defensively initialized, so a disabled `exec()` led to an uncaught TypeError. This specific bug is moot now — v2 (below) removed `exec()` entirely rather than patching around it further. |
| **Production confirmed:** `exec()`/`shell_exec()`/`system()`/`passthru()`/`popen()` all disabled | Confirmed | The subprocess model could never have worked on this host regardless of further patching. Triggered the v2 architecture change. |
| HTTP-loopback execution model | Designed, then rejected | Fully specified as an alternative (new endpoint, token auth, in-process-per-request via PHP-FPM's symbol table reset) — explicitly rejected for adding infrastructure complexity to a database tool. See `docs/MIGRATION_MANAGEMENT_PLAN.md` §7. |
| **Mechanical refactor of all 21 `database/migrate_*.php` files** | Completed | Shared helpers extracted to new `database/migrate_helpers.php`; each file's logic wrapped in a uniquely-named `migrate_<name>()` function returning `['success','applied','failures','message']`; standalone-execution guard added. No SQL/business logic changed — verified by diff before changing anything, and by testing after. See `docs/MIGRATION_MANAGEMENT_PLAN.md` §7.2. |
| **`database/migrate.php` v2 (in-process execution)** | Completed | Rewritten: `require_once` + direct function call per migration, each wrapped in its own try/catch, `migrate_failures_reset()` called between migrations to prevent cross-migration state bleed. No `exec()`, no shell, no subprocess, no HTTP. Discovery/pending-detection/`schema_migrations` schema/CLI-only guard all unchanged from v1. |
| Testing (php -l, all 21 executed individually + via runner, idempotency, real `INFORMATION_SCHEMA` verification) | Completed | All passed against a local throwaway database. One bug found and fixed during testing: `migrate_helpers.php` itself was being discovered as a fake 22nd migration (glob pattern matched it too) — excluded explicitly. Full detail in `docs/CHANGELOG.md`. |
| Future rollback convention (`rollback_<name>()` naming reservation) | Documented only | Not implemented, per approved scope. Reserved in `migrate_helpers.php`/`migrate.php` docblocks and `docs/MIGRATION_MANAGEMENT_PLAN.md` §7.6 so a future migration can adopt it without restructuring. |
| **Run `database/migrate.php --run` against production** | **Not started — awaiting a separate go-ahead** | Explicitly not executed as part of any task so far. This is now the actual, working, in-process architecture — no further exec-related blockers expected, but production has not yet been touched. |
| `migrate_supplier_order_currency.php` applying to production | Blocked — needs manual execution | Unchanged from Supplier Orders section above; still the one item actually blocking a live feature today. Will be resolved once the item above is approved and run. |

## Other modules

Not yet tracked here — `docs/CURRENT_SYSTEM_AUDIT.md` covers the full-system findings (all modules), but per-module implementation tracking has only been set up for Supplier Orders and Migration Management so far, since those are the areas actually worked on. Add a section per module here as work begins on it, rather than backfilling status for modules untouched by an actual implementation task.
