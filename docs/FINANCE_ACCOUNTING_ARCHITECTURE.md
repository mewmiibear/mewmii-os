# Finance & Accounting — Architecture

**Status:** Design document. Phases A and B of the roadmap in §16 have since been implemented (Expense Categories/Expenses/Receipt Attachments/permissions, then Bank Accounts/Manual Income/`bank_account_id` integration) — see `docs/IMPLEMENTATION_STATUS.md` for current, authoritative implementation status. Phases C–F (Assets, Supplier Payments Rollup, Cost Classifications, Profit & Loss, Cash Flow, Budget Planning, Tax Reports) remain design-only, not yet implemented, as described below.
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
| Assets, bank accounts, chart of accounts, tax | **Zero at the time of this audit** (pre-Phase-A baseline). Bank accounts are no longer zero — implemented in Phase B (`bank_accounts` table, `docs/IMPLEMENTATION_STATUS.md`). Assets, chart of accounts, and tax calculation remain zero/not implemented. | |
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

## 8. Shipping Cost Classification (resolved)

The single most consequential classification decision in this design — it directly determines whether Gross Margin figures in P&L are trustworthy. Five distinct cost types were named for analysis; each is classified independently, with reasoning, and the mechanism for applying the classification is designed to be **configurable, not hardcoded** (§8.6), per explicit instruction.

### 8.1 International freight (supplier/factory → Mewmii)
**Recommendation: COGS.** This is the cost of getting purchased inventory into a sellable location — standard landed-cost accounting treatment, and not even a new decision: `includes/product_cost.php`'s existing Landed Cost formula already includes a "Shipping Allocation" component (`supplier_order_items.shipping_allocated`) for exactly this. This confirms existing behavior stays as-is.

### 8.2 Supplier shipping charges (freight billed on the supplier's own invoice, rather than via a separate forwarder)
**Recommendation: COGS.** Functionally identical to §8.1 — same cost, different billing path. Already captured today via the same `shipping_allocated`/`supplier_order_item_costs` mechanism; no new classification needed.

### 8.3 Customs (import duty/clearance fees)
**Recommendation: COGS.** Customs duty is a necessary cost of bringing purchased goods into sellable condition in Malaysia — standard treatment adds it to inventory cost, not operating expense. Already partially captured today: the audit found `supplier_order_item_costs` already accepts a free-text `cost_type` of "Customs." This formalizes that existing usage as COGS rather than introducing a new concept.

### 8.4 Import tax
**Recommendation: Configurable, defaulting to COGS.** This is the one genuinely ambiguous item, and deliberately not force-classified: "import tax" can mean the same thing as customs duty (→ COGS, same reasoning as §8.3) for a business that is *not* SST-registered, where the tax is a real, non-recoverable cost of the goods. But if the business is (or becomes) SST-registered and the tax is a recoverable input credit, it isn't a cost at all — it's a receivable, and forcing it into COGS or Operating Expense would misstate both P&L and the actual amount owed to/from the tax authority. Since Mewmii OS cannot know the business's SST-registration status or how a given import tax line should be treated without being told, this is the clearest case in the whole list for the configurable mechanism in §8.6, defaulted to COGS (the safer assumption for an unregistered small business, matching §8.3's reasoning) rather than assumed recoverable.

### 8.5 Local courier cost to customer (outbound, Mewmii → customer)
**Recommendation: Operating Expense.** This resolves the open question originally flagged in an earlier pass of this document. Standard e-commerce/retail accounting treats outbound shipping-to-customer as a fulfillment/distribution cost, not part of COGS — COGS represents the cost of the goods themselves, not the cost of getting an already-finished good to a buyer. This also matches the user's own expense category list, which already includes "Shipping" as an Operating Expense category (`FINANCE_DATABASE_DESIGN.md` §3's `expense_categories`, under Operations — see §10 below).

### 8.6 The configurable mechanism

Rather than hardcoding any of the five recommendations above into P&L calculation logic, a small new lookup table is proposed — `finance_cost_classifications` (design in `FINANCE_DATABASE_DESIGN.md` §3): one row per cost type (International Freight, Supplier Shipping, Customs, Import Tax, Local Courier — seeded with the recommendations above as `default_treatment`, and an independently-editable `current_treatment`), read by the P&L calculation instead of an `if ($costType === 'shipping')`-style hardcoded branch anywhere in the code. This means a future change of mind (e.g. the business becomes SST-registered and Import Tax should stop being COGS) is a settings change, not a code change — directly satisfying "the design should support future flexibility rather than hardcoding accounting assumptions."

## 9. Budget Planning

**Purpose:** let Mewmii Bear set planned spending per category per month and compare it against actual spending — not a forecasting/rolling-budget engine, a simple plan-vs-actual comparison.

**Core design decision: Budget stores only the plan. Actual spending is never duplicated into a second table — it's computed live from the existing `expenses` table**, filtered to the same category and period. This is the same "integration layer, not a duplicate system" principle the user just reaffirmed as the most important thing to preserve, applied to the newest piece of the design: a budget is a single new number (the plan) attached to data that already exists everywhere else.

```
Budget row: category + month → planned_amount
Actual    : SUM(expenses.amount) WHERE category_id = ? AND expense_date BETWEEN <month start> AND <month end>   (computed, not stored)
Variance  : planned_amount − actual   (positive = under budget, negative = over budget, matching the user's own worked example)
```

**Deliberately not built now** (matching "do not over-engineer" and "design a structure that can be expanded later" exactly): multi-month rolling budgets, budget rollover/carry-forward, budget templates, approval workflows, per-user budget ownership. The one-row-per-category-per-month shape extends to any of these later (a yearly budget is just a different `period_type` value on the same table; rollover is a query, not a schema change) without requiring today's design to anticipate them structurally.

**Dashboard integration** (design only, matching `DASHBOARD_PHILOSOPHY.md`'s existing rule-based, silent-when-healthy philosophy — this is not a new dashboard pattern, it's the same one already governing Mission Control):
- **Budget Used %** / **Remaining Budget** — a Today's-Business-style number, computed live, no new schema.
- **Categories Over Budget** — a My-Day-style rule: if any category's variance is negative this month, it becomes a task-shaped signal ("3 categories over budget this month → Review") exactly like every other My Day source in `DASHBOARD_PHILOSOPHY.md` §4 — a live view, not a stored alert, disappearing the moment spending drops back under budget.
- **Monthly Trend** — a report-tier concern (`FINANCE_WORKFLOW.md` §11), not a dashboard widget — per the Dashboard Philosophy's own rule ("if it doesn't change today, it doesn't belong on today's dashboard"), a trend line is exactly the kind of thing that belongs in a report, not the Mission Control surface.

## 10. Expense Category Structure

Full seed list is in `FINANCE_DATABASE_DESIGN.md` §3.1; the design principle here: **two levels of grouping by default (a parent "group" and its categories), not four or five.** The user's example (`Operations` containing `Packaging`/`Shipping`/`Warehouse`/`Equipment`) is a genuine improvement over the flatter list this design started with — grouping related categories under a small number of recognizable business-area parents makes both the data-entry picker and the tax-facing reports (`TAX_REPORTING_DESIGN.md`) easier to scan, without the deeper nesting (e.g. Packaging → Bubble Wrap → ...) that an earlier pass of this design considered and left as free-text description instead, to avoid a picker deep enough to slow down the single most frequent Finance workflow (recording an expense).

One clarification worth stating explicitly: **"Assets" appearing alongside Marketing/Technology/etc. in the category list is not a real row in `expense_categories`.** Per §6's Expense-vs-Asset rule, an asset purchase never becomes an `expenses` row at all — it lives in the separate `assets` table. "Assets" shows up as a *pseudo-category* only in the broader "where did my money go" reporting view (`FINANCE_WORKFLOW.md` §11), where it's useful to see asset purchases alongside expense categories for a complete cash-out picture, computed from the `assets` table and merged into that one report — never inserted into `expense_categories`, never selectable when recording an expense. This preserves the Expense/Asset structural separation the user already approved rather than quietly reintroducing a third, blended concept.

## 11. Assets — expanded fields

`FINANCE_DATABASE_DESIGN.md` §3 has the full column list; noting here why each new field earns its place, since "do not over-engineer" applies to Assets too, not just Budget: `warranty_expiry` and `disposal_date` are both plain dates with no calculation behind them (no automated warranty-expiry alerting is designed in this phase — that would be a dashboard/notification feature to design explicitly later, not assumed here) — they exist because an Asset Register (`TAX_REPORTING_DESIGN.md` §3) that's missing them isn't actually useful as a register. `notes` is kept separate from `description` (unlike Expenses, which folds notes into one field) because an Asset's description answers "what is this" once at purchase time, while notes accumulate over the asset's life (a repair, a relocation, a condition change) — a genuinely different, ongoing-vs-point-in-time distinction that justifies the two fields where Expenses' single-purchase-event nature didn't need it.

## 12. Bank Accounts — reconciliation-readiness

The user asked for "future support for reconciliation," explicitly not automatic bank sync. The design response is narrow and specific: every `expenses`/`manual_income` record gets an optional `bank_account_id` (upgraded from an earlier draft of this design, which only proposed a free-text payment-method field — see `FINANCE_DATABASE_DESIGN.md` §3 for why this was revised now rather than retrofitted later). This is the one piece of groundwork reconciliation genuinely needs and can't be added after the fact without back-filling historical records: knowing *which* account a transaction moved through. Actual reconciliation (matching a bank statement line-by-line against these tagged transactions, flagging discrepancies) is not designed in this phase — the tagging is the only piece built now, deliberately, because it's the one piece that's expensive to retrofit and cheap to include from day one.

## 13. Business-decision Reporting

Full report designs are in `FINANCE_WORKFLOW.md` §11 — noted here because the user's framing ("reports should focus on helping business decisions, not only accounting") is itself a real design constraint, not just a preference: every report in §11 there answers one of the five business questions the user posed verbatim, and each is checked against whether it needs new data or just a new query over data already designed above (the answer, in every case, is the latter — no report in this design requires a new table beyond what §2-§12 already introduce).

## 14. Future compatibility

- **Multi-Warehouse** (`FUTURE_MULTI_WAREHOUSE.md`): nothing in this design — including the new Budget and Bank Account concepts — assumes a single stock location. Budgets are category-scoped, not location-scoped; if a warehouse dimension is added later, Finance reports would gain an optional location filter, not require restructuring.
- **RBAC** (`FUTURE_RBAC.md`): new `finance.view`/`finance.manage` permissions (see `FINANCE_DATABASE_DESIGN.md` §5) cover Budget Planning and every other Finance sub-area the same way they cover Expenses — one pair, not a permission per sub-feature — and route through the existing `app_has_permission()` exactly like every other module. No hardcoded Owner check anywhere in this design.
- **API Layer** (`FUTURE_API_LAYER.md`): all Finance business logic (expense creation, budget variance, P&L calculation, cost-classification lookups) is designed to live in `includes/finance.php`-style function libraries, callable from a future API endpoint exactly like everything else — never embedded in page-rendering code.
- **Multiple currencies**: Finance reuses the existing `currency_rates` infrastructure rather than inventing a second one. Every Expense/Income/Asset record carries its own `currency` + `exchange_rate` at entry time, matching `supplier_orders`' existing pattern. **Budgets are the one place this needs an explicit rule**, since a plan and its actuals must be comparable: budgets are designed as MYR-denominated only (the business's home currency, and the only currency planning meaningfully happens in) — an expense entered in a foreign currency converts to MYR via its own recorded exchange rate (not a new conversion engine) before being compared against the MYR budget line.
- **Future mobile app / multi-user**: every new table in this update (`budgets`, `finance_cost_classifications`, the `bank_account_id` addition) carries `created_by` from day one, matching `supplier_order_payments.created_by`'s existing precedent — Finance is never designed assuming a single implicit actor.

## 15. Financial Lifecycle / State Model

Designed only where a real state transition exists — most Finance concepts in this design are single-state (an Asset's `status`, already designed; a Bank Account's `is_active`, a plain toggle, not a lifecycle) and get no new state machine here just for the sake of consistency. Three genuinely have one:

### Expenses: Draft → Paid → Archived

```
Draft ──────► Paid ──────► Archived
  └──────────────────────────►┘   (Archived reachable directly from Draft too — e.g. a mistaken/duplicate entry, kept for audit trail rather than hard-deleted)
```

- **Draft**: the default state for every new expense — a real, recorded cost, not a placeholder (there is no separate "not yet real" state before this; a saved expense is a real expense). Counts in **Profit & Loss** immediately, against its `expense_date` (accrual — matches §5's existing accrual rule for Operating Expenses). Does **not** count in **Cash Flow** yet, since no money has moved.
- **Paid**: money has actually left the business. From this point the expense also counts in Cash Flow, dated by when it was marked Paid, not its original `expense_date` (§5's cash-basis rule, unchanged).
- **Archived**: a visibility state, not a financial one — hides the record from active list views without deleting it or changing any historical P&L/Cash Flow figure it already contributed to. Reachable from either Draft or Paid.

This collapses what an earlier pass of this design called `unpaid`/`pending`/`paid` (three states) down to the two the user's example asked for before Archived, since distinguishing "unpaid" from "pending" added a distinction without a clear behavioral difference — exactly the over-engineering the instruction warns against. The accrual/cash split itself isn't lost — it's still fully expressed by Draft-vs-Paid, just with simpler names.

### Supplier Payments: Outstanding → Partial → Paid

**Deliberately not a stored column anywhere.** This is a *derived* state, computed the same way `modules/supplier-orders/view.php` already computes a PO's remaining balance today: `Outstanding` when `supplier_order_paid_amount() = 0`, `Partial` when `0 <` paid `<` total, `Paid` when paid `≥` total. Stating it as a named lifecycle here is documentation, not a new field — the Supplier Payments rollup (Phase D) presents this computed label per supplier/PO, reusing the exact existing figures, matching §7's "no new data entry" rule for this feature exactly.

### Assets: In Use → Disposed / Sold

Already present as the `status` field in `FINANCE_DATABASE_DESIGN.md` §3 (`in_use | disposed | sold`) — restated here only so every real Finance lifecycle is documented in one place. `disposed`/`sold` are both terminal; there's no path back to `in_use` (a disposed asset that returns to service would be a new asset record, not a reversed status, matching how this codebase treats e.g. a cancelled order — a new record, not an un-cancel).

### Deliberately no lifecycle for: Budgets, Bank Accounts, Manual Income, Attachments, Categories

Named explicitly so the absence reads as a decision, not an oversight. A Budget line simply exists for its period; a Bank Account is active or not (a toggle, not a state machine); Manual Income is recorded once, point-in-time; attachments and categories have no meaningful state beyond existing/not.

## 16. Implementation Roadmap

Revised phase order (superseding the order in an earlier pass of this design) — reasoning restated here since it's now the authoritative plan implementation is measured against:

| Phase | Scope | Reason for this position |
|---|---|---|
| **A** | Expense Categories, Expenses, Receipt Attachments, `finance.view`/`finance.manage` permissions | The core gap; nothing else depends on anything except this |
| **B** | Bank Accounts, Manual Income, `bank_account_id` integration | Moved ahead of Assets: every Expense should be able to reference a real bank account from the beginning — retrofitting the relationship after hundreds of expense records exist creates unnecessary cleanup work. Minimizing the Phase-A-only window before this exists is the whole point of the reorder. |
| **C** | Assets | Same entry pattern as A/B, low risk, no longer blocking anything ahead of it |
| **D** | Supplier Payments Rollup, `finance_cost_classifications`, Profit & Loss, Cash Flow | The reporting payoff — depends on A-C existing and populated |
| **E** | Budget Planning, Dashboard Budget widgets | Depends on Expenses (A) and Categories (A) being live |
| **F** | Tax Reports, Receipt Export by year, Annual Reports | Depends on everything above having real data |

**Process for every phase, no exceptions:** Audit → Design → Approval → Implement → Test → Documentation, per the standing project process. Phase B does not begin until Phase A is completed, tested, and reviewed — this applies to every subsequent phase boundary the same way, not just this one.

## 17. What this document deliberately does not decide

- Exact column lists and data types — see `FINANCE_DATABASE_DESIGN.md`.
- Depreciation methodology — explicitly out of scope per instruction.
- Tax calculation logic — explicitly out of scope; see `TAX_REPORTING_DESIGN.md` for what *is* in scope (information organization).
- Recurring expense automation logic — reserved shape only, not built (`FINANCE_DATABASE_DESIGN.md` §6).
- OCR for receipts — explicitly out of scope per instruction.
- Actual bank reconciliation logic (statement import, line-matching, discrepancy flagging) — only the `bank_account_id` tagging groundwork is designed now (§12).
- Automated warranty-expiry alerting — the field exists (§11); a notification/dashboard rule reading it does not, and should be designed explicitly later if wanted, not assumed here.
- Expense Templates — future scope only, reserved shape in `FINANCE_DATABASE_DESIGN.md` (new §), explicitly not part of any phase in §16's roadmap.
- Any approval/review workflow on Expenses, Manual Income, or Assets (e.g. a second person confirming a Draft before it counts) — not requested; the lifecycle in §15 is deliberately status-only, no workflow/routing layer.
