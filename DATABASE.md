# Mewmii OS Database Specification

Version: 1.0

Mewmii OS is the main business system.

WordPress WooCommerce is only used for:
- Customer shopping
- Customer account portal
- Checkout/payment

All business logic is managed inside Mewmii OS.

---

# 1. Authentication & Users

## users

Stores admin/staff login accounts.

Fields:

- id
- name
- email
- password_hash
- role_id
- status
- last_login_at
- created_at
- updated_at


---

## roles

User permission groups.

Examples:
- Owner
- Manager
- Packing Staff
- Customer Service
- Accountant

Fields:

- id
- name
- description
- created_at
- updated_at


---

## permissions

Available system permissions.

Fields:

- id
- name
- module
- created_at


---

## role_permissions

Connect roles with permissions.

Fields:

- id
- role_id
- permission_id


---

# 2. Customer Management

## customers

Main customer database.

Fields:

- id
- woocommerce_customer_id
- name
- email
- phone
- instagram_username
- birthday
- address
- notes
- created_at
- updated_at


---

## customer_addresses

Stores multiple addresses.

Fields:

- id
- customer_id
- type
- address_line
- city
- state
- postcode
- country


---

# 3. Product Management

## products

Main product database.

Mewmii OS controls products.

Fields:

- id
- woocommerce_product_id
- sku
- name
- description
- product_type

Product type:
- ready_stock
- preorder
- early_bird

Pricing:

- selling_price
- product_cost

Supplier:

- supplier_id
- moq

Schedule:

- sale_start_date
- sale_end_date
- official_release_date
- estimated_arrival_date
- expiry_date

Status:

- draft
- coming_soon
- active
- preorder_closed
- expired
- hidden

Sync:

- published_to_woocommerce
- created_at
- updated_at


---

## product_images

Product image storage.

Fields:

- id
- product_id
- image_url
- sort_order


---

## product_tags

Product tags.

Examples:

- Sanrio Original
- Chiikawa
- Plush
- Stationery

Fields:

- id
- name


---

## product_tag_relationships

Connect products and tags.

Fields:

- id
- product_id
- tag_id


---

# 4. Supplier Management

## suppliers

Supplier information.

Fields:

- id
- name
- contact
- country
- notes
- created_at


---

## supplier_orders

Purchase orders sent to suppliers.

Fields:

- id
- supplier_id
- purchase_number

Status:

- draft
- waiting_payment
- ordered
- shipping
- received
- completed

Financial:

- estimated_cost
- actual_cost
- payment_date

Dates:

- order_date
- received_date

created_at
updated_at


---

## supplier_order_items

Products inside supplier purchase.

Fields:

- id
- supplier_order_id
- product_id

Quantity:

- customer_quantity
- moq_quantity
- top_up_quantity
- total_quantity

Cost:

- supplier_price
- subtotal


Example:

Customer orders:
8

MOQ:
10

Top up:
2

Total:
10


---

# 5. Customer Orders

## mewmii_orders

Main order records.

Synced from WooCommerce.

Fields:

- id
- woocommerce_order_id
- order_number
- customer_id

Payment:

- payment_status
- payment_method

Order:

- order_status
- shipping_status

Financial:

- subtotal
- discount
- shipping_fee
- total_amount

Dates:

- order_date
- created_at
- updated_at


---

## mewmii_order_items

Products inside customer order.

Fields:

- id
- order_id
- product_id
- quantity
- selling_price
- cost_snapshot


Cost snapshot keeps historical profit accuracy.


---

## mewmii_order_events

Order timeline.

Examples:

- Order Created
- Payment Received
- Supplier Ordered
- Warehouse Received
- Shipped

Fields:

- id
- order_id
- event_type
- description
- created_by
- created_at


---

# 6. Inventory Management

## mewmii_inventory

Current inventory status.

Fields:

- id
- product_id

Quantity:

- available_quantity
- reserved_quantity
- incoming_quantity
- customer_storage_quantity

updated_at


---

## inventory_transactions

Inventory history.

Fields:

- id
- product_id
- transaction_type

Examples:

- supplier_receive
- customer_order
- ship_my_box
- adjustment

Quantity:

- quantity

Reference:

- reference_type
- reference_id

created_at


---

# 7. Ship My Box

## customer_storage

Products stored for customers.

Fields:

- id
- customer_id
- product_id
- quantity

Status:

- stored
- shipped

Dates:

- arrival_date
- created_at


---

## ship_requests

Customer shipping requests.

Fields:

- id
- customer_id

Shipping:

- shipping_fee
- weight
- status

Status:

- pending
- processing
- shipped
- completed

created_at


---

## ship_request_items

Items included in shipment.

Fields:

- id
- ship_request_id
- customer_storage_id
- quantity


---

# 8. Membership System

## membership_tiers

Editable membership settings.

Examples:

Baby Bear
Silver Bear
Gold Bear
VIP Bear

Fields:

- id
- name
- upgrade_points
- duration_months

Benefits:

- monthly_voucher_amount
- birthday_voucher_amount
- free_shipping_threshold
- early_bird_access
- early_bird_discount
- birthday_gift_enabled


---

## customer_memberships

Customer membership status.

Fields:

- id
- customer_id
- membership_tier_id

Dates:

- start_date
- expiry_date

Status:

- active
- expired


---

# 9. Points System

## point_transactions

Points history.

Fields:

- id
- customer_id

Type:

- earn
- redeem
- upgrade

Points:

- amount

Reference:

- order_id
- description

created_at


---

# 10. Birthday Reward System

## birthday_rewards

Birthday reward settings.

Fields:

- id
- membership_tier_id
- days_before_birthday
- valid_days
- voucher_amount
- gift_enabled


---

## birthday_reward_logs

Track issued rewards.

Fields:

- id
- customer_id
- reward_type
- issued_date
- expiry_date
- used_date


---

# 11. Store Credit / Voucher

## store_credit

Customer balance.

Fields:

- id
- customer_id
- balance


---

## store_credit_logs

Credit history.

Fields:

- id
- customer_id
- amount
- type
- reference
- created_at


---

# 12. Finance

## invoices

Customer invoices.

Fields:

- id
- invoice_number
- order_id
- customer_id
- amount
- status
- created_at


---

## expenses

Business expenses.

Examples:

- Packaging
- Ads
- Website
- Rent
- Printing

Fields:

- id
- category
- amount
- description
- receipt_file
- expense_date
- created_at


---

# 13. WooCommerce Synchronization

## sync_logs

Tracks API synchronization.

Fields:

- id
- sync_type

Examples:

- product
- order
- customer
- inventory

Reference:

- reference_id

Status:

- success
- failed

Error:

- error_message

created_at


---

# 14. System

## mewmii_notifications

Internal notifications.

Fields:

- id
- user_id
- title
- message
- type
- read_status
- created_at


---

## settings

Global editable settings.

Examples:

- Membership rules
- Shipping rules
- Birthday rules
- Reward settings

Fields:

- id
- setting_key
- setting_value
- updated_at


---

# Database Relationship Summary

Customer:

customers
|
├── orders
├── memberships
├── points
├── store_credit
└── customer_storage


Product:

products
|
├── order_items
├── supplier_order_items
├── inventory
└── tags


Supplier:

suppliers
|
└── supplier_orders


Order:

mewmii_orders
|
├── order_items
└── order_events


---

# Development Rule

Do not hard-code business rules.

Everything important must be editable from Mewmii OS admin settings.

Mewmii OS is designed to scale into:
- Mobile App
- WhatsApp Automation
- Accounting Integration
- e-Invoice
- Multiple Staff
- Multiple Suppliers