# Changelog

All notable changes to Mewmii OS are recorded here, newest first.

## Unreleased

### Supplier Orders module improvements

**Scope:** `modules/supplier-orders/create.php`, `modules/supplier-orders/edit.php`, `includes/supplier_orders.php`, `assets/js/supplier-order-form.js`. Approved via the CLAUDE.md analysis → plan → wait-for-approval process; see `docs/CURRENT_SYSTEM_AUDIT.md` §5.2/§6 for the findings this was based on.

- **Added:** Supplier order creation now writes an `activity_logs` entry (`activity_log($pdo, 'supplier_orders', 'create', ...)`) recording purchase number, item count, currency, and total. Previously, creating a PO left zero audit trail — verified by grep, not assumed.
- **Added:** The Exchange Rate field on both Create and Edit now pre-fills from the existing, centrally-managed `currency_rates` table (`currency_rates_get('supplier', code)`) when a foreign currency is selected and the field is still empty. Suggestion only — never overwrites a value the admin already typed or an already-saved invoiced rate on an existing order. No new lookup logic was written; this reuses the same function the product-pricing flow already uses.
- **Refactored:** Extracted the currency + line-item validation block — previously duplicated verbatim between `create.php` and `edit.php` — into a single shared function, `supplier_order_validate_form()`, in `includes/supplier_orders.php`. Behavior is unchanged; this is an extraction, not a rewrite (verified: no variable left dangling in either caller after the extraction, `php -l` clean on all touched files).

**Database changes:** none. **Migration required:** none for this change (the separate, already-diagnosed `database/migrate_supplier_order_currency.php` migration remains outstanding from a prior incident and is unrelated to this change, but is a prerequisite for the module to work at all in production — see Known Risks in `docs/IMPLEMENTATION_STATUS.md`).
