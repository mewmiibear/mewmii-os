# Phase 1 Implementation Readiness Review

**Status:** Completed. Gate passed — Phase 1 implementation may proceed.
**Scope:** Navigation consolidation, Notification Badge, Dashboard Mission Control (`MEWMII_OS_V2_PLAN.md` §4, Phase 1).
**Method:** every claim below was verified against the current codebase (file:line cited), not assumed from the design docs.

---

## 1. Architecture compatibility check

### Dashboard Mission Control
Of the 7 "My Day" task sources listed in `DASHBOARD_PHILOSOPHY.md` §4, **5 are ready to reuse as-is**, **2 need a small new inline query** (not new schema, not new abstraction — the same "inline query on the dashboard" pattern already used for every other stat on `index.php` today):

| My Day source | Status | Detail |
|---|---|---|
| Purchase planning needs | Ready | `purchase_planning_needs()`, `includes/purchase_planning.php:86`, already called `index.php:93` |
| Overdue supplier orders | Ready, but duplicated | Predicate (`expected_delivery_date < CURDATE() AND status NOT IN (...)`) is copy-pasted in `index.php:120-125` and `includes/notifications.php:198-204`. Mission Control will extract this into one function (`supplier_orders_overdue()` in `includes/purchase_planning.php` or similar) and have both call sites use it — this *removes* existing duplication, it doesn't add any. |
| Ready-to-ship count | Ready | Already computed in the existing status-count GROUP BY, `index.php:65-73`, referenced at `index.php:550`. |
| Inventory allocation queue | Ready | `inventory_allocation_queue()`, `includes/customer_storage.php:145`, already called `index.php:175` |
| Low stock items | Ready | Inline query `index.php:136-154`, mirrors `inventory_stock_badges()`'s threshold rule by design (comment at `index.php:129-133`). Reused as-is, not re-derived a third time. |
| Pending payment receipts to verify | **Needs scoping decision** | Two different things share this name in the codebase: (a) WooCommerce order payment-proof (`mewmii_orders.receipt_status = 'pending'`) — already dashboard-ready, `index.php:267-282`; (b) resolution/refund `payment_receipts` awaiting approval — no global query exists, every existing query is scoped `WHERE resolution_id = ?` (`includes/order_resolution.php:519,553,585`). **Decision: My Day's "verify receipts" task uses (a) only** — it's the one with actual dashboard-scale volume and an existing query to reuse. (b) stays a per-order action on `modules/orders/view.php`, unchanged, since building a global cross-order list for it is new scope, not a Phase 1 dashboard concern. |
| Open customer resolution/issue requests | **New inline query needed** | `resolution_requests` table exists (`database/schema.sql:767`); no global `WHERE status <> 'resolved'` count exists anywhere (confirmed — every existing call is per-order). A new inline COUNT query, matching the style of every other dashboard stat, is required. This is new SQL, not new architecture. |

**Conclusion:** Mission Control can be built with zero new abstractions and one justified deduplication. No duplicated business logic is introduced.

### Notification Badge
Fully reusable: `notification_unread_count(PDO $pdo): int`, `includes/notifications.php:224-227` — a plain, cheap `COUNT` query. It's called today only from `index.php:479`; it has **never been called from `includes/header.php`** (confirmed zero hits), so putting it in the persistent header is a new call site, not new logic. Generation (`notification_generate_alerts()`) and auto-resolution explicitly never run on page load (only via CLI/manual button, per `includes/notifications.php` docblocks) — so adding a read-only count call to every page load carries no risk of accidentally triggering alert generation.

One caution, not a blocker: the query filters `read_status = 0 AND resolved_status = 0`, and the table's two composite indexes both lead with `type`/`reference_id`, not `read_status` (`database/schema.sql:560-574`). At current data volume this is a non-issue; flagged for later if `mewmii_notifications` grows large enough that this COUNT shows up in slow-query logs — not a Phase 1 concern.

### Navigation consolidation
No permission checks are being changed — every existing item keeps its current `app_has_permission()` gate (full inventory in §2 below). Because there is **no central router and no `.htaccess` rewrite rules** (confirmed — `routes/` and `public/` are empty, `.htaccess` only denies direct access to `.env`/`config.php`), every module page is reached by its literal file path. **Hard constraint for this phase: consolidation means regrouping/relabeling/reordering sidebar entries only — no file is moved or renamed.** Under that constraint, no bookmarked URL can break, because the URLs are untouched; only the sidebar markup around them changes. (If a future phase ever wants to rename a module's URL, every hardcoded reference — `includes/dashboard.php`, `includes/global_search.php`, `includes/notifications.php`, and `index.php`'s own tiles all hardcode paths independently, listed in full in §2 — would need updating in lockstep, since there's no single route registry. Out of scope here, noted for the record.)

One real gap found: `modules/purchase-planning/generate.php` has no sidebar entry at all — reachable only via a dashboard Quick Action button. The page itself gates on `app_require_permission('supplier-orders.manage')` (`modules/purchase-planning/generate.php:4`) — checked directly rather than assumed from the sidebar's existing `Purchasing`/`Control Center` gate (`inventory.view`), since a mismatched sidebar gate would either hide a reachable page or show a link that 403s. Adding it to the sidebar gated on `supplier-orders.manage` (matching the page's own check exactly) is in scope for this phase since it's additive and directly serves Workflow 2 (`Product → supplier pricing → purchasing → inventory → sales`).

### Future Multi-Warehouse / RBAC / API — not blocked
- No Phase 1 item introduces a "one inventory row = one location" assumption (nav, badge, and dashboard are all location-agnostic) — `FUTURE_MULTI_WAREHOUSE.md` §4 unaffected.
- Every permission check remains routed through `app_has_permission()`; nothing is hardcoded to the single seeded `Owner` role — `FUTURE_RBAC.md` §4 unaffected.
- No business logic is being written inside `index.php`'s rendering code that isn't already reusable — the two new dashboard queries are simple counts, and the overdue-supplier-orders dedup actively improves API-layer readiness by removing a duplicate — `FUTURE_API_LAYER.md` §4 unaffected.

## 2. Database impact review

**Tables/columns touched by Phase 1: none.** Full accounting:

| Need | Existing? | Action |
|---|---|---|
| Notification count/table | `mewmii_notifications`, `database/schema.sql:560-574` | Reused as-is |
| Overdue supplier orders | `supplier_orders.expected_delivery_date`/`status` | Reused as-is (query relocated into a shared function, not altered) |
| Open resolutions count | `resolution_requests`, `database/schema.sql:767` | Reused as-is (new query, existing table) |
| Purchase planning, allocation queue, low stock, ready-to-ship | Existing tables/functions | Reused as-is |
| New sidebar entry (Purchase Planning) | `modules/purchase-planning/generate.php` already exists as a page | Reused as-is, gated on existing `supplier-orders.manage` permission (matching the page's own check) — zero new `permissions` rows |

**Migrations required: none.** **Rollback strategy: N/A** — there is no schema change to roll back; every change in Phase 1 is either markup (`header.php`) or a query/function relocation in PHP (fully reversible by reverting the file, no data implication either direction). **No unnecessary schema changes are introduced** — confirmed no new tables, no new columns, no new permission rows, and (per `DASHBOARD_PHILOSOPHY.md` §4's own known tension) no `task_dismissals` table, which stays explicitly deferred past v1.

## 3. Shared component location confirmation

| Component | Confirmed location | Notes |
|---|---|---|
| Drawer | `assets/js/drawer.js` (per `COMPONENT_LIBRARY_SPEC.md` §1) | **Not built in Phase 1** — Phase 2. Confirming only that nothing in Phase 1 conflicts with this reservation. |
| Activity Feed | `modules/activity-log/index.php` (per spec §2) | **Not built in Phase 1** — Phase 2. |
| Notification badge | `includes/header.php`, inserted at the existing Notifications link (`includes/header.php:548-550`) | New markup + one new `notification_unread_count()` call in `header.php`. No new file. |
| AJAX handlers | `modules/search/ajax_quick.php`-style fragment endpoints (existing convention) | **Not needed in Phase 1** — nav consolidation is static markup, the badge is a synchronous count on page load, and Mission Control is server-rendered exactly like today's dashboard. No new AJAX handler in this phase. |
| Shared JS | `assets/js/` for global files loaded via `header.php` (`sidebar.js`, `global_search.js` are the existing examples, both loaded `includes/header.php:519-520`) | Phase 1 needs no new shared JS file — the badge is pure server-rendered markup, no client behavior. |
| Shared CSS | `includes/header.php`'s inline `<style>` block (`includes/header.php:17-488`) | **Correcting an assumption from the component spec:** `assets/css/style.css` exists but is empty and referenced by zero pages (confirmed) — there is no global stylesheet file in actual use today, despite the name suggesting otherwise. All shared CSS genuinely lives inline in `header.php`. Phase 1's badge/nav styling is added there, consistent with current reality rather than inventing a new file the rest of the app doesn't use. |
| Toast/flash message | **Not previously specced — new finding, addressed below** | |

**Toast component — flagged, not built in Phase 1.** Investigating for this review surfaced that **no flash/toast mechanism exists anywhere in the codebase today.** Every module's success/error feedback is a hand-rolled, per-page, query-string-driven `<div class="alert...">` block (e.g. `modules/orders/index.php:159-164`, `modules/products/index.php:298-307`), duplicated independently per module, styled from the shared `.alert` rules already in `header.php:103-126`. This was never part of the approved `COMPONENT_LIBRARY_SPEC.md` (only Drawer, Activity Feed, Bulk Actions, Command Palette, and Notification Badge were specced and approved). Nothing in Phase 1's actual scope — nav consolidation, the badge, or Mission Control — requires a toast. Rather than design and build an unspecced component under review pressure, the recommendation is: **defer a proper Toast spec to a short addendum of `COMPONENT_LIBRARY_SPEC.md` before Phase 2**, when Bulk Actions and the Drawer will actually need transient feedback (a bulk action's per-row result, a drawer save confirmation) in a way the current one-alert-per-page-load pattern can't express. Building it now, unspecced, would violate this project's own "design → implement" order.

## 4. Phase 1 implementation plan

### Step 1 — Navigation consolidation
- **Files affected:** `includes/header.php` only (sidebar markup, lines ~545–627).
- **Changes:** (a) add the missing `Purchase Planning` sidebar entry (`modules/purchase-planning/generate.php`, gated `supplier-orders.manage`, grouped in Operations near Purchasing); (b) visually group the System section's 8 flat items into two labeled sub-groups — Integrations (WooCommerce Sync, Webhook Events, Sync Logs) and System (Job Queue, System Health, Currency Rates, Inventory Reconciliation, Settings, Reset Test Data) — grouping/labels only, no items removed, no permission changes, no file moves.
- **Database impact:** none.
- **Dependencies:** none — first step, as proposed.
- **Testing checklist:** every pre-existing link still points at its original, unchanged URL; the new Purchase Planning link resolves and is hidden/shown correctly under `inventory.view`; `$navActive()` (`includes/header.php:532-543`) still correctly highlights every item including the new one; mobile off-canvas drawer still opens/closes (`sidebar.js` untouched); no PHP warnings/errors on any page load.

### Step 2 — Notification badge
- **Files affected:** `includes/header.php` (one new `notification_unread_count()` call + badge markup at the existing Notifications link).
- **Database impact:** none.
- **Dependencies:** Step 1 (badge is inserted into the nav markup Step 1 just touched — sequencing avoids editing the same block twice in parallel).
- **Testing checklist:** badge count matches `modules/notifications/index.php`'s own count for the same data; badge is absent entirely at zero unread (per `DASHBOARD_PHILOSOPHY.md` "silence is the default state"); badge respects the existing `dashboard.view` gate; visible correctly in the mobile off-canvas drawer.

### Step 3 — Dashboard Mission Control
- **Files affected:** `index.php` (structural rewrite per `DASHBOARD_PHILOSOPHY.md` §3), `includes/dashboard.php` (add the overdue-supplier-orders dedup function and the new open-resolutions count query).
- **Database impact:** none.
- **Dependencies:** Steps 1 and 2 — Mission Control's design assumes unread-notification visibility already lives in the header badge, not as its own dashboard card (`DASHBOARD_PHILOSOPHY.md` §8), so the badge must exist first for the dashboard's Notifications card to be safely removed/folded in.
- **Testing checklist:** all 4 priority workflows unaffected end-to-end (listed in §5 below); Status Line health tier matches the rule table (`DASHBOARD_PHILOSOPHY.md` §5) against real data; My Day task list spot-checked against the underlying raw queries; Today's Business numbers match the existing 30-day sales snapshot; page weight/query count not regressed relative to today's `index.php`; mobile layout; `dashboard.view` permission gate unchanged.

## 5. Testing requirements (applied after each step)

- **Product → Supplier Order → Receiving → Inventory**
- **Customer Order → Allocation → Shipment**
- **Inventory adjustment → WooCommerce sync**
- Permission checks re-verified against the current single-role (`Owner`) reality — noted limitation: there is no seeded non-Owner role to test a *denied* nav item or badge against today (`install.php:33-40` seeds exactly one role), so "verify permissions" for Phase 1 means confirming every `app_has_permission()` call site is unchanged and reasoned through, not observed against a second live role. This gap is exactly what `FUTURE_RBAC.md` exists to close later.
- Mobile layout (off-canvas drawer breakpoint, `includes/header.php:311-407`).
- `docs/CHANGELOG.md` and `docs/IMPLEMENTATION_STATUS.md` updated after each step, not batched at the end.

---

**Gate result: passed.** No database migration is required for Phase 1. No future architecture is blocked. One new component (Toast) was surfaced but deliberately deferred rather than built unspecced. Proceeding to Step 1.
