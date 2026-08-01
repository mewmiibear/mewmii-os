# Mewmii OS Dashboard Philosophy — Mission Control

**Status:** Approved design, Phase 1 of `MEWMII_OS_V2_PLAN.md`. Not yet implemented.
**Purpose of this document:** the permanent governing philosophy for the Dashboard — not just this version's spec, but the rule set every future dashboard change must be checked against.

---

## 1. Philosophy

The dashboard's only job is to convert business state into a work queue. Not to inform, summarize, or impress. It answers exactly three questions, in this order, and nothing else:

1. What is broken?
2. What should I do next?
3. How is my business today?

If a piece of information doesn't help answer one of those three, it does not belong on the dashboard — no matter how interesting it is.

**Never on the Dashboard:** anything with no action attached; anything historical/trend-based with no "so what for today"; anything that needs explanation to understand at a glance; anything equally well served by Search on demand; anything identical regardless of whether the business changed today; charts with no action attached.

**Decision table — when does something become:**

| Becomes | Rule |
|---|---|
| Dashboard widget | Changes daily/hourly, requires a decision today, ignoring it has a real cost |
| My Day task | A discrete, completable action, generated live from current state |
| Notification | An interrupt — resolves into either a My Day task or disappears |
| Report | Understanding a trend/pattern over time, for planning, not today's action |
| Timeline / Activity Log | Historical record — "when did X happen," audit and context |
| Search result | Specific, user-initiated — only wanted on demand |

## 2. Principles

1. Silence is the default state — no manufactured content to fill space.
2. Every item is a verb, not a noun ("Receive PO-1023," not "Pending Supplier Orders: 9").
3. If it doesn't change today, it doesn't belong on today's dashboard.
4. One glance answers "is anything broken." One scroll answers "what do I do next." Nothing needs a third level on this page.
5. Every widget must earn its place — if ignoring it has zero consequence, it doesn't belong here.
6. Prefer Search over permanent display for anything wanted on demand.
7. The dashboard is never the reason the page feels slow.

## 3. Structure

Three components, not a stack of sections:

```
STATUS LINE      — 🟢 all clear, or 🔴/🟡 N things need attention (silent when healthy)
MY DAY           — auto-generated task list, capped, sorted by urgency, verb-first
TODAY'S BUSINESS — Revenue / Orders / AOV, 3 numbers max
──────────────────────────────────────────────
Shortcuts (evergreen, non-signal-driven actions)
Since your last visit: N changes → Activity Log
This month: RM X revenue → Full Report
```

## 4. My Day

A **live view, not a to-do app.** No new table, no manual task creation, no stored "completed" state — a task exists only because its underlying condition is currently true, and disappears the moment that condition resolves (the PO is created, the receipt is verified). This is a deliberate choice: zero new schema, a rules engine reading existing functions.

| Task | Source |
|---|---|
| "Order N products from Supplier X" | `purchase_planning_needs()`, grouped by supplier |
| "Receive PO-X (N days late)" | `supplier_orders` overdue query |
| "Verify N payment receipts" | Existing pending-receipt query |
| "Ship N ready orders" | Existing `ready_to_ship` count |
| "Respond to N customer issues" | Resolution system's open requests |
| "Allocate N arrived preorder units" | `inventory_allocation_queue()` |
| "Review N low-stock items" | Existing low-stock query |

Sorted overdue-first, capped at top 5–8 with "+N more."

**Known open question, not resolved in v1:** no dismiss/snooze. A task you can't act on yet (waiting on a supplier reply) will keep reappearing. This is the most likely first real feature request post-launch — resolving it needs a small new table (`task_dismissals`), which is a deliberate v2 non-goal, not an oversight.

## 5. Business Health

Not a new metric — a single-value summary of signals already used by My Day. Fully rule-based, no prediction, no AI:

```
🔴 CRITICAL   — Sync Health critical, OR negative stock exists, OR any critical notification
🟡 ATTENTION  — low stock > 0, OR pending receipts > 0, OR open customer issues > 0,
                OR purchase planning needs > 0  (and not already Critical)
🟢 HEALTHY    — none of the above
```

If a future failure mode isn't caught by these rules, the fix is adding a rule here — never distrusting the model.

## 6. Search-first direction

Global Search already exists and works. As Search's capability grows, **Dashboard content should shrink, not grow alongside it** — Search absorbs "lookup," Dashboard keeps only "action" and "status." Every list-preview widget (low stock items, top products) is a candidate to eventually be replaced by a filtered search once Search supports facets — this is a direction, not a v1 change.

## 7. Extensibility

The structure (Status → My Day → Today's Business) never grows outward as new modules (CRM, Accounting, Marketing, AI Assistant) are added — only the *rule set feeding My Day* grows. A new module contributes a My Day rule only if it produces a real "do this today" signal; otherwise it never touches the dashboard, by definition of §1. A future AI Assistant plugs in as another rule source ("AI suggests reordering Product X"), not a separate panel.

## 8. What moved off the dashboard, and where

| Removed from Dashboard | Moved to |
|---|---|
| Full cross-entity Recent Activity timeline | Activity Log page (see `COMPONENT_LIBRARY_SPEC.md` §2 — its first-ever viewer) |
| Business Snapshot detail (30-day breakdown) | Reports (one-line teaser remains) |
| Top Selling Products | Reports / Search |
| Routine/resolved notifications | Notification Center |
| Sync Health, Inventory Health as permanent KPI cards | Folded into the Status Line, silent when healthy |

## 9. Known tensions (deliberately unresolved in v1)

- No dismiss/snooze on My Day tasks (§4).
- Today's Business is kept even on a healthy day specifically so the "glance at good numbers" experience isn't lost to pure exception-handling — a deliberate balance, not an oversight.
- The 3-tier health model is a simplification by design, meant to be extended via new rules, not trusted as exhaustive from day one.
