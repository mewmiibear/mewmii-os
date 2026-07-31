# Migration Management System — Design

**Status:** v2 implemented and tested locally. `schema_migrations` and `database/migrate.php` exist, using **in-process execution** (§7) — not subprocess execution as §2/§2a/§2b describe. Not yet run against production. Read §7 first; §2/§2a/§2b are kept as historical record of how the design got here, not the current behavior.
**Depends on:** `docs/MIGRATION_SYSTEM_AUDIT.md` — every decision below is a direct answer to a gap that audit found in the real system, not a generic best-practice import.

**Architecture history, in order:** §2 (original sketch: subprocess via `exec()`) → §2a (two deviations found during implementation, still subprocess-based) → §2b (a production crash in the subprocess approach, fixed while keeping subprocess execution) → production confirmed `exec()`/`shell_exec()`/`system()`/`passthru()`/`popen()` are **all** disabled, meaning subprocess execution can never work on this host at all → an HTTP-loopback alternative was designed and then explicitly rejected for adding infrastructure complexity (HTTP, token auth, a new web endpoint, loopback dependency) to what should be a simple database tool → **§7: the actual, current architecture** — a one-time mechanical refactor of all 21 migration files enabling true in-process execution, no subprocess, no HTTP, no shell of any kind.

**Design principle governing every choice below:** reuse what already works (idempotency convention, `app_require_permission()`, existing filenames) and add the smallest amount of new machinery that closes the actual gap — a tracking table and one runner script. No ORM, no CI/CD, no rollback engine, no renaming of the 21 existing scripts. This is a small team on shared hosting with no CI/CD (confirmed in `DEPLOYMENT.md`); the design fits that reality rather than importing a framework-scale migration system.

---

## 1. Migration tracking table

```
schema_migrations
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  migration          VARCHAR(191) NOT NULL UNIQUE   -- exact filename, e.g. 'migrate_supplier_order_currency.php'
  status             ENUM('success','failed') NOT NULL DEFAULT 'success'
  checksum           CHAR(64) NULL                  -- SHA-256 of the file's contents at the time it was run
  executed_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  execution_time_ms  INT UNSIGNED NULL
  executed_by        VARCHAR(100) NULL              -- 'cli', or the admin's name/email for a browser-triggered run
  error_message      TEXT NULL                      -- populated only when status = 'failed'
```

**Reasoning for each field, and what was deliberately left out:**

- **`migration` stores the filename verbatim, not a numeric ID.** This is the single most important decision: it means all 21 existing scripts need **zero renaming**. The runner's "what's pending" logic becomes a plain diff between `glob('database/migrate_*.php')` and `SELECT migration FROM schema_migrations WHERE status = 'success'` — no second manifest to maintain, which is exactly the class of thing that already failed once (`includes/system_health.php`'s hand-maintained array, missing 4 of 21 scripts).
- **`checksum`** closes a real risk the audit surfaced: nothing today stops someone from editing an already-applied migration file, assuming that "fixes" production, when it doesn't (the script would only re-run on a database where it was never applied in the first place). If the checksum of a script marked `success` no longer matches the file on disk, the runner surfaces this as a warning that needs human judgment — it never silently re-runs a changed script.
- **`status` includes `'failed'`, not just successful runs.** A failed attempt is still recorded, with `error_message`, so a partial failure is visible in the same place as everything else instead of only in a PHP error log someone has to think to check. The runner treats anything other than `status = 'success'` as still pending, so a fixed-and-rerun migration naturally records a second, successful row.
- **`execution_time_ms`** is cheap and directly useful given the audit's own finding that several tables (`inventory_transactions`, `activity_logs`) have no retention policy and will keep growing — a migration that starts taking meaningfully longer over time is an early warning worth being able to see.
- **`executed_by`** reuses the exact pattern `activity_log()` already uses (`$_SESSION['user_id']` when available) rather than inventing a new identity concept.
- **Deliberately omitted: a `down`/rollback script reference, and a dependency/`depends_on` column.** See §4 (Rollback Strategy) and §2 (dependency handling) for why — both would be solving problems this codebase doesn't actually have today, which is exactly what the task brief asked to avoid.

## 2. Migration runner (historical — superseded by §7, kept for the design record)

> **This section, §2a, and §2b describe the subprocess-based execution model as originally designed and then patched. Production confirmed `exec()`/`shell_exec()`/`system()`/`passthru()`/`popen()` are all disabled, so this model can never actually run there. §7 describes what's actually implemented now.**

**New file: `database/migrate.php`.** Lives alongside the existing 21 scripts, following the same "run via browser or CLI" convention already established — no new deployment mechanism.

**Discovery:** `glob(__DIR__ . '/migrate_*.php')`, excluding itself. This is the fix for the actual root cause — migrations are found by *looking at disk*, never by consulting a second list that has to be remembered.

**Pending detection:** for each discovered file not present in `schema_migrations` with `status = 'success'` and a matching checksum, it's pending. Sorted by filename for a deterministic run order (see §3 for why this is sufficient without a full dependency graph).

**Two modes, mirroring how every sensitive action elsewhere in this app already works (confirm before destructive/impactful action)** — original sketch below; see §2a for what v1 actually implements (CLI-only, no browser path):
- **Check (default, no flag):** reports pending migrations and any checksum mismatches. Runs nothing. This closes a real, currently-live risk: today, simply *invoking* `migrate_x.php` executes it immediately, with no confirmation step. The new runner never executes on a bare invocation.
- **Run (explicit action, `--run` flag):** executes each pending migration's file in filename order, records one `schema_migrations` row per script (`success` or `failed` + captured output), and **continues past a failure** rather than stopping the batch — this matches today's actual tolerant behavior and is safe because every migration already independently checks its own preconditions via `INFORMATION_SCHEMA`; a migration that depended on a failed one will itself fail informatively and get its own recorded row, rather than corrupting anything silently.

**Failed migrations are handled by:** re-running `database/migrate.php --run` again after the root cause is fixed. Because every existing script is already idempotent, this is safe today and remains safe under the new runner — a `failed` row simply gets superseded by a new `success` row on the next successful attempt.

**Results are logged to:** the `schema_migrations` table (persistent, queryable) and echoed to output (matching every existing script's current behavior) and, on the `run` path specifically, one `activity_log()` entry summarizing what ran — reusing existing infrastructure rather than building a second logging system.

### 2a. Two deviations from this design, found during implementation

**1. Each pending migration runs as its own subprocess (`exec(PHP_BINARY . ' ' . $path)`), not `require`-d into the runner's own process.** This was not a stylistic choice — it's required. Grepping all 21 scripts found that **20 of them independently define an identical `migrate_run()` function** (and 18 define `migrate_column_exists()`) at global scope. `require`-ing any two of them into the same PHP process would fatal-error with "Cannot redeclare function migrate_run()" on the second one. Running each as a genuinely separate OS process avoids this completely — every migration already runs standalone today (`php database/migrate_X.php`), so this is actually the more faithful execution model, not a workaround. **This required zero changes to any of the 21 existing migration files** — the alternative (namespacing/renaming their internal helper functions to avoid collisions) would have meant rewriting all 21, which the task explicitly required stopping and asking about first. The subprocess approach made that unnecessary.

One consequence: because `migrate_run()`'s own internal per-statement try/catch already lets a script finish normally (exit 0) even if one of its `ALTER` statements failed internally, the runner's `success`/`failed` status reflects **"did the subprocess complete without a fatal PHP error,"** not **"did every statement inside it succeed."** The full captured output is always stored (in `error_message` on failure), so a human can see everything a script printed — same as reading its output when run manually today, which is the only way this has ever been verified either.

**2. The runner is CLI-only (`database/migrate.php`), not browser + CLI as originally sketched.** The original design's browser `run` path (POST + CSRF + `app_require_permission('settings.manage')`) added real UI/permission-plumbing complexity for v1, and the audit's own headline finding was that unauthenticated browser access to migration scripts is a live risk today — going CLI-only for this new script sidesteps that risk entirely rather than managing it, and mirrors `cli/job_worker.php`'s exact, already-proven `PHP_SAPI !== 'cli'` guard. `check` (preview) and `run` are both plain CLI invocations (`php database/migrate.php` / `php database/migrate.php --run`). A browser path can be added later as its own explicitly-scoped follow-up if wanted; v1 does not need it to satisfy the approved scope (discover, preview by default, explicit confirmation to execute, show pending/completed, record results).

### 2b. Host environment compatibility — a real production incident and its fix

Running `database/migrate.php --run` against production surfaced a real gap: the process printed `-> migrate_additional_costs.php` (the first pending migration alphabetically) and then terminated immediately, with no further output and no `schema_migrations` row written for any migration.

**Root cause, confirmed by local reproduction (see `docs/CHANGELOG.md` for the exact test):** `migrate_runner_execute()` called `exec($command, $outputLines, $exitCode)` without first initializing `$outputLines`/`$exitCode`. This works fine when `exec()` actually runs — it always populates its by-reference parameters. But shared hosts (Hostinger included, on some plans) commonly disable `exec()`/`shell_exec()`/`system()`/`passthru()`/`proc_open()` via `disable_functions` in `php.ini`, as a standard security measure. When a disabled function is called, PHP does not throw — it silently no-ops, and the by-reference parameters are never touched. The very next line, `implode(PHP_EOL, $outputLines)`, then received an unset variable and threw an **uncaught TypeError**, crashing the entire runner before `migrate_runner_record()` was ever reached for even the first migration — exactly matching the observed symptom.

**Why subprocess execution remains necessary regardless of this incident:** this is a robustness bug in how the runner *handles* subprocess execution, not a reason to abandon subprocess execution itself. §2a's reasoning is unchanged — 20 of the 21 existing migration scripts still redeclare an identical `migrate_run()` function, so `require`-ing more than one into a single process would still fatal-error. The fix keeps the exact same architecture and adds three things:

1. **Defensive initialization** — `$outputLines = []; $exitCode = null;` before the `exec()` call, so a disabled `exec()` degrades into a normal, recorded `'failed'` result instead of an uncaught crash.
2. **A pre-flight environment check** (`migrate_runner_check_exec_available()`) — run once, before attempting any migration, via `function_exists('exec')` and a `disable_functions` check. If `exec()` is unavailable, the runner stops immediately with a clear, actionable message (what's wrong, why subprocess execution is required, and to ask hosting support whether `exec()` can be allowlisted for CLI-invoked scripts specifically — several hosts, including Hostinger on some plans, permit it from SSH/CLI while blocking it for web-triggered PHP) — instead of failing silently and confusingly on whichever migration happens to sort first.
3. **Richer failure reporting** — a failed migration now reports its exit code, full captured output, and a best-effort "possible cause" heuristic (`migrate_runner_guess_cause()`), and both the success and failure recording calls are individually try/caught so a transient database hiccup while *recording* a result can never crash the batch or mask the real outcome.

**If `exec()` turns out to be disabled even for CLI/SSH-invoked scripts on this host** (not yet confirmed — the pre-flight check will report this clearly the next time `--run` is attempted), the next escalation is checking whether `proc_open()` specifically remains available even when `exec()` doesn't (hosts sometimes disable a subset, not all four), before considering anything that would require changes to the 21 existing migration files.

## 3. Migration naming convention

**Recommendation: keep all 21 existing filenames exactly as they are. For new migrations going forward, adopt `migrate_YYYYMMDD_description.php`.**

Rejected alternative: sequential integers (`001_`, `002_`, ...). A central sequence requires a coordinator to avoid collisions — a real risk for this project specifically, since two migrations were added in this session alone with no coordination mechanism between them. A date prefix is collision-resistant by construction and self-documenting (age is visible at a glance, useful with no separate migration changelog), while preserving the existing, already-proven `migrate_<description>.php` shape that every doc, comment, and the `system_health.php` checklist already refers to by name.

This is why §1's tracking table keys on filename rather than a generated ID — it's what makes "no renaming required" possible at all.

## 4. Safety rules

- **Backup requirement:** the `run` confirmation screen (browser) / `--run` flag (CLI) must show a reminder to confirm a recent backup exists, per `BACKUP.md`'s existing "daily minimum" recommendation. This is a manual checklist prompt, not new automated backup infrastructure — `BACKUP.md` itself confirms no backup automation exists yet, and building that is out of scope here.
- **Transaction handling:** deliberately **not** wrapping each migration in `$pdo->beginTransaction()`. MySQL's `ALTER TABLE`/`CREATE TABLE` cause an implicit commit in InnoDB regardless of an open transaction — wrapping DDL in a transaction doesn't provide real atomicity and would be a false sense of safety. The existing convention (each statement independently try/caught) is already the correct approach given this MySQL constraint; the new runner keeps it, per-statement, unchanged.
- **Rollback strategy: forward-only, no down-migrations.** Every one of the 21 existing scripts is purely additive (new tables/columns, `NULL`-able or defaulted, no data deleted or destructively rewritten) — there has never once been a need to undo one. This also matches CLAUDE.md's own standing rule ("never delete business history," "never hard delete orders"). A mistake is fixed by writing a new forward migration, not by reverting the old one. Building a rollback engine would be solving a problem this codebase has never actually had.
- **Production rules:** v1 is CLI-only (see §2a) — both `check` and `run` require direct server/SSH access, which is a stronger guarantee than any application-level permission check would be, and closes rather than reproduces §5 of the audit's finding (unauthenticated browser access to migration scripts).
- **Testing requirement:** a new migration should be run against a local or staging copy of the database before being considered ready, same expectation as today, just stated explicitly — no new staging/CI infrastructure is proposed, since none exists today and building it is out of scope.

## 5. Integration with Mewmii OS workflow

**How a future migration should be created:**
1. Write `database/migrate_YYYYMMDD_description.php`, following the existing docblock convention (purpose, tables/columns touched, idempotency statement) — no change to that convention, it already works well.
2. Use the existing `INFORMATION_SCHEMA`-check-before-altering pattern (or `CREATE TABLE IF NOT EXISTS` for new tables) — same as all 21 predecessors.
3. Nothing else to register anywhere — `database/migrate.php` discovers it automatically on its next `check` run. This is the actual fix for the incident: there is no second list to forget to update.

**Recommended CLAUDE.md addition** (not made in this pass — CLAUDE.md wasn't in this task's output list, so this is a recommendation for a future, explicitly-scoped update): add one line to the existing "Never modify schema without migration" rule — *"...and confirm `database/migrate.php` reports it applied before considering the task done."* This closes the gap where a migration script existing in the repo was treated as equivalent to it having actually been run.

**`includes/system_health.php`'s existing check does not need to be removed** — once `schema_migrations` exists, its 17-entry hardcoded array can be read from the tracking table instead of `INFORMATION_SCHEMA` directly (same UI, more reliable and complete source), but that's an implementation detail for the approved build phase, not a design requirement — flagging it here so it isn't independently rediscovered later as a "new" gap.

---

## 7. Architecture v2 (current) — in-process execution via a one-time mechanical refactor

**Why subprocess execution (§2/§2a/§2b) had to be abandoned, not just patched again:** production confirmed `exec()`, `shell_exec()`, `system()`, `passthru()`, and `popen()` are **all** disabled — standard practice on this class of shared hosting. §2b's fix made the runner *fail cleanly* when this happens; it could never make subprocess execution *work*. There is no PHP-level workaround for a disabled process-spawning function — the only way to run PHP code with real isolation without one is a genuinely separate execution context, and on stock PHP-FPM/CGI hosting the only such mechanism left is a separate HTTP request.

**An HTTP-loopback design was fully specified and then explicitly rejected.** The runner would have made an authenticated HTTPS request to the application's own domain per migration, hitting a new endpoint that `require`s exactly one migration file per request (PHP-FPM resets its symbol table between requests, giving real isolation without any subprocess). This worked on paper and required zero changes to the 21 existing files — but it meant a new web-reachable endpoint, a shared-secret token auth scheme, a curl/loopback-HTTP dependency, and materially more moving parts for what should be a simple database tool. Rejected for exactly that reason: unnecessary infrastructure complexity for a migration runner.

**What was built instead: eliminate the collision at the source, then run everything in one process for real.**

### 7.1 The collision, precisely

20 of the 21 existing migration files independently declared an identical `migrate_run()` function (18 also declared `migrate_column_exists()`, etc.) at global scope. PHP has no mechanism to undeclare a function once registered — `require`-ing two files that both declare the same function name fatal-errors with "Cannot redeclare function," full stop, regardless of execution model. This was the actual, permanent blocker — not `exec()` being disabled, which only made it impossible to route around with a subprocess.

### 7.2 The fix — three mechanical changes across the 21 files

Verified byte-for-byte before changing anything (see `docs/MIGRATION_SYSTEM_AUDIT.md`'s inventory and the exact diffs run during implementation): the shared helpers were identical or trivially cosmetic-different (one parameter name) across every file that had them, with exactly one real behavioral variant (3 files' `migrate_run()` skipped recording into the failures registry — confirmed those 3 files never read that registry either, so unifying on the fuller version only adds unused bookkeeping, never changes visible behavior).

1. **Extracted the shared helpers into `database/migrate_helpers.php`** — `migrate_column_exists()`, `migrate_table_exists()`, `migrate_index_exists()`, `migrate_failures()`, `migrate_run()`, plus one new function, `migrate_failures_reset()` (see below). Every migration file now does `require_once __DIR__ . '/migrate_helpers.php';` instead of locally re-declaring these. A handful of genuinely migration-specific helper functions (e.g. `migrate_catalog.php`'s `migrate_find_foreign_keys_on_column()`, `migrate_currency_rate_types.php`'s `migrate_find_single_column_unique_index()`) were **not** shared — they're unique to one file and stay there.
2. **Wrapped each migration's own top-level logic in a uniquely-named function**, `migrate_<name>()`, derived directly from the filename (`migrate_supplier_order_currency.php` → `migrate_supplier_order_currency()`) — this is the "unique execution function name" requirement, and it's what makes `require`-ing a file side-effect-free: the file now only *declares* its function, it doesn't run anything until that function is explicitly called. Every function returns a structured result:
   ```php
   [
       'success'  => bool,           // true only if $failures is empty
       'applied'  => array,          // labels of statements that succeeded
       'failures' => array,          // label => error message, for statements that failed
       'message'  => string,         // e.g. "3 statement(s) applied", "Already up to date"
   ]
   ```
   This is a genuinely new piece of information every migration now exposes (none returned anything before, since none were wrapped in a function) — but it's built entirely from `$applied`/`$failures`, the exact variables each script already computed internally; nothing new is calculated.
3. **Added a standalone-execution guard** at the bottom of every file: `if (!defined('MIGRATE_RUNNER_ACTIVE')) { migrate_<name>(app_db()); }`. `database/migrate.php` defines that constant before requiring any migration file, so the guard is skipped there (the runner calls the function itself, explicitly). Run directly — `php database/migrate_X.php`, or via browser — the constant is undefined, so the bottom guard fires and the file behaves exactly as it always did, echoing its own progress to output. **This is what keeps every migration standalone-runnable**, unchanged from before this refactor.

**Not changed in any of the 21 files:** any SQL statement, table name, column name, or control-flow condition. Confirmed by direct review of every file during implementation, and by testing (§7.4).

### 7.3 The rewritten runner

`database/migrate.php`'s discovery, pending/completed/modified-since-applied detection, `schema_migrations` schema, CLI-only guard, and CLI usage (`php database/migrate.php` / `--run`) are **unchanged** from §1–§4 above. What changed is purely how a pending migration is executed:

```
define('MIGRATE_RUNNER_ACTIVE', true);        // before requiring anything

For each pending migration $filename:
    migrate_failures_reset();                  // see below - prevents cross-migration bleed
    ob_start();
    try {
        require_once $filename;                 // declares migrate_<name>(), does not run it
        $result = migrate_<name>($pdo);          // ['success' => ..., 'applied' => ..., ...]
        $output = ob_get_clean();
    } catch (Throwable $e) {                    // one migration's real bug can't crash the batch
        $output = ob_get_clean();
        $result = ['success' => false, ...];
    }
    record to schema_migrations exactly as before (checksum, status, execution_time_ms, output)
```

**`migrate_failures_reset()` — the one real correctness fix this refactor required, beyond pure mechanics.** `migrate_failures()`'s data lives in a `static` variable, which persists for the life of the PHP process. That was harmless when every migration ran in its own subprocess (a fresh process = a fresh static) — but with several migrations now running back-to-back in *one* process, migration B could silently inherit migration A's stale failures without this reset. `database/migrate.php` calls it immediately before invoking each migration's function; no migration file calls it, and none needs to.

Every migration's function call is individually wrapped in `try`/`catch (Throwable)` in the runner's own loop — a genuine bug in one migration (not the per-statement failures each script already catches internally, but an actual uncaught error) is now caught by the runner and recorded as a failure, and the batch continues to the next migration, rather than taking down the whole run.

### 7.4 What this makes obsolete from earlier sections

- §2b's `migrate_runner_check_exec_available()` pre-flight check and `migrate_runner_guess_cause()` diagnostic — both were specifically about diagnosing subprocess/`exec()` failures. There is no `exec()` call anywhere in the new runner, so neither applies; both were removed rather than kept as dead code.
- Any reference to `PHP_BINARY`, shell command construction, or captured subprocess exit codes.

### 7.5 Testing performed

- `php -l` on all 23 touched/new files (`migrate.php`, `migrate_helpers.php`, all 21 migrations) — clean.
- Against a fresh local database seeded from `install.sql`: ran `database/migrate.php --run` — **all 21 migrations succeeded in a single PHP process** (the exact scenario that used to fatal-error before this refactor), including the two most complex files (`migrate_catalog.php`, which also executes the *entirety* of `schema.sql` via `$pdo->exec(file_get_contents(...))` as its own first step, and `migrate_production_hardening.php`, which contains a multi-table customer-deduplication routine).
- **Idempotency verified**: re-ran the runner (both preview and `--run`) immediately after — all 21 correctly reported as already-`Completed`, 0 pending, no errors, no duplicate application.
- **Standalone execution verified for all 21 individually**: reset to a fresh pre-migration database and ran every `migrate_X.php` file directly (`php database/migrate_X.php`, not via the runner) in sequence — all exited 0 with their expected "Done." message; the `MIGRATE_RUNNER_ACTIVE`-gated auto-run guard fired correctly in every case.
- **Verified against the live database schema, not just log output**: queried `INFORMATION_SCHEMA` directly after a run and confirmed `supplier_orders.currency`, `product_variations.weight_mode`, `currency_rates.rate_type`, the `resolution_requests` table, and the `supplier_orders.purchase_number` UNIQUE index were all genuinely present — the migrations' real DDL executed, not just their echoed claims.
- One real bug found and fixed *during* this testing (not before): the discovery glob (`migrate_*.php`) also matched `migrate_helpers.php` itself, which would have been "run" as a fake 22nd migration and failed (no `migrate_helpers()` function to call). Fixed by explicitly excluding it in `migrate_runner_discover()`.
- Not tested: execution against production, or any real production data — only a local, throwaway MariaDB instance was used, torn down after.

### 7.6 Future rollback convention (documented only — not implemented)

A future migration **may** optionally define `rollback_<migration_name>()` alongside its `migrate_<migration_name>()` function (e.g. `migrate_foo()` paired with `rollback_foo()`). **Nothing in `database/migrate_helpers.php` or `database/migrate.php` currently looks for or calls such a function** — this is purely a naming reservation, documented in both files' own docblocks, so that if rollback support is ever actually built, no migration written under this convention needs restructuring to adopt it. Do not add a `rollback_*()` function speculatively; only add one when a specific migration genuinely needs to be reversible and rollback execution has actually been implemented in the runner. This does not change §4's rollback-strategy reasoning (forward-only remains the working default) — it only keeps the door open cheaply.

---

## 8. Summary of what this design deliberately does NOT add

Per the task's explicit constraint ("do not introduce unnecessary complexity"):

- No rollback/down migration *execution* (§4, §7.6 — forward-only remains the default; the naming convention is reserved, not built).
- No renaming of any existing file (§1/§3 — filename-keyed tracking makes this unnecessary; §7 confirms this held even through the architecture change).
- No new deployment pipeline, CI, or staging environment (none exists today; out of scope).
- No dependency graph / `depends_on` metadata — only one real dependency exists across all 21 scripts (`migrate_currency_rate_types.php` → `migrate_currency_rates.php`), and it already fails safely and informatively if run out of order, since the dependent script's own `ALTER` would simply error against a nonexistent table.
- No HTTP endpoint, token auth, or loopback dependency (§7 — the alternative that was designed and explicitly rejected).
- No change to any migration's actual SQL/business logic — the refactor is 100% mechanical (extraction + function-wrapping), confirmed by review and by testing.
