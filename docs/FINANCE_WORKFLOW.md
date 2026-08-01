# Finance & Accounting — Workflow Design

**Status:** Design only. No code, no migrations. Companion to `FINANCE_ACCOUNTING_ARCHITECTURE.md`.
**Principle:** per `MEWMII_OS_V2_PLAN.md` §2 ("Workflow First"), every workflow below is measured by how few steps it takes and how much it reuses instead of re-entering data already in the system.

---

## 1. Recording an expense

The core, most frequent Finance workflow. Designed as a single form, one screen, no multi-step wizard:

1. Is this an **Expense** or a **Business Asset**? (the fork from `FINANCE_ACCOUNTING_ARCHITECTURE.md` §6 — asked first, before anything else, since it determines the destination table)
2. If Expense: Category (dropdown, seeded from the user's list — Packaging [with sub-category: Bubble Wrap/Boxes/Tape/Stickers/Thank-you Cards/Labels], Shipping, Marketing, Software, Hosting, Utilities, Office Supplies, Equipment, Travel, Bank Charges, Payment Gateway Fees, Subscriptions, Professional Services, Miscellaneous)
3. Date, Amount, Currency (defaults to MYR, matching every other module's default), Supplier (optional — reuses the existing `suppliers` picker component already used on Supplier Orders, not a new supplier list), Payment Method, Reference Number, Description/Notes, Tax Deductible flag (defaults on), Status (Paid / Unpaid / Pending — see §4 for why this matters for Cash Flow)
4. Optional: attach a receipt (image or PDF) — reuses `receipt_storage.php`'s existing private-storage pattern (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §1), the same convention already proven for order payment-proof receipts.
5. Save.

**Reuse, not re-entry:** the Supplier field reuses the exact supplier picker already built for Supplier Orders — an expense paid to a known supplier (e.g. a courier company that's also in the `suppliers` table) links to that existing record rather than free-typing the name a second time. If the payee isn't an existing supplier (e.g. a software subscription), the field is simply left blank — Finance does not require every expense to have a supplier.

## 2. Recording a business asset

Same entry point as expenses (§1 step 1's fork), different destination:

1. Name/Description, Category (Furniture, Equipment, Electronics, etc. — a separate, small category list from Expenses'), Purchase Date, Purchase Amount, Currency, Supplier (optional, same reused picker), Notes.
2. Optional receipt attachment (same mechanism as §1).
3. Save — no depreciation fields shown at all in this phase (explicitly deferred, `FINANCE_ACCOUNTING_ARCHITECTURE.md` §6).

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

- Attaching: covered in §1/§2 — one optional step per Expense/Asset record.
- Finding: a Receipts view (accessible from Expenses, not a separate top-level nav item — receipts are not independent objects, they always belong to an Expense or Asset) with search by date range, category, supplier, or amount — reusing the same filter-card pattern already established across every other module's list page (`.filter-card`, per the design-token conventions documented in `COMPONENT_LIBRARY_SPEC.md`).
- Preview: inline for images, browser-native PDF viewer for PDFs — no custom PDF renderer needed.
- Download: a direct authenticated stream, following `modules/orders/receipt_download.php`'s existing pattern exactly (permission check → activity log entry → `receipt_storage_stream()`).

## 8. Preparing for LHDN tax filing (annual)

See `TAX_REPORTING_DESIGN.md` for the full report design. The workflow itself: once a year (or whenever the business's accountant requests it), open Finance → Tax Reports, select the tax year, and each report (Expense Summary, Expense by Category, Annual Operating Expenses, P&L, Asset Register, Income Summary) is exportable — handed to an accountant or used directly for self-filing. This workflow produces **organized information**, not a computed tax liability (explicitly out of scope).

## 9. Recurring expenses (architecture reserved, not built)

Not implemented in this phase, per explicit instruction. The workflow it will eventually support, so the schema can be designed to not block it (`FINANCE_DATABASE_DESIGN.md` §6): a recurring template (e.g. "Hosting, RM 50/month") generates a reminder — not an auto-created Expense row — on its due date, which the user then confirms/edits into a real Expense via the normal §1 flow. Auto-creation without confirmation is deliberately not the design, since amounts often vary slightly (e.g. usage-based hosting bills) and silent auto-entry would undermine trust in the numbers.

## 10. Daily Business Owner workflow (illustrative, ties workflows together)

A single walkthrough showing how the pieces compose, not a new feature:

> Mewmii Bear buys packaging tape (RM 45) — records it as an Expense under Packaging → Tape, attaches the receipt photo, marks it Paid. At month-end, she opens Profit & Loss for the month, sees Net Profit and margin. She checks Cash Flow to confirm what actually left the bank vs. what's still owed to a supplier on 30-day terms. Once a year, she opens Tax Reports, exports the Annual Operating Expenses and Asset Register, and hands both to her accountant for LHDN filing.

Every step above reuses either an existing page (Supplier Orders, Orders, resolution flow) or a new Finance page designed in this document — nothing requires leaving Mewmii OS or maintaining a spreadsheet in parallel, which is the actual goal this whole module serves.
