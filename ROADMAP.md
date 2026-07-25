# Mewmii OS Roadmap

_Rewritten from a full code audit, then kept current through Phases 5C–5F and a round of operational/data-integrity fixes. Every item below reflects what's actually in the codebase, not what was originally planned._

---

## Phase 1 — Foundation
**Status: ✅ Complete**

- ✅ Project setup (PHP/PDO/MySQL, Bootstrap 5.3.3, no build step)
- ✅ Database structure (`database/schema.sql` — full schema for every module below)
- ✅ Database migrations (manual, SQL-file-based — no formal migration runner, but functional)
- ✅ User login (`login.php`, bcrypt via `password_verify()`, CSRF-protected, session-based)
- ✅ User roles & permissions (`roles`/`permissions`/`role_permissions` tables, `app_has_permission()`/`app_require_permission()` — enforced on every module entry point)
- ✅ Admin dashboard (`index.php` — Operations Command Centre, rebuilt three times: Phase 3's six-section version, Phase 5A's consolidated Top Summary/Needs Attention/Recent Activity, Phase 5D's added Operations Overview action-focused stat row)
- ✅ System settings (`config.php` + `.env` — see Security & Reliability below for the full migration)
- ✅ Audit logs (`audit_logs` table + `app_log_action()` — records login success/failure/logout with IP address; separate `activity_logs` table + `activity_log()` records supplier/inventory/product actions)
- ✅ Login brute-force protection (progressive per-IP+email delay, built on `audit_logs`)

**Not originally planned, built anyway:** environment-based configuration (`.env`, `includes/env_loader.php`, `config.example.php`), a documented backup checklist (`BACKUP.md`), a read-only Inventory Reconciliation diagnostic (see Phase 6).

---

## Phase 2 — Product & Customer Management
**Status: 🟡 Partially Complete** (Products: done. Customer CRM base: done. Membership/Rewards: not started — see Phase 9.)

**Product Management — ✅ Complete**
- ✅ Simple and variable products, with full attribute/variation management (`modules/products/`, extensive `ajax/` subdirectory for gallery, variation generation, attribute values)
- ✅ Product categories, brands, collections, tags (dedicated CRUD modules for each)
- ✅ Product images (main + gallery + per-variation images, WebP conversion pipeline in `includes/image_upload.php`)
- ✅ Product lifecycle (`ready_stock` / `preorder` / `early_bird`, closing dates, reopen logic, sale pricing windows)
- ✅ Product Control Center (cross-linked view: orders, supplier orders, inventory for one product)
- ✅ WooCommerce product sync (push-to-WooCommerce, `includes/wc_client.php`)
- ✅ Products list decluttered (removed the redundant plain-text "Availability" column that duplicated the Stage badge, and the product-draft/active "Status" column that duplicated inventory reality; replaced with one **Stock** column: quantity if in stock, a red "Out of Stock" badge if not — Stage and Stock now each answer one clear question instead of overlapping)

**Customer CRM — 🟡 Partial**
- ✅ Customer profiles (name, email, phone, Instagram username, birthday, address)
- ✅ Customer order history, customer storage view (`modules/customers/view.php`)
- ✅ CSV import for customers
- ⬜ Membership tiers, points, store credit, vouchers — see Phase 9, essentially unbuilt

---

## Phase 3 — Order Management
**Status: ✅ Complete** (for the admin-operations scope this system actually targets)

- ✅ Customer orders, order items (`mewmii_orders`, `mewmii_order_items`)
- ✅ Order Date now visible on the Orders list and editable on the Order edit page (`order_date`, the pre-existing column that already fed Sales Report/FIFO reservation ordering) — CSRF- and permission-protected via the existing edit form, no new endpoint
- ✅ Payment status (`pending|paid|refunded|failed`, badge-driven UI as of Phase 5B) with **bulk Approve Payment** (Phase 5E.1) — multi-select on the Orders list, each order approved in its own transaction so one failure never blocks another's success, reusing `apply_payment_status_change()`/`inventory_reserve_for_order_partial()`/`order_recompute_status()` unchanged
- ✅ Order status — fully automatic, computed by `order_recompute_status()` from payment + fulfillment + shipment state, never manually set
- ✅ Order timeline events (`mewmii_order_events`, human-readable labels as of Phase 5B — previously raw enum strings)
- ✅ Order source badges — 🟣 W for WooCommerce (`woocommerce_order_id IS NOT NULL`), gray "Historical" for CSV imports, no badge for manually created orders (no reliable per-order channel data exists for those yet — see Remaining Work). Order numbers display compactly (`#12958` instead of "WooCommerce Order #12958") across every HTML display location app-wide, via a new `order_display_number_compact()` that leaves the original plain-text `order_display_number()` untouched (still used in JSON/error/non-HTML contexts)
- ✅ Orders list reworked into an operational queue (Phase 5E cleanup): **Active / Completed / Cancelled / All** tabs (Active excludes completed/cancelled by default), oldest-order-first sorting on the Active tab so the longest-waiting order surfaces first, an order-age indicator ("6 days ago") with a "⏱ Waiting" badge past a 3-day threshold, and real pagination (replacing a hard 20-row cutoff) — all existing filters (search/status/payment/bulk-approve) preserved
- ✅ Customer order history (`modules/customers/view.php`)
- ✅ Receipt verification workflow (approve/reject bank-transfer/QR receipts, WordPress round-trip via `includes/wc_receipt_verification.php`, full audit trail)
- ❌ **Customer Portal** — removed from scope. The original roadmap envisioned a customer-facing login inside Mewmii OS; the actual architecture (confirmed in `PROJECT.md`) makes WooCommerce/WordPress the sole customer-facing surface, with Mewmii OS as the internal admin system only.

---

## Phase 4 — Security & Reliability
**Status: ✅ Complete**

- ✅ Secrets migrated out of tracked files (`config.php` untracked/gitignored, `.env` + `includes/env_loader.php`, backward-compatible during transition, since fully cut over)
- ✅ Default-admin-password risk closed (`install.php` no longer has a hardcoded fallback — generates a random password if `APP_ADMIN_PASSWORD` isn't set)
- ✅ Admin audit trail restored (`app_log_action()` — was a disabled no-op, now writes real rows)
- ✅ Login brute-force protection (progressive delay, IP + email based, resets on success, never a hard account lock)
- ✅ WooCommerce sync concurrency protection (MySQL advisory lock — cron and the manual "Import Orders Now" button can never run concurrently)
- ✅ WooCommerce sync health monitoring (time-based Healthy/Warning/Critical, surfaced on both the dashboard and the integration page)
- ✅ WooCommerce API reliability (bounded single retry for transient failures/5xx/429, GET-only to avoid duplicate-write risk on retry)
- ✅ Production backup requirements documented (`BACKUP.md` — critical data, frequency, restore order; **execution not yet confirmed**, see Version 1.0 Checklist)
- 🟡 Receipt rejection evidence retention — decision made (recommended: Mewmii OS archives a snapshot before WordPress deletes), **not yet implemented**

---

## Phase 5 — UI / UX Modernisation
**Status:**
Phase 5A ✅ Complete
Phase 5B ✅ Complete
Phase 5C ✅ Complete
Phase 5D ✅ Complete
Phase 5E ✅ Complete
Phase 5F 🟡 Partial (5F.1 done; sales-velocity hint on Purchase Planning deferred)
Forms audit + full mobile pass — ⬜ Not Started (the one remaining item from the original Phase 5 scope)

**Phase 5A — Global layout, design system, dashboard, navigation — ✅ Complete**
- ✅ Neutral, bordered "admin SaaS" design tokens (replaced heavy pink drop-shadows)
- ✅ Sidebar regrouped into labeled sections (Catalog / Sales / Operations / Fulfilment / **Reports** / System) with icons — Reports added in the 5F pass, see below
- ✅ Dashboard consolidated: Top Summary strip, Needs Attention list, Recent Activity timeline

**Phase 5B — Orders, Inventory, Purchase Planning — ✅ Complete**
- ✅ Orders: visible search/filter, payment/receipt badges, Customer info card, merged shipping cards, friendly timeline labels
- ✅ Inventory: attention banner, low/out-of-stock row highlighting, incoming-stock ETA
- ✅ Purchase Planning: always-visible quantity explanation (was hidden behind a click), MOQ impact badge, prominent generate action

**Phase 5C — Supplier Orders, Shipments, Inventory Receiving — ✅ Complete**
- ✅ Supplier Orders: status badges, search/filter, supplier-avatar placeholder, receiving progress bar, payment summary card, cleaner Items table
- ✅ Shipments: status/carrier/tracking visibility on the list, tracking card + timeline icons on the detail page
- ✅ Inventory Receiving covered inline (Supplier Order detail's Items table + receive/mark-arrived actions — no separate receiving page exists)
- ✅ Mobile: `.responsive-stack-table` extended to Supplier Orders and Shipments lists (previously only Orders/Inventory)
- ⬜ Forms audit (create/edit pages across Products/Customers/Suppliers) — never scoped into a phase, still open

**Phase 5D — Dashboard, Order Fulfillment Visibility, Inventory Quick Filters — ✅ Complete**
- ✅ Dashboard "Operations Overview" — action-focused stat row (Pending Customer Orders, Orders Waiting Fulfillment, Low Stock, Incoming Supplier Stock, Pending Supplier Orders, Overdue Supplier Orders, Shipments Waiting Action), each linking to its filtered list
- ✅ Order detail: color-coded fulfillment badges per item; a `waiting_stock` item now shows available quantity, incoming quantity, and — if one exists — the status/expected-delivery-date of the supplier order already covering it, directly answering "why can't this order ship yet"
- ✅ Inventory: two new quick-filter chips (Out of Stock, Arrived/Ready to Allocate), an "Incoming" badge distinguishing incoming stock from a plain number

**Phase 5E — Orders Bulk Payments, Supplier Order Intelligence, Ship My Box — ✅ Complete**
- ✅ 5E.1: Orders bulk Approve Payment (see Phase 3 above)
- ✅ 5E.2: Supplier Order Intelligence — demand breakdown per line (customer-driven vs. MOQ top-up vs. stock top-up, using data `purchase_planning_generate()` already stored but never displayed), and a "Blocking N customer orders" priority badge on open supplier orders, computed from the same demand-definition functions the Reservation/Allocation Centers already use
- ✅ 5E.3: Ship My Box modernised to the same standard as Orders/Supplier Orders/Shipments/Inventory — status badges, search/status filter, quick-filter chips (New/Preparing/Needs Action/Completed), responsive table

**Phase 5F — Inventory Intelligence — 🟡 Partial**
- ✅ 5F.1: new `modules/reports/inventory.php` — Dead Stock/Slow-Moving (real stock, zero sales in a selectable period, ranked by capital tied up), Fast Movers At Risk (a plain "sold ≥ current stock" ratio flag — explicitly not a forecast), and a Total/Slow-Moving Inventory Value summary. A new "Reports" sidebar group now links both this and the existing Sales Report (previously only reachable from the dashboard, no nav entry at all).
- ⬜ 5F.2 (deferred): sales-velocity hint on Purchase Planning lines ("~X sold/week" next to each suggested reorder quantity)

---

## Phase 6 — Inventory, Purchasing & Supplier Operations
**Status: ✅ Complete** (one known gap: inbound tracking numbers)

- ✅ Inventory ledger (`mewmii_inventory`, `inventory_transactions` — every quantity change is ledger-paired; **verified exhaustively** — every one of the 18 distinct `UPDATE mewmii_inventory` mutation sites in the codebase was traced and confirmed to pair with a logged ledger transaction, with zero unlogged exceptions found)
- ✅ Available / Reserved / Incoming / Arrived / Customer Storage stock buckets, correctly separated for ready-stock vs. preorder/early-bird logic
- ✅ **Inventory Reconciliation tool** (`modules/settings/inventory_reconciliation.php`, new, `settings.manage`-gated, read-only) — compares live `mewmii_inventory` quantities against balances reconstructed from `inventory_transactions`. Available/Incoming/Customer Storage quantity are checked as **exact** (every transaction type touching them has one unambiguous effect); Reserved/Arrived quantity are checked as **advisory/best-effort only** and clearly labelled as such, since two specific ledger limitations (the `order_ship` reserved/available split, and `customer_storage_add`'s undocumented debit source) mean a flagged mismatch there can be an expected false positive, not a real bug
- ✅ Reservation Center (FIFO auto-reserve for paid ready-stock orders)
- ✅ Allocation Center (FIFO auto-allocate arrived preorder/early-bird stock to waiting orders)
- ✅ MOQ calculation, customer-quantity tracking, top-up quantity (`includes/purchase_planning.php`), now **displayed** per supplier-order line (Phase 5E.2), not just stored
- ✅ Purchase Planning (paid-preorder demand + ready-stock target-level replenishment, grouped by supplier, MOQ-rounded suggestions — Phase 5B added always-visible reasoning)
- ✅ One-click Supplier Order Generation from Purchase Planning
- ✅ Supplier order workflow (Draft → Ordered → Partially Received → Received → Completed, plus Cancel), now with a "Blocking N customer orders" priority signal (Phase 5E.2)
- ✅ Supplier payment tracking (`estimated_cost`, `actual_cost`, `payment_status`, `payment_date`)
- ✅ Historical data foundation (`is_historical` flag on customer/supplier orders, bypasses live reservation/receiving)
- ✅ CSV import system (customers, suppliers, historical customer orders, historical supplier orders, inventory opening stock — all-or-nothing validation)
- ⬜ Supplier-side inbound shipment tracking (no carrier/tracking-number field exists on `supplier_orders` itself)
- ⬜ Packing system / parcel photos (no such feature anywhere in the codebase)

---

## Phase 7 — WooCommerce Integration & Sync Automation
**Status: 🟡 Partially Complete** (order sync is the most mature, most heavily engineered part of the entire system; customer sync is the one clear gap)

- ✅ WooCommerce order import — delta-based (`modified_after`, not "always latest 20"), paginated, cron-automated (`cli/wc_order_sync.php`), concurrency-locked, health-monitored, retry-hardened
- ✅ **Custom order status "shipped" mapping fixed** — `wc_order_import_map_payment_status()` was missing this status entirely (added by a shipping/tracking plugin, not a default WooCommerce status), so any order WooCommerce moved to "Shipped" was silently skipped on every subsequent sync — its totals/receipt fields froze while WooCommerce's own admin showed it as shipped. Now maps to `paid`, same as `processing`/`completed`. Confirmed: `order_recompute_status()` was not touched and still never reads WooCommerce's status directly — Mewmii OS remains sole owner of `order_status`, by design.
- 🟡 Other custom statuses (e.g. `partial-shipped`) audited but not added — no confirmation yet which shipping/tracking plugin, if any beyond the "shipped" status already observed, is actually configured on the live store
- ✅ WooCommerce product sync (push Mewmii → WooCommerce, simple + variable products, images, pricing, stock)
- ✅ Payment sync (receipt verification round-trip, WooCommerce order status mapped to `payment_status`)
- ✅ Outbound inventory sync (stock quantity/status pushed on every product sync)
- 🟡 Inventory sync is one-directional (Mewmii → WooCommerce on push; no live reverse sync)
- ⬜ Customer sync — no dedicated bidirectional sync exists; only incidental matching (email/WooCommerce customer ID) happens during order import
- ✅ Mewmii OS is the source of truth for orders/inventory, per the original architecture goal

---

## Phase 8 — Fulfilment & Ship My Box
**Status: ✅ Complete** (core workflow and UI both modernised; automatic fee calculation not built)

- ✅ Customer Storage (`customer_storage` table + dedicated module — items physically held for a customer)
- ✅ Ship My Box requests (`ship_requests`/`ship_request_items`, one-button workflow: pending → processing → shipped → completed), UX-modernised in Phase 5E.3 (status badges, filters, quick-filter chips)
- ✅ Unified Shipments module (`shipments`/`shipment_items` — handles both direct order shipments and Ship My Box requests through one code path), UX-modernised in Phase 5C
- ⬜ Automatic shipping-fee calculation (the field exists and is admin-entered; there is no weight/zone-based calculator)
- ⬜ Shipments list filters (status/date-range quick filters — scoped during the Phase 5F planning pass, not yet built)

---

## Phase 9 — Membership & Rewards
**Status: ⬜ Not Started**

Verified directly: `membership_tiers` and `point_transactions` tables exist and are seeded (Baby/Silver/Gold/VIP Bear, per `install.php`), and the Customers list already **displays** a Tier column and a Points figure (`modules/customers/index.php`) — but there is no code anywhere that writes to either table. No way to award points, assign/upgrade a tier, issue a voucher, or trigger a birthday reward exists. This is schema and a read-only display column, not a working feature.

- ⬜ Points system (earn/redeem)
- ⬜ Membership tier assignment/upgrade logic
- ⬜ Store credit
- ⬜ Monthly vouchers, birthday rewards, VIP birthday gifts

---

## Phase 10 — Finance & Business Reporting
**Status: 🟡 Partial** (upgraded from "Mostly Not Started" — Inventory Intelligence added a real second report)

- ✅ Sales Report (`modules/reports/sales.php` — revenue, units sold, top products, period filter)
- ✅ Inventory Intelligence Report (`modules/reports/inventory.php`, new, Phase 5F.1) — Dead Stock/Slow-Moving value, Fast Movers At Risk, Total/Slow-Moving Inventory Value
- ⬜ Invoice system
- ⬜ Expense tracking
- ⬜ Supplier payment report (the underlying data exists on `supplier_orders`, but there's no dedicated reporting view)
- ⬜ Profit calculation report
- ⬜ Tax / LHDN export

---

## Phase 11 — Mobile & Automation Intelligence
**Status: 🟡 Partial (mobile) / ⬜ Not Started (automation)**

- ✅ Mobile-responsive tables (`.responsive-stack-table` pattern) — now applied to Orders, Inventory, Supplier Orders, and Shipments (4 of ~8 major list pages, up from 2)
- ⬜ PWA installation (no `manifest.json` or service worker anywhere in the codebase)
- 🟡 Mobile dashboard (inherits the responsive dashboard from Phase 5A, not separately audited)
- ⬜ Mobile-specific workflows beyond responsive tables (e.g. a receiving-focused mobile view)
- ⬜ AI assistant, sales-analysis suggestions
- ⬜ Automated notifications — confirmed via full-repo search: **zero** `mail()`/SMTP/notification code exists anywhere in Mewmii OS. Every "alert" today is a visual indicator an admin must actively open the app to see.

---

## Version 1.0 Checklist

- ✅ Product Management
- 🟡 Customer Management (CRM base done; membership/rewards not)
- ✅ Orders (incl. receipt verification, bulk payment approval, source badges, operational queue view)
- ✅ Supplier Purchase (incl. Purchase Planning, payment tracking, demand breakdown, priority visibility)
- ✅ Inventory (incl. Reservation/Allocation Centers, Reconciliation diagnostic)
- ✅ Ship My Box / Fulfilment
- ⬜ Membership
- 🟡 WooCommerce Sync (order/product sync mature and recently hardened further; customer sync missing)
- 🟡 Reports (Sales + Inventory Intelligence; no invoicing/expense/profit/tax)
- ✅ Security & Reliability hardening
- 🟡 UI/UX Modernisation (5A–5F substantially complete; Forms audit + full mobile pass remain)
- ❌ Production readiness confirmed — **unverified operational items remain** (see Critical, below — unchanged since Phase 4, no new evidence either way)

---

## Completion Estimates

| Area | Estimate | Why |
|---|---|---|
| Foundation | 95% | Core auth/permissions/audit/settings all real and hardened; only gap is no formal migration tooling |
| Products | 90% | Extremely mature — variations, attributes, images, lifecycle, sync all built; Stock/Stage display cleanup removed the last real UX confusion |
| Customers | 55% | Solid CRM base, dragged down hard by membership/rewards being entirely unbuilt despite being a named brand pillar |
| Orders | 93% | Full workflow + receipt verification + bulk payment approval + operational queue view (tabs/age/sorting) + source badges; customer portal deliberately out of scope |
| Inventory | 93% | Ledger, reservation, allocation, low-stock detection, dead-stock/fast-mover reporting, and a verified reconciliation tool all real; packing/parcel-photo system missing |
| Purchasing | 92% | MOQ, Purchase Planning, one-click generation, demand breakdown, priority visibility all built and UX-polished |
| Suppliers | 88% | Full CRUD + order history + payment tracking + Phase 5C UX polish; no supplier performance analytics |
| Shipments | 83% | Ship My Box + unified Shipments module both solid and UX-modernised; no automatic fee calculation, carrier API, or list filters yet |
| WooCommerce | 87% | Order sync is the most hardened part of the system, and a real sync-freeze bug (custom "shipped" status) was just fixed; customer sync is the one real gap |
| Security | 85% | Four dedicated hardening phases completed; remaining gaps are 2FA-level polish and unconfirmed backup execution |
| Dashboard | 90% | Rebuilt three times, now a genuine action-focused command-centre (Phase 5D) |
| Reporting | 30% | Two real reports now (Sales, Inventory Intelligence) — still no invoicing, expenses, profit, or tax export |
| Membership | 5% | Schema and seed data only; zero working business logic |
| Mobile | 65% | Responsive pattern now applied to 4 of ~8 major modules; no PWA |
| UI/UX | 85% | Phases 5A–5E complete, 5F mostly complete; only Forms audit + full mobile pass remain from the original Phase 5 scope |

### Overall completion toward Version 1.0: **~77%**

**Why the jump from ~70%:** this round closed the entire "half-upgraded admin" problem the roadmap previously flagged — Supplier Orders, Shipments, and Ship My Box now match the Orders/Inventory visual standard, the dashboard became genuinely action-oriented, Orders gained a real operational-queue workflow (tabs, sorting, age, bulk actions), and Reporting doubled in scope with the Inventory Intelligence report. A real data-integrity gap (no way to verify the inventory ledger stayed correct) was also closed with the Reconciliation tool, and a live sync-freeze bug was found and fixed. What still holds the number down is the same as before: Membership & Rewards (~5%), deeper Finance/Reporting (invoicing/expense/profit/tax), and full PWA/mobile-workflow coverage — none of which block daily operations, but all three are named pillars of the original vision that remain substantially unbuilt.

---

## Remaining Work Before Version 1.0

### Critical (must finish before launch) — unchanged since Phase 4, still unconfirmed
1. **Verify the production admin password is not the old install-time default**, and rotate the WooCommerce API keys/webhook secret now that `.env` migration is complete.
2. **Confirm the production backup process is actually running**, not just documented in `BACKUP.md`.
3. **Decide and implement receipt-rejection evidence retention** (recommendation already made: Mewmii OS archives a snapshot before WordPress deletes the file).
4. **Confirm every code change from this entire engagement — Phase 4 through Phase 5F, plus the recent Orders/WooCommerce/Inventory fixes — is actually deployed to the live Hostinger server**, not just committed to git.

### High Priority
5. **Forms audit** (create/edit pages across Products/Customers/Suppliers) — the one remaining item from the original Phase 5 scope; field grouping, validation messaging, visual consistency with the now-modernised list/detail pages.
6. **WooCommerce customer sync** — closes a real architecture gap; today a customer only gets matched/linked as a side effect of their first order importing.
7. **Reporting expansion** — a supplier-payment view and a simple profit figure, since the underlying data already exists on `supplier_orders`/`mewmii_orders`; invoicing and tax export are larger, separate efforts.
8. **Automated notifications** (even just email) for the highest-severity events (sync failure, negative stock) — confirmed zero notification infrastructure exists today.
9. Confirm whether `partial-shipped` (or other custom WooCommerce statuses) are actually configured on the live store, and extend the status-mapping fix if so.

### Medium Priority
10. **Membership & Rewards MVP** — even a minimal version (manual tier assignment + a points ledger UI) would activate a feature that's currently pure unused schema.
11. Shipments list filters (status/date-range quick filters).
12. Sales-velocity hint on Purchase Planning lines (Phase 5F.2, deferred).
13. Real per-order source tracking (`order_source` column: Instagram DM/WhatsApp/Phone/Walk-in) — needed if you want the source badge to go beyond WooCommerce/Historical/nothing; requires a schema change, previously scoped but deferred pending approval.
14. Packing system / parcel photos for warehouse operations.
15. Supplier-side inbound tracking number.
16. Automatic shipping-fee calculation.

### Nice to Have
17. PWA installation (manifest + service worker).
18. AI assistant / sales-suggestion automation.
19. 2FA / advanced session security.
20. Tax / LHDN export tooling.

---

## Recommended Next Phase

**Forms Audit — the last piece of the original Phase 5 UI/UX Modernisation scope.**

**Why this and not something else:**
- **Technical dependencies:** zero new ones — same proven pattern as every other Phase 5 sub-phase (design-token reuse, no business-logic changes, no schema changes), and it's the one item from the *original* Phase 5 scope that's been carried forward, unstarted, since Phase 5A.
- **Production readiness:** the Critical items above are one-time operational actions for the account owner to perform directly — not a development phase, and shouldn't block or be blocked by feature work. They should happen in parallel, now.
- **Business value:** every list/detail page in the app has now been modernised (Orders, Inventory, Supplier Orders, Shipments, Ship My Box) — but the create/edit *forms* behind them haven't been touched since before Phase 5 started. That's now the most visible remaining inconsistency: a polished list page leading into an old-style form is a jarring transition, the exact problem Phase 5 was scoped to eliminate everywhere else.
- **Closes the loop:** finishing this is what actually completes "no page should feel like an old admin panel anymore" — right now that's true everywhere except the forms.

Runner-up candidates — **WooCommerce customer sync** and **Reporting expansion** — are both real gaps but are new feature/architecture work, not modernisation of existing screens, and (matching how every phase in this engagement has started) deserve their own dedicated audit-first pass rather than being folded into a UI-only phase.
