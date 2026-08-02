# Finance & Accounting — Workflow Design

**Status:** Design only. No code, no migrations. Companion to `FINANCE_ACCOUNTING_ARCHITECTURE.md`.
**Principle:** per `MEWMII_OS_V2_PLAN.md` §2 ("Workflow First"), every workflow below is measured by how few steps it takes and how much it reuses instead of re-entering data already in the system.

---

## 1. Recording an expense

The core, most frequent Finance workflow. Designed as a single form, one screen, no multi-step wizard:

1. Is this an **Expense** or a **Business Asset**? (the fork from `FINANCE_ACCOUNTING_ARCHITECTURE.md` §6 — asked first, before anything else, since it determines the destination table)
2. If Expense: Category (two-level dropdown, seeded from the default structure in `FINANCE_DATABASE_DESIGN.md` — Operations [Packaging/Shipping/Warehouse/Equipment/Office Supplies], Marketing, Technology [Software/Hosting/Subscriptions], Professional Services, Finance [Bank Charges/Payment Gateway Fees], Utilities, Travel, Miscellaneous — fully editable/extendable after seeding)
3. Date, Amount, Currency (defaults to MYR, matching every other module's default), Supplier (optional — reuses the existing `suppliers` picker component already used on Supplier Orders, not a new supplier list), Bank/Payment Account (optional, from the new Bank Accounts list — §13), Payment Method, Reference Number, Description/Notes, Tax Deductible flag (defaults on). Status starts at **Draft** automatically — not a field the user sets on this form at all, since every new expense starts there by definition (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §15); moving it to **Paid** is a separate, later action (§4).
4. Optional: attach a receipt (image or PDF) — reuses `receipt_storage.php`'s existing private-storage pattern (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §1), the same convention already proven for order payment-proof receipts.
5. Save.

**Reuse, not re-entry:** the Supplier field reuses the exact supplier picker already built for Supplier Orders — an expense paid to a known supplier (e.g. a courier company that's also in the `suppliers` table) links to that existing record rather than free-typing the name a second time. If the payee isn't an existing supplier (e.g. a software subscription), the field is simply left blank — Finance does not require every expense to have a supplier.

## 2. Recording a business asset

Same entry point as expenses (§1 step 1's fork), different destination:

1. Name/Description, Category (Furniture, Equipment, Electronics, etc. — a separate, small category list from Expenses'), Purchase Date, Purchase Amount, Currency, Supplier (optional, same reused picker), Warranty Expiry (optional date), Notes (a separate, ongoing field from Description — `FINANCE_ACCOUNTING_ARCHITECTURE.md` §11).
2. Optional receipt attachment (same mechanism as §1, multiple receipts supported — §7).
3. Save — no depreciation fields shown at all in this phase (explicitly deferred, `FINANCE_ACCOUNTING_ARCHITECTURE.md` §6). Status defaults to "In Use"; Disposal Date only becomes relevant later, when the asset's status is changed to Disposed/Sold (a separate, later action on the same record — not part of initial entry).

## 3. Reviewing Profit & Loss

1. Open Finance → Profit & Loss.
2. Select period: Daily / Weekly / Monthly / Yearly (matching the exact granularity already offered by `modules/reports/sales.php`'s trend view — same date-bucketing convention, not a new one).
3. See, in order: Sales (from existing order data) → COGS (from existing Landed Cost formula) → **Gross Profit** → Operating Expenses (from the new Expenses table) → **Net Profit** → Profit Margin %.
4. Each line is a link to its own detail — Sales links to `modules/reports/sales.php`'s existing breakdown (not a duplicate view), Operating Expenses links to a filtered Expenses list for that period. This matches the exact "every number links straight to where you'd act on it" principle already established for the Dashboard.

No new calculation engine is designed here beyond simple addition/subtraction of already-computed figures (existing revenue query result, existing Landed Cost batch result, new Expenses sum) — see `FINANCE_DATABASE_DESIGN.md` §7 for exactly which existing functions this reuses.

## 4. Reviewing Cash Flow

Distinct from P&L (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §5) — this report answers "how much actual money moved," not "how much did we earn." Money in: order payments received. Money out: supplier payments made (`supplier_order_payments`, existing), expenses actually paid (Expenses where `status = 'paid'`, using the expense's own payment date, not its entry date), refunds actually paid out (`resolution_refunds` where `status = 'completed'`).

This is why an Expense's `status` field (§1) matters beyond bookkeeping tidiness: an expense entered today but not yet paid (e.g. an invoice on 30-day terms) appears in P&L when incurred but does not appear in Cash Flow until its status changes to Paid.

## 5. Reconciling supplier payments

1. Open Finance → Supplier Payments.
2. See every supplier with an outstanding balance, aggregated **across all their POs** — the cross-supplier rollup identified as missing in the audit (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §2 item 4), built by summing the existing per-PO `supplier_order_paid_amount()` calculations, not a new payment-tracking mechanism.
3. Drill into a supplier to see their individual POs and payments — this reuses `modules/supplier-orders/view.php`'s existing per-PO payment display unchanged, just links to it from a new aggregated entry point.

**No new data entry here at all** — this workflow is purely a new *view* over `supplier_order_payments`, which is why it's listed under "integration," not "new capability," in the architecture doc.

## 6. Handling refunds

Finance does not introduce a new refund-recording step. `resolution_refunds` already has a complete workflow (`includes/order_resolution.php`, unchanged) triggered from `modules/orders/view.php`'s existing resolution flow. Finance's only role is **reading** completed refunds into Cash Flow (§4) and Net Revenue (P&L, §3) — recording a refund still happens exactly where it happens today.

## 7. Attaching and finding receipts

- Attaching: covered in §1/§2 — one optional step per Expense/Asset record, and not limited to one file — an Expense or Asset can carry more than one receipt (e.g. a purchase receipt plus a separate delivery note), shown as a small thumbnail list/gallery on the record rather than a single image slot (`FINANCE_DATABASE_DESIGN.md` §3's `expense_attachments`/`asset_attachments`).
- Finding: a Receipts view (accessible from Expenses, not a separate top-level nav item — receipts are not independent objects, they always belong to an Expense or Asset) with search by date range, category, supplier, or amount — reusing the same filter-card pattern already established across every other module's list page (`.filter-card`, per the design-token conventions documented in `COMPONENT_LIBRARY_SPEC.md`).
- Preview: inline for images, browser-native PDF viewer for PDFs — no custom PDF renderer needed.
- Download: a direct authenticated stream, following `modules/orders/receipt_download.php`'s existing pattern exactly (permission check → activity log entry → `receipt_storage_stream()`).
- **Export by year**: a single action from the Receipts view or Tax Reports (`TAX_REPORTING_DESIGN.md` §4) that bundles every receipt attached to an expense within a selected tax year into one downloadable archive — the same per-file authorization/streaming as a single download, just looped and zipped server-side (`FINANCE_DATABASE_DESIGN.md` §3). No OCR, no content extraction — purely a bulk-download convenience for handing a year's worth of receipts to an accountant.

## 8. Preparing for LHDN tax filing (annual)

See `TAX_REPORTING_DESIGN.md` for the full report design. The workflow itself: once a year (or whenever the business's accountant requests it), open Finance → Tax Reports, select the tax year, and each report (Expense Summary, Expense by Category, Annual Operating Expenses, P&L, Asset Register, Income Summary) is exportable — handed to an accountant or used directly for self-filing. This workflow produces **organized information**, not a computed tax liability (explicitly out of scope).

## 9. Recurring expenses (architecture reserved, not built)

Not implemented in this phase, per explicit instruction. The workflow it will eventually support, so the schema can be designed to not block it (`FINANCE_DATABASE_DESIGN.md` §6): a recurring template (e.g. "Hosting, RM 50/month") generates a reminder — not an auto-created Expense row — on its due date, which the user then confirms/edits into a real Expense via the normal §1 flow. Auto-creation without confirmation is deliberately not the design, since amounts often vary slightly (e.g. usage-based hosting bills) and silent auto-entry would undermine trust in the numbers.

## 11. Setting and reviewing a Budget

1. Open Finance → Budget, select a month (defaults to the current month).
2. For each expense category, enter a planned amount — a flat list, one input per category, no wizard. Categories with no planned amount simply show no budget line for that month (not a zero-budget warning) — an unbudgeted category is a normal, unremarkable state, not an error.
3. The same screen shows, per category, actual spend for the month so far (live `SUM(expenses.amount)`, `FINANCE_DATABASE_DESIGN.md` §3's `budgets` table) and variance, using the same +over/−under notation the user's own example used (Packaging: planned RM300, actual RM265, **+RM35** under; Marketing: planned RM800, actual RM920, **−RM120** over).
4. No separate "review" screen — setting and reviewing a budget are the same page, since the actual-spend column is always live, never a stale snapshot that needs refreshing.

## 12. Reports that answer real business questions

Each of the five questions posed maps to a specific, already-designed report — none require new data beyond what §1-§11 and `FINANCE_DATABASE_DESIGN.md` already introduce:

| Question | Report | Data source |
|---|---|---|
| "Where did my money go this month?" | A spending breakdown by category **and type** — Expenses grouped by category, plus Asset purchases as a pseudo-category, plus Supplier Payments made in the period, all in one list | `expenses`, `assets`, `supplier_order_payments` — broader than Expense by Category (`TAX_REPORTING_DESIGN.md` §3), which is expenses-only |
| "Which expense category increased the most?" | Month-over-month category comparison, sorted by absolute or % change | `SUM(expenses.amount)` per category, current month vs. previous month — one extra date filter on data already grouped for Expense by Category |
| "How much do I spend on packaging per order?" | Packaging category spend ÷ order count, same period | `SUM(expenses.amount) WHERE category = Packaging`, divided by the existing order-count query already used in `modules/reports/sales.php` — a genuine cross-module reuse, not a new metric engine |
| "What is my monthly operating cost?" | Operating Expenses total for the month | Already the Operating Expenses line in P&L (§3) — surfaced as its own standalone quick-answer figure, not a new calculation |
| "What is my true net profit after all expenses?" | Net Profit | This **is** P&L's own bottom line (§3) — the question and the existing report design are the same thing, confirmed here rather than assumed |

## 13. Managing Bank Accounts

1. Open Finance → Bank Accounts — a short list (typically 3-6 rows for a business this size: e.g. "Maybank Business," "Touch 'n Go eWallet," "Wise," "Cash"), each with a name, type, and currency.
2. Adding an account is a 3-field form (name, type, currency) — no bank-integration credentials, no statement upload, matching "do not implement automatic bank sync" exactly.
3. Once an account exists, it becomes selectable on the Expense/Income form (§1) as an optional field. Nothing retroactively requires existing records to have one — this is additive, never a blocking requirement on entry.
4. There is no "Bank Accounts" report/reconciliation view in this phase — the account list exists purely so `bank_account_id` tagging can start now, ready for a future reconciliation feature to sum tagged transactions per account without needing to back-fill history first (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §12).

## 14. Daily Business Owner workflow (illustrative, ties workflows together)

A single walkthrough showing how the pieces compose, not a new feature:

> Mewmii Bear buys packaging tape (RM 45) — records it as an Expense under Operations → Packaging, tags it against her Maybank Business account, attaches the receipt photo, marks it Paid. Partway through the month she glances at Finance → Budget and sees Marketing is already RM120 over its planned RM800 — she knows to slow down ad spend for the rest of the month. At month-end, she opens Profit & Loss, sees Net Profit and margin, and checks Cash Flow to confirm what actually left the bank vs. what's still owed to a supplier on 30-day terms. Once a year, she opens Tax Reports, exports the Annual Operating Expenses and Asset Register (plus a zipped bundle of the year's receipts), and hands all three to her accountant for LHDN filing.

Every step above reuses either an existing page (Supplier Orders, Orders, resolution flow) or a new Finance page designed in this document — nothing requires leaving Mewmii OS or maintaining a spreadsheet in parallel, which is the actual goal this whole module serves.
