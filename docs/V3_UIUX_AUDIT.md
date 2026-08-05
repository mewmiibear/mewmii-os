# Mewmii OS V3 — UI/UX Modernization Audit & Design Specification

**Status:** Audit + design only. No implementation. Awaiting approval.
**Scope:** Presentation layer only. No DB, schema, permissions, business logic, routing, or API changes.
**Governing documents this extends (does not replace):** `MEWMII_OS_V2_PLAN.md`, `DASHBOARD_PHILOSOPHY.md`, `COMPONENT_LIBRARY_SPEC.md`.

---

## 1. Executive Summary

Mewmii OS is **not** a badly designed application. Its architecture is sound, its workflows are coherent, and a real design vocabulary already exists in `includes/header.php` — `.page-header`, `.filter-card`, `.stat-card`, `.empty-state`, `.attention-item`, `.responsive-stack-table`, `.action-bar`. The V2 work established these deliberately and documented why.

**The core V3 finding is that the design system exists but is unenforced.** Roughly half the application ignores it and falls back to raw Bootstrap. This produces the "outdated" feeling far more than any individual page's styling does.

Evidence, measured across 236 PHP files / ~120 user-facing pages / 28 modules:

| Standard | Compliant | Non-compliant | Rate |
|---|---:|---:|---:|
| `.page-header` on pages with a page title | 34 | 42 | **45%** |
| `.responsive-stack-table` on pages with tables | 18 | 43 | **30%** |
| `.empty-state` vs. raw "No X yet" text rows | 41 | 47 | **47%** |
| Pagination on pages that `LIMIT` results | 11 | 38 | **22%** |
| Modal confirmation vs. native `confirm()` | 9 | 48 | **16%** |
| Loading states anywhere in PHP | 0 | — | **0%** |

The second finding is a **broken color contract**. `header.php` overrides `.btn-primary` and `.alert-*` with the soft Mewmii palette, but never overrides `.badge bg-*` or `.btn-success/danger/warning`. Saturated stock Bootstrap colors therefore render directly beside the custom soft palette on the same screen. `badge bg-primary` resolves to Bootstrap's `#0d6efd` — a **third blue** that appears nowhere in the token block.

The third finding is **no action hierarchy**. 366 uses of `btn-sm` against 4 of `btn-lg` means effectively every control is the same size. Seven pages carry four or more `btn-primary` elements, so "the primary action" is not visually identifiable on the screens where it matters most.

**V3's job is therefore consolidation and enforcement, not reinvention.** Most of this work is applying patterns that already exist and are already documented, plus three genuinely new primitives (semantic status tokens, a confirmation modal, and loading/skeleton states). This aligns directly with `MEWMII_OS_V2_PLAN.md` §2: *"A change that makes a page visually cleaner but doesn't reduce clicks has not achieved the goal."*

---

## 2. Global UI/UX Problems

### 2.1 CSS architecture

- **484 lines of CSS live inline in `includes/header.php`**, which is a 33KB PHP file mixing tokens, components, layout, responsive rules, and page markup.
- **`assets/css/style.css` is 0 bytes.** The intended stylesheet was never populated.
- **99 inline `style="..."` attributes** across module pages bypass the system entirely (e.g. `style="font-size: 0.55rem;"` on a close button in `supplier-orders/view.php`).
- Consequence: no caching of styles, no way to review design changes in isolation, and every new page has an incentive to inline rather than extend.

### 2.2 Color and status semantics

Ten separate badge functions map status → color with **no shared scale**:

| Function | Notable mapping |
|---|---|
| `order_status_badge()` | `ready_to_ship` → primary, `shipped` → dark, `waiting_stock` → warning |
| `supplier_order_status_badge()` | `partially_received` → primary, **`received` → warning** |
| `payment_status_badge()` | `paid` → success, `refunded` → info |
| plus `shipment_`, `ship_request_`, `order_item_fulfillment_`, `order_receipt_`, `order_source_`, `catalog_lifecycle_`, `supplier_order_payment_` | — |

Two concrete semantic collisions:

1. **`warning` means both "blocked" and "progressing."** `waiting_stock` (a problem) and `received` (a success step) render in the same amber. An operator cannot learn the color language.
2. **`primary` is used as a status color.** `header.php`'s own token comment states brand pink is *"reserved for primary actions/active states."* Using `primary` for `ready_to_ship`/`partially_received` breaks that contract — and because `.bg-primary` is un-overridden, it renders as stock Bootstrap blue rather than pink, so the app displays three blues: `#0d6efd`, `--mewmii-blue #3472EF`, and `--sky-blue #85D2FF`.

### 2.3 Action hierarchy

- 366 × `btn-sm`, 4 × `btn-lg` — no meaningful size scale.
- 133 × `btn-outline-secondary` is the single most common button, so the default visual weight of an action is "de-emphasized."
- Pages with 4+ competing `btn-primary`:

  | Page | `btn-primary` count |
  |---|---:|
  | `modules/inventory/index.php` | 9 |
  | `modules/products/_form.php` | 8 |
  | `modules/ship-my-box/index.php` | 7 |
  | `modules/orders/index.php` | 7 |
  | `modules/supplier-orders/view.php` | 6 |
  | `modules/shipments/index.php` | 5 |
  | `modules/products/index.php` | 4 |

- No overflow-menu convention. The dropdown added in V2 U2 on `supplier-orders/index.php` is currently the **only** one in the codebase.

### 2.4 Density and hierarchy on record pages

- `modules/orders/view.php` — **16 stacked `<h5>` card sections and 12 separate `<form>` elements.** Four of those sections are payment-related and adjacent: *Receipt*, *Receipt Verification*, *Manual Payment Status*, *Payment Status*.
- `modules/supplier-orders/view.php` — 10 sections, 11 forms, 6 primary buttons, 1,400+ lines.
- `modules/products/_form.php` — **71 form fields on a single page**, no step/section navigation.
- Every section is a flat, equally-weighted card, so nothing indicates what matters most.

### 2.5 Tables

- 61 pages contain tables; **17 have 9 or more columns and none of those stack on mobile.**

  | Page | Columns |
  |---|---:|
  | `reports/sales.php` | 26 |
  | `customers/view.php` | 24 |
  | `suppliers/view.php` | 22 |
  | `purchasing/control-center.php` | 21 |
  | `integrations/woocommerce.php` | 20 |

- 50 pages use `table-responsive` (horizontal scroll), which on a phone means a 26-column table is scrolled sideways rather than reflowed.
- No column alignment convention — numeric columns are not consistently right-aligned.
- **Only 11 of 49 list pages paginate.**

### 2.6 Feedback, confirmation, and loading

- **48 native browser `confirm()` dialogs.** These are unstyled, unbrandable, cannot show record context, and cannot distinguish a destructive action from a routine one. `supplier-orders/view.php` alone uses them for Cancel Order, Delete, Reverse Receipt, Remove Cost, Mark Arrived, and Delete Payment — a reversible workflow step and a permanent deletion get identical treatment.
- **Zero loading states in PHP; one spinner across all JS.** Long operations (WooCommerce sync, imports, batch receive) give no progress feedback.
- Success/error feedback is via `?flag=1` query params rendering an `.alert` (117 danger / 100 success). No toast pattern, so feedback is tied to a full page reload.

### 2.7 Navigation

- 37 sidebar links across 8 sections, all flat and always expanded — no collapse, no persistence of state.
- `MEWMII_OS_V2_PLAN.md` §4 Phase 1 called for consolidating to **5 sections**; the sidebar currently has 8 (Catalog, Sales, Reports, Operations, Fulfilment, Finance, Integrations, System).
- `$navActive()` prefix-matching highlights a parent and its child simultaneously (Inventory + Reservation Center both active).
- Icons are inconsistent: only 11 of 76 page titles carry an icon; sidebar sub-links have none while top-level links do.

### 2.8 Responsive

- Mobile drawer works below 992px (V2 work — solid).
- But: 43 tables scroll horizontally, forms are single-column-only with no tablet optimization, and the dashboard shortcut grid uses `col-md-4 col-lg-2`, producing 6 cramped tiles on a laptop.

---

## 3. Design System Specification

### 3.1 Foundations

**Extraction rule:** move all CSS out of `includes/header.php` into `assets/css/` as `tokens.css`, `base.css`, `components.css`, `utilities.css`. `header.php` links them. No visual change in the same commit as the move — extraction and restyling must never be mixed.

#### Color tokens

Retain the existing brand identity; add the missing semantic scale.

```
/* Brand — actions and active states ONLY. Never a status. */
--brand:            #FF94C4   (existing --mewmii-pink)
--brand-hover:      #F97DB7
--brand-tint:       #FFF1F7

/* Neutrals */
--bg:               #F6F6F7
--surface:          #FFFFFF
--border:           #E3E3E3
--border-strong:    #CFCFD2
--text:             #202223
--text-muted:       #6B6F76
--text-subtle:      #8C9196

/* Semantic status scale — NEW. One meaning per color, app-wide. */
--info:    #1D57B0  on  --info-bg    #EAF2FF   /* in progress, informational */
--success: #1F7A43  on  --success-bg #E6F6EC   /* done, healthy, complete */
--warning: #8A6116  on  --warning-bg #FFF6E5   /* needs attention, blocked */
--danger:  #A32458  on  --danger-bg  #FDEAF1   /* failed, cancelled, error */
--neutral: #6B6F76  on  --neutral-bg #F1F1F2   /* draft, inactive, none */
```

The `--info/--success/--warning/--danger` foreground+background pairs are already proven — they are the exact values `header.php` uses for `.alert-*` today. V3 extends them to badges and buttons so the entire app shares one palette. `--sky-blue` and `--berry-rose` are retired.

**Required override:** `.badge.bg-*`, `.btn-success`, `.btn-danger`, `.btn-warning` must be restyled to these tokens so stock Bootstrap colors stop leaking.

#### Status semantics — one rule, all ten badge functions

| Token | Meaning | Applies to |
|---|---|---|
| `neutral` | Not started / inactive | `draft`, `pending`, `inactive` |
| `info` | In progress, no action needed now | `processing`, `ordered`, `shipping`, `partially_received`, `partially_fulfilled`, `refunded` |
| `warning` | **Blocked — operator action required** | `waiting_stock`, `waiting_ship_my_box`, `waiting_payment`, `failed_verification` |
| `success` | Complete / healthy | `completed`, `received`, `paid`, `delivered` |
| `danger` | Failed / cancelled | `cancelled`, `failed` |
| `brand` | **Never used for status** | — |

This reassigns `received` from warning → success and `ready_to_ship`/`partially_*` from primary → info/success, resolving both collisions in §2.2. **Status enum values are untouched — this is display mapping only.**

#### Typography

Single family: system stack (`-apple-system, "Segoe UI", Roboto, sans-serif`). No web font — it would cost a network request against `DASHBOARD_PHILOSOPHY.md` §2.7.

| Role | Size | Weight | Use |
|---|---|---|---|
| Page title | 24px / 1.25 | 700 | One `<h1>` per page (currently `<h2>` — see §6) |
| Section title | 16px / 1.4 | 600 | Card headings (currently `<h5>`) |
| Body | 14px / 1.5 | 400 | Default |
| Secondary | 13px / 1.5 | 400 | `--text-muted` |
| Label / overline | 12px / 1.4 | 600, 0.02em, uppercase | Form labels, table headers, stat labels |
| Numeric | tabular-nums | — | All money/quantity columns |

#### Spacing, radius, shadow

4px base scale: `4, 8, 12, 16, 24, 32, 48`. Radius: `6px` controls, `10px` cards, `999px` badges. Shadows: only two — `--shadow-sm` `0 1px 2px rgba(0,0,0,.05)` for resting cards, `--shadow-md` `0 4px 12px rgba(0,0,0,.08)` for overlays. No third shadow.

### 3.2 Components

**Page header** — mandatory on every page. `<h1>` title, optional one-line description, `.action-bar` right-aligned. Breadcrumb only on detail pages nested two or more levels deep. Stacks vertically below 576px (rule already exists).

**Buttons** — a strict hierarchy, one meaning each:

| Variant | Appearance | Rule |
|---|---|---|
| Primary | Solid brand pink | **Exactly one per page.** The single most likely next action. |
| Secondary | Outlined neutral | Supporting actions, unlimited |
| Subtle | Text only | Tertiary / inline |
| Destructive | Outlined `--danger`; solid only inside a confirm modal | Never placed adjacent to primary |
| Overflow | `⋯` icon → dropdown | Anything rare, destructive, or beyond the 3rd action |

Sizes: `sm` (28px) inside table rows only; `md` (36px) everywhere else — replacing today's blanket `btn-sm`.

**Action bar rule:** maximum **3 visible buttons**; everything else goes into overflow.

**Cards** — `--surface`, 1px `--border`, 10px radius, `--shadow-sm`, 24px padding. Section title 16px/600. **No nested cards.** Cards do not carry their own colored headers.

**Tables** — header row `--text-muted` 12px uppercase; 12px/16px cell padding; numeric right-aligned + tabular figures; row hover `--bg`; a single trailing actions column (max 2 inline buttons, rest in overflow). **Hard cap: 8 visible columns**, with any excess moved to the detail page or a column-picker. `.responsive-stack-table` becomes mandatory, not opt-in. Every table needs an `.empty-state`, and every table over 25 rows needs pagination.

**Forms** — max-width 720px for single-column; two-column only above 992px for genuinely paired fields. Labels above inputs, 12px/600. Help text 13px `--text-muted` below. Errors inline beneath the field in `--danger` plus a summary alert at top. Any form over ~20 fields must use section navigation (see §8 for `products/_form.php`). Sticky footer action bar on long forms.

**Filter bar** — the existing `.filter-card`, standardized: inline row of controls, "Clear all" when any filter is active, and **active filters shown as removable chips**. Compliance is already good (24 of 25 GET-form pages).

**Status badges** — 12px, 600, `999px` radius, tinted background + dark foreground from the §3.1 scale. Text is always the human label, never a raw enum.

**Alerts vs. toasts** — inline `.alert` for persistent page-level state (validation errors, warnings). **New:** toast, top-right, auto-dismissing, for transient confirmation of an action — replacing the `?created=1` → full-reload → alert pattern where a reload isn't otherwise needed.

**Modals** — one width scale (`sm` 400 / `md` 560 / `lg` 800). Header with title + close, body, footer with cancel (secondary, left) and confirm (primary or destructive, right).

**Confirmation dialog — NEW, replaces all 48 `confirm()` calls.** Three tiers:

| Tier | Pattern | Example |
|---|---|---|
| Routine reversible | No confirmation; toast with **Undo** where feasible | Mark note read |
| Significant | Modal, states exact consequence + record identifier | Reverse Receipt, Mark Arrived |
| Destructive/irreversible | Modal, destructive button, consequence spelled out | Delete supplier order, Delete payment |

**Empty states** — the existing `.empty-state`, applied everywhere: icon, title, one explanatory line, at most one CTA. Distinguish *"nothing exists yet"* (offer the create CTA) from *"no results for this filter"* (offer Clear filters). The 47 raw "No X yet" rows all convert.

**Loading states — NEW.**
- Full page: normal server render, no skeleton needed.
- Async region (drawer, quick view): skeleton rows.
- Button-triggered action: button enters disabled + inline spinner, retaining its width.
- Long operation (sync, import): progress region with a determinate count where available.

**Pagination** — one component: `Showing X–Y of Z`, prev/next, page numbers when ≤ 7 pages. Default 25 rows for operational lists, 50 for reference lists. Applied to all 38 currently-unpaginated list pages.

**Icons** — Bootstrap Icons (already in use, 40 distinct). Rules: sidebar top-level links **yes**, sub-links **no** (current behavior — formalize it); page titles **never** (removes the 11/76 inconsistency); buttons only when the icon is the sole content (overflow `⋯`, close `×`). One icon per concept, app-wide.

### 3.3 Navigation, dashboard, and layout

**Sidebar** — consolidate 8 sections → **5**, honoring the V2 Phase 1 commitment:

| Section | Contents |
|---|---|
| Overview | Dashboard, Notifications |
| Sales | Orders, Customers, Customer Storage, Ship My Box, Shipments |
| Operations | Inventory, Reservation Center, Allocation Center, Purchasing, Purchase Planning, Suppliers, Supplier Orders |
| Insights | All Reports |
| Settings | Integrations, Sync Logs, Webhooks, Job Queue, System Health, Settings |

Collapsible sections with persisted state; fix `$navActive()` so a parent and child are not both highlighted; keep the existing 992px drawer behavior unchanged.

**Dashboard** — governed by `DASHBOARD_PHILOSOPHY.md`; V3 changes **presentation only**. Shortcut tiles move from `col-lg-2` (6 cramped tiles) to a 3–4 column responsive grid; My Day tasks gain consistent verb-first phrasing and count chips.

**Layout** — page max-width `1440px`; content padding 32px desktop / 16px mobile. Breakpoints stay Bootstrap's (`576 / 768 / 992 / 1200`) — no new breakpoints.

---

## 4. Page-by-Page Recommendations

Grouped by pattern. Every page inherits §3; only page-specific work is listed.

### 4.1 List pages — Products, Orders, Inventory, Supplier Orders, Customers, Suppliers, Shipments, Ship My Box, Customer Storage, Finance, Notifications, Sync Logs, Job Queue, Webhooks, Catalog tabs

Uniform treatment: standard `.page-header`; action bar reduced to ≤3 with overflow; `.filter-card` with active-filter chips; column cap of 8; `.responsive-stack-table` mandatory; pagination added; `.empty-state` for both empty and filtered-empty.

Page-specific:
- **`inventory/index.php`** — 9 primary buttons is the app's worst offender. Reduce to one (Adjust Stock); the quick-filter pill row (Need Ordering / Waiting Supplier / Low Stock) becomes a filter-chip group rather than buttons.
- **`orders/index.php`** — 7 primary buttons → 1 (Create Order); bulk actions move into a selection toolbar that appears only when rows are checked.
- **`products/index.php`** — 14 columns → 8; 6 forms consolidated.
- **`ship-my-box/index.php`** / **`shipments/index.php`** — 7 and 5 primary buttons → 1 each.
- **`purchasing/index.php`** and **`purchasing/control-center.php`** — 20 and 21 columns; these are the two densest operational tables and need the column cap most.

### 4.2 Record/detail pages — the biggest wins

**`modules/orders/view.php` (16 sections, 12 forms) — highest-impact page in the app.**
Restructure to a two-column layout with tabs, without moving any action to a different system:
- Left (primary): Summary → Items → Fulfillment.
- Right (context rail): Customer, Payment, Shipments.
- **Merge the four adjacent payment sections** (Receipt, Receipt Verification, Manual Payment Status, Payment Status) into a single *Payment* card with one status line and one action group. This is the single largest clutter reduction available.
- Move Timeline, Inventory Activity, Resolutions, and Edit History into tabs — they are reference, not action.

**`modules/supplier-orders/view.php` (10 sections, 11 forms, 6 primary).**
- One primary action only: the workflow next-step button (Mark Arrived / next action).
- Receiving becomes the focus of the Items card; Fill Remaining / Clear demoted to subtle buttons; Receive Entered is the section's own primary.
- Costs, Payments, Receiving History, Edit History → tabs.
- Cancel and Delete → overflow menu, out of the header row.
- Reverse Receipt keeps its modal (already correct) and gains the standard destructive/significant treatment.

**`modules/products/view.php`**, **`customers/view.php`** (24 cols), **`suppliers/view.php`** (22 cols) — same two-column + tabs treatment; column caps applied.

### 4.3 Form pages

**`modules/products/_form.php` — 71 fields, 8 primary buttons.** Introduce section navigation (a left rail or tab set): *Basics · Pricing · Inventory · Variations · Media · Suppliers · SEO*. Single sticky Save. No field is removed, reordered across sections arbitrarily, or rewired — this is grouping and navigation only.

Other forms (`orders/create.php` 6 fields, `supplier-orders/create.php` 10, `customers/create.php` 8) are already reasonably sized and need only the standard form treatment plus a `.page-header`.

### 4.4 Report pages

`reports/sales.php` (26 columns) is the widest table in the app. Reports get: a consistent filter bar, column caps with drill-down to detail, right-aligned tabular numerics, and export placed in overflow. `DASHBOARD_PHILOSOPHY.md` already assigns trend analysis here, so reports may stay dense — but they must stay *scannable*.

### 4.5 Settings & system pages

`settings/*` (11 pages), `integrations/woocommerce.php` (7 sections, 20 columns, 6 forms), `system_health.php`. These are the least-standardized area — most lack `.page-header` entirely. Treatment: standard header, one card per settings group, consistent save behavior, and a real progress state for the WooCommerce sync operations.

---

## 5. Priority List

Priority follows `MEWMII_OS_V2_PLAN.md` §2: does it shorten one of the four core workflows, or is it orthogonal?

### Critical — do first
1. **Extract CSS** to `assets/css/` (no visual change). Unblocks everything else.
2. **Semantic status token scale** + override `.badge.bg-*` and the un-overridden button variants. Fixes the `warning`-means-two-things collision and the third-blue leak.
3. **Standardize the ten badge functions** onto that scale.
4. **Button hierarchy + action-bar cap of 3** on the seven pages with 4+ primaries.

### High
5. **Confirmation modal component**, replacing 48 `confirm()` calls — destructive actions first.
6. **`orders/view.php` restructure**, including the four-payment-section merge.
7. **`supplier-orders/view.php` restructure** — directly shortens the receiving workflow.
8. **`.page-header` rollout** to the 42 non-compliant pages.
9. **Table standardization** — column caps, alignment, mandatory mobile stacking on the 17 wide tables.

### Medium
10. Pagination on the 38 unpaginated list pages.
11. `.empty-state` for the 47 raw text rows.
12. Loading/skeleton states + button pending states.
13. Sidebar consolidation to 5 sections; fix `$navActive()`.
14. `products/_form.php` section navigation.
15. Toast component.

### Low
16. Icon rationalization.
17. Report page density.
18. Settings page consistency.
19. Dashboard grid density.
20. Remove the 99 inline `style` attributes.

---

## 6. Components to Standardize

| Component | Current state | Target |
|---|---|---|
| Page header | 34/76 compliant | 100%, `<h1>`, no icon |
| Status badge | 10 functions, ad-hoc colors | One 5-token scale |
| Button | 366 `btn-sm`, no hierarchy | 5 variants, 2 sizes, 1 primary per page |
| Overflow menu | 1 instance | Standard `⋯` dropdown |
| Table | 61 pages, inconsistent | One spec, 8-col cap, mandatory stacking |
| Empty state | 41/88 | 100% |
| Pagination | 11/49 | 100% of list pages |
| Filter bar | 24/25 — good | + active-filter chips |
| Form | Inconsistent | One spec + section nav over 20 fields |
| Modal | 9 pages | One size scale |
| Confirmation | 48 native `confirm()` | 3-tier modal component |
| Loading | 0 | Skeleton + button pending + progress |
| Toast | Does not exist | New |
| Alert | Good (already tokenized) | Keep; scope to persistent state only |

---

## 7. Components to Remove or Merge

**Remove:**
- The inline `<style>` block in `header.php` (→ `assets/css/`).
- All 99 inline `style` attributes.
- All 48 native `confirm()` calls.
- `--sky-blue` and `--berry-rose` tokens (unused by the new scale).
- Stock Bootstrap color leakage via un-overridden `.bg-*`.

**Merge:**
- `orders/view.php`'s four payment sections → one Payment card.
- `supplier-orders/view.php`'s Costs + Payments → one Financials tab.
- Per-page bespoke table markup → one table component.
- Duplicate "Back to X" links → breadcrumb.
- `reports/*` filter markup → the shared `.filter-card`.

**Explicitly keep (already correct):**
`.filter-card`, `.stat-card`, `.attention-item`, `.empty-state`, `.responsive-stack-table`, the Drawer, the 992px sidebar drawer, and the alert color tokens. V3 extends these — it does not replace them.

---

## 8. Screens Needing the Biggest Redesign

Ranked by (clutter × operational frequency):

| # | Screen | Why |
|---|---|---|
| 1 | `orders/view.php` | 16 sections, 12 forms, 4 redundant payment sections. Highest-traffic record page. |
| 2 | `supplier-orders/view.php` | 10 sections, 11 forms, 6 primaries. Owns receiving + reverse receiving — core workflow #1. |
| 3 | `products/_form.php` | 71 fields, no navigation. |
| 4 | `inventory/index.php` | 9 primary buttons — worst action hierarchy in the app. |
| 5 | `purchasing/control-center.php` + `purchasing/index.php` | 21 and 20 columns, no mobile stacking. |
| 6 | `integrations/woocommerce.php` | 7 sections, 20 columns, 6 forms, no progress feedback on long syncs. |
| 7 | `reports/sales.php` | 26 columns — widest table in the app. |

**Workflow-level improvements (UI only — no business logic touched):**
- **Receiving:** keep the batch-receive form, but make it the Items card's focused action rather than one of six competing primaries. Fewer decisions per screen, same POST.
- **Reverse receiving:** already modal-based and correct; needs only the standard destructive treatment.
- **Reservation / Allocation Centers:** currently one `Auto Allocate` + one `Manual` button per row. Keep both, but make Auto the row primary and Manual subtle, and add row-level bulk selection so a multi-product queue is one submit instead of N. (Bulk actions are already specced in `COMPONENT_LIBRARY_SPEC.md` §3 and must call the same per-record functions.)
- **Purchase Planning:** 17 columns → cap at 8 with detail on demand; keep the single real generation action clearly primary.
- **Customer Storage / Ship My Box / Shipments:** standard list treatment; reduce 7 and 5 primaries to 1 each.

---

## 9. Suggested V3 Implementation Roadmap

> **⚠️ SUPERSEDED.** This ten-phase draft was consolidated into four phases in
> **`V3_DESIGN_SYSTEM.md` §5**, which is the authoritative roadmap. The table below is
> retained only as a record of the original decomposition. Sections 1–8 of this document
> remain current as the evidence base.

Every phase is independently shippable and reviewable. This deliberately mirrors the `audit → design → implement → review → document` cycle in `MEWMII_OS_V2_PLAN.md` §1, and the module order in its Phase 3.

| Phase | Scope | Risk | Verification |
|---|---|---|---|
| **V3.0 — CSS extraction** | Move 484 lines to `assets/css/`. **Zero visual change.** | Very low | Byte-level visual diff on a sample of pages |
| **V3.1 — Tokens & status scale** | Semantic tokens; override `.badge.bg-*` and button variants; restandardize the 10 badge functions | Low — display mapping only | Every status enum still renders, with correct new color |
| **V3.2 — Button & action hierarchy** | 5 variants, 2 sizes; overflow menu component; cap action bars at 3 on the 7 offender pages | Low | No action removed — every action still reachable |
| **V3.3 — Confirmation & feedback** | 3-tier confirm modal replacing 48 `confirm()`; toast; button pending states | Medium — touches destructive paths | Each converted action still POSTs identically and is still gated |
| **V3.4 — Page header & empty states** | `.page-header` on 42 pages; `.empty-state` on 47 rows | Low | Every page has exactly one `<h1>` |
| **V3.5 — Table system** | Column caps, alignment, mandatory stacking, pagination on 38 pages | Medium — pagination changes result sets shown | No row becomes unreachable; counts match pre-change totals |
| **V3.6 — Navigation** | 8 → 5 sidebar sections; collapsible; `$navActive()` fix | Low | Every link still present and permission-gated identically |
| **V3.7 — Record pages** | `orders/view.php`, then `supplier-orders/view.php` | **Highest** — most forms per page | Each of the 12 and 11 forms POSTs unchanged |
| **V3.8 — Forms** | `products/_form.php` section nav; standard form spec elsewhere | Medium | All 71 fields present, same names, same validation |
| **V3.9 — Long tail** | Reports, settings, integrations, icons, dashboard grid, inline styles | Low | — |

**Cross-cutting rules for every phase:**
1. No DB, schema, permission, business-logic, routing, or API change. Ever.
2. Never mix a structural move with a visual change in one commit.
3. Every form's `name` attributes, `action`, and `method` are preserved exactly.
4. Every action must remain reachable — reducing visual prominence is allowed, removing access is not.
5. Each phase ends with the same before/after audit used in V2 U2: actions inventoried, access paths confirmed, permissions diffed.

---

## Verification Note

**This project has no automated test suite** — no `tests/`, no `composer.json`, no PHPUnit — and no PHP binary is available in the current environment, so pages cannot be linted or executed here. Every phase above therefore relies on manual before/after verification, and that constraint should be factored into V3 scheduling. Standing up even a minimal smoke-test harness (load every page, assert HTTP 200 + one `<h1>`) before V3.7 would materially de-risk the record-page restructure, which is the highest-risk phase in this plan.
