# Mewmii OS — Production Backup Checklist

Documentation only — no backup automation exists yet. This defines what must be backed up,
how often, and in what order to restore, so that decision doesn't have to be made during an
actual incident.

## What is critical (must be backed up)

### Database

| Table(s) | Why critical |
|---|---|
| `mewmii_orders`, `mewmii_order_items`, `mewmii_order_events` | The entire order history and business record. Irreplaceable. |
| `customers` | Customer records, contact details, WooCommerce customer linkage. |
| `mewmii_inventory`, `inventory_transactions`, `customer_storage` | The full stock ledger — available/incoming/arrived/reserved quantities and their history. Losing this means stock levels can no longer be trusted at all. |
| `supplier_orders`, `supplier_order_items` | Purchasing history and outstanding orders with suppliers. |
| `products`, `product_variations`, `product_images`, `product_attributes`, `product_attribute_values`, `categories`, `brands`, `collections`, `tags` | The product catalog. Partially reconstructable from WooCommerce (see below), but not perfectly — Mewmii-OS-specific fields (`product_type`, `moq`, `target_stock_level`, cost prices) exist only here. |
| `shipments`, `shipment_items`, `ship_requests`, `ship_request_items` | Shipment and Ship My Box history. |
| `settings` | Includes the WooCommerce delta-sync cursor (`wc_order_import_last_synced_at`) — losing this doesn't lose data, but the next sync will re-walk full history until a new cursor is established (self-healing, but worth knowing). |
| `sync_logs`, `audit_logs`, `activity_logs` | Operational and security history. Not needed for the business to keep running, but needed for any post-incident investigation — including a database-loss incident itself. |
| `users`, `roles`, `permissions`, `role_permissions` | Admin accounts and access control. |

**In short: the entire database.** There is no table in this schema that's safe to skip — even the log tables matter for investigating whatever caused a restore to be needed in the first place.

### Files

| Item | Why critical | Notes |
|---|---|---|
| `.env` (and, until Phase 4D's migration is confirmed live, `config.php`) | Without it, the application cannot connect to its own database or to WooCommerce at all | Must be backed up **separately and more securely** than the general file/database backup — it's the one thing that shouldn't sit in the same easily-accessible backup archive as everything else, since anyone with read access to that archive gets full credentials. |
| `uploads/` | Product/variation images | Regenerable from original source photos if you still have them — tedious, not existential. Confirmed via `includes/image_upload.php`: this directory holds *only* self-generated WebP product images, nothing else. |

### Explicitly NOT Mewmii OS's responsibility

**Receipt images.** Confirmed this engagement: `mewmii_orders.receipt_url` only ever stores a URL pointing to a WordPress media attachment — Mewmii OS never downloads or stores the file itself. Backing up receipt images is entirely WordPress/WooCommerce/Hostinger's responsibility for the `mewmiibear.com` site, not something a Mewmii OS backup can capture. (See Phase 4B/4D's separate audit of receipt rejection evidence retention — a related but distinct question about images WordPress *deletes*, not backs up.)

## What can be regenerated (lower priority)

- Product images in `uploads/` — only if original source files still exist elsewhere.
- `sync_logs`/`activity_logs`/`audit_logs` history older than what's needed for compliance/investigation purposes — these are append-only operational logs, not business state; losing old rows doesn't break anything going forward.
- The WooCommerce delta-sync cursor (`settings.wc_order_import_last_synced_at`) — self-heals on the next sync run (re-walks further back, bounded by the existing page-safety-ceiling), just costs one slower run.

## Recommended backup frequency

| What | Frequency | Reasoning |
|---|---|---|
| Database | **Daily**, minimum | Orders, receipts, and inventory change constantly during business hours. A daily backup bounds worst-case data loss to under 24 hours. If Hostinger's plan supports it, hourly is better given this is a live order-taking business. |
| `.env` / `config.php` | On every change only (not a recurring schedule) | These change rarely — back up immediately whenever a credential is rotated or added, store the backup somewhere access-controlled and separate from the general backup archive. |
| `uploads/` | Weekly | Product photos don't change as often as transactional data; daily is unnecessary. |

## Restore priority order

If restoring from scratch (e.g. after catastrophic server loss):

1. **Database** first — nothing else functions without it, and every other step depends on knowing what state the business was actually in.
2. **`.env`/`config.php`** — needed immediately after the database restore, to reconnect the application to it and to WooCommerce.
3. **Codebase** (this git repository) — should already be safe via git's own distributed history; not really a "backup" concern the same way the above are, but confirm the deployed commit matches what you expect.
4. **`uploads/`** — last, since the application functions (orders, inventory, sync) without product images being present; they're a UX/catalog-completeness concern, not an operational blocker.

## Where backups should be stored

- **Not on the same Hostinger account/server as production** — a backup that lives next to what it's backing up doesn't protect against account-level compromise, hosting provider incidents, or accidental deletion of the whole account.
- Check whether your Hostinger plan includes automated off-site/managed backups (many do) — if so, confirm it's actually enabled and covers the database, not just files.
- For `.env`/`config.php` specifically: a password manager or similarly access-controlled secret store is more appropriate than a general file-backup destination, given what's in it.
