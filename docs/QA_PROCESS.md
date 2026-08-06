# QA Process

The standard verification workflow for every UI phase. Adopted after V3 Phase 3.1a, when a
browser QA pass found two real defects that **268 CLI assertions and six clean smoke runs had
all missed** — because neither defect changed anything structural.

---

## 1. The two verifiers answer different questions

Both are required. Neither substitutes for the other.

| | `tools/smoke` | `tools/browser-qa` |
|---|---|---|
| **Question** | *Did the page structure change?* | *Does it look and behave correctly?* |
| **Sees** | HTTP status, form `action`/`method`/field names, heading counts, table columns, PHP notices | computed colour, focus, hover, keyboard, ARIA, spinner, layout |
| **Blind to** | anything visual or interactive | anything it does not have an assertion for |
| **Needs** | PHP + curl | PHP + Chrome/Edge + Node |
| **Runtime** | ~40s, 94 routes | ~60s |

The Phase 3.1 defect is the canonical example: a CSS specificity conflict was silently
swallowing the focus ring on every form control in the application. Nothing structural changed,
so every smoke run passed — correctly. Only a computed-style assertion could see it.

---

## 2. The workflow

Run in this order for every UI phase. Do not skip step 2 — a stale dataset makes step 3
unreadable (see §4).

```
1. Apply implementation
2. php tools/browser-qa/seed_qa.php          # seed the dataset
3. php tools/smoke/smoke.php verify ...      # structural regression
4. cd tools/browser-qa && npm run qa         # visual + behavioural
5. Fix regressions if found
6. Capture the new baseline
7. Commit (code + snapshot together)
```

### Prerequisites

- A **running instance** you can log into (`php -S 127.0.0.1:8901 -t .` is sufficient).
- A **throwaway database** on the allow-list in `tests/_guard.php`. Never point any of this at
  real data — `seed_qa.php` writes ~200 rows and the behavioural suites in `tests/` truncate
  tables.
- `config.php` present (gitignored). Copy from `config.example.php`; the DB name comes from the
  `DB_DATABASE` environment variable.

---

## 3. The seeded dataset is part of the contract

`tools/browser-qa/seed_qa.php` brings the database to a known shape:

| | Rows | Why it matters |
|---|---:|---|
| Products | 60 | 3 pages at 25/page — exercises first, middle and last pagination states |
| Customers | 60 | pagination + dropdown cap behaviour |
| Orders | 60 | bulk-select checkboxes for the selected-row table state |
| Supplier orders | 30 | destructive dialogs with a real record name in the title |
| Suppliers, brands, tags | 5 / 1 / 1 | catalog delete dialogs need a target to name |

Without this, whole checks silently pass by *not running*: a single-page list renders no
prev/next controls, an empty queue renders no dialog trigger, and a table with no rows has no
row to select. A QA run on a thin dataset can report all-green while testing almost nothing.

The account also needs the **full permission set**. A user with partial permissions makes the
smoke crawler 403 on most routes, which looks like a clean baseline and is not — this happened
during Phase 3.1 and produced a baseline with `index.php` returning 403.

---

## 4. Baselines: the failure mode to watch for

**A smoke `PASS` that carries unexplained warnings is not a pass.** It has meant a real problem
three times:

1. **Contaminated baseline (Phase 3.1).** The baseline was captured while the crawler was only
   partly authenticated. The comparison reported `0 breaking` alongside 158 warnings including
   `sidebar_links 26 → 42` — differences CSS cannot produce. Re-captured with `index.php`
   returning 200, then clean.
2. **Real defect caught by a diff (Phase 3.2a).** `<h2> count 0 → 1` on every page: the
   confirmation dialog's title was a heading, injecting one into all ~94 page outlines via
   `footer.php`. Changed to a `<div>` — the accessible name comes from `aria-labelledby`, so
   assistive tech was unaffected. Caught before commit *because* the warning was investigated.
3. **Dataset drift (Phase 3.1a).** 18 warnings — `badges 4 → 40`, `empty_states 3 → 0` — were
   entirely row-count differences, because the baseline predated the QA seed. Re-captured with
   the code changes stashed so the data was held constant, then compared: clean.

### Capturing a baseline correctly

To prove a change is structurally inert, hold the **data** constant and vary only the **code**:

```bash
git stash push -- <the files you changed>
php tools/smoke/smoke.php capture --out=tools/smoke/snapshots/base-<phase>.json ...
git stash pop
php tools/smoke/smoke.php verify --against=tools/smoke/snapshots/base-<phase>.json \
                                 --out=tools/smoke/snapshots/after-<phase>.json ...
```

Prefer `verify` over `capture` + `compare`: it produces the answer while both files are still
guaranteed to exist. Snapshots are **committed on purpose** — see
`tools/smoke/snapshots/.gitignore` for why. Name them `after-<phase>.json`, one per sub-phase.

**A snapshot embeds the dataset it was captured against.** `after-3.1a.json` onward reflects the
seeded dataset, so run `seed_qa.php` before comparing against it.

---

## 5. Reading a browser QA failure

**Confirm the cause before treating it as a defect.** Of the first five failures, three were
real and two were the harness's own fault:

- A focus-trap assertion sampled the single frame where Bootstrap hands focus through `<body>`
  before wrapping it back. The trap was working; the assertion now checks the thing that
  matters — focus never reaching an interactive element *behind* the dialog.
- An Escape assertion failed because the preceding test had already parked focus outside the
  dialog, so the keydown never reached it.
- A readonly-border assertion was measured while the field still held focus, so
  `[readonly]:focus` legitimately won.

**Headless Chrome handles Tab focus differently from headed.** When a keyboard or focus result
looks wrong, re-run that check with `headless: false` before concluding anything.

Every failure prints `got` and `want` and is written to `tools/browser-qa/issues.json` with a
severity. Screenshots land in `tools/browser-qa/shots/`. Both are gitignored — evidence for one
run, not source. Attach them to the QA report instead.

---

## 6. What is still not covered

Stated so nobody assumes otherwise:

- **Real screen-reader output.** The harness asserts ARIA attributes; it does not verify what
  NVDA or VoiceOver announces.
- **Any browser other than Chrome/Edge.** No Firefox or Safari coverage. The `::placeholder`
  `opacity: 1` rule exists specifically because Firefox differs, and that is unverified here.
- **Real pointer input.** Hover is driven programmatically; touch is not tested at all.
- **Print styles, reduced-motion, forced-colors, zoom.**
- **Performance.** Neither tool measures page weight or render time.

---

## 7. Related tooling

| Path | Purpose |
|---|---|
| `tools/smoke/` | Structural regression verifier. See its README for route rules and safety notes. |
| `tools/browser-qa/` | Visual and behavioural verifier. See its README for configuration. |
| `tests/` | Behavioural suites from V2 — business outcomes over the real ledger, not markup. Guarded against non-throwaway databases by `tests/_guard.php`. |
