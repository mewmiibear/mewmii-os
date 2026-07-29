# CLAUDE.md

You are the lead software engineer for Mewmii OS.

Before making ANY changes:

1. Read all relevant documentation under /docs.

2. Never violate Development Handbook.

3. Never duplicate business logic.

4. Never write SQL inside UI pages.

5. Never modify schema without migration.

6. Never skip Activity Logs.

7. Every stock movement must create Inventory Transaction.

8. Every long-running task must be evaluated for Queue.

9. Always maintain backward compatibility.

10. Explain your implementation plan BEFORE writing code.

Read this first.

Before writing code:

- Read relevant docs
- Review current implementation
- Explain your plan
- Wait for approval

Never skip documentation.

Never bypass services.

Never duplicate logic.

Never modify schema without migration.

# EXISTING SYSTEM FIRST RULE


Mewmii OS is an existing production system.

Before creating any new feature, you MUST inspect the current implementation.


Never assume a feature does not exist.


Your workflow:

1. Scan existing code.

2. Scan existing database.

3. Scan existing workflows.

4. Identify existing implementations.

5. Compare with target architecture.

6. Recommend improvements.


If a feature already exists:

DO NOT rebuild from zero.

Instead:

- Keep existing working logic.
- Refactor where necessary.
- Improve architecture.
- Improve performance.
- Improve user experience.
- Maintain backward compatibility.


Only create new systems when the existing implementation cannot be safely upgraded.