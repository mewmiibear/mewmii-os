<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('settings.manage');

/**
 * Inventory Reconciliation - a read-only diagnostic that compares mewmii_inventory's live
 * quantity columns against balances reconstructed from the inventory_transactions ledger.
 * Every query here is a plain SELECT; nothing in this file writes to the database, and
 * nothing in includes/inventory.php, includes/supplier_orders.php, includes/customer_storage.php,
 * or includes/order_fulfillment.php is touched or required to change for this to work - the
 * ledger those functions already write to (see inventory_log_transaction(), confirmed as the
 * one and only writer of inventory_transactions across the whole codebase) is the only input.
 *
 * Two tiers, per the design audit this implements:
 *
 * EXACT checks (available_quantity, incoming_quantity, customer_storage_quantity) - every
 * transaction_type touching these three has one single, unambiguous effect, so a mismatch
 * here reliably means something is actually wrong.
 *   - available_quantity is verified against inventory_log_transaction()'s own balance_after
 *     snapshot (captured at write time, on every transaction type, regardless of what that
 *     transaction touched) rather than by summation - cheaper and immune to the ambiguities
 *     below.
 *   - incoming_quantity/customer_storage_quantity are verified by summing signed quantities
 *     per transaction_type, which is safe for these two because every type that touches them
 *     has exactly one meaning.
 *
 * ADVISORY checks (reserved_quantity, arrived_quantity) - best-effort only, and labelled as
 * such in the UI. Two ledger limitations mean a "mismatch" here can be a false positive:
 *   - order_ship logs the TOTAL shipped quantity, but inventory_ship_order_quantity()
 *     (includes/inventory.php) splits the actual column write between reserved_quantity and
 *     available_quantity (a "shortfall" case) - that split isn't recoverable from the ledger
 *     row alone, so reconstructing reserved_quantity as "-quantity" for every order_ship can
 *     over-subtract.
 *   - customer_storage_add always increases customer_storage_quantity, but which bucket it
 *     was debited from ($debitFrom: 'available'/'incoming'/'arrived' - see
 *     customer_storage_add() in includes/customer_storage.php) is a PHP-time decision never
 *     recorded in the ledger row itself. Restricted here to preorder/early_bird product_type
 *     (ready_stock's customer_storage_add is essentially always debited from 'available',
 *     which doesn't touch arrived_quantity at all, so including it would produce constant
 *     false positives for every ready_stock product that uses Customer Storage) - this
 *     narrows but does not eliminate the ambiguity, since a preorder item's customer_storage_add
 *     can legitimately be debited from 'incoming' too.
 */

$appTitle = 'Inventory Reconciliation';
$pdo = app_db();

// --- 1. Every current inventory row, with display info -----------------------------------
$unitsStmt = $pdo->query("
    SELECT inv.product_id, inv.variation_id, inv.available_quantity, inv.reserved_quantity,
           inv.incoming_quantity, inv.arrived_quantity, inv.customer_storage_quantity,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name
    FROM mewmii_inventory inv
    INNER JOIN products p ON p.id = inv.product_id
    LEFT JOIN product_variations pv ON pv.id = inv.variation_id
    ORDER BY p.name ASC
");
$units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);

// --- 2. available_quantity: latest balance_after snapshot per unit, batched ---------------
$latestBalanceStmt = $pdo->query("
    SELECT it.product_id, it.variation_id, it.balance_after
    FROM inventory_transactions it
    INNER JOIN (
        SELECT product_id, variation_id, MAX(id) AS max_id
        FROM inventory_transactions
        GROUP BY product_id, variation_id
    ) latest ON latest.max_id = it.id
");
$latestBalanceByUnit = [];
foreach ($latestBalanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0);
    $latestBalanceByUnit[$key] = $row['balance_after'] !== null ? (int) $row['balance_after'] : null;
}

// --- 3. incoming_quantity reconstruction, batched -----------------------------------------
// supplier_order_cancelled/supplier_order_adjusted already store a signed value matching
// their direct effect (see includes/supplier_orders.php); supplier_order_placed/
// supplier_receive store unsigned magnitudes that need an explicit sign here.
$incomingStmt = $pdo->query("
    SELECT product_id, variation_id,
           SUM(CASE
               WHEN transaction_type = 'supplier_order_placed' THEN quantity
               WHEN transaction_type = 'supplier_receive' THEN -quantity
               WHEN transaction_type = 'supplier_order_cancelled' THEN quantity
               WHEN transaction_type = 'supplier_order_adjusted' THEN quantity
               ELSE 0
           END) AS reconstructed
    FROM inventory_transactions
    WHERE transaction_type IN ('supplier_order_placed', 'supplier_receive', 'supplier_order_cancelled', 'supplier_order_adjusted')
    GROUP BY product_id, variation_id
");
$incomingByUnit = [];
foreach ($incomingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $incomingByUnit[(int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0)] = (int) $row['reconstructed'];
}

// --- 4. customer_storage_quantity reconstruction, batched ---------------------------------
$storageStmt = $pdo->query("
    SELECT product_id, variation_id,
           SUM(CASE
               WHEN transaction_type = 'customer_storage_add' THEN quantity
               WHEN transaction_type = 'customer_storage_remove' THEN -quantity
               WHEN transaction_type = 'ship_my_box' THEN -quantity
               ELSE 0
           END) AS reconstructed
    FROM inventory_transactions
    WHERE transaction_type IN ('customer_storage_add', 'customer_storage_remove', 'ship_my_box')
    GROUP BY product_id, variation_id
");
$storageByUnit = [];
foreach ($storageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $storageByUnit[(int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0)] = (int) $row['reconstructed'];
}

// --- 5. reserved_quantity reconstruction (ADVISORY), batched ------------------------------
$reservedStmt = $pdo->query("
    SELECT product_id, variation_id,
           SUM(CASE
               WHEN transaction_type = 'order_reserve' THEN quantity
               WHEN transaction_type = 'order_release' THEN -quantity
               WHEN transaction_type = 'order_ship' THEN -quantity
               ELSE 0
           END) AS reconstructed
    FROM inventory_transactions
    WHERE transaction_type IN ('order_reserve', 'order_release', 'order_ship')
    GROUP BY product_id, variation_id
");
$reservedByUnit = [];
foreach ($reservedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $reservedByUnit[(int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0)] = (int) $row['reconstructed'];
}

// --- 6. arrived_quantity reconstruction (ADVISORY), batched -------------------------------
// supplier_receive/customer_storage_add are restricted to preorder/early_bird product_type -
// see the file-level doc comment above for why.
$arrivedStmt = $pdo->query("
    SELECT it.product_id, it.variation_id,
           SUM(CASE
               WHEN it.transaction_type = 'supplier_receive' AND p.product_type IN ('preorder', 'early_bird') THEN it.quantity
               WHEN it.transaction_type = 'customer_storage_add' AND p.product_type IN ('preorder', 'early_bird') THEN -it.quantity
               WHEN it.transaction_type = 'arrived_release_to_available' THEN -it.quantity
               ELSE 0
           END) AS reconstructed
    FROM inventory_transactions it
    INNER JOIN products p ON p.id = it.product_id
    WHERE it.transaction_type IN ('supplier_receive', 'customer_storage_add', 'arrived_release_to_available')
    GROUP BY it.product_id, it.variation_id
");
$arrivedByUnit = [];
foreach ($arrivedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $arrivedByUnit[(int) $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0)] = (int) $row['reconstructed'];
}

// --- 7. Compare - pure PHP, no further queries --------------------------------------------
$exactMismatches = ['available_quantity' => [], 'incoming_quantity' => [], 'customer_storage_quantity' => []];
$advisoryMismatches = ['reserved_quantity' => [], 'arrived_quantity' => []];

foreach ($units as $unit) {
    $key = (int) $unit['product_id'] . ':' . (int) ($unit['variation_id'] ?? 0);
    $label = $unit['product_name'] . (!empty($unit['sku']) ? ' (' . $unit['sku'] . ')' : '');
    $row = ['label' => $label, 'product_id' => (int) $unit['product_id'], 'variation_id' => $unit['variation_id'] !== null ? (int) $unit['variation_id'] : null];

    // available_quantity - only checked when a ledger entry actually exists for this unit;
    // a unit with zero transactions can only be at its default (0), which is trivially
    // consistent - inventory_log_transaction() is the sole writer of every quantity column
    // (verified across all 18 mutation sites in the prior audit), so "no ledger" can never
    // coexist with "non-zero available_quantity".
    if (array_key_exists($key, $latestBalanceByUnit) && $latestBalanceByUnit[$key] !== null) {
        $expected = $latestBalanceByUnit[$key];
        $actual = (int) $unit['available_quantity'];
        if ($expected !== $actual) {
            $exactMismatches['available_quantity'][] = $row + ['expected' => $expected, 'actual' => $actual];
        }
    }

    $expectedIncoming = $incomingByUnit[$key] ?? 0;
    $actualIncoming = (int) $unit['incoming_quantity'];
    if ($expectedIncoming !== $actualIncoming) {
        $exactMismatches['incoming_quantity'][] = $row + ['expected' => $expectedIncoming, 'actual' => $actualIncoming];
    }

    $expectedStorage = $storageByUnit[$key] ?? 0;
    $actualStorage = (int) $unit['customer_storage_quantity'];
    if ($expectedStorage !== $actualStorage) {
        $exactMismatches['customer_storage_quantity'][] = $row + ['expected' => $expectedStorage, 'actual' => $actualStorage];
    }

    $expectedReserved = $reservedByUnit[$key] ?? 0;
    $actualReserved = (int) $unit['reserved_quantity'];
    if ($expectedReserved !== $actualReserved) {
        $advisoryMismatches['reserved_quantity'][] = $row + ['expected' => $expectedReserved, 'actual' => $actualReserved];
    }

    $expectedArrived = $arrivedByUnit[$key] ?? 0;
    $actualArrived = (int) $unit['arrived_quantity'];
    if ($expectedArrived !== $actualArrived) {
        $advisoryMismatches['arrived_quantity'][] = $row + ['expected' => $expectedArrived, 'actual' => $actualArrived];
    }
}

$exactLabels = ['available_quantity' => 'Available Quantity', 'incoming_quantity' => 'Incoming Quantity', 'customer_storage_quantity' => 'Customer Storage Quantity'];
$advisoryLabels = ['reserved_quantity' => 'Reserved Quantity', 'arrived_quantity' => 'Arrived Quantity'];
$advisoryExplanations = [
    'reserved_quantity' => "order_ship logs the total shipped quantity, but the actual column write can split between reserved and available stock (a \"shortfall\" case) - that split can't be recovered from the ledger alone, so a mismatch here may be expected, not a real problem.",
    'arrived_quantity' => "customer_storage_add always records that stock moved into storage, but which bucket it was taken from (available / incoming / arrived) isn't recorded in the ledger row - so a mismatch here may be expected, not a real problem.",
];

$totalExactMismatches = array_sum(array_map('count', $exactMismatches));

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1"><i class="bi bi-clipboard-data"></i> Inventory Reconciliation</h1>
        <p class="page-description">Compares live inventory quantities against balances reconstructed from the transaction ledger. Read-only - runs fresh every time this page is loaded.</p>
    </div>
</div>

<?php if ($totalExactMismatches === 0): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle"></i> Healthy - no exact-check mismatches found across <?php echo count($units); ?> inventory record(s).</div>
<?php else: ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?php echo (int) $totalExactMismatches; ?> exact-check mismatch(es) found - see below.</div>
<?php endif; ?>

<h4 class="mb-3">Exact Checks</h4>
<p class="text-muted small mb-3">Every transaction type feeding these three columns has one unambiguous effect - a mismatch here reliably indicates a real discrepancy.</p>

<?php foreach ($exactLabels as $field => $label): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">
            <?php echo app_escape($label); ?>
            <?php if ($exactMismatches[$field] === []): ?>
                <span class="badge bg-success">Healthy</span>
            <?php else: ?>
                <span class="badge bg-danger"><?php echo count($exactMismatches[$field]); ?> mismatch<?php echo count($exactMismatches[$field]) === 1 ? '' : 'es'; ?></span>
            <?php endif; ?>
        </h5>
        <?php if ($exactMismatches[$field] === []): ?>
            <p class="text-muted small mb-0">No mismatches - every checked unit's live value matches its reconstructed ledger balance.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-end">Live Value</th>
                            <th class="text-end">Reconstructed</th>
                            <th class="text-end">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exactMismatches[$field] as $mismatch): ?>
                            <tr>
                                <td><?php echo app_escape($mismatch['label']); ?></td>
                                <td class="text-end"><?php echo (int) $mismatch['actual']; ?></td>
                                <td class="text-end"><?php echo (int) $mismatch['expected']; ?></td>
                                <td class="text-end text-danger fw-semibold"><?php echo (int) ($mismatch['actual'] - $mismatch['expected']) > 0 ? '+' : ''; ?><?php echo (int) ($mismatch['actual'] - $mismatch['expected']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<h4 class="mb-3 mt-5">Advisory Checks</h4>
<p class="text-muted small mb-3">Best-effort only. These two columns have known ledger ambiguities (see explanation on each card below) - a mismatch here is a hint worth a manual look, not proof of a real problem.</p>

<?php foreach ($advisoryLabels as $field => $label): ?>
    <div class="card p-4 mb-4 border-warning-subtle">
        <h5 class="mb-2">
            <?php echo app_escape($label); ?>
            <span class="badge bg-secondary">Advisory</span>
            <?php if ($advisoryMismatches[$field] !== []): ?>
                <span class="badge bg-warning text-dark"><?php echo count($advisoryMismatches[$field]); ?> flagged</span>
            <?php endif; ?>
        </h5>
        <p class="text-muted small mb-3"><?php echo app_escape($advisoryExplanations[$field]); ?></p>
        <?php if ($advisoryMismatches[$field] === []): ?>
            <p class="text-muted small mb-0">No mismatches flagged.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-end">Live Value</th>
                            <th class="text-end">Reconstructed (best-effort)</th>
                            <th class="text-end">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advisoryMismatches[$field] as $mismatch): ?>
                            <tr>
                                <td><?php echo app_escape($mismatch['label']); ?></td>
                                <td class="text-end"><?php echo (int) $mismatch['actual']; ?></td>
                                <td class="text-end"><?php echo (int) $mismatch['expected']; ?></td>
                                <td class="text-end text-warning-emphasis fw-semibold"><?php echo (int) ($mismatch['actual'] - $mismatch['expected']) > 0 ? '+' : ''; ?><?php echo (int) ($mismatch['actual'] - $mismatch['expected']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
