# MEWMII OS DEVELOPMENT HANDBOOK

Version: 2.0

Project:
Mewmii OS

Purpose:
Define the engineering standards and development principles for Mewmii OS.

Mewmii OS is not a simple inventory system.

It is an ERP platform designed for Japanese merchandise businesses with:

- Preorder workflow
- Multi-supplier purchasing
- Multi-currency cost management
- Inventory management
- Warehouse workflow
- WooCommerce synchronization
- Customer order management
- Future AI automation

Every development decision must prioritize:

1. Stability
2. Data Integrity
3. Performance
4. Scalability
5. Maintainability


---

# 1. CORE PRINCIPLES

Every feature must be:

- Reusable
- Maintainable
- Extendable
- Testable
- Backward compatible


Never:

- Duplicate business logic
- Mix UI and business logic
- Put SQL inside views
- Modify database without migration
- Delete important business history


---

# 2. ARCHITECTURE PRINCIPLE

Mewmii OS follows a layered architecture.
