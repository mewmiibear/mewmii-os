# Finance Integration Plan (Pre-Phase B Design Review)

Status: Design only. No schema or code changes in this document.

## 1) Integration principle

Finance is an integration layer, not an isolated subsystem.  
It must read and connect business events from existing modules, and only store new records where the concept does not already exist.

Core rules:
- Keep source-of-truth in existing modules (Orders, Supplier Orders, Inventory, Resolutions).
- Avoid duplicate financial records.
- Prefer linking (`*_id`) over retyping.
- Keep all new Finance writes in Finance-owned tables only.

## 2) Module-by-module integration design

## Suppliers

Finance should enrich Supplier records with linked financial views (read-only rollups at this stage):
- Total purchases
- Outstanding supplier payments
- Total expenses linked to supplier
- Average monthly spend
- Supplier payment history timeline

Data sources:
- `supplier_orders`, `supplier_order_items`, `supplier_order_payments`
- `expenses` (Phase A) via `supplier_id`
- Future: `manual_income` is explicitly unrelated to suppliers unless a future business case is approved

## Supplier Orders

Supplier Orders remain the source of truth for purchasing and receiving.  
Finance integration should:
- Reuse existing supplier payment records (no parallel payment ledger)
- Reuse landed-cost pipeline from existing cost logic
- Reuse supplier-order currency and exchange-rate facts
- Surface expense links where operational costs are posted separately (e.g., local courier as operating expense policy)

Data contract:
- Supplier-order payment entries stay in `supplier_order_payments`
- Finance reports aggregate from them; Finance does not duplicate them

## Inventory

Inventory remains quantity + movement source of truth.  
Finance reads:
- Landed cost components
- Product valuation inputs
- COGS calculations for reporting

Integration outputs:
- Inventory valuation in finance reports
- COGS contribution to P&L
- Optional future reconciliation dimensions (warehouse-aware filtering) without changing current schema direction

## Customer Orders

Orders remain revenue source of truth.  
Finance integration should:
- Read paid-order revenue from existing order/report logic
- Read completed refunds from resolution tables
- Compute profit contribution per period/product/customer segment in reports

No duplication rule:
- Manual Income must never mirror order revenue
- Finance report layers combine Order Revenue + Manual Income explicitly as separate lines

## Products

Products are not finance records, but finance-relevant attributes should be projected:
- Average cost
- Margin contribution
- Future packaging allocation support (policy/config-driven)

Integration approach:
- Reuse existing product cost functions
- Keep cost policy in Finance settings/reporting layer, not hardcoded in product CRUD

## Dashboard

Finance widgets should be cross-module indicators, not standalone accounting pages:
- Monthly expenses
- Cash balance proxy (account-tagged inflow vs outflow until reconciliation phase)
- Net profit
- Budget usage
- Outstanding supplier payments

Dashboard behavior:
- Link every metric to an actionable page/filter
- Reuse Mission Control signaling conventions (attention-first, no duplicate warning systems)

## Reports

Cross-module reporting opportunities:
- P&L: Orders + COGS + Expenses + Refund impact
- Cash Flow: Supplier payments + paid expenses + paid refunds + manual income
- Supplier spend analysis: Supplier Orders + Expenses
- Profit contribution: Product margin + order mix + operating cost overlays
- Tax-ready exports: Expense categories + attachment bundles + annual summaries

## Customers & Resolution

Finance should consume:
- Completed refunds from resolution flow
- Wallet/store-credit movement as contextual data (not standalone revenue/expense)

No process fork:
- Refunds continue to be created in Resolution workflows, not inside Finance forms

## Notifications & Activity Log

Finance operations should follow existing app conventions:
- Log create/update/status actions via `activity_logs`
- Reuse existing notification lifecycle patterns only where needed (no new notification engine)

## Settings / Currency

Finance should reuse:
- Existing permission model (`finance.view`, `finance.manage`)
- Existing currency-rate infrastructure for conversion context
- Existing migration discipline and schema tracking

## 3) Phase B design boundary (implementation scope handoff)

Approved Phase B functional scope:
- Bank Accounts
- Manual Income
- `bank_account_id` integration

Non-goals in Phase B:
- Bank statement import
- Automated reconciliation
- P&L/Cash Flow rollout (Phase D)
- Budget module rollout (Phase E)

## 4) Phase B data ownership and anti-duplication rules

- `bank_accounts`: reference list only (account identity, type, currency, active state)
- `manual_income`: only non-order income
- `expenses.bank_account_id`: tagging for reconciliation-readiness, not account balance engine
- No duplicate copies of supplier payments, order revenue, or refunds in new tables

## 5) Future compatibility commitments

Multi-Warehouse:
- Keep Finance joins optional/filter-based; do not hardcode single-location assumptions

RBAC:
- Keep all new pages/actions behind existing permission checks
- Avoid Owner-only shortcuts in Finance pages

API layer:
- Keep core logic in `includes/finance.php` functions
- Keep module pages as thin orchestration + rendering layers

Multiple currencies:
- Keep per-record `currency` + `exchange_rate` conventions
- Keep bank account currency explicit for future reconciliation/reporting

---

This plan is the design gate for implementation.  
Phase B should proceed by adding Bank Accounts + Manual Income + `bank_account_id` linkage while preserving existing business logic and integration-first architecture.
