# Future Backlog

**Status:** Ideas and known gaps only. **Nothing in this document is scheduled, approved, or implemented.** Items here have not been through the standing Audit → Design → Approval cycle — this is a parking place so they are not lost, not a plan.

Each item records *why* it's deferred, so a future reader can tell the difference between "not needed yet" and "overlooked."

---

## 1. Asset Management improvements (Finance Phase C follow-ups)

Phase C deliberately shipped a minimal asset register — see `docs/FINANCE_ACCOUNTING_ARCHITECTURE.md` §6 for the standing rule that Mewmii OS tracks asset *ownership and operations*, not asset *accounting*. Everything below stays consistent with that boundary; none of it reintroduces depreciation, capital allowance, or ledger entries.

| Idea | Notes / why deferred |
|---|---|
| **QR / barcode labels** | Print a scannable label per asset for physical stock-takes. Explicitly excluded from Phase C scope. Needs a label-rendering decision (client-side vs. server-side) and a scanning entry point before it's designable — neither exists today |
| **Automatic asset code generation** | `assets.asset_code` is deliberately optional, user-entered, `NULL UNIQUE`, with no numbering engine — a decision made to avoid committing to ERP numbering rules before real usage shows what they should be. The column shape already supports auto-generation without restructuring; adding it is a create-flow change, not a schema change. Revisit once there are enough assets for manual coding to become annoying |
| **Warranty expiry reminders** | `assets.warranty_expiry` exists and is captured, but nothing reads it. A reminder would be a dashboard/notification rule, and per `docs/DASHBOARD_PHILOSOPHY.md` that needs its own explicit design (what tier of signal, silent-when-healthy behaviour) rather than being assumed |
| **Asset dashboard / summary widgets** | E.g. total asset value, count by category, assets by location. Same constraint as above: `DASHBOARD_PHILOSOPHY.md`'s rule is that a figure belongs on Mission Control only if it changes *today*. Asset totals mostly don't, so this is likely a report-tier concern, not a dashboard one |
| **Asset export (CSV / print)** | Would slot alongside the Tax Reports exports designed in `docs/TAX_REPORTING_DESIGN.md` §4. Note the Asset Register report there (Phase F) already covers the tax-facing need — this item is only about ad-hoc operational export, so it may turn out to be redundant once Phase F lands |
| **Asset history timeline** | An `asset_transactions` ledger (assignment changes, location moves, repairs) was considered during Phase C design and **deliberately rejected for now**: an asset's meaningful events are already captured by `purchase_date`/`disposal_date`/`status`, and ongoing changes go in the free-text `notes` field. Build this only if `notes` proves insufficient in real use — structure without demand is exactly what the project philosophy warns against |
| **`sold` asset status + Asset Sale flow** | Phase C ships `in_use`/`disposed` only. `sold` was removed by explicit decision until an Asset Sale accounting flow exists to give it meaning. Note `manual_income` already has an `'Asset Sale'` category, so the income side is recordable today — what's missing is the link between a disposal and that income record |

---

## 2. Permission seeding has no migration path (architectural gap)

**Discovered during Finance Phase C deployment, 2026-08-03.** This is the highest-value item in this document — it is a live operational trap, not a feature idea.

### Current behaviour

Two unconnected mechanisms:

- **Database structure** → `database/migrate_*.php`, discovered and run by `database/migrate.php`, tracked in `schema_migrations`, and detectable afterward via Settings → System Health.
- **Permission seeding** → `install.php` lines 49-69, and **nowhere else**. Confirmed by audit: `INSERT INTO permissions` and `INSERT INTO role_permissions` appear at exactly two places in the entire codebase, both in `install.php`. No migration touches permissions. There is no RBAC admin UI — no module anywhere reads or writes `role_permissions`.

### The problem

A deploy that adds a new permission ships code that *checks* for a permission the database has never been told about. Nothing detects this:

1. Migrations run fine — the tables exist, so the feature looks deployed.
2. System Health reports green — it probes schema artifacts only and has no concept of permissions.
3. The UI fails **silently**, not loudly. `includes/header.php` wraps each nav section in `app_has_permission(...)`, so a missing permission makes the entire section vanish with no error, no badge, no hint. Direct page access returns a bare "Access denied," which reads like a deliberate restriction rather than a misconfiguration.

The only remedy today is re-running `install.php` — but `install.php:5` first executes the whole of `database/schema.sql` via `$pdo->exec()` under `PDO::ERRMODE_EXCEPTION`. All 75 statements are `CREATE TABLE IF NOT EXISTS` (audited — no `DROP`/`ALTER`/`INSERT`/`DELETE`/`TRUNCATE`), so it is not destructive; but any single statement error aborts the script **before** the permission sync at line 49, which makes the fix appear to do nothing. `install.php` also has no authentication guard and is reachable by direct URL, the same exposure `docs/MIGRATION_SYSTEM_AUDIT.md` already records for the migration scripts.

**This already caused a real incident:** the entire Finance module was inaccessible after Phase C deployed, and the cause was Phase A's permissions never having been seeded — meaning Finance had likely been invisible since Phase A, and it simply wasn't noticed until Phase C.

### Directions worth considering (none designed, none chosen)

- Extract the permission sync from `install.php` into an includable function both `install.php` and a small runner can call, so permissions can be synced without executing `schema.sql`.
- Or treat permissions as migratable — a `migrate_permissions.php` following the existing idempotent convention, so permission changes flow through the same tracked pipeline as schema changes.
- Either way, consider teaching System Health to report missing permissions, so the silent-failure mode becomes a visible one.
- A related open question: `install.php` links new permissions to the **Owner role only**. Any future non-Owner role will not receive them automatically. Out of scope here; noted because it interacts with `docs/FUTURE_RBAC.md`.

**Do not fix as part of Finance work.** This is cross-cutting infrastructure and deserves its own audit, design, and approval cycle.
