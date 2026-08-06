# Changelog

All notable changes to Mewmii OS are recorded here, newest first.

## Unreleased

### V3 Phase 3.6a-2 — the two remaining headers

Closes the `.page-header` rollout. In-scope adoption is now **67/67**; the nine pages without the
component are all documented §7 exclusions (5 importers, `products/_form.php`,
`settings/system_health.php`, `settings/reset_test_data.php`, and `resolution.php` — which has no
`<h1>` in the admin sense but is counted here for completeness).

**`catalog/index.php` was never bespoke — it was a measurement error in the 3.6a-1 audit.** The
page already carried `page-header`, `page-description` and `action-bar`. The audit tested
`str_contains($src, 'class="page-header')`, which only matches when the class is written *first*
in the attribute; this page had `class="d-flex justify-content-between align-items-center mb-4
page-header"`, with the class written last. The migration was therefore a class reorder plus
dropping the stale `mb-4`. That `mb-4` had been inert — Bootstrap's utilities load before
`components.css`, so `.page-header`'s own 32px margin already won at equal specificity — but it
misdescribed the layout and is gone.

This is the third audit in Phase 3 whose *matcher* was wrong rather than its subject (after the
`str_ends_with('/index.php')` exclusion bug and the "9 primary buttons" class-count). Substring
tests against hand-written markup keep producing false negatives; attribute-aware matching is the
fix, not a wider substring.

**`search/index.php` did genuinely differ.** It had `<div class="mb-4">` with no flex row, because
it is the one migrated page with no actions at all, and `<p class="text-muted mb-0">` instead of
`.page-description`. It now uses the same wrapper as every other page — all 31 pages from 3.6a-1
use the identical class string regardless of whether they have an action bar, so the shape was
matched rather than a no-actions variant invented. The description is a PHP conditional (result
term vs. empty-state prompt) and both branches were checked.

**Verification:** smoke 0 breaking / 0 warnings / 0 informational; browser QA 67 passed, 0 failed.
Computed styles on both pages plus the empty-search branch: `.page-header` margin-bottom 32px,
exactly one `<h1>`, `.page-description` at `--text-muted`, no horizontal overflow, no JS errors.
Before/after screenshots differ only by the 8px the header gap grew (24px → 32px); nothing else
moved.

### QA tooling — `seed_qa.php` referenced a table that does not exist

Separate from the UI work it was found alongside, and recorded separately so the history
distinguishes **UI standardisation** from **QA tooling maintenance**.

`tools/browser-qa/seed_qa.php` inserted its brand fixture into `product_brands`. The real table
is `brands`, so the script fatally errored on its last two statements and seeded no brand or tag
— which in turn meant the catalog delete dialogs had no record to name during a QA run.

The bug shipped in the 3.1a commit that promoted the harness out of scratch. It had been worked
around by hand at the time (a direct `INSERT` against the correct table), and the workaround
never made it back into the file — so the committed script had never actually run end to end.
Fixed in the 3.6a-1 commit; the script now completes and reports its row counts.

Worth noting as a pattern: a tooling script that is only ever run once, by hand, with its
failures patched interactively, is not verified just because the work it supported was.

### V3 Phase 3.6a-1 — `.page-header` rollout (31 pages)

Markup only. No behaviour, no layout redesign, no typography outside the design system, no PHP
logic touched. Per page: the ad-hoc `d-flex justify-content-between align-items-center mb-4`
wrapper becomes `page-header d-flex justify-content-between align-items-center` (the component
owns the 32px bottom margin, so `mb-4` goes), the muted description takes `.page-description`,
and trailing actions are grouped in `.action-bar`.

**The documented target of 41 pages was stale** — it predates Phase 2.4 promoting 79 titles to
`<h1>`. Re-measured before touching anything: 85 pages audited, 34 already compliant, **35
needing migration**, of which 31 are mechanical. Adoption went **34/85 → 65/85**.

Action-bar handling was deliberately not forced into one shape: 6 pages already had one, 7 had a
`d-flex gap-2` container that is an action bar in all but name and was renamed rather than
nested, 13 had a bare action run that was wrapped, and 5 have no actions. The transformer skipped
anything it could not do confidently — its first pass reported 10 sites as "conditional", which
on inspection were two different shapes, and only then were they handled correctly.

**Deferred to 3.6a-2**, each needing individual judgement rather than a class swap:
`catalog/index.php` and `search/index.php`. *(The description of `catalog/index.php` as having
"no wrapper" was wrong — see 3.6a-2 below.)*

**Two pages newly excluded** and added to §7: `settings/reset_test_data.php` (administrative
maintenance utility behind a typed-phrase gate — treated consistently with System Health) and
`resolution.php` (root-level customer-facing page, outside the admin module experience). §7's
`catalog/tabs/*` count was also corrected from 4 to 5.

**Verification:** smoke 0 breaking / 0 warnings / 0 informational — a class rename should be
structurally inert, and is. Browser QA 67 passed, 0 failed. Computed styles checked on five
migrated pages: `.page-header` margin-bottom 32px, `.action-bar` gap 9.6px, exactly one `<h1>`,
`.page-description` present.

### V3 Phase 3 — Interaction & Density (3.1–3.5, plus the 3.1a/3.2g regression fixes)

Phase 3 of the V3 redesign, organised by *system* rather than by page per the Phase 2.6
re-ranking (`V3_DESIGN_SYSTEM.md` §4.2). Eleven commits, each independently verified before
the next began. **No database, schema, business logic, routing, permission or workflow change
anywhere in the phase** — form `action`/`method`/field `name` attributes and every
`app_has_permission()` gate are byte-identical throughout.

**3.1 — Form control system.** All seven states as one interlocking unit, because a control
that is compliant at rest but indistinguishable on hover, or focusable with no visible focus,
is not usable. New `--border-input` `#848890`, deliberately **separate from `--border-strong`**
so form controls could be made compliant without silently restyling `.btn-secondary`,
`.btn-outline-secondary`, `.btn-filter` and `.badge-status--outline`, which share that token.
`#848890` rather than the `#8C9196` originally proposed in Option A: `#8C9196` passes on white
(3.18:1) but fails on the `--bg` fill a readonly control uses (2.94:1), so it would have been
compliant on some form controls and not others. Focus is carried by the **border colour
change**, not the ring — `--focus-ring` composites to `#E0E9FC` over white, 1.22:1, effectively
invisible, and raising the alpha does not rescue it. Placeholder moved off `--text-subtle`
(3.18:1, fails the 4.5:1 text floor) onto `--text-muted`, with `opacity: 1` because Firefox's
default placeholder opacity would otherwise drop it back under.

**3.2 — Confirmation dialogs, six sub-commits.** 52 of 53 native `confirm()` calls replaced;
`settings/reset_test_data.php` keeps its server-validated typed phrase and was never in scope.
The audit found **53 sites, not the 48 the design doc stated**, and that every one guards a
form submission — none guards a link — which made a single submit interceptor viable.

- *3.2a* framework only, zero call sites converted. Declarative `data-confirm` for the 49
  static guards; `window.ConfirmUI.confirm()` for the 4 that fire only on a runtime condition.
  Uses `requestSubmit()`, never `submit()` — the latter silently skips HTML5 validation, so a
  required field left empty would post anyway, a regression introduced by the very change meant
  to make things safer. Loaded from `footer.php` **after** the Bootstrap bundle, because the
  other app scripts load from `header.php` before it and a script reading `window.bootstrap` at
  parse time gets `undefined` — the ordering trap that disabled a modal for a release in V2.
- *3.2b/3.2c* 23 destructive sites (danger tone). Every one gained the record it acts on, which
  a native `confirm()` cannot show: "Delete this supplier order?" became
  *Delete supplier order PO-1023?*. Consequence text verified against the code rather than
  assumed — all five catalog deletes are guarded by `catalog_*_delete_if_unused()`, so the body
  says a record still assigned to products cannot be deleted.
- *3.2d* 10 reversible workflow sites (warning tone). This is the split the audit found missing:
  `supplier-orders/view.php` gave "Mark Arrived" and "Delete" identical native dialogs.
- *3.2e* 15 routine sites (neutral tone). Approvals, retries, automation, draft creation.
- *3.2f* the 4 programmatic sites. One — the shipping-allocation overwrite — turned out to have
  a **PHP-derived** condition and moved to the declarative path instead, which also removed the
  need to reimplement re-entry protection.

**3.3 — Table system.** Header on `--surface-sunken`; row hover set through
`--bs-table-bg-state` (the variable Bootstrap's own `.table-hover` rule reads — setting
`background` directly loses to its more specific selector); selected row via `:has()` with no
JS; tabular figures applied through the existing `.text-end` convention (221 cells, 44 files)
with no markup change. **Sticky headers deliberately not implemented** — `position: sticky`
needs a scrolling container with constrained height and no table in the application has one, so
it is a layout decision, not CSS polish.

**3.4 — Pagination.** 17 hand-rolled blocks → one `render_pagination()` in
`includes/pagination.php`. All 17 were compared and found **semantically identical** before
extraction, so behaviour is provably unchanged rather than assumed. Page-number links stayed
out: §2.13 specifies them for ≤7 pages, but adding them here would change what every list shows
in the same commit that consolidates markup.

**3.5 — Loading and pending states.** Five implementations found, three duplicating each other;
consolidated into `LoadingUI`. `supplier-order-form.js` gained the spinner and the preserved
width §2.13 specifies — it previously set `button.disabled = true` and nothing else. `drawer.js`
and the webhooks batch-progress region deliberately left alone: the first owns loading, error
*and* retry as one flow, the second is a genuine progress region rather than a duplicate.

**3.1a / 3.2g — two regressions found by browser QA, neither visible to the smoke verifier.**

The hover rule carried `:not(:disabled):not([readonly])` guards, making it `(0,4,0)` against
`.form-control:focus` and `.form-control.is-invalid` at `(0,2,0)`. With the pointer resting on a
field — the normal state immediately after clicking into it — the focus ring and the invalid
border both fell back to the hover colour. Measured in Chrome: focus read `rgb(107,111,118)`
instead of `rgb(52,114,239)`. A WCAG 2.4.11 regression introduced by Phase 3.1 itself, where the
guards meant to protect disabled/readonly were the cause. Rewritten as an explicit precedence —
`disabled > readonly > invalid > focus > hover > resting` — enforced by source order at matched
specificity, with an extra qualifier only where two states genuinely coexist. Hover is now
unqualified so everything below outranks it by order alone; readonly and disabled cancel hover
explicitly rather than hover excluding them. No `!important`. No colour moved, so every contrast
figure holds unchanged.

Separately, **Bootstrap 5.3's `Modal.show()` unconditionally writes `role="dialog"`**, clobbering
the value set beforehand — so every destructive confirmation was announcing to a screen reader
as an ordinary dialog. The attribute now goes in the existing `shown.bs.modal` handler.

**Verification.** Every sub-phase ran `tools/smoke/smoke.php verify` against the preceding
snapshot: 0 breaking, 0 warnings, 0 informational, exit 0, on all eleven. The browser QA pass
went 57/5 → **67 passed, 0 failed**. Regression walkthrough across the eight highest-risk
modules: 12 pages, HTTP 200, exactly one `<h1>`, no PHP notices, empty JS console.

Two verification lessons are recorded in `docs/QA_PROCESS.md` rather than here, because both
recur: a smoke "PASS" carrying unexplained warnings is not a pass (twice it meant a contaminated
baseline, once a dataset difference), and a browser QA failure must have its cause confirmed
before it is treated as a defect — of the first five, two were artifacts of the harness's own
sequencing.

### SO-D.1 / SO-D.2 / P7a — Supplier order index, backorder visibility, edit protection

Three small independent changes to Supplier Orders. **No schema table or column added** (one index), and receiving, the inventory ledger, costing, `product_cost_history`, cancel, and delete are untouched throughout.

**SO-D.1 — `supplier_orders.order_date` index.** `order_date` is the leading sort key in every batch landed-cost calculation (`includes/product_cost.php`'s reference-line and purchase-cost lookups, which run on the Margin Report, Inventory Report, Purchasing and the cost-increase alert generator) and in SO-C's purchase-history views — and was the only one of those sort keys without an index. New `database/migrate_supplier_order_date_index.php` (idempotent), matching `schema.sql` declaration, System Health row. Registering it correctly required extending the detection priority to `unique_column` → `index` → `column` → `table`: `SYSTEM_HEALTH_INDEXES` hardcodes every index miss to `migrate_production_hardening.php`, so using it here would have told an operator to run the wrong script. Purely a lookup path — an index cannot change which rows a query returns.

**SO-D.2 — cross-order backorder visibility.** Per-line received/remaining already existed on `view.php`; what was missing was the cross-order answer, so "what am I still waiting on across every supplier" meant opening each PO in turn. New `supplier_orders_outstanding_batch()` (`includes/supplier_orders.php`) returns ordered/received/outstanding units for a whole page in **one** query, derived from the `inventory_transactions` ledger — the same authoritative source `supplier_order_item_received_quantity()` uses, aggregated set-based rather than per line, following the N+1 discipline already established by `supplier_order_blocked_customer_orders_batch()`. `modules/supplier-orders/index.php` gained a "Units" column showing `received / ordered` plus an outstanding badge, suppressed for cancelled and completed orders; over-receives clamp to zero rather than showing negative. Read-only.

**P7a — edit protection after receiving.** The P7 audit corrected an earlier mischaracterisation of this codebase: edit was *not* blocked once receiving existed. `supplier_order_has_receiving_history()` gates only cancel and delete; edit is gated by `status = 'completed'`. So a partially or fully received order that wasn't completed could have its supplier, currency, exchange rate and per-line unit cost changed freely. The problem was under-restriction.

`supplier_order_apply_edit()` now rejects, once any stock has been received: changing supplier, currency, or exchange rate; and changing unit cost on any line with received quantity > 0. This matters because since SO-A1 `unit_cost_myr` is the authoritative landed-cost input and `product_cost_history` froze a snapshot from it at receiving — so a currency edit would silently rewrite the recorded cost of stock already on the shelf and leave the frozen snapshot unreconcilable. Reassigning `supplier_id` would additionally orphan `product_cost_history.supplier_id` and misattribute delivery performance in `supplier_lead_time_stats_batch()`. Each rejection throws with a message explaining why, surfaced by `edit.php`'s existing handler.

UI mirrors the rule so a submission can't fail unexpectedly: `edit.php` renders supplier/currency/exchange-rate disabled with an explanation, each paired with a hidden field carrying the unchanged value (disabled inputs don't submit); `assets/js/supplier-order-form.js` renders a received line's cost input `readonly`, which does submit. `edit.php` also re-attaches `received_quantity` after a validation error, since the shared `supplier_order_validate_form()` doesn't carry it and the re-render would otherwise appear to unlock those fields — the server guard held regardless; this stops the UI misrepresenting it.

Unchanged and still permitted: notes, payment status, shipping fee, adding lines, increasing quantities, and editing or removing lines with nothing received. Completed-order and `is_historical` locks unchanged.

**Deferred — P7b (allow cancel after partial receiving).** `supplier_order_cancel()` reverses each line's **full** `total_quantity`, but receiving already decremented `incoming_quantity` by the received amount, so on a partially received order it double-decrements. `GREATEST(…, 0)` prevents a negative, but the `mewmii_inventory` row is shared across every open PO for that product, so the excess would silently consume **other orders'** incoming stock. The cancel guard is protecting that bug, not being conservative. Relaxing it requires reversing outstanding-only (`total_quantity − received`) in the same change — behavioural, and it needs its own approval and test pass.

**Verification:** `php -l` clean on `includes/supplier_orders.php`, `modules/supplier-orders/edit.php`, `database/migrate_supplier_order_date_index.php`, `includes/system_health.php`, `modules/settings/system_health.php`; `node --check` clean on `assets/js/supplier-order-form.js`. Index name confirmed identical across `schema.sql`, migration and System Health; detection priority verified to resolve all 30 registered checks with no legacy row reclassified; `SYSTEM_HEALTH_INDEXES` byte-unchanged; `product_cost.php`, `inventory.php`, `supplier-orders/view.php`, `supplier-orders/create.php`, and the cancel/delete/receiving functions all diff-confirmed untouched. **Runtime results have not been reported back for these three changes** — the migration has not been confirmed run, and the UI/guard test checklists remain outstanding.

### SO-C — Multi-supplier sourcing (`supplier_products`)

**Reason:** `products.supplier_id` is a single nullable FK, so a product could record exactly one supplier, one supplier SKU, and one cost. Multi-sourcing — the same item available from two suppliers at different prices, under different SKUs, in different currencies — could not be recorded at all, even though the PO form never actually restricted which products go on which supplier's order. The record was missing, not the capability.

**Audit finding that shaped the design:** `products.supplier_id` has exactly one semantic role — the *preferred* supplier for reordering. `includes/purchase_planning.php` tags each reorder need with it so `generate.php` can group needs into one draft PO per supplier. It is never a constraint. So it is kept, unchanged, and the new table holds alternatives alongside it.

**Schema (`database/schema.sql`, `database/migrate_supplier_products.php`):** one new table, `supplier_products` — `(product_id, variation_id, supplier_id)` with `supplier_sku`, `unit_cost`, `currency`, `exchange_rate`, `priority`, `moq`, `notes`, `is_active`. Uniqueness is enforced on `(product_id, variation_key, supplier_id)` using a `variation_key` generated column that collapses NULL to 0 — the same technique `mewmii_inventory` already uses, so simple products get the constraint too. The migration seeds one row per product that already has a supplier, copying its existing SKU/cost/currency/rate at priority 0: **INSERT-only, guarded by `NOT EXISTS`, and it never updates or deletes a `products`, `product_variations`, or `suppliers` row.**

**Three boundaries, deliberately:**

1. **`products.supplier_id` is untouched** and still means "preferred supplier". `priority` ranks alternatives for a human choosing where to buy; it never overrides that column. Purchase planning, the product form, and cost-configuration status all behave exactly as before.
2. **`unit_cost` is quotation data and never enters costing.** The chain stays as SO-A1/SO-A2 established it: actual PO line cost → landed cost → `product_cost_history`. This is enforced by absence — `product_cost.php`, `supplier_orders.php`, and `purchase_planning.php` contain zero references to `supplier_products`, verified by grep.
3. **Purchase history is derived, not stored.** `supplier_order_items` ⋈ `supplier_orders` already yields supplier, real unit cost (foreign and MYR), currency, and date per product/variation, so a history table would only duplicate existing data. `supplier_product_purchase_history()` and `supplier_product_supplier_summary()` query it directly, and deliberately work even for a supplier never added to the catalogue.

**New (`includes/supplier_products.php`):** list/get/validate/upsert/delete, `supplier_products_for_supplier()` (keyed `productId:variationId`), plus the two derived-history functions. Upsert works on the natural key, so re-adding a supplier updates rather than duplicates.

**New (`modules/products/suppliers.php`):** sourcing catalogue management with the quoted-price/costing boundary stated on the page itself, plus per-supplier purchase history and rollup. `modules/products/view.php` gained a link, an alternative-supplier count, and a "Preferred" badge. `supplier_products.moq` is recorded but **not wired into purchasing** — `products.moq` remains authoritative.

**Deferred:** surfacing supplier-specific SKU/price in the PO item picker. That requires changing `supplier_order_picker_products()`'s JSON payload and `assets/js/supplier-order-form.js`'s render logic — the PO-creation workflow, out of scope here. `supplier_products_for_supplier()` is written and tested for whenever it is picked up.

**Verification:** `php -l` clean on all 5 PHP files. Schema/migration parity confirmed definition-for-definition (23 each, differing only by a SQL comment). Runtime load test confirms all 8 helpers resolve. `purchase_planning.php`, `product_cost.php`, `inventory.php`, `purchase-planning/generate.php`, `supplier-orders/create.php`, `supplier-orders/edit.php`, and `supplier-order-form.js` diff-confirmed untouched. Production tests passed, including the cost-boundary check: changing a quoted `unit_cost` leaves Landed Cost, inventory valuation, and margins unmoved.

### SO-B — Supplier payments linked to bank accounts

**Reason:** `expenses` and `manual_income` both gained `bank_account_id` in Finance Phase B, but `supplier_order_payments` — the largest cash outflow in the business — did not, leaving supplier spend invisible to account-level reconciliation.

**Changed:** `database/migrate_supplier_order_payment_bank_account.php` (new, idempotent: additive nullable column + index + FK, all three guarded), `database/schema.sql`, `includes/system_health.php` (1 detection row), `includes/supplier_orders.php` (`supplier_order_add_payment()` gained an **optional trailing** `$bankAccountId` — 7 params, 6 required, so the existing call site is unaffected; `supplier_order_list_payments()` joins the account name), `modules/supplier-orders/view.php` (optional account picker on the payment form, account name in the payment list).

**Deliberate behaviour:** a `bank_account_id` that does not resolve is stored as NULL rather than raising an FK error. A mistyped account must never block recording a payment that actually happened — the payment is the fact, the tag is metadata.

**Scope held:** tagging only. No balance or reconciliation engine, no change to order totals, `payment_status`, or `supplier_order_paid_amount()` — Paid/Remaining are still derived live from `SUM(amount)`. `payment_method` retained as the lighter free-text fallback, exactly as on `expenses`. Nothing in receiving, the inventory ledger, costing, or `product_cost_history` reads this table.

**Verification:** `php -l` clean on all 4 files; reflection-confirmed the new parameter is optional and trailing; runtime load test confirmed no require cycle between `supplier_orders.php` and `finance.php`. Production tests passed, including confirmation that Paid/Remaining did not drift.

### SO-A2 — Variation-level landed cost for the Inventory Report

**Reason:** `modules/reports/inventory.php` values **sellable units** (a variable product contributes one unit per variation) but applied a single **product-level** landed cost to every variation of a product. A variation bought at a different price, or carrying its own shipping allocation, was valued at a blended figure that matched none of them. Two further defects were invisible at product grain: the costing engine never consulted `product_variations.cost_price` (D4), and margin used the parent's `selling_price` even for variations with `price_mode = 'custom'` (D5).

**Measured first (SO-A2A):** `cli/so_a2a_variation_cost_impact.php`, read-only, over 53 products / 155 sellable units (145 variations). **5 variations changed across 2 products, all increases, 0 became incomputable, simple products unchanged.** Affected variations rose 67–96% (14.40→24.00 on four Sanrio variations, 21.54→42.24 on a Tamagotchi variation), entirely from shipping/additional costs resolving per variation rather than per product.

**Added (`includes/product_cost.php`):** `product_cost_calculate_units_batch(PDO, array $units): array`, keyed `"productId:variationId"` (null variation → 0, the same convention `catalog_sellable_units()` and `mewmii_inventory.variation_key` already use). Unit-grain Lookup A (newest non-cancelled PO line for that exact product+variation — same rules as SO-A1, one grain finer) and unit-grain Lookup B (shipping + additional costs per product+variation). **No cross-variation inheritance:** a variation without its own allocation reports "not configured" rather than borrowing a sibling's, since attributing one variation's shipment costs to another is the exact error this removes. Master fallback goes through `variation_effective_cost()` (D4); selling price through `variation_effective_price()` + `catalog_product_effective_price()` (D5) — those helpers are **reused, never reimplemented**, keeping pricing rules in one place. `product_cost_build_breakdown()` is reused unchanged, so the Landed Cost formula still exists exactly once. A `require_once` for `product_variations.php` was added; no cycle (that file pulls `inventory.php`/`product_images.php`, neither of which pulls this one).

**Wired (`modules/reports/inventory.php`):** switched to the unit-grain call and the `productId:variationId` lookup key. Still one batched call — the N+1 avoidance from Phase 7G is intact. **`reports/margins.php` and `purchasing/index.php` deliberately remain on `product_cost_calculate_batch()`** — both roll stock up per product, so product grain is correct for them.

**Unchanged:** `product_cost_calculate_batch()` (signature, `product_id =>` keying, all return keys), `product_cost_history_capture()`, `notifications.php`, supplier order workflow, inventory ledger. **No database migration.**

**Three caveats worth recording:**

1. **Inventory valuation moved RM 0.00 — and that is not evidence the two methods agree.** Valuation is `on_hand × landed_cost`, and all 5 affected variations hold **zero stock**; catalogue-wide stock is near zero (only 10 of 155 units carry a shipping allocation). The change was barely exercised. **Expect Inventory Value to move by the 67–96% shown above when any affected variation is restocked** — that is the fix working.
2. **D4 is implemented but currently dormant.** No variation in the catalogue has its own `cost_price` set, so all 138 fallback units resolve to the parent's `product_cost`. It takes effect the first time a variation-level cost is entered.
3. **The −1.24 pp average margin change measured by SO-A2A is not surfaced anywhere.** It comes from D5 across 62 units with custom selling prices; `inventory.php` reads only `landed_cost` and `is_estimated`, and `margins.php` stays product-level.

**Verification:** `php -l` clean on both changed files. 13 DB-free logic tests pass, including the R2 double-conversion guard (¥1000 @ 0.031 stays RM100 on the PO path, converts to RM31 on the master path), both D4 fallback branches, and D5 custom-vs-inherit pricing. Production run: **SO-A0 re-run reported products changed 0 of 136**, proving the new function and its `require_once` left the product-level path untouched; SO-A2A reported became-incomputable 0 and simple-product diff 0.

**Not in scope, not begun:** SO-A2.3 (per-variation Cost Breakdown on `products/view.php`) and SO-A2.4 (D6 — `product_cost_history_list_batch()` keys by `product_id` and ignores `variation_id`, so a variable product's "latest snapshot" can belong to an unrelated variation; it touches `notifications.php` and needs separate approval).

### SO-A1 — Landed cost now uses the actual purchase price, not the product master cost

**Reason:** `product_cost_build_breakdown()` computed Converted Supplier Cost from `products.product_cost` — the master record — while sourcing Shipping Allocation and Additional Costs from the real supplier order line. The price actually negotiated and paid (`supplier_order_items.unit_cost_myr` / `supplier_price`) was never read by the costing engine at all, so every landed cost, margin figure, and inventory valuation reflected a maintained field rather than reality.

**Measured before changing anything (SO-A0):** a read-only impact audit over 136 products found **15 changed, all 15 decreases, 0 increases**, **0 products became incomputable**, and **0 newly triggered cost-increase notifications**. Master costs had been systematically *above* real purchase prices, meaning **margins were understated, not overstated**. The zero-increase result is what removed the notification-flood risk entirely — `includes/notifications.php` fires only on increases and has no percentage threshold, so a deploy full of decreases produces no alerts. 8 of the 15 were products with real purchase history but no shipping allocation, which the old reference-line predicate could not see at all.

**Changed — `includes/product_cost.php` only:**

- **"Lookup A"** in `product_cost_calculate_batch()`: one additional batched query resolving each product's most recent **non-cancelled** supplier order line. Priority `unit_cost_myr > 0` → `supplier_price > 0` → none. `is_historical` orders **included** (imported orders carry real prices; excluding them would strand imported catalogue on master cost). `0.00` counts as "not set", matching `variation_effective_cost()`'s existing precedent. Deliberately has **no** `shipping_allocated IS NOT NULL OR has additional costs` predicate — that filter is exactly what hid the 8 products above. Newest line only: a product whose newest line has no usable cost falls through to master cost rather than scanning older lines, which matches the SO-A0 measurement exactly and is what makes re-running that audit a valid acceptance check.
- **"Lookup B"** — the pre-existing reference-line query supplying Shipping Allocation and Additional Costs — is left **byte-identical**, so its documented "always from ONE line, never mixed across shipments" invariant is provably untouched. Base cost and shipping may now come from different lines; that is not a regression, since base cost previously came from an entirely different source (the master record).
- **`product_cost_build_breakdown()`** gained two optional trailing parameters: `?float $purchaseCostMyr` and `?string $costSourceLabel`. Both default to `null` = exact pre-SO-A1 behaviour. When a purchase cost is supplied it replaces the supplier cost and **suppresses currency conversion** by blanking `$costCurrency`/`$exchangeRate`, so the existing conversion branch resolves to its "already base currency" case. This reuses the one place conversion is decided rather than adding a parallel path, and is the guard against double-converting an already-MYR figure — the failure mode that would have turned a ¥ order at rate 0.031 into ~3% of its true cost.
- **`cost_source`** added to the returned breakdown: `po_unit_cost_myr` | `po_supplier_price` | `product_master`. **Metadata only** — nothing branches on it; it exists for diagnostics, the Cost Breakdown card, and post-deploy verification.

**Deliberately NOT changed:** `product_cost_calculate_batch()`'s signature and `product_id => breakdown` return keying (all 8 call sites untouched); every pre-existing return key's name, type, and meaning; `is_estimated` semantics (an earlier plan proposed extending it to flag master-cost fallback — reversed after SO-A0 showed ~121 products would newly flag as "estimated" across 11 read sites, turning a quiet correctness fix into a catalogue-wide badge change; `cost_source` carries that information without the churn); `notifications.php`; `product_cost_history_capture()`; variation-level costing (SO-A2); supplier order workflow; inventory ledger; schema; migrations. **No database migration required.**

**Expected visible effects:** Margin Report gross profit and margin % **rise** for the 15 affected products; inventory valuation (`reports/inventory.php`) **falls**, since stock is valued at landed cost. Both are corrections. Frozen `product_cost_history` snapshots are unaffected — that table is INSERT-only and its capture function was not touched, so past captures keep exactly the figures they recorded, and any report comparing frozen-vs-live will show a one-time step at this deploy.

**Verification — implementation-side (static):** `product_cost_calculate_batch()`/`product_cost_calculate()` signatures unmodified; all 15 pre-existing return keys still present with no renames; `is_estimated` computation untouched (diff-confirmed); no write statement of any kind added; `product_cost_history_capture()` not modified; brace/paren/bracket balance identical to HEAD.

**Verification — production (maintainer-run, PASSED):** `php -l includes/product_cost.php` clean. **Acceptance gate passed** — `cli/so_a0_landed_cost_impact.php` re-run against the real database after implementation reported **products changed: 0**, newly computable 0, became incomputable 0, notification spike 0. Because that audit independently recomputes the proposed figures and compares them against what `product_cost_calculate_batch()` now actually returns, a zero result proves the shipped code produces exactly the numbers measured and approved in SO-A0 — no drift between plan, measurement, and implementation, and no unintended movement anywhere in the catalogue. SO-A1 is closed.

### Supplier Order migrations registered in System Health (unique-constraint detection)

**Reason:** the Supplier Orders audit confirmed that neither `migrate_supplier_order_currency.php` nor `migrate_supplier_order_purchase_number_unique.php` was registered in `SYSTEM_HEALTH_MIGRATIONS` — the two remaining `supplier_order*` entries in `docs/MIGRATION_SYSTEM_AUDIT.md`'s long-standing untracked set, one of which is the script behind the original incident that prompted that audit. A production verification (read-only `INFORMATION_SCHEMA` query, run by the maintainer) confirmed the currency migration **had** already been applied and the schema is correct — but no tool inside Mewmii OS could answer that question, which is the gap this change closes.

**Detection extension (`includes/system_health.php`):** `migrate_supplier_order_purchase_number_unique.php` adds **only a UNIQUE index** — no table, no column — which the existing array could not express (it supported column-exists or table-exists only). Added:

- `system_health_unique_index_exists(PDO, string $table, string $column): bool` — matches by `COLUMN_NAME + NON_UNIQUE = 0`, **never by `INDEX_NAME`**. This is the crux of the change: the same logical constraint has different names depending on how the database was built. A migrated database names it `idx_supplier_orders_purchase_number_unique`; a fresh `database/schema.sql` install gets MySQL's auto-generated `purchase_number` from the inline `VARCHAR(100) NOT NULL UNIQUE`. Both are fully migrated, so a name-based check would raise a false "pending migration" alarm on every fresh install. Query shape mirrors the migration's own idempotency guard.
- A `unique_column` → `column` → `table` detection priority in `system_health_check()`. `unique_column` is an **optional** key; all 24 pre-existing rows omit it and resolve exactly as before, so this adds a case rather than altering one.

**Why not `SYSTEM_HEALTH_INDEXES`:** that set is hardcoded to attribute every missing index to `migrate_production_hardening.php`, so it would have named the wrong script, and it matches on `INDEX_NAME`, which is unstable here. Its behaviour is entirely unchanged by this commit.

**Registered (3 rows):**
- `migrate_supplier_order_currency.php` → `supplier_orders.foreign_total` and `supplier_order_items.unit_cost_myr`. Two rows because the migration alters two tables and one sentinel cannot span both. Each deliberately checks the **last** column added to its table, not the first: the `ALTER`s run in order, so a present `foreign_total`/`unit_cost_myr` implies the earlier columns landed. Sentinelling on `currency` would report green even when `exchange_rate`/`foreign_total` had failed — exactly the partial state that breaks supplier order creation.
- `migrate_supplier_order_purchase_number_unique.php` → `supplier_orders.purchase_number` via the new `unique_column` check.

**Page change (`modules/settings/system_health.php`):** the artifact descriptor branches on `column`, so the new row would have rendered as "supplier_orders table" — implying a table-existence check. Its descriptor now mirrors the same three-way priority and shows "supplier_orders.purchase_number unique". One `echo` expression; no other page logic touched. *(This was missed in the approved plan, which stated no page change was needed.)*

**Coverage:** 22 of 24 migration scripts tracked (was 20). `migrate_sync_logs_index.php` and `migrate_webhooks.php` remain untracked — out of scope here, still recorded in `docs/MIGRATION_SYSTEM_AUDIT.md`.

**Explicitly NOT changed:** no migration was executed by this change; no migration script, `database/migrate.php`, `database/schema.sql`, or any supplier-order code was touched. This is read-only detection only — `schema_migrations` gains no rows from it.

**Testing:** static verification — no PHP runtime or database is available in this environment, so the page was not loaded and `php -l` was not run. Verified by tooling: all 27 registered checks parsed and resolved through a simulation of `system_health_check()`'s exact priority (1 unique, 15 column, 11 table); zero pre-existing rows resolve differently than before; zero rows fail to resolve; every tracked migration name matches a real file on disk. The negative tests (dropping each artifact and confirming the correct script is named) require a throwaway database and **have not been run** — see the testing checklist in `docs/IMPLEMENTATION_STATUS.md`.

### Bug fix — `app_forbidden()` was called but never defined

**Reason:** `modules/finance/bank_accounts.php:23` and `modules/finance/manual_income.php:32` (both Phase B) were written against an `app_forbidden()` helper that does not exist anywhere in the codebase. A `finance.view`-only user submitting a POST to either page hit a PHP fatal "undefined function" error — a 500 plus an error-log entry — instead of the intended 403 message. It **failed closed** (the fatal aborts the request before any write), so no unauthorised change was ever possible; this was an error-handling defect, not a privilege-escalation hole. Found during Phase C implementation, when the same pattern was copied into `asset_view.php` and the missing symbol surfaced during a function-resolution cross-check.

**Fix (`includes/bootstrap.php`):** defined `app_forbidden(string $message = 'Access denied.')` immediately after `app_require_permission()`, matching that function's exact response shape (`http_response_code(403)` → `alert alert-danger` div → `exit`), with the caller-supplied message passed through `app_escape()`. **Neither call site was changed** — the fix supplies the helper the existing code already assumed, so the two Finance pages keep their specific, more useful messages and their behaviour is otherwise untouched.

**Why define the helper rather than rewrite the two call sites:** the two functions are complementary, not duplicates. `app_require_permission($name)` decides for itself by permission name; `app_forbidden($message)` is an unconditional stop for a denial the caller has already determined (e.g. a `$canManage` flag computed once and reused across branches). Defining it also places the helper where its siblings already live — `includes/bootstrap.php` holds the page-context auth helpers, `includes/ajax_helpers.php` holds the AJAX equivalents (`ajax_require_permission`/`ajax_require_permission_html`) — following the same split rather than introducing a new one. It deliberately does **not** call `app_require_login()`, since re-checking login would change what the existing call sites do.

**Scope:** one function added to one file. No permission architecture changed, no Phase C file touched, no call site edited. Note `modules/finance/asset_view.php` continues to use `app_require_permission('finance.manage')` for its POST guard — that is correct for its case (it re-checks the permission rather than trusting an earlier-computed flag) and was deliberately left alone.

**Testing:** verified by inspection and tooling — exactly one definition now exists, both call sites resolve to it, both pass one string argument matching the signature, both files load `bootstrap.php`, no name collision existed beforehand, and brace/paren balance is unchanged. **No PHP runtime or database is available in this environment, so the 403 path was not exercised at runtime** — the recommended check is a `finance.view`-only session POSTing to Bank Accounts, which should now render "You do not have permission to manage bank accounts." with HTTP 403 instead of a 500.

### Finance & Accounting Phase C — Asset Management

**Reason:** Phase C of the approved Finance roadmap (`docs/FINANCE_ACCOUNTING_ARCHITECTURE.md` §16). An operational asset register — what the business owns, where it is, who holds it. Deliberately **not** an accounting module: no depreciation, no capital allowance, no balance sheet, no ledger entries, no maintenance system, per explicit scope decision. This also gives `TAX_REPORTING_DESIGN.md` §3's Asset Register report the data source it was previously missing.

**Schema (`database/schema.sql`, `database/migrate_finance_phase_c.php`):** two new tables, purely additive — no `ALTER` against any existing table, so unlike Phase A no data-safety guard was needed (there was no legacy `assets` scaffolding to protect).
- `assets` — `asset_code` (optional, user-entered, `UNIQUE`), `name`, `category`, `supplier_id`, `bank_account_id`, `assigned_to`, `location`, `purchase_date`, `purchase_amount`, `currency`, `exchange_rate`, `warranty_expiry`, `description`, `notes`, `status`, `disposal_date`, `created_by`, timestamps. All four FKs `ON DELETE SET NULL`, so removing a supplier/account/user never destroys an asset record. Five indexes mirroring the `expenses` set.
- `asset_attachments` — structurally identical to `expense_attachments`, `ON DELETE CASCADE` from its asset.

Three design points worth recording: **`asset_code` is `NULL UNIQUE`** — MariaDB does not treat NULLs as equal, so any number of assets may have no code while no two may share a non-NULL one, which is exactly the "optional but unique when present" semantic wanted. There is **no numbering engine and no auto-generation**, by explicit decision, to avoid committing to ERP numbering rules before they're needed. **`assigned_to` is the custodian**, not legal ownership — every UI label says "Assigned To" for that reason. **Only two statuses** (`in_use`, `disposed`); `sold` is deliberately absent until an Asset Sale accounting flow exists to give it meaning.

**Migration:** idempotent via `migrate_table_exists()`. Depends on Phase B (`bank_accounts` FK target); run order is handled automatically by `database/migrate.php`'s glob+sort discovery (`_phase_a` → `_phase_b` → `_phase_c`). Run standalone against a database missing Phase B, the `CREATE TABLE` fails on the missing FK target, is recorded as a failure, and nothing is left half-built.

**System Health (`includes/system_health.php`):** 2 rows registered in `SYSTEM_HEALTH_MIGRATIONS` **in the same change as the migration itself** — plain table-existence checks per table (unambiguous here, with no pre-existing scaffolding to cause the false positive Phase A had to work around). Coverage is now 20 of 24 scripts. *(Corrected 2026-08-04: this entry originally said "21 of 24" — an arithmetic slip. 24 scripts existed with 4 untracked — `migrate_sync_logs_index.php`, `migrate_webhooks.php`, `migrate_supplier_order_currency.php`, `migrate_supplier_order_purchase_number_unique.php` — so the correct figure was 20.)*

**Business logic (`includes/finance.php`, appended — no existing function modified):** `ASSET_STATUSES`/`ASSET_CATEGORIES` constants (a PHP list, not a lookup table, matching how `MANUAL_INCOME_CATEGORIES`/`BANK_ACCOUNT_TYPES` already handle small closed sets in the same file), `asset_status_labels()`, `asset_code_normalise()` (trim → uppercase → empty-to-NULL), `asset_code_in_use()`, `asset_validate_form()` (shared by create and edit), `asset_create()`, `asset_update()`, `asset_get()`, `assets_list()` (owns its own WHERE clause and returns its own totals, so count and rows can never be filtered differently), `asset_dispose()`, and three `asset_attachment_*` functions reusing `includes/receipt_storage.php` unchanged.

`status` and `disposal_date` are **not settable from the create or edit forms at all** — disposal is its own separate action, so no POST to those forms can alter an asset's lifecycle state. `asset_dispose()` re-checks `status = 'in_use'` inside the `UPDATE` itself, so a replayed or forged POST cannot overwrite an existing disposal date.

**Module (`modules/finance/`, new):** `assets.php` (list, filter by search/category/status/location/date range, paginated, totals), `asset_create.php`, `asset_edit.php`, `asset_view.php` (details, documents, disposal), `asset_attachment_download.php` (permission check → activity log → `receipt_storage_stream()`, same pattern as the expense receipt download). Nav link added to `includes/header.php`, gated on `finance.view`.

**Permissions:** none added — reuses `finance.view`/`finance.manage`, so `install.php` is untouched.

**Bug found during implementation (pre-existing, in Phase B — NOT introduced here and NOT fixed here):** `modules/finance/bank_accounts.php:23` and `modules/finance/manual_income.php:32` both call **`app_forbidden()`, which does not exist anywhere in the codebase.** A `finance.view`-only user who POSTs to either page hits a PHP fatal error instead of a clean 403. It fails *closed* (no write occurs), so this is a correctness/error-handling defect rather than a privilege-escalation hole, but it is real and would surface as a 500 with an error-log entry. It escaped the Phase B readiness audit because that audit verified a permission check was *present* without verifying the function it calls *exists*. Phase C's `asset_view.php` initially copied the same pattern and was corrected to `app_require_permission('finance.manage')` (which really exists, emits 403, and exits). **The two Phase B files are left untouched pending a separate go-ahead**, per the standing no-unrelated-changes rule.

**Verification:** implemented and statically reviewed in an environment with no PHP runtime or database (so `php -l` was never run here), then deployed and verified by the maintainer. Static review confirmed: every `asset_*` function called by a module is defined in `includes/finance.php`; every `app_*`/`receipt_*` helper resolves to a real definition (this is how the `app_forbidden()` defect above was found); brace/paren/`<?php`-tag balance checked programmatically; and the `assets`/`asset_attachments` DDL in `database/schema.sql` confirmed **definition-for-definition identical** to the migration's (30 and 10 definitions respectively) by automated comparison. Maintainer-side verification confirmed: migration applied successfully, both tables created, Assets pages reachable, no runtime errors in basic use.

**Deployment issue found and resolved (not a Phase C defect):** after the migration ran, the entire Finance menu was invisible and all Finance pages returned "Access denied" — including Phase A/B pages, which is what identified it as pre-existing rather than Phase C. Root cause: **database structure and permission seeding are two unconnected mechanisms.** Migrations create tables; `install.php` (lines 49-69) is the *only* code anywhere that inserts into `permissions`/`role_permissions`, and no migration touches permissions. On a database where `install.php` had not been re-run since `finance.view`/`finance.manage` were added to its canonical list, those rows never existed, so `app_has_permission('finance.view')` was false — and because `includes/header.php:653` wraps the whole Finance section (label included) in that single check, it disappeared silently rather than rendering as locked. Resolved by inserting the two permission rows and their Owner links directly, without re-running `schema.sql`. The underlying architectural gap is recorded in `docs/FUTURE_BACKLOG.md` for a future, separately-approved fix.

### Finance migrations registered in System Health (production migration visibility)

**Reason:** the Finance Phase B readiness audit found that neither `migrate_finance_phase_a.php` nor `migrate_finance_phase_b.php` was registered in `includes/system_health.php`'s `SYSTEM_HEALTH_MIGRATIONS` array — so Settings → System Health would report a clean bill of health on a production database that was missing every Finance table. This is the exact drift failure mode `docs/MIGRATION_SYSTEM_AUDIT.md` §1 documents (a hand-maintained array that must be remembered for each new migration, and wasn't — previously for 4 of 21 scripts, now caught for 2 more before it caused an incident rather than after).

**Changed (`includes/system_health.php`):** 4 rows added to the `SYSTEM_HEALTH_MIGRATIONS` constant. **Data only — no change to detection logic, execution logic, the migration runner, or the System Health page itself** (the page renders the array generically, so it picked the new rows up with zero page changes).

- **Phase A** → checks `expenses.category_id`, **not** the `expenses` table. This is the one non-obvious choice in the change and is deliberate: a pre-Phase-A database already had an `expenses` table (the dead scaffolding with free-text `category VARCHAR(100)`), so a table-existence check would have reported a false "applied" on precisely the databases that still need the migration. `category_id` exists only on the rebuilt shape. It doubles as the signal for the migration's own data-safety guard, which withholds exactly this artifact if it refuses to rebuild a non-empty legacy table.
- **Phase B** → three rows (`bank_accounts` table, `expenses.bank_account_id` column, `manual_income` table), following the existing two-row `migrate_pricing_engine.php` precedent. Justified because this migration spans a new table, an `ALTER` to a pre-existing table, and a second new table, and `migrate_run()` records per-statement failures — so partial application is genuinely reachable, and each artifact breaks a different part of the app if absent. All three collapse to one entry in the page's "pending migrations" list via the existing `array_unique()`.

**Coverage:** System Health now tracks **19 of 23** migration scripts (was 17 of 21). The 4 long-standing untracked scripts (`migrate_sync_logs_index.php`, `migrate_webhooks.php`, `migrate_supplier_order_currency.php`, `migrate_supplier_order_purchase_number_unique.php`) are unchanged and still untracked — out of scope for this change, still tracked as open in `docs/MIGRATION_SYSTEM_AUDIT.md`.

**Explicitly NOT claimed by this change:** that the Finance migrations have been run against production. This change only makes that question *answerable* from the System Health page; nobody has yet run the check, because no session so far has had live database access. `docs/IMPLEMENTATION_STATUS.md` continues to carry the production status as **unverified**, deliberately.

**Testing:** static verification only — no PHP runtime or database is available in this environment, so `php -l` could not be run and the page could not be loaded. The change is 4 entries in an array literal that the page already iterates generically; correctness of the *chosen artifacts* was verified by reading `database/migrate_finance_phase_a.php`, `database/migrate_finance_phase_b.php`, and `database/schema.sql` against each other. Stating this plainly rather than implying it was exercised.

### Finance & Accounting — documentation reconciliation (Phase B implementation found already complete)

**Reason:** a Phase B architecture-audit task (per the standing audit-before-changes process) found that `docs/IMPLEMENTATION_STATUS.md` and this changelog described Phase B (Bank Accounts, Manual Income, `bank_account_id` integration) as "Not Started," while the actual codebase already contains a complete, working implementation of it, matching `docs/FINANCE_ACCOUNTING_ARCHITECTURE.md` §16 and `docs/FINANCE_INTEGRATION_PLAN.md` §3's approved scope. This entry documents that implementation for the record; no application code or schema was changed in this pass — documentation only.

**Schema (`database/schema.sql`, `database/migrate_finance_phase_b.php`):** `bank_accounts` (reference list — `name`, `account_type` [`bank`/`cash`/`e-wallet`], `currency`, `notes`, `is_active`; not a statement-import/reconciliation system, per explicit design constraint), `expenses.bank_account_id` (nullable FK to `bank_accounts`, added via `ALTER TABLE` + index + FK, `ON DELETE SET NULL`), `manual_income` (`income_date`, `description`, `amount`, `currency`, `exchange_rate`, `category` [`Asset Sale`/`Grant`/`Other`], `bank_account_id`, `reference_number`, `created_by`) — deliberately narrow, never a mirror of `mewmii_orders` revenue. `database/migrate_finance_phase_b.php` is additive/idempotent (`migrate_table_exists()`/`migrate_column_exists()`/FK-existence guards throughout), matching the same defensive pattern as Phase A's migration.

**Business logic (`includes/finance.php`):** `bank_account_get()`/`bank_accounts_list()`/`bank_account_validate_form()`/`bank_account_create()`/`bank_account_update()`/`bank_account_set_active()`; `manual_income_validate_form()`/`manual_income_create()`/`manual_income_update()`/`manual_income_get()`/`manual_income_list()` (search/category/date-range filtering). `expense_validate_form()`/`expense_create()`/`expense_update()`/`expense_get()` extended to carry `bank_account_id` end to end. Every mutation logs to `activity_logs` via the existing `activity_log()` function (`bank_account_created`/`bank_account_updated`/`bank_account_status_changed`, `manual_income_created`/`manual_income_updated`), matching Phase A's convention exactly.

**Module (`modules/finance/`):** `bank_accounts.php` (list, create/edit, activate/deactivate toggle) and `manual_income.php` (list with filters, create/edit) added; `create.php`/`edit.php`/`view.php` extended with the Bank Account field. `includes/header.php`'s Finance nav section extended with Manual Income and Bank Accounts links, gated on `finance.view`/`finance.manage` exactly like Phase A's Expenses/Categories links.

**Permissions:** no new permissions — reuses Phase A's `finance.view`/`finance.manage` pair, matching the one-view/manage-pair-per-module convention.

**Documentation corrected in this pass:** `docs/IMPLEMENTATION_STATUS.md` (Finance table row added, phase-roadmap table's Phase B row changed from "Not Started" to "Completed," Migration Management System section notes the unverified production migration status). No changes were needed to `docs/FINANCE_ACCOUNTING_ARCHITECTURE.md`, `docs/FINANCE_DATABASE_DESIGN.md`, or `docs/FINANCE_INTEGRATION_PLAN.md` — all three already described Phase B's design accurately and consistently with what's actually implemented; the drift was isolated to the two status-tracking documents.

**Verification performed in this pass:** code-level read-only inspection only (schema, migration script, business logic, module files, nav wiring, permission gating) — no database connection was available in this session to query `INFORMATION_SCHEMA` directly, so whether `migrate_finance_phase_a.php`/`migrate_finance_phase_b.php` have actually been run against production remains unconfirmed (see `docs/IMPLEMENTATION_STATUS.md`'s Migration Management System section, new row). Also found: neither Finance migration is registered in `includes/system_health.php`'s `SYSTEM_HEALTH_MIGRATIONS` detection array, so the System Health page itself cannot currently answer this question either — flagged as a gap, not fixed (would be an application-code change, out of scope for this documentation-only pass).

**Not done in this pass, per explicit scope:** no application code or database schema changed; Phase C (Assets) not started or designed further.

### Finance & Accounting Phase A — Expense Categories, Expenses, Receipt Attachments, permissions

**Reason:** first implementation phase of the approved Finance & Accounting design (`docs/FINANCE_ACCOUNTING_ARCHITECTURE.md` §16's roadmap). Per the standing process, this follows the full audit → design → approval cycle already completed in prior entries — this entry is the implement → test → document half.

**Schema (`database/schema.sql`, `database/migrate_finance_phase_a.php`):** three new tables — `expense_categories` (one level of self-referencing nesting), `expenses` (replaces the pre-existing dead scaffolding table — confirmed zero application code depended on it), `expense_attachments`. `expenses.status` implements the `draft → paid → archived` lifecycle (`FINANCE_ACCOUNTING_ARCHITECTURE.md` §15) — every new expense starts at `draft`, a real recorded cost from the moment it's saved. `bank_account_id` deliberately **not** included yet — reserved for Phase B once `bank_accounts` exists, per the user's own phase-ordering rationale. The migration is defensive by design: it only drops/rebuilds the legacy `expenses` table if it's confirmed empty; if it somehow already has rows, it refuses and reports a failure instead of ever destroying data.

**Permissions (`install.php`):** two new entries, `finance.view`/`finance.manage`, added via the existing idempotent permission-sync loop — no new pattern. Also added: idempotent seeding of the default `expense_categories` hierarchy (Operations → Packaging/Shipping/Warehouse/Equipment/Office Supplies; Technology → Software/Hosting/Subscriptions; Finance → Bank Charges/Payment Gateway Fees; plus Marketing/Professional Services/Utilities/Travel/Miscellaneous as flat top-level categories) — looked up by name before insert, exactly like the permission sync it sits next to, safe to re-run on every deploy.

**Business logic (`includes/finance.php`, new):** `expense_categories_flat()`, `expense_validate_form()` (one validator shared by `create.php`/`edit.php`, same reuse discipline as `supplier_order_validate_form()`), `expense_create()`/`expense_update()`/`expense_get()`, `expense_set_status()` (the lifecycle transition), `expense_attachment_store()`/`expense_attachments_list()`/`expense_attachment_get()`. Receipt storage reuses `includes/receipt_storage.php`'s existing `receipt_upload_validate_and_store()`/`receipt_storage_stream()` **unchanged** — no parallel upload pipeline was built. Every write logs to `activity_logs` via the existing `activity_log()` function, matching the convention already used by Supplier Orders/Inventory.

**Module (`modules/finance/`, new):** `index.php` (list, filter by category/status/date range, search), `create.php`, `edit.php`, `view.php` (details, receipt gallery, status-transition buttons), `categories.php` (add categories, toggle active/inactive), `receipt_download.php` (streams an attachment, same permission-check-then-stream pattern as `modules/orders/receipt_download.php`). A new "Finance" sidebar section (`includes/header.php`) with Expenses/Categories links, gated on `finance.view`/`finance.manage` respectively.

**Testing performed:** a throwaway local database was created and torn down after use (three separate throwaway databases, in fact — one for the primary fresh-install/UI test, one simulating a pre-Phase-A production database to test the migration's upgrade path, one to test the migration's data-safety guard); production `.env`/`config.php` were never touched. `php -l` on every new/changed file. Verified: fresh `schema.sql` creates the new `expenses` shape directly; the migration correctly upgrades a simulated old database (idempotent — a second run applies zero statements); the migration's safety guard correctly refuses to drop `expenses` when it already contains a row, leaving the data untouched and reporting a clear failure. Drove the real UI end-to-end over HTTP: created an expense with a real receipt image attached (byte-for-byte verified round-trip on download), edited it, walked it through the full Draft → Paid → Archived lifecycle (each transition logged to `activity_logs`), added a new category, and confirmed form validation rejects an empty description and a non-positive amount. Verified permission enforcement at three tiers with real seeded roles: a zero-permission user gets 403 on the whole module; a `finance.view`-only user can see expenses but every manage-only control (Add Expense, Manage Categories, Edit, Mark Paid, Archive) is correctly absent from the rendered page, and `categories.php` itself 403s for that role.

**Bug found during testing (test tooling, not application code):** the test environment's `curl` build failed silently (exit 26, "Failed to open/read local data") when a multipart file upload's `-F` value included an explicit `;type=` MIME override alongside other `-F` fields — dropping the override (letting curl infer the type from the file extension) resolved it. Noted here since it cost real debugging time; not a Mewmii OS issue.

**Explicitly not done in this phase:** Bank Accounts, Manual Income, Assets, Supplier Payments rollup, Cost Classifications, Profit & Loss, Cash Flow, Budget Planning, Tax Reports — all later lettered phases, per the roadmap. Phase B does not begin until this phase has been reviewed and approved.

### Finance & Accounting — roadmap revision, lifecycle model, Expense Templates (design only, no code yet)

**Reason:** the Finance architecture and Budget Planning/Shipping Classification extension were both approved; before implementation, the user requested one roadmap change (Bank Accounts moved ahead of Assets, so every Expense can reference a real bank account from the start rather than retrofitting the relationship after real data exists) plus two more design pieces: a documented lifecycle/state model for financial records, and a reserved-shape design for future Expense Templates.

**`docs/FINANCE_ACCOUNTING_ARCHITECTURE.md`:**
- New §15, Financial Lifecycle — Expenses simplified to **Draft → Paid → Archived** (collapsing an earlier two-state unpaid/pending distinction into one "Draft" state, since the distinction added no real behavioral difference); Supplier Payments' Outstanding/Partial/Paid stated explicitly as a *derived* label computed from existing `supplier_order_payments` data, never a new stored column; Assets' existing `in_use`/`disposed`/`sold` restated for completeness; and an explicit list of what deliberately has no lifecycle (Budgets, Bank Accounts, Manual Income, Attachments, Categories) so the omissions read as decisions, not gaps.
- New §16, Implementation Roadmap — now persisted in the docs rather than only stated in chat. Revised order: **A** Expense Categories/Expenses/Receipt Attachments/permissions, **B** Bank Accounts/Manual Income/`bank_account_id` integration (moved ahead of Assets), **C** Assets, **D** Supplier Payments Rollup/Cost Classifications/P&L/Cash Flow, **E** Budget Planning/Dashboard widgets, **F** Tax Reports/Receipt Export/Annual Reports.

**`docs/FINANCE_DATABASE_DESIGN.md`:** `expenses.status` updated to `draft | paid | archived`; new §6.1, Expense Templates — reserved shape only (`expense_templates` table sketch), explicitly distinguished from Recurring Expenses (time-based vs. reuse-based) and explicitly out of every lettered phase.

**`docs/FINANCE_WORKFLOW.md`:** expense entry workflow (§1) updated — Status is no longer a field the user sets at entry; every new expense starts at Draft automatically.

**Still design only** — this entry, no code. Per the user's explicit instruction, Phase A implementation begins immediately after this documentation update; see the next changelog entry for that work.

### Finance & Accounting — design extension: Budget Planning, Shipping Classification, expanded categories/receipts/assets/bank accounts (no code, no migrations, no database changes)

**Reason:** the initial Finance & Accounting design was approved with its core principle reaffirmed — Finance is an integration layer, not a duplicate business system — and one additional capability requested before implementation: Budget Planning, plus a formal shipping-cost classification analysis and several targeted expansions to the already-approved design. This entry is documentation only.

**`docs/FINANCE_ACCOUNTING_ARCHITECTURE.md`:**
- New §8, Shipping Cost Classification — International Freight and Supplier Shipping Charges recommended as COGS (confirming existing `product_cost.php`/`supplier_order_item_costs` behavior), Customs as COGS, Import Tax as **configurable, defaulting to COGS** (the one genuinely ambiguous item — depends on the business's SST-registration status, which this system can't know on its own), Local Courier to Customer as Operating Expense (resolves the open question from the first design pass). The classification mechanism itself (`finance_cost_classifications`, a small lookup table) is designed to be settings-editable, not hardcoded into P&L logic.
- New §9, Budget Planning — one row per category per month holding only the *plan*; actual spending is always computed live from `expenses`, never duplicated. Dashboard integration (Budget Used %, Remaining Budget, Categories Over Budget, Monthly Trend) designed against the existing `DASHBOARD_PHILOSOPHY.md` rules, not a new dashboard pattern.
- New §10, Expense Category Structure — the recommended two-level default (Operations/Marketing/Technology/Professional Services/Finance/Utilities/Travel/Miscellaneous, with Operations/Technology/Finance carrying children), and an explicit clarification that "Assets" in the category list is a reporting pseudo-category, never a real `expense_categories` row.
- New §11/§12, expanded Assets (`warranty_expiry`, `disposal_date`, separate `notes` field) and Bank Accounts (reconciliation-readiness via a real `bank_account_id` FK on Expenses/Income, not automatic sync).
- New §13, Business-decision Reporting — cross-reference confirming every requested business-question report needs a new query, not a new table.
- §14/§15 (renumbered Future Compatibility / What This Doc Doesn't Decide) updated for the new additions — Budgets are MYR-only by design (comparability with a home-currency plan), multi-currency/RBAC/API/multi-warehouse/multi-user compatibility re-confirmed against every new table.

**`docs/FINANCE_DATABASE_DESIGN.md`:** new `budgets` and `finance_cost_classifications` tables designed; `expenses`/`manual_income` revised to carry `bank_account_id` (upgraded from an earlier free-text-only draft, specifically because reconciliation-readiness is cheap to design in now and expensive to retrofit later); `assets` revised with the 3 new fields; full recommended `expense_categories` seed list added; receipt "export by year" designed as a controller-level bulk-download action, not a new storage concept; P&L reuse table extended with the cost-classification lookup and budget-variance rows.

**`docs/FINANCE_WORKFLOW.md`:** new sections for setting/reviewing a Budget (one page, live actuals, no separate review step), the five business-question reports mapped to their data sources, and managing Bank Accounts (a short reference list, explicitly no bank-sync credentials). Existing expense/asset/receipt workflow sections updated in place for the new category structure, warranty/disposal fields, multiple-receipts-per-record, and export-by-year.

**`docs/TAX_REPORTING_DESIGN.md`:** cross-referenced the new cost-classification mechanism (a P&L export automatically reflects a later reclassification, no report-specific update needed) and the receipt export-by-year action (designed to sit alongside the existing report exports for a single accountant handoff).

**Explicitly not done in this round, same as the first design pass:** no code, no migration, no schema change, no depreciation logic, no tax calculation, no OCR, no bank-sync/reconciliation logic, no recurring-expense automation. Awaiting a separate approval before implementation begins.

### Finance & Accounting — design phase (no code, no migrations, no database changes)

**Reason:** Mewmii OS is evolving from an Operations System into a complete Business Operating System — the missing piece, per explicit user direction, is Finance. This entry is documentation only; nothing described in it has been implemented.

**Audit performed first** (before any design was written): a full codebase audit found Mewmii OS already holds a surprising amount of financial data that's never been assembled into a Finance module — real revenue reporting (`modules/reports/sales.php`), a real Landed Cost/COGS formula (`includes/product_cost.php`), real per-PO supplier payment tracking (`supplier_order_payments`), real refund tracking (`resolution_refunds`), and real currency-rate infrastructure (`currency_rates`). It also found genuine, confirmed-zero gaps: no outbound shipping cost tracking anywhere, no payment gateway fee capture, no cross-supplier payment rollup, and two fully dead scaffolding tables (`expenses`, `invoices` — present in `schema.sql`, zero application code). This finding — mostly integration, not creation — shaped the entire design.

**New documents:**
- `docs/FINANCE_ACCOUNTING_ARCHITECTURE.md` — the audit findings, the guiding "integration layer first" philosophy, navigation design (with two reasoned refinements to the user's proposal — "Income" scoped narrowly to non-order income only, "Payments" renamed "Supplier Payments" and scoped to the existing per-PO tracking's missing cross-supplier rollup), the Expense-vs-Asset distinction, integration points, an explicitly-flagged open question (where outbound shipping cost sits in P&L), and future-compatibility notes (Multi-Warehouse/RBAC/API/multi-currency/multi-user).
- `docs/FINANCE_WORKFLOW.md` — Workflow-First-principled walkthroughs for recording an expense/asset, reviewing P&L and Cash Flow (designed as two genuinely different reports — accrual vs. cash — not two views of one number), reconciling supplier payments, handling refunds (Finance reads the existing resolution flow, never re-implements it), receipt search, annual tax-filing prep, and the reserved (not built) recurring-expense shape.
- `docs/FINANCE_DATABASE_DESIGN.md` — a proposed schema (design sketch, no migration file, `schema.sql` untouched): `expense_categories`, `expenses`, `assets`, `expense_attachments`/`asset_attachments` (two small tables, not one polymorphic table — reasoned against, since this codebase has no existing polymorphic-FK precedent), `bank_accounts`, `manual_income`, plus a reserved `recurring_expense_templates` shape. Recommends replacing (not retrofitting) the dead `expenses`/`invoices` scaffolding, reusing `includes/receipt_storage.php`'s existing private-attachment pattern unchanged, and two new permissions (`finance.view`/`finance.manage`) via the existing idempotent `install.php` pattern.
- `docs/TAX_REPORTING_DESIGN.md` — six report designs (Expense Summary, Expense by Category, Annual Operating Expenses, P&L in tax-year framing, Asset Register, Income Summary) organized for LHDN filing support, explicitly scoped to information organization only — no tax rate, SST, or capital-allowance calculation anywhere in the design.

**Explicitly not done in this phase:** no code, no migration, no schema change, no depreciation logic, no tax calculation, no OCR, no recurring-expense automation. Per the user's closing instruction, implementation waits for a separate approval.

### Mewmii OS v2 Phase 2 — Shared Drawer framework + Inventory pilot

**Reason:** implements Phase 2 of `docs/MEWMII_OS_V2_PLAN.md` — shared, reusable UI infrastructure rather than a module-specific solution. Gated behind `docs/PHASE2_READINESS_REVIEW.md` (audited existing modal/AJAX/JS/CSS/permission/responsive patterns first; determined the Drawer should be built on `bootstrap.Offcanvas`, already loaded and unused, rather than hand-rolled) and refined per explicit user direction into a strict three-layer architecture — Drawer Framework → Controller → View — so HTML rendering never mixes into business logic and the framework never grows a per-resource-type code path.

**Drawer framework (new):**
- `assets/js/drawer.js` — `window.DrawerUI.open({url, title})` / `.close()`. Fetches a URL, injects the response as-is, nothing else. Built on a real `bootstrap.Offcanvas` instance — Esc, backdrop-click, and focus trap are inherited from Bootstrap, not reimplemented (unlike `assets/js/sidebar.js`'s hand-rolled mobile drawer, which explicitly lacks a focus trap).
- `includes/header.php` — one shared `#app-drawer` Offcanvas container (present on every logged-in page, same "lives once, populated by JS" convention as the global search dropdown), the `drawer.js` script tag, and `.app-drawer` width CSS (420px ≥576px, full-width below — reusing the breakpoint already established for every other small-viewport rule in this file, not a new one).
- `includes/ajax_helpers.php` — new `ajax_require_permission_html()`, a sibling of the existing `ajax_require_permission()` that renders a readable HTML fragment (with the correct 401/403 status) instead of a JSON body, since a Drawer panel needs to display a permission failure, not parse one.

**Inventory pilot (`modules/inventory/`):**
- `ajax/drawer.php` (Controller) — permission check, loads data via a plain read (deliberately not `inventory_get_or_create_row()`, which takes a row lock and creates a row as a side effect — inappropriate for a read-only preview) plus the existing `inventory_transactions_recent()` (new, small, unenriched — see below) and `variation_build_label()`.
- `views/drawer.php` (View) — pure presentation, no query, reads the Controller's variables. First file in the new `modules/<domain>/views/` convention documented in `docs/PHASE2_IMPLEMENTATION.md`.
- A "Quick View" (🔍) button added to both the simple-product row and the variation row in `modules/inventory/index.php`, alongside the existing Adjust Stock/View History buttons — same permission exposure as those (page-level `inventory.view`, no separate gate needed).
- The Drawer's "Related actions" (Adjust Stock, View Full History) call the page's own already-loaded `InventoryUI.openAdjustModal()`/`openHistoryModal()` directly — zero duplication of the existing modal/history logic.

**New function (`includes/inventory.php`):** `inventory_transactions_recent(PDO $pdo, int $productId, ?int $variationId, int $limit = 5): array` — a small, deliberately unenriched read (same table/WHERE shape as `modules/inventory/ajax/history.php`, without that endpoint's ~40-line reference-label resolution) for the Drawer's "glance before you leave the page" preview. The full, resolved history stays exclusively in the existing History modal — reused via `InventoryUI.openHistoryModal()`, never duplicated.

**Bug caught during implementation, before it shipped:** the first version of `drawer.js` only treated HTTP 401/403 as "displayable content" separately from a genuine error; the Controller also uses 400 (missing parameter) and 404 (not found) for deliberately-crafted fragments, which would have incorrectly hit the generic "Something went wrong" fallback instead of the Controller's own readable message. Fixed by broadening the condition to any 2xx or 4xx response (see `docs/PHASE2_IMPLEMENTATION.md` §5 for the resulting contract) — caught by re-reading the code against its own design doc before testing, then confirmed by the 400/404 tests below.

**Testing performed:** `php -l` on every new/changed PHP file, `node --check` on `drawer.js`. A throwaway local database (`mewmii_phase2_test`) was created and torn down after use, same discipline as Phase 1 — production `.env`/`config.php` untouched. Seeded a real product with stock quantities and two transactions, plus a variable product with one variation, and drove the app through real HTTP requests (PHP's built-in server): confirmed the Quick View button and Drawer/script markup render on the Inventory page; fetched the Controller endpoint directly and confirmed every stock number/transaction/action in the returned fragment matches the seeded data exactly, for both a simple product and a variation; confirmed a `Limited` (zero-permission) user gets the 403 HTML fragment from the endpoint directly *and* can't reach `modules/inventory/index.php` at all (defense-in-depth, unchanged); confirmed the 404 (nonexistent product) and 400 (missing `product_id`) paths each render their own crafted fragment with the correct status; confirmed the Drawer's global markup/script also render correctly on `index.php`, `modules/products/index.php`, `modules/supplier-orders/index.php`, and `modules/orders/index.php` with zero PHP errors/warnings (regression check on the shared `header.php` change). Not verified in this environment (no browser available): actual Esc-key/focus-trap/backdrop-click behavior and real mobile-viewport rendering — these rely on Bootstrap's own Offcanvas implementation rather than custom code, so the risk is materially lower than it would be for hand-rolled equivalents, but this is not the same as having watched it work in a browser.

**Explicitly not done in this phase, per the user's phase-discipline instruction:** the Activity Feed viewer (Phase 2 Step 3) and expansion of the Drawer to any module beyond Inventory. Both wait for this round's review.

### Mewmii OS v2 Phase 1 — Navigation consolidation, Notification badge, Dashboard Mission Control

**Reason:** implements the Phase 1 roadmap approved in `docs/MEWMII_OS_V2_PLAN.md`, gated behind a formal `docs/PHASE1_READINESS_REVIEW.md` (architecture compatibility, database impact, shared component locations, exact implementation order, testing requirements — all confirmed before any code changed). Every change below reuses an existing function/table; no new tables, columns, or permission rows were needed anywhere in this phase.

**Step 1 — Navigation consolidation (`includes/header.php`):** added the previously-orphaned `Purchase Planning` link (`modules/purchase-planning/generate.php` — reachable before only via a dashboard button, no sidebar entry) to the Operations section, gated on `supplier-orders.manage` to match the page's own `app_require_permission()` call exactly (not assumed from a sibling item's gate). Split the flat 8-item System section into `Integrations` (WooCommerce Sync, Webhook Events, Sync Logs) and `System` (the remaining 6 items) sub-labels. Grouping/labels/order only — no file was moved or renamed, so no bookmarked URL changed.

**Step 2 — Notification badge (`includes/header.php`):** the sidebar's `Notifications` link now shows an unread-count badge, reusing `notification_unread_count()` (`includes/notifications.php`, previously only ever called from `index.php`) — no new query, no new table. Absent entirely at zero unread, per the "silence is the default state" rule. Gated on the same `dashboard.view` permission the link itself already required.

**Step 3 — Dashboard Mission Control (`index.php`, full rewrite):** replaced the "Operations Command Centre" layout (5-card stat strip, Operations Overview, Purchasing Intelligence, Needs Attention, a full Notifications card, 30-day Business Snapshot with Top Products/Customer Activity) with `docs/DASHBOARD_PHILOSOPHY.md`'s three-part structure: a silent-when-healthy **Status Line** (rule-based 3-tier health), **My Day** (a live, auto-generated task list — six of the seven task sources named in the philosophy doc: overdue supplier orders, ready-to-ship orders, pending payment receipts, arrived preorder units to allocate, low-stock review, and purchase-planning-needs-by-supplier; the seventh, open customer resolutions, is deferred — no admin list page exists yet to link it to, so it feeds the health tier only, not a clickable task), and **Today's Business** (Orders/Revenue/AOV, now genuinely scoped to today + a real calendar-month teaser, not the old 30-day rolling window). Every number is either an unchanged existing function call or a plain read-only query already used elsewhere on this page before — see `docs/PHASE1_READINESS_REVIEW.md` §1 for the full reuse audit this was built against. Sections dropped per `DASHBOARD_PHILOSOPHY.md` §8's explicit "what moved off the dashboard" mapping (Top Selling Products/Business Snapshot detail → Reports; Notifications card → the new header badge + `/modules/notifications/`; Sync Health/Inventory Health permanent cards → folded silently into the Status Line) are gone rather than kept as dead weight, which also reduces the page's query count materially (the removed Purchasing Intelligence row alone ran a `demand_forecast_calculate()` pass and two `product_cost_calculate_batch()` passes over the catalog on every load).

**Deduplication (`includes/purchase_planning.php`, `includes/notifications.php`):** the overdue-supplier-orders predicate was previously copy-pasted identically in both `index.php` and `notifications.php`'s `supplier_order_overdue` alert generator. Extracted into one function, `supplier_orders_overdue(PDO $pdo): array`, that both now call — this *removes* pre-existing duplication rather than adding any.

**Bug found and fixed during testing (`includes/purchase_planning.php`), pre-existing, unrelated to the Phase 1 changes themselves:** `purchase_planning_needs()` fatally errored (`SQLSTATE[22003]: Numeric value out of range`) whenever a ready-stock product's `available_quantity` exceeded its `target_stock_level` — an entirely plausible real state (e.g. just after a large receiving event), not an edge case. Root cause: unsigned-column subtraction (`target_stock_level - available_quantity - incoming_quantity`) going negative under MariaDB strict mode. This function is called by three places (`index.php`'s dashboard, `modules/purchase-planning/generate.php`, `modules/inventory/index.php`'s needs-ordering filter) and would have crashed all three the first time this state occurred in production. Fixed by casting all three operands to `SIGNED` before subtracting — the `> 0` comparison already correctly excludes negative results either way, so this changes nothing about which rows are returned, only prevents the crash. Found via functional testing against a throwaway local database (see Testing below), not via a bug report.

**Testing performed:** a throwaway local database (`mewmii_phase1_test`, MariaDB via XAMPP) was created and torn down after use — production `.env`/`config.php` were never read or touched; DB credentials were overridden via process environment variables, which `includes/env_loader.php` never overrides once already set. `php -l` on every changed file. Standalone function-level tests for `notification_unread_count()` (zero/non-zero/read/resolved transitions, badge-visibility condition). A full install (`install.php`) seeded a real `Owner` role (all 20 permissions) and a `Limited` role (zero permissions) with real users; `app_has_permission()` was verified to return the correct boolean for both roles against every permission gate touched in Step 1/2. Logged in via real HTTP requests (PHP's built-in server) as both roles: confirmed the `Limited` user gets a 403 on `/index.php` (defense-in-depth, permission gate unchanged); confirmed the new Purchase Planning nav link renders/hides correctly and gets the `active` class on its own page; confirmed the header badge count matches `notification_unread_count()` and disappears at zero. Seeded real rows (a low-stock + purchase-planning-eligible product, a negative-stock adjustment, an overdue supplier order, a ready-to-ship order, a pending-receipt order) and confirmed via live page loads: Status Line correctly transitions healthy → attention → critical per the exact rule table in `DASHBOARD_PHILOSOPHY.md` §5; all five implemented My Day task types render with correct copy, correct link targets, correct sort order (overdue-first); Today's Business Orders/Revenue/AOV numbers matched the seeded data exactly. Mobile layout was not visually verified (no browser available in this environment) — relies on the existing `.card`/`.attention-item`/Bootstrap grid classes already responsive elsewhere on this page, no new breakpoint-specific CSS was introduced.

### Mewmii OS v2 — design documentation phase (no code changes)

**Reason:** after three rounds of chat-only design work (Dashboard v2 in three passes, then a full system-wide "System Design Phase" review), the user approved the direction and directed it be persisted as real documents under `docs/` rather than remain ephemeral — with binding requirements: preserve current philosophy (no rewrite, no unnecessary abstraction migration), add a "Workflow First" principle measured against 4 named daily workflows, confirm the Phase 1/2/3 roadmap, and require a separate forward-compatibility document each for multi-warehouse, RBAC, and API layer so current decisions don't block them later.

**Scope:** documentation only. No application code touched.

**New documents:**
- `docs/MEWMII_OS_V2_PLAN.md` — master plan: philosophy, the 4 priority workflows, UX north star (Shopify/Linear/Notion, principles not pixels), the Phase 1/2/3 roadmap, and pointers to every companion document.
- `docs/DASHBOARD_PHILOSOPHY.md` — the permanent Mission Control governing philosophy: the 3-question purpose, the "never on the dashboard" rules, My Day (rule-derived, zero new schema), Business Health (3-tier, fully rule-based), Search-first direction, and what moved off the dashboard and where.
- `docs/COMPONENT_LIBRARY_SPEC.md` — full specs for the 5 new v2 components (Drawer, Activity Feed viewer, Bulk Actions extended, Command Palette, Notification Badge), each covering where it lives, its AJAX pattern, permission handling, mobile behavior, and empty/loading/error states. Every spec was checked for reuse before being written — the Drawer reuses each module's existing `view.php` rather than adding parallel endpoints, and the Command Palette reuses Global Search's existing endpoint and render function rather than building a second search implementation.
- `docs/FUTURE_MULTI_WAREHOUSE.md`, `docs/FUTURE_RBAC.md`, `docs/FUTURE_API_LAYER.md` — design-only, explicitly not scheduled for implementation. Each documents the confirmed current gap, a sketch of the eventual shape, and — the load-bearing part — exactly what current v2 decisions must not assume so none of the three are blocked later (e.g., no component may hardcode an Owner-only check instead of `app_has_permission()`; no new business logic may be written directly inside page-rendering code instead of `includes/*.php`).

**Explicitly not done in this phase:** no implementation. Per the user's closing instruction, Phase 1 work (navigation consolidation, notification badge, Dashboard Mission Control) does not begin until this documentation set is reviewed and approved.

### Migration Management System v2 — architecture change: in-process execution (no exec/shell/subprocess/HTTP)

**Reason:** production confirmed `exec()`, `shell_exec()`, `system()`, `passthru()`, and `popen()` are all disabled — the subprocess execution model from v1 could never work there, not just occasionally fail. An HTTP-loopback alternative was designed and explicitly rejected (unnecessary infrastructure complexity — a new web endpoint, token auth, curl/loopback dependency — for a database tool). Built instead: a one-time mechanical refactor removing the actual blocker (a function-name collision), enabling true in-process execution.

**Scope:** `database/migrate_helpers.php` (new), all 21 `database/migrate_*.php` files (mechanically refactored — see below), `database/migrate.php` (rewritten). `docs/MIGRATION_MANAGEMENT_PLAN.md` (new §7/§8), `docs/IMPLEMENTATION_STATUS.md` updated.

**What changed in the 21 migration files — confirmed mechanical only, no SQL/logic change:**
- Removed each file's local declarations of shared helper functions (`migrate_run()`, `migrate_column_exists()`, `migrate_table_exists()`, `migrate_index_exists()`, `migrate_failures()` — up to 20 files duplicated these identically or near-identically) in favor of one `require_once __DIR__ . '/migrate_helpers.php';`. Genuinely migration-specific helpers (e.g. `migrate_catalog.php`'s `migrate_find_foreign_keys_on_column()`) were left in place, unshared.
- Wrapped each file's existing top-level logic, unchanged, in a uniquely-named function derived from its filename (`migrate_supplier_order_currency.php` → `function migrate_supplier_order_currency(PDO $pdo): array`), per the approved "unique execution function name" requirement.
- Each function now returns `['success' => bool, 'applied' => array, 'failures' => array, 'message' => string]` — built from each script's own pre-existing `$applied`/`$failures` variables; `message` summarizes the outcome (e.g. "3 statement(s) applied", "Already up to date", "N statement(s) failed").
- Added a standalone-execution guard (`if (!defined('MIGRATE_RUNNER_ACTIVE')) { migrate_<name>(app_db()); }`) so `php database/migrate_X.php` run directly still works exactly as before.
- **Verified, not assumed:** every shared helper's body was diffed byte-for-byte across every file that had it before touching anything. Found one real behavioral variant — 3 files' `migrate_run()` skipped recording into the failures registry — and confirmed those 3 files never read that registry either, so unifying was safe (adds unused bookkeeping only, no visible behavior change).

**`database/migrate_helpers.php` (new):** the 5 unified helpers, plus `migrate_failures_reset()` — a real correctness fix this refactor required: `migrate_failures()`'s data lives in a `static` variable that persists for the process's lifetime, harmless when each migration ran in its own subprocess but a real cross-migration bleed risk once several run back-to-back in one process. The runner calls this before each migration; no migration file does.

**`database/migrate.php` (rewritten):** discovery/pending-detection/`schema_migrations` schema/CLI-only guard/CLI usage all unchanged. Execution is now `require_once` (declares the function) + a direct function call, each wrapped in its own `try`/`catch(Throwable)` so one migration's genuine bug can't crash the batch. The subprocess-era `migrate_runner_check_exec_available()` pre-flight check and `migrate_runner_guess_cause()` diagnostic were removed as obsolete — there's no `exec()` call left to diagnose.

**Bug found and fixed during testing (not before):** the discovery glob (`migrate_*.php`) also matched `migrate_helpers.php` itself, which would have been treated as a fake 22nd migration and failed (no `migrate_helpers()` function exists). Fixed by explicitly excluding it in `migrate_runner_discover()`.

**Future rollback convention (documented only, per approved scope — not implemented):** a migration may optionally define `rollback_<migration_name>()` alongside `migrate_<migration_name>()`. Nothing in the runner or helpers currently looks for or calls one — pure naming reservation, documented in both files' docblocks and in `docs/MIGRATION_MANAGEMENT_PLAN.md` §7.6.

**Testing performed:**
- `php -l` on all 23 touched/new files — clean.
- Fresh local database seeded from `install.sql`: `database/migrate.php --run` — **all 21 migrations succeeded in one PHP process**, including the two most complex (`migrate_catalog.php`, which also runs the entirety of `schema.sql` as its own first step; `migrate_production_hardening.php`, which contains a multi-table customer-deduplication routine) — this is the exact scenario (multiple migrations, one process) that used to fatal-error before the refactor.
- Idempotency verified: immediately re-ran (preview and `--run`) — all 21 correctly `Completed`, 0 pending, no errors, no duplicate application.
- Standalone execution verified individually for all 21: reset to a fresh pre-migration database, ran every `migrate_X.php` directly (not via the runner) — all exited 0 with expected output, auto-run guard fired correctly every time.
- Verified against real `INFORMATION_SCHEMA` state, not just log output: confirmed `supplier_orders.currency`, `product_variations.weight_mode`, `currency_rates.rate_type`, the `resolution_requests` table, and the `supplier_orders.purchase_number` UNIQUE index were all genuinely present after the run.
- All testing used a local, throwaway MariaDB instance, torn down afterward — no production database was touched.

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
