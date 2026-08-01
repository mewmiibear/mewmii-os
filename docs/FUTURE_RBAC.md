# Future: Multi-Staff / RBAC Management

**Status:** Design-only. Not scheduled for implementation. Do not build this now.
**Purpose of this document:** confirm the current gap, sketch the eventual shape, and state what v2 work happening *now* must not assume.

---

## 1. Current state (confirmed gap)

The mechanism already exists and is sound: `roles`, `permissions`, `role_permissions` tables, and `app_has_permission()` as the single check used throughout the codebase. What's missing is entirely on the data side — only one role, "Owner," is ever seeded, and there is no UI anywhere to create a role, edit a role's permissions, or assign a non-Owner role to a user. This is a "half-built, correctly" situation, not a design gap: the mechanism is right, it's just never been used for more than one role.

## 2. Why it isn't happening now

Mewmii Bear is currently a single-operator business — there's no second staff member to manage permissions for yet. Building role-management UI ahead of an actual second user would be exactly the kind of premature feature this project's philosophy warns against ("prefer configuration over hardcoding," but not "build config UI nobody can use yet").

## 3. Eventual shape (sketch, not a spec)

- A "Staff & Roles" settings page: list existing roles, create a new role, check/uncheck permissions from the existing `permissions` table per role.
- A "Users" management page (or extension of an existing one) to invite/create a staff account and assign a role.
- No changes needed to `app_has_permission()` itself or to any of the ~19 modules' existing permission checks — they already check permissions generically, not "is this user the Owner."

This is a genuinely small build when it happens — the hard part (the permission-check plumbing) is already done throughout the codebase. The gap is purely "no UI to manage the data," which is exactly why it's cheap to defer.

## 4. What current v2 decisions must NOT assume

- **No new v2 component may hardcode an Owner-only check.** Every permission gate described in `COMPONENT_LIBRARY_SPEC.md` (Drawer, Activity Feed's new `activity-log.view`, Bulk Actions, Command Palette, Notification Badge) routes through `app_has_permission()` — none of them check `is_owner` or equivalent. This was verified while writing that spec and must hold for every future addition too.
- **The new `activity-log.view` permission** (introduced for the Activity Feed viewer) must be seeded through the same `roles`/`permissions`/`install.php` mechanism every existing permission uses — not as a special case — so it's automatically manageable once role-management UI exists, with zero rework.
- **Navigation consolidation (Phase 1)** should continue to derive visible sidebar items from `app_has_permission()` per item (as it already does), not from a hardcoded single-role assumption — so that the day a second role exists with a narrower permission set, the sidebar correctly narrows itself with no code change.
- **No component should assume "there is only ever one active user"** in ways that would break under concurrent multi-staff use — e.g., activity logging must already record *which* user performed an action (it does, since `activity_logs` is used for the Activity Feed viewer), not assume a singular implicit actor.

## 5. Trigger for revisiting

This document gets promoted from "future" to "planned" when Mewmii Bear actually hires or brings on a second staff member who needs system access — not on a schedule.
