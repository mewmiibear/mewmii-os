# Mewmii OS v2 — Component Library Specification

**Status:** Design only, Phase 1/2 of `MEWMII_OS_V2_PLAN.md`. Nothing below has been implemented.
**Rule for every component in this document:** no new framework, no new AJAX convention beyond what `assets/js/global_search.js` + `modules/search/ajax_quick.php` already establish, no component reinvents a pattern the codebase already has a working answer for.

---

## 1. Drawer / Slide-over Panel

**Problem it solves:** every "view a record while staying on a list" need currently forces a full page navigation — confirmed zero existing usage (`Offcanvas` — zero grep hits across the whole codebase).

**Where it lives:**
- `assets/js/drawer.js` (new) — vanilla JS, same convention as `sidebar.js`/`global_search.js`.
- One drawer container markup lives once in `includes/header.php` (same pattern as the existing `#global-search-dropdown`), reused by every page.
- CSS added to `header.php`'s existing `<style>` block as `.drawer`/`.drawer-backdrop`.

**Design decision — no new backend endpoints per module.** Rather than building a parallel `ajax/drawer_view.php` for every module (net-new files, net-new logic to keep in sync with each module's real `view.php`), each module's **existing** `view.php` gains a small conditional: a `?panel=1` query flag makes it skip `require_once header.php`/`footer.php` and output only its inner content. Everything else about the page — the query, the permission check, the business logic — is completely unchanged. This is the smallest possible change that gets drawer support, and it can never drift out of sync with the real page, because it *is* the real page.

**API/AJAX pattern:** `fetch('/modules/<domain>/view.php?id=123&panel=1')` → inject the returned HTML fragment into the drawer container. Same one-fetch-one-inject shape `global_search.js` already uses.

**Permission handling:** unchanged — `view.php` already calls `app_require_permission()` before rendering anything. If that fails, the fetch returns a 403; the JS checks the response status and shows an inline "You don't have access to this" message in the drawer rather than trying to inject a full error page's HTML.

**Mobile behavior:** below 768px the drawer becomes full-screen (matching how the sidebar already becomes a full off-canvas panel below 992px) — a narrow side panel on a phone-width screen is unusable. Above 768px it's a fixed-width panel sliding in from the right.

**Empty / loading / error states:**
- Loading: a centered spinner while the fetch is in flight.
- Empty (record not found): the existing `.empty-state` component, verbatim — no new empty-state design.
- Error (permission denied, network failure): an inline `.alert-danger` inside the drawer, with an explicit "Open full page instead" link — the drawer must never trap a user with no escape hatch to the real page.

## 2. Activity Feed Viewer

**Problem it solves:** `activity_logs` and `audit_logs` have been write-only since they were built — confirmed zero viewers anywhere in the codebase.

**Where it lives:** new `modules/activity-log/index.php` — a standard list page, following the exact same shape as every other module's `index.php` (`.filter-card`, pagination, `.responsive-stack-table`), not a new architecture.

**API/AJAX pattern:** primarily server-rendered, like every other list page. An optional "load more" AJAX tail can be added later for a fast-growing table, reusing the Drawer's fetch-and-inject shape — not required for v1.

**Permission handling:** needs one genuinely new permission, `activity-log.view` — this is real data that's never been exposed before, and it needs its own gate rather than piggybacking on an existing one. Seeded through the same `roles`/`permissions` mechanism `install.php` already uses for every other permission — a small, standard addition, not new infrastructure.

**Mobile behavior:** `.responsive-stack-table`, unchanged from every other list page.

**Empty / loading / error states:** `.empty-state` for a filtered view with zero rows (the unfiltered table itself will rarely be empty once this ships, given how long these tables have been silently accumulating data). No special loading state needed — this is a normal page load.

## 3. Bulk Actions (extended)

**Problem it solves:** only 2 of ~19 modules (Orders, Products) have bulk actions today — everywhere else, multi-item work means one-at-a-time clicking.

**Where it lives:** `modules/inventory/bulk_action.php`, `modules/supplier-orders/bulk_action.php`, `modules/customers/bulk_action.php` — new files, directly modeled on the two existing implementations, not a new pattern.

**API/AJAX pattern:** identical to the existing two — a checkbox column, a "Bulk action" dropdown, POST selected IDs + action to the module's own `bulk_action.php`. **Hard requirement:** each bulk action must call the exact same per-record function the equivalent single-record action already calls (e.g., a bulk inventory adjustment calls the same function the single-record Adjust Stock modal calls) — no bulk-specific reimplementation of any business logic, ever.

**Permission handling:** gated on the same `.manage` permission the individual action already requires. No new permission — bulk is the same action, repeated.

**Mobile behavior:** the checkbox becomes the first field in each stacked card-row under `.responsive-stack-table` (already achievable with the existing utility class). Bulk actions remain fully usable on mobile, just less ergonomic than desktop — consistent with desktop being the primary working screen (see `DASHBOARD_PHILOSOPHY.md`'s desktop/mobile split reasoning).

**Empty / loading / error states:** "0 selected" disables the bulk-action control (already the existing behavior in Orders/Products — extend, don't redesign). A failure partway through a batch reports per-row success/failure and continues — never all-or-nothing — matching the exact discipline already established by `product_import_commit()` and the migration runner (one bad row/migration doesn't take down the batch).

## 4. Command Palette

**Problem it solves:** every action requires mouse navigation through the sidebar; no keyboard-driven way to jump anywhere.

**Where it lives:** `assets/js/command_palette.js` (new), bound globally in `header.php` via a keyboard shortcut (Ctrl/Cmd+K). Visual container reuses the existing Bootstrap modal pattern (already used in 14 files) rather than inventing a new overlay mechanism.

**API/AJAX pattern — deliberately zero new backend.** The Command Palette is a presentation-layer addition only: it calls the **exact same** `modules/search/ajax_quick.php` endpoint the header search bar already uses, and reuses `global_search.js`'s existing `renderResults()` function, just mounted in a centered overlay instead of a dropdown under the search input. Same data, same permission scoping, different trigger and container.

**Permission handling:** identical to Global Search — already permission-scoped per section server-side inside `global_search()`. Nothing new to gate.

**Mobile behavior:** intentionally **not built for mobile.** Ctrl/Cmd+K has no meaningful mobile equivalent, and mobile users already have the header search bar for the same underlying capability. This is a deliberate scope decision, not a gap.

**Empty / loading / error states:** identical to Global Search's existing dropdown states (including "No results for X") — literally the same rendering function, so there is nothing new to design here.

## 5. Notification Badge

**Problem it solves:** unread notification count is currently only visible on the dashboard page itself — invisible everywhere else in the app.

**Where it lives:** `includes/header.php`'s sidebar render, next to the existing "Notifications" sub-link — a markup addition, not a new file.

**API/AJAX pattern:** `notification_unread_count()` is a single cheap `COUNT` query, already used on the dashboard — safe to call synchronously on every page load, no AJAX required for v1. A live-refresh-without-reload polling endpoint is a possible future enhancement, not required, since this is a normal multi-page app where every navigation already re-renders the header with a fresh count.

**Permission handling:** rendered only under the same `app_has_permission('dashboard.view')` gate the Notifications link itself already uses.

**Mobile behavior:** renders inside the existing off-canvas sidebar exactly where the desktop version renders — no separate mobile treatment, since the sidebar markup is already shared between breakpoints.

**Empty / loading / error states:** **zero unread = no badge at all.** This follows the Dashboard Philosophy's "silence is the default state" principle directly — a visible "0" badge is noise, not information.

---

## Summary table

| Component | New files | New backend logic | New permission | Mobile treatment |
|---|---|---|---|---|
| Drawer | `assets/js/drawer.js` | None — reuses existing `view.php` pages via a query flag | None | Full-screen below 768px |
| Activity Feed Viewer | `modules/activity-log/index.php` | None (read-only over existing tables) | `activity-log.view` (new) | `.responsive-stack-table`, unchanged |
| Bulk Actions | 3 new `bulk_action.php` files | None — calls existing per-record functions | None — reuses existing `.manage` permissions | Supported, not optimized |
| Command Palette | `assets/js/command_palette.js` | None — reuses `ajax_quick.php` | None | Not built (desktop-only, by design) |
| Notification Badge | None (markup addition to `header.php`) | None — reuses `notification_unread_count()` | None | Shared markup with desktop |
