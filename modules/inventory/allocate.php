<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/customer_storage.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/order_fulfillment.php';
app_require_permission('inventory.view');

$appTitle = 'Allocate Arrived Stock';
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
    $variationStmt = $pdo->prepare("SELECT id, sku FROM product_variations WHERE id = ? AND product_id = ? LIMIT 1");
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
        $error = 'You do not have permission to allocate inventory.';
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'allocate') {
            $tickedIds = array_map('intval', $_POST['rows'] ?? []);
            $qtys = $_POST['qty'] ?? [];

            if ($tickedIds === []) {
                $error = 'Select at least one order to allocate to.';
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

                    if ($requestedTotal > (int) $row['arrived_quantity']) {
                        throw new RuntimeException(catalog_format_stock_error($pdo, 'Total allocated quantity exceeds arrived stock on hand.', $productId, $variationId, 'Arrived quantity', (int) $row['arrived_quantity'], $requestedTotal));
                    }

                    $touchedOrderIds = [];

                    foreach ($tickedIds as $orderItemId) {
                        $qty = (int) ($qtys[$orderItemId] ?? 0);

                        if ($qty < 1) {
                            continue;
                        }

                        $itemStmt = $pdo->prepare('
                            SELECT oi.id, oi.product_id, oi.variation_id, oi.quantity, o.id AS order_id, o.customer_id, o.order_status, o.order_number, o.is_historical
                            FROM mewmii_order_items oi
                            INNER JOIN mewmii_orders o ON o.id = oi.order_id
                            WHERE oi.id = ?
                            FOR UPDATE
                        ');
                        $itemStmt->execute([$orderItemId]);
                        $orderItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$orderItem) {
                            throw new RuntimeException('One of the selected order items no longer exists.');
                        }

                        $itemVariationId = $orderItem['variation_id'] !== null ? (int) $orderItem['variation_id'] : null;

                        if (
                            (int) $orderItem['product_id'] !== $productId
                            || $itemVariationId !== $variationId
                            || $orderItem['order_status'] === 'cancelled'
                            || !empty($orderItem['is_historical'])
                            || (int) $orderItem['customer_id'] < 1
                        ) {
                            throw new RuntimeException('Order ' . $orderItem['order_number'] . ' is no longer eligible for allocation.');
                        }

                        $allocated = supplier_order_item_customer_storage_allocated($pdo, $orderItemId);
                        $outstanding = (int) $orderItem['quantity'] - $allocated;

                        if ($qty > $outstanding) {
                            throw new RuntimeException('Requested quantity (' . $qty . ') exceeds the outstanding amount (' . $outstanding . ') for order ' . $orderItem['order_number'] . '.');
                        }

                        customer_storage_add($pdo, (int) $orderItem['customer_id'], $productId, $qty, null, $variationId, $orderItemId, 'arrived');

                        // See inventory_log_allocation_event() - there is no real customer
                        // notification channel in this app yet, so this is logged on the
                        // order's own timeline as the closest available stand-in.
                        inventory_log_allocation_event(
                            $pdo,
                            (int) $orderItem['order_id'],
                            'Preorder item(s) arrived and stored (qty ' . $qty . '). Customer notification: "Your preorder item has arrived and is now stored."'
                        );

                        $touchedOrderIds[(int) $orderItem['order_id']] = true;
                    }

                    foreach (array_keys($touchedOrderIds) as $touchedOrderId) {
                        order_recompute_status($pdo, $touchedOrderId);
                    }

                    $pdo->commit();

                    $redirect = '/modules/inventory/allocate.php?product_id=' . $productId
                        . ($variationId !== null ? '&variation_id=' . $variationId : '')
                        . '&allocated=1';
                    app_redirect($redirect);
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to allocate stock.';
                }
            }
        } elseif ($action === 'allocate_fifo') {
            $pdo->beginTransaction();

            try {
                $allocations = inventory_allocate_fifo($pdo, $productId, $variationId);

                if ($allocations === []) {
                    throw new RuntimeException('Nothing to allocate - no arrived stock or no outstanding orders for this item.');
                }

                $touchedOrderIds = [];
                foreach ($allocations as $allocation) {
                    inventory_log_allocation_event(
                        $pdo,
                        $allocation['order_id'],
                        'Preorder item(s) arrived and stored (qty ' . $allocation['quantity'] . '). Customer notification: "Your preorder item has arrived and is now stored."'
                    );
                    $touchedOrderIds[$allocation['order_id']] = true;
                }

                foreach (array_keys($touchedOrderIds) as $touchedOrderId) {
                    order_recompute_status($pdo, $touchedOrderId);
                }

                $pdo->commit();

                $redirect = '/modules/inventory/allocate.php?product_id=' . $productId
                    . ($variationId !== null ? '&variation_id=' . $variationId : '')
                    . '&allocated=' . count($allocations);
                app_redirect($redirect);
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to auto-allocate stock.';
            }
        } elseif ($action === 'release') {
            $releaseQty = (int) ($_POST['release_quantity'] ?? 0);

            if ($releaseQty < 1) {
                $error = 'Enter a quantity of at least 1 to release.';
            } else {
                $pdo->beginTransaction();

                try {
                    $row = inventory_get_or_create_row($pdo, $productId, $variationId);

                    if ($releaseQty > (int) $row['arrived_quantity']) {
                        throw new RuntimeException(catalog_format_stock_error($pdo, 'Cannot release more than the arrived quantity on hand.', $productId, $variationId, 'Arrived quantity', (int) $row['arrived_quantity'], $releaseQty));
                    }

                    $pdo->prepare('
                        UPDATE mewmii_inventory
                        SET arrived_quantity = arrived_quantity - ?, available_quantity = available_quantity + ?
                        WHERE product_id = ? AND variation_id <=> ?
                    ')->execute([$releaseQty, $releaseQty, $productId, $variationId]);

                    inventory_log_transaction($pdo, $productId, 'arrived_release_to_available', $releaseQty, 'manual_release', (int) ($_SESSION['user_id'] ?? 0), $variationId);

                    $pdo->commit();

                    $redirect = '/modules/inventory/allocate.php?product_id=' . $productId
                        . ($variationId !== null ? '&variation_id=' . $variationId : '')
                        . '&released=1';
                    app_redirect($redirect);
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to release stock.';
                }
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$inventoryRow = inventory_get_or_create_row($pdo, $productId, $variationId);
$arrivedQuantity = (int) $inventoryRow['arrived_quantity'];

$candidatesStmt = $pdo->prepare('
    SELECT oi.id AS order_item_id, oi.quantity, o.id AS order_id, o.order_number, o.order_date,
           c.id AS customer_id, c.name AS customer_name, c.email AS customer_email
    FROM mewmii_order_items oi
    INNER JOIN mewmii_orders o ON o.id = oi.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE oi.product_id = ? AND oi.variation_id <=> ?
      AND o.order_status <> \'cancelled\' AND o.is_historical = 0
    ORDER BY o.order_date ASC, o.id ASC, oi.id ASC
');
$candidatesStmt->execute([$productId, $variationId]);

$remainingArrived = $arrivedQuantity;
$candidates = [];
foreach ($candidatesStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
    $allocated = supplier_order_item_customer_storage_allocated($pdo, (int) $candidate['order_item_id']);
    $outstanding = (int) $candidate['quantity'] - $allocated;

    if ($outstanding < 1) {
        continue;
    }

    $default = min($outstanding, max(0, $remainingArrived));
    $remainingArrived -= $default;

    $candidate['outstanding'] = $outstanding;
    $candidate['default_qty'] = $default;
    $candidates[] = $candidate;
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Allocate Arrived Stock</h2>
        <p class="text-muted mb-0">
            <?php echo app_escape($sku); ?> &mdash; <?php echo app_escape($product['name']); ?>
            <?php if ($variationLabel !== ''): ?>
                <span class="text-muted">(<?php echo app_escape($variationLabel); ?>)</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/allocation-center.php">Back to Allocation Center</a>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Back to Inventory</a>
    </div>
</div>

<?php if (isset($_GET['allocated'])): ?>
    <div class="alert alert-success">Stock allocated to selected order(s).</div>
<?php endif; ?>
<?php if (isset($_GET['released'])): ?>
    <div class="alert alert-success">Stock released to available inventory.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-1">Arrived, Pending Allocation</h5>
    <p class="display-6 mb-0"><?php echo (int) $arrivedQuantity; ?></p>
</div>

<?php if ($canManage): ?>
    <?php if ($candidates !== []): ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Option A: Allocate Automatically (FIFO)</h5>
            <p class="text-muted small mb-3">Fills the oldest outstanding orders first from the arrived stock on hand. Stops once either the orders or the stock runs out - never allocates more than what's actually arrived.</p>
            <form method="post" onsubmit="return confirm('Automatically allocate arrived stock to the oldest outstanding orders first?');">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="allocate_fifo">
                <button class="btn btn-primary" type="submit">Allocate Automatically (FIFO)</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Option B: Manually Select Customers</h5>
        <?php if ($candidates === []): ?>
            <p class="text-muted mb-0">No outstanding customer orders for this product.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="allocate">

                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Ordered</th>
                            <th>Outstanding</th>
                            <th>Allocate Qty</th>
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

                <button class="btn btn-primary" type="submit">Allocate Selected</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card p-4">
        <h5 class="mb-3">Release to Available Stock</h5>
        <p class="text-muted small">Move arrived stock that isn't earmarked for a customer order (e.g. MOQ/top-up buffer) into general available stock.</p>
        <form method="post" class="d-flex gap-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="release">

            <div>
                <label class="form-label">Quantity</label>
                <input type="number" class="form-control" name="release_quantity" min="1" max="<?php echo (int) $arrivedQuantity; ?>" required>
            </div>

            <button class="btn btn-outline-primary" type="submit">Release</button>
        </form>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
