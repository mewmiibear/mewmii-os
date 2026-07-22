<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('supplier-orders.manage');

$appTitle = 'New Supplier Order';
$error = '';
$pdo = app_db();

const SUPPLIER_ORDER_ITEM_ROWS = 6;

$form = [
    'supplier_id' => '',
    'purchase_number' => 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
];
$itemRows = array_fill(0, SUPPLIER_ORDER_ITEM_ROWS, ['product_id' => '', 'quantity' => '', 'supplier_price' => '']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));
    $form['purchase_number'] = trim((string) ($_POST['purchase_number'] ?? ''));

    $postedProductIds = $_POST['product_id'] ?? [];
    $postedQuantities = $_POST['quantity'] ?? [];
    $postedPrices = $_POST['supplier_price'] ?? [];

    $itemRows = [];
    $validItems = [];

    for ($i = 0; $i < SUPPLIER_ORDER_ITEM_ROWS; $i++) {
        $rowProductId = trim((string) ($postedProductIds[$i] ?? ''));
        $rowQuantity = trim((string) ($postedQuantities[$i] ?? ''));
        $rowPrice = trim((string) ($postedPrices[$i] ?? ''));

        $itemRows[] = ['product_id' => $rowProductId, 'quantity' => $rowQuantity, 'supplier_price' => $rowPrice];

        if ($rowProductId === '' && $rowQuantity === '') {
            continue;
        }

        if ($error === '') {
            if ($rowProductId === '' || (int) $rowProductId < 1) {
                $error = 'Row ' . ($i + 1) . ': select a product.';
            } elseif (!ctype_digit($rowQuantity) || (int) $rowQuantity < 1) {
                $error = 'Row ' . ($i + 1) . ': quantity must be a whole number of at least 1.';
            } elseif ($rowPrice !== '' && (!is_numeric($rowPrice) || (float) $rowPrice < 0)) {
                $error = 'Row ' . ($i + 1) . ': price must be a valid non-negative number.';
            } else {
                $validItems[] = [
                    'product_id' => (int) $rowProductId,
                    'quantity' => (int) $rowQuantity,
                    'supplier_price' => $rowPrice !== '' ? round((float) $rowPrice, 2) : 0.00,
                ];
            }
        }
    }

    if ($error === '' && ($form['supplier_id'] === '' || (int) $form['supplier_id'] < 1)) {
        $error = 'Select a supplier.';
    }

    $supplierId = (int) $form['supplier_id'];
    if ($error === '') {
        $supplierCheck = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id = ?');
        $supplierCheck->execute([$supplierId]);
        if ((int) $supplierCheck->fetchColumn() === 0) {
            $error = 'Selected supplier does not exist.';
        }
    }

    if ($error === '') {
        if ($form['purchase_number'] === '' || strlen($form['purchase_number']) > 100) {
            $error = 'Purchase number is required and must be 100 characters or fewer.';
        }
    }

    if ($error === '') {
        $poCheck = $pdo->prepare('SELECT COUNT(*) FROM supplier_orders WHERE purchase_number = ?');
        $poCheck->execute([$form['purchase_number']]);
        if ((int) $poCheck->fetchColumn() > 0) {
            $error = 'Purchase number already exists.';
        }
    }

    if ($error === '' && $validItems === []) {
        $error = 'Add at least one product line with a quantity.';
    }

    if ($error === '') {
        $estimatedCost = 0.00;
        foreach ($validItems as $line) {
            $estimatedCost += $line['quantity'] * $line['supplier_price'];
        }

        $pdo->beginTransaction();

        try {
            $orderStmt = $pdo->prepare("
                INSERT INTO supplier_orders (supplier_id, purchase_number, status, estimated_cost, order_date)
                VALUES (?, ?, 'draft', ?, CURDATE())
            ");
            $orderStmt->execute([$supplierId, $form['purchase_number'], round($estimatedCost, 2)]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO supplier_order_items (supplier_order_id, product_id, total_quantity, supplier_price, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ');

            foreach ($validItems as $line) {
                $subtotal = round($line['quantity'] * $line['supplier_price'], 2);
                $itemStmt->execute([$orderId, $line['product_id'], $line['quantity'], $line['supplier_price'], $subtotal]);
                $itemId = (int) $pdo->lastInsertId();

                supplier_order_mark_incoming($pdo, $line['product_id'], $itemId, $line['quantity']);
            }

            $pdo->commit();

            app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&created=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create supplier order.';
        }
    }
}

$suppliersStmt = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200');
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$productsStmt = $pdo->query('SELECT id, sku, name FROM products ORDER BY name ASC LIMIT 200');
$allProducts = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">New Supplier Order</h2>
        <p class="text-muted mb-0">Create a purchase order and mark the ordered stock as incoming.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/index.php">Back to Supplier Orders</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label">Supplier</label>
                <select class="form-select" name="supplier_id" required>
                    <option value="">Select a supplier&hellip;</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Purchase Number</label>
                <input type="text" class="form-control" name="purchase_number" value="<?php echo app_escape($form['purchase_number']); ?>" maxlength="100" required>
            </div>
        </div>

        <h5 class="mb-3">Products</h5>
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Supplier Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itemRows as $row): ?>
                    <tr>
                        <td>
                            <select class="form-select" name="product_id[]">
                                <option value="">Select a product&hellip;</option>
                                <?php foreach ($allProducts as $product): ?>
                                    <option value="<?php echo (int) $product['id']; ?>" <?php echo $row['product_id'] === (string) $product['id'] ? 'selected' : ''; ?>>
                                        <?php echo app_escape($product['sku']); ?> &mdash; <?php echo app_escape($product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" class="form-control" name="quantity[]" min="1" value="<?php echo app_escape($row['quantity']); ?>">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" class="form-control" name="supplier_price[]" value="<?php echo app_escape($row['supplier_price']); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <button class="btn btn-primary mt-3" type="submit">Create Supplier Order</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
