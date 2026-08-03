# Finance & Accounting — Database Design

**Status:** Design document. The Phase A tables (`expense_categories`, `expenses`, `expense_attachments`) and Phase B tables/columns (`bank_accounts`, `manual_income`, `expenses.bank_account_id`) described below have since been created — see `database/schema.sql`, `database/migrate_finance_phase_a.php`, `database/migrate_finance_phase_b.php`, and `docs/IMPLEMENTATION_STATUS.md` for current, authoritative status. `assets`/`asset_attachments`, `budgets`, and `finance_cost_classifications` (§3, Phases C–D) remain proposed only — not yet created. Column lists below are the design sketch this was built from; refer to `database/schema.sql` for the exact, current DDL of implemented tables.

---

## 1. Design principles

1. **Reuse before creating.** Every table below exists only because §1 of `FINANCE_ACCOUNTING_ARCHITECTURE.md` confirmed the concept genuinely doesn't exist yet. Nothing here duplicates `mewmii_orders`, `supplier_order_payments`, `resolution_refunds`, or `currency_rates`.
2. **Match existing conventions exactly**, not a parallel style: `created_by`/`created_at`/`updated_at` on every table (matching `supplier_order_payments`), a `currency` + independently-entered `exchange_rate` pair per record (matching `supplier_orders`, not a live-converted value), soft status lifecycles via a plain `VARCHAR` status column (matching `resolution_refunds`'s `pending`→`completed`, not a separate boolean-flag-per-state approach).
3. **The dead `expenses`/`invoices` scaffolding is replaced, not reused.** Reasoning below (§2).

## 2. Why the existing `expenses`/`invoices` tables should be replaced, not adopted

`database/schema.sql:538-546`'s `expenses` table (`category VARCHAR(100)` free text, `amount`, `description`, `receipt_file VARCHAR(500)`, `expense_date`) is missing every structured field this design needs: currency, exchange rate, payment method, reference number, tax-deductible flag, status, supplier link, category as a real lookup (not free text), and `created_by`. It also stores its receipt as a raw `VARCHAR(500)` path rather than using the existing private `receipt_storage.php` convention. Since it has zero application code depending on it today (confirmed by audit — the only references are a customer-merge FK-hygiene script and a test-data-reset utility, neither of which encodes any real design decision), replacing it cleanly is lower-risk than trying to retrofit these fields onto it. **Recommendation for the implementation phase: drop and recreate both `expenses` and `invoices` as part of the same migration that adds the rest of this schema**, rather than `ALTER TABLE`-ing the old shape. `invoices` is not designed here at all — nothing in this phase's scope needs it (Finance's "Income" concept, §3, is deliberately simpler than a full invoicing system); it's noted only so a future implementer knows it was considered and explicitly deferred, not overlooked.

## 3. Proposed new tables

### `expense_categories`
```
id, name, parent_id NULL (self-referencing FK — see the seed list below for the recommended default depth), is_active, sort_order
```
See "Recommended default `expense_categories` seed list" below for the full seed list and grouping rationale (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §10 has the narrative reasoning).

### `expenses`
```
id, category_id (FK expense_categories), supplier_id NULL (FK suppliers — reuses the existing table, no new "payee" concept),
bank_account_id NULL (FK bank_accounts, defined below — added in this revision for reconciliation-readiness, FINANCE_ACCOUNTING_ARCHITECTURE.md §12),
expense_date, description, amount, currency, exchange_rate NULL,
payment_method, reference_number NULL, tax_deductible (bool, default true),
status (draft | paid | archived — see FINANCE_ACCOUNTING_ARCHITECTURE.md §15 for the lifecycle), created_by (FK users), created_at, updated_at
```
Matches every field explicitly requested: Date, Category, Supplier, Description, Amount, Currency, Exchange Rate, Payment Method, Reference Number, Notes (folded into `description`, matching how every other module in this codebase already treats a single free-text notes/description field rather than splitting them), Tax Deductible flag, Status. Receipt attachment is a separate table (§4), not a column — an expense can have zero, one, or (rarely) more than one receipt image without a schema change either way.

### `assets`
```
id, name, category (simple VARCHAR or a small assets_categories lookup — Furniture/Equipment/Electronics/Other; not the same list as expense_categories), 
supplier_id NULL, purchase_date, purchase_amount, currency, exchange_rate NULL,
warranty_expiry NULL, disposal_date NULL,
description, notes NULL, status (in_use | disposed | sold), created_by, created_at, updated_at
```
**Deliberately no depreciation columns** — per explicit instruction. The table is shaped so a future `asset_depreciation_schedule(asset_id, method, useful_life_years, ...)` table can reference `assets.id` without touching this table's own structure; depreciation is additive, not retrofitted. `description` (what the asset is, fixed at purchase) and `notes` (ongoing, accumulates over the asset's life) are kept as two fields rather than one — see `FINANCE_ACCOUNTING_ARCHITECTURE.md` §11 for why this is the one place in the whole design that splits them. `disposal_date` pairs with `status`: set only when `status` moves to `disposed`/`sold`, never populated for an `in_use` asset.

### `budgets`
```
id, category_id (FK expense_categories), period_type ('monthly' — the only value designed/seeded now, see below),
period_start (DATE, first-of-month), planned_amount, currency (MYR-only in this phase — FINANCE_ACCOUNTING_ARCHITECTURE.md §14),
created_by, created_at, updated_at
UNIQUE (category_id, period_type, period_start)
```
One row per category per month — nothing else. **Actual spending is never stored here or anywhere new** — it's `SUM(expenses.amount) WHERE category_id = budgets.category_id AND expense_date` falls within `period_start`'s month, computed at report time. This is the one table in the entire Finance design that exists purely to hold a plan, with zero duplicated actuals, matching the principle the user just reaffirmed as most important. See `FINANCE_ACCOUNTING_ARCHITECTURE.md` §9 for why `period_type` is designed as an extensible field (ready for a future `'yearly'` value) despite only `'monthly'` being seeded/used now — this is the "structure that can be expanded later" the instruction asked for, without building the expansion itself.

### `finance_cost_classifications`
```
id, cost_type_key (e.g. 'international_freight', 'supplier_shipping', 'customs', 'import_tax', 'local_courier'),
label, default_treatment ('cogs' | 'operating_expense'), current_treatment ('cogs' | 'operating_expense'), description, updated_at
```
Seeded with the five cost types analyzed in `FINANCE_ACCOUNTING_ARCHITECTURE.md` §8, `current_treatment` initialized equal to `default_treatment` at seed time and independently editable afterward via a small Finance settings screen. P&L/COGS calculation logic reads `current_treatment` per cost type rather than branching on a hardcoded cost-type string anywhere in code — this is the mechanism that makes §8's "configurable, not hardcoded" requirement real rather than aspirational. `local_courier` is the row a future `shipments.shipping_cost` column (§4 below) or a "Shipping"-category expense would both defer to, so whichever capture mechanism is eventually built, the classification lookup is the same one table either way.

### `asset_attachments` / `expense_attachments`
Two small tables (not one polymorphic table — see §8 for why), each:
```
id, expense_id (or asset_id), file_path, original_filename, file_type, uploaded_by, uploaded_at
```
Storage mechanics reuse `includes/receipt_storage.php`'s existing functions unchanged (private directory, `.htaccess`-denied, `finfo`-validated MIME, random on-disk filename) — these tables only record the metadata `receipt_storage.php` already expects a caller to persist (mirroring exactly how `payment_receipts` already does this today). One `expense_id` (or `asset_id`) can have multiple rows — multiple receipts per expense (e.g. a purchase + a separate delivery note) is supported by this shape without any extra design, just multiple inserts against the same `expense_id`.

**Export receipts by year** (new requirement, design note rather than a new table): a controller-level action, not a schema addition — given a tax year, look up every `expense_attachments` row for expenses with `expense_date` in that year, stream each through `receipt_storage_stream()`'s existing authorization/streaming logic (same pattern as `modules/orders/receipt_download.php`), and bundle the results into a single downloadable archive. No new storage location, no new attachment concept — purely an aggregation of files already tracked by this table, offered alongside the Tax Reports exports (`TAX_REPORTING_DESIGN.md` §4).

### `bank_accounts`
```
id, name, account_type (bank | cash | e-wallet), currency, notes, is_active, created_at
```
A small reference list, **not** a reconciliation/statement-import system — supports multiple accounts of different types out of the box (e.g. "Maybank Business" / bank, "Touch 'n Go eWallet" / e-wallet, "Wise" / bank, "Cash" / cash — one row each, `account_type` is a fixed small enum not a free-text field, so reporting can group by type later without string-matching). **Revised in this update:** `expenses`/`manual_income` now carry a real `bank_account_id` FK (not a free-text `payment_method` fallback, as an earlier pass of this design proposed) — see `FINANCE_ACCOUNTING_ARCHITECTURE.md` §12 for why this was brought forward now rather than left for a later retrofit. `payment_method` remains on `expenses` as a separate free-text field for cases with no real "account" (e.g. a one-off cash payment not worth registering as a formal account) — the two fields are complementary, not redundant: `bank_account_id` is for reconciliation-readiness against a known account, `payment_method` is a lighter-weight descriptive fallback.

### Recommended default `expense_categories` seed list

Two-level grouping by default (narrative reasoning in `FINANCE_ACCOUNTING_ARCHITECTURE.md` §10):

```
Operations
  ├── Packaging
  ├── Shipping
  ├── Warehouse
  ├── Equipment          (minor/consumable equipment spend - see note below)
  └── Office Supplies
Marketing                 (no children by default)
Technology
  ├── Software
  ├── Hosting
  └── Subscriptions
Professional Services      (no children by default)
Finance
  ├── Bank Charges
  └── Payment Gateway Fees
Utilities                 (no children by default)
Travel                    (no children by default)
Miscellaneous              (no children by default - catch-all)
```

**Note on "Equipment" appearing in both Expenses and Assets:** this is intentional, not a naming collision. `Operations → Equipment` (an expense category) covers minor, consumable, or below-materiality-threshold equipment-adjacent spend (e.g. printer ink, a cheap tool) that the business owner judges not worth capitalizing. A real capitalized purchase (a laptop, a proper printer) goes in the separate `assets` table under its own Equipment/Electronics asset category instead (§3's `assets` design) — the Expense-vs-Asset fork (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §6) is what decides which one, not the category name.

**Note on "Assets" in the category list:** per `FINANCE_ACCOUNTING_ARCHITECTURE.md` §10, "Assets" is never inserted into `expense_categories` — it appears only as a pseudo-category in the broader spending-overview report (`FINANCE_WORKFLOW.md` §11), computed from the `assets` table.

All categories are seeded once, then fully user-editable/extendable afterward (add, rename, deactivate) — matching the existing idempotent-seed pattern already used for permissions in `install.php`, applied here to give new users sensible defaults without locking the list.

### `manual_income`
```
id, income_date, description, amount, currency, exchange_rate NULL, category (e.g. "Asset Sale", "Grant", "Other"),
bank_account_id NULL (FK bank_accounts, same reconciliation-readiness reasoning as expenses),
reference_number NULL, created_by, created_at
```
Deliberately narrow — see `FINANCE_ACCOUNTING_ARCHITECTURE.md` §4's "Income" refinement. Order revenue is never inserted here; this table exists only for genuinely non-order income.

## 4. Recommended small schema addition outside Finance's own tables (flagged, not designed in detail here)

`FINANCE_ACCOUNTING_ARCHITECTURE.md` §2/§7 identified outbound shipping cost as a real gap that a pure Finance-side table can't fully close on its own — the cost belongs conceptually to the `shipments` table (or is captured as a generic "Shipping" Expense, as an interim/fallback that requires no schema change to any existing table at all). **Two options, for the user to choose between when this phase is actually implemented:**

- **Option A (no existing-table change):** all shipping cost is recorded as a normal Expense under the "Shipping" category (§3). Zero risk, zero schema change to `shipments`, but doesn't tie a specific cost to a specific shipment record.
- **Option B (small existing-table change):** add `shipping_cost DECIMAL(12,2) NULL` to the existing `shipments` table, letting a per-shipment cost roll up automatically. A real, minimal, additive column — but it is a change to a table Finance doesn't own, so it's flagged here rather than assumed, per the instruction that this phase makes no database changes.

Recommendation: start with Option A (zero schema risk, ships with the rest of Finance), revisit Option B once real usage shows whether per-shipment cost precision is actually needed.

## 5. Permissions

New entries for the existing `permissions` table (via the same idempotent `install.php` pattern already used for every other module — confirmed safe/precedented by the audit): `finance.view`, `finance.manage`. Two, not four+, matching the existing convention of one view/manage pair per module rather than per-sub-feature (Expenses/Assets/Bank Accounts/Reports all share these two, exactly like Inventory's single `inventory.view`/`inventory.manage` pair already covers several sub-pages). No new role is created — these link to the existing `Owner` role via `role_permissions`, same as every permission today.

## 6. Recurring expenses — reserved shape, not built

Per explicit instruction: designed, not implemented.
```
recurring_expense_templates: id, category_id, description, amount, currency, interval (weekly | monthly | yearly),
next_due_date, is_active, created_by, created_at
```
Nothing reads or writes this table in this phase. When built later (a separate, explicitly-approved phase), it generates a reminder on `next_due_date` — never an auto-created `expenses` row without confirmation (`FINANCE_WORKFLOW.md` §9's reasoning).

## 6.1 Expense Templates — reserved shape, not built, future scope only

A distinct, complementary concept to Recurring Expenses above, not a duplicate of it — worth being explicit about the difference since both involve "prefilling an expense": **Recurring Expenses are time-based** (a reminder fires on a schedule); **Expense Templates are reuse-based** (a named shortcut the user picks manually, as often or rarely as they like, with no schedule at all — e.g. "Packaging Purchase" used several times a week, or "Fuel" used ad hoc). A future Recurring Expense could reference a Template to know what to prefill into its confirmation step, but Templates stand alone and are useful with no Recurring Expense feature present at all.

```
expense_templates: id, name, category_id, default_amount NULL, default_supplier_id NULL,
default_description NULL, default_tax_deductible (bool), is_active, created_by, created_at
```

Examples from the user's list map directly to rows: "Packaging Purchase" (category: Operations → Packaging), "Hosting" (Technology → Hosting, likely with a `default_amount` since hosting bills are often fixed), "Software Subscription," "Marketing," "Fuel," "Parking," "Office Supplies." Selecting a template on the expense entry form (`FINANCE_WORKFLOW.md` §1) would prefill Category/Supplier/Description/Amount/Tax-deductible — **every field stays fully editable after prefill**, nothing is locked or auto-submitted, matching the same non-silent philosophy Recurring Expenses already commits to (§6 above).

**Explicitly not part of any Phase A-F work** — reserved shape only, exactly like Recurring Expenses. Nothing reads or writes this table until a future, separately-approved phase.

## 7. What Profit & Loss reuses vs. computes fresh

To keep `FINANCE_ACCOUNTING_ARCHITECTURE.md` §3's "integration, not creation" claim concrete:

| P&L line | Source |
|---|---|
| Sales | Existing query shape from `modules/reports/sales.php` (`SUM(oi.subtotal)` where `payment_status='paid' AND order_status<>'cancelled'`) — reused, not reimplemented |
| COGS | Existing `product_cost_calculate_batch()` (`includes/product_cost.php`), applied to units actually sold in the period |
| Operating Expenses | `SUM(expenses.amount)` for the period, no status filter — Draft, Paid, and Archived all count (accrual rule, `FINANCE_ACCOUNTING_ARCHITECTURE.md` §15; Archived is a visibility state, not a financial exclusion) |
| Refund adjustment | `SUM(resolution_refunds.amount) WHERE status='completed'`, existing table |
| Gross/Net Profit, Margin % | Plain arithmetic over the above — no new calculation engine |
| COGS-vs-Operating split for freight/customs/import tax/courier | `finance_cost_classifications.current_treatment` lookup per cost type, not a hardcoded branch — `FINANCE_ACCOUNTING_ARCHITECTURE.md` §8 |
| Budget variance (not a P&L line, a separate Budget report) | `budgets.planned_amount − SUM(expenses.amount)` for the matching category/period — new table, but the actual-spending half is still reused, never duplicated |

## 8. Why two attachment tables, not one polymorphic table

A single `finance_attachments(id, attachable_type, attachable_id, ...)` polymorphic table was considered and rejected: this codebase has no existing polymorphic-reference precedent anywhere (every FK relationship found in the audit is a plain, single-purpose foreign key — e.g. `resolution_items.order_item_id`, `supplier_order_payments.supplier_order_id`), and introducing one here for two tables would be exactly the kind of new abstraction `MEWMII_OS_V2_PLAN.md` §1 warns against adopting "as a blanket change." Two small, boring, single-purpose tables match the codebase's existing style better than one clever one.

## 9. Explicitly not designed in this document

- Depreciation schedule (deferred, §3).
- Bank statement import/reconciliation logic (statement upload, line-matching, discrepancy flagging) — only the `bank_account_id` tagging groundwork is designed now, per explicit instruction ("future support for reconciliation is enough... do not implement automatic bank sync").
- Tax calculation columns/logic (see `TAX_REPORTING_DESIGN.md` — organization only).
- Multi-warehouse-aware Expense/Asset location fields (no current need identified; would be additive if ever required, per `FUTURE_MULTI_WAREHOUSE.md`'s constraint that current decisions must not block it).
- Budget rollover, multi-year budgets, approval workflows — `budgets`' one-row-per-category-per-month shape supports these later without restructuring, but none are built now (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §9).
- Warranty-expiry notification/alerting logic — the field exists; a rule reading it does not.
