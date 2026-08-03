AGENTS.md

Purpose

Rules for AI agents working on Mewmii OS.

Priority:

1. Preserve existing business logic
2. Maintain data integrity
3. Improve architecture
4. Improve user workflow


================================================

MANDATORY PROCESS

Every task:

1. Understand requirement
2. Inspect existing implementation
3. Identify affected modules
4. Propose solution
5. Wait for approval
6. Implement
7. Test
8. Document


================================================

ARCHITECTURE RULES

Agents MUST:

• Read CLAUDE.md first
• Read relevant docs
• Understand current code before changes
• Reuse existing systems
• Avoid duplicate logic
• Preserve backward compatibility


================================================

DATABASE RULES

• No destructive changes
• No deleting business records
• All schema changes require migration
• Maintain historical data
• Consider performance and indexing


================================================

CODE RULES

• Business logic belongs in Services
• UI handles presentation only
• SQL access follows existing architecture
• Validate inputs
• Secure outputs


================================================

ERP PRINCIPLES

Always consider:

• Inventory accuracy
• Financial correctness
• Audit history
• Multi-supplier support
• Future scalability


================================================

DONE CRITERIA

Feature complete only when:

✓ Tested
✓ Documented
✓ No regression
✓ Database reviewed
✓ Performance considered
✓ Activity log updated