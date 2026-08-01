# Future: API Layer

**Status:** Design-only. Not scheduled for implementation. Do not build this now.
**Purpose of this document:** confirm the current gap, sketch the eventual shape, and state what v2 work happening *now* must not assume.

---

## 1. Current state (confirmed gap)

There is no `/api/` directory or equivalent anywhere in the codebase. Mewmii OS only ever acts as an API *consumer/producer* in one specific direction — it calls out to and receives calls from WooCommerce (via `wc_client.php` and related sync code). There is no general-purpose internal API for external tools, a future mobile app, or scripted integrations to call. This is a genuine zero, not a partial build.

## 2. Why it isn't happening now

Nothing in the current roadmap needs it — there's no mobile app, no third-party integration request, and no scripted-automation need beyond the existing WooCommerce sync, which already has its own purpose-built integration code. Building general API infrastructure speculatively would be exactly the "unnecessary infrastructure complexity" the project already explicitly rejected once this session (the HTTP-loopback migration runner alternative) for the same reason: don't build infrastructure a real need hasn't justified yet.

## 3. Eventual shape (sketch, not a spec)

- A new `/api/` directory, separate from `modules/`, exposing JSON endpoints.
- Token-based authentication, distinct from the session-cookie auth every page currently uses — an API consumer isn't a logged-in browser session.
- Endpoints call into the **same** `includes/*.php` business-logic functions every page already calls — an API endpoint for "create a customer order" should call the exact function `modules/orders/create.php` calls today, not a reimplementation.

This is the most speculative of the three future documents, precisely because there's no concrete trigger yet (unlike RBAC, where "a second staff member" is a plausible near-term event) — it's included because the user asked for it to be planned against, not because it's expected soon.

## 4. What current v2 decisions must NOT assume

This is the one with the most teeth for day-to-day v2 work:

- **No v2 business logic may be written directly inside a page's rendering code.** Every new component in `COMPONENT_LIBRARY_SPEC.md` already respects this: the Drawer reuses `view.php`'s existing logic rather than duplicating it, Bulk Actions call the same per-record functions the single-record actions already call, the Command Palette calls the same `global_search()` function the header search already uses. This discipline is what keeps a future API layer cheap to add — if logic already lives in `includes/*.php` and is called from a page, exposing the same logic via an API endpoint later is a thin wrapper, not an extraction project.
- **Any genuinely new business logic written during Phase 2/3** (e.g., the Activity Feed's query logic, a Phase 3 module's improved workflow logic) should default to living in `includes/*.php` as a callable function, with the module's page as a thin caller — the same pattern already used throughout, not a new pattern to introduce, just a discipline to keep enforcing.
- **No new endpoint should conflate "authentication" with "the fact that a browser session exists."** Nothing in the current AJAX pattern (`global_search.js` / `ajax_quick.php`, and the Drawer's `?panel=1` fetches) needs to change here — they're same-origin, session-authenticated fragment fetches, not a public API, and they can stay exactly that. The distinction to preserve is conceptual: a future API's token auth is a separate concern layered on top of existing session auth, not a replacement for it.

## 5. Trigger for revisiting

This document gets promoted from "future" to "planned" when there's a concrete external consumer — a mobile app, a partner integration, or a scripting need that the existing WooCommerce-specific sync code can't cover — not on a schedule.
