<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/purchase_planning.php';
app_require_permission('supplier-orders.manage');

$appTitle = 'Generate Supplier Order';
$error = '';
$pdo = app_db();

$productTypeLabels = ['ready_stock' => 'Ready Stock', 'preorder' => 'Preorder', 'early_bird' => 'Early Bird'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '') {
        $selectedKeys = $_POST['selected'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $costs = $_POST['unit_cost'] ?? [];
        $productIds = $_POST['product_id'] ?? [];
        $variationIds = $_POST['variation_id'] ?? [];
        $supplierIds = $_POST['supplier_id'] ?? [];
        $demandBases = $_POST['demand_basis'] ?? [];
        $demandQuantities = $_POST['demand_quantity'] ?? [];
        $moqTopUps = $_POST['moq_top_up'] ?? [];

        $selectedLines = [];
        foreach ($selectedKeys as $key) {
            if (!isset($productIds[$key])) {
                continue;
            }

            $quantity = (int) ($quantities[$key] ?? 0);
            if ($quantity < 1) {
                continue;
            }

            $rowCost = (string) ($costs[$key] ?? '');
            // Same non-negative rule modules/supplier-orders/create.php and edit.php already
            // enforce for supplier_price on every other path that creates supplier_order_items -
            // this generation path was the one place it was missing.
            if ($rowCost !== '' && (!is_numeric($rowCost) || (float) $rowCost < 0)) {
                $error = 'Unit cost must be a valid non-negative number.';
                break;
            }

            $selectedLines[] = [
                'product_id' => (int) $productIds[$key],
                'variation_id' => ((string) ($variationIds[$key] ?? '')) !== '' ? (int) $variationIds[$key] : null,
                'supplier_id' => ((string) ($supplierIds[$key] ?? '')) !== '' ? (int) $supplierIds[$key] : null,
                'quantity' => $quantity,
                'supplier_price' => (float) $rowCost,
                'demand_basis' => (string) ($demandBases[$key] ?? 'topup'),
                'demand_quantity' => (int) ($demandQuantities[$key] ?? 0),
                'moq_top_up' => (int) ($moqTopUps[$key] ?? 0),
            ];
        }

        if ($error === '' && $selectedLines === []) {
            $error = 'Select at least one product with a quantity of at least 1.';
        }

        if ($error === '') {
            $pdo->beginTransaction();

            try {
                $createdOrderIds = purchase_planning_generate($pdo, $selectedLines);
                $pdo->commit();

                if (count($createdOrderIds) === 1) {
                    app_redirect('/modules/supplier-orders/view.php?id=' . $createdOrderIds[0] . '&created=1');
                }
                app_redirect('/modules/supplier-orders/index.php?generated=' . count($createdOrderIds));
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to generate supplier order(s).';
            }
        }
    }
}

$needs = purchase_planning_needs($pdo);

$supplierIds = array_unique(array_filter(array_column($needs, 'supplier_id')));
$suppliersById = [];
if ($supplierIds !== []) {
    $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id IN ({$placeholders})");
    $stmt->execute(array_values($supplierIds));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $suppliersById[(int) $row['id']] = $row['name'];
    }
}

// Group for display: one section per supplier, plus a "No Supplier Assigned" section for
// products that can't be included in generation until a supplier is set on the product.
$groups = [];
foreach ($needs as $need) {
    $groupKey = $need['supplier_id'] ?? 0;
    $groups[$groupKey]['supplier_name'] = $need['supplier_id'] !== null ? ($suppliersById[$need['supplier_id']] ?? 'Unknown Supplier') : 'No Supplier Assigned';
    $groups[$groupKey]['items'][] = $need;
}
uasort($groups, static fn (array $a, array $b): int => strcmp($a['supplier_name'], $b['supplier_name']));

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Generate Supplier Order</h2>
        <p class="text-muted mb-0">Products where Need &gt; 0, grouped by supplier. Order Qty is pre-filled MOQ-rounded - review and adjust before generating. Expand a row for the calculation breakdown.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Back to Inventory</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if ($needs === []): ?>
    <div class="card p-4">
        <p class="text-muted mb-0">Nothing currently needs ordering. Preorder/Early Bird products need paid customer orders exceeding incoming stock; Ready Stock products need a Target Stock Level set (Product Edit page) above available + incoming stock.</p>
    </div>
<?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <?php foreach ($groups as $groupKey => $group): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">
                    <?php echo app_escape($group['supplier_name']); ?>
                    <?php if ((int) $groupKey === 0): ?>
                        <span class="badge bg-warning text-dark">Set a supplier on these products to include them</span>
                    <?php endif; ?>
                </h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Demand</th>
                                <th>Stock</th>
                                <th>Incoming</th>
                                <th>MOQ</th>
                                <th>Order Qty</th>
                                <th>Left</th>
                                <th>Unit Cost</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['items'] as $need): ?>
                                <?php
                                $rowKey = $need['key'];
                                $safeRowKey = str_replace(':', '-', $rowKey);
                                $disabled = (int) $groupKey === 0;
                                // Left = Order Qty - (Demand - Stock - Incoming) = suggested_quantity - raw_need,
                                // which is exactly the MOQ top-up already computed by purchase_planning_needs() -
                                // reused here rather than re-derived. Reflects the pre-filled Order Qty; if the
                                // admin edits Order Qty before submitting, this display value doesn't recompute
                                // live (same convention already used by the Total column below).
                                $left = $need['moq_top_up'];
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-check-input row-select" name="selected[]" value="<?php echo app_escape($rowKey); ?>" <?php echo $disabled ? 'disabled' : 'checked'; ?>>
                                        <input type="hidden" name="product_id[<?php echo app_escape($rowKey); ?>]" value="<?php echo (int) $need['product_id']; ?>">
                                        <input type="hidden" name="variation_id[<?php echo app_escape($rowKey); ?>]" value="<?php echo $need['variation_id'] !== null ? (int) $need['variation_id'] : ''; ?>">
                                        <input type="hidden" name="supplier_id[<?php echo app_escape($rowKey); ?>]" value="<?php echo $need['supplier_id'] !== null ? (int) $need['supplier_id'] : ''; ?>">
                                        <input type="hidden" name="demand_basis[<?php echo app_escape($rowKey); ?>]" value="<?php echo app_escape($need['demand_basis']); ?>">
                                        <input type="hidden" name="demand_quantity[<?php echo app_escape($rowKey); ?>]" value="<?php echo (int) $need['demand_quantity']; ?>">
                                        <input type="hidden" name="moq_top_up[<?php echo app_escape($rowKey); ?>]" value="<?php echo (int) $need['moq_top_up']; ?>">
                                    </td>
                                    <td>
                                        <?php echo app_escape($need['label'] !== null ? ($need['sku'] . ' - ' . $need['label']) : $need['sku']); ?>
                                    </td>
                                    <td><?php echo app_escape($need['sku']); ?></td>
                                    <td><?php echo app_escape((string) $need['customer_demand']); ?></td>
                                    <td><?php echo app_escape((string) $need['available_quantity']); ?></td>
                                    <td><?php echo app_escape((string) $need['incoming_quantity']); ?></td>
                                    <td><?php echo app_escape((string) $need['moq']); ?></td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm" style="width:90px;" name="quantity[<?php echo app_escape($rowKey); ?>]" min="1" value="<?php echo (int) $need['suggested_quantity']; ?>" <?php echo $disabled ? 'disabled' : ''; ?>>
                                    </td>
                                    <td class="text-muted"><?php echo app_escape((string) $left); ?></td>
                                    <td>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" style="width:100px;" name="unit_cost[<?php echo app_escape($rowKey); ?>]" value="<?php echo app_escape(number_format((float) $need['cost_price'], 2, '.', '')); ?>" <?php echo $disabled ? 'disabled' : ''; ?>>
                                    </td>
                                    <td class="text-muted">RM <?php echo app_escape(number_format($need['suggested_quantity'] * (float) $need['cost_price'], 2)); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="collapse" data-bs-target="#detail-<?php echo app_escape($safeRowKey); ?>">Details</button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="detail-<?php echo app_escape($safeRowKey); ?>">
                                    <td colspan="12" class="text-muted small py-2">
                                        Type: <?php echo app_escape($productTypeLabels[$need['product_type']] ?? $need['product_type']); ?>
                                        &nbsp;&middot;&nbsp; Need / Shortage: <?php echo app_escape((string) $need['raw_need']); ?>
                                        &nbsp;&middot;&nbsp; MOQ top-up: <?php echo app_escape((string) $need['moq_top_up']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary">Generate Supplier Order(s)</button>
    </form>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
