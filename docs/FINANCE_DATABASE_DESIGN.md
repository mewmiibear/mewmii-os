# Finance & Accounting — Database Design

**Status:** Design only. **Nothing in this document has been created — no migration file exists, no table has been added, `database/schema.sql` is untouched.** This is a proposed schema for a future implementation phase, presented for review. Column lists below are a design sketch, not a final DDL spec — exact types/constraints get finalized when this phase is actually approved for implementation.

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
id, name, parent_id NULL (self-referencing FK, one level of nesting — e.g. "Packaging" as parent of "Bubble Wrap"/"Boxes"/"Tape"/"Stickers"/"Thank-you Cards"/"Labels"), is_active, sort_order
```
Seeded from the user's list: Packaging (with the 6 sub-categories above), Shipping, Marketing, Software, Hosting, Utilities, Office Supplies, Equipment, Travel, Bank Charges, Payment Gateway Fees, Subscriptions, Professional Services, Miscellaneous.

### `expenses`
```
id, category_id (FK expense_categories), supplier_id NULL (FK suppliers — reuses the existing table, no new "payee" concept),
expense_date, description, amount, currency, exchange_rate NULL,
payment_method, reference_number NULL, tax_deductible (bool, default true),
status (unpaid | paid | pending), created_by (FK users), created_at, updated_at
```
Matches every field explicitly requested: Date, Category, Supplier, Description, Amount, Currency, Exchange Rate, Payment Method, Reference Number, Notes (folded into `description`, matching how every other module in this codebase already treats a single free-text notes/description field rather than splitting them), Tax Deductible flag, Status. Receipt attachment is a separate table (§4), not a column — an expense can have zero, one, or (rarely) more than one receipt image without a schema change either way.

### `assets`
```
id, name, category (simple VARCHAR or a small assets_categories lookup — Furniture/Equipment/Electronics/Other; not the same list as expense_categories), 
supplier_id NULL, purchase_date, purchase_amount, currency, exchange_rate NULL,
description, status (in_use | disposed | sold), created_by, created_at, updated_at
```
**Deliberately no depreciation columns** — per explicit instruction. The table is shaped so a future `asset_depreciation_schedule(asset_id, method, useful_life_years, ...)` table can reference `assets.id` without touching this table's own structure; depreciation is additive, not retrofitted.

### `asset_attachments` / `expense_attachments`
Two small tables (not one polymorphic table — see §8 for why), each:
```
id, expense_id (or asset_id), file_path, original_filename, file_type, uploaded_by, uploaded_at
```
Storage mechanics reuse `includes/receipt_storage.php`'s existing functions unchanged (private directory, `.htaccess`-denied, `finfo`-validated MIME, random on-disk filename) — these tables only record the metadata `receipt_storage.php` already expects a caller to persist (mirroring exactly how `payment_receipts` already does this today).

### `bank_accounts`
```
id, name, account_type (bank | cash | e-wallet), currency, notes, is_active, created_at
```
A small reference list, **not** a reconciliation/statement-import system — Expenses/Income optionally tag which account they moved through (`payment_method` on `expenses` can either stay free-text or become a `bank_account_id` FK; recommend free-text for this phase, upgrading to a FK once real usage shows which accounts actually recur, avoiding a premature required-relationship).

### `manual_income`
```
id, income_date, description, amount, currency, exchange_rate NULL, category (e.g. "Asset Sale", "Grant", "Other"),
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

## 7. What Profit & Loss reuses vs. computes fresh

To keep `FINANCE_ACCOUNTING_ARCHITECTURE.md` §3's "integration, not creation" claim concrete:

| P&L line | Source |
|---|---|
| Sales | Existing query shape from `modules/reports/sales.php` (`SUM(oi.subtotal)` where `payment_status='paid' AND order_status<>'cancelled'`) — reused, not reimplemented |
| COGS | Existing `product_cost_calculate_batch()` (`includes/product_cost.php`), applied to units actually sold in the period |
| Operating Expenses | `SUM(expenses.amount)` for the period, new table |
| Refund adjustment | `SUM(resolution_refunds.amount) WHERE status='completed'`, existing table |
| Gross/Net Profit, Margin % | Plain arithmetic over the above — no new calculation engine |

## 8. Why two attachment tables, not one polymorphic table

A single `finance_attachments(id, attachable_type, attachable_id, ...)` polymorphic table was considered and rejected: this codebase has no existing polymorphic-reference precedent anywhere (every FK relationship found in the audit is a plain, single-purpose foreign key — e.g. `resolution_items.order_item_id`, `supplier_order_payments.supplier_order_id`), and introducing one here for two tables would be exactly the kind of new abstraction `MEWMII_OS_V2_PLAN.md` §1 warns against adopting "as a blanket change." Two small, boring, single-purpose tables match the codebase's existing style better than one clever one.

## 9. Explicitly not designed in this document

- Depreciation schedule (deferred, §3).
- Bank statement import/reconciliation (not requested — `bank_accounts` is a reference list only).
- Tax calculation columns/logic (see `TAX_REPORTING_DESIGN.md` — organization only).
- Multi-warehouse-aware Expense/Asset location fields (no current need identified; would be additive if ever required, per `FUTURE_MULTI_WAREHOUSE.md`'s constraint that current decisions must not block it).
