<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/purchase_planning.php';
app_require_permission('supplier-orders.manage');

/**
 * Phase 8A - "Create Supplier Order" quick action from a Purchasing Dashboard recommendation
 * group (modules/purchasing/index.php). Reuses purchase_planning_generate() (includes/
 * purchase_planning.php) exactly as-is - the same function modules/purchase-planning/
 * generate.php's manual review screen already calls - so a draft created from here goes
 * through the one existing supplier-order-creation code path, never a second one.
 *
 * Unlike the manual review screen, there is no per-row human review before this fires, so
 * this endpoint rebuilds the supplier's current needs LIVE from purchase_planning_needs()
 * (never trusting quantity/cost values carried in a POST body, which could be stale by the
 * time the button is clicked) and adds its own "skip products without valid cost" guard - see
 * below. purchase_planning_generate() itself is untouched, so the manual screen's own
 * behaviour (which lets an admin knowingly submit a $0 line after seeing it) is unaffected.
 */

$pdo = app_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('/modules/purchasing/index.php');
}

try {
    app_require_csrf();
} catch (RuntimeException $exception) {
    app_redirect('/modules/purchasing/index.php?create_order_error=' . rawurlencode($exception->getMessage()));
}

$supplierIdInput = trim((string) ($_POST['supplier_id'] ?? ''));
$supplierId = $supplierIdInput !== '' && ctype_digit($supplierIdInput) ? (int) $supplierIdInput : null;

// Supplier required - the button is only ever rendered for a real supplier group, but this
// endpoint is a plain POST target, so the check is repeated server-side rather than trusted.
if ($supplierId === null) {
    app_redirect('/modules/purchasing/index.php?create_order_error=' . rawurlencode('Select a supplier.'));
}

$allNeeds = purchase_planning_needs($pdo);
$supplierNeeds = array_values(array_filter(
    $allNeeds,
    static fn (array $need): bool => $need['supplier_id'] === $supplierId
));

if ($supplierNeeds === []) {
    app_redirect('/modules/purchasing/index.php?create_order_error=' . rawurlencode('Nothing currently needs ordering for this supplier.'));
}

$selectedLines = [];
$skippedCount = 0;
foreach ($supplierNeeds as $need) {
    // Quantity > 0 - purchase_planning_needs() only ever returns a positive suggested_quantity,
    // but this is re-checked here rather than assumed.
    if ((int) $need['suggested_quantity'] < 1) {
        continue;
    }
    // Skip products without a valid cost: a $0/unset unit cost would create a supplier order
    // line with no purchase value at all. There is no per-row review step on this quick-create
    // path (unlike the manual Generate Supplier Order screen), so an invalid-cost line is
    // skipped rather than silently included or blocking the whole order.
    if ((float) $need['cost_price'] <= 0) {
        $skippedCount++;
        continue;
    }

    $selectedLines[] = [
        'product_id' => $need['product_id'],
        'variation_id' => $need['variation_id'],
        'supplier_id' => $need['supplier_id'],
        'quantity' => $need['suggested_quantity'],
        'supplier_price' => $need['cost_price'],
        'demand_basis' => $need['demand_basis'],
        'demand_quantity' => $need['demand_quantity'],
        'moq_top_up' => $need['moq_top_up'],
    ];
}

if ($selectedLines === []) {
    app_redirect('/modules/purchasing/index.php?create_order_error=' . rawurlencode('No products with a valid cost and quantity were available to order for this supplier.'));
}

$pdo->beginTransaction();

try {
    $createdOrderIds = purchase_planning_generate($pdo, $selectedLines);
    $pdo->commit();

    $orderId = $createdOrderIds[0];
    $suffix = $skippedCount > 0 ? ('&skipped=' . $skippedCount) : '';
    app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&created=1' . $suffix);
} catch (RuntimeException $exception) {
    $pdo->rollBack();
    app_redirect('/modules/purchasing/index.php?create_order_error=' . rawurlencode($exception->getMessage()));
} catch (Exception $exception) {
    $pdo->rollBack();
    app_redirect('/modules/purchasing/index.php?create_order_error=' . rawurlencode('Failed to create supplier order.'));
}
