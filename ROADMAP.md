# Mewmii OS Roadmap

_Last rewritten from a full code audit — every item below was verified directly against the current codebase (schema, `includes/`, `modules/`), not against previous planning documents or conversation history. This replaces the original roadmap, which had drifted significantly from what's actually built._

---

## Phase 1 — Foundation
**Status: ✅ Complete**

- ✅ Project setup (PHP/PDO/MySQL, Bootstrap 5.3.3, no build step)
- ✅ Database structure (`database/schema.sql` — full schema for every module below)
- ✅ Database migrations (manual, SQL-file-based — no formal migration runner, but functional)
- ✅ User login (`login.php`, bcrypt via `password_verify()`, CSRF-protected, session-based)
- ✅ User roles & permissions (`roles`/`permissions`/`role_permissions` tables, `app_has_permission()`/`app_require_permission()` — enforced on every module entry point)
- ✅ Admin dashboard (`index.php` — Operations Command Centre, rebuilt twice: Phase 3 six-section version, then Phase 5A's consolidated Top Summary / Needs Attention / Recent Activity)
- ✅ System settings (`config.php` + `.env` — see Security & Reliability below for the full migration)
- ✅ Audit logs (`audit_logs` table + `app_log_action()` — records login success/failure/logout with IP address; separate `activity_logs` table + `activity_log()` records supplier/inventory/product actions)
- ✅ Login brute-force protection (progressive per-IP+email delay, built on `audit_logs`)

**Not originally planned, built anyway:** environment-based configuration (`.env`, `includes/env_loader.php`, `config.example.php`), a documented backup checklist (`BACKUP.md`).

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

**Customer CRM — 🟡 Partial**
- ✅ Customer profiles (name, email, phone, Instagram username, birthday, address)
- ✅ Customer order history, customer storage view (`modules/customers/view.php`)
- ✅ CSV import for customers
- ⬜ Membership tiers, points, store credit, vouchers — see Phase 9, essentially unbuilt

---

## Phase 3 — Order Management
**Status: ✅ Complete** (for the admin-operations scope this system actually targets)

- ✅ Customer orders, order items (`mewmii_orders`, `mewmii_order_items`)
- ✅ Payment status (`pending|paid|refunded|failed`, badge-driven UI as of Phase 5B)
- ✅ Order status — fully automatic, computed by `order_recompute_status()` from payment + fulfillment + shipment state, never manually set
- ✅ Order timeline events (`mewmii_order_events`, human-readable labels as of Phase 5B — previously raw enum strings)
- ✅ Customer order history (`modules/customers/view.php`)
- ✅ Receipt verification workflow (approve/reject bank-transfer/QR receipts, WordPress round-trip via `includes/wc_receipt_verification.php`, full audit trail)
- ❌ **Customer Portal** — removed from scope. The original roadmap envisioned a customer-facing login inside Mewmii OS; the actual architecture (confirmed in `PROJECT.md`) makes WooCommerce/WordPress the sole customer-facing surface, with Mewmii OS as the internal admin system only. Building a second customer portal here would duplicate WooCommerce's own account pages.

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
Phase 5C ⬜ Not Started (Next)

**Phase 5A — Global layout, design system, dashboard, navigation — ✅ Complete**
- ✅ Neutral, bordered "admin SaaS" design tokens (replaced heavy pink drop-shadows)
- ✅ Sidebar regrouped into labeled sections (Catalog / Sales / Operations / Fulfilment / System) with icons
- ✅ Dashboard consolidated: Top Summary strip, Needs Attention list, Recent Activity timeline

**Phase 5B — Orders, Inventory, Purchase Planning — ✅ Complete**
- ✅ Orders: visible search/filter, payment/receipt badges, Customer info card, merged shipping cards, friendly timeline labels
- ✅ Inventory: attention banner, low/out-of-stock row highlighting, incoming-stock ETA
- ✅ Purchase Planning: always-visible quantity explanation (was hidden behind a click), MOQ impact badge, prominent generate action

**Phase 5C — Supplier Orders, Shipments, Forms, full mobile audit — ⬜ Not Started**
- ⬜ Supplier Orders: status visibility, receiving workflow clarity, overdue highlighting (list page not yet touched — only referenced during the Phase 5 audit)
- ⬜ Shipments: status visibility, tracking display
- ⬜ Forms audit (create/edit pages across Products/Customers/Suppliers) — field grouping, validation messaging
- ⬜ Full mobile responsiveness pass beyond Orders/Inventory (Supplier Orders, Shipments, all forms)

---

## Phase 6 — Inventory, Purchasing & Supplier Operations
**Status: ✅ Complete** (one known gap: inbound tracking numbers)

- ✅ Inventory ledger (`mewmii_inventory`, `inventory_transactions` — every quantity change is ledger-paired)
- ✅ Available / Reserved / Incoming / Arrived stock buckets, correctly separated for ready-stock vs. preorder/early-bird logic
- ✅ Reservation Center (FIFO auto-reserve for paid ready-stock orders)
- ✅ Allocation Center (FIFO auto-allocate arrived preorder/early-bird stock to waiting orders)
- ✅ MOQ calculation, customer-quantity tracking, top-up quantity (`includes/purchase_planning.php`)
- ✅ Purchase Planning (paid-preorder demand + ready-stock target-level replenishment, grouped by supplier, MOQ-rounded suggestions — Phase 5B added always-visible reasoning)
- ✅ One-click Supplier Order Generation from Purchase Planning
- ✅ Supplier order workflow (Draft → Ordered → Partially Received → Received → Completed, plus Cancel)
- ✅ Supplier payment tracking (`estimated_cost`, `actual_cost`, `payment_status`, `payment_date` — contrary to the old roadmap, this is actually implemented)
- ✅ Historical data foundation (`is_historical` flag on customer/supplier orders, bypasses live reservation/receiving)
- ✅ CSV import system (customers, suppliers, historical customer orders, historical supplier orders, inventory opening stock — all-or-nothing validation)
- ⬜ Supplier-side inbound shipment tracking (no carrier/tracking-number field exists on `supplier_orders` itself)
- ⬜ Packing system / parcel photos (no such feature anywhere in the codebase)

---

## Phase 7 — WooCommerce Integration & Sync Automation
**Status: 🟡 Partially Complete** (order sync is the most mature, most heavily engineered part of the entire system; customer sync is the one clear gap)

- ✅ WooCommerce order import — delta-based (`modified_after`, not "always latest 20"), paginated, cron-automated (`cli/wc_order_sync.php`), concurrency-locked, health-monitored, retry-hardened
- ✅ WooCommerce product sync (push Mewmii → WooCommerce, simple + variable products, images, pricing, stock)
- ✅ Payment sync (receipt verification round-trip, WooCommerce order status mapped to `payment_status`)
- ✅ Outbound inventory sync (stock quantity/status pushed on every product sync)
- 🟡 Inventory sync is one-directional (Mewmii → WooCommerce on push; no live reverse sync)
- ⬜ Customer sync — no dedicated bidirectional sync exists; only incidental matching (email/WooCommerce customer ID) happens during order import
- ✅ Mewmii OS is the source of truth for orders/inventory, per the original architecture goal

---

## Phase 8 — Fulfilment & Ship My Box
**Status: ✅ Complete** (core workflow; automatic fee calculation not built)

- ✅ Customer Storage (`customer_storage` table + dedicated module — items physically held for a customer)
- ✅ Ship My Box requests (`ship_requests`/`ship_request_items`, one-button workflow: pending → processing → shipped → completed)
- ✅ Unified Shipments module (`shipments`/`shipment_items` — handles both direct order shipments and Ship My Box requests through one code path)
- ⬜ Automatic shipping-fee calculation (the field exists and is admin-entered; there is no weight/zone-based calculator)

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
**Status: ⬜ Mostly Not Started**

- ✅ Sales Report (`modules/reports/sales.php` — revenue, units sold, top products, period filter)
- ⬜ Invoice system
- ⬜ Expense tracking
- ⬜ Supplier payment report (the underlying data exists on `supplier_orders`, but there's no dedicated reporting view)
- ⬜ Profit calculation report
- ⬜ Tax / LHDN export

---

## Phase 11 — Mobile & Automation Intelligence
**Status: 🟡 Partial (mobile) / ⬜ Not Started (automation)**

- ✅ Mobile-responsive tables (`.responsive-stack-table` pattern, applied to Orders and Inventory in Phase 5B)
- ⬜ PWA installation (no `manifest.json` or service worker anywhere in the codebase)
- 🟡 Mobile dashboard (inherits the responsive dashboard from Phase 5A, not separately audited)
- ⬜ Mobile-specific workflows beyond responsive tables (e.g. a receiving-focused mobile view)
- ⬜ AI assistant, sales-analysis suggestions
- ⬜ Automated notifications — confirmed via full-repo search: **zero** `mail()`/SMTP/notification code exists anywhere in Mewmii OS. Every "alert" today is a visual indicator an admin must actively open the app to see.

---

## Version 1.0 Checklist

- ✅ Product Management
- 🟡 Customer Management (CRM base done; membership/rewards not)
- ✅ Orders (incl. receipt verification)
- ✅ Supplier Purchase (incl. Purchase Planning, payment tracking)
- ✅ Inventory (incl. Reservation/Allocation Centers)
- ✅ Ship My Box / Fulfilment
- ⬜ Membership
- 🟡 WooCommerce Sync (order/product sync mature; customer sync missing)
- 🟡 Basic Reports (sales only)
- ✅ Security & Reliability hardening
- 🟡 UI/UX Modernisation (5A + 5B done, 5C pending)
- ❌ Production readiness confirmed — **unverified operational items remain** (see Critical, below)

---

## Completion Estimates

| Area | Estimate | Why |
|---|---|---|
| Foundation | 95% | Core auth/permissions/audit/settings all real and hardened; only gap is no formal migration tooling |
| Products | 90% | Extremely mature — variations, attributes, images, lifecycle, sync all built and tested across many phases |
| Customers | 55% | Solid CRM base, dragged down hard by membership/rewards being entirely unbuilt despite being a named brand pillar |
| Orders | 90% | Full workflow + receipt verification is genuinely production-hardened; customer portal deliberately out of scope |
| Inventory | 90% | Ledger, reservation, allocation, low-stock detection all real; packing/parcel-photo system missing |
| Purchasing | 90% | MOQ, Purchase Planning, one-click generation, workflow states all built and UX-polished in Phase 5B |
| Suppliers | 85% | Full CRUD + order history + payment tracking; no supplier performance analytics |
| Shipments | 80% | Ship My Box + unified Shipments module both solid; no automatic fee calculation or carrier API |
| WooCommerce | 85% | Order sync is the most hardened part of the system (delta+cron+lock+health+retry); customer sync is the one real gap |
| Security | 85% | Four dedicated hardening phases completed; remaining gaps are 2FA-level polish and unconfirmed backup execution |
| Dashboard | 85% | Rebuilt twice, now a genuine command-centre; Phase 5C mobile/consistency pass still pending |
| Reporting | 20% | Sales report only — no invoicing, expenses, profit, or tax export |
| Membership | 5% | Schema and seed data only; zero working business logic |
| Mobile | 55% | Real responsive pattern exists and is applied to 2 of ~8 major modules; no PWA |
| UI/UX | 65% | Phase 5A complete, 5B complete for 3 modules, 5C (Supplier Orders/Shipments/Forms) not started |

### Overall completion toward Version 1.0: **~70%**

**Why:** the core operational loop a plushie preorder business actually runs on day to day — catalog, orders, receipt verification, inventory, purchasing, supplier management, fulfilment, and WooCommerce sync — is genuinely mature and, in several places (sync reliability, security), more heavily engineered than a typical v1.0 needs. What holds the overall number down is that three areas explicitly named in the project's own vision document are substantially incomplete: Membership & Rewards (a core brand identity feature, ~5% done), Finance/Reporting (~20%), and Mobile/PWA (~55%). None of these block daily operations today, but all three are named, expected pillars of "Mewmii OS" as originally envisioned — the system works without them, but isn't the system that was promised.

---

## Remaining Work Before Version 1.0

### Critical (must finish before launch)
1. **Verify the production admin password is not the old install-time default**, and rotate the WooCommerce API keys/webhook secret now that `.env` migration is complete — both flagged since Phase 4B/4C, neither confirmed closed.
2. **Confirm the production backup process is actually running**, not just documented in `BACKUP.md`.
3. **Decide and implement receipt-rejection evidence retention** (recommendation already made in Phase 4B/4D: Mewmii OS archives a snapshot before WordPress deletes the file) — currently, a rejected receipt's evidence is permanently gone the moment it's rejected.
4. **Confirm every Phase 4/5 code change discussed in this engagement is actually deployed to the live Hostinger server**, not just committed to git — several past sessions surfaced real gaps between "committed" and "live."

### High Priority
5. Finish **UI/UX Phase 5C** (Supplier Orders, Shipments, Forms, full mobile audit) — already scoped, no new technical dependencies, directly improves daily-use screens.
6. **WooCommerce customer sync** — closes a real architecture gap; today a customer only gets matched/linked as a side effect of their first order importing.
7. **Basic reporting expansion** — at minimum a supplier-payment view and a simple profit figure, since the underlying data already exists on `supplier_orders`/`mewmii_orders`.
8. **Automated notifications** (even just email) for the highest-severity events (sync failure, negative stock) — confirmed zero notification infrastructure exists today.

### Medium Priority
9. **Membership & Rewards MVP** — even a minimal version (manual tier assignment + a points ledger UI) would activate a feature that's currently pure unused schema.
10. Packing system / parcel photos for warehouse operations.
11. Supplier-side inbound tracking number (currently only `expected_delivery_date`/`received_date` exist, no carrier tracking).
12. Automatic shipping-fee calculation (currently a manually-entered field).

### Nice to Have
13. PWA installation (manifest + service worker).
14. AI assistant / sales-suggestion automation.
15. 2FA / advanced session security.
16. Tax / LHDN export tooling.

---

## Recommended Next Phase

**Phase 5C — Finish UI/UX Modernisation (Supplier Orders, Shipments, Forms, Mobile).**

**Why this and not something else:**
- **Technical dependencies:** zero new ones — it's a direct continuation of the exact pattern already proven safe and effective across Phase 5A/5B (design-token reuse, no business-logic changes, no schema changes).
- **Production readiness:** the Critical items above (password/key verification, backup confirmation, deployment verification) are one-time operational actions the account owner must perform directly — they are not a "development phase" in the roadmap sense, and shouldn't block or be blocked by feature work. They should happen in parallel, immediately, regardless of which dev phase comes next.
- **Business value:** Supplier Orders and Shipments are used daily by whoever runs receiving and fulfilment — leaving them in the pre-Phase-5 visual state right after modernising Orders/Inventory/Purchase Planning creates a jarring, half-upgraded admin experience, which was explicitly the risk Phase 5 was scoped in small steps to avoid.
- **User experience:** finishing the set is what actually delivers on "no page should feel like an old admin panel anymore" — a partially modernised app reads as unfinished even where the older pages still work correctly.

Membership & Rewards and Finance/Reporting are real gaps, but both are **new feature development**, not modernisation of existing screens — they deserve their own dedicated scoping/audit pass (matching how every other phase in this engagement started: audit first, then build), rather than being folded into a UI-only phase.
