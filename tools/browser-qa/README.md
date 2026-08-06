# Browser QA harness

Drives a real, installed Chrome against a running Mewmii OS and asserts on **computed
styles and actual event behaviour** — not on markup.

This exists because `tools/smoke` cannot see it. The smoke verifier compares structural
fingerprints (HTTP status, form `action`/`method`/field names, heading counts), which is
exactly right for catching a broken conversion — and it passed cleanly through every
sub-phase of V3 Phase 3. It still could not see that a CSS specificity conflict was
silently swallowing the focus ring, because nothing structural changed. The first run of
this harness found two real defects that 268 CLI assertions and six clean smoke runs had
all missed.

The two tools answer different questions and both are needed:

| | `tools/smoke` | `tools/browser-qa` |
|---|---|---|
| Question | *Did the page structure change?* | *Does it look and behave correctly?* |
| Sees | forms, fields, status codes, headings | computed colour, focus, keyboard, ARIA |
| Needs | PHP + curl | PHP + Chrome + Node |

## Requirements

- **Node 18+** and npm.
- **Google Chrome or Edge installed.** `puppeteer-core` drives your existing browser and
  downloads nothing.
- A **running Mewmii OS** you can log into, backed by a **throwaway database**. The seed
  script writes ~200 rows; never point it at anything real.

## Running

```bash
cd tools/browser-qa
npm install                 # once - installs puppeteer-core only

# seed enough rows that pagination has multiple pages and tables have selectable rows
DB_DATABASE=mewmii_rrtest php seed_qa.php

npm run qa
```

Exit code is 0 when every assertion passes.

### Configuration

All optional — the defaults match a local `php -S` on port 8901.

| Variable | Default |
|---|---|
| `QA_BASE_URL` | `http://127.0.0.1:8901` |
| `QA_CHROME` | `C:/Program Files/Google/Chrome/Application/chrome.exe` |
| `QA_EMAIL` | `t@t.t` |
| `QA_PASSWORD` | `Smoke1234!` |

On Edge, point `QA_CHROME` at `msedge.exe` — the harness uses no Chrome-only APIs.

## What it covers

**Form controls** — resting, hover, focus, invalid, readonly, disabled and placeholder,
each read back as a *computed* colour and compared against the token value.

**Confirmation dialogs** — all three tones; `role` and initial focus per tone; Escape,
backdrop and Cancel all resolving to "no"; focus trap; focus return; and the case that
matters most, that **Enter on an unread dialog does not delete anything**.

**Tables** — header surface, row hover, selected-row tint, tabular figures, and that a
390px viewport does not scroll horizontally.

**Pagination** — first page, last page, disabled states, and single-page lists rendering
no controls at all.

**Loading states** — spinner, `aria-busy`, width preservation, label restoration, and
double-submit prevention.

**Regression walkthrough** — 12 pages across the eight highest-risk modules, asserting
HTTP 200, exactly one `<h1>`, no PHP notice text, and an empty JS console.

## Reading a failure

A failure prints `got` and `want`, and every failure is also written to `issues.json` with
a severity. **Confirm the cause before treating it as a defect** — the first run produced
five failures, of which two were artifacts of the harness's own sequencing:

- A focus-trap assertion sampled the single frame where Bootstrap hands focus through
  `BODY` before wrapping it back. The trap was working.
- An Escape assertion failed because the preceding test had already parked focus outside
  the dialog, so the keydown never reached it.

Headless Chrome also handles Tab focus differently from headed. When a keyboard or focus
result looks wrong, re-run that check with `headless: false` before concluding anything.

## Not committed

`shots/`, `issues.json`, `node_modules/` and `package-lock.json` are ignored. Screenshots
are evidence for one run, not source — attach them to a QA report rather than the repo.
