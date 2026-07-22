<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('ship-my-box.manage');

$appTitle = 'New Ship Request';
$error = '';
$pdo = app_db();

$customerId = (int) ($_GET['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $postedQuantities = $_POST['quantity'] ?? [];

    if ($error === '' && $customerId < 1) {
        $error = 'Select a customer.';
    }

    $lines = [];
    if ($error === '' && is_array($postedQuantities)) {
        foreach ($postedQuantities as $storageId => $qty) {
            $storageId = (int) $storageId;
            $qty = trim((string) $qty);

            if ($storageId < 1 || $qty === '' || $qty === '0') {
                continue;
            }

            if (!ctype_digit($qty) || (int) $qty < 1) {
                $error = 'Quantities must be whole numbers of at least 1.';
                break;
            }

            $lines[$storageId] = (int) $qty;
        }
    }

    if ($error === '' && $lines === []) {
        $error = 'Select at least one item and quantity to ship.';
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $customerCheck = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE id = ?');
            $customerCheck->execute([$customerId]);
            if ((int) $customerCheck->fetchColumn() === 0) {
                throw new RuntimeException('Selected customer does not exist.');
            }

            $requestStmt = $pdo->prepare("INSERT INTO ship_requests (customer_id, status) VALUES (?, 'pending')");
            $requestStmt->execute([$customerId]);
            $shipRequestId = (int) $pdo->lastInsertId();

            $storageStmt = $pdo->prepare("SELECT * FROM customer_storage WHERE id = ? AND customer_id = ? AND status = 'stored' FOR UPDATE");
            $itemStmt = $pdo->prepare('INSERT INTO ship_request_items (ship_request_id, customer_storage_id, quantity) VALUES (?, ?, ?)');

            foreach ($lines as $storageId => $qty) {
                $storageStmt->execute([$storageId, $customerId]);
                $storageRow = $storageStmt->fetch(PDO::FETCH_ASSOC);

                if (!$storageRow) {
                    throw new RuntimeException('Storage record #' . $storageId . ' is not available for this customer.');
                }

                if ($qty > (int) $storageRow['quantity']) {
                    throw new RuntimeException('Requested quantity exceeds stored quantity for storage record #' . $storageId . '.');
                }

                $itemStmt->execute([$shipRequestId, $storageId, $qty]);
            }

            $pdo->commit();

            app_redirect('/modules/ship-my-box/view.php?id=' . $shipRequestId . '&created=1');
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create ship request.';
        }
    }
}

$customersStmt = $pdo->query('SELECT id, name, email FROM customers ORDER BY name ASC LIMIT 200');
$allCustomers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$storedItems = [];
$selectedCustomer = null;

if ($customerId > 0) {
    $customerStmt = $pdo->prepare('SELECT id, name, email FROM customers WHERE id = ? LIMIT 1');
    $customerStmt->execute([$customerId]);
    $selectedCustomer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedCustomer) {
        $storedStmt = $pdo->prepare("
            SELECT cs.id, cs.quantity, cs.arrival_date, p.sku, p.name AS product_name
            FROM customer_storage cs
            INNER JOIN products p ON p.id = cs.product_id
            WHERE cs.customer_id = ? AND cs.status = 'stored' AND cs.quantity > 0
            ORDER BY cs.created_at DESC
        ");
        $storedStmt->execute([$customerId]);
        $storedItems = $storedStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">New Ship Request</h2>
        <p class="text-muted mb-0">Select a customer, then choose which stored items to ship.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/ship-my-box/index.php">Back to Ship My Box</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Customer</h5>
    <form method="get" class="d-flex gap-2">
        <select class="form-select" name="customer_id" required>
            <option value="">Select a customer&hellip;</option>
            <?php foreach ($allCustomers as $customer): ?>
                <option value="<?php echo (int) $customer['id']; ?>" <?php echo $customerId === (int) $customer['id'] ? 'selected' : ''; ?>>
                    <?php echo app_escape($customer['name']); ?><?php if (!empty($customer['email'])): ?> (<?php echo app_escape($customer['email']); ?>)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-primary" type="submit">Load Stored Items</button>
    </form>
</div>

<?php if ($selectedCustomer): ?>
    <div class="card p-4">
        <h5 class="mb-3">Stored Items for <?php echo app_escape($selectedCustomer['name']); ?></h5>

        <?php if ($storedItems === []): ?>
            <p class="text-muted mb-0">This customer has no items currently in storage.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="customer_id" value="<?php echo (int) $selectedCustomer['id']; ?>">

                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product</th>
                            <th>Arrival</th>
                            <th>Stored Qty</th>
                            <th>Qty to Ship</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($storedItems as $item): ?>
                            <tr>
                                <td><?php echo app_escape($item['sku']); ?></td>
                                <td><?php echo app_escape($item['product_name']); ?></td>
                                <td><?php echo app_escape($item['arrival_date'] ?? '-'); ?></td>
                                <td><?php echo app_escape((string) $item['quantity']); ?></td>
                                <td>
                                    <input type="number" class="form-control" style="max-width: 120px;" name="quantity[<?php echo (int) $item['id']; ?>]" min="0" max="<?php echo (int) $item['quantity']; ?>" value="0">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <button class="btn btn-primary mt-3" type="submit">Create Ship Request</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
