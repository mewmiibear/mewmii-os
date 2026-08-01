# Implementation Status

Tracks the status of active improvement work, per module. Status values: Not Started, Planning, In Progress, Testing, Completed, Blocked.

## Finance & Accounting

| Item | Status | Notes |
|---|---|---|
| Current-system finance capability audit | Completed | Full codebase audit — see `docs/FINANCE_ACCOUNTING_ARCHITECTURE.md` §1. Found rich existing revenue/COGS/supplier-payment/refund/currency data, and confirmed-zero shipping-cost/gateway-fee/expense/asset/tax capability |
| `docs/FINANCE_ACCOUNTING_ARCHITECTURE.md` | Completed | Architecture, navigation (2 refinements to the user's proposal), Expense-vs-Asset rule, integration points, P&L-vs-Cash-Flow distinction, future-compatibility |
| `docs/FINANCE_WORKFLOW.md` | Completed | Workflow-First-principled walkthroughs for every Finance workflow, tied to existing pages wherever one already exists |
| `docs/FINANCE_DATABASE_DESIGN.md` | Completed | Design sketch only — no migration file, `database/schema.sql` untouched. Proposes `expense_categories`/`expenses`/`assets`/attachment tables/`bank_accounts`/`manual_income`, recommends replacing (not retrofitting) the dead `expenses`/`invoices` scaffolding |
| `docs/TAX_REPORTING_DESIGN.md` | Completed | 6 LHDN-oriented report designs, information organization only — no tax calculation logic |
| **Finance implementation (any code, migration, or schema change)** | **Not Started — awaiting approval** | Explicit user instruction: design phase only. No code was written for this phase |

## Mewmii OS v2

| Item | Status | Notes |
|---|---|---|
| System-wide design review (navigation, per-module UX, cross-module transitions, global components, performance, scalability) | Completed | Produced across three chat-only design passes; consolidated into the documents below |
| `docs/MEWMII_OS_V2_PLAN.md` | Completed | Master plan — philosophy, Workflow First principle (4 named workflows), UX north star, Phase 1/2/3 roadmap |
| `docs/DASHBOARD_PHILOSOPHY.md` | Completed | Mission Control governing philosophy — permanent reference for all future dashboard changes |
| `docs/COMPONENT_LIBRARY_SPEC.md` | Completed | Drawer, Activity Feed viewer, Bulk Actions (extended), Command Palette, Notification Badge — each with location, AJAX pattern, permissions, mobile behavior, empty/loading/error states |
| `docs/FUTURE_MULTI_WAREHOUSE.md` | Completed | Design-only; not scheduled. Confirms zero warehouse/location concept exists today |
| `docs/FUTURE_RBAC.md` | Completed | Design-only; not scheduled. Mechanism (`roles`/`permissions`/`app_has_permission()`) already exists; only management UI and multiple seeded roles are missing |
| `docs/FUTURE_API_LAYER.md` | Completed | Design-only; not scheduled. Confirms zero general-purpose `/api/` surface exists today (WooCommerce sync is the only external-facing integration) |
| `docs/PHASE1_READINESS_REVIEW.md` | Completed | Architecture compatibility, database impact (none required), shared component locations, exact implementation order, testing requirements — gate passed before any Phase 1 code changed |
| **Phase 1 Step 1 — Navigation consolidation** | Completed | `includes/header.php`: added the orphaned Purchase Planning link (gated `supplier-orders.manage`, matching the page's own check), split System into Integrations/System sub-groups. No file moved/renamed — no bookmarked URL affected. Tested: routes, permission gating (verified against a real seeded `Limited` role), active-state highlighting |
| **Phase 1 Step 2 — Notification badge** | Completed | `includes/header.php`: reuses the pre-existing `notification_unread_count()` — zero new query/table. Tested end-to-end against a throwaway DB: count accuracy, zero-state (badge absent), permission gating |
| **Phase 1 Step 3 — Dashboard Mission Control** | Completed | `index.php` fully rewritten per `docs/DASHBOARD_PHILOSOPHY.md`: Status Line (3-tier rule-based health), My Day (6 of 7 named task sources — open customer resolutions deferred pending an admin list page), Today's Business (now genuinely today/this-month, not a 30-day window). Tested against seeded real data: health-tier transitions (healthy → attention → critical), all 5 My Day task types, Today's Business figures — all verified correct via live HTTP requests |
| Overdue-supplier-orders dedup (`includes/purchase_planning.php`, `includes/notifications.php`) | Completed | New `supplier_orders_overdue()` function; both `index.php` and the `supplier_order_overdue` alert generator now call it instead of each maintaining a copy of the same predicate |
| **Bug fix found during Phase 1 testing:** `purchase_planning_needs()` crash | Completed | Pre-existing bug, unrelated to Phase 1 itself — fatally errored whenever a ready-stock product's `available_quantity` exceeded its `target_stock_level` (unsigned-subtraction overflow under MariaDB strict mode). Affects 3 call sites (dashboard, `modules/purchase-planning/generate.php`, `modules/inventory/index.php`'s needs-ordering filter). Fixed via `CAST(... AS SIGNED)` on the affected operands — no change to which rows are returned for any previously-working case |
| `docs/PHASE2_READINESS_REVIEW.md` | Completed | Audited existing modal/AJAX/JS/CSS/permission/responsive patterns; determined the Drawer should be built on `bootstrap.Offcanvas` (already loaded, unused) and content endpoints should return HTML fragments, not JSON — gate passed before Phase 2 code changed |
| **Phase 2 Step 1 — Shared Drawer framework** | Completed | `assets/js/drawer.js` (`window.DrawerUI.open()`/`.close()`), `#app-drawer` Offcanvas container + CSS in `includes/header.php`, new `ajax_require_permission_html()` in `includes/ajax_helpers.php`. Strict 3-layer architecture (Framework → Controller → View) per explicit user refinement — see `docs/PHASE2_IMPLEMENTATION.md` |
| **Phase 2 Step 2 — Inventory pilot** | Completed | `modules/inventory/ajax/drawer.php` (Controller) + `modules/inventory/views/drawer.php` (View, first use of the new `modules/<domain>/views/` convention) + a Quick View trigger on both the simple-product and variation rows in `modules/inventory/index.php`. Related actions reuse the page's existing `InventoryUI.openAdjustModal()`/`openHistoryModal()` directly — zero duplicated modal/history logic |
| New function (`includes/inventory.php`) | Completed | `inventory_transactions_recent()` — small, unenriched read for the Drawer's history preview; the full resolved history stays exclusively in the existing History modal, reused not duplicated |
| **Bug caught before shipping (Phase 2):** `drawer.js` status-code handling | Completed | Initial version only special-cased 401/403 as "displayable content"; the Controller also uses 400/404 for deliberate fragments. Caught via design-doc review before testing, fixed to treat any 2xx/4xx as displayable, confirmed by the 400/404 tests |
| Phase 2 Step 1/2 testing | Completed | Real HTTP testing against a throwaway DB: Quick View rendering, Controller output matched seeded data exactly (simple product + variation), permission denial (403, plus page-level defense-in-depth), not-found (404), missing-param (400), and a 4-page regression check on the shared `header.php` change (zero PHP errors). Not verified: real-browser Esc/focus-trap/backdrop/mobile-viewport behavior (relies on Bootstrap's own Offcanvas, not custom code) — no browser available in this environment |
| `docs/PHASE2_IMPLEMENTATION.md` | Completed | Architecture, lifecycle, file locations, reusable APIs, and a worked "how to add a Drawer to a new module" extension guide |
| Phase 2 Step 3 — Activity Feed viewer | Not Started | Explicitly deferred — user instruction: do not begin further Phase 2/3 work until this round's implementation review is complete |
| Phase 2 — Drawer expansion to other modules (Supplier Orders, Customer Orders, Products, etc.) | Not Started | Explicitly deferred — same reason |
| Phase 3 implementation (per-module redesign: Inventory → Supplier Orders → Customer Orders → Products/Catalog → Suppliers → Shipments → Reports) | Not Started | Each module goes through its own full audit → design → approval → implement → review → document cycle individually, not as one pass |

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
