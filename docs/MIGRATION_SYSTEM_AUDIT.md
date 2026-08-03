# Migration System Audit

**Status:** Complete. Analysis and design only — no code, migration files, or database changes were made to produce this document, per task scope.
**Read before this:** `docs/CURRENT_SYSTEM_AUDIT.md` §6.1/§6.5 (the finding and incident this document expands on).

> **Addendum (2026-08-03) — counts below are the point-in-time figures from when this audit was written and are deliberately left unedited as the historical record.** Two migrations have been added since (`migrate_finance_phase_a.php`, `migrate_finance_phase_b.php`), bringing the total to **23**. Both were initially unregistered in `includes/system_health.php` — the exact drift failure mode §1 predicts for every new migration — and were found during a Finance Phase B readiness audit. Both are now registered (4 array rows: one for Phase A, three for Phase B), so System Health coverage is now **19 of 23** scripts. The 4 still-untracked scripts named in §2 (`migrate_sync_logs_index.php`, `migrate_webhooks.php`, `migrate_supplier_order_currency.php`, `migrate_supplier_order_purchase_number_unique.php`) are unchanged and still untracked. This addendum is itself evidence for §4/§6's core argument: a hand-maintained detection array drifts by default, and catching it depends on someone happening to audit for it.

---

## 1. Executive summary

Mewmii OS has **21** standalone migration scripts in `database/`. Every one of them is individually well-built: self-documenting, idempotent (checks `INFORMATION_SCHEMA` before altering, or uses `CREATE TABLE IF NOT EXISTS`), and additive-only (no script deletes or destructively rewrites existing data). The problem was never migration *quality* — it's that there is no system tracking *which scripts have actually been run against a given database*.

This is not a new problem being discovered for the first time. **A detection mechanism already exists** (`includes/system_health.php`, "System Health (Issue 5 - Production Migration Safety)") — and its own docblock states it was built after **two prior incidents** of exactly this failure mode (a missing `saved_views` table, a missing `products.woocommerce_sync_hash` column). This week's supplier-order-currency outage is the **third** occurrence of the identical failure. The existing mechanism didn't prevent it because it's a hand-maintained array that must be remembered and updated every time a new migration is written — and it wasn't, for 4 of the 21 scripts, including the one that broke production.

**Second, independent finding:** all 21 migration scripts have zero authentication. They are executable by anyone who requests the URL directly — confirmed against the site's own `.htaccess`, which protects `.env`/`config.php` but was never extended to `database/*.php`.

---

## 2. Migration inventory

All 21 scripts, ordered by file modification time (a reasonable proxy for actual authorship/intended-run order, since there is no other ordering signal anywhere in the codebase).

| # | Script | Purpose | Tables touched | Idempotent? | Tracked by System Health? |
|---|---|---|---|---|---|
| 1 | `migrate_woocommerce_sync.php` | WooCommerce push-sync improvements (ID-matching, status mapping, skip-unchanged) | `products` | ✅ INFORMATION_SCHEMA check | ✅ |
| 2 | `migrate_catalog.php` | Catalog overhaul — simple/variable products, attributes, variations, templates. Largest script (38KB), touches 13 tables + creates `supplier_order_events` | `products`, `product_variations`, `product_attribute_values`, `product_images`, `mewmii_orders`, `mewmii_order_items`, `mewmii_inventory`, `inventory_transactions`, `customer_storage`, `ship_requests`, `ship_request_items`, `suppliers`, `supplier_order_items`, `supplier_orders` + new `supplier_order_events` | ✅ | ✅ |
| 3 | `migrate_catalog_management.php` | Catalog Management consolidation — description/image fields on taxonomy tables | `brands`, `categories`, `collections` | ✅ | ✅ |
| 4 | `migrate_production_hardening.php` | Production Hardening Phase 2 — indexes + customer dedup/merge | `customers`, `inventory_transactions`, `mewmii_orders`, `products`, `supplier_orders` | ✅ | ✅ (+ separate index check, `SYSTEM_HEALTH_INDEXES`) |
| 5 | `migrate_saved_views.php` | Saved Views feature | new `saved_views` | ✅ | ✅ |
| 6 | `migrate_product_costing.php` | Schema prep for landed-cost chain (currency → rate → converted cost) | `products`, `supplier_order_items` | ✅ | ✅ |
| 7 | `migrate_sync_logs_index.php` | Adds one missing index (`sync_logs` was a full table scan on every product/order page load) | `sync_logs` | ✅ | ❌ **not tracked** |
| 8 | `migrate_webhooks.php` | WooCommerce webhook receiver + queue backing table | new `webhook_events` | ✅ | ❌ **not tracked** |
| 9 | `migrate_additional_costs.php` | Additional Costs Framework | new `supplier_order_item_costs` | ✅ | ✅ |
| 10 | `migrate_product_cost_history.php` | Frozen Landed Cost snapshots | new `product_cost_history` | ✅ | ✅ |
| 11 | `migrate_notifications.php` | Notification & Alert Center — `reference_id` column | `mewmii_notifications` | ✅ | ✅ |
| 12 | `migrate_notification_lifecycle.php` | Notification Active/Acknowledged/Resolved lifecycle | `mewmii_notifications` | ✅ | ✅ |
| 13 | `migrate_pricing_engine.php` | Pricing Engine — original/market price columns + shipping rate countries | `products` + new `shipping_rate_countries` | ✅ | ✅ (2 entries, same script) |
| 14 | `migrate_currency_rates.php` | Global Currency Exchange Rate Settings | new `currency_rates` | ✅ | ✅ |
| 15 | `migrate_variation_weight_mode.php` | Variation weight inherit/custom mode | `product_variations` | ✅ | ✅ |
| 16 | `migrate_currency_rate_types.php` | Widens `currency_rates` to per-rate-type (Supplier/Original/Market) — **depends on #14 already having created the table** | `currency_rates` | ✅ | ✅ |
| 17 | `migrate_supplier_order_currency.php` | Phase 6B — supplier order currency/exchange_rate/foreign_total + line-level unit_cost_foreign/myr | `supplier_orders`, `supplier_order_items` | ✅ | ❌ **not tracked — this is the incident** |
| 18 | `migrate_customer_delete_lifecycle.php` | WooCommerce delete-webhook support — `archived_at` | `customers` | ✅ | ✅ |
| 19 | `migrate_outbound_jobs.php` | Unified Outbound Job Queue backing table | new `outbound_jobs` | ✅ | ✅ |
| 20 | `migrate_order_resolution.php` | Customer Order Resolution System — 7 new tables, purely additive | new `resolution_requests`, `resolution_items`, `resolution_refunds`, `payment_receipts`, `customer_wallets`, `customer_wallet_transactions`, `customer_notifications` | ✅ | ✅ |
| 21 | `migrate_supplier_order_purchase_number_unique.php` | Adds missing UNIQUE constraint on `supplier_orders.purchase_number` | `supplier_orders` | ✅ | ❌ **not tracked — same blind spot, added this session** |

**Idempotency: 21/21 (100%).** This is a genuine strength — every script independently reinvented the same safe pattern (`INFORMATION_SCHEMA` check or `CREATE TABLE IF NOT EXISTS`) without a shared framework enforcing it. Re-running any script against an already-migrated database is confirmed safe by inspection.

**Dependencies found:** only one explicit ordering dependency exists in the whole set — `migrate_currency_rate_types.php` (#16) alters a table that `migrate_currency_rates.php` (#14) creates. Every other script is independent (touches its own new table, or adds columns to an existing table without depending on another migration's columns). This matters for the target design: a strict numbered/dated sequence is not solving a real widespread dependency problem, just this one pair — but ordering still matters for auditability even where it isn't strictly required for correctness.

**Execution status against the live database: cannot be verified from this environment** (no safe database access — see prior sessions' notes on why). The one thing that *can* be checked safely and immediately is `includes/system_health.php`'s own read-only report — Settings → System Health already answers "which of the 17 tracked migrations are missing" today, for free, with zero new code. It just doesn't know about the other 4.

---

## 3. Current process (as it actually works, not as documented)

There is no standard process. Concretely:

- **No runner.** No script in `cli/` or elsewhere iterates and applies pending migrations. Each `migrate_*.php` is a fully standalone, individually-executed file.
- **No tracking table.** No `schema_migrations` or equivalent exists in `database/schema.sql`.
- **Two execution paths, both manual:** each script's own docblock says "Run once via browser (`https://yourdomain/database/migrate_X.php`) or CLI (`php database/migrate_X.php`)" — the developer is trusted to remember to do one of these after deploying code that depends on it.
- **One partial detection mechanism exists** (`includes/system_health.php`, §1 above) — read-only, manually curated, currently covers 17 of 21 scripts.
- **No relationship to deployment.** `DEPLOYMENT.md` documents file-preservation rules (uploads, `.env`, `config.php`) in detail but has exactly one line about migrations: "confirm nothing in System Health shows a pending migration" — a manual post-deploy checklist item, not an enforced gate.
- **Not imported through phpMyAdmin** — no `.sql` migration files exist; every migration is a PHP script using `$pdo->exec()`/`ALTER`/`CREATE`, run through the application's own bootstrap (so it uses the same DB credentials as the app, not a separate admin connection).

## 4. Problems, direct answers

**How do we know which migrations ran?** Only by checking System Health's 17-script subset, or by manually inspecting `INFORMATION_SCHEMA` yourself. No record exists of *when* or *by whom* any migration was actually executed.

**How do we know migration order?** We don't, formally — file `mtime` (this audit's only ordering signal) isn't preserved by git and isn't a real execution log. The one real dependency found (#14→#16) isn't encoded anywhere; a developer running #16 first on a brand-new-but-partially-set-up database would get a clean, silent failure or a confusing error, not a guided "run #14 first" message.

**What happens on a new server?** `database/schema.sql`/`install.sql` create the *current* shape directly — a fresh install never needs any `migrate_*.php` script (several scripts' own docblocks say so explicitly: "Brand-new installs don't need this"). The risk is entirely on **existing** databases that predate a given migration.

**What happens if a migration partially fails?** Depends on the script. Most wrap each `ALTER`/`CREATE` independently (see `migrate_run()`'s try/catch pattern used across most scripts, e.g. `migrate_supplier_order_currency.php`) and continue past a single failed statement, reporting failures at the end rather than stopping. This means a script *can* leave a database in a partially-migrated state (some of its columns added, others not) if one statement fails — and because there's no tracking table, that partial state looks identical to "never run" on the next check.

**How do we recover?** Re-run the same script — since every step is independently idempotent, a re-run only attempts whatever didn't succeed last time. This actually works today, it's just undiscoverable without already knowing to do it.

**How do we prevent duplicate execution?** Each script's own `INFORMATION_SCHEMA` check prevents a duplicate *column/table* from being created twice — but nothing prevents the script itself from being *run* twice; it's just a safe, wasted no-op when it is.

## 5. Security finding (new, found during this audit)

**All 21 migration scripts are reachable via unauthenticated direct URL.** Verified: `grep -L "app_require_permission\|app_require_login" database/migrate_*.php` returns all 21 — none of them require login, let alone a specific permission. Verified further against the root `.htaccess`: it explicitly blocks direct web access to `.env`/`.env.example`/`config.php`/`.gitignore` (added in a prior "Phase 10A — Security & Permission Audit" pass, per its own comment), but its own comment states "Nothing else in this directory is affected" — `database/*.php` was never brought into that protection. Each script's own docblock even invites this ("Run once via browser (`https://yourdomain/database/migrate_X.php`)"), meaning the exposure is by design, not oversight-in-code — it's a gap in what the *hosting-level* protection pass covered.

**Risk:** low-to-moderate in practice (every script is idempotent, so repeated/malicious execution can't corrupt data), but real: an unauthenticated actor can discover exact schema/migration history from script output, and can trigger real `ALTER TABLE` operations (locking, I/O load) against production tables on demand. This is the same class of finding as `docs/CURRENT_SYSTEM_AUDIT.md` §9's `?wc_webhook_diagnose` endpoint — a recurring pattern of admin/ops tooling shipped without the same auth discipline applied to user-facing pages.

---

## 6. What's already good (preserve, don't replace)

- The idempotent-check convention itself (100% adoption) — the target system should formalize this pattern, not invent a new one.
- `includes/system_health.php`'s detection approach (one representative column/table per migration, checked live against `INFORMATION_SCHEMA`) is sound in principle — its only real flaw is being hand-maintained and therefore capable of silently drifting out of sync, which is exactly what happened.
- Every script's docblock already records purpose, tables touched, and idempotency in readable prose — better inline documentation than most migration tools produce automatically. A future tracking table should capture this as structured metadata, not discard it.
