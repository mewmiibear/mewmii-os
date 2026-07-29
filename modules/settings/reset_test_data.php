<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/activity_log.php';
app_require_permission('settings.manage');

/**
 * Settings -> Maintenance -> Reset Test Data: a one-way, whole-table wipe of Mewmii's
 * operational data (customers, orders, supplier orders, inventory ledger/stock, shipments,
 * invoices/expenses, loyalty/store-credit activity), for clearing out everything entered
 * while setting up/testing the system before it goes live for real.
 *
 * This is deliberately a different, much blunter tool than modules/settings/maintenance.php
 * (Data Cleanup): that one only ever deletes an individual record proven to have zero real
 * business history against it. This one wipes whole tables outright, which is why it's gated
 * behind a typed confirmation phrase (not just a JS confirm()) on top of the permission check.
 *
 * Never touches WooCommerce integration data:
 *   - The product catalog (products, variations, images, attributes, brands, categories,
 *     collections, tags) is never touched - it's real setup work, not test data, and every
 *     one of those tables carries its own woocommerce_*_id sync link that this must not
 *     orphan.
 *   - sync_logs and the settings table (which holds the order-import delta cursor) are
 *     never touched - wiping either would desync or replay the WooCommerce order importer.
 *   - Any customers/mewmii_orders row that already has a woocommerce_customer_id /
 *     woocommerce_order_id (i.e. came from the real, live store) is left alone. Only
 *     locally-created rows with no WooCommerce link are deleted. This does NOT reach into
 *     WooCommerce itself either way - it only ever affects Mewmii's own local copy.
 *   - suppliers, membership_tiers, and birthday_rewards are configuration/master data
 *     (set up once by staff, not generated per test transaction) and are preserved, same as
 *     the catalog. audit_logs/activity_logs are a security/audit trail and are preserved too
 *     - this action itself is recorded there, not erased from it.
 *
 * Every child table below (order items/events, customer addresses/memberships/points/
 * birthday rewards/store credit, ship requests/shipments and their line items/events) is
 * deleted purely via its own existing ON DELETE CASCADE (see database/schema.sql) by
 * deleting the top-level customers/orders/supplier_orders rows in reset_test_data_delete() -
 * nothing here reimplements that cascade by hand, so it can never drift out of sync with the
 * schema. The one exception is ship_request_items.customer_storage_id, which is RESTRICT, not
 * CASCADE - see reset_test_data_blocking_ship_request_items_condition_sql() for why those
 * specific rows are deleted explicitly instead.
 */

const RESET_TEST_DATA_CONFIRM_PHRASE = 'RESET TEST DATA';

function reset_test_data_customer_ids_sql(): string
{
    return 'SELECT id FROM customers WHERE woocommerce_customer_id IS NULL';
}

function reset_test_data_order_ids_sql(): string
{
    return 'SELECT id FROM mewmii_orders WHERE woocommerce_order_id IS NULL';
}

/**
 * ship_request_items.customer_storage_id has no ON DELETE action (RESTRICT/NO ACTION) -
 * unlike every other FK this reset relies on, which is CASCADE or SET NULL (see
 * database/schema.sql, which is not being changed here). `customers` cascades to BOTH
 * customer_storage (directly) and ship_requests -> ship_request_items (transitively) inside
 * the single DELETE FROM customers statement in reset_test_data_delete() - InnoDB does not
 * guarantee a ship_request_items row is removed before the customer_storage row it points at
 * is deleted in that same statement, so for a test customer who has a stored item already
 * tied to a Ship My Box request, that delete could fail with a foreign key constraint error.
 *
 * The column is NOT NULL, so it can't be "detached" by nulling it out - removal is the only
 * option. This targets exactly (and only) the ship_request_items rows that reference a
 * customer_storage row owned by a customer this reset is about to delete: every one of them
 * was already going to be cascade-deleted anyway once its own ship_request is removed by the
 * same customer cascade, so pre-deleting them here changes nothing about what ultimately
 * survives the reset - it only removes the specific ordering hazard.
 */
function reset_test_data_blocking_ship_request_items_condition_sql(): string
{
    // Deliberately only references customer_storage/customers, never ship_request_items
    // itself - MySQL rejects "DELETE FROM t WHERE id IN (SELECT id FROM t ...)" (error 1093,
    // "can't specify target table for update"), so this is used as a plain WHERE condition
    // (not a self-referencing subquery) by both the preview count and the delete below.
    return 'customer_storage_id IN (SELECT id FROM customer_storage WHERE customer_id IN (' . reset_test_data_customer_ids_sql() . '))';
}

function reset_test_data_count(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

/**
 * Preview counts, grouped for display - computed fresh every time (both for the page's
 * normal preview and, inside the transaction, immediately before the actual delete in
 * reset_test_data_delete(), so the "what will be deleted" numbers shown to the admin and the
 * numbers actually removed can never drift apart).
 */
function reset_test_data_sections(PDO $pdo): array
{
    $customerIds = reset_test_data_customer_ids_sql();
    $orderIds = reset_test_data_order_ids_sql();

    $customersToDelete = reset_test_data_count($pdo, 'SELECT COUNT(*) FROM customers WHERE woocommerce_customer_id IS NULL');
    $customersKept = reset_test_data_count($pdo, 'SELECT COUNT(*) FROM customers WHERE woocommerce_customer_id IS NOT NULL');
    $ordersToDelete = reset_test_data_count($pdo, 'SELECT COUNT(*) FROM mewmii_orders WHERE woocommerce_order_id IS NULL');
    $ordersKept = reset_test_data_count($pdo, 'SELECT COUNT(*) FROM mewmii_orders WHERE woocommerce_order_id IS NOT NULL');

    $loyaltyCount = reset_test_data_count($pdo, "SELECT COUNT(*) FROM customer_memberships WHERE customer_id IN ({$customerIds})")
        + reset_test_data_count($pdo, "SELECT COUNT(*) FROM point_transactions WHERE customer_id IN ({$customerIds})")
        + reset_test_data_count($pdo, "SELECT COUNT(*) FROM birthday_reward_logs WHERE customer_id IN ({$customerIds})");

    $storeCreditCount = reset_test_data_count($pdo, "SELECT COUNT(*) FROM store_credit WHERE customer_id IN ({$customerIds})")
        + reset_test_data_count($pdo, "SELECT COUNT(*) FROM store_credit_logs WHERE customer_id IN ({$customerIds})");

    $customerNote = $customersKept > 0 ? ($customersKept . ' WooCommerce-linked customer(s) kept, untouched') : null;
    $orderNote = 'includes their line items and status history' . ($ordersKept > 0 ? '; ' . $ordersKept . ' WooCommerce-linked order(s) kept, untouched' : '');

    return [
        [
            'title' => 'Customers & customer activity',
            'rows' => [
                ['label' => 'Customers (local/test only)', 'count' => $customersToDelete, 'note' => $customerNote],
                ['label' => 'Customer addresses', 'count' => reset_test_data_count($pdo, "SELECT COUNT(*) FROM customer_addresses WHERE customer_id IN ({$customerIds})"), 'note' => null],
                ['label' => 'Memberships, loyalty points & birthday rewards', 'count' => $loyaltyCount, 'note' => null],
                ['label' => 'Store credit balances & history', 'count' => $storeCreditCount, 'note' => null],
                ['label' => 'Customer storage records (Ship My Box)', 'count' => reset_test_data_count($pdo, "SELECT COUNT(*) FROM customer_storage WHERE customer_id IN ({$customerIds})"), 'note' => null],
                ['label' => 'Ship request line items referencing those storage records', 'count' => reset_test_data_count($pdo, 'SELECT COUNT(*) FROM ship_request_items WHERE ' . reset_test_data_blocking_ship_request_items_condition_sql()), 'note' => 'removed first so the storage cascade above cannot be blocked'],
                ['label' => 'Ship requests', 'count' => reset_test_data_count($pdo, "SELECT COUNT(*) FROM ship_requests WHERE customer_id IN ({$customerIds})"), 'note' => 'includes their (other) line items'],
                ['label' => 'Shipments', 'count' => reset_test_data_count($pdo, "SELECT COUNT(*) FROM shipments WHERE customer_id IN ({$customerIds})"), 'note' => 'includes their line items and status history'],
            ],
        ],
        [
            'title' => 'Orders',
            'rows' => [
                ['label' => 'Customer orders (local/test only)', 'count' => $ordersToDelete, 'note' => $orderNote],
            ],
        ],
        [
            'title' => 'Supplier Orders',
            'rows' => [
                ['label' => 'Supplier orders (all)', 'count' => reset_test_data_count($pdo, 'SELECT COUNT(*) FROM supplier_orders'), 'note' => 'includes their line items, status history & payments'],
            ],
        ],
        [
            'title' => 'Finance & Notifications',
            'rows' => [
                ['label' => 'Invoices', 'count' => reset_test_data_count($pdo, 'SELECT COUNT(*) FROM invoices'), 'note' => null],
                ['label' => 'Expenses', 'count' => reset_test_data_count($pdo, 'SELECT COUNT(*) FROM expenses'), 'note' => null],
                ['label' => 'Notifications', 'count' => reset_test_data_count($pdo, 'SELECT COUNT(*) FROM mewmii_notifications'), 'note' => null],
            ],
        ],
        [
            'title' => 'Inventory',
            'rows' => [
                ['label' => 'Inventory ledger transactions', 'count' => reset_test_data_count($pdo, 'SELECT COUNT(*) FROM inventory_transactions'), 'note' => null],
                ['label' => 'Current stock rows (reset to zero)', 'count' => reset_test_data_count($pdo, 'SELECT COUNT(*) FROM mewmii_inventory'), 'note' => 'products/variations themselves are kept'],
            ],
        ],
    ];
}

function reset_test_data_total(array $sections): int
{
    $total = 0;
    foreach ($sections as $section) {
        foreach ($section['rows'] as $row) {
            $total += $row['count'];
        }
    }

    return $total;
}

/**
 * The actual wipe. Almost every child table is removed purely via its own ON DELETE CASCADE
 * by deleting the top-level rows below - see the file-level docblock. The one exception is
 * ship_request_items.customer_storage_id, which is RESTRICT (see
 * reset_test_data_blocking_ship_request_items_condition_sql()) - those specific rows are
 * deleted explicitly, first, so the customer_storage cascade a few lines down cannot be
 * blocked by it. Every other FK touched by this function is CASCADE or ON DELETE SET NULL.
 * Orders/customers run before the independent full-table wipes below. Caller is responsible
 * for the transaction.
 */
function reset_test_data_delete(PDO $pdo): void
{
    // Must run before the customers delete below - see the docblock on
    // reset_test_data_blocking_ship_request_items_condition_sql() for why.
    $pdo->exec('DELETE FROM ship_request_items WHERE ' . reset_test_data_blocking_ship_request_items_condition_sql());

    $pdo->exec('DELETE FROM mewmii_orders WHERE woocommerce_order_id IS NULL');
    $pdo->exec('DELETE FROM customers WHERE woocommerce_customer_id IS NULL');
    $pdo->exec('DELETE FROM supplier_orders');

    $pdo->exec('DELETE FROM invoices');
    $pdo->exec('DELETE FROM expenses');
    $pdo->exec('DELETE FROM mewmii_notifications');
    $pdo->exec('DELETE FROM inventory_transactions');
    $pdo->exec('DELETE FROM mewmii_inventory');
}

$appTitle = 'Reset Test Data';
$pdo = app_db();
$error = '';
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '') {
        $phrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
        if ($phrase !== RESET_TEST_DATA_CONFIRM_PHRASE) {
            $error = 'Confirmation phrase did not match - type "' . RESET_TEST_DATA_CONFIRM_PHRASE . '" exactly (case-sensitive) to proceed. Nothing was deleted.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $sections = reset_test_data_sections($pdo);
            reset_test_data_delete($pdo);
            $pdo->commit();

            $total = reset_test_data_total($sections);
            activity_log(
                $pdo,
                'settings',
                'test_data_reset',
                null,
                'Reset test data: ' . $total . ' row(s) deleted (customers/orders local-only; supplier orders, inventory ledger/stock, shipments, finance records all wiped). WooCommerce-linked customers/orders, the product catalog, and all configuration were left untouched.'
            );

            $success = $sections;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Reset failed - nothing was deleted: ' . $exception->getMessage();
        }
    }
}

$previewSections = reset_test_data_sections($pdo);
$previewTotal = reset_test_data_total($previewSections);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Reset Test Data</h2>
        <p class="text-muted mb-0">Development-mode tool: permanently wipes Mewmii's operational data so the system can go live with a clean slate.</p>
    </div>
</div>

<div class="d-flex gap-2 mb-4">
    <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/maintenance.php">Data Cleanup</a>
    <a class="btn btn-secondary btn-sm" href="/modules/settings/reset_test_data.php">Reset Test Data</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if ($success !== null): ?>
    <div class="alert alert-success">
        <?php echo (int) reset_test_data_total($success); ?> row(s) deleted. The counts below now reflect the current (post-reset) state.
    </div>
<?php endif; ?>

<div class="alert alert-warning">
    <strong>This cannot be undone.</strong> It permanently deletes rows from the database - there is no backup or recovery step here.
    <ul class="mb-0 mt-2">
        <li>Never touches the product catalog (products, variations, images, attributes, brands, categories, collections, tags) or anything WooCommerce-linked.</li>
        <li>Customers and orders that already have a WooCommerce link (<code>woocommerce_customer_id</code> / <code>woocommerce_order_id</code>) are kept - only locally-created ones are deleted.</li>
        <li>Suppliers, membership tiers, and birthday reward program settings are configuration, not test data, and are kept.</li>
        <li>Login/security history (audit log) and admin action history (activity log) are kept - this reset is itself recorded there.</li>
    </ul>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-3">What will be deleted<?php echo $success !== null ? ' (before this reset)' : ''; ?></h5>
    <?php foreach (($success ?? $previewSections) as $section): ?>
        <h6 class="text-muted mt-3"><?php echo app_escape($section['title']); ?></h6>
        <table class="table table-sm align-middle mb-0">
            <tbody>
                <?php foreach ($section['rows'] as $row): ?>
                    <tr>
                        <td><?php echo app_escape($row['label']); ?></td>
                        <td class="text-muted small"><?php echo $row['note'] !== null ? app_escape($row['note']) : ''; ?></td>
                        <td class="text-end fw-semibold" style="width: 90px;"><?php echo (int) $row['count']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
    <div class="text-end fw-bold mt-3">Total: <?php echo (int) ($success !== null ? reset_test_data_total($success) : $previewTotal); ?> row(s)</div>
</div>

<?php if ($success === null): ?>
<div class="card p-4 border-danger">
    <h5 class="mb-3 text-danger">Confirm Reset</h5>
    <?php if ($previewTotal < 1): ?>
        <p class="text-muted mb-0">Nothing to reset - every table above is already empty.</p>
    <?php else: ?>
        <p class="text-muted small">Type <strong><?php echo app_escape(RESET_TEST_DATA_CONFIRM_PHRASE); ?></strong> exactly (case-sensitive) below to confirm.</p>
        <form method="post" id="reset-test-data-form" onsubmit="return confirm('This will permanently delete <?php echo (int) $previewTotal; ?> row(s) as listed above. This cannot be undone. Continue?');">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <div class="mb-3" style="max-width: 360px;">
                <input type="text" class="form-control" name="confirm_phrase" id="reset-test-data-phrase" autocomplete="off" placeholder="<?php echo app_escape(RESET_TEST_DATA_CONFIRM_PHRASE); ?>" required>
            </div>
            <button type="submit" class="btn btn-danger" id="reset-test-data-submit" disabled>Permanently Delete <?php echo (int) $previewTotal; ?> Row(s)</button>
        </form>
        <script>
        (function () {
            'use strict';
            // Progressive enhancement only - the real gate is the server-side phrase check
            // above, which runs regardless of JS. This just disables the button until the
            // phrase is typed exactly, so a slip can't be submitted by accident.
            var phraseInput = document.getElementById('reset-test-data-phrase');
            var submitButton = document.getElementById('reset-test-data-submit');
            var expected = <?php echo json_encode(RESET_TEST_DATA_CONFIRM_PHRASE); ?>;

            if (phraseInput && submitButton) {
                phraseInput.addEventListener('input', function () {
                    submitButton.disabled = phraseInput.value !== expected;
                });
            }
        })();
        </script>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
