# Mewmii OS System Overview

## Vision

Mewmii OS is a Business Operating System for Mewmii Bear.

It is designed specifically for Japanese merchandise businesses.

---

# Core Domains

Catalog

Inventory

Suppliers

Supplier Orders

Purchase Planning

Customer Orders

Shipments

Finance

Reports

WooCommerce Sync

---

# Architecture

Presentation

↓

Controllers

↓

Business Services

↓

Database

Business logic should remain reusable.

Avoid duplicate logic.

---

# Single Source of Truth

Orders

↓

Revenue

Supplier Orders

↓

Purchasing

Inventory

↓

Stock

Finance

↓

Expenses

Reports

↓

Read from every module

Never duplicate business data.

---

# Future Compatibility

Every implementation should remain compatible with:

- Multi Warehouse
- RBAC
- Multiple Currency
- API Layer
- Mobile App

Do not hardcode assumptions that prevent future expansion.