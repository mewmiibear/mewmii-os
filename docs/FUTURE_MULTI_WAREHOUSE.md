# Future: Multi-Warehouse Architecture

**Status:** Design-only. Not scheduled for implementation. Do not build this now.
**Purpose of this document:** confirm the current gap, sketch the eventual shape, and — the actually load-bearing part — state what v2 work happening *now* must not assume, so this doesn't require ripping anything up later.

---

## 1. Current state (confirmed gap)

There is no warehouse or location concept anywhere in the schema. Every inventory row is implicitly "the one place stock lives." No table has a `warehouse_id` or `location_id` column, no module has a location switcher, no query is scoped by location. This isn't a partial implementation to extend — it's a genuine zero.

## 2. Why it isn't happening now

Nothing in the current roadmap (`MEWMII_OS_V2_PLAN.md` Phase 1–3) requires it, and per the project's no-rewrite philosophy, schema changes of this size only happen when a real, evidenced need exists — not preemptively. Building it now would also mean designing it against workflows (Phase 3 module redesigns) that haven't happened yet, which is backwards.

## 3. Eventual shape (sketch, not a spec)

- A new `warehouses` table (id, name, is_default, active).
- `inventory` (or wherever stock quantity is currently tracked) gains a `warehouse_id` foreign key; existing rows backfill to a single seeded "Main Warehouse" so nothing currently working changes behavior.
- Stock movements, allocations, and the inventory ledger all become location-aware — a movement is *between* two `warehouse_id` values (or from `NULL` for external receipt/shipment), not just a quantity delta.
- Purchasing and receiving gain a "receive into which warehouse" step.
- Reports gain an optional warehouse filter/breakdown.

This is a real migration, not a config flag — every place that reads or writes a stock quantity needs to become location-aware, which is exactly why it's being deferred until there's a concrete trigger (a second physical location, a 3PL integration, etc.) rather than spent on now.

## 4. What current v2 decisions must NOT assume

This is the part that actually matters today:

- **No component should hardcode "one inventory row = one location."** The Drawer, Activity Feed, and Bulk Actions specs (`COMPONENT_LIBRARY_SPEC.md`) are all location-agnostic already — none of them reference a location concept, so none of them need revisiting.
- **Inventory module (Phase 3, item 1)** is the one place this actually bites. When its audit/design pass happens, it must not design its improved workflow in a way that's structurally incompatible with an eventual `warehouse_id` — e.g., don't build a "total stock" summary UI so tightly coupled to a single-number-per-product assumption that adding a location breakdown means a rewrite rather than an extension. It does not need to *build* multi-warehouse UI now, just avoid closing the door.
- **No new table introduced in Phase 1–3 should encode "there is exactly one place stock lives"** as a structural assumption (e.g., a new stock-adjustment table should key off `product_id` + a nullable/future-proof location reference shape, not assume a single global quantity is the only representation ever needed) — this is a modeling discipline, not a requirement to add the column now.
- **Reports (Phase 3, item 7)**, being last in the module order, should be designed last partly *because* by then any Inventory-module decisions with warehouse implications will already be settled — reducing the chance Reports has to be redesigned around a warehouse dimension added after the fact.

## 5. Trigger for revisiting

This document gets promoted from "future" to "planned" when there's a concrete, real business need — a second physical location, a 3PL/fulfillment partner, or similar — not on a schedule.
