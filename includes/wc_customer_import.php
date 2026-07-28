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
require_once __DIR__ . '/wc_client.php';
require_once __DIR__ . '/sync_log.php';

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
    } else {
        $matchedByIdentity = app_customer_find_existing_by_identity($pdo, [
            'phone' => $fields['phone'] ?? null,
            'email' => $fields['email'] ?? null,
            'instagram_username' => null,
        ]);
        if ($matchedByIdentity !== null) {
            $existingId = (int) $matchedByIdentity['id'];
        }
    }

    if ($existingId !== null) {
        if ($wcCustomerId > 0) {
            $pdo->prepare('UPDATE customers SET woocommerce_customer_id = ? WHERE id = ? AND (woocommerce_customer_id IS NULL OR woocommerce_customer_id = 0)')
                ->execute([$wcCustomerId, $existingId]);
        }

        $pdo->prepare('
            UPDATE customers
            SET name = COALESCE(?, name),
                email = COALESCE(?, email),
                phone = COALESCE(?, phone),
                address = COALESCE(?, address)
            WHERE id = ?
        ')->execute([$fields['name'], $fields['email'], $fields['phone'], $fields['address'], $existingId]);

        return ['id' => $existingId, 'created' => false];
    }

    $insertStmt = $pdo->prepare('
        INSERT INTO customers (woocommerce_customer_id, name, email, phone, address)
        VALUES (?, ?, ?, ?, ?)
    ');
    $insertStmt->execute([
        $wcCustomerId > 0 ? $wcCustomerId : null,
        $fields['name'] ?? '',
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
const WC_CUSTOMER_IMPORT_SYNC_TYPE = 'woocommerce_customer_import';
const WC_CUSTOMER_IMPORT_SETTING_LAST_SYNCED_AT = 'wc_customer_import_last_synced_at';
const WC_CUSTOMER_IMPORT_SETTING_LAST_RUN_SUMMARY = 'wc_customer_import_last_run_summary';
const WC_CUSTOMER_IMPORT_PAGE_SIZE = 100;
const WC_CUSTOMER_IMPORT_MAX_PAGES = 25;
const WC_CUSTOMER_IMPORT_LOCK_NAME = 'mewmii_wc_customer_import';
const WC_CUSTOMER_IMPORT_LOCK_BUSY_CODE = 42306;
const WC_CUSTOMER_IMPORT_MASTER_LOCAL_BLOCKED_CODE = 42307;
const WC_CUSTOMER_IMPORT_MASTER_LOCAL_BLOCKED_MESSAGE = 'Mewmii OS is the master source. WooCommerce customer import is disabled.';

function wc_customer_import_get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : null;
}

function wc_customer_import_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('
        INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ')->execute([$key, $value]);
}

function wc_customer_import_fetch_all(string $endpoint, array $query, callable $onPage): array
{
    $all = [];
    $page = 1;
    $fullyCompleted = false;

    while (true) {
        if ($page > WC_CUSTOMER_IMPORT_MAX_PAGES) {
            break;
        }

        $items = wc_client_get($endpoint, array_merge($query, ['per_page' => WC_CUSTOMER_IMPORT_PAGE_SIZE, 'page' => $page]));
        if (!is_array($items) || $items === []) {
            $fullyCompleted = true;
            break;
        }

        $all = array_merge($all, $items);
        $onPage($page, count($items));

        if (count($items) < WC_CUSTOMER_IMPORT_PAGE_SIZE) {
            $fullyCompleted = true;
            break;
        }

        $page++;
    }

    return ['items' => $all, 'fully_completed' => $fullyCompleted];
}

function wc_customer_import_run(PDO $pdo, bool $dryRun = false): array
{
    if (wc_client_sync_mode($pdo) === WC_CLIENT_SYNC_MODE_MASTER_LOCAL) {
        sync_log_write($pdo, WC_CUSTOMER_IMPORT_SYNC_TYPE, 'warning', null, 'action=master_local_customer_import_blocked reason=Mewmii OS is authoritative');

        throw new RuntimeException(WC_CUSTOMER_IMPORT_MASTER_LOCAL_BLOCKED_MESSAGE, WC_CUSTOMER_IMPORT_MASTER_LOCAL_BLOCKED_CODE);
    }

    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockStmt->execute([WC_CUSTOMER_IMPORT_LOCK_NAME]);

    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException(
            'Another WooCommerce customer import is already in progress - skipped this run.',
            WC_CUSTOMER_IMPORT_LOCK_BUSY_CODE
        );
    }

    try {
        return wc_customer_import_run_body($pdo, $dryRun);
    } finally {
        try {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([WC_CUSTOMER_IMPORT_LOCK_NAME]);
        } catch (Throwable $e) {
            // Lock release failure is non-fatal for this connection.
        }
    }
}

function wc_customer_import_run_body(PDO $pdo, bool $dryRun): array
{
    $stats = [
        'created' => 0,
        'updated' => 0,
        'failed' => 0,
    ];

    $syncStartedAt = gmdate('Y-m-d\TH:i:s');
    $previousCursor = wc_customer_import_get_setting($pdo, WC_CUSTOMER_IMPORT_SETTING_LAST_SYNCED_AT);

    $customersQuery = [];
    if ($previousCursor !== null) {
        $customersQuery['modified_after'] = $previousCursor;
        $customersQuery['dates_are_gmt'] = true;
    }

    try {
        $fetchResult = wc_customer_import_fetch_all('customers', $customersQuery, static function (int $page, int $count): void {});
    } catch (Throwable $e) {
        if (!$dryRun) {
            sync_log_failure($pdo, WC_CUSTOMER_IMPORT_SYNC_TYPE, 'WooCommerce customer fetch failed: ' . $e->getMessage());
            wc_customer_import_store_run_summary($pdo, $syncStartedAt, $stats);
        }

        return $stats;
    }

    $wcCustomers = $fetchResult['items'];
    $fullyCompleted = $fetchResult['fully_completed'];

    if (!$fullyCompleted && !$dryRun) {
        sync_log_write(
            $pdo,
            WC_CUSTOMER_IMPORT_SYNC_TYPE,
            'failed',
            null,
            'Stopped after ' . WC_CUSTOMER_IMPORT_MAX_PAGES . ' pages (safety limit) - more customers may remain. Cursor not advanced; re-run to continue.'
        );
        wc_customer_import_store_run_summary($pdo, $syncStartedAt, $stats);

        return $stats;
    }

    foreach ($wcCustomers as $wcCustomer) {
        try {
            $result = wc_customer_import_upsert($pdo, $wcCustomer);
            if ($result['created']) {
                $stats['created']++;
            } else {
                $stats['updated']++;
            }

            if (!$dryRun) {
                sync_log_success($pdo, WC_CUSTOMER_IMPORT_SYNC_TYPE, $result['id']);
            }
        } catch (Throwable $e) {
            $stats['failed']++;
            if (!$dryRun) {
                $wcCustomerId = (int) ($wcCustomer['id'] ?? 0);
                sync_log_failure($pdo, WC_CUSTOMER_IMPORT_SYNC_TYPE, 'WooCommerce customer #' . $wcCustomerId . ': ' . $e->getMessage());
            }
        }
    }

    if (!$dryRun) {
        wc_customer_import_store_run_summary($pdo, $syncStartedAt, $stats);

        if ($fullyCompleted) {
            wc_customer_import_set_setting($pdo, WC_CUSTOMER_IMPORT_SETTING_LAST_SYNCED_AT, $syncStartedAt);
        }
    }

    return $stats;
}

function wc_customer_import_store_run_summary(PDO $pdo, string $ranAt, array $stats): void
{
    wc_customer_import_set_setting($pdo, WC_CUSTOMER_IMPORT_SETTING_LAST_RUN_SUMMARY, json_encode([
        'ran_at' => $ranAt,
        'imported' => $stats['created'] + $stats['updated'],
        'created' => $stats['created'],
        'updated' => $stats['updated'],
        'failed' => $stats['failed'],
    ]));
}
