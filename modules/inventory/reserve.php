<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/order_fulfillment.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('inventory.view');

$appTitle = 'Reserve Available Stock';
$error = '';
$pdo = app_db();

$productId = (int) ($_GET['product_id'] ?? 0);
$variationIdParam = $_GET['variation_id'] ?? null;

if ($productId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$productStmt = $pdo->prepare('SELECT id, sku, name, catalog_type FROM products WHERE id = ? LIMIT 1');
$productStmt->execute([$productId]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$variationId = null;
$variationLabel = '';
$sku = $product['sku'];

if ($product['catalog_type'] === 'variable') {
    $variationId = (int) ($variationIdParam ?? 0);
    $variationStmt = $pdo->prepare('SELECT id, sku FROM product_variations WHERE id = ? AND product_id = ? LIMIT 1');
    $variationStmt->execute([$variationId, $productId]);
    $variation = $variationStmt->fetch(PDO::FETCH_ASSOC);

    if (!$variation) {
        http_response_code(404);
        require_once __DIR__ . '/../../includes/header.php';
        echo '<div class="alert alert-danger">Variation not found for this product.</div>';
        require_once __DIR__ . '/../../includes/footer.php';
        exit;
    }

    $sku = $variation['sku'];
    $variationLabel = variation_build_label($pdo, $variationId);
} else {
    $variationId = null;
}

$canManage = app_has_permission('inventory.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !$canManage) {
        http_response_code(403);
        $error = 'You do not have permission to reserve inventory.';
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'reserve') {
            $tickedIds = array_map('intval', $_POST['rows'] ?? []);
            $qtys = $_POST['qty'] ?? [];

            if ($tickedIds === []) {
                $error = 'Select at least one order to reserve for.';
            } else {
                $pdo->beginTransaction();

                try {
                    $row = inventory_get_or_create_row($pdo, $productId, $variationId);

                    $requestedTotal = 0;
                    foreach ($tickedIds as $orderItemId) {
                        $requestedTotal += max(0, (int) ($qtys[$orderItemId] ?? 0));
                    }

                    if ($requestedTotal < 1) {
                        throw new RuntimeException('Enter a quantity for at least one selected order.');
                    }

                    if ($requestedTotal > (int) $row['available_quantity']) {
                        throw new RuntimeException(catalog_format_stock_error($pdo, 'Total requested quantity exceeds available stock on hand.', $productId, $variationId, 'Available quantity', (int) $row['available_quantity'], $requestedTotal));
                    }

                    $touchedOrderIds = [];

                    foreach ($tickedIds as $orderItemId) {
                        $qty = (int) ($qtys[$orderItemId] ?? 0);
                        if ($qty < 1) {
                            continue;
                        }

                        $orderStmt = $pdo->prepare('SELECT order_id FROM mewmii_order_items WHERE id = ?');
                        $orderStmt->execute([$orderItemId]);
                        $orderId = $orderStmt->fetchColumn();

                        inventory_reserve_order_item($pdo, $orderItemId, $qty);

                        if ($orderId !== false) {
                            $touchedOrderIds[(int) $orderId] = true;
                        }
                    }

                    foreach (array_keys($touchedOrderIds) as $touchedOrderId) {
                        order_recompute_status($pdo, $touchedOrderId);
                    }

                    $pdo->commit();

                    $redirect = '/modules/inventory/reserve.php?product_id=' . $productId
                        . ($variationId !== null ? '&variation_id=' . $variationId : '')
                        . '&reserved=1'
                        . '&order_ids=' . implode(',', array_keys($touchedOrderIds));
                    app_redirect($redirect);
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to reserve stock.';
                }
            }
        } elseif ($action === 'reserve_fifo') {
            $pdo->beginTransaction();

            try {
                $reservations = inventory_reserve_fifo($pdo, $productId, $variationId);

                if ($reservations === []) {
                    throw new RuntimeException('Nothing to reserve - no available stock or no outstanding paid orders for this item.');
                }

                $touchedOrderIds = [];
                foreach ($reservations as $reservation) {
                    order_recompute_status($pdo, $reservation['order_id']);
                    $touchedOrderIds[$reservation['order_id']] = true;
                }

                $pdo->commit();

                $redirect = '/modules/inventory/reserve.php?product_id=' . $productId
                    . ($variationId !== null ? '&variation_id=' . $variationId : '')
                    . '&reserved=' . count($reservations)
                    . '&order_ids=' . implode(',', array_keys($touchedOrderIds));
                app_redirect($redirect);
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to auto-reserve stock.';
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$inventoryRow = inventory_get_or_create_row($pdo, $productId, $variationId);
$availableQuantity = (int) $inventoryRow['available_quantity'];

// Success-message glue: which order(s) this action just touched, passed through via the
// redirect above (the ids were already computed by the POST handler - this just surfaces
// them, no new write/logic). Gated on orders.view since these link into a different module.
$canViewOrders = app_has_permission('orders.view');
// Purchase Planning link below (shown when there's demand but nothing available to reserve)
// goes to modules/purchase-planning/generate.php, which requires supplier-orders.manage -
// the destination permission, not this page's own inventory.view/manage gate.
$canManageSupplierOrders = app_has_permission('supplier-orders.manage');
$touchedOrders = [];
if (isset($_GET['order_ids']) && $_GET['order_ids'] !== '') {
    $orderIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_GET['order_ids'])))));
    if ($orderIds !== []) {
        $orderPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
        $touchedOrdersStmt = $pdo->prepare("SELECT id, order_number FROM mewmii_orders WHERE id IN ({$orderPlaceholders})");
        $touchedOrdersStmt->execute($orderIds);
        $touchedOrders = $touchedOrdersStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$candidatesStmt = $pdo->prepare("
    SELECT oi.id AS order_item_id, oi.quantity, o.id AS order_id, o.order_number, o.order_date,
           c.id AS customer_id, c.name AS customer_name, c.email AS customer_email
    FROM mewmii_order_items oi
    INNER JOIN mewmii_orders o ON o.id = oi.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE oi.product_id = ? AND oi.variation_id <=> ?
      AND o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.is_historical = 0
    ORDER BY o.order_date ASC, o.id ASC, oi.id ASC
");
$candidatesStmt->execute([$productId, $variationId]);

$remainingAvailable = $availableQuantity;
$candidates = [];
foreach ($candidatesStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
    $reserved = inventory_net_reserved($pdo, (int) $candidate['order_id'], $productId, $variationId);
    $outstanding = (int) $candidate['quantity'] - $reserved;

    if ($outstanding < 1) {
        continue;
    }

    $default = min($outstanding, max(0, $remainingAvailable));
    $remainingAvailable -= $default;

    $candidate['outstanding'] = $outstanding;
    $candidate['default_qty'] = $default;
    $candidates[] = $candidate;
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Reserve Available Stock</h2>
        <p class="text-muted mb-0">
            <?php echo app_escape($sku); ?> &mdash; <?php echo app_escape($product['name']); ?>
            <?php if ($variationLabel !== ''): ?>
                <span class="text-muted">(<?php echo app_escape($variationLabel); ?>)</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/reservation-center.php">Back to Reservation Center</a>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Back to Inventory</a>
    </div>
</div>

<?php if (isset($_GET['reserved'])): ?>
    <div class="alert alert-success">
        Stock reserved for selected order(s).
        <?php if ($touchedOrders !== []): ?>
            <div class="mt-2 d-flex gap-1 flex-wrap">
                <?php foreach ($touchedOrders as $touchedOrder): ?>
                    <?php if ($canViewOrders): ?>
                        <a class="btn btn-sm btn-outline-success" href="/modules/orders/view.php?id=<?php echo (int) $touchedOrder['id']; ?>"><?php echo app_escape($touchedOrder['order_number']); ?></a>
                    <?php else: ?>
                        <span class="badge bg-success"><?php echo app_escape($touchedOrder['order_number']); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-1">Available</h5>
    <p class="display-6 mb-0"><?php echo (int) $availableQuantity; ?></p>
</div>

<?php if ($availableQuantity <= 0 && $candidates !== [] && $canManageSupplierOrders): ?>
    <div class="alert alert-warning">
        No stock available to reserve, but orders are waiting on this product -
        <a href="/modules/purchase-planning/generate.php?highlight_product_id=<?php echo (int) $productId; ?>">check Purchase Planning &rarr;</a>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
    <?php if ($candidates !== []): ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Option A: Reserve Automatically (FIFO)</h5>
            <p class="text-muted small mb-3">Fills the oldest outstanding paid orders first from the available stock on hand. Stops once either the orders or the stock runs out - never reserves more than what's actually available.</p>
            <form method="post" onsubmit="return confirm('Automatically reserve available stock for the oldest outstanding orders first?');">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="reserve_fifo">
                <button class="btn btn-primary" type="submit">Reserve Automatically (FIFO)</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="card p-4">
        <h5 class="mb-3">Option B: Manually Select Orders</h5>
        <?php if ($candidates === []): ?>
            <p class="text-muted mb-0">No outstanding paid orders for this product.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="reserve">

                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Ordered</th>
                            <th>Outstanding</th>
                            <th>Reserve Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $candidate): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input" name="rows[]" value="<?php echo (int) $candidate['order_item_id']; ?>" <?php echo $candidate['default_qty'] > 0 ? 'checked' : ''; ?>>
                                </td>
                                <td>
                                    <a href="/modules/orders/view.php?id=<?php echo (int) $candidate['order_id']; ?>"><?php echo app_escape($candidate['order_number']); ?></a>
                                    <div class="text-muted small"><?php echo app_escape($candidate['order_date'] ?? '-'); ?></div>
                                </td>
                                <td>
                                    <?php echo app_escape($candidate['customer_name']); ?>
                                    <div class="text-muted small"><?php echo app_escape($candidate['customer_email'] ?? ''); ?></div>
                                </td>
                                <td><?php echo (int) $candidate['quantity']; ?></td>
                                <td><?php echo (int) $candidate['outstanding']; ?></td>
                                <td style="width: 120px;">
                                    <input type="number" class="form-control form-control-sm" name="qty[<?php echo (int) $candidate['order_item_id']; ?>]" min="1" max="<?php echo (int) $candidate['outstanding']; ?>" value="<?php echo (int) $candidate['default_qty']; ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <button class="btn btn-primary" type="submit">Reserve Selected</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
