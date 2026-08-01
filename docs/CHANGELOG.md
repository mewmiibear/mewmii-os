# Changelog

All notable changes to Mewmii OS are recorded here, newest first.

## Unreleased

### Mewmii OS v2 Phase 1 — Navigation consolidation, Notification badge, Dashboard Mission Control

**Reason:** implements the Phase 1 roadmap approved in `docs/MEWMII_OS_V2_PLAN.md`, gated behind a formal `docs/PHASE1_READINESS_REVIEW.md` (architecture compatibility, database impact, shared component locations, exact implementation order, testing requirements — all confirmed before any code changed). Every change below reuses an existing function/table; no new tables, columns, or permission rows were needed anywhere in this phase.

**Step 1 — Navigation consolidation (`includes/header.php`):** added the previously-orphaned `Purchase Planning` link (`modules/purchase-planning/generate.php` — reachable before only via a dashboard button, no sidebar entry) to the Operations section, gated on `supplier-orders.manage` to match the page's own `app_require_permission()` call exactly (not assumed from a sibling item's gate). Split the flat 8-item System section into `Integrations` (WooCommerce Sync, Webhook Events, Sync Logs) and `System` (the remaining 6 items) sub-labels. Grouping/labels/order only — no file was moved or renamed, so no bookmarked URL changed.

**Step 2 — Notification badge (`includes/header.php`):** the sidebar's `Notifications` link now shows an unread-count badge, reusing `notification_unread_count()` (`includes/notifications.php`, previously only ever called from `index.php`) — no new query, no new table. Absent entirely at zero unread, per the "silence is the default state" rule. Gated on the same `dashboard.view` permission the link itself already required.

**Step 3 — Dashboard Mission Control (`index.php`, full rewrite):** replaced the "Operations Command Centre" layout (5-card stat strip, Operations Overview, Purchasing Intelligence, Needs Attention, a full Notifications card, 30-day Business Snapshot with Top Products/Customer Activity) with `docs/DASHBOARD_PHILOSOPHY.md`'s three-part structure: a silent-when-healthy **Status Line** (rule-based 3-tier health), **My Day** (a live, auto-generated task list — six of the seven task sources named in the philosophy doc: overdue supplier orders, ready-to-ship orders, pending payment receipts, arrived preorder units to allocate, low-stock review, and purchase-planning-needs-by-supplier; the seventh, open customer resolutions, is deferred — no admin list page exists yet to link it to, so it feeds the health tier only, not a clickable task), and **Today's Business** (Orders/Revenue/AOV, now genuinely scoped to today + a real calendar-month teaser, not the old 30-day rolling window). Every number is either an unchanged existing function call or a plain read-only query already used elsewhere on this page before — see `docs/PHASE1_READINESS_REVIEW.md` §1 for the full reuse audit this was built against. Sections dropped per `DASHBOARD_PHILOSOPHY.md` §8's explicit "what moved off the dashboard" mapping (Top Selling Products/Business Snapshot detail → Reports; Notifications card → the new header badge + `/modules/notifications/`; Sync Health/Inventory Health permanent cards → folded silently into the Status Line) are gone rather than kept as dead weight, which also reduces the page's query count materially (the removed Purchasing Intelligence row alone ran a `demand_forecast_calculate()` pass and two `product_cost_calculate_batch()` passes over the catalog on every load).

**Deduplication (`includes/purchase_planning.php`, `includes/notifications.php`):** the overdue-supplier-orders predicate was previously copy-pasted identically in both `index.php` and `notifications.php`'s `supplier_order_overdue` alert generator. Extracted into one function, `supplier_orders_overdue(PDO $pdo): array`, that both now call — this *removes* pre-existing duplication rather than adding any.

**Bug found and fixed during testing (`includes/purchase_planning.php`), pre-existing, unrelated to the Phase 1 changes themselves:** `purchase_planning_needs()` fatally errored (`SQLSTATE[22003]: Numeric value out of range`) whenever a ready-stock product's `available_quantity` exceeded its `target_stock_level` — an entirely plausible real state (e.g. just after a large receiving event), not an edge case. Root cause: unsigned-column subtraction (`target_stock_level - available_quantity - incoming_quantity`) going negative under MariaDB strict mode. This function is called by three places (`index.php`'s dashboard, `modules/purchase-planning/generate.php`, `modules/inventory/index.php`'s needs-ordering filter) and would have crashed all three the first time this state occurred in production. Fixed by casting all three operands to `SIGNED` before subtracting — the `> 0` comparison already correctly excludes negative results either way, so this changes nothing about which rows are returned, only prevents the crash. Found via functional testing against a throwaway local database (see Testing below), not via a bug report.

**Testing performed:** a throwaway local database (`mewmii_phase1_test`, MariaDB via XAMPP) was created and torn down after use — production `.env`/`config.php` were never read or touched; DB credentials were overridden via process environment variables, which `includes/env_loader.php` never overrides once already set. `php -l` on every changed file. Standalone function-level tests for `notification_unread_count()` (zero/non-zero/read/resolved transitions, badge-visibility condition). A full install (`install.php`) seeded a real `Owner` role (all 20 permissions) and a `Limited` role (zero permissions) with real users; `app_has_permission()` was verified to return the correct boolean for both roles against every permission gate touched in Step 1/2. Logged in via real HTTP requests (PHP's built-in server) as both roles: confirmed the `Limited` user gets a 403 on `/index.php` (defense-in-depth, permission gate unchanged); confirmed the new Purchase Planning nav link renders/hides correctly and gets the `active` class on its own page; confirmed the header badge count matches `notification_unread_count()` and disappears at zero. Seeded real rows (a low-stock + purchase-planning-eligible product, a negative-stock adjustment, an overdue supplier order, a ready-to-ship order, a pending-receipt order) and confirmed via live page loads: Status Line correctly transitions healthy → attention → critical per the exact rule table in `DASHBOARD_PHILOSOPHY.md` §5; all five implemented My Day task types render with correct copy, correct link targets, correct sort order (overdue-first); Today's Business Orders/Revenue/AOV numbers matched the seeded data exactly. Mobile layout was not visually verified (no browser available in this environment) — relies on the existing `.card`/`.attention-item`/Bootstrap grid classes already responsive elsewhere on this page, no new breakpoint-specific CSS was introduced.

### Mewmii OS v2 — design documentation phase (no code changes)

**Reason:** after three rounds of chat-only design work (Dashboard v2 in three passes, then a full system-wide "System Design Phase" review), the user approved the direction and directed it be persisted as real documents under `docs/` rather than remain ephemeral — with binding requirements: preserve current philosophy (no rewrite, no unnecessary abstraction migration), add a "Workflow First" principle measured against 4 named daily workflows, confirm the Phase 1/2/3 roadmap, and require a separate forward-compatibility document each for multi-warehouse, RBAC, and API layer so current decisions don't block them later.

**Scope:** documentation only. No application code touched.

**New documents:**
- `docs/MEWMII_OS_V2_PLAN.md` — master plan: philosophy, the 4 priority workflows, UX north star (Shopify/Linear/Notion, principles not pixels), the Phase 1/2/3 roadmap, and pointers to every companion document.
- `docs/DASHBOARD_PHILOSOPHY.md` — the permanent Mission Control governing philosophy: the 3-question purpose, the "never on the dashboard" rules, My Day (rule-derived, zero new schema), Business Health (3-tier, fully rule-based), Search-first direction, and what moved off the dashboard and where.
- `docs/COMPONENT_LIBRARY_SPEC.md` — full specs for the 5 new v2 components (Drawer, Activity Feed viewer, Bulk Actions extended, Command Palette, Notification Badge), each covering where it lives, its AJAX pattern, permission handling, mobile behavior, and empty/loading/error states. Every spec was checked for reuse before being written — the Drawer reuses each module's existing `view.php` rather than adding parallel endpoints, and the Command Palette reuses Global Search's existing endpoint and render function rather than building a second search implementation.
- `docs/FUTURE_MULTI_WAREHOUSE.md`, `docs/FUTURE_RBAC.md`, `docs/FUTURE_API_LAYER.md` — design-only, explicitly not scheduled for implementation. Each documents the confirmed current gap, a sketch of the eventual shape, and — the load-bearing part — exactly what current v2 decisions must not assume so none of the three are blocked later (e.g., no component may hardcode an Owner-only check instead of `app_has_permission()`; no new business logic may be written directly inside page-rendering code instead of `includes/*.php`).

**Explicitly not done in this phase:** no implementation. Per the user's closing instruction, Phase 1 work (navigation consolidation, notification badge, Dashboard Mission Control) does not begin until this documentation set is reviewed and approved.

### Migration Management System v2 — architecture change: in-process execution (no exec/shell/subprocess/HTTP)

**Reason:** production confirmed `exec()`, `shell_exec()`, `system()`, `passthru()`, and `popen()` are all disabled — the subprocess execution model from v1 could never work there, not just occasionally fail. An HTTP-loopback alternative was designed and explicitly rejected (unnecessary infrastructure complexity — a new web endpoint, token auth, curl/loopback dependency — for a database tool). Built instead: a one-time mechanical refactor removing the actual blocker (a function-name collision), enabling true in-process execution.

**Scope:** `database/migrate_helpers.php` (new), all 21 `database/migrate_*.php` files (mechanically refactored — see below), `database/migrate.php` (rewritten). `docs/MIGRATION_MANAGEMENT_PLAN.md` (new §7/§8), `docs/IMPLEMENTATION_STATUS.md` updated.

**What changed in the 21 migration files — confirmed mechanical only, no SQL/logic change:**
- Removed each file's local declarations of shared helper functions (`migrate_run()`, `migrate_column_exists()`, `migrate_table_exists()`, `migrate_index_exists()`, `migrate_failures()` — up to 20 files duplicated these identically or near-identically) in favor of one `require_once __DIR__ . '/migrate_helpers.php';`. Genuinely migration-specific helpers (e.g. `migrate_catalog.php`'s `migrate_find_foreign_keys_on_column()`) were left in place, unshared.
- Wrapped each file's existing top-level logic, unchanged, in a uniquely-named function derived from its filename (`migrate_supplier_order_currency.php` → `function migrate_supplier_order_currency(PDO $pdo): array`), per the approved "unique execution function name" requirement.
- Each function now returns `['success' => bool, 'applied' => array, 'failures' => array, 'message' => string]` — built from each script's own pre-existing `$applied`/`$failures` variables; `message` summarizes the outcome (e.g. "3 statement(s) applied", "Already up to date", "N statement(s) failed").
- Added a standalone-execution guard (`if (!defined('MIGRATE_RUNNER_ACTIVE')) { migrate_<name>(app_db()); }`) so `php database/migrate_X.php` run directly still works exactly as before.
- **Verified, not assumed:** every shared helper's body was diffed byte-for-byte across every file that had it before touching anything. Found one real behavioral variant — 3 files' `migrate_run()` skipped recording into the failures registry — and confirmed those 3 files never read that registry either, so unifying was safe (adds unused bookkeeping only, no visible behavior change).

**`database/migrate_helpers.php` (new):** the 5 unified helpers, plus `migrate_failures_reset()` — a real correctness fix this refactor required: `migrate_failures()`'s data lives in a `static` variable that persists for the process's lifetime, harmless when each migration ran in its own subprocess but a real cross-migration bleed risk once several run back-to-back in one process. The runner calls this before each migration; no migration file does.

**`database/migrate.php` (rewritten):** discovery/pending-detection/`schema_migrations` schema/CLI-only guard/CLI usage all unchanged. Execution is now `require_once` (declares the function) + a direct function call, each wrapped in its own `try`/`catch(Throwable)` so one migration's genuine bug can't crash the batch. The subprocess-era `migrate_runner_check_exec_available()` pre-flight check and `migrate_runner_guess_cause()` diagnostic were removed as obsolete — there's no `exec()` call left to diagnose.

**Bug found and fixed during testing (not before):** the discovery glob (`migrate_*.php`) also matched `migrate_helpers.php` itself, which would have been treated as a fake 22nd migration and failed (no `migrate_helpers()` function exists). Fixed by explicitly excluding it in `migrate_runner_discover()`.

**Future rollback convention (documented only, per approved scope — not implemented):** a migration may optionally define `rollback_<migration_name>()` alongside `migrate_<migration_name>()`. Nothing in the runner or helpers currently looks for or calls one — pure naming reservation, documented in both files' docblocks and in `docs/MIGRATION_MANAGEMENT_PLAN.md` §7.6.

**Testing performed:**
- `php -l` on all 23 touched/new files — clean.
- Fresh local database seeded from `install.sql`: `database/migrate.php --run` — **all 21 migrations succeeded in one PHP process**, including the two most complex (`migrate_catalog.php`, which also runs the entirety of `schema.sql` as its own first step; `migrate_production_hardening.php`, which contains a multi-table customer-deduplication routine) — this is the exact scenario (multiple migrations, one process) that used to fatal-error before the refactor.
- Idempotency verified: immediately re-ran (preview and `--run`) — all 21 correctly `Completed`, 0 pending, no errors, no duplicate application.
- Standalone execution verified individually for all 21: reset to a fresh pre-migration database, ran every `migrate_X.php` directly (not via the runner) — all exited 0 with expected output, auto-run guard fired correctly every time.
- Verified against real `INFORMATION_SCHEMA` state, not just log output: confirmed `supplier_orders.currency`, `product_variations.weight_mode`, `currency_rates.rate_type`, the `resolution_requests` table, and the `supplier_orders.purchase_number` UNIQUE index were all genuinely present after the run.
- All testing used a local, throwaway MariaDB instance, torn down afterward — no production database was touched.

### Migration Management System v1 — production crash fixed

**Scope:** `database/migrate.php` only. No existing `database/migrate_*.php` file was modified. `docs/MIGRATION_MANAGEMENT_PLAN.md` (new §2b) and `docs/IMPLEMENTATION_STATUS.md` updated to match.

**Incident:** running `php database/migrate.php --run` in production printed `-> migrate_additional_costs.php`, then terminated immediately with no further output and zero `schema_migrations` rows written.

**Root cause:** `migrate_runner_execute()` called `exec($command, $outputLines, $exitCode)` without initializing `$outputLines`/`$exitCode` first. `exec()` populates those by reference when it actually runs — but shared hosts (Hostinger included, on some plans) commonly disable `exec()` via `disable_functions` in `php.ini`; when disabled, the call silently no-ops without touching those variables. The next line, `implode(PHP_EOL, $outputLines)`, then threw an uncaught TypeError on the unset variable, crashing the runner before any result could be recorded.

**Confirmed by local reproduction**, not assumed: `implode(PHP_EOL, null)` (simulating `exec()` never populating its output) reproduced the exact failure —
```
PHP Fatal error:  Uncaught TypeError: implode(): Argument #1 ($array) must be of type array, string given
EXIT CODE: 255
```
— matching the production symptom exactly (silent, immediate termination, no output, no recorded result).

**Fix (`database/migrate.php`):**
- Defensive initialization (`$outputLines = []; $exitCode = null;`) before every `exec()` call — a disabled `exec()` now degrades into a normal, recorded `'failed'` result instead of an uncaught crash.
- New pre-flight check (`migrate_runner_check_exec_available()`), run once before attempting any migration in `--run` mode — stops immediately with a clear, actionable message (what's wrong, why subprocess execution is required, and to ask hosting support whether `exec()` can be allowlisted for CLI/SSH specifically) if `exec()` is unavailable, rather than failing silently on whichever migration sorts first.
- Richer failure reporting — a failed migration now shows its exit code, full output, and a best-effort "possible cause" (`migrate_runner_guess_cause()`); both success and failure recording are individually try/caught so a database hiccup while *recording* a result can never itself crash the batch.

**Kept unchanged, per explicit instruction:** the subprocess execution architecture itself, all 21 existing migration files (none modified), and the overall runner design — see `docs/MIGRATION_MANAGEMENT_PLAN.md` §2b for why subprocess execution remains necessary regardless of this incident (the `migrate_run()` function-redeclaration collision across 20 of 21 scripts is unrelated to and unaffected by this fix).

**Testing performed:** `php -l` clean. Reproduced the disabled-`exec()` scenario locally via `php -d disable_functions=exec` against an isolated copy of the runner's pure functions (no database involved) — confirmed the pre-flight check now correctly detects and reports it (`exec() does not exist in this PHP build.`) instead of crashing. Separately verified the normal path (`exec()` available) still works correctly — `migrate_runner_execute()` against a deliberately-missing file correctly captured exit code `1` and the real "Could not open input file" output, with no crash. **Not tested:** a live `--run` against production or any database — not required to verify this specific fix, and no production migration was executed.

### Migration Management System v1 — implemented, not yet run against production

**Scope:** `database/schema.sql` (new `schema_migrations` table definition), `database/migrate.php` (new runner). No existing `database/migrate_*.php` file was modified. `docs/MIGRATION_MANAGEMENT_PLAN.md` and `docs/IMPLEMENTATION_STATUS.md` updated to match.

- **Added** the `schema_migrations` tracking table (see `database/schema.sql`) — keyed by exact filename, so none of the 21 existing migration scripts needed renaming.
- **Added** `database/migrate.php`: discovers `database/migrate_*.php` from disk (never a hand-maintained list — the direct fix for the incident's root cause), defaults to preview-only, requires an explicit `--run` flag to execute, shows pending/completed/modified-since-applied migrations, and records one `schema_migrations` row per script run.
- **Design change found during implementation, not in the original plan:** grepping all 21 scripts found 20 of them independently define an identical `migrate_run()` function at global scope. `require`-ing more than one into the same PHP process would fatal-error ("Cannot redeclare function"). Fixed by running each pending migration as its own `php` subprocess instead — this required **zero changes to any existing migration file**. See `docs/MIGRATION_MANAGEMENT_PLAN.md` §2a for full detail.
- **Design change:** v1 ships CLI-only (`PHP_SAPI !== 'cli'` guard, matching `cli/job_worker.php`'s existing pattern), not browser + CLI as originally sketched — closes the audit's unauthenticated-access finding for this new script rather than reproducing it, and avoids the extra permission/CSRF/UI work a browser path would need for v1. See `docs/MIGRATION_MANAGEMENT_PLAN.md` §2a.
- **Explicitly not implemented**, per approved scope: rollback engine, dependency graph, CI/CD integration.

**Database changes:** `schema_migrations` table added to `database/schema.sql` (fresh installs get it automatically); on an existing database it's created the first time `database/migrate.php` runs (`CREATE TABLE IF NOT EXISTS`, same idempotent pattern as every other migration). **Not run against production as part of this change** — per instruction, no production migration was executed.

**Testing performed:** `php -l` clean on `database/migrate.php`. End-to-end verified against a throwaway local MariaDB instance (XAMPP, `127.0.0.1`, isolated test database, credentials injected via process environment variables — the real `.env`/`config.php` were never read or touched): confirmed `schema_migrations` bootstraps with the exact intended schema, and confirmed preview mode correctly discovers and lists all 21 existing migration files as pending against an empty database. The local test database and MySQL instance were torn down afterward — nothing was left running. **`--run` execution mode (actually applying migrations) was not live-tested**, per direction received mid-task — its correctness rests on code review and the exit-code/output-capture logic being the same pattern already proven in `cli/job_worker.php`, not on an observed live run.

### Migration Management System — audit & design (planning only)

**Scope:** Documentation only — `docs/MIGRATION_SYSTEM_AUDIT.md` (new), `docs/MIGRATION_MANAGEMENT_PLAN.md` (new), `docs/CURRENT_SYSTEM_AUDIT.md` (migration count corrected 19 → 21), `docs/IMPLEMENTATION_STATUS.md`. No code, migration file, or database change was made — implementation awaits approval.

- **Audited** all 21 existing `database/migrate_*.php` scripts (filename, purpose, tables/columns, idempotency, dependencies). Confirmed 21/21 are idempotent. Found `includes/system_health.php`'s existing migration-detection array — built after two prior silent-migration incidents — covers only 17 of the 21 scripts; the 4 missing include `migrate_supplier_order_currency.php`, the exact migration behind this week's outage.
- **New finding:** all 21 migration scripts are reachable via unauthenticated direct URL — confirmed against the root `.htaccess`, which protects `.env`/`config.php` but was never extended to `database/*.php`. Same class of gap as the `?wc_webhook_diagnose` endpoint noted in `docs/CURRENT_SYSTEM_AUDIT.md` §9.
- **Designed** (not built) a `schema_migrations` tracking table and a `database/migrate.php` runner that discovers migrations from disk rather than a hand-maintained list — the direct structural fix for the incident's root cause. Design deliberately excludes rollback/down-migrations, a dependency graph, and any renaming of the 21 existing scripts, per explicit scope constraints — see `docs/MIGRATION_MANAGEMENT_PLAN.md` §6 for the full list of what was intentionally left out.

### Supplier Orders module improvements

**Scope:** `modules/supplier-orders/create.php`, `modules/supplier-orders/edit.php`, `includes/supplier_orders.php`, `assets/js/supplier-order-form.js`. Approved via the CLAUDE.md analysis → plan → wait-for-approval process; see `docs/CURRENT_SYSTEM_AUDIT.md` §5.2/§6 for the findings this was based on.

- **Added:** Supplier order creation now writes an `activity_logs` entry (`activity_log($pdo, 'supplier_orders', 'create', ...)`) recording purchase number, item count, currency, and total. Previously, creating a PO left zero audit trail — verified by grep, not assumed.
- **Added:** The Exchange Rate field on both Create and Edit now pre-fills from the existing, centrally-managed `currency_rates` table (`currency_rates_get('supplier', code)`) when a foreign currency is selected and the field is still empty. Suggestion only — never overwrites a value the admin already typed or an already-saved invoiced rate on an existing order. No new lookup logic was written; this reuses the same function the product-pricing flow already uses.
- **Refactored:** Extracted the currency + line-item validation block — previously duplicated verbatim between `create.php` and `edit.php` — into a single shared function, `supplier_order_validate_form()`, in `includes/supplier_orders.php`. Behavior is unchanged; this is an extraction, not a rewrite (verified: no variable left dangling in either caller after the extraction, `php -l` clean on all touched files).

**Database changes:** none. **Migration required:** none for this change (the separate, already-diagnosed `database/migrate_supplier_order_currency.php` migration remains outstanding from a prior incident and is unrelated to this change, but is a prerequisite for the module to work at all in production — see Known Risks in `docs/IMPLEMENTATION_STATUS.md`).

**Queue review:** No queue required — all three changes are synchronous, fast, database-local operations (an activity log insert, a batched exchange-rate lookup, a pure validation-logic extraction). No long-running process was introduced.

**Future security note:** `purchase_number` has no character-class restriction (only length ≤100 and uniqueness are enforced), and it now flows into `activity_logs.description` via the new logging call. Not exploitable today — no admin page renders `activity_logs` anywhere in the codebase (see audit §6.6) — but whenever an Activity Log viewer is built, it **must** run `description` (and every other logged field) through `app_escape()` before rendering, same as every other display site in this codebase.

Queue Review:
No queue required.

Reason:
Changes are synchronous, fast database-local operations:
- activity log insertion
- exchange-rate lookup
- validation extraction

No long-running process introduced.

Future Activity Log Viewer must escape description fields before display.
