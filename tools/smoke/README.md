# Mewmii OS — Smoke Verification Tool

A standalone structural regression harness, built to protect the V3 UI/UX phases.

**It is completely independent of the application.** It includes no application file, opens no
database connection, and calls no application function. It talks to a running Mewmii OS over
plain HTTP exactly as a browser would. Nothing in the application was changed to accommodate it.
Delete `tools/` and the application is unaffected.

---

## Why this exists

This project has no test suite. V3 restructures pages that between them contain dozens of forms,
and the dangerous failure mode is silent: a field that stops being submitted because its markup
moved, or a form whose `action` changed during a layout refactor. Neither produces a visible
error — the page still renders, the button still clicks, and the data quietly stops saving.

This tool turns that class of regression into a mechanical diff.

---

## Requirements

- PHP 7.4+ CLI with `ext-curl` and `ext-dom` (both are standard in XAMPP/Laragon).
- A running Mewmii OS instance you can log into.

> ### Run this against staging, not production
>
> It issues `GET` only (plus one login `POST`), but it is still a crawler pointed at an
> authenticated admin panel, and the application is **not** hardened against being crawled.
> The first production run hit `modules/products/sync.php`, which triggers a full WooCommerce
> product push; only its CSRF check stopped it. See "Baseline audit" below.
>
> Credentials passed as `--password=` land in your shell history and process list. Prefer the
> `SMOKE_*` environment variables, and use an account you are willing to rotate.

---

## Usage

### 1. Review what will and won't be crawled

```
php tools/smoke/smoke.php routes
```

No HTTP, no login. Prints every discovered route, which ones need an `?id=`, and every excluded
endpoint **with the reason it was excluded**. Read this first.

### Preferred: `verify` — capture and compare in one run

```
php tools/smoke/smoke.php verify \
  --against=tools/smoke/snapshots/baseline-before-2.4.json \
  --out=tools/smoke/snapshots/after-2.4.json
```

Use this rather than `capture` followed by `compare`. **The answer matters more than the
artefact.** Snapshots have repeatedly been lost between the two steps — captured on the
production server, then gone before anything could be diffed against them, which silently cost
two phases their verification. `verify` produces the regression result while both files are
still guaranteed to exist, so a snapshot that later disappears cannot take the answer with it.

Exit code is the comparison's: `0` clean, `1` regressions found.

### Keeping snapshots

Snapshots are committed (see `snapshots/.gitignore`). If you capture on a server whose working
directory is wiped by deploys, git alone will not save you — **commit or copy the file back**,
or rely on `verify` so the comparison has already happened. `capture` warns when it has written
the only snapshot in its directory.

### 2. Capture a baseline (before making changes)

```
php tools/smoke/smoke.php capture \
  --base-url=http://localhost/mewmii \
  --email=you@example.com \
  --password=secret \
  --out=tools/smoke/snapshots/before-phase2.json
```

Credentials can also come from `SMOKE_BASE_URL`, `SMOKE_EMAIL`, `SMOKE_PASSWORD` so they stay out
of your shell history.

`--limit=N` crawls only the first N parameterless routes — useful for a quick sanity run.

### 3. Make your changes, then capture again

```
php tools/smoke/smoke.php capture ... --out=tools/smoke/snapshots/after-phase2.json
```

### 4. Compare

```
php tools/smoke/smoke.php compare \
  tools/smoke/snapshots/before-phase2.json \
  tools/smoke/snapshots/after-phase2.json
```

Exit code `0` = no structural regressions, `1` = regressions found, `2` = usage/transport error.

---

## What it checks

Per route, it records and compares:

| Category | Captured | Severity if changed |
|---|---|---|
| **HTTP status** | Final status after redirects | **BREAKING** if it becomes ≥400 |
| **Route reachability** | Present in snapshot | **BREAKING** if it disappears |
| **PHP diagnostics** | 14 signals (`Fatal error`, `Warning:`, `Undefined array key`, `SQLSTATE`, …) leaked into the body | **BREAKING** if new |
| **Form action** | `action` attribute per form | **BREAKING** if a form's `method + action` vanishes |
| **Form method** | `get` / `post` | **BREAKING** (same rule) |
| **Input names** | every `input` / `select` / `textarea` `name`, sorted | **BREAKING** if a name is lost |
| **Button names** | `button` names, falling back to label text | **BREAKING** if lost |
| **Form count** | forms per page | **BREAKING** if it drops, warning if it grows |
| **Headings** | `h1` / `h2` / `h3` / `h5` counts | INFO |
| **Tables** | column count per table | INFO |
| **Components** | cards, badges, empty-states, filter-cards, modals, nav links | INFO |
| **Controls** | link and button counts | INFO |

**Field names are sorted before comparison.** This is deliberate: moving a field into a tab or
reordering a form is invisible to the tool, but *losing* one is not — which is exactly the V3
risk profile. `csrf_token` is excluded as per-session noise.

Heading, table, and component counts are reported as INFO rather than failures. Phase 2 changes
`h2 → h1` and `h5 → h2` on purpose, so those must not fail the run — but they should still be
*visible*, so you can confirm the change happened where you intended and nowhere else.

---

## Safety — read before editing the route rules

The crawler issues **GET only**, and works from an explicit deny-list.

This matters. `modules/products/ajax/delete_variation.php` performs a destructive write
**without checking the request method** — a naive "GET every `.php`" crawler would delete product
variations. Similarly, `modules/settings/reset_test_data.php` deletes orders, customers, and
supplier orders.

Every exclusion carries a machine-readable reason, listed by `smoke.php routes`. The excluded
classes are:

| Class | Reason |
|---|---|
| `_*.php` partials | Included by another page; not routable alone |
| `**/ajax/**`, `ajax_*.php` | Fragment endpoints; some write without a POST guard |
| `delete.php`, `bulk_action.php` | Mutation endpoints |
| `sync_one.php`, `reopen_preorder.php`, `create_order.php`, `retry_pending.php` | POST-only action endpoints |
| `export_*.php`, `*_download.php`, `import_template.php` | File downloads, not HTML pages |
| `reset_test_data.php` | **Destructive** — never crawled |
| `logout.php` | Would end the crawler's session mid-run |
| `install.php` | Would re-seed the database |
| `test-db.php`, `generate_password.php`, `config.example.php` | Developer utilities, not application pages |

All remaining `create.php` / `edit.php` / `import.php` pages were individually verified to gate
every mutation behind `if ($_SERVER['REQUEST_METHOD'] === 'POST')`. A GET renders the form only.

---

## Baseline audit — first production run

The first baseline run (against the live admin domain, commit `62eec69`) reported 6 HTTP failures
and 2 PHP fatal errors. **All eight were classification gaps in this tool. Zero were application
bugs.** Every one is now fixed. Recorded here so the same findings aren't re-investigated.

| Reported | Verdict | Resolution |
|---|---|---|
| `403 config.php` | Tool gap. The 403 is *correct* — the server is properly refusing to serve the file holding DB credentials. | Excluded: config file, not a page |
| `404 modules/customer-storage/view.php` | Tool gap. Needs `?customer_id=`, not `?id=` | Now crawled with the right parameter |
| `404 modules/inventory/allocate.php` | Tool gap. Needs `?product_id=` + `?variation_id=` | Now crawled with the right parameters |
| `404 modules/inventory/reserve.php` | Tool gap. Same as above | Now crawled with the right parameters |
| `404 resolution_receipt.php` | Tool gap. Needs `?receipt_id=` **and** a valid `?token=`; returns a file | Excluded: token-authenticated download |
| `400 + fatal modules/products/sync.php` | Tool gap, and a **near miss** — see below | Excluded: action endpoint |
| `fatal modules/inventory/views/drawer.php` | Tool gap. A view partial, documented "never invoked directly"; fatals standalone because `bootstrap.php` was never loaded | Excluded: `views/` partials |
| *(not reported, found during the audit)* `modules/dashboard/index.php` | Dead 0-byte file — the real dashboard is the root `index.php`. Returns a blank 200. | Excluded by the generic partial rule |

### The `products/sync.php` near miss

`modules/products/sync.php` triggers a **full WooCommerce product push for every product**. The
crawler issued a GET against it on a live system.

Nothing ran. `app_require_csrf()` is on line 5 and reads `$_POST['csrf_token']`, which is empty on
a GET, so it set HTTP 400 and threw before reaching `wc_client_sync_all_products()` on line 15.
The "fatal error" in the baseline is that uncaught exception. **No sync executed and no data
changed** — but the only thing standing between a smoke crawl and a full production sync was that
CSRF check.

The original exclusion list caught `sync_one.php` and missed `sync.php`. It is now excluded
explicitly, and an audit was run for every other crawlable route that calls `app_require_csrf()`
without an earlier request-method guard. The only other two matches were false positives — the
string appeared in a doc comment, not in code.

**If you add a route rule, re-run that audit.** The application is not hardened against being
crawled; this tool's deny-list is what keeps it safe.

## Pages needing an identifier parameter

**Every route is crawled bare first.** Only routes that actually *fail* bare are retried with a
query string harvested from a real link during pass 1.

This is deliberate, and it is the second thing the baseline runs got wrong. Reading
`$_GET['supplier_id']` does not mean a page *requires* it — list pages read `*_id` as optional
filters. The second baseline classified `orders/index.php`, `products/index.php`,
`inventory/index.php` and `supplier-orders/index.php` as un-crawlable on that basis and skipped
all four: the busiest pages in the application, and the ones Phase 2 changes most. Letting
behaviour decide instead of source removes that whole class of error — a page that returns 200
bare needs nothing, whatever its source reads.

Retries use the app's own links, so parameter names are always correct: `?customer_id=` for
customer storage, `?product_id=&variation_id=` for inventory allocate/reserve. A route is recorded
as `skipped`, with its bare status and the reason, when no usable link was found — typically an
empty module.

Retries pick the **lowest** identifier, not the first seen. If a before-run and an after-run
sampled *different* records, two legitimately different states (a draft order vs a shipped one)
would diff as a false BREAKING form change. Lowest-id is stable across runs provided the record
still exists — so **do not delete the sampled records between a before and after capture.**

This means **coverage depends on your data.** A database with at least one order, product,
customer, supplier, supplier order, and shipment gives materially better coverage than an empty
one. The skip list is printed in the run summary, so gaps are always visible rather than silent.

---

## Limitations — stated plainly

- **It verifies structure, not correctness.** A page can return 200 with a perfect form signature
  and still be visually broken or functionally wrong.
- **It does not execute JavaScript.** Content rendered client-side is invisible to it. Forms
  inside Bootstrap modals *are* captured, since those exist in the server-rendered HTML.
- **It does not compare visual appearance.** No screenshots, no pixel diffing. Phase 1's
  "zero visual change" claim was proven by resolving CSS variables and diffing, not by this tool.
- **It only samples one record per module** for `?id=` routes. A bug that only manifests on a
  specific record will not be caught.
- **PHP diagnostic detection depends on `display_errors`.** If the server hides errors, the tool
  cannot see them. Enable `display_errors` in your development environment for full value.
