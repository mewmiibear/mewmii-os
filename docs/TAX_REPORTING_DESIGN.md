# Tax Reporting — Design

**Status:** Design only. No tax calculation logic is designed or implemented here — only how existing and newly-designed financial information should be organized so LHDN (Malaysia's Inland Revenue Board) filing is easier. Companion to `FINANCE_ACCOUNTING_ARCHITECTURE.md`/`FINANCE_DATABASE_DESIGN.md`.

---

## 1. Scope boundary (stated explicitly, per instruction)

This document designs **report organization**, not tax computation. No LHDN rate, threshold, capital-allowance percentage, or filing-form logic appears anywhere below. Every report described here is a read-only view over data already captured by Finance (`FINANCE_DATABASE_DESIGN.md`) and existing operational tables (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §1) — the actual filing decision and math stays with the business owner or their accountant.

## 2. Why this is organized around LHDN specifically, not generic "accounting reports"

Mewmii OS's config (`app.timezone = 'Asia/Kuala_Lumpur'`, MYR as the default currency throughout) confirms this is a Malaysia-based business. LHDN filing for a small business/sole proprietor typically needs, at minimum: a statement of income and expenses for the tax year, an itemized expense breakdown by category (to substantiate deductions), and an asset register (for capital allowance purposes, even though the allowance calculation itself is the accountant's job, not this system's). The six reports below map directly onto that need — this is why "Tax Reports" is designed as a curated *subset/re-presentation* of Finance's data, not a new data source.

## 3. The six reports

### Expense Summary
Every expense in a selected tax year (default: calendar year, since LHDN's assessment year is typically calendar-year for individuals), one row per expense: date, category, supplier, description, amount, tax-deductible flag. Sortable/filterable by category and tax-deductible status. This is the rawest, most complete export — everything else below is a rollup of this same data.

### Expense by Category
The same data as Expense Summary, grouped and subtotaled by `expense_categories` (including the Packaging sub-categories rolling up into their parent for this view). One number per category, one grand total. This is the shape an accountant most commonly asks for first.

### Annual Operating Expenses
A single-year total, broken out only by top-level category (no sub-category detail) — the "how much did the business spend, in total, on what" summary. Distinct from Expense by Category mainly in level of detail: this is the one-page version, that one is the drill-down.

### Profit & Loss (tax-year framing)
The same P&L calculation designed in `FINANCE_ACCOUNTING_ARCHITECTURE.md` §5/`FINANCE_DATABASE_DESIGN.md` §7, but with the period selector defaulted to a full tax year rather than the operational daily/weekly/monthly views. No new calculation — the same report, a different default filter, since a tax filing needs the yearly figure specifically.

### Asset Register
Every asset (`assets` table, `FINANCE_DATABASE_DESIGN.md` §3) still `in_use` or acquired during the tax year: name, category, purchase date, purchase amount, supplier. This is deliberately just a clean listing — **not** a capital allowance schedule (that requires an allowance method/rate decision this system explicitly does not make). The listing is what an accountant needs as their starting input to calculate capital allowance themselves.

### Income Summary
Order-derived revenue for the tax year (reusing `sales.php`'s existing revenue query, scoped to the year) plus any `manual_income` entries (`FINANCE_DATABASE_DESIGN.md` §3) for the same period, shown as two clearly-labeled sections rather than blended into one number — so it's obvious to the business owner (and their accountant) which figure came from actual sales versus a one-off manual entry.

## 4. Data organization principles specific to tax reporting

1. **Every report is scoped to a tax year, not an arbitrary date range.** The operational Finance reports (`FINANCE_WORKFLOW.md` §3) offer daily/weekly/monthly/yearly granularity for day-to-day use; Tax Reports specifically defaults to (and is optimized for) full-year views, since that's the unit LHDN filing actually needs.
2. **Tax-deductible is a flag on the expense, not a filter that hides non-deductible expenses.** Every report shows the flag rather than silently excluding non-deductible expenses — the business owner (or accountant) makes the deductibility judgment call per line; this system never decides that for them by omission.
3. **Category names are chosen to be accountant-legible, not just internally convenient.** The seeded category list (`FINANCE_DATABASE_DESIGN.md` §3) already matches common, recognizable business-expense groupings (Packaging, Shipping, Marketing, Software, Utilities, Professional Services, etc.) rather than internal jargon — this is a deliberate design choice so an exported Expense by Category report is usable by a third party (the accountant) without translation.
4. **Every report is exportable**, not just viewable — CSV for spreadsheet handoff to an accountant, and a print-friendly/PDF layout for direct filing-support use. No export format beyond these two is designed in this phase (no accounting-software-specific integration format like a QuickBooks/Xero export — not requested, and would need its own scoped design later if ever needed).
5. **Receipts stay linked, not re-attached.** Since Expense Summary/Category exports reference the same `expenses` rows that already carry a receipt attachment (`FINANCE_DATABASE_DESIGN.md` §3), an accountant reviewing the export can be handed direct links/downloads to the underlying receipt images rather than a separate folder of files that has to be manually cross-referenced against the spreadsheet.

## 5. What this deliberately does not do

- No tax rate tables, no SST (Malaysia's Sales and Service Tax) calculation, no capital allowance percentage tables.
- No auto-generated LHDN form (e.g. Form B/Form P) — reports are inputs to filing, not the filing itself.
- No year-end "closing" workflow (locking a period's Expenses from further edits) — not requested, and would need its own explicit design (audit trail for post-close edits, who can override) if it becomes a real need later.
