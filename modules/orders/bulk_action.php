<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/order_fulfillment.php';
app_require_permission('orders.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('/modules/orders/index.php');
}

try {
    app_require_csrf();
} catch (RuntimeException $exception) {
    app_redirect('/modules/orders/index.php');
}

$pdo = app_db();
$action = (string) ($_POST['bulk_action'] ?? '');
$orderIds = array_values(array_unique(array_filter(array_map('intval', $_POST['order_ids'] ?? []), static fn (int $id): bool => $id > 0)));

// UI/UX Phase 5E.1: only 'approve_payment' is supported today - the exact three functions
// named in the brief (apply_payment_status_change()/inventory_reserve_for_order_partial()/
// order_recompute_status(), via order_approve_payment()), looped per order. Reject Payment
// stays single-order only (modules/orders/view.php) since it isn't part of this scope.
if ($action !== 'approve_payment' || $orderIds === []) {
    app_redirect('/modules/orders/index.php');
}

$results = order_bulk_approve_payment($pdo, $orderIds);

// Order numbers for a readable summary - one lookup for the whole batch rather than per row.
$orderNumbersById = [];
if ($results !== []) {
    $placeholders = implode(',', array_fill(0, count($results), '?'));
    $numberStmt = $pdo->prepare("SELECT id, order_number FROM mewmii_orders WHERE id IN ({$placeholders})");
    $numberStmt->execute(array_column($results, 'order_id'));
    foreach ($numberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderNumbersById[(int) $row['id']] = $row['order_number'];
    }
}
foreach ($results as &$result) {
    $result['order_number'] = $orderNumbersById[$result['order_id']] ?? ('#' . $result['order_id']);
}
unset($result);

// One-time flash - read and cleared by modules/orders/index.php on the next request, same
// "store the detailed result, keep the URL clean" reasoning a long per-order failure list
// would otherwise force onto the query string.
$_SESSION['orders_bulk_result'] = $results;

app_redirect('/modules/orders/index.php?bulk_result=1');
