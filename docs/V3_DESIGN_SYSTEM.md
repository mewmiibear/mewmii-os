# Mewmii OS V3 — Design System Specification & Implementation Plan

**Status:** Phases 1, 2 and **3 implemented and verified.** Phase 2.6 review complete: the §4
ranking is approved, and §8.4 is decided (Option A, delivered inside Phase 3.1). Phase 3.1–3.5
shipped as eleven commits, followed by the 3.1a/3.2g regression fixes; Phase 4 has not started.
Per-sub-phase status and the deferred sticky-header decision live in
`docs/IMPLEMENTATION_STATUS.md`. Verification workflow: `docs/QA_PROCESS.md`.

**§8.1 is now out of date and superseded.** It says this project has no test suite and no PHP
binary, and recommends a smoke harness "before Phase 4". Three verifiers now exist and are part
of the permanent toolchain: `tools/smoke` (structural), `tools/browser-qa` (computed style and
behaviour, added in 3.1a after it caught two defects smoke structurally could not see), and
`tests/` (V2 behavioural suites over the real ledger).
**Supersedes:** the roadmap section (§9) *and* the impact ranking (§8) of `V3_UIUX_AUDIT.md`. That
document remains the evidence base; this document is the authoritative spec, ranking, and plan.
Where the two disagree, this one is correct — see §6A.3 for why one of its findings was withdrawn.
**Extends (does not replace):** `MEWMII_OS_V2_PLAN.md`, `DASHBOARD_PHILOSOPHY.md`, `COMPONENT_LIBRARY_SPEC.md`.

**V3 is visual-only.** No database, schema, business logic, routing, permission, or workflow changes. Status enum values, form field names, POST targets, and permission gates are untouched throughout.

---

## 0. Governing Principle: Consolidate, Don't Invent

Mewmii OS has ~14 existing UI components. The problem is not that they are bad — it is that **half the application doesn't use them, and the half that does uses them inconsistently.**

Every proposal in this document was checked against three questions in order:

1. Does a component for this already exist? → **Extend it.**
2. Does something similar exist that could absorb this? → **Merge into it.**
3. Neither? → **Only then** propose something new.

That test produces exactly **three genuinely new primitives** in all of V3: a semantic status token scale, a confirmation dialog, and loading states. Everything else is extension, merge, or rollout of what already exists.

**On metrics:** the percentages in `V3_UIUX_AUDIT.md` are diagnostic, not targets. The goal is not "100% `.page-header` usage." The goal is that an operator moving between Orders, Inventory, and Supplier Orders encounters the same page shape, the same button meanings, and the same status colors — so they stop re-learning the interface at every hop. Where a page legitimately needs to differ, §7 documents why rather than forcing it into the standard.

---

## 1. Component Inventory

Measured across 236 PHP files, 8 JS files, 28 modules.

### 1.1 CSS components (defined in `includes/header.php`)

| Component | Pages | Uses | Verdict | Notes |
|---|---:|---:|---|---|
| `.empty-state` | 41 | 153 | **Standard** | Best-adopted component. Extend with a filtered-empty variant. |
| `.table-responsive` | 50 | 72 | **Merge** | Bootstrap default; horizontal scroll. Fold into the table standard alongside stacking. |
| `.page-header` | 35 | 36 | **Standard** | Roll out; promote `h2` → `h1`. |
| `.page-description` | 34 | 35 | **Standard** | Always paired with `.page-header`. |
| `.filter-card` | 24 | 24 | **Standard** | Strong adoption already. Add active-filter chips. |
| `.responsive-stack-table` | 18 | 19 | **Standard** | Under-adopted; becomes default rather than opt-in. |
| `.action-bar` | 17 | 18 | **Standard** | Add the 3-visible-action cap + overflow. |
| `.stat-card` | 9 | 60 | **Standard** | Sound. Add tabular numerics. |
| `.attention-item` | 3 | 3 | **Standard** | Under-used; the right vehicle for count-based alerts. |
| `.stat-card-link` | 1 | 3 | **Merge** | Fold into `.stat-card` as an `is-clickable` state. |
| `.receipt-preview-thumb` | 1 | 1 | **Merge** | Generalize into a `.thumb` utility. |
| `.sidebar` + `.nav-link` + `.nav-section-label` | global | — | **Standard** | Extend: 8 → 5 sections, collapsible, `$navActive()` fix. |
| `.app-drawer` | **0** | — | **Standard, unadopted** | See §1.4 — built and working, zero call sites. |
| `.card` | ~all | — | **Standard** | Bootstrap base + existing override. Ban nesting. |

### 1.2 PHP render helpers (`includes/`)

| Helper | Verdict | Notes |
|---|---|---|
| 10 × `*_status_badge()` | **Standardize** | See §3.6 — conflicting color semantics. |
| 8 × `*_status_label()` | **Keep as-is** | Label text is correct and consistent. |
| `render_saved_views_widget()` | **Standard** | Used on 5 list pages. Extend to remaining list pages later. |
| `variation_build_label()` / `_full_label()` | **Keep as-is** | Domain formatting, not presentation. |
| `catalog_lifecycle_badge()` | **Rebuild** | Only badge using hardcoded hex + emoji + inline `style`. |

### 1.3 View partials

Only **three** `_`-prefixed partials exist in the entire view layer — there is almost no include-based reuse.

| Partial | Verdict | Notes |
|---|---|---|
| `orders/_item_picker_modal.php` (104 ln) | **Merge** | ~85% identical to the supplier-orders version. |
| `supplier-orders/_item_picker_modal.php` (56 ln) | **Merge** | Same modal shell/table; differs only in filter dropdowns. |
| `products/_form.php` (81KB, 71 fields) | **Keep, restructure** | Correctly shared by create + edit. Needs section nav. |

### 1.4 JavaScript components

| File | Global | Pages | Verdict |
|---|---|---:|---|
| `drawer.js` | `window.DrawerUI` | **0** | **Standard, unadopted — highest-value existing asset** |
| `inventory.js` | `window.InventoryUI` | 3 | **Standard** — the adjust-stock modal pattern |
| `global_search.js` | — | global | **Standard** |
| `sidebar.js` | — | global | **Standard** |
| `entry-form-validation.js` | — | 10 | **Standard** — extend app-wide for §3.9 validation |
| `order-form.js` | — | 7 | **Keep** — domain-specific |
| `supplier-order-form.js` | — | 4 | **Keep** — domain-specific |
| `product-form.js` (77KB) | — | 3 | **Keep** — domain-specific; uses native `alert`/`confirm` |

**The Drawer finding.** `assets/js/drawer.js` is complete and working: `window.DrawerUI.open()`, fetch-and-inject, plus **loading, error, and retry states — the only loading state that exists anywhere in the application.** It has zero call sites, because `COMPONENT_LIBRARY_SPEC.md` §1's companion change (a `?panel=1` flag on each `view.php`) was never implemented.

This matters for two reasons. First, V3 does not need to build a slide-over — it needs to *adopt* one. Second, `drawer.js`'s existing loading/error markup is the natural source of truth for the loading states in §3.13, rather than a new invention.

> Caveat: wiring `?panel=1` into a `view.php` is a small conditional around the header/footer includes. It is arguably a routing behavior rather than pure presentation, so it is **held out of V3's core phases** and listed as an optional add-on in §6, requiring separate approval.

### 1.5 Components that do not exist

| Missing | Today | Priority |
|---|---|---|
| Confirmation dialog | 48 native `confirm()` | **New — required** |
| Loading / pending states | 0 in PHP (only `drawer.js`) | **New — extend `drawer.js`** |
| Overflow menu | 1 instance (V2 U2) | **Extend that instance** |
| Pagination | 11 of 49 list pages, ad-hoc | **Standardize existing** |
| Toast | Does not exist | **New — low priority** |
| Breadcrumb | Ad-hoc "Back to X" links | **New — low priority** |

---

## 2. Design System Specification

### 2.1 Color

Brand identity is retained. The semantic scale is new and uses values already proven in `header.php`'s `.alert-*` overrides.

```css
/* ---- Brand: actions and active states ONLY. Never a status. ---- */
--brand:              #FF94C4;   /* primary buttons, active nav */
--brand-hover:        #F97DB7;
--brand-active:       #E86BA6;
--brand-tint:         #FFF1F7;   /* active nav background */

/* ---- Secondary: links and secondary emphasis ---- */
--secondary:          #3472EF;
--secondary-hover:    #2A5FCB;

/* ---- Semantic status. One meaning each, application-wide. ----
   The `-tint` suffix (matching the existing --brand-tint) is deliberate:
   `--neutral-bg` would collide with `--bg`, the app background, which the
   pre-V3 token block already used that exact name for. */
--success:            #1F7A43;   --success-tint:  #E6F6EC;
--warning:            #8A6116;   --warning-tint:  #FFF6E5;
--danger:             #A32458;   --danger-tint:   #FDEAF1;
--info:               #1D57B0;   --info-tint:     #EAF2FF;
--neutral:            #666A71;   --neutral-tint:  #F1F1F2;
--danger-accent:      #D9486E;   /* stat alert figure, attention row border */

/* ---- Surfaces ---- */
--bg:                 #F6F6F7;   /* app background */
--surface:            #FFFFFF;   /* cards, tables, modals */
--surface-sunken:     #FBFAFC;   /* filter cards, table headers */

/* ---- Borders ---- */
--border:             #E3E3E3;   /* default */
--border-strong:      #CFCFD2;   /* inputs, dividers needing weight */
--border-focus:       #3472EF;   /* focus ring */

/* ---- Text hierarchy ---- */
--text:               #202223;   /* primary */
--text-muted:         #6B6F76;   /* secondary, labels, help */
--text-subtle:        #8C9196;   /* tertiary, timestamps, placeholders */
--text-on-brand:      #FFFFFF;
```

**Retired:** `--sky-blue` `#85D2FF`, `--berry-rose` `#B2668C`, and the undeclared `#66524E` hardcoded inside `catalog_lifecycle_badge()`.

**Mandatory override.** `header.php` currently overrides `.btn-primary` and `.alert-*` but **not** `.badge.bg-*`, `.btn-success`, `.btn-danger`, or `.btn-warning`. Stock Bootstrap colors therefore render beside the soft Mewmii palette on the same screen, and `.bg-primary` resolves to `#0d6efd` — a third blue present in no token. All of these must be overridden to the scale above.

### 2.2 Typography

System stack: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif`. No web font — a network request would violate `DASHBOARD_PHILOSOPHY.md` §2.7 ("the dashboard is never the reason the page feels slow").

| Role | Size / line-height | Weight | Color | Element |
|---|---|---|---|---|
| Page title | 24 / 1.25 | 700 | `--text` | `<h1>`, one per page |
| Section title | 16 / 1.4 | 600 | `--text` | `<h2>` in cards (currently `<h5>`) |
| Card title | 15 / 1.4 | 600 | `--text` | `<h3>` for sub-blocks |
| Body | 14 / 1.5 | 400 | `--text` | default |
| Body secondary | 13 / 1.5 | 400 | `--text-muted` | descriptions, help |
| Caption | 12 / 1.4 | 400 | `--text-subtle` | timestamps, meta |
| Label / overline | 12 / 1.4 | 600, `0.02em`, uppercase | `--text-muted` | form labels, `<th>`, stat labels |
| Numeric | inherit | inherit | inherit | `font-variant-numeric: tabular-nums` on all money/quantity |

Heading levels become semantic: exactly one `<h1>` per page, `<h2>` for card sections, `<h3>` for sub-blocks. Today 79 pages use `<h2>` as the page title and `<h5>` for sections, skipping `h3`/`h4` entirely.

### 2.3 Spacing

4px base scale. No arbitrary values.

| Token | Value | Use |
|---|---:|---|
| `--space-1` | 4px | icon–text gap, badge padding |
| `--space-2` | 8px | tight stacks, button gaps |
| `--space-3` | 12px | table cell vertical, input padding |
| `--space-4` | 16px | table cell horizontal, form field gap |
| `--space-5` | 24px | card padding, card gap |
| `--space-6` | 32px | section spacing, page padding (desktop) |
| `--space-7` | 48px | major section separation, empty-state padding |

Applied: page padding `32px` desktop / `16px` below 576px · card padding `24px` · card-to-card gap `24px` · form field gap `16px` · section spacing `32px` · page-header bottom margin `32px` · grid gutter `24px`.

### 2.4 Border radius

| Token | Value | Applies to |
|---|---:|---|
| `--radius-sm` | 8px | inputs, selects, buttons, small controls |
| `--radius-md` | 12px | cards, modals, drawer |
| `--radius-full` | 999px | badges, pills, avatars |

**Revised during Phase 1 implementation.** This originally drafted 6px/10px. On inspection the existing 8px/12px pairing is already applied consistently across buttons, inputs, and cards, so changing it would be churn with no legibility gain. The tokens adopt the existing values. Two genuine outliers remain and are normalized in Phase 2: `.alert` (14px) and `.attention-item` (10px).

### 2.5 Shadows

Only two. Depth communicates layer, not decoration.

| Token | Value | Use |
|---|---|---|
| `--shadow-sm` | `0 1px 2px rgba(0,0,0,.04)` | resting cards, sticky headers |
| `--shadow-md` | `0 4px 10px rgba(0,0,0,.08)` | modals, drawer, dropdowns, hover lift |
| `--shadow-drawer` | `2px 0 16px rgba(0,0,0,.15)` | off-canvas sidebar |

**Revised during Phase 1 implementation** to the existing proven values (drafted as `.05` / `4px 12px`). The deltas were sub-perceptual, so adopting the existing values kept Phase 1 at a provable zero visual change rather than spending a visual-change commit on an invisible difference.

Filter cards and table containers get **no shadow** — border only, so they read as supporting rather than competing surfaces.

### 2.6 Icons

Bootstrap Icons (already loaded, 40 distinct in use).

| Context | Rule |
|---|---|
| Sidebar top-level | **Always** — one icon per section |
| Sidebar sub-links | **Never** — indentation carries hierarchy |
| Page titles | **Never** — currently 11 of 76, inconsistently |
| Buttons with text | **Never** — except the standard overflow `⋯` |
| Icon-only buttons | **Required** + `aria-label` — overflow, close, sort |
| Status badges | **Never** — no emoji (see `catalog_lifecycle_badge()`) |
| Empty states | **Always** — one 32px muted icon |

Size 16px inline, 20px standalone. One icon per concept application-wide — the same concept must not use two different icons in two modules.

### 2.7 Buttons

| Variant | Appearance | When to use | Limit |
|---|---|---|---|
| **Primary** | Solid `--brand`, white text | The single most likely next action on the page | **Exactly 1 per page** |
| **Secondary** | White fill, `--border-strong`, `--text` | Supporting actions of equal-or-lower importance | Unlimited |
| **Outline** | Transparent fill, `--secondary` border/text | Navigation to a related destination | Unlimited |
| **Ghost** | No fill, no border, `--text-muted` | Tertiary / inline / in-table actions, table sort | Unlimited |
| **Danger** | Outline `--danger`; **solid only inside a confirm modal** | Destructive actions | Never adjacent to primary |

Sizes: `sm` 28px — inside table rows only. `md` 36px — everywhere else. There is no `lg`. This replaces today's blanket `btn-sm` (366 uses vs 4 `btn-lg`), which flattens everything to one weight.

Disabled: 50% opacity, `cursor: not-allowed`, never removed from the DOM — a disappearing button is worse than a disabled one.

**Action-bar rule:** at most **3 visible actions**; everything beyond goes into overflow. Destructive actions live in overflow, never in the header row.

Rationale for `Primary = exactly one`: not button *count* — page-level CTA count was never
actually the problem (see §6A.3). The real defect was that `btn-primary` carried four unrelated
meanings at once: page CTA, active filter, form submit, and bulk action. A rule of one primary
per page is what keeps those roles from collapsing back into one colour. Resolved in Phase 2.3 by
giving selection its own treatment rather than by removing buttons.

### 2.8 Badges — full standardization

This is the largest single consistency defect in the application. Ten badge functions assign colors independently, and **the same status word currently renders in up to four different colors depending on which page you are on.**

**Measured conflicts:**

| Status | `order_status` | `order_item_fulfillment` | `shipment_status` | `ship_request` | `supplier_order` |
|---|---|---|---|---|---|
| `pending` | secondary | — | secondary | **warning** | — |
| `waiting_stock` | **warning** | **danger** | — | — | — |
| `shipped` | **dark** | **success** | **primary** | **primary** | — |
| `cancelled` | danger | **secondary** | danger | — | danger |
| `received` | — | — | — | — | **warning** |

`shipped` has four colors. `waiting_stock` has two. `received` — a success step — renders amber, the same color as `waiting_stock`, a blocked state.

**The V3 rule — five tokens, one meaning each:**

| Token | Meaning | Operator reading |
|---|---|---|
| `neutral` | Not started / inactive | "Nothing to do yet" |
| `info` | In progress, no action needed now | "Moving, leave it" |
| `warning` | **Blocked — action required** | "I need to do something" |
| `success` | Complete / healthy | "Done" |
| `danger` | Failed / cancelled / error | "Something went wrong" |
| `brand` | **Never a status** | — |

**Complete mapping — every status in the application:**

| Status value | Token | Function(s) |
|---|---|---|
| `draft`, `pending`, `inactive`, `historical`, `Awaiting Receipt Upload` | `neutral` | order, shipment, supplier_order, fulfillment, receipt |
| `processing`, `ordered`, `shipping`, `partially_received`, `partially_fulfilled`, `refunded`, `Receipt Submitted` | `info` | order, supplier_order, payment, receipt |
| `waiting_stock`, `waiting_ship_my_box`, `waiting_payment`, `waiting_release` | `warning` | order, fulfillment, supplier_order, catalog |
| `ready_to_ship`, `stored`, `shipped`, `received`, `delivered`, `completed`, `paid`, `Approved` | `success` | all |
| `cancelled`, `failed`, `unpaid`, `Rejected`, `closed` | `danger` | all |

Notable reassignments: `received` warning → **success** · `waiting_stock` unified to **warning** (was warning *and* danger) · `shipped` unified to **success** (was dark/success/primary/primary) · `cancelled` unified to **danger** (was danger *and* secondary) · `ready_to_ship`/`stored`/`partially_*` off `primary`, freeing brand pink for actions only.

**`catalog_lifecycle_badge()` is rebuilt.** It is the only badge using hardcoded hex, emoji, and inline `style`, and it uses brand pink as a status color. Product lifecycle is a *category*, not a *state*, so it becomes a neutral outline badge with a text label and no emoji: `Early Bird` · `Preorder` · `Ready Stock`. `Waiting Release` → `warning`, `Closed` → `danger`.

Badge style: 12px / 600 / `--radius-full` / `4px 10px` padding / tinted background + dark foreground. Text is always the human label, never a raw enum.

**Contrast, verified at badge size (Phase 2.1).** These pairs were proven as alert text, but badges render at 12px, where WCAG AA requires 4.5:1 rather than the 3:1 large-text allowance. Measured:

| Token | Ratio | |
|---|---:|---|
| `info` | 6.13:1 | pass |
| `danger` | 6.17:1 | pass |
| `warning` | 5.15:1 | pass |
| `outline` | 5.05:1 | pass |
| `success` | 4.78:1 | pass |
| `neutral` | **4.47:1** | **failed** → `--neutral` darkened from `#6B6F76` to `#666A71`, now **4.81:1** |

`--neutral` was referenced by nothing that rendered at the time, so the correction cost nothing visually.

### 2.9 Cards

| Type | Spec |
|---|---|
| **Page card** | `--surface`, 1px `--border`, `--radius-md`, `--shadow-sm`, 24px padding. Section title 16/600. |
| **Stat card** | Extends page card. Label 12/600 uppercase muted · value 28/700 tabular · helper 13 muted. Optional `is-clickable` (absorbs `.stat-card-link`). |
| **Filter card** | `--surface-sunken`, 1px `--border`, **no shadow**, 16px padding. Visually quieter than content. |
| **Form card** | Extends page card, 32px padding, max-width 720px single-column. |

**No nested cards.** Use a `24px` gap or a `1px --border` divider instead. **No colored card headers** — status belongs in a badge, not a header bar.

### 2.10 Tables

| Aspect | Spec |
|---|---|
| Header | `--surface-sunken`, 12/600 uppercase `--text-muted`, 2px `--border` bottom |
| Row height | 48px; 12px vertical / 16px horizontal padding |
| Hover | `--bg` background, no shift |
| Selected | `--brand-tint` background, 3px `--brand` left border |
| Alignment | Text left · **numbers right + tabular-nums** · actions right · status center-left |
| Dividers | 1px `--border` between rows; none between columns |
| Actions column | Max 2 inline ghost buttons, rest in overflow. Always last, right-aligned |
| Column cap | **8 visible.** Excess → detail page (exception: §7) |
| Empty | `.empty-state`, distinguishing "none exist" from "none match filter" |
| Pagination | Required above 25 rows |
| Mobile | `.responsive-stack-table` **by default**, not opt-in |
| Bulk actions | Checkbox first column; a selection toolbar replaces the filter bar when ≥1 row selected, showing "N selected" + actions + Clear |

Bulk actions follow `COMPONENT_LIBRARY_SPEC.md` §3: each bulk action **must** call the same per-record function the single-record action calls. No bulk-specific logic, ever.

### 2.11 Forms

| Aspect | Spec |
|---|---|
| Layout | Single column, max-width 720px. Two columns only above 992px, and only for genuinely paired fields |
| Label | Above input, 12/600 uppercase `--text-muted`, 4px gap |
| Required | `*` in `--danger` after label text. Optional fields unmarked |
| Helper | 13px `--text-muted`, below input |
| Sections | `<h2>` 16/600 + `--border` divider + 32px spacing |
| Section nav | Required above ~20 fields (see §7 for `products/_form.php`) |
| Actions | Bottom-left: Primary submit, Ghost cancel. Sticky footer if the form exceeds one viewport |

Validation extends `entry-form-validation.js` (already on 10 pages) rather than introducing a new library.

#### 2.11.1 Control states — the complete set

**Decided in Phase 2.6 (§8.4, Option A); implemented as one unit in Phase 3.** Every value below
is measured, not estimated. A text input is empty by definition, so its **border is the only
thing identifying it as a control** — which is why WCAG 1.4.11's 3:1 applies here with no room to
argue, and why these states are specified together rather than one at a time.

| State | Border | Fill | Text | Boundary contrast |
|---|---|---|---|---|
| **Resting** | 1px `--border-input` `#848890` | `--surface` | `--text` | **3.56:1** ✅ |
| **Hover** | 1px `--text-muted` `#6B6F76` | `--surface` | `--text` | 5.05:1 ✅ |
| **Focus** | 1px `--border-focus` `#3472EF` + 3px ring | `--surface` | `--text` | **4.38:1** ✅ |
| **Invalid** | 1px `--danger` `#A32458` + message below | `--surface` | `--text` | 7.12:1 ✅ |
| **Readonly** | 1px `--border-input` `#848890` | `--bg` `#F6F6F7` | `--text` | **3.29:1** ✅ |
| **Disabled** | 1px `--border` `#E3E3E3` | `--bg` `#F6F6F7` | `--text-muted` | 1.28:1 — **exempt** |
| **Placeholder** | — | — | `--text-muted` `#6B6F76` | 5.05:1 ✅ |

**Why `#848890` and not the `#8C9196` named in Option A.** `#8C9196` measures 3.18:1 on white but
only **2.94:1 on the `--bg` fill a readonly control uses** — so the same token would pass on one
form control and fail on another. `#848890` clears 3:1 on both surfaces (3.56 / 3.29) with
headroom. One token, correct everywhere.

**Disabled is deliberately exempt.** WCAG 1.4.11 excludes inactive components, so a disabled
control may — and should — recede. Readonly is *not* exempt: it remains focusable and its text
selectable, so it carries the full 3:1 boundary.

**Focus cannot rely on the ring.** The existing `--focus-ring` is `rgba(52,114,239,0.15)`, which
composites to `#E0E9FC` over white — **1.22:1**, effectively invisible. Raising the alpha does not
rescue it (0.45 still only reaches 1.85:1). So the focus indicator **is the border colour change**
to `#3472EF` at 4.38:1; the ring is a supporting halo and never the sole signal. This satisfies
WCAG 2.4.11 through the border, not the glow.

**Placeholder text is text.** `--text-subtle` `#8C9196` measures 3.18:1 and fails the 4.5:1 needed
for text, so placeholders take `--text-muted` `#6B6F76` (5.05:1). `--text-subtle` remains valid for
decorative and non-essential content only.

**Success has no green field.** A valid input looks like a resting input. Confirmation belongs to
a toast or a redirect — colouring every satisfied field green produces a wall of noise and leaves
nowhere for a genuine error to stand out.

#### 2.11.2 Consequence for buttons — flagged, not yet decided

`--border-strong` is currently shared by form controls **and** by `.btn-secondary`,
`.btn-outline-secondary`, and `.btn-filter`. Those buttons have a white fill on a white card, so
by the same argument their border is also the only thing identifying them, and they would also
need 3:1.

Raising them makes the toolbar visibly heavier — the cost Option A acknowledged. Phase 3 will
introduce `--border-input` as a **separate token** so the form system can be made compliant
without silently restyling every button in the app in the same commit. Whether buttons follow is
a distinct decision, to be raised with a rendered comparison once forms are done.

### 2.12 Navigation

**Sidebar** — 8 sections → **5**, honoring the `MEWMII_OS_V2_PLAN.md` §4 Phase 1 commitment:

| Section | Contents |
|---|---|
| **Overview** | Dashboard, Notifications |
| **Sales** | Orders, Customers, Customer Storage, Ship My Box, Shipments |
| **Operations** | Inventory, Reservation Center, Allocation Center, Purchasing, Control Center, Purchase Planning, Suppliers, Supplier Orders |
| **Insights** | Sales Report, Inventory Intelligence, Demand Forecast, Margin Report, Cost History, Supplier Performance |
| **Settings** | WooCommerce Sync, Webhook Events, Sync Logs, Job Queue, System Health, Currency Rates, Inventory Reconciliation, Settings, Reset Test Data |

Collapsible sections with persisted state (`localStorage`, same convention as `sidebar.js`). Every link keeps its existing `app_has_permission()` gate exactly. Fix `$navActive()` so a parent and child are not both highlighted simultaneously. 992px drawer behavior unchanged.

**Page header** — `<h1>` + optional one-line description + right-aligned `.action-bar` (≤3 + overflow). 32px bottom margin. Stacks below 576px.

**Breadcrumbs** — only on detail pages nested two or more levels deep (e.g. Supplier Orders → PO-1023 → Receiving). Format `Section / Parent / Current`, current unlinked. Replaces ad-hoc "Back to X" buttons, which currently consume an action-bar slot on many pages.

**Back buttons** — removed where a breadcrumb exists. Retained only where a page is genuinely modal-like in intent (e.g. `inventory/allocate.php`).

**Overflow menu** — `⋯` ghost icon button → right-aligned dropdown. Contains: rare actions, all destructive actions, and anything beyond the 3rd action. Destructive items are `--danger` text, separated by a divider, always last. Extends the single dropdown introduced in V2 U2.

### 2.13 Alerts, toasts, modals, confirmations, loading, empty, pagination

**Alerts** — persistent page-level state only (validation errors, standing warnings). Existing `.alert-*` tokens are already correct; keep them.

**Toast** *(new, low priority)* — transient action confirmation, top-right, auto-dismiss 4s, `--shadow-md`. Replaces the `?created=1` → reload → alert pattern where no reload is otherwise needed.

**Modals** — one width scale: `sm` 400px (confirm) · `md` 560px (single form) · `lg` 800px (item picker). Header title + close, body, footer with Ghost cancel (left) and Primary/Danger confirm (right). Reuses the existing Bootstrap modal already present on 9 pages.

**Confirmation dialog** *(new — replaces all 48 native `confirm()` calls)*, three tiers:

| Tier | Pattern | Examples |
|---|---|---|
| Routine reversible | No dialog; toast with Undo where feasible | Mark note read, clear filter |
| Significant | `sm` modal, states exact consequence + record identifier | Reverse Receipt, Mark Arrived, Cancel Order |
| Destructive | `sm` modal, Danger button, consequence spelled out, no default focus on confirm | Delete supplier order, Delete payment, Reset test data |

A native `confirm()` cannot show which record is affected, cannot distinguish a reversible workflow step from a permanent deletion, and cannot be styled. `supplier-orders/view.php` alone gives identical treatment to "Mark Arrived" and "Delete".

**Loading states** *(new — extends `drawer.js`'s existing markup)*:

| Context | Pattern |
|---|---|
| Full page load | None — server-rendered, already fast |
| Async region | Skeleton rows (drawer's existing loading markup, generalized) |
| Button action | Disabled + inline spinner, **width preserved** so layout doesn't jump |
| Long operation (sync, import) | Progress region with determinate count where available |

**Empty states** — extends `.empty-state`: 32px muted icon, 16/600 title, one 13px muted line, at most one CTA. Two variants: *nothing exists yet* (offer create) vs *no results for this filter* (offer Clear filters). The two are not interchangeable — offering "Create Product" when a filter simply matched nothing is actively misleading.

**Pagination** — one component: `Showing X–Y of Z` left, prev/next right, page numbers when ≤7 pages. Default 25 rows operational / 50 reference.

---

## 3. Component Standardization Plan

| Component | Action | From → To |
|---|---|---|
| Status badges | **Standardize** | 10 independent maps → 1 five-token scale |
| `catalog_lifecycle_badge()` | **Rebuild** | Hex + emoji + inline style → token badge |
| Buttons | **Standardize** | 366 `btn-sm`, no hierarchy → 5 variants, 2 sizes, 1 primary/page |
| Overflow menu | **Extend** | 1 instance → shared pattern |
| `.page-header` | **Roll out** | 35 pages → all pages with a title; `h2` → `h1` |
| `.action-bar` | **Extend** | 17 pages → all headers, capped at 3 |
| Tables | **Standardize** | Ad-hoc → one spec, 8-col cap, stacking default |
| `.responsive-stack-table` | **Promote** | Opt-in (18) → default |
| `.table-responsive` | **Merge** | Fold into the table standard |
| `.empty-state` | **Extend** | 41 pages → all lists; add filtered-empty variant |
| Pagination | **Standardize** | 11 ad-hoc → 1 component |
| `.filter-card` | **Extend** | Add active-filter chips + Clear all |
| `.stat-card-link` | **Merge** | → `.stat-card.is-clickable` |
| `.receipt-preview-thumb` | **Merge** | → generic `.thumb` |
| `.attention-item` | **Extend** | 3 pages → the standard count-alert vehicle |
| Item picker modals | **Merge** | 2 near-identical partials → 1 parameterized |
| Confirmation | **New** | 48 `confirm()` → 3-tier modal |
| Loading states | **New (extend `drawer.js`)** | 0 → skeleton + pending + progress |
| Toast | **New** | — |
| Breadcrumb | **New** | Ad-hoc "Back to X" → breadcrumb |
| Drawer | **Adopt** | Built, 0 call sites → wired (optional, §6) |
| CSS location | **Extract** | 484 lines in `header.php` → `assets/css/` |
| Inline `style=` | **Remove** | 99 attributes → token classes |

---

## 4. Highest-Impact Page Ranking

**Re-derived in Phase 2.6** from measurements taken *after* Phase 2, against the five factors
below. The previous ranking is superseded; see §4.3 for exactly what moved and why.

> **Assumption stated plainly:** this project has no analytics and I could not query the
> database, so *frequency* is **inferred** from each page's position in the four core workflows
> in `MEWMII_OS_V2_PLAN.md` §2 — not measured. Real usage data should override it.

### 4.1 Scoring factors

| Factor | How it is measured |
|---|---|
| **Operator impact** | Does fixing it remove steps or confusion from daily work? |
| **Frequency** | Position in the four core workflows (inferred, see above) |
| **UX friction** | Measured density: forms + `<h5>` sections + widest table |
| **Accessibility impact** | Measured contrast failures, missing landmarks, mobile reachability |
| **Implementation risk** | Number of forms that must keep posting identically |

### 4.2 Cross-cutting work, ranked above any single page

Phase 2.6 measurement shows the largest remaining wins are **not** page-specific. These affect
dozens of pages each and should be scheduled before individual page work.

| # | Item | Scope | Operator impact | A11y | Risk |
|---|---|---:|---|---|---|
| C1 | **Confirmation dialogs** replacing native `confirm()` | 48 sites | High — "Delete supplier order" and "Mark Arrived" are currently indistinguishable | High | Medium |
| C2 | **Mobile table stacking** | 43 of 61 table pages | High — a 26-column table is sideways-scrolled on a phone | High | Low |
| C3 | **Pagination** on list pages that `LIMIT` without it | 38 pages | High — rows are silently unreachable | Medium | Medium |
| C4 | **Form control system** — all seven states as one unit (§2.11.1) | app-wide | Medium | **High** — input borders 1.37:1 vs 3:1 required | Medium |
| C5 | **`.page-header` rollout** | 41 pages have an `<h1>` but no `.page-header` | Medium | Low | Low |
| C6 | **Loading / pending states** | 0 exist | Medium — long syncs and imports give no feedback | Low | Low |
| C7 | **Empty states** | 41 of ~88 list surfaces | Low | Low | Low |
| C8 | **99 inline `style=` attributes** | 33 files | None — maintainability only | None | Low |

### 4.3 Page ranking (re-measured)

Density = `<form>` count + `<h5>` section count. Columns = widest `<thead>` on the page.

| # | Page | Freq | Density | Cols | Risk | Evidence / change since original |
|---|---|---|---:|---:|---|---|
| 1 | `orders/view.php` | Very high | **28** | 9 | **High** | 12 forms + 16 sections; four adjacent payment sections. Unchanged — still the densest page in the app. |
| 2 | `supplier-orders/view.php` | Very high | **21** | 16 | **High** | 11 forms + 10 sections. Owns receiving and reverse receiving. Its "6 primaries" were all form submits — the density, not the buttons, is the problem. |
| 3 | `reports/sales.php` | Low | 5 | **26** | Low | Widest table in the app. **Up from #15** — column count is now weighted properly, and reports are exempt from the cap but not from being scannable. |
| 4 | `customers/view.php` | Medium | 5 | **24** | Low | **Up from #12.** No mobile stacking. |
| 5 | `suppliers/view.php` | Medium | 4 | **22** | Low | **Up from #13.** No mobile stacking. |
| 6 | `purchasing/control-center.php` | Medium | 5 | **21** | Low | **Up from #10.** Densest *operational* table. |
| 7 | `purchasing/index.php` | Medium | 2 | **20** | Low | Newly ranked. |
| 8 | `integrations/woocommerce.php` | Low | **13** | 20 | Medium | 6 forms + 7 sections + 20 cols, and no progress feedback on a long sync (C6). |
| 9 | `products/_form.php` | High | 11 | 13 | **High** | 71 fields, no section navigation. Down from #5 only because C1–C4 outrank it, not because it improved. |
| 10 | `products/index.php` | High | 6 | 14 | Medium | 6 forms, 14 columns. |
| 11 | `products/import.php` | Low | 7 | **18** | Medium | Newly ranked; shares the wizard pattern with four other importers. |
| 12 | `purchase-planning/generate.php` | High | 3 | 17 | Low | Core of workflow #1. |
| 13 | `catalog/tabs/*` (5 pages) | Medium | 12 | 11 | Medium | ~400 near-identical lines each. Merging them is **logic** refactoring — out of V3 scope (§7). |
| 14 | `allocation-center.php` / `reservation-center.php` | Medium | 3 | 10 | Low | Per-row Auto/Manual; no bulk selection. |
| — | ~~`inventory/index.php`~~ | — | 3 | 13 | — | **Withdrawn from the ranking.** Was #3 on a corrected artifact; it has 1 CTA and unremarkable density. Ordinary list-page standardisation only. |
| — | ~~`orders/index.php`~~ | — | 6 | 11 | — | **Dropped from #4** to ordinary list work for the same reason. |
| — | ~~`shipments/index.php`~~, ~~`ship-my-box/index.php`~~ | — | 5 / 4 | 11 / 8 | — | **Dropped.** Their counts were pills and submits. |

**Implementation order is impact-driven, not module-driven** — and after re-measurement, the
cross-cutting items in §4.2 outrank every individual page except the two record pages.

---

## 5. Refined Roadmap — 4 Phases

Consolidated from the ten-phase V3.0–V3.9 draft. Each phase is independently reviewable and shippable.

---

### Phase 1 — Foundation

**Objective.** Move the design system out of `header.php` into real stylesheets and establish the token layer, with **zero intended visual change**. Every later phase depends on this.

**Scope.** Extract 484 lines of CSS from `includes/header.php` into `assets/css/tokens.css`, `base.css`, `components.css`. Define all tokens from §2.1–2.5. Add the missing overrides for `.badge.bg-*`, `.btn-success`, `.btn-danger`, `.btn-warning` so stock Bootstrap colors stop leaking. Rewrite existing component CSS in terms of tokens. No markup changes, no page changes.

**Files.** `includes/header.php` (CSS block removed, `<link>`s added) · `assets/css/*.css` (new) · `assets/css/style.css` (currently 0 bytes — populated or deleted).

**Risk: Low.** Single file's styles relocated; class names unchanged.

**Visual impact: Minimal by design** — except the badge/button color overrides, which intentionally normalize the saturated Bootstrap colors. This is the one deliberate visual change in Phase 1 and should be reviewed on its own.

**Regression risk: Low.** Main hazard is CSS load order or a missed rule. Mitigation: extract verbatim first, tokenize second, as two separate commits.

**Order: 1st — blocks everything.**

---

### Phase 2 — Visual Language

**Objective.** Make the application visually consistent without restructuring any page. After this phase, colors, badges, buttons, typography, and headers mean the same thing everywhere.

**Scope.**
- Standardize all 10 badge functions onto the five-token scale (§2.8); rebuild `catalog_lifecycle_badge()`.
- Roll out the button hierarchy (§2.7); reduce to one primary per page; build the shared overflow menu.
- Apply the type scale; promote page titles `h2` → `h1`, sections `h5` → `h2`.
- Roll out `.page-header` / `.page-description` / `.action-bar` where a page has a title and no legitimate reason to differ (§7).
- Remove the 99 inline `style` attributes.

**Files.** `includes/orders.php`, `supplier_orders.php`, `shipments.php`, `ship_my_box.php`, `catalog.php` (badge functions only) · ~60 module pages (markup/class changes) · `assets/css/components.css`.

**Risk: Low–Medium.** Wide but shallow — many files, small changes each, no logic touched.

**Visual impact: High.** This is the phase where the app starts to look modern.

**Regression risk: Low.** Badge functions are pure display; enum values untouched. Hazard is a missed status falling to a default color — mitigated by asserting every value in §2.8's mapping table renders.

**Order: 2nd.**

---

### Phase 3 — Interaction & Density

**Objective.** Fix how the application *behaves*: confirmations, form controls, tables, and
feedback. **Re-scoped in Phase 2.6** — the ranking in §4 showed cross-cutting work outranks every
individual page except the two record pages, so Phase 3 is now organised by *system*, not by page.

**Scope, in implementation order.** Each sub-phase is one commit followed by a `verify` run.

| # | Sub-phase | Scale | Ref |
|---|---|---|---|
| **3.1** | **Form control system.** All seven states in one unit — resting, hover, focus, invalid, readonly, disabled, placeholder. Introduces `--border-input` `#848890`, fixes placeholder contrast, makes focus carry on the border rather than the ring. | app-wide CSS | §2.11.1, §8.4 |
| **3.2** | **Confirmation dialog.** Replace all 48 native `confirm()`, destructive actions first, one module at a time. | 48 sites | §2.13 |
| **3.3** | **Table system.** Header/spacing/hover/selected/alignment, 8-column cap, `.responsive-stack-table` as default. | 43 of 61 table pages | §2.10 |
| **3.4** | **Pagination.** One component, applied to list pages that `LIMIT` without it. | 38 pages | §2.13 |
| **3.5** | **Loading & pending states.** Generalised from `drawer.js`'s existing markup — button pending, skeletons, progress on long syncs and imports. | app-wide | §2.13 |
| **3.6** | **`.page-header` rollout + `.filter-card` active-filter chips.** | 41 + 24 pages | §2.12 |
| **3.7** | **Widest tables** — `reports/sales.php` (26), `customers/view.php` (24), `suppliers/view.php` (22), `purchasing/control-center.php` (21), `purchasing/index.php` (20). Column caps and drill-down; reports stay exempt from the cap but must stay scannable. | 5 pages | §4.3, §7 |
| **3.8** | **Empty states, item-picker merge, the 99 inline `style=` attributes.** | long tail | §3 |

**Removed from Phase 3 by the §4 re-ranking.** `inventory/index.php`, `orders/index.php`,
`shipments/index.php`, and `ship-my-box/index.php` were listed here to "reduce competing
primaries." That was the corrected artifact (§6A.3) — they have 1–3 real CTAs, and Phase 2.3
already resolved the underlying colour overloading. They now receive ordinary list-page
standardisation through 3.3, 3.4, and 3.6 like every other list.

**Files.** `assets/css/` (form states, table system) · `assets/js/` (new confirm + loading;
`product-form.js`'s native `alert`/`confirm` converted) · ~50 pages with tables · 41 pages for
`.page-header` · `modules/{orders,supplier-orders}/_item_picker_modal.php`.

**Risk: Medium.** Touches destructive action paths and changes how many rows a list shows.

**Visual impact: High.** Every form control in the app changes weight (3.1), and on mobile 43
tables stop scrolling sideways (3.3).

**Regression risk: Medium — the highest of any phase except 4.** Three specific hazards:
(a) a converted `confirm()` must still submit the identical form to the identical action — a
broken conversion could block a legitimate action or, worse, allow a destructive one without
confirmation; (b) adding pagination to a previously unpaginated list changes what the operator
sees by default; (c) 3.1 restyles every input in the application at once, so a mistake there is
visible everywhere rather than on one page. Mitigations: convert confirmations one module at a
time with each form's `action`/`method`/`name` diffed before and after; verify paginated totals
match pre-change row counts; run `verify` after every sub-phase, not only at the end of the phase.

**Order: 3rd.** 3.1 goes first because §2.11.1's states are a single interlocking unit and every
later sub-phase renders controls on top of them.

---

### Phase 4 — Structure

**Objective.** Restructure the pages whose information architecture — not styling — is the problem.

**Scope.**
- `orders/view.php`: two-column + tabs; **merge the four adjacent payment sections into one Payment card**; move Timeline / Inventory Activity / Resolutions / Edit History to tabs.
- `supplier-orders/view.php`: one primary (workflow next-step); receiving focused in the Items card; Costs + Payments merged into a Financials tab; Cancel/Delete to overflow.
- `products/_form.php`: section navigation (Basics · Pricing · Inventory · Variations · Media · Suppliers · SEO), single sticky Save. All 71 fields retained with identical names.
- Sidebar 8 → 5 sections, collapsible, `$navActive()` fix.
- Breadcrumbs on deep detail pages; remove redundant "Back to X".
- Remaining detail pages (`customers/view.php`, `suppliers/view.php`, `products/view.php`), reports, settings, `woocommerce.php`.
- Toast component.

**Files.** `modules/orders/view.php` · `modules/supplier-orders/view.php` · `modules/products/_form.php` · `includes/header.php` (sidebar) · remaining detail/report/settings pages.

**Risk: High — the highest in V3.** `orders/view.php` and `supplier-orders/view.php` contain 23 forms between them.

**Visual impact: Very high** on the two most-used record pages.

**Regression risk: High.** Moving a form inside a tab must not change its `action`, `method`, field `name`s, CSRF token, or permission gate. A field that stops being submitted because it moved into an inactive tab is the specific failure mode to guard against — hidden tabs must remain in the DOM and inside the same `<form>`, not lazily rendered.

**Order: 4th — last, deliberately.** By this point the token layer, components, and confirmation patterns are proven, so restructuring is the only variable.

---

### Phase summary

| Phase | Objective | Risk | Visual impact | Regression risk |
|---|---|---|---|---|
| 1 — Foundation | CSS extraction + tokens | Low | Minimal (intentional) | Low |
| 2 — Visual Language | Badges, buttons, type, headers | Low–Med | High | Low |
| 3 — Interaction & Density | Form controls, confirmations, tables, pagination, feedback | Medium | High | **Medium** |
| 4 — Structure | Record pages, forms, navigation | **High** | Very high | **High** |

---

## 6. Optional Add-On — Drawer Adoption *(separate approval)*

`drawer.js` is complete and unused. Adopting it (list → open record without losing filter context) is the single largest click-reduction available in V3 and would shorten workflows #1 and #3 in `MEWMII_OS_V2_PLAN.md` §2.

It is **excluded from the four phases** because it requires a `?panel=1` conditional in each `view.php` — a request-handling change, which sits outside "visual-only." I am not proposing it under V3's current constraints; it is flagged so the decision is explicit rather than accidental. If approved separately, it fits naturally after Phase 3.

---

## 6A. Phase 2 Outcomes and Lessons

Recorded in Phase 2.6. Phase 2 ran as six commits: status tokens → badge migration → button
tokenisation → primary/filter decisions → typography → legacy colour cleanup.

### 6A.1 What was delivered

**Status semantics unified.** Ten badge helpers each chose their own Bootstrap colour, so the
same business status rendered differently depending on the page — `shipped` appeared in four
different colours, `waiting_stock` in two, and `received` (a success step) shared amber with
`waiting_stock` (a blocked one). All ten now render through one helper on a five-token scale.
The rule settled on is **one colour per enum value per column**, not one colour per English
word: `ship_requests.status = 'pending'` stays amber because it means "staff must review",
while `mewmii_orders.order_status = 'pending'` is neutral because it means "nothing to do yet".

**Buttons separated into roles.** `btn-primary` had been carrying four unrelated meanings at
once — page CTA, active filter, form submit, bulk action. Selection now has its own treatment
(`.btn-filter` / `.is-active`), and brand pink means "action" and nothing else. Twelve variants
plus a new `.btn-ghost` moved off stock Bootstrap.

**Typography hierarchy fixed.** The application contained **no `<h1>` at all** — every page title
was an `<h2>` and headings then jumped to `<h5>`. 79 titles were promoted; the type scale is
applied from tokens; money and quantity columns use tabular figures.

**Tokens normalised.** 60 tokens, and **no hardcoded colour, radius, or type value survives
outside `tokens.css`**. Three legacy tokens retired, two pink-tinted greys neutralised.

### 6A.2 Accessibility fixed along the way

None of these were in the original audit; all were found by measuring rather than looking.

| Issue | Was | Now | Fixed in |
|---|---|---|---|
| Primary CTA label — the app's most-clicked control | **2.04:1** | 7.82:1 | 2.3 |
| `--danger-accent` as stat text | **4.12:1** | 7.12:1 (retired) | 2.5 |
| `neutral` badge at 12px | **4.47:1** | 4.81:1 | 2.1 |
| No `<h1>` anywhere | 0 pages | every page | 2.4 |

### 6A.3 Lessons that change how the rest of V3 is run

1. **A CSS class is not a semantic role.** The "9 competing primary buttons" finding was a grep
   artifact that conflated filter pills, form submits, and real CTAs, and it reached the impact
   ranking unchallenged. Every future count of `btn-*`, `badge bg-*`, `<h5>`, or `<form>` is an
   **upper bound**, not a finding, until each instance is checked against what it does.
2. **Measure contrast, never eyeball it.** Four failures were found by computing ratios; three
   would have shipped otherwise, and one had been live for the entire life of the application.
3. **Fix accessibility while a token is still unreferenced.** `--neutral` was corrected for free
   in 2.1. Had the same defect been found in 2.2, it would have meant changing a colour already
   live on every list page.
4. **Verify where the work happens.** Snapshots were repeatedly lost between capture and compare,
   costing two phases their verification, until `verify` collapsed both halves into one run.
   Config was the wrong layer; the workflow was the problem.
5. **Zero-visual-change phases are worth their commit.** Phase 1 and 2.1 changed nothing visible,
   which made them provable — and made every later diff interpretable.

---

## 7. Documented Exceptions to the Standard

Per your direction, these pages legitimately differ. They are exceptions by design, not compliance failures.

| Page / group | Exception | Why |
|---|---|---|
| `index.php` (Dashboard) | No `.page-header`; own layout | Governed by `DASHBOARD_PHILOSOPHY.md`. V3 changes presentation only. |
| `reports/*` | **Exempt from the 8-column cap** | Analysis is the purpose; density is a feature. Must still be scannable — alignment, tabular numerics, sticky header. |
| `products/_form.php` | Exempt from the 720px single-column form | 71 fields require section navigation. |
| `catalog/tabs/*` (5 pages) | Tabbed sub-app, not standard list pages | ~400 lines each, near-identical taxonomy CRUD. Merging them is real consolidation but is **logic refactoring, not visual** — out of V3 scope. Flagged for a future phase. |
| `settings/system_health.php` | Diagnostic layout | Not an operational list. |
| `settings/reset_test_data.php` | Diagnostic layout; no `.page-header` | Administrative maintenance utility behind a typed-phrase gate, not a normal application page. Treated consistently with System Health. *(Added Phase 3.6a-1.)* |
| `resolution.php` | Outside the admin page standard | Root-level customer-facing resolution page, not part of the admin module experience. To be reviewed separately before any admin standard is applied. *(Added Phase 3.6a-1.)* |
| `*/import.php` (5 pages) | Step/wizard pattern | Upload → map → preview → commit is its own flow. Standardize the wizard across all five instead of forcing the list standard. |
| `login.php`, `install.php` | Standalone, no sidebar | Pre-authentication. |
| `inventory/allocate.php`, `reserve.php` | Keep "Back to" instead of breadcrumb | Task-focused, modal-like in intent. |

---

## 8. Risks & Implementation Strategy

### 8.1 Principal risk: no automated verification

**This project has no test suite** — no `tests/`, no `composer.json`, no PHPUnit — and no PHP binary is available in this environment, so pages cannot currently be linted or executed here. Every phase depends entirely on manual verification.

Phase 4 restructures two pages containing 23 forms. **I recommend a minimal smoke harness before Phase 4** — load every page, assert HTTP 200, exactly one `<h1>`, and no PHP notice — plus a form-attribute snapshot (every `<form>`'s `action`/`method` and every field `name`, before and after) diffed automatically. This is test tooling, not application code, so it doesn't breach the visual-only constraint. It converts Phase 4's highest risk from "hope we caught it in review" to a mechanical diff.

This is a scheduling decision, not a detail — it should be settled before Phase 3 begins.

### 8.2 Risk register

| Risk | Phase | Severity | Mitigation |
|---|---|---:|---|
| Converted `confirm()` breaks or silently skips a destructive action | 3 | **High** | Convert per-module; diff form `action`/`method`/`name` before+after; destructive actions converted first and reviewed individually |
| A form field stops submitting after moving into a tab | 4 | **High** | Hidden tabs stay in the DOM inside the same `<form>`; never lazy-render form content |
| A status value falls through to a default color | 2 | Medium | Assert every value in §2.8's table renders with its assigned token |
| Pagination changes what an operator sees by default | 3 | Medium | Verify paginated totals equal pre-change row counts; default 25 operational |
| CSS extraction drops or reorders a rule | 1 | Medium | Two commits: verbatim move, then tokenize |
| An action becomes unreachable after moving to overflow | 2, 3 | Medium | Per-phase action inventory — the V2 U2 before/after audit, repeated |
| Permission gate lost during markup restructuring | 2, 3, 4 | **High** | Diff every `app_has_permission()` / `app_require_permission()` call site per phase; count must be identical |
| Scope creep from visual → logic | All | Medium | §7 exceptions documented up front; catalog-tab merge explicitly deferred |

### 8.3 Rules binding every phase

1. **No** database, schema, business logic, routing, permission, or workflow change.
2. Form `action`, `method`, and field `name` attributes are preserved **exactly**.
3. Status enum values are never changed — only their display mapping.
4. Every action stays reachable. Reducing visual prominence is allowed; removing access is not.
5. Structural moves and visual changes never share a commit.
6. Each phase ends with the V2 U2 audit format: files changed · actions moved/removed · remaining access paths · permission diff.
7. Any change that turns out to require logic modification **stops and returns for approval** rather than proceeding.

### 8.4 DECIDED — input border contrast (Option A, delivered in Phase 3)

**Status: resolved in Phase 2.6 review. Option A approved.** The border system is raised to meet
WCAG 1.4.11, and — per the approval — this is **not** a standalone Phase 2 change. It ships inside
the Phase 3 form system as one unit together with the resting, hover, focus, validation, disabled,
and readonly states, so the control never exists in a half-restyled state. The full specification
with measured values is **§2.11.1**.

Two refinements emerged while specifying it, both recorded there:

- The token is **`#848890`, not the `#8C9196`** named in the option. `#8C9196` passes on white
  (3.18:1) but fails on the grey fill a readonly control uses (2.94:1) — it would have been
  compliant on some form controls and not others.
- Two further failures surfaced in the same measurement pass: **placeholder text** at 3.18:1
  (text needs 4.5:1) and the **focus ring** at 1.22:1 (so focus must be carried by the border
  colour change, not the glow). Both are folded into the same phase.

The original analysis follows, retained as the record of why Option A was chosen.

---

WCAG 1.4.11 requires **3:1** contrast for the visual boundary that identifies a UI component. A
text input's border is exactly that. Measured against the white card surface:

| Token | Ratio | |
|---|---:|---|
| `--border-subtle` `#EDEDEE` | 1.17:1 | fails |
| `--border` `#E3E3E3` | 1.28:1 | fails |
| `--border-strong` `#DCDCDE` — **used on every input and button** | **1.37:1** | fails |
| `#949499` | 3.02:1 | passes |
| `#8C9196` (already defined as `--text-subtle`) | 3.18:1 | passes |

Every border in the application currently fails, and always has — this is pre-existing, not
introduced by V3. Phase 2.5 neutralised the pink cast and fixed the ramp ordering but
deliberately did not change the weight, because reaching 3:1 puts a visibly medium-grey edge on
every input in the app. That is a look-and-feel decision, not a cleanup.

The options:

| Option | Effect |
|---|---|
| **A. Raise `--border-strong` to `#8C9196`** | Compliant. Inputs become clearly bounded; the UI reads heavier and less airy than Shopify/Linear. |
| **B. Raise only on `:focus` / `:invalid`** | Cheap, but does not meet 1.4.11 — the resting state is what identifies the control. |
| **C. Keep the light border, add a filled input background** | Compliant via a different signal (fill rather than edge); a larger visual change than A. |
| **D. Accept the gap and document it** | No visual change; the app knowingly fails 1.4.11 on every form control. |

No option is free, which is why this was raised rather than decided.

**Outcome:** Option A was chosen at the Phase 2.6 review, with the implementation deferred into
the Phase 3 form system rather than taken as a standalone Phase 2 change — see the top of this
section and §2.11.1.

### 8.5 Sequencing rationale

Phases run strictly in order. Phase 1 is a prerequisite for everything. Phase 2 must precede 3 so tables and confirmations are built on settled tokens. Phase 3 must precede 4 so the highest-risk restructuring happens against components already proven in production. Each phase is independently shippable and independently revertable.
