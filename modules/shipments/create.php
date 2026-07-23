<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/shipments.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('shipments.manage');

/**
 * Create Shipment: two entry modes.
 * - order_id given: source_type = 'order' - pick from that order's still-shippable
 *   reserved (ready_stock) items and stored (preorder/early_bird) customer_storage lots.
 *   Replaces the old order-level "Mark Shipped" action - see modules/orders/view.php.
 * - no order_id: source_type = 'manual' - a replacement/warranty shipment not tied to any
 *   order, pick a customer and freeform product/quantity lines. Scoped to just creating the
 *   tracked shipment record - it does not attempt to auto-derive inventory consumption,
 *   since a manual shipment isn't tied to a specific existing reservation.
 */

$appTitle = 'Create Shipment';
$error = '';
$pdo = app_db();

$orderId = isset($_GET['order_id']) && ctype_digit((string) $_GET['order_id']) ? (int) $_GET['order_id'] : null;
$order = null;

if ($orderId !== null) {
    $orderStmt = $pdo->prepare('SELECT o.*, c.name AS customer_name FROM mewmii_orders o INNER JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        require_once __DIR__ . '/../../includes/header.php';
        echo '<div class="alert alert-danger">Order not found.</div>';
        require_once __DIR__ . '/../../includes/footer.php';
        exit;
    }
    if (!empty($order['is_historical'])) {
        http_response_code(400);
        require_once __DIR__ . '/../../includes/header.php';
        echo '<div class="alert alert-danger">This is a historical (imported) order - it cannot be shipped through this workflow.</div>';
        require_once __DIR__ . '/../../includes/footer.php';
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '') {
        $customerId = $order !== null ? (int) $order['customer_id'] : (int) ($_POST['customer_id'] ?? 0);
        $sourceType = $order !== null ? 'order' : 'manual';
        $orderItemLines = $_POST['order_item'] ?? [];
        $storageLines = $_POST['storage'] ?? [];
        $manualProductIds = $_POST['manual_product_id'] ?? [];
        $manualVariationIds = $_POST['manual_variation_id'] ?? [];
        $manualQtys = $_POST['manual_quantity'] ?? [];

        if ($customerId < 1) {
            $error = 'Select a customer.';
        }

        $lines = [];

        if ($error === '' && is_array($orderItemLines)) {
            foreach ($orderItemLines as $orderItemId => $qty) {
                $qty = (int) $qty;
                if ($qty < 1) {
                    continue;
                }
                $lines[] = ['order_item_id' => (int) $orderItemId, 'quantity' => $qty];
            }
        }

        if ($error === '' && is_array($storageLines)) {
            foreach ($storageLines as $storageId => $qty) {
                $qty = (int) $qty;
                if ($qty < 1) {
                    continue;
                }
                $lines[] = ['customer_storage_id' => (int) $storageId, 'quantity' => $qty];
            }
        }

        if ($error === '' && $sourceType === 'manual' && is_array($manualProductIds)) {
            foreach ($manualProductIds as $index => $productId) {
                $productId = (int) $productId;
                $qty = (int) ($manualQtys[$index] ?? 0);
                if ($productId < 1 || $qty < 1) {
                    continue;
                }
                $variationRaw = $manualVariationIds[$index] ?? '';
                $variationId = $variationRaw !== '' ? (int) $variationRaw : null;
                $lines[] = ['product_id' => $productId, 'variation_id' => $variationId, 'quantity' => $qty];
            }
        }

        if ($error === '' && $lines === []) {
            $error = 'Select at least one item and quantity to ship.';
        }

        if ($error === '') {
            $pdo->beginTransaction();

            try {
                $shipmentId = shipment_create($pdo, $customerId, $sourceType, $orderId, $lines);
                $pdo->commit();

                app_redirect('/modules/shipments/view.php?id=' . $shipmentId . '&created=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to create shipment.';
            }
        }
    }
}

$eligibleOrderItems = [];
$eligibleStorageLots = [];

if ($order !== null) {
    $itemsStmt = $pdo->prepare('
        SELECT oi.id, oi.product_id, oi.variation_id, oi.quantity, oi.variation_label,
               COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name, p.product_type
        FROM mewmii_order_items oi
        INNER JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_variations pv ON pv.id = oi.variation_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ');
    $itemsStmt->execute([$orderId]);

    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $label = $item['product_name'] . (!empty($item['variation_label']) ? ' (' . $item['variation_label'] . ')' : '');

        if (in_array($item['product_type'], ['preorder', 'early_bird'], true)) {
            $storageStmt = $pdo->prepare("
                SELECT id, arrival_date FROM customer_storage
                WHERE order_item_id = ? AND status = 'stored'
                ORDER BY created_at ASC
            ");
            $storageStmt->execute([(int) $item['id']]);
            foreach ($storageStmt->fetchAll(PDO::FETCH_ASSOC) as $lot) {
                $availableToShip = shipment_storage_lot_available_to_ship($pdo, (int) $lot['id']);
                if ($availableToShip < 1) {
                    continue;
                }
                $eligibleStorageLots[] = [
                    'storage_id' => (int) $lot['id'],
                    'sku' => $item['sku'],
                    'label' => $label,
                    'available' => $availableToShip,
                    'arrival_date' => $lot['arrival_date'],
                ];
            }
        } else {
            $availableToShip = shipment_order_item_available_to_ship($pdo, (int) $item['id']);
            if ($availableToShip < 1) {
                continue;
            }
            $eligibleOrderItems[] = [
                'order_item_id' => (int) $item['id'],
                'sku' => $item['sku'],
                'label' => $label,
                'available' => $availableToShip,
            ];
        }
    }
}

$sellableUnits = $order === null ? catalog_sellable_units($pdo) : [];

$customersStmt = $pdo->query('SELECT id, name, email FROM customers ORDER BY name ASC LIMIT 200');
$allCustomers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Create Shipment</h2>
        <p class="text-muted mb-0">
            <?php if ($order !== null): ?>
                From order <?php echo app_escape($order['order_number']); ?> (<?php echo app_escape($order['customer_name']); ?>) - only reserved/stored, not-yet-shipped items are shown.
            <?php else: ?>
                Manual shipment - not tied to any existing order (replacement/warranty).
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo $order !== null ? '/modules/orders/view.php?id=' . (int) $orderId : '/modules/shipments/index.php'; ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

    <?php if ($order === null): ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Customer</h5>
            <select class="form-select" name="customer_id" required>
                <option value="">Select a customer&hellip;</option>
                <?php foreach ($allCustomers as $customer): ?>
                    <option value="<?php echo (int) $customer['id']; ?>">
                        <?php echo app_escape($customer['name']); ?><?php if (!empty($customer['email'])): ?> (<?php echo app_escape($customer['email']); ?>)<?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <?php if ($order !== null): ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Ready Stock Items (Reserved)</h5>
            <?php if ($eligibleOrderItems === []): ?>
                <p class="text-muted mb-0">No reserved, not-yet-shipped ready-stock items on this order.</p>
            <?php else: ?>
                <table class="table align-middle">
                    <thead><tr><th>SKU</th><th>Product</th><th>Available To Ship</th><th>Qty</th></tr></thead>
                    <tbody>
                        <?php foreach ($eligibleOrderItems as $eligible): ?>
                            <tr>
                                <td><?php echo app_escape($eligible['sku']); ?></td>
                                <td><?php echo app_escape($eligible['label']); ?></td>
                                <td><?php echo (int) $eligible['available']; ?></td>
                                <td><input type="number" class="form-control" style="max-width: 120px;" name="order_item[<?php echo (int) $eligible['order_item_id']; ?>]" min="0" max="<?php echo (int) $eligible['available']; ?>" value="0"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card p-4 mb-4">
            <h5 class="mb-3">Preorder / Early Bird Items (Stored)</h5>
            <?php if ($eligibleStorageLots === []): ?>
                <p class="text-muted mb-0">No stored, not-yet-shipped preorder/early-bird items on this order.</p>
            <?php else: ?>
                <table class="table align-middle">
                    <thead><tr><th>SKU</th><th>Product</th><th>Arrival</th><th>Available To Ship</th><th>Qty</th></tr></thead>
                    <tbody>
                        <?php foreach ($eligibleStorageLots as $lot): ?>
                            <tr>
                                <td><?php echo app_escape($lot['sku']); ?></td>
                                <td><?php echo app_escape($lot['label']); ?></td>
                                <td><?php echo app_escape($lot['arrival_date'] ?? '-'); ?></td>
                                <td><?php echo (int) $lot['available']; ?></td>
                                <td><input type="number" class="form-control" style="max-width: 120px;" name="storage[<?php echo (int) $lot['storage_id']; ?>]" min="0" max="<?php echo (int) $lot['available']; ?>" value="0"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Items</h5>
            <p class="text-muted small mb-3">Not tied to any existing reservation/storage - enter product, optional variation, and quantity directly.</p>
            <table class="table align-middle">
                <thead><tr><th>Product / Variation</th><th>Qty</th></tr></thead>
                <tbody>
                    <?php for ($row = 0; $row < 5; $row++): ?>
                        <tr>
                            <td>
                                <select class="form-select" name="manual_product_id[]">
                                    <option value="">-- none --</option>
                                    <?php foreach ($sellableUnits as $unit): ?>
                                        <option value="<?php echo (int) $unit['product_id']; ?>" data-variation-id="<?php echo $unit['variation_id'] !== null ? (int) $unit['variation_id'] : ''; ?>">
                                            <?php echo app_escape($unit['sku']); ?> &mdash; <?php echo app_escape($unit['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="manual_variation_id[]" value="">
                            </td>
                            <td><input type="number" class="form-control" style="max-width: 120px;" name="manual_quantity[]" min="0" value="0"></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <script>
            document.querySelectorAll('select[name="manual_product_id[]"]').forEach(function (select) {
                select.addEventListener('change', function () {
                    var option = select.options[select.selectedIndex];
                    var hidden = select.parentElement.querySelector('input[name="manual_variation_id[]"]');
                    hidden.value = option ? (option.getAttribute('data-variation-id') || '') : '';
                });
            });
        </script>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary">Create Shipment</button>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
