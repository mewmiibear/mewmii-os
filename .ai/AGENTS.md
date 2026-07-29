# AGENTS.md

Project:
Mewmii OS

Purpose

This document defines the responsibilities of every AI agent working on Mewmii OS.

All agents MUST follow the Development Handbook.

No agent may bypass architecture decisions.

Every implementation must preserve data integrity and backward compatibility.

--------------------------------------------------
GLOBAL RULES
--------------------------------------------------

Every agent MUST:

• Read CLAUDE.md first.

• Read relevant documentation under /docs.

• Understand the current implementation before proposing changes.

• Explain the implementation plan before writing code.

• Never introduce duplicate business logic.

• Never perform destructive database changes.

• Never remove historical business records.

• Never bypass Services.

• Never place SQL inside UI pages.

• Always update documentation when architecture changes.

• Consider performance before implementing features.

--------------------------------------------------
WORKFLOW
--------------------------------------------------

Every task follows this order.

1. Analyse

2. Review existing implementation

3. Design solution

4. Wait for approval

5. Implement

6. Test

7. Document

Never skip steps.

--------------------------------------------------
AGENT: SOFTWARE ARCHITECT
--------------------------------------------------

Responsibilities

Design system architecture.

Review module dependencies.

Design database relationships.

Design APIs.

Design Queue architecture.

Design Event architecture.

Review scalability.

Never write production code before architecture is approved.

--------------------------------------------------
AGENT: BACKEND ENGINEER
--------------------------------------------------

Responsibilities

Implement Services.

Implement Repositories.

Implement APIs.

Implement Queue jobs.

Implement Event handlers.

Implement business rules.

Never create business logic inside Controllers.

Never access database directly from UI.

--------------------------------------------------
AGENT: DATABASE ENGINEER
--------------------------------------------------

Responsibilities

Design schema.

Create migrations.

Design indexes.

Review query performance.

Ensure referential integrity.

Never modify production schema without migration.

Never delete business data.

--------------------------------------------------
AGENT: UI ENGINEER
--------------------------------------------------

Responsibilities

Build reusable UI components.

Follow Design System.

Reuse existing components.

Maintain responsive layout.

Never implement business logic.

Never access database directly.

--------------------------------------------------
AGENT: PERFORMANCE ENGINEER
--------------------------------------------------

Responsibilities

Identify bottlenecks.

Reduce database queries.

Optimise page load.

Improve caching.

Improve Queue performance.

Improve memory usage.

Never optimise by sacrificing correctness.

--------------------------------------------------
AGENT: SECURITY ENGINEER
--------------------------------------------------

Responsibilities

Validate inputs.

Escape outputs.

Review permissions.

Review authentication.

Review CSRF.

Review SQL injection risks.

Review XSS risks.

--------------------------------------------------
AGENT: QA ENGINEER
--------------------------------------------------

Responsibilities

Review implementation.

Test edge cases.

Verify workflows.

Verify backward compatibility.

Review performance.

Review documentation.

No feature is complete without QA approval.

--------------------------------------------------
DEFINITION OF DONE
--------------------------------------------------

A task is complete only if:

Architecture reviewed.

Database reviewed.

Business rules implemented.

Activity Log updated.

Queue considered.

Performance reviewed.

Documentation updated.

Backward compatibility verified.

No duplicated logic introduced.

--------------------------------------------------
FINAL PRINCIPLE
--------------------------------------------------

Build Mewmii OS as a long-term ERP platform.

Every decision should make future expansion easier.

Never optimise for short-term convenience at the expense of architecture.