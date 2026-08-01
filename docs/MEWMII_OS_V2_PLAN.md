# Mewmii OS v2 — Master Plan

**Status:** Approved direction, design phase. No implementation has started.
**Supersedes/consolidates:** the system-wide design work done across this project's audit and design passes (`CURRENT_SYSTEM_AUDIT.md`, the dashboard design rounds, the navigation/component/workflow review). This is the one document to read to understand what v2 actually is and in what order it's being built.
**Companion documents:** `DASHBOARD_PHILOSOPHY.md`, `COMPONENT_LIBRARY_SPEC.md`, `FUTURE_MULTI_WAREHOUSE.md`, `FUTURE_RBAC.md`, `FUTURE_API_LAYER.md`.

---

## 1. Philosophy (permanent, governs every future v2 decision)

**This is not a rewrite.** Confirmed across three separate audits this project: the data layer, the inventory ledger, the computed-status engines, the permission system, and the database structure are sound and heavily reused. v2 does not touch any of these unless a specific, evidenced architectural issue is found — not because a page "could look nicer."

**No unnecessary abstraction migration.** The flat procedural page + shared `includes/*.php` function library pattern stays. It is not being replaced with a framework, a service layer, or a repository layer as a blanket change. If a specific piece of duplicated logic is found, it gets extracted (as already done for the migration helpers) — that is not the same thing as restructuring the whole codebase's architecture.

**Every change follows:** audit → design → implement → review → document. No step is skipped, for any change, regardless of size. This has been the process for every piece of work this project, and it does not change for v2.

## 2. Workflow First

**The goal of v2 is not making pages prettier. The goal is reducing the number of steps needed for daily operations.** A change that makes a page visually cleaner but doesn't reduce clicks, scrolling, or repeated work has not achieved the goal. A change that's visually unremarkable but removes a navigation hop has.

**The four workflows every v2 decision is measured against:**
1. Receive supplier stock → allocate preorder/customer orders → fulfill shipment.
2. Product → supplier pricing → purchasing → inventory → sales.
3. Customer order → storage → shipping.
4. Dashboard alerts → direct action.

Before any v2 change ships, it should be checked against these four: does it shorten one of them, or is it orthogonal to all four? Orthogonal changes are not wrong, but they are lower priority than anything that shortens one of these paths.

## 3. UX north star

Mewmii OS should eventually feel closer to **Shopify Admin for operations, Linear for speed/navigation, Notion for information organization** — not by copying their UI, but by reducing cognitive load the way each of them does in its own domain. Concretely: Shopify's discipline of "one obvious next action per screen," Linear's discipline of "everything reachable without leaving your context" (hence the Drawer/Command Palette investment), Notion's discipline of "information is organized so you find it without having to remember where it lives" (hence Search-first, see `DASHBOARD_PHILOSOPHY.md` §8).

## 4. Roadmap

### Phase 1 — Foundation (no new components, pure consolidation + the dashboard)
- Navigation consolidation (5 sections instead of 6, merge redundant entries — see the System Design Phase review for the exact list).
- Notification badge in persistent header.
- Dashboard Mission Control (`DASHBOARD_PHILOSOPHY.md`).

### Phase 2 — Shared components
- Drawer (spec: `COMPONENT_LIBRARY_SPEC.md` §1).
- Activity Feed viewer (spec: §2) — the first-ever reader for `activity_logs`/`audit_logs`.
- Bulk actions extended beyond Orders/Products (spec: §3).

### Phase 3 — Module-by-module UX improvement, in this order
1. Inventory
2. Supplier Orders
3. Customer Orders
4. Products/Catalog
5. Suppliers
6. Shipments
7. Reports

Each module in Phase 3 goes through the full audit → design → approval → implement → review → document cycle individually — the same process already proven on Supplier Orders and the Migration System this project. Phase 3 is not a single big-bang pass across all seven.

## 5. Forward compatibility (design now, build later)

Three areas are explicitly **not being implemented** as part of v2, but every v2 decision must be checked against them so nothing built now has to be undone later:

- **Multi-warehouse** — see `FUTURE_MULTI_WAREHOUSE.md`. No v2 component should assume "one inventory row = one location" in a way that would need rewriting once a warehouse dimension is added.
- **Multi-staff / RBAC management** — see `FUTURE_RBAC.md`. No v2 component should hardcode "the current user is the Owner" instead of going through `app_has_permission()`.
- **API layer** — see `FUTURE_API_LAYER.md`. No v2 business logic should be written directly inside a page's rendering code in a way that would prevent that same logic being called from a future API endpoint.

## 6. What "done" looks like for this plan

This document, and its companions, are the reference for every subsequent implementation task in v2. When a Phase 3 module's turn comes up, its own audit/design pass should explicitly check itself against §2 (workflow first), §5 (forward compatibility), and the relevant component specs — not start from a blank page.
