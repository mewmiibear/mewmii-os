<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_variations.php';

ajax_require_permission('inventory.view');

$pdo = app_db();

$productId = (int) ($_GET['product_id'] ?? 0);
$variationId = isset($_GET['variation_id']) && (int) $_GET['variation_id'] > 0 ? (int) $_GET['variation_id'] : null;
$search = trim((string) ($_GET['search'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 20;

if ($productId < 1) {
    ajax_json(['error' => 'A product is required.'], 400);
}

$where = 'product_id = ? AND variation_id <=> ?';
$params = [$productId, $variationId];

if ($type !== '') {
    $where .= ' AND transaction_type = ?';
    $params[] = $type;
}
if ($search !== '') {
    $where .= ' AND (reason LIKE ? OR notes LIKE ? OR reference_type LIKE ?)';
    $likeTerm = '%' . $search . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE {$where}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$rowsStmt = $pdo->prepare("
    SELECT id, transaction_type, quantity, reason, notes, balance_after, reference_type, reference_id, created_at
    FROM inventory_transactions
    WHERE {$where}
    ORDER BY created_at DESC, id DESC
    LIMIT {$pageSize} OFFSET " . (($page - 1) * $pageSize) . '
');
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

// Resolve reference_type into something human where it's cheap to do so: 'order' -> the
// order number (+ a link), 'manual_adjustment'/'manual_release' -> the acting user's name
// (their reference_id is a users.id, an existing convention from the Adjust Stock handler).
// Everything else just shows the raw transaction_type, no worse than before.
$orderIds = [];
$userIds = [];
foreach ($rows as $row) {
    if ($row['reference_type'] === 'order' && $row['reference_id']) {
        $orderIds[] = (int) $row['reference_id'];
    } elseif (in_array($row['reference_type'], ['manual_adjustment', 'manual_release'], true) && $row['reference_id']) {
        $userIds[] = (int) $row['reference_id'];
    }
}

$orderNumbers = [];
if ($orderIds !== []) {
    $placeholders = implode(',', array_fill(0, count(array_unique($orderIds)), '?'));
    $stmt = $pdo->prepare("SELECT id, order_number FROM mewmii_orders WHERE id IN ($placeholders)");
    $stmt->execute(array_values(array_unique($orderIds)));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $orderNumbers[(int) $order['id']] = $order['order_number'];
    }
}

$userNames = [];
if ($userIds !== []) {
    $placeholders = implode(',', array_fill(0, count(array_unique($userIds)), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id IN ($placeholders)");
    $stmt->execute(array_values(array_unique($userIds)));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $userNames[(int) $user['id']] = $user['name'];
    }
}

$formatted = [];
foreach ($rows as $row) {
    $referenceLabel = $row['reference_type'] ?? '-';
    $referenceUrl = null;

    if ($row['reference_type'] === 'order' && isset($orderNumbers[(int) $row['reference_id']])) {
        $referenceLabel = 'Order ' . $orderNumbers[(int) $row['reference_id']];
        $referenceUrl = '/modules/orders/view.php?id=' . (int) $row['reference_id'];
    } elseif (in_array($row['reference_type'], ['manual_adjustment', 'manual_release'], true) && isset($userNames[(int) $row['reference_id']])) {
        $referenceLabel = 'By ' . $userNames[(int) $row['reference_id']];
    }

    $formatted[] = [
        'created_at' => $row['created_at'],
        'transaction_type' => $row['transaction_type'],
        'quantity' => (int) $row['quantity'],
        'reason' => $row['reason'],
        'notes' => $row['notes'],
        'balance_after' => $row['balance_after'] !== null ? (int) $row['balance_after'] : null,
        'reference_label' => $referenceLabel,
        'reference_url' => $referenceUrl,
    ];
}

$typesStmt = $pdo->prepare('SELECT DISTINCT transaction_type FROM inventory_transactions WHERE product_id = ? AND variation_id <=> ? ORDER BY transaction_type ASC');
$typesStmt->execute([$productId, $variationId]);
$types = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

ajax_json([
    'rows' => $formatted,
    'types' => $types,
    'total' => $total,
    'page' => $page,
    'page_size' => $pageSize,
]);
