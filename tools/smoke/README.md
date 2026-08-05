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

Run it against **a development or staging database, never production.** It only issues `GET`
requests (plus one login `POST`), but it is a crawler and should be treated as one.

---

## Usage

### 1. Review what will and won't be crawled

```
php tools/smoke/smoke.php routes
```

No HTTP, no login. Prints every discovered route, which ones need an `?id=`, and every excluded
endpoint **with the reason it was excluded**. Read this first.

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

## Pages needing an `?id=`

19 routes read `$_GET['id']` and cannot be crawled bare. The tool handles these in a second pass:
while crawling list pages it harvests real `?id=N` values out of the HTML, then samples the
**lowest** id per module. A route is recorded as `skipped` — with the reason — when no id could
be discovered (typically an empty module in a fresh database).

Sampling is deliberately lowest-id rather than first-seen. If a before-run and an after-run
sampled *different* records, two legitimately different states (a draft order vs a shipped one)
would diff as a false BREAKING form change. Lowest-id is stable across runs, provided the record
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
