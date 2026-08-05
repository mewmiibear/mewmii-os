# Behavioural test suites

The functional baseline for the V2 work (Reverse Receiving, P1–P7) and the V2.1 U1 navigation
change. Preserved here so a later redesign can be checked against known-good behaviour.

**These assert on business outcomes, not on markup.** They read the real inventory ledger
(`inventory_transactions`, `mewmii_inventory`), order status, `customer_storage`, and the
values rendered into form fields — never CSS classes or layout structure. That is deliberate:
a UI/UX redesign should keep every one of these green. A failure means behaviour moved, not
that a page was restyled.

## Running them

```
php tests/run_all.php          # every suite, one combined result
php tests/p4_test.php          # a single suite
```

Exit code is 0 only when every suite passes and every suite reports.

## Required environment

- **MySQL running locally** (XAMPP is what these were written against).
- **A throwaway database** whose name is on the allow-list in `_guard.php`
  (`mewmii_rrtest` or `mewmii_test`), created with the full schema:
  ```
  mysql -u root -e "CREATE DATABASE mewmii_rrtest"
  mysql -u root mewmii_rrtest < database/schema.sql
  ```

Connection settings are set per-suite via `putenv()` and default to `127.0.0.1` / `root` /
no password. `includes/env_loader.php` never overrides an already-set environment variable,
so a real `.env` will not redirect these at your production database.

## Safety

**Every suite truncates tables.** They write real rows so they can assert on the actual ledger
rather than on mocks, which makes them destructive by design.

`_guard.php` is required by every suite and aborts unless the configured database name is on
the allow-list. It reads the value `config.php` will actually use, so a suite that forgets its
own `putenv()` still fails closed. The list is explicit rather than a pattern (`*_test`) so a
typo cannot accidentally match a real database.

Suites seed their own fixtures and must run **sequentially** — never two at once against the
same database.

## What each suite covers

| Suite | Covers |
|---|---|
| `rr_test.php` | Reverse Receiving — negative-ledger reversal, allocation protection, status rollback |
| `ui_test.php` | Reverse Receipt modal wiring + full POST round trip |
| `p1_test.php` | Reservation Center in-place Auto Reserve, FIFO order, rollback |
| `p2_test.php` | Shared last-used carrier, incl. the edit-tracking regression guard |
| `p3_test.php` | Ship My Box quantity defaults and availability ceilings |
| `p4_test.php` | Customer Storage remove defaults, CSRF, permissions, transactions |
| `p6_test.php` | Customer dropdown standardisation, 200-row cap, pinned customer |
| `p7_test.php` | Supplier Orders Receive shortcut visibility rules |
| `u1_test.php` | Context-aware return navigation + open-redirect rejection |

## HTTP verification

`seed_*.php` scripts pair with the suites for end-to-end checks against a real web SAPI, which
is the only way to observe redirects (`header()` is a no-op under CLI):

```
php tests/seed_integration.php
DB_DATABASE=mewmii_rrtest php -S 127.0.0.1:8899 -t .
```

Then log in over HTTP and exercise the flows. Seeded login is `t@t.t` / `Test1234!` where the
seed script creates one.

## Known limitations

- **No browser.** Anything relying on real browser behaviour is unverified: the Reverse Receipt
  modal's Esc/focus-trap/backdrop, and the Ship All / Clear JS helpers. Both rely on Bootstrap's
  own components or trivial DOM writes.
- **Assertions are order-sensitive** where arrays are compared with `===`. Pages that list
  newest-first will not match a naively-ordered expectation.
- These are not unit tests and have no framework, no mocking, and no isolation between suites
  beyond each one truncating and re-seeding.
