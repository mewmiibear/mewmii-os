# Phase 2 Implementation — Shared Drawer Framework + Inventory Pilot

**Status:** Drawer framework built, Inventory is the first (and currently only) module using it. Do not convert additional modules until this round's review is complete — see `docs/CHANGELOG.md`'s Phase 2 entry for the implementation summary, testing results, and lessons learned.
**Purpose of this document:** the permanent reference for how the Drawer works and how a future module adopts it. A developer should be able to add a Drawer to a new module by following this document alone, without reading `drawer.js`'s internals first.

---

## 1. Architecture

```
Drawer Framework (assets/js/drawer.js, includes/header.php)
        ↓ fetch(url) → response.text() → innerHTML
Drawer Controller (modules/<domain>/ajax/drawer.php)
        ↓ permission check, calls existing data functions
Drawer View (modules/<domain>/views/drawer.php)
        ↓ renders HTML fragment, no query, no business logic
```

Each layer knows only about the layer directly below it, and never about a specific resource type outside its own module:

- **The framework** (`drawer.js` + the `#app-drawer` markup in `header.php`) knows a URL, a title, and how to show loading/success/error/close. It never knows what a Product or a Supplier looks like, and never grows a new code path when a new module adopts it.
- **The Controller** (one per module, `modules/<domain>/ajax/drawer.php`) checks permission, loads data via the module's own existing functions (never a new query re-deriving what a full page already computes), and hands the data to the View.
- **The View** (one per module, `modules/<domain>/views/drawer.php`) is pure presentation — an included PHP partial that reads the Controller's already-computed variables and echoes markup. No `PDO` object is used inside a View file.

This is the same three-layer separation the user asked for explicitly: infrastructure, controller, view — kept as three distinct files per module, not folded together into one "AJAX endpoint that also builds HTML inline."

## 2. Why HTML fragments, not JSON (recap)

Covered in full in `docs/PHASE2_READINESS_REVIEW.md` §2/§7 — summarized here because it's the load-bearing decision every other part of this document depends on: if Drawer content endpoints returned JSON, `drawer.js` itself would need a rendering branch for every resource type, which is exactly what "the framework never changes per resource type" rules out. Returning ready-to-inject HTML instead means `drawer.js` only ever does `fetch → text() → innerHTML`, forever, regardless of how many modules adopt it.

## 3. Built on `bootstrap.Offcanvas`

The Drawer is a real `bootstrap.Offcanvas` instance (`assets/js/drawer.js`, `getInstance()`), not hand-rolled JS. Esc-to-close, backdrop click, and focus trap are **never reimplemented** — they come from Bootstrap's own already-loaded component (`bootstrap.bundle.min.js`, loaded once in `includes/footer.php`). Do not add custom keyboard/focus-trap/backdrop code to `drawer.js` — if Bootstrap's default Offcanvas behavior doesn't do what a future requirement needs, that's a sign to extend via Bootstrap's own supported options (`data-bs-backdrop`, `data-bs-scroll`, `data-bs-keyboard` on the `#app-drawer` element in `header.php`), not to bypass the component.

## 4. File locations

| Piece | Location | Per-module or shared? |
|---|---|---|
| Drawer container markup | `includes/header.php` (inside the `app_is_logged_in()` block, alongside the sidebar/backdrop) | Shared — one instance, present on every logged-in page |
| Drawer JS framework | `assets/js/drawer.js` | Shared — loaded globally via `header.php`, same convention as `sidebar.js`/`global_search.js` |
| Drawer CSS | `includes/header.php`'s existing inline `<style>` block (`.app-drawer` width rules) | Shared |
| `ajax_require_permission_html()` | `includes/ajax_helpers.php` | Shared helper, used by every module's Controller |
| Controller | `modules/<domain>/ajax/drawer.php` | One per module |
| View | `modules/<domain>/views/drawer.php` | One per module |
| Trigger markup (the button/row that calls `DrawerUI.open(...)`) | Wherever the module already renders its list/table (e.g. `modules/inventory/index.php`'s row actions) | Per-module |

## 5. Drawer lifecycle

1. A page (currently only `modules/inventory/index.php`) renders a trigger with `onclick="DrawerUI.open({url: '...', title: '...'})"`.
2. `DrawerUI.open()` stores the config, sets the panel title, shows the Offcanvas, and calls `load()`.
3. `load()` aborts any still-in-flight previous request (same `AbortController` convention as `global_search.js`), shows a `.spinner-border` loading state, and fetches the URL.
4. The response is handled by status:
   - **2xx or 4xx** → the body text is injected directly as the panel's content. This covers success, "access denied" (`ajax_require_permission_html()`'s 401/403 fragment), "not found" (404), and "bad request" (400) — a Drawer Controller is expected to always render *some* deliberate, readable fragment for any of these, never a redirect or raw JSON.
   - **Network failure or 5xx** → the framework's own built-in fallback renders ("Something went wrong loading this." + a Retry button that re-runs the same `open()` call). This is the one piece of markup the framework itself owns, and only because the Controller never got a chance to render anything of its own in this case.
5. `DrawerUI.close()` (or the panel's own close button / Esc / backdrop click) hides the Offcanvas. Content is left in place until the next `open()` call overwrites it — the panel doesn't need to be emptied on close.

## 6. How to add a Drawer to a new module

Using Inventory as the worked example (`modules/inventory/ajax/drawer.php` + `modules/inventory/views/drawer.php`):

1. **Create `modules/<domain>/ajax/drawer.php`** (the Controller):
   ```php
   require_once __DIR__ . '/../../../includes/bootstrap.php';
   require_once __DIR__ . '/../../../includes/ajax_helpers.php';
   // require whatever includes/*.php file already has the module's data functions

   ajax_require_permission_html('<domain>.view');   // same permission the module's own page requires

   $pdo = app_db();
   // read $_GET params, validate, echo an .empty-state fragment + the right 400/404 status
   // and exit if something's missing/not found - see modules/inventory/ajax/drawer.php for
   // the exact shape

   // load data via EXISTING functions from includes/<domain>.php - never a new query that
   // re-derives something the module's own view/index page already computes

   require __DIR__ . '/../views/drawer.php';
   ```
2. **Create `modules/<domain>/views/drawer.php`** (the View): a plain PHP partial, no `<?php require` at the top, no `PDO` usage — just reads whatever variables the Controller put in scope and echoes HTML. Reuse `.card`, `.empty-state`/`.empty-state-title`/`.empty-state-text` (`header.php`'s existing classes) rather than inventing new visual patterns.
3. **Wire a trigger** wherever the module renders a row/record: `onclick="DrawerUI.open({url: '/modules/<domain>/ajax/drawer.php?id=...', title: '...'})"`.
4. **Reuse the module's own JS namespace for "related actions" inside the View, if it has one** — e.g. Inventory's View calls `InventoryUI.openAdjustModal(...)`/`InventoryUI.openHistoryModal(...)` directly, since those are already-loaded, already-working functions on the same page. **Constraint to know about:** this only works because the Drawer, for now, is only ever triggered from the Inventory page itself, where `inventory.js` is already loaded (it's a per-module script, not global — see `docs/PHASE1_READINESS_REVIEW.md`/`PHASE2_READINESS_REVIEW.md` §3/§5 on the JS loading convention). A future Drawer triggered from a *different* page than the resource's own module page (e.g. a cross-module "preview" link) cannot assume the target module's JS namespace is loaded — that scenario isn't solved by this document and should be designed for explicitly if/when it comes up, not assumed to already work.

## 7. Template convention

`modules/<domain>/views/` is the new, permanent home for Drawer view partials. As more modules adopt the Drawer (Supplier Orders, Customer Orders, Products, etc., per the roadmap in `MEWMII_OS_V2_PLAN.md`), each gets its own `modules/<domain>/views/drawer.php` — this directory is reserved for Drawer views specifically (not a general "any partial goes here" convention; existing partial conventions like `_item_picker_modal.php` living directly in `modules/<domain>/` are unchanged).

## 8. Reusable APIs — quick reference

**JavaScript (`window.DrawerUI`):**
```js
DrawerUI.open({ url: '/modules/<domain>/ajax/drawer.php?...', title: 'Panel title' });
DrawerUI.close();
```

**PHP (`includes/ajax_helpers.php`):**
```php
ajax_require_permission_html(string $permission): void   // 401/403 as a readable fragment, not JSON
```

**PHP (`includes/inventory.php`, Inventory-specific but shows the pattern):**
```php
inventory_transactions_recent(PDO $pdo, int $productId, ?int $variationId, int $limit = 5): array
```
A small, deliberately unenriched read (no reference-label resolution) for a "glance before you leave the page" preview — the full, resolved history stays in the existing `modules/inventory/ajax/history.php` + History modal, reused as-is via `InventoryUI.openHistoryModal()` from inside the Drawer's own View, never duplicated.

## 9. What NOT to do when extending this

- Don't add a second way to open a panel (e.g. a module-specific `openQuickView()` function) — always `DrawerUI.open()`.
- Don't return JSON from a Drawer Controller "just this once" — the framework's status-code handling (§5) assumes every response is a displayable HTML fragment.
- Don't put a query or business-logic call inside a View file — Views read variables, Controllers load data.
- Don't reuse `inventory_get_or_create_row()` (or any function with side effects / row locks) for a read-only Drawer preview — see the comment at the top of `modules/inventory/ajax/drawer.php` for why a plain `SELECT` was used instead.
