# Changelog

All notable changes to Mewmii OS are recorded here, newest first.

## Unreleased

### Migration Management System v1 — production crash fixed

**Scope:** `database/migrate.php` only. No existing `database/migrate_*.php` file was modified. `docs/MIGRATION_MANAGEMENT_PLAN.md` (new §2b) and `docs/IMPLEMENTATION_STATUS.md` updated to match.

**Incident:** running `php database/migrate.php --run` in production printed `-> migrate_additional_costs.php`, then terminated immediately with no further output and zero `schema_migrations` rows written.

**Root cause:** `migrate_runner_execute()` called `exec($command, $outputLines, $exitCode)` without initializing `$outputLines`/`$exitCode` first. `exec()` populates those by reference when it actually runs — but shared hosts (Hostinger included, on some plans) commonly disable `exec()` via `disable_functions` in `php.ini`; when disabled, the call silently no-ops without touching those variables. The next line, `implode(PHP_EOL, $outputLines)`, then threw an uncaught TypeError on the unset variable, crashing the runner before any result could be recorded.

**Confirmed by local reproduction**, not assumed: `implode(PHP_EOL, null)` (simulating `exec()` never populating its output) reproduced the exact failure —
```
PHP Fatal error:  Uncaught TypeError: implode(): Argument #1 ($array) must be of type array, string given
EXIT CODE: 255
```
— matching the production symptom exactly (silent, immediate termination, no output, no recorded result).

**Fix (`database/migrate.php`):**
- Defensive initialization (`$outputLines = []; $exitCode = null;`) before every `exec()` call — a disabled `exec()` now degrades into a normal, recorded `'failed'` result instead of an uncaught crash.
- New pre-flight check (`migrate_runner_check_exec_available()`), run once before attempting any migration in `--run` mode — stops immediately with a clear, actionable message (what's wrong, why subprocess execution is required, and to ask hosting support whether `exec()` can be allowlisted for CLI/SSH specifically) if `exec()` is unavailable, rather than failing silently on whichever migration sorts first.
- Richer failure reporting — a failed migration now shows its exit code, full output, and a best-effort "possible cause" (`migrate_runner_guess_cause()`); both success and failure recording are individually try/caught so a database hiccup while *recording* a result can never itself crash the batch.

**Kept unchanged, per explicit instruction:** the subprocess execution architecture itself, all 21 existing migration files (none modified), and the overall runner design — see `docs/MIGRATION_MANAGEMENT_PLAN.md` §2b for why subprocess execution remains necessary regardless of this incident (the `migrate_run()` function-redeclaration collision across 20 of 21 scripts is unrelated to and unaffected by this fix).

**Testing performed:** `php -l` clean. Reproduced the disabled-`exec()` scenario locally via `php -d disable_functions=exec` against an isolated copy of the runner's pure functions (no database involved) — confirmed the pre-flight check now correctly detects and reports it (`exec() does not exist in this PHP build.`) instead of crashing. Separately verified the normal path (`exec()` available) still works correctly — `migrate_runner_execute()` against a deliberately-missing file correctly captured exit code `1` and the real "Could not open input file" output, with no crash. **Not tested:** a live `--run` against production or any database — not required to verify this specific fix, and no production migration was executed.

### Migration Management System v1 — implemented, not yet run against production

**Scope:** `database/schema.sql` (new `schema_migrations` table definition), `database/migrate.php` (new runner). No existing `database/migrate_*.php` file was modified. `docs/MIGRATION_MANAGEMENT_PLAN.md` and `docs/IMPLEMENTATION_STATUS.md` updated to match.

- **Added** the `schema_migrations` tracking table (see `database/schema.sql`) — keyed by exact filename, so none of the 21 existing migration scripts needed renaming.
- **Added** `database/migrate.php`: discovers `database/migrate_*.php` from disk (never a hand-maintained list — the direct fix for the incident's root cause), defaults to preview-only, requires an explicit `--run` flag to execute, shows pending/completed/modified-since-applied migrations, and records one `schema_migrations` row per script run.
- **Design change found during implementation, not in the original plan:** grepping all 21 scripts found 20 of them independently define an identical `migrate_run()` function at global scope. `require`-ing more than one into the same PHP process would fatal-error ("Cannot redeclare function"). Fixed by running each pending migration as its own `php` subprocess instead — this required **zero changes to any existing migration file**. See `docs/MIGRATION_MANAGEMENT_PLAN.md` §2a for full detail.
- **Design change:** v1 ships CLI-only (`PHP_SAPI !== 'cli'` guard, matching `cli/job_worker.php`'s existing pattern), not browser + CLI as originally sketched — closes the audit's unauthenticated-access finding for this new script rather than reproducing it, and avoids the extra permission/CSRF/UI work a browser path would need for v1. See `docs/MIGRATION_MANAGEMENT_PLAN.md` §2a.
- **Explicitly not implemented**, per approved scope: rollback engine, dependency graph, CI/CD integration.

**Database changes:** `schema_migrations` table added to `database/schema.sql` (fresh installs get it automatically); on an existing database it's created the first time `database/migrate.php` runs (`CREATE TABLE IF NOT EXISTS`, same idempotent pattern as every other migration). **Not run against production as part of this change** — per instruction, no production migration was executed.

**Testing performed:** `php -l` clean on `database/migrate.php`. End-to-end verified against a throwaway local MariaDB instance (XAMPP, `127.0.0.1`, isolated test database, credentials injected via process environment variables — the real `.env`/`config.php` were never read or touched): confirmed `schema_migrations` bootstraps with the exact intended schema, and confirmed preview mode correctly discovers and lists all 21 existing migration files as pending against an empty database. The local test database and MySQL instance were torn down afterward — nothing was left running. **`--run` execution mode (actually applying migrations) was not live-tested**, per direction received mid-task — its correctness rests on code review and the exit-code/output-capture logic being the same pattern already proven in `cli/job_worker.php`, not on an observed live run.

### Migration Management System — audit & design (planning only)

**Scope:** Documentation only — `docs/MIGRATION_SYSTEM_AUDIT.md` (new), `docs/MIGRATION_MANAGEMENT_PLAN.md` (new), `docs/CURRENT_SYSTEM_AUDIT.md` (migration count corrected 19 → 21), `docs/IMPLEMENTATION_STATUS.md`. No code, migration file, or database change was made — implementation awaits approval.

- **Audited** all 21 existing `database/migrate_*.php` scripts (filename, purpose, tables/columns, idempotency, dependencies). Confirmed 21/21 are idempotent. Found `includes/system_health.php`'s existing migration-detection array — built after two prior silent-migration incidents — covers only 17 of the 21 scripts; the 4 missing include `migrate_supplier_order_currency.php`, the exact migration behind this week's outage.
- **New finding:** all 21 migration scripts are reachable via unauthenticated direct URL — confirmed against the root `.htaccess`, which protects `.env`/`config.php` but was never extended to `database/*.php`. Same class of gap as the `?wc_webhook_diagnose` endpoint noted in `docs/CURRENT_SYSTEM_AUDIT.md` §9.
- **Designed** (not built) a `schema_migrations` tracking table and a `database/migrate.php` runner that discovers migrations from disk rather than a hand-maintained list — the direct structural fix for the incident's root cause. Design deliberately excludes rollback/down-migrations, a dependency graph, and any renaming of the 21 existing scripts, per explicit scope constraints — see `docs/MIGRATION_MANAGEMENT_PLAN.md` §6 for the full list of what was intentionally left out.

### Supplier Orders module improvements

**Scope:** `modules/supplier-orders/create.php`, `modules/supplier-orders/edit.php`, `includes/supplier_orders.php`, `assets/js/supplier-order-form.js`. Approved via the CLAUDE.md analysis → plan → wait-for-approval process; see `docs/CURRENT_SYSTEM_AUDIT.md` §5.2/§6 for the findings this was based on.

- **Added:** Supplier order creation now writes an `activity_logs` entry (`activity_log($pdo, 'supplier_orders', 'create', ...)`) recording purchase number, item count, currency, and total. Previously, creating a PO left zero audit trail — verified by grep, not assumed.
- **Added:** The Exchange Rate field on both Create and Edit now pre-fills from the existing, centrally-managed `currency_rates` table (`currency_rates_get('supplier', code)`) when a foreign currency is selected and the field is still empty. Suggestion only — never overwrites a value the admin already typed or an already-saved invoiced rate on an existing order. No new lookup logic was written; this reuses the same function the product-pricing flow already uses.
- **Refactored:** Extracted the currency + line-item validation block — previously duplicated verbatim between `create.php` and `edit.php` — into a single shared function, `supplier_order_validate_form()`, in `includes/supplier_orders.php`. Behavior is unchanged; this is an extraction, not a rewrite (verified: no variable left dangling in either caller after the extraction, `php -l` clean on all touched files).

**Database changes:** none. **Migration required:** none for this change (the separate, already-diagnosed `database/migrate_supplier_order_currency.php` migration remains outstanding from a prior incident and is unrelated to this change, but is a prerequisite for the module to work at all in production — see Known Risks in `docs/IMPLEMENTATION_STATUS.md`).

**Queue review:** No queue required — all three changes are synchronous, fast, database-local operations (an activity log insert, a batched exchange-rate lookup, a pure validation-logic extraction). No long-running process was introduced.

**Future security note:** `purchase_number` has no character-class restriction (only length ≤100 and uniqueness are enforced), and it now flows into `activity_logs.description` via the new logging call. Not exploitable today — no admin page renders `activity_logs` anywhere in the codebase (see audit §6.6) — but whenever an Activity Log viewer is built, it **must** run `description` (and every other logged field) through `app_escape()` before rendering, same as every other display site in this codebase.

Queue Review:
No queue required.

Reason:
Changes are synchronous, fast database-local operations:
- activity log insertion
- exchange-rate lookup
- validation extraction

No long-running process introduced.

Future Activity Log Viewer must escape description fields before display.
