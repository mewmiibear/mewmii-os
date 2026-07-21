# 🌸 Mewmii OS

Internal Business Operating System for **Mewmii Bear**.

Mewmii OS is a custom ERP system designed to manage the complete Mewmii Bear business workflow.

The system manages:

- Products
- Customers
- Orders
- Supplier Purchases
- Inventory
- Ship My Box
- Membership
- Rewards
- Finance
- Reports


---

# 🐻 System Vision

Mewmii OS is the main business system.

WordPress WooCommerce is only used as:

- Customer shopping website
- Customer account portal
- Checkout/payment interface


Architecture:


Customer

↓

mewmiibear.com
(WordPress + WooCommerce)

↓

API Sync

↓

Mewmii OS

↓

Database



All business logic should be handled inside Mewmii OS.

---

# 🚧 Current Development Phase

## Phase 1 — Foundation

Current repository includes the first scalable foundation:

✅ Authentication and session-based login

✅ Role-aware admin access

✅ Shared bootstrap and layout components

✅ Database schema foundation

✅ Dashboard structure

✅ Module entry pages:

- Products
- Orders
- Suppliers
- Inventory
- Customers


---

# 🏗 Development Roadmap

## Phase 1
Foundation

- Authentication
- Database
- User roles
- Dashboard


## Phase 2
Core Business

- Product management
- Customer CRM
- Order management
- Order timeline


## Phase 3
Supplier & Preorder

- Supplier orders
- MOQ calculation
- Supplier payment tracking
- Shipment tracking


## Phase 4
Warehouse

- Inventory
- Receiving
- Packing
- Parcel photos


## Phase 5
Customer Experience

- Ship My Box
- Membership
- Points
- Store credit
- Birthday rewards


## Phase 6
Business Intelligence

- Analytics
- Reports
- AI Assistant


## Phase 7

Mobile:

- Responsive admin
- PWA support


---

# ⚙️ Technology

Current system:

Backend:
- PHP

Database:
- MySQL

Frontend:
- Responsive web dashboard


Future:

- API architecture
- WooCommerce REST API integration
- Mobile PWA


---

# 📦 Installation

## Requirements

- PHP environment
- MySQL database
- Web server


## Setup

1. Create or update the MySQL database.

2. Configure environment values.

3. Run installer:


http://localhost/install.php



4. Login using:

- Generated admin account

or configure:


APP_ADMIN_EMAIL
APP_ADMIN_PASSWORD


before installation.


---

# 🗂 Project Principles

## Modular

Each business module should be independent:

- Products
- Orders
- Suppliers
- Inventory
- Customers
- Membership
- Finance


## Scalable

The system should support future:

- More products
- More customers
- Multiple staff
- Mobile app
- External integrations


## Configurable

Avoid hard-coded business rules.

Important settings should be stored in the database:

Examples:

- Membership requirements
- Voucher values
- Shipping rules
- Reward rules


## Secure

System should include:

- Authentication
- Role permissions
- Data protection
- Audit records


---

# 📝 Development Notes

Current focus:

Build a strong ERP foundation first.

Do not add advanced automation before the core data structure is stable.

Future integrations:

- WooCommerce sync
- WhatsApp automation
- AI assistant
- Accounting systems
- LHDN-ready reporting


---

# 🌸 Final Goal

Mewmii OS should become the operational heart of Mewmii Bear.

From:

Product creation

↓

Customer order

↓

Supplier purchase

↓

Warehouse arrival

↓

Customer storage

↓

Shipping

↓

Payment

↓

Profit

↓

Reports


Everything should be managed from one dashboard.