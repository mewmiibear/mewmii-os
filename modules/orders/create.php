<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('orders.manage');

$appTitle = 'New Order';
$error = '';
$pdo = app_db();

const ORDER_ITEM_ROWS = 6;

$form = [
    'customer_id' => '',
    'order_number' => 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
];
$itemRows = array_fill(0, ORDER_ITEM_ROWS, ['product_id' => '', 'quantity' => '']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['customer_id'] = trim((string) ($_POST['customer_id'] ?? ''));
    $form['order_number'] = trim((string) ($_POST['order_number'] ?? ''));

    $postedProductIds = $_POST['product_id'] ?? [];
    $postedQuantities = $_POST['quantity'] ?? [];

    $itemRows = [];
    $validItems = [];
    $productCache = [];

    for ($i = 0; $i < ORDER_ITEM_ROWS; $i++) {
        $rowProductId = trim((string) ($postedProductIds[$i] ?? ''));
        $rowQuantity = trim((string) ($postedQuantities[$i] ?? ''));

        $itemRows[] = ['product_id' => $rowProductId, 'quantity' => $rowQuantity];

        if ($rowProductId === '' && $rowQuantity === '') {
            continue;
        }

        if ($error === '') {
            if ($rowProductId === '' || (int) $rowProductId < 1) {
                $error = 'Row ' . ($i + 1) . ': select a product.';
            } elseif (!ctype_digit($rowQuantity) || (int) $rowQuantity < 1) {
                $error = 'Row ' . ($i + 1) . ': quantity must be a whole number of at least 1.';
            } else {
                $productId = (int) $rowProductId;

                if (!isset($productCache[$productId])) {
                    $productStmt = $pdo->prepare('SELECT id, sku, name, selling_price, product_cost FROM products WHERE id = ?');
                    $productStmt->execute([$productId]);
                    $productCache[$productId] = $productStmt->fetch(PDO::FETCH_ASSOC);
                }

                if (!$productCache[$productId]) {
                    $error = 'Row ' . ($i + 1) . ': selected product does not exist.';
                } else {
                    $validItems[] = [
                        'product' => $productCache[$productId],
                        'quantity' => (int) $rowQuantity,
                    ];
                }
            }
        }
    }

    if ($error === '' && ($form['customer_id'] === '' || (int) $form['customer_id'] < 1)) {
        $error = 'Select a customer.';
    }

    $customerId = (int) $form['customer_id'];
    if ($error === '') {
        $customerCheck = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE id = ?');
        $customerCheck->execute([$customerId]);
        if ((int) $customerCheck->fetchColumn() === 0) {
            $error = 'Selected customer does not exist.';
        }
    }

    if ($error === '') {
        if ($form['order_number'] === '' || strlen($form['order_number']) > 100) {
            $error = 'Order number is required and must be 100 characters or fewer.';
        }
    }

    if ($error === '') {
        $orderNumberCheck = $pdo->prepare('SELECT COUNT(*) FROM mewmii_orders WHERE order_number = ?');
        $orderNumberCheck->execute([$form['order_number']]);
        if ((int) $orderNumberCheck->fetchColumn() > 0) {
            $error = 'Order number already exists.';
        }
    }

    if ($error === '' && $validItems === []) {
        $error = 'Add at least one product line with a quantity.';
    }

    // Check stock availability per product (aggregated across rows) before committing.
    if ($error === '') {
        $demand = [];
        foreach ($validItems as $line) {
            $productId = (int) $line['product']['id'];
            $demand[$productId] = ($demand[$productId] ?? 0) + $line['quantity'];
        }

        foreach ($demand as $productId => $neededQty) {
            $invStmt = $pdo->prepare('SELECT available_quantity FROM mewmii_inventory WHERE product_id = ?');
            $invStmt->execute([$productId]);
            $available = (int) $invStmt->fetchColumn();

            if ($available < $neededQty) {
                $productName = $productCache[$productId]['name'] ?? ('#' . $productId);
                $error = 'Insufficient available stock for ' . $productName . ' (available: ' . $available . ', requested: ' . $neededQty . ').';
                break;
            }
        }
    }

    if ($error === '') {
        $subtotal = 0.00;
        foreach ($validItems as $line) {
            $subtotal += $line['quantity'] * (float) $line['product']['selling_price'];
        }
        $subtotal = round($subtotal, 2);

        $pdo->beginTransaction();

        try {
            $orderStmt = $pdo->prepare('
                INSERT INTO mewmii_orders (order_number, customer_id, subtotal, discount, shipping_fee, total_amount, order_date)
                VALUES (?, ?, ?, 0.00, 0.00, ?, CURDATE())
            ');
            $orderStmt->execute([$form['order_number'], $customerId, $subtotal, $subtotal]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO mewmii_order_items (order_id, product_id, quantity, selling_price, cost_snapshot)
                VALUES (?, ?, ?, ?, ?)
            ');

            foreach ($validItems as $line) {
                $itemStmt->execute([
                    $orderId,
                    (int) $line['product']['id'],
                    $line['quantity'],
                    $line['product']['selling_price'],
                    $line['product']['product_cost'],
                ]);
            }

            $pdo->commit();

            app_redirect('/modules/orders/view.php?id=' . $orderId . '&created=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create order.';
        }
    }
}

$customersStmt = $pdo->query('SELECT id, name, email FROM customers ORDER BY name ASC LIMIT 200');
$allCustomers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$productsStmt = $pdo->query('SELECT id, sku, name, selling_price FROM products ORDER BY name ASC LIMIT 200');
$allProducts = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">New Order</h2>
        <p class="text-muted mb-0">Create a customer order and check stock availability before saving.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/orders/index.php">Back to Orders</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label">Customer</label>
                <select class="form-select" name="customer_id" required>
                    <option value="">Select a customer&hellip;</option>
                    <?php foreach ($allCustomers as $customer): ?>
                        <option value="<?php echo (int) $customer['id']; ?>" <?php echo $form['customer_id'] === (string) $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($customer['name']); ?><?php if (!empty($customer['email'])): ?> (<?php echo app_escape($customer['email']); ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Order Number</label>
                <input type="text" class="form-control" name="order_number" value="<?php echo app_escape($form['order_number']); ?>" maxlength="100" required>
            </div>
        </div>

        <h5 class="mb-3">Products</h5>
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
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
                                        <?php echo app_escape($product['sku']); ?> &mdash; <?php echo app_escape($product['name']); ?> (<?php echo app_escape((string) $product['selling_price']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" class="form-control" name="quantity[]" min="1" value="<?php echo app_escape($row['quantity']); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted small">Prices are taken automatically from each product's current selling price.</p>

        <button class="btn btn-primary mt-3" type="submit">Create Order</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
