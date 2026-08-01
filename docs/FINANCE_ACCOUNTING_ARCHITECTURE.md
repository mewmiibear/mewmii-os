# Finance & Accounting — Architecture

**Status:** Design only. No code, no migrations, no database changes. Nothing in this document has been implemented.
**Companion documents:** `FINANCE_WORKFLOW.md`, `FINANCE_DATABASE_DESIGN.md`, `TAX_REPORTING_DESIGN.md`.
**Method:** every claim about "what already exists" below is grounded in a full codebase audit (file:line citations in the sections that need them) — this document does not guess at what Mewmii OS already has.

---

## 1. Current finance capability audit

Mewmii OS already contains a surprising amount of financial data — it has just never been assembled into a Finance module. This is the central finding driving this whole design: **most of Phase 1 (§7 below) is integration, not creation.**

| Capability | Status | Where |
|---|---|---|
| Revenue | Rich, already reportable | `mewmii_orders`/`mewmii_order_items`, `modules/reports/sales.php` (revenue, units, AOV, best-sellers, trend, category breakdowns — all live queries, `payment_status='paid'`-scoped) |
| COGS / Landed Cost | Real formula exists | `includes/product_cost.php`: Landed Cost = Converted Supplier Cost + Shipping Allocation (from `supplier_order_items.shipping_allocated`) + Additional Costs (`supplier_order_item_costs`, free-text type). Used by `modules/reports/margins.php`. |
| Supplier payments (cash out) | Real, but per-PO only | `supplier_order_payments` table, `supplier_order_paid_amount()` (`includes/supplier_orders.php:129-135`), remaining-balance shown on `modules/supplier-orders/view.php`. **No cross-supplier rollup exists.** |
| Refunds (cash out) | Real, status-lifecycle-backed | `resolution_refunds` table (`amount`, `status: pending→completed`), created/read by `includes/order_resolution.php`. |
| Store credit | Real | `customer_wallets`/`customer_wallet_transactions`, via `includes/customer_wallet.php`. (The older `store_credit`/`store_credit_logs` tables are confirmed dead — zero reads/writes.) |
| Currency/exchange rates | Real infrastructure, narrow scope | `currency_rates` table (`rate_type`: supplier/original/market), feeds `products.exchange_rate` for pricing and Landed Cost. `supplier_orders.exchange_rate` is a **separate**, independently-entered per-PO rate. No rate history — updating a rate overwrites it. |
| Outbound shipping cost (what Mewmii pays a carrier) | **Zero** | `shipments` table has no cost column at all — confirmed. The only "shipping fee" figures anywhere are customer-facing charges (`mewmii_orders.shipping_fee`, `ship_requests.shipping_fee`), never Mewmii's own carrier cost. |
| Payment gateway/transaction fees | **Zero** | No column, table, or WooCommerce-import mapping anywhere. WooCommerce's API exposes `fee_lines`; Mewmii OS's importer doesn't consume them. |
| Expenses | **Dead scaffolding only** | `expenses` table exists in schema (`category` VARCHAR(100) free text, `amount`, `receipt_file`, `expense_date`) but has **zero application code** — no create/edit/view page, no module directory. Referenced only by a test-data-reset utility. |
| Invoices | **Dead scaffolding only** | Same situation — `invoices` table exists, zero functional code. |
| Assets, bank accounts, chart of accounts, tax | **Zero**, confirmed nowhere in schema, code, or prior docs | |
| Supplier payment terms/bank details/balance | Free-text only | `suppliers.payment_terms` is a display-only VARCHAR(100), never parsed. No bank-details columns. No outstanding-balance column (only computable per-PO today). |
| File upload conventions | Two real, distinct patterns | `includes/image_upload.php` (public, image-only, WebP conversion) vs `includes/receipt_storage.php` (private, `.htaccess`-denied, image+PDF via `finfo` MIME validation, random filenames, explicit-permission-gated streaming). |
| Permissions | Exactly 20, confirmed no `finance.*` or `reports.*` domain | `install.php:9-30` |

## 2. Missing capabilities

Reading directly off the gaps in §1, in the order they matter for accurate profit reporting:

1. **A real Expenses system** — the single biggest gap; nothing currently records money spent on packaging, marketing, software, utilities, etc.
2. **Outbound shipping cost capture** — margin/profit figures today silently exclude what Mewmii actually pays carriers.
3. **Payment gateway fee capture** — same problem for whatever percentage payment processors take.
4. **A cross-supplier payment rollup** — "how much do we owe Supplier X in total" doesn't exist, only per-PO.
5. **An Asset register**, distinct from Expenses.
6. **Bank/payment accounts** as a reference concept expenses and income can be tagged against.
7. **A Profit & Loss report** that actually nets revenue against COGS and operating expenses — today's reports (`sales.php`, `margins.php`) are both real but neither produces a bottom-line Net Profit figure.
8. **A Cash Flow report** — distinct from P&L (see §5).
9. **Tax-ready report organization** — nothing today is organized for LHDN filing.
10. **Receipt attachment on a financial record** — `receipt_storage.php`'s pattern exists and is directly reusable, but nothing calls it for anything except order payment-proof today.

## 3. Guiding philosophy (unchanged from the rest of v2)

**Finance is an integration layer first, a new data source second.** Every place §1 already has real financial data (revenue, COGS, supplier payments, refunds), Finance reads it — it never re-enters or duplicates it. Finance only introduces new tables for genuinely new concepts (§1's "zero" rows: expenses, gateway fees, outbound shipping cost, assets, bank accounts). This mirrors the exact discipline already applied throughout v2 Phases 1-2 (the dashboard reuses existing functions, the Drawer reuses existing modal/history logic) — Finance is not a special case.

**No rewrite, no unnecessary schema changes.** The dead `expenses`/`invoices` scaffolding tables are not silently repurposed — see `FINANCE_DATABASE_DESIGN.md` §2 for why they're recommended for replacement rather than reuse, and why that's still a minimal, justified change, not scope creep.

## 4. Recommended navigation

The user's proposal is sound; two refinements based on the audit:

```
Finance
├── Expenses            (new — the core data-entry surface)
├── Income               (new, but narrow — see below)
├── Supplier Payments    (renamed from "Payments" — see below)
├── Budget               (new — see §11)
├── Bank Accounts        (new — reference list, reconciliation-ready — see §14)
├── Assets               (new — see §13)
├── Profit & Loss         (report — reads Orders + product_cost.php + Expenses)
└── Cash Flow             (report — reads actual money movement, see §5)
```

**Refinement 1 — "Income" is scoped narrowly.** Order revenue is already a first-class, well-reported concept (`sales.php`). Duplicating it into a manually-maintained "Income" list would violate "no duplicated data entry." Income should be a small, separate concern for genuinely non-order income only (asset sales, grants, misc income) — Profit & Loss pulls order revenue directly from `mewmii_orders`/`mewmii_order_items`, never from an Income table. See `FINANCE_DATABASE_DESIGN.md` §2.

**Refinement 2 — "Payments" renamed "Supplier Payments," scope clarified.** The audit found a real, existing concept this maps to almost exactly: `supplier_order_payments`, currently only visible per-PO. This nav item should be the cross-supplier rollup that's missing today (§2 item 4), reusing the existing table and `supplier_order_paid_amount()` — not a new, generic "Payments" concept that could be confused with Expenses' `payment_method` field.

**Tax Reports** is intentionally not a `Finance` sub-item in the sidebar sense — see `TAX_REPORTING_DESIGN.md` for why it's designed as a report *view* over Finance + existing data, not a data-entry destination.

## 5. Profit & Loss vs. Cash Flow — a real accounting distinction, not two views of the same thing

The user asked for both as separate nav items; this is correct, and the distinction should be explicit in the design rather than assumed:

- **Profit & Loss is accrual-based**: revenue is recognized when an order is paid, COGS is recognized against that same sale (via the Landed Cost formula), operating expenses are recognized when incurred (expense date) — regardless of when cash actually moved.
- **Cash Flow is cash-based**: money that actually left or entered a bank/account — order payments received, supplier payments actually made (`supplier_order_payments`), expenses actually paid (an Expense's own payment date/status), refunds actually paid out (`resolution_refunds` where `status='completed'`).

These will show different numbers in the same period (e.g. a supplier order placed in one month but paid in installments over three shows up once in P&L's COGS-when-sold timing, but spread across Cash Flow's payment dates). This is expected and correct, not a bug to reconcile away — see `FINANCE_WORKFLOW.md` §4.

## 6. Expense vs. Asset — the distinguishing design

Not every purchase is an expense. The recommended rule, applied at data-entry time (a single choice the user makes when recording a purchase, not a later reclassification step):

| | Expense | Asset |
|---|---|---|
| Definition | Consumed in normal operation, no lasting resale/use value beyond the current period | Retains value/utility beyond the current period; the business owns something after the purchase |
| Examples from the user's list | Bubble wrap, boxes, tape, marketing spend, hosting, subscriptions, bank charges | Shelf, printer, laptop, camera, phone, furniture |
| Recorded in | `expenses` | `assets` |
| P&L treatment | Full amount hits Operating Expenses in the period incurred | **Not** an Operating Expense (design-only rule for now — no depreciation yet, per explicit instruction; see `FINANCE_DATABASE_DESIGN.md` §2 for exactly how this is deferred, not ignored) |
| Depreciation | N/A | Reserved for a later phase — the `assets` table is designed so a future `asset_depreciation_schedule` table can attach to it without restructuring anything already built |

The UI-level design (not implementation): when recording a purchase, the form asks "Is this an expense or a business asset?" as the first question, before category — this is a genuine fork, not a category choice, because it determines which table the record lives in.

## 7. Integration points

```
Supplier Orders → Landed Cost (existing, includes/product_cost.php) → COGS in P&L
Customer Orders → mewmii_order_items.subtotal (existing) → Revenue in P&L
Manual Expenses → Operating Expenses in P&L
Shipping (once a cost column exists — see FINANCE_DATABASE_DESIGN.md §4) → COGS or Operating Expense, per cost type — see §8
Payment Gateway (once fee capture exists) → Operating Expenses (transaction fees)
resolution_refunds (existing, status='completed') → reduces Net Revenue in P&L, appears as cash-out in Cash Flow
supplier_order_payments (existing) → Supplier Payments nav rollup + Cash Flow
customer_wallet_transactions (existing) → informational only in Finance; wallet credit/debit is not itself a P&L event (it becomes one only when redeemed against a paid order, which is already captured via normal order revenue)
```

No integration point requires modifying the source module (Orders, Supplier Orders, Inventory) — every arrow above is Finance *reading* an existing table or function, consistent with §3.

## 8. Open design question: where does shipping cost sit in P&L?

Flagged rather than silently decided: outbound shipping cost could reasonably be treated either as part of COGS (cost of fulfilling a sale) or as an Operating Expense (a general business cost not tied to a specific product's margin). Retail/e-commerce accounting commonly does the latter (shipping-to-customer cost is usually an operating "fulfillment expense," not COGS, since COGS is about the cost of the goods themselves). **Recommendation: Operating Expense**, under a dedicated "Shipping" category — this also matches the user's own proposed expense category list, which already includes "Shipping" as a category, implying an Operating Expense treatment was already the intent. Confirm this before `FINANCE_DATABASE_DESIGN.md`'s shipping-cost-capture design (§4 there) is treated as final.

## 9. Future compatibility

- **Multi-Warehouse** (`FUTURE_MULTI_WAREHOUSE.md`): nothing in this design assumes a single stock location. Expenses/Assets have no location assumption baked in; if a warehouse dimension is added later, Finance reports would gain an optional location filter, not require restructuring.
- **RBAC** (`FUTURE_RBAC.md`): new `finance.view`/`finance.manage` permissions (see `FINANCE_DATABASE_DESIGN.md` §5) route through the existing `app_has_permission()` exactly like every other module — no hardcoded Owner check anywhere in this design.
- **API Layer** (`FUTURE_API_LAYER.md`): all Finance business logic (expense creation, P&L calculation) is designed to live in `includes/finance.php`-style function libraries, callable from a future API endpoint exactly like everything else — never embedded in page-rendering code.
- **Multiple currencies**: Finance reuses the existing `currency_rates` infrastructure rather than inventing a second one. Every Expense/Income/Asset record carries its own `currency` + `exchange_rate` at entry time (matching the exact pattern `supplier_orders` already uses — an independently-entered per-record rate, not a live-converted one), so historical reports never retroactively change when today's rate changes.
- **Future mobile app / multi-user**: every Expense/Asset record is designed with a `created_by` (user) column from day one (matching `supplier_order_payments.created_by`'s existing precedent) — Finance is never designed assuming a single implicit actor, consistent with `FUTURE_RBAC.md`'s explicit instruction not to assume single-user forever.

## 10. What this document deliberately does not decide

- Exact column lists and data types — see `FINANCE_DATABASE_DESIGN.md`.
- Depreciation methodology — explicitly out of scope per instruction.
- Tax calculation logic — explicitly out of scope; see `TAX_REPORTING_DESIGN.md` for what *is* in scope (information organization).
- Recurring expense automation logic — reserved shape only, not built (`FINANCE_DATABASE_DESIGN.md` §6).
- OCR for receipts — explicitly out of scope per instruction.
