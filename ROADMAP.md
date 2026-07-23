# 🐻 Mewmii OS Development Roadmap

## Phase 1 — Foundation (Core System)

Goal:
Build the base system.

- [ ] Project setup
- [ ] Database structure
- [ ] Database migrations
- [ ] User login
- [ ] User roles & permissions
- [ ] Admin dashboard
- [ ] System settings
- [ ] Audit logs

---

# Phase 2 — Product & Customer Management

Goal:
Create the main business database.

- [ ] Product Management
- [ ] Product categories
- [ ] Product tags
- [ ] Product images
- [ ] Product timeline
    - Sale start date
    - Sale end date
    - Release date
    - Expiry date
- [ ] Supplier Management
- [ ] Customer CRM

---

# Phase 3 — Order Management

Goal:
Manage customer purchases.

- [ ] Customer Orders
- [ ] Order Items
- [ ] Payment Status
- [ ] Order Status
- [ ] Order Timeline Events
- [ ] Customer Order History
- [ ] Customer Portal

---

# Phase 4 — Supplier & Preorder System

Goal:
Manage Japan supplier purchasing.

- [x] Supplier Orders
- [x] Supplier Order Items
- [x] Product MOQ Calculation
- [x] Customer Quantity Tracking
- [x] Top Up Quantity
- [ ] Estimated Supplier Payment
- [ ] Actual Supplier Payment
- [ ] Shipment Tracking
- [x] Purchase Planning (paid preorder demand + ready-stock target-level replenishment calculation, grouped by supplier)
- [x] Supplier Order Generation (one-click generation of supplier orders from Purchase Planning)
- [x] Supplier Order Workflow Improvements (Draft -> Ordered -> Partially Received -> Received/"Arrived" -> Completed, plus Cancel)
- [x] Historical Data Foundation (`is_historical` on customer orders and supplier orders, bypassing reservation/shipping/incoming/receiving)
- [x] Import Foundation (CSV import for customers, suppliers, historical customer orders, historical supplier orders, inventory opening stock - all-or-nothing validation)

---

# Phase 5 — Inventory & Warehouse

Goal:
Manage incoming and outgoing products.

- [x] Inventory System
- [x] Incoming Stock
- [x] Reserved Stock
- [x] Available Stock
- [x] Inventory Transactions (every quantity-changing write is ledger-paired, including a new opening_stock transaction type for historical baselines)
- [x] Warehouse Receiving
- [ ] Packing System
- [ ] Parcel Photos
- [ ] Shipping Status

---

# Phase 6 — Ship My Box

Goal:
Customer storage and combined shipping.

- [ ] Customer Storage
- [ ] Stored Item Management
- [ ] Create Ship My Box Request
- [ ] Select Items To Ship
- [ ] Shipping Fee Calculation
- [ ] Shipment History

---

# Phase 7 — Membership & Rewards

Goal:
Customer loyalty system.

- [ ] Membership Tiers
- [ ] Baby Bear
- [ ] Silver Bear
- [ ] Gold Bear
- [ ] VIP Bear
- [ ] Upgrade System
- [ ] Points System
- [ ] Point Transactions
- [ ] Store Credit
- [ ] Monthly Voucher
- [ ] Birthday Rewards
- [ ] VIP Birthday Gift

---

# Phase 8 — WooCommerce Integration

Goal:
Connect customer website.

- [ ] WooCommerce Product Sync
- [ ] WooCommerce Customer Sync
- [ ] WooCommerce Order Sync
- [ ] Payment Sync
- [ ] Inventory Sync
- [ ] Customer Account Sync

Mewmii OS becomes the source of truth.

---

# Phase 9 — Finance & Business Reports

Goal:
Prepare for business growth and LHDN.

- [ ] Invoice System
- [ ] Expense Tracking
- [ ] Supplier Payment Records
- [ ] Profit Calculation
- [ ] Sales Reports
- [ ] Product Performance
- [ ] Customer Reports
- [ ] Tax Export Reports

---

# Phase 10 — Intelligence & Automation

Goal:
Make operations easier.

- [ ] AI Assistant
- [ ] Sales Analysis
- [ ] Inventory Suggestions
- [ ] Low Stock Alerts
- [ ] Supplier Purchase Suggestions
- [ ] Automated Notifications

---

# Phase 11 — Mobile Experience

Goal:
Manage anywhere.

- [ ] Mobile Responsive Admin
- [ ] PWA Installation
- [ ] Mobile Dashboard
- [ ] Mobile Order Management
- [ ] Mobile Inventory Receiving

---

# Version 1.0 Release

Official Mewmii OS Launch

Includes:

✅ Product Management  
✅ Customer Management  
✅ Orders  
✅ Supplier Purchase  
✅ Inventory  
✅ Ship My Box  
✅ Membership  
✅ WooCommerce Sync  
✅ Basic Reports  