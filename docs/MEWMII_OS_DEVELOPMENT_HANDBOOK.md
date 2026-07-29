# MEWMII OS DEVELOPMENT HANDBOOK

Version:
2.0

Project:
Mewmii OS

Owner:
Mewmii Bear

Purpose

Mewmii OS is NOT a simple inventory system.

It is an ERP platform specifically designed for Japanese merchandise businesses with preorder, multi-supplier purchasing, inventory management, warehouse workflow, WooCommerce synchronization, accounting and future AI automation.

Every implementation MUST prioritize:

1. Stability
2. Data Integrity
3. Performance
4. Scalability
5. Maintainability

Never prioritize visual appearance over architecture.

--------------------------------------------------
CORE PRINCIPLES
--------------------------------------------------

Every feature must satisfy:

✓ Single Responsibility

✓ Reusable

✓ Extendable

✓ Testable

✓ Backward Compatible

Never duplicate business logic.

Never mix UI and business logic.

Never put SQL inside views.

Never directly modify database from UI pages.

--------------------------------------------------
ARCHITECTURE
--------------------------------------------------

Use layered architecture.

Browser

↓

Controller

↓

Service

↓

Repository

↓

Database

Business rules belong ONLY inside Services.

Repositories only access database.

Controllers only validate requests.

Views never execute SQL.

--------------------------------------------------
MODULES
--------------------------------------------------

Core Modules

Dashboard

Products

Categories

Brands

Suppliers

Supplier Orders

Purchase Orders

Incoming Shipments

Inventory

Inventory Transactions

Customer Orders

Customers

Warehouse

Finance

Reports

Settings

Notification

Activity Logs

System Jobs

WooCommerce Sync

Future Modules

AI Assistant

Forecasting

Restock Suggestion

Multi Warehouse

POS

Mobile App

--------------------------------------------------
DATABASE PRINCIPLES
--------------------------------------------------

Every table MUST have

id

created_at

updated_at

Every important action must have audit history.

Never delete business records.

Use status.

Use archived flags.

Never hard delete orders.

--------------------------------------------------
SERVICE LAYER
--------------------------------------------------

Every module has its own Service.

Example

ProductService

InventoryService

SupplierService

SupplierOrderService

CustomerOrderService

WooSyncService

FinanceService

NotificationService

ActivityService

Services contain ALL business logic.

--------------------------------------------------
QUEUE SYSTEM
--------------------------------------------------

Long-running tasks MUST NOT execute synchronously.

Use Job Queue.

Examples

WooCommerce Sync

Image Download

Image Resize

Product Import

Supplier Import

Exchange Rate Update

Report Generation

Email

Notification

Queue Status

Pending

Running

Completed

Failed

Retry

Cancelled

Dashboard must display queue progress.

--------------------------------------------------
EVENT SYSTEM
--------------------------------------------------

Business events trigger actions.

Example

Customer Order Created

↓

Reserve Inventory

↓

Create Activity

↓

Notify Dashboard

↓

Generate Supplier Order

↓

Queue Woo Sync

Never tightly couple modules.

--------------------------------------------------
API PRINCIPLES
--------------------------------------------------

Every module must expose APIs.

Future clients include

Dashboard

Mobile App

AI

External Integration

Never access database directly from frontend.

--------------------------------------------------
PERFORMANCE
--------------------------------------------------

Dashboard must never execute heavy SQL.

Dashboard uses cache.

Large tables use:

Pagination

Lazy Loading

Server-side Search

Index Database Columns

Avoid N+1 queries.

Never load thousands of records at once.

--------------------------------------------------
INVENTORY
--------------------------------------------------

Inventory is the source of truth.

Every stock movement creates:

Inventory Transaction

Reason

Reference

User

Timestamp

Balance After

Inventory can NEVER become inconsistent.

--------------------------------------------------
SUPPLIER ORDERS
--------------------------------------------------

Supplier Orders are generated from demand.

Support:

Multiple suppliers

Different currencies

Different supplier prices

Partial arrivals

Split shipments

--------------------------------------------------
CUSTOMER ORDERS
--------------------------------------------------

Customer Orders support:

Draft

Pending Payment

Paid

Reserved

Waiting Supplier

Incoming

Packing

Shipped

Completed

Cancelled

Refunded

Every status change generates Activity Log.

--------------------------------------------------
ACTIVITY LOG
--------------------------------------------------

Every important action must be logged.

Example

Product Updated

Inventory Adjusted

Supplier Order Created

Shipment Arrived

Woo Sync Completed

Payment Received

Never lose historical records.

--------------------------------------------------
NOTIFICATION
--------------------------------------------------

Notification Center supports

Inventory Low

Sync Failed

Supplier Arrival

Payment

Order Issues

Queue Completed

Notifications must be dismissible.

--------------------------------------------------
SEARCH
--------------------------------------------------

Global Search searches:

Products

SKU

Suppliers

Orders

Customers

Inventory

Settings

Search must be fast.

--------------------------------------------------
DASHBOARD
--------------------------------------------------

Dashboard is a work center.

Widgets include:

Today's Revenue

Today's Orders

Profit

Inventory Value

Pending Supplier Orders

Incoming Shipments

Low Stock

Out of Stock

Woo Sync Status

Queue Status

Exchange Rate

Recent Activity

Quick Actions

Dashboard should open in under one second.

--------------------------------------------------
UI DESIGN
--------------------------------------------------

Use reusable components.

StatCard

Button

Badge

Table

Modal

Drawer

Tabs

Timeline

Chart

Alert

Skeleton Loader

Empty State

Avoid duplicated UI.

--------------------------------------------------
CODING STANDARD
--------------------------------------------------

Readable code.

Small functions.

Meaningful names.

No duplicated logic.

No magic numbers.

No huge controllers.

No SQL in templates.

--------------------------------------------------
DATABASE MIGRATIONS
--------------------------------------------------

Never modify production schema directly.

Every schema change requires migration.

Migration must be reversible.

--------------------------------------------------
SECURITY
--------------------------------------------------

Validate inputs.

Escape outputs.

Use CSRF protection.

Role-based permissions.

Audit sensitive operations.

--------------------------------------------------
ERROR HANDLING
--------------------------------------------------

Never hide exceptions.

Log errors.

Return friendly UI messages.

Allow retry.

--------------------------------------------------
AI READY
--------------------------------------------------

Architecture must allow future AI modules.

Future AI features:

Restock Suggestion

Sales Forecast

Profit Analysis

Purchase Recommendation

Customer Behaviour

Inventory Prediction

--------------------------------------------------
WORKFLOW
--------------------------------------------------

Every new feature follows:

Analysis

↓

Architecture

↓

Database

↓

Service

↓

API

↓

UI

↓

Testing

↓

Documentation

Never skip steps.

--------------------------------------------------
CLAUDE WORKFLOW
--------------------------------------------------

Before coding:

Review current implementation.

Review database.

Review related modules.

Review backward compatibility.

Identify risks.

Then propose implementation.

Do NOT immediately write code.

--------------------------------------------------
DEFINITION OF DONE
--------------------------------------------------

A feature is complete only when:

Architecture updated

Database updated

Services updated

UI updated

Logs implemented

Queue considered

Activity implemented

Errors handled

Documentation updated

Backward compatibility verified

No duplicate logic introduced

Performance acceptable

Code reviewed

--------------------------------------------------
FINAL RULE

Always think like an ERP architect.

Never think like a page developer.

Build Mewmii OS to scale from hundreds of products to hundreds of thousands.

Every design decision should make future expansion easier.