# Phase 2 Component Readiness Review

**Status:** Audit complete. Design proposed below for approval — **no code has been written**. Per the user's explicit instruction, implementation does not begin until this design is reviewed and approved.
**Scope:** the shared Drawer framework (Step 1) and, more lightly, the Activity Feed's data source (Step 3), since the Drawer's AJAX-pattern decision determines how the Activity Feed loads too. Method: every claim below is grounded in the actual codebase (file:line), not the earlier `COMPONENT_LIBRARY_SPEC.md` draft — where this review's findings disagree with that draft, it's called out explicitly rather than silently overridden.

---

## 1. Existing modal implementation

Bootstrap's JS bundle (`bootstrap.bundle.min.js`, 5.3.3 — Modal + Offcanvas + Collapse + Popper, not just CSS) is loaded once, globally, at `includes/footer.php:7`. Every page that includes `footer.php` (70 of 102 top-level module pages) gets it for free.

Every modal found in the codebase (24 files grep-matched; 3 read in full — `modules/orders/_item_picker_modal.php`, `modules/catalog/tabs/tags.php`'s merge modal, `modules/products/_form.php`'s three modals) follows one consistent shape:

- HTML is **static** — always present in the page's DOM, never fetched.
- Opened either declaratively (`data-bs-toggle="modal" data-bs-target="#id"`, e.g. `tags.php:276`) or via JS (`bootstrap.Modal.getOrCreateInstance(el).show()`, e.g. `order-form.js:241-246`) when the modal needs pre-fill logic first.
- Closed via real Bootstrap `Modal` instances everywhere — **no file reimplements Esc-key or backdrop-click dismissal**. Esc/backdrop/focus-trap all come free from Bootstrap's own JS.

**Bootstrap Offcanvas — the natural base for a slide-over Drawer — is used nowhere in the codebase today**, confirmed by an explicit zero-hit grep. The one existing "slide-in panel," the mobile sidebar (`assets/js/sidebar.js`, `includes/header.php:378-408`), is hand-rolled instead: manual `.is-open` class toggling, manual backdrop-click wiring, manual `Escape` keydown handler — and **explicitly has no focus trap**. This was a reasonable choice for the sidebar (a fixed, single-purpose element that predates any Drawer plan), but it is not a reason to hand-roll a second one now that a free, better-behaved primitive (`bootstrap.Offcanvas`) is already loaded and unused.

**Finding: the original `COMPONENT_LIBRARY_SPEC.md` Drawer entry didn't specify a base component.** Given Bootstrap's Offcanvas is already loaded, already the exact "slide-in side panel" shape the Drawer needs, and gives Esc/backdrop/focus-trap for free where the sidebar's hand-rolled equivalent explicitly doesn't — **the Drawer should be built on `bootstrap.Offcanvas`, not hand-rolled JS.** This directly satisfies "do NOT invent a second pattern if one already exists": it reuses Bootstrap's existing modal-adjacent component family, the same family every existing modal in this codebase already depends on.

## 2. Existing AJAX helper pattern

Two established, real patterns exist — and they solve different problems:

**a) `includes/ajax_helpers.php`** (used by `modules/search/ajax_quick.php` and 17 other `modules/*/ajax/*.php` endpoints, e.g. `modules/inventory/ajax/history.php:7`, `modules/customers/ajax/create_customer.php:8-9`): `ajax_require_login()`, `ajax_require_permission(string $permission)`, `ajax_require_csrf()`, `ajax_json(array $data, int $status = 200)`. Every real AJAX endpoint in this codebase uses this — permission failures return a clean `401`/`403` **JSON** body (`{error: ...}`) instead of a full HTML page, specifically because `app_require_permission()`'s normal HTML-output-then-`exit` behavior isn't something a `fetch()` caller can parse.

**b) Client-side fetch + render**: `assets/js/global_search.js` (debounced, `AbortController`-cancelled, fetches JSON, renders via hand-built HTML string + `escapeHtml()`, `innerHTML` injection) and `assets/js/inventory.js`'s View History modal (`openHistoryModal`/`fetchHistory`, lines 26-127 — lazy-loads only when the modal opens, shows `'Loading…'`, `'No transactions found.'`, `'Failed to load history.'` states). The history modal is the richest existing loading/empty/error-state precedent in the codebase and the closest architectural relative of both the Drawer and the Activity Feed: lazy-loaded on open, permission-checked via `ajax_require_permission('inventory.view')`, JSON in, HTML rendered client-side.

**Where this matters for the Drawer specifically:** the user's requirement is that the Drawer frame must support "any future resource... without changing the drawer framework itself" and that "modules should provide content, the drawer should only provide infrastructure." A JSON-in/client-rendered-out pattern (like (b) above) would require `drawer.js` itself to know how to render a Product differently from a Supplier differently from an Activity Feed entry — i.e., the framework would grow a rendering branch for every new resource type, which is exactly what "without changing the drawer framework itself" rules out. **Recommendation: Drawer content endpoints return ready-to-inject HTML fragments, not JSON** — `drawer.js`'s job becomes purely `fetch(url) → response.text() → innerHTML`, with zero knowledge of what's inside. This is a deliberate, reasoned departure from pattern (b) above, not a new invention: it's the same "fetch and inject" shape, just with the rendering responsibility left where the user's own requirement puts it — in the module, not the framework.

This does mean Drawer endpoints can't reuse `ajax_json()`'s envelope for permission failures (a JSON `{error}` body isn't directly injectable as readable HTML). The fix is minimal: a new `ajax_require_permission_html(string $permission): void` alongside the existing `ajax_require_permission()` in `includes/ajax_helpers.php` — same `app_has_permission()` check, same status codes, only the output body changes (an HTML fragment instead of JSON) so the Drawer can inject it directly as an in-panel "Access denied" state. This reuses the existing permission-check logic exactly; only the response envelope is new, and only because the Drawer's own constraint (HTML fragments, not JSON) requires it.

## 3. Existing JS architecture

`assets/js/` has 7 files, all flat, no subdirectories, no build step, no bundler. Confirmed style convention (zero `import`/`export`/`class` anywhere): every file is a single `(function () { 'use strict'; ... })()` IIFE that exposes its public API as a plain object assigned to `window.<Name>` at the end (e.g. `window.InventoryUI = { openAdjustModal, openHistoryModal }`, `inventory.js:207-210`), called from inline `onclick="InventoryUI.openX(...)"` attributes in PHP-rendered markup. Only `global_search.js` and `sidebar.js` load globally (`includes/header.php:520-521`); everything else is opted into per-module with a `?v=<?php echo filemtime(...); ?>` cache-bust.

**Since the Drawer must work from every module eventually** (per the user's explicit requirement — Product, Supplier, Supplier Order, Customer, Customer Order, Shipment, Inventory Item, Notification, Activity Feed), `drawer.js` belongs alongside `global_search.js`/`sidebar.js` as a **global** include in `includes/header.php`, not a per-module opt-in. It should match the exact same convention: one IIFE, `'use strict'`, `window.DrawerUI = {...}` export.

## 4. Existing CSS/component structure

All shared CSS lives inline in `includes/header.php`'s `<style>` block (confirmed in Phase 1's review too — `assets/css/style.css` is empty and unreferenced). Relevant existing pieces the Drawer should reuse rather than reinvent:

- `.card` (`header.php:52-56`) — the flat bordered surface convention, reusable for the Drawer's internal content blocks.
- `.empty-state`/`.empty-state-title`/`.empty-state-text` (`header.php:241-260`) — already the established empty-state look; the Drawer's empty state should use this verbatim, not a new one.
- **No loading-spinner convention exists anywhere** — every existing loading state (`inventory.js:54`) is plain `'Loading…'` text. Bootstrap's `.spinner-border` class ships free in the already-loaded `bootstrap.min.css` (zero new CSS needed) and is a strict improvement over plain text at zero cost — proposed for the Drawer's loading state. This is using an existing shipped primitive, not introducing a new one.
- **No custom `.modal`/`.offcanvas` CSS overrides exist** — Bootstrap's defaults are used unmodified everywhere a modal appears. The Drawer's Offcanvas should follow the same discipline: only add the CSS truly needed (width/breakpoint behavior, see §6), not a parallel visual system.

## 5. Existing permission checks

Confirmed consistent and rigorous, not "lighter" than full pages — every AJAX endpoint checks the same permission strings a full page would, just via `ajax_require_permission()` so failures come back in a `fetch()`-consumable form instead of an HTML redirect. `modules/inventory/ajax/history.php:7`'s `ajax_require_permission('inventory.view')` is the direct precedent: the Drawer's Inventory content endpoint (Step 2) should gate on the same `inventory.view` permission the Inventory module itself already requires — one gate, not a new permission.

## 6. Existing responsive behavior

The sidebar's mobile conversion happens at `@media (max-width: 991.98px)` (`header.php:378-396`, Bootstrap's standard `lg` breakpoint), with a second, unrelated spacing-only breakpoint at `575.98px` (`sm`). Bootstrap's Offcanvas is inherently responsive-capable — the Drawer should use a fixed width (proposed: 420px, matching the existing global-search dropdown's own `max-width: 420px` at `header.php:501`) at `≥576px`, and full-width (`100vw`) below `575.98px`, reusing the exact breakpoint values already established rather than picking new ones.

---

## 7. Proposed Drawer design (for approval)

### File structure
- `assets/js/drawer.js` — **new**, global IIFE, `window.DrawerUI` export, loaded at `includes/header.php` alongside `global_search.js`/`sidebar.js`.
- `includes/header.php` — add: (a) one Offcanvas container markup block (same "lives once, populated by JS" convention as `#global-search-dropdown`), (b) the `<script src="/assets/js/drawer.js">` tag, (c) Drawer-specific CSS additions to the existing inline `<style>` block (width/breakpoint only).
- `includes/ajax_helpers.php` — add one new function, `ajax_require_permission_html(string $permission): void` (see §2), alongside the existing `ajax_require_permission()`.
- Per-module content endpoints, added one at a time as each module adopts the Drawer — for Phase 2 Step 2, just `modules/inventory/ajax/drawer.php` (new).

### PHP entry points
Each module that adopts the Drawer adds one endpoint, `modules/<domain>/ajax/drawer.php`, following `inventory/ajax/history.php`'s exact existing shape:
1. `ajax_require_permission_html('<domain>.view')`.
2. Fetch the resource via the module's **existing** data functions — never a new query re-deriving what `view.php` already computes. For Inventory specifically, this means reusing whatever `modules/inventory/view.php` (or the row-building logic already in `modules/inventory/index.php`) already calls, not a new SELECT.
3. Render a small, self-contained HTML fragment (reusing `.card`/`.empty-state` classes from §4) and echo it directly — no `ajax_json()` envelope, no page chrome.

### JavaScript API (`window.DrawerUI`)
```
DrawerUI.open({ url, title })   // fetch(url) -> text() -> inject into the drawer body, show the Offcanvas, set the title
DrawerUI.close()                // hide the Offcanvas (also reachable via Esc/backdrop - free from bootstrap.Offcanvas)
```
Deliberately not `DrawerUI.open({ productId, ... })` or resource-typed variants — the framework only ever knows "a URL and a title," matching "modules provide content, the drawer provides infrastructure" exactly. A module wires a row/button to it the same way `inventory.js` already wires `onclick="InventoryUI.openHistoryModal(...)"` — e.g. `onclick="DrawerUI.open({url: '/modules/inventory/ajax/drawer.php?product_id=1', title: 'Product Name'})"`.

### AJAX loading pattern
`fetch(url) → response.text() → innerHTML` — see §2 for why this (HTML fragments) was chosen over the JSON-in/client-rendered-out pattern `global_search.js`/`inventory.js` otherwise use.

### Permission integration
`ajax_require_permission_html()` per endpoint (§2/§7 PHP entry points), gated on the same permission string the resource's own full page already requires — never a new permission.

### Responsive behavior
`bootstrap.Offcanvas`, `offcanvas-end` (slides from the right, distinct from the sidebar's left-slide), fixed 420px width at `≥576px`, full-width below `575.98px` — reusing the two breakpoints already established in `header.php` (§6).

### Keyboard support
Esc-to-close and focus trap both come free from `bootstrap.Offcanvas` — zero custom JS needed, and this is a genuine improvement over the sidebar's hand-rolled equivalent, which explicitly has no focus trap today.

### Loading / empty / error states
- Loading: `.spinner-border` (Bootstrap default, unused elsewhere but free — §4), shown the instant `DrawerUI.open()` fires, before the fetch resolves.
- Empty: each endpoint's own fragment uses the existing `.empty-state` classes (§4) when its resource has nothing to show.
- Error: a fetch rejection or non-200 response renders a small built-in fallback fragment inside `drawer.js` itself (e.g. "Something went wrong loading this." + a plain retry button that re-runs the last `open()` call) — this one piece of markup *is* owned by the framework, since it's the one case where the module's own endpoint couldn't run at all (network failure) to provide its own error content. A 403 from `ajax_require_permission_html()` still renders the *endpoint's* HTML (an "Access denied" fragment), not this fallback.

## 8. Activity Feed data source (for Step 3, noted now since it affects the Drawer's design)

Two tables exist and are **not interchangeable**: `audit_logs` (`user_id, action, details, ip_address` — a flat security/auth trail, no `module`/`record_id`, used today only for auth-adjacent events) and `activity_logs` (`user_id, module, action, record_id, description` — purpose-built for a per-record cross-module feed, already written by supplier order edits/payments, inventory adjustments, and product deletes). **The Activity Feed component should read `activity_logs` only** — it's the one already shaped for "show me this record's history," and the two tables have no common column shape to unify behind one query. This confirms `COMPONENT_LIBRARY_SPEC.md` §2's original choice; no change needed there.

## 9. Open decisions for approval

1. **Base the Drawer on `bootstrap.Offcanvas`**, not hand-rolled JS like the sidebar (§1).
2. **Drawer content endpoints return HTML fragments, not JSON** — a deliberate departure from `global_search.js`/`inventory.js`'s JSON pattern, justified by the "framework never changes per resource type" requirement (§2).
3. **New `ajax_require_permission_html()` helper** in `includes/ajax_helpers.php`, minimal sibling to the existing `ajax_require_permission()` (§2/§7).
4. **`drawer.js` loads globally** via `header.php`, matching `global_search.js`/`sidebar.js` (§3).
5. **Bootstrap's `.spinner-border`** for the loading state, replacing the codebase's current plain-text `'Loading…'` convention going forward for anything new (§4) — existing `'Loading…'` text elsewhere is left as-is, not retrofitted.
6. **Activity Feed reads `activity_logs` only**, not `audit_logs` (§8).

No code has been written. Awaiting approval before Step 1 implementation begins.
