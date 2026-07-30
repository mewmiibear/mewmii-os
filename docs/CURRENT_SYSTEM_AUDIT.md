# Mewmii OS — Current System Audit

**Status:** Complete (v1)
**Scope:** Read-only analysis of the system as it actually runs today. No code was changed to produce this document.
**Method:** Direct reading of `database/schema.sql`, `includes/*.php`, `modules/**/*.php`, `cli/*.php`, and the `docs/` folder itself, cross-checked against two real production incidents diagnosed in this repo this week (see §6.5).
**Companion documents:** `docs/MEWMII_OS_DEVELOPMENT_HANDBOOK.md` already describes a *target* architecture (Controller → Service → Repository → Queue → Event). That document is aspirational — almost none of it has been built yet. This audit describes what exists *today*, and is the factual baseline the target architecture must be reconciled against before any of it is implemented.

---

## 1. System Inventory

| Dimension | Count | Notes |
|---|---|---|
| Database tables | 66 | `database/schema.sql`, all `InnoDB`/`utf8mb4` |
| Module page scripts | 102 | `modules/**/*.php`, ~25 domain folders |
| Shared logic libraries | 44 | `includes/*.php` |
| Cron/CLI entry points | 6 | `cli/*.php` |
| MVC scaffolding (`app/`, `routes/`, `public/`) | effectively none | `app/` contains only a `.claude/settings.json`; `routes/` and `public/` are empty |

**Folder structure (as it actually exists, not as documented):**

```
mewmii-os/
├── modules/<domain>/<action>.php   ← one file = one URL = one endpoint (no router)
├── includes/*.php                  ← shared function libraries, required via require_once
├── database/schema.sql             ← authoritative CREATE TABLE source
├── database/migrate_*.php          ← 19 standalone, hand-run migration scripts (see §6.5)
├── cli/*.php                       ← 6 cron-invoked scripts (webhook processing, job worker,
│                                      WooCommerce order/product sync, alert generation)
├── config.php / .env               ← config.php committed with real production values;
│                                      .env layered on top via includes/env_loader.php
├── install.php / install.sql       ← one-time seed (Owner role + Owner user), not a migration
├── app/, routes/, public/          ← present in the tree but empty/vestigial
└── docs/, modules/*.md             ← documentation; largely unfilled (see §1.1)
```

There is no front controller and no URL router: Apache/the webserver maps a request path directly to a file under `modules/`. Routing, authentication, CSRF, and permission checks are therefore re-declared at the top of every single page rather than centralized.

### 1.1 Documentation inventory (why this document exists)

Before writing anything, the existing `docs/` folder and `modules/*.md` domain specs were read in full. Findings:

| File | Real content? |
|---|---|
| `docs/01_PROJECT_VISION.md` | Real, but a short 18-line statement |
| `docs/MEWMII_OS_DEVELOPMENT_HANDBOOK.md` | Real and substantial (685 lines) — but describes a **target** architecture that hasn't been built |
| `docs/04_DEVELOPMENT_HANDBOOK.md` | Thin stub, cuts off after ~60 lines, duplicates the start of the file above |
| `docs/03_SYSTEM_ARCHITECTURE.md` | 6-line skeleton diagram, does not reflect reality |
| `docs/CURRENT_SYSTEM_AUDIT.md` (this file, before this rewrite) | Was literally an unfilled prompt template — never actually completed |
| `docs/02_PRODUCT_REQUIREMENTS.md`, `docs/06_DATABASE_DESIGN.md` | Empty (0 bytes) |
| `docs/10_PERMISSION_SYSTEM.md`, `docs/11_NOTIFICATION_SYSTEM.md`, `docs/12_ACTIVITY_LOG.md`, `docs/13_SEARCH_SYSTEM.md` | Empty (0 bytes) — verified directly |
| `modules/PRODUCTS.md` | 14-line skeleton |
| `modules/SUPPLIERS.md`, `modules/PURCHASE.md` | Empty (0 bytes) — verified directly |

**Conclusion:** no prior audit of the real system exists. This is the first one. Every other planning document either depends on this one or was written without ever having read the real code.

---

## 2. Current PHP Architecture

**Actual pattern (confirmed across every domain surveyed):** flat procedural page scripts. Each `modules/<domain>/<action>.php` file combines, in one file: permission/CSRF checks, request validation, raw SQL (`$pdo->prepare()`/`query()` calls written directly in the page), business-rule branching, and HTML rendering. Shared business logic is factored into `includes/*.php` function libraries (not classes), pulled in via `require_once`. There are **no Controller, Service, or Repository classes anywhere in the codebase** — the target architecture in `MEWMII_OS_DEVELOPMENT_HANDBOOK.md` (`Browser → Controller → Service → Repository → Database`) does not exist yet in any form.

Concrete evidence of how deep this goes: `modules/inventory/index.php` contains 24 inline SQL statements, `modules/orders/view.php` ~30, `modules/ship-my-box/create.php` 13, `modules/customer-storage/view.php` 11. This is the house style throughout, not a handful of stragglers.

**Consistent conventions that *do* exist** (these are the load-bearing seams a future architecture should build on, not replace):
- `app_require_permission('module.action')` at the top of nearly every entry point, including AJAX endpoints
- `app_require_csrf()` on every mutating POST
- `app_log_action()` / `activity_log()` for audit trails
- A real ledger-of-truth pattern for inventory (`inventory_transactions`) and order status (recomputed, never manually set — see §6.3)
- Two independent, production-grade async queue implementations already exist (see §6.5.1)

**Verdict:** this is not "no architecture" — it's a consistent, if flat, convention that has been applied uniformly for a long time. A v2 architecture should treat this as the migration *source*, not as chaos to be discarded wholesale.

---

## 3. Database Structure

66 tables across seven natural domains:

| Domain | Representative tables |
|---|---|
| Access control | `roles`, `permissions`, `role_permissions`, `users` |
| Catalog | `products`, `product_variations`, `product_attributes(_values)`, `product_images`, `brands`, `categories`, `collections`, `product_tags`, `variation_templates` |
| Pricing/currency | `currency_rates`, `product_cost_history` |
| Procurement | `suppliers`, `supplier_orders`, `supplier_order_items`, `supplier_order_item_costs`, `supplier_order_events`, `supplier_order_payments` |
| Inventory/warehouse | `mewmii_inventory`, `inventory_transactions`, `customer_storage`, `ship_requests(_items)`, `shipments`, `shipment_items(_events)` |
| Customer orders | `mewmii_orders`, `mewmii_order_items`, `mewmii_order_events`, `resolution_requests(_items)`, `resolution_refunds`, `payment_receipts`, `customer_wallets(_transactions)` |
| Platform | `activity_logs`, `audit_logs`, `mewmii_notifications`, `customer_notifications`, `sync_logs`, `webhook_events`, `outbound_jobs`, `saved_views`, `settings` |

**Schema governance gap (see §6.5 for the concrete incident):** `database/schema.sql` is the authoritative source for a *fresh* install, but there is no migration-version tracking table and no single command that brings an *existing* database up to date. Instead there are 19 separate `database/migrate_*.php` scripts, each idempotent on its own (checks `INFORMATION_SCHEMA` before altering), but nothing records which ones have actually been run against a given environment. This is not hypothetical — it is the exact root cause of a live production outage diagnosed this week (§6.5).

---

## 4. Module Snapshot

| Domain | Folder(s) | Status |
|---|---|---|
| Dashboard | `modules/dashboard/index.php` | **Dead file (0 bytes).** The real dashboard is the root `index.php` (868 lines). `modules/dashboard/` is orphaned and misleading — remove or redirect it. |
| Catalog | `modules/products`, `catalog`, `brands`, `categories`, `collections`, `tags`, `attributes` | Working; `brands/categories/collections/tags/attributes` are legacy redirect stubs, real UI consolidated into `modules/catalog/index.php` tabs — but the underlying CRUD logic behind each tab is still duplicated per-taxonomy (§6.2) |
| Suppliers / Procurement | `modules/suppliers`, `purchasing`, `purchase-planning`, `supplier-orders` | Working; supplier-orders currency feature (Phase 6B) is currently the subject of a live migration-drift incident (§6.5) |
| Inventory / Warehouse | `modules/inventory`, `shipments`, `ship-my-box`, `customer-storage` | Working; unified `shipments` model is a genuine strength (§8) |
| Customer Orders | `modules/orders`, `customers` | Working; status is a derived/computed field, not manually set (§6.3) — a real strength, but diverges from the handbook's aspirational status vocabulary |
| WooCommerce | `modules/integrations/woocommerce.php`, `webhooks/*`, `sync-logs` | Working; push is auto-sync-on-save, pull is polling + webhook; bulk "Import Now" buttons run synchronously in-request (§7) |
| Reports/Finance | `modules/reports/*` | Working, read-only, generally well-paginated |
| Settings | `modules/settings/*` (~15 files) | Working; includes currency rates (recently hardened this week), data export, system health, maintenance |
| Search | `modules/search/*` | Working but not scalable (§7 — leading-wildcard `LIKE` only, no fulltext/indexed search) |
| Notifications | `modules/notifications/index.php` | Working, well-built lifecycle (Active/Acknowledged/Resolved), but gated on `dashboard.view` permission instead of its own — minor inconsistency |
| Activity Log | `includes/activity_log.php` | Write-only. **No admin page displays `activity_logs` anywhere in the codebase.** Real audit data is being recorded and never read. |
| Permissions | `roles`/`permissions`/`role_permissions` + `app_has_permission()` | Enforced everywhere it's checked, but **no role-management UI exists at all** (§9) — `install.php` seeds exactly one role, "Owner," with every permission. In practice, access control today is single-role. |
| Background Jobs | `includes/job_queue.php`, `includes/wc_webhook.php` | Two real, independent, production-grade queues exist (§6.5.1) — not vaporware, genuinely reusable |

---

## 5. Key Workflows (as implemented, not as documented)

### 5.1 Inventory
`mewmii_inventory` (current quantities) + `inventory_transactions` (append-only ledger, one row per mutation, includes `balance_after`) is the source of truth. Every mutating function (`inventory_reserve_for_order`, `inventory_ship_order_quantity`, `supplier_order_mark_incoming`, `supplier_order_receive_item`, etc.) writes both in the same transaction and enqueues a WooCommerce resync via `inventory_queue_woocommerce_resync()`, flushed once after commit. This pattern is consistent and well-disciplined across the whole codebase — it is the single most reliable subsystem surveyed.

### 5.2 Supplier Order
`purchase_planning_needs()` computes shortages (two formulas: preorder/early-bird demand vs. ready-stock target level) → admin approves lines → `supplier_orders`/`supplier_order_items` created → `supplier_order_mark_incoming()` moves stock to "incoming" → receiving moves it to "available" (ready-stock) or "arrived" (preorder/early-bird, pending manual allocation). Currency is captured per-PO (Phase 6B: `currency`/`exchange_rate`/`foreign_total` on `supplier_orders`, `unit_cost_foreign`/`unit_cost_myr` on `supplier_order_items`) — deliberately separate from the centrally-managed `currency_rates` table used for product pricing, since a supplier invoice's rate is a point-in-time fact, not a live lookup.

### 5.3 Customer Order
`order_status` is **never set directly by a form** — it's recomputed by `order_recompute_status()` after every relevant mutation, rolled up from live per-item fulfillment state (itself derived from `inventory_net_reserved()`, `customer_storage`, and `shipment_items` — never stored). Payment status is a separate, gating field. Refunds are handled entirely outside `mewmii_orders` by a bolt-on Resolution subsystem (`resolution_requests/items/refunds`) that computes a display-only adjusted total and never touches `mewmii_orders.total_amount`. This is a deliberately different design from the aspirational 11-state handbook workflow (Draft/Pending Payment/.../Refunded) — the real system has 8 states plus a side-branch, and "Refunded" is a resolution outcome, not an order status.

### 5.4 WooCommerce Sync
Push (Mewmii → Woo): auto-sync-on-save (`wc_client_auto_sync_product`), triggered from every product mutation page. Pull (Woo → Mewmii): polling (`wc_*_import_run()` functions, batch-capped) plus an additive incoming-webhook queue (`webhook_events`, processed by `cli/wc_webhook_process.php`). A "master mode" setting decides whether Mewmii OS is the source of truth or sync is bidirectional — a genuinely well-designed answer to a hard data-ownership question.

### 5.5 A real, currently-live incident that illustrates the biggest structural gap

This week, supplier order creation broke in production with `Failed to create supplier order.` Root-causing it required temporarily dumping raw exceptions (now reverted) and turned up two successive missing-column errors: `Unknown column 'currency' in 'INSERT INTO'`, then `Unknown column 'soi.unit_cost_foreign' in 'SELECT'`. The code was correct throughout — `database/schema.sql` already declares both columns, and `database/migrate_supplier_order_currency.php` already exists and would add them safely and idempotently. **The live database was simply never migrated after that script was written.** This is not a one-off bug; it's the direct, observed consequence of §3's schema-governance gap: 19 migration scripts with no tracking of which have actually run, discovered only when a feature silently breaks in production. Any v2 plan must solve this class of problem structurally (a real migrations table + a single `migrate` command), not just patch this one instance.

---

## 6. Findings — Architectural Problems

### 6.1 [CRITICAL] No migration version tracking
- **Problem:** 19 independent `database/migrate_*.php` scripts, each self-checking via `INFORMATION_SCHEMA`, but nothing records which have run against which environment.
- **Current situation:** Deploying code assumes the DB is current; nothing enforces or verifies it.
- **Risk:** Silent production breakage (already happened — §5.5), with the failure surfacing as a confusing runtime error rather than a deployment-time check.
- **Recommendation:** A `schema_migrations` table + a single ordered runner script that applies whatever hasn't run yet, in sequence, logging what it did. Low effort relative to the risk it closes.
- **Priority:** Critical.

### 6.2 [MEDIUM] Duplicated CRUD across taxonomy modules
- **Problem:** `modules/catalog/tabs/{brands,tags,categories,collections,attributes}.php` (roughly 360–560 lines each) each hand-roll near-identical add/update/delete/move/merge logic against different tables instead of sharing one generic taxonomy pattern.
- **Risk:** A fix or improvement (e.g. better duplicate-name handling) has to be applied five times; already has visibly drifted once (each has its own ad hoc "find" query shape).
- **Recommendation:** Extract one shared taxonomy CRUD pattern (table name + a small config of column names) that all five tabs call into.
- **Priority:** Medium — real debt, not urgent.

### 6.3 [LOW] Divergent status-recompute logic (orders vs. resolutions)
- **Problem:** `order_recompute_status()` and the resolution subsystem's own status recompute follow the same "derive from children" shape independently, with no shared abstraction.
- **Risk:** Low today (both are correct and well-tested); a third similar subsystem would make the duplication worth resolving.
- **Recommendation:** Note it, don't act on it yet — premature to abstract from two instances.
- **Priority:** Low.

### 6.4 [MEDIUM] `wc_client.php` is a 1,687-line god file with a second, parallel data-access surface
- **Problem:** Required by 9 of 17 `modules/products/*.php` files; re-implements its own SQL for images/attributes rather than calling the existing `product_images.php`/`catalog.php` accessors.
- **Risk:** Two code paths reading/writing the same tables can drift (e.g. an image-handling fix applied in `product_images.php` silently not applying to what WooCommerce sync sees).
- **Recommendation:** Route `wc_client.php`'s reads through the existing `catalog.php`/`product_images.php`/`product_variations.php` accessor functions instead of raw SQL.
- **Priority:** Medium.

### 6.5 [CRITICAL] Schema drift under live production traffic
Covered in full in §5.5. Restated here because it's the highest-priority item in this entire audit: it is not a risk, it already happened.

#### 6.5.1 Async infrastructure is real but inconsistently adopted
- **Problem:** Three separate "sync with WooCommerce" mechanisms coexist: a real outbound job queue (`outbound_jobs`/`job_queue.php`, generic and production-grade), a real inbound webhook queue (`webhook_events`/`wc_webhook.php`), and synchronous-in-request "Import Now" buttons (`modules/integrations/woocommerce.php`) for bulk pulls that bypass both queues.
- **Risk:** The synchronous path is the one that will hit shared-hosting execution-time limits as order/product volume grows — and it's the one path with no retry/backoff safety net the other two already have.
- **Recommendation:** Route bulk imports through `outbound_jobs` (or a new job type) instead of running inline in the POST handler.
- **Priority:** High (not yet an incident, but the queue infrastructure to fix it already exists — this is a low-effort, high-value fix).

### 6.6 [LOW] Two unrelated "audit" tables, neither one visible
`activity_logs` (module actions) and `audit_logs` (login/logout) are both written consistently and **never read** — no admin page displays either. This is real audit data being generated and discarded from a usability standpoint. Not a data-loss risk (the rows exist), but the intended purpose — visibility — isn't being served. **Priority: Medium** (cheap to fix, real value: a read-only viewer, plus pagination/date-bounding designed in from the start since these will become the highest-volume tables in the system).

---

## 7. Findings — Performance Bottlenecks

| # | Issue | Location | Priority |
|---|---|---|---|
| 1 | Unbounded lifecycle-filter scan — fetches every matching row, no `LIMIT`, filters in PHP | `modules/products/index.php:164-185` | High |
| 2 | Leading-wildcard `LIKE '%term%'` search, unindexable, used everywhere (global search, product search) | `includes/global_search.php`, `modules/products/index.php:103-108` | High |
| 3 | Synchronous, per-variation WooCommerce sync loop (DB query + image query + 2 API calls, per variation, per product) inside an unbounded `SELECT * FROM products` | `includes/wc_client.php:1042-1129, 1552-1560` | Critical — the single biggest scaling blocker found |
| 4 | Attribute-value N+1 on every product create/edit page render | `modules/products/create.php:531-536`, `edit.php:592-596` | Medium |
| 5 | Full-table SKU load into PHP memory on every CSV import (preview + confirm) | `includes/product_import.php:152-153` | Medium |
| 6 | `inventory_transactions` — no index on `(product_id, variation_id)`, the predicate used by nearly every hot-path ledger read | `database/schema.sql:1030-1045` | High |
| 7 | `inventory_transactions`/`activity_logs`/`audit_logs` have no retention/cleanup job and, for the latter two, no read-path at all yet — will become the largest tables in the system with no pagination story designed in | schema-wide | Medium |
| 8 | Hardcoded `LIMIT 200` supplier dropdown — silent truncation, not real pagination | `modules/supplier-orders/create.php`, `edit.php:591` | Low |

**Counter-evidence worth preserving:** `includes/pricing_engine.php` and `includes/product_cost.php` are explicitly, deliberately batch-first (one query for products, one batched rate lookup, no per-row loop) — proof the team already knows how to do this well elsewhere. Several N+1 patterns in `purchase_planning.php`/`inventory.php`/`customer_storage.php` were already found and fixed in a prior "Production Readiness" pass (visible in the code's own docblocks). This is a codebase with real, demonstrated performance discipline in places — the problems above are gaps, not a systemic absence of care.

---

## 8. Findings — What's Already Well-Designed (Preserve List)

Per the explicit instruction that this is an upgrade, not a rewrite, these should be treated as the *foundation* a v2 architecture builds on, not code to be replaced:

- **Inventory ledger** (`inventory_transactions` as source of truth, `mewmii_inventory` as a derived cache) — correctness-resilient even if a step is repeated or skipped.
- **Order status as a computed, never-manually-set field** (`order_recompute_status()`) — eliminates an entire class of desync bugs by construction.
- **Unified `shipments`/`shipment_items` model** covering orders, ship-my-box, and manual shipments through one polymorphic record — no duplicated stock-consumption logic anywhere.
- **`pricing_engine.php` / `product_cost.php`** — genuinely exemplary batch-query design, explicitly documented against N+1.
- **Two production-grade async queues already built** (`job_queue.php`, `wc_webhook.php`) — row-locked claims, stale-recovery, backoff, retention cleanup. A v2 "Queue Design" doesn't need to invent this; it needs to route more work through what's already there.
- **Currency Rates system** (Phase 9F.1/9F.2) — a single, centrally-managed source of Supplier/Original/Market rates per currency, with correct batch lookups.
- **Resolution/wallet subsystem** — append-only, row-locked, token-based, item-level granularity.
- **Consistent `app_require_permission()`/`app_require_csrf()`/`activity_log()` usage** across nearly every entry point, including AJAX — the seams a real permission/audit system should be built on top of, not replaced.

---

## 9. Findings — Security

| # | Issue | Location | Priority |
|---|---|---|---|
| 1 | Single-role RBAC in practice — no role-management UI exists anywhere; `install.php` seeds exactly one "Owner" role with every permission | `install.php:32-69`, whole codebase | High |
| 2 | Unauthenticated diagnostic endpoint reachable via `?wc_webhook_diagnose`, echoes config paths/existence, opcache state, secret *lengths* — leftover debug code, pre-signature-check | `modules/webhooks/woocommerce.php:46-112` | High — should be removed regardless of any v2 plan, independent of this audit |
| 3 | `includes/auth.php` is a 0-byte stub — actual RBAC logic lives in `bootstrap.php` instead, meaning the "auth" file name is misleading for anyone auditing security by filename | `includes/auth.php` | Low |

---

## 10. Findings — Technical Debt Summary

The recurring theme across every domain surveyed is not "bad code" — it's **consistent, disciplined procedural code that has outgrown the absence of a data-access boundary.** The same four problems recur in every domain:
1. SQL written directly in UI pages (universal, by convention, not by accident).
2. No caching layer anywhere (every page recomputes from the DB on every load).
3. Real async infrastructure exists but is adopted in ~3 places out of many candidates.
4. Documentation describes a target architecture that was never built, creating a gap between what `MEWMII_OS_DEVELOPMENT_HANDBOOK.md` says and what actually ships — this audit is the first document to reconcile the two.

---

## 11. Priority Summary

| Priority | Count | Examples |
|---|---|---|
| Critical | 2 | Migration version tracking (§6.1); the live schema-drift incident it caused (§5.5/§6.5) |
| High | 5 | Async adoption gap (§6.5.1), unbounded catalog scan, unindexable search, missing inventory_transactions index, single-role RBAC, diagnostic endpoint |
| Medium | 6 | Taxonomy CRUD duplication, wc_client.php dual data-access surface, attribute N+1, CSV full-table load, write-only audit logs, retention/cleanup gap |
| Low | 3 | Status-recompute duplication, hardcoded LIMIT 200, auth.php naming |

---

## 12. What This Document Does Not Cover Yet

This audit answers "what exists and what's wrong with it." It deliberately does not yet propose the v2 target architecture, folder structure, API design, queue design, caching strategy, or migration plan — those require decisions (not just facts) and should be built *on top of* this document, not in parallel with it, given how much of the aspirational handbook this audit has now shown diverges from reality. Recommended next document, in order: a reconciled **Recommended Architecture** that keeps the strengths in §8, closes the gaps in §6/§7/§9, and replaces the handbook's unbuilt Controller/Service/Repository/Queue/Event vocabulary with one grounded in what the codebase actually needs next.
