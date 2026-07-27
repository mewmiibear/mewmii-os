<?php

/**
 * WooCommerce -> Mewmii OS customer import (Phase 6E - genuinely new functionality; no
 * customer sync existed before this phase). Reachable only via the webhook path today
 * (customer.created/customer.updated/customer.deleted - see includes/wc_webhook.php's
 * dispatch), since customers were never in scope for the existing poll-based importers.
 *
 * Matching is the same 3-tier strategy already established by
 * wc_order_import_match_customer() (includes/wc_order_import.php), which matches a customer
 * from order billing data: WooCommerce customer id first, email fallback (case-insensitive)
 * second, create new only if neither matches. That existing function is left completely
 * unchanged - this is a parallel entrypoint for the customer-object shape a customer webhook
 * payload actually has (billing sub-object, first_name/last_name at the top level), not a
 * replacement of it.
 *
 * On an update to an already-matched customer, only name/email/phone/address are ever
 * written, and only when WooCommerce actually supplied a non-empty value (via COALESCE) -
 * notes/birthday/instagram_username (fields WooCommerce has no equivalent for, or that are
 * clearly staff-authored) are never touched, and a blank field on the incoming payload can
 * never clobber a good existing value. Matches wc_import_upsert_product()'s "only touch the
 * columns this import owns" convention in includes/wc_product_import.php.
 */

require_once __DIR__ . '/wc_order_import.php';

/**
 * @return array{name: ?string, email: ?string, phone: ?string, address: ?string} null means
 * "WooCommerce didn't supply this - leave whatever is already stored alone", never "blank it".
 */
function wc_customer_import_extract_fields(array $wcCustomer): array
{
    $billing = $wcCustomer['billing'] ?? [];
    if (!is_array($billing)) {
        $billing = [];
    }

    $name = trim(($wcCustomer['first_name'] ?? '') . ' ' . ($wcCustomer['last_name'] ?? ''));
    if ($name === '') {
        // Some webhook payloads only populate the billing sub-object's name, not the
        // top-level first_name/last_name - fall back rather than losing a real name.
        $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
    }

    $email = trim((string) ($wcCustomer['email'] ?? ($billing['email'] ?? '')));
    $phone = trim((string) ($billing['phone'] ?? ''));
    // wc_order_import_format_address() (includes/wc_order_import.php) already does exactly
    // this flattening for an order's billing block - the shape is identical here, reused
    // rather than re-implemented.
    $address = wc_order_import_format_address($billing);

    return [
        'name' => $name !== '' ? $name : null,
        'email' => $email !== '' ? $email : null,
        'phone' => $phone !== '' ? $phone : null,
        'address' => $address !== '' ? $address : null,
    ];
}

/**
 * Idempotent create-or-update for one WooCommerce customer object (the payload shape of a
 * customer.created/customer.updated webhook, or a GET /customers/{id} response - both are
 * the same resource representation). Caller (wc_webhook_dispatch_customer()) is responsible
 * for the surrounding transaction.
 *
 * @return array{id: int, created: bool}
 * @throws RuntimeException if the payload has no usable WooCommerce customer id.
 */
function wc_customer_import_upsert(PDO $pdo, array $wcCustomer): array
{
    $wcCustomerId = (int) ($wcCustomer['id'] ?? 0);
    if ($wcCustomerId < 1) {
        throw new RuntimeException('Missing WooCommerce customer ID.');
    }

    $fields = wc_customer_import_extract_fields($wcCustomer);

    $existingId = null;

    $idStmt = $pdo->prepare('SELECT id FROM customers WHERE woocommerce_customer_id = ?');
    $idStmt->execute([$wcCustomerId]);
    $matchedById = $idStmt->fetchColumn();
    if ($matchedById !== false) {
        $existingId = (int) $matchedById;
    } elseif ($fields['email'] !== null) {
        // Same case-insensitive convention as wc_order_import_match_customer() and every
        // other customer-creation path in this app.
        $emailStmt = $pdo->prepare('SELECT id FROM customers WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $emailStmt->execute([$fields['email']]);
        $matchedByEmail = $emailStmt->fetchColumn();
        if ($matchedByEmail !== false) {
            $existingId = (int) $matchedByEmail;
        }
    }

    if ($existingId !== null) {
        $pdo->prepare('
            UPDATE customers
            SET woocommerce_customer_id = ?,
                name = COALESCE(?, name),
                email = COALESCE(?, email),
                phone = COALESCE(?, phone),
                address = COALESCE(?, address)
            WHERE id = ?
        ')->execute([$wcCustomerId, $fields['name'], $fields['email'], $fields['phone'], $fields['address'], $existingId]);

        return ['id' => $existingId, 'created' => false];
    }

    $insertStmt = $pdo->prepare('
        INSERT INTO customers (woocommerce_customer_id, name, email, phone, address)
        VALUES (?, ?, ?, ?, ?)
    ');
    $insertStmt->execute([
        $wcCustomerId,
        $fields['name'] ?? 'WooCommerce Customer',
        $fields['email'],
        $fields['phone'],
        $fields['address'],
    ]);

    return ['id' => (int) $pdo->lastInsertId(), 'created' => true];
}

/**
 * WooCommerce customer.deleted webhook handling - genuinely new; no archive/anonymise
 * lifecycle existed anywhere for customers before this (unlike products - status='archived',
 * see includes/catalog.php's product_deactivate() - and orders - order_status='cancelled',
 * see modules/orders/view.php's Cancel Order action - which both already had one). Never a
 * hard delete: mewmii_orders.customer_id, customer_storage, and every other table that
 * references customers.id keep working unchanged - only this row's own PII is cleared.
 *
 * Idempotent: guarded by archived_at IS NULL, so a redelivered/retried webhook for an
 * already-archived customer is a clean no-op rather than re-clearing already-cleared fields
 * or double-logging.
 *
 * name is intentionally NOT nulled (customers.name is NOT NULL - see database/schema.sql) -
 * replaced with a clearly-labelled placeholder instead, so anything that already displays a
 * customer's name (order lists, shipment labels, customer_storage) keeps rendering correctly
 * without silently showing stale PII.
 *
 * @return int|null the local customers.id archived, or null if no local customer is linked to
 * this WooCommerce customer id (nothing to do - not an error, same convention as
 * wc_webhook_dispatch_product()'s "no SKU yet" skip).
 */
function wc_customer_import_archive_deleted(PDO $pdo, int $wcCustomerId): ?int
{
    if ($wcCustomerId < 1) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM customers WHERE woocommerce_customer_id = ? AND archived_at IS NULL');
    $stmt->execute([$wcCustomerId]);
    $customerId = $stmt->fetchColumn();

    if ($customerId === false) {
        return null;
    }

    $customerId = (int) $customerId;

    $pdo->prepare('
        UPDATE customers
        SET name = ?, email = NULL, phone = NULL, address = NULL, instagram_username = NULL,
            birthday = NULL, notes = NULL, archived_at = NOW()
        WHERE id = ?
    ')->execute(['Deleted WooCommerce Customer #' . $wcCustomerId, $customerId]);

    return $customerId;
}
