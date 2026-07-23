<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_storage.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('customer-storage.view');

$appTitle = 'Customer Storage';
$error = '';
$pdo = app_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !app_has_permission('customer-storage.manage')) {
        http_response_code(403);
        $error = 'You do not have permission to add items to customer storage.';
    }

    if ($error === '') {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $unitKey = trim((string) ($_POST['unit_key'] ?? ''));
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $arrivalDate = trim((string) ($_POST['arrival_date'] ?? ''));
        $unit = $unitKey !== '' ? catalog_parse_sellable_key($unitKey) : null;

        if ($customerId < 1) {
            $error = 'Select a customer.';
        } elseif ($unit === null || $unit['product_id'] < 1) {
            $error = 'Select a product.';
        } elseif ($quantity < 1) {
            $error = 'Enter a quantity of at least 1.';
        } else {
            $pdo->beginTransaction();

            try {
                customer_storage_add($pdo, $customerId, $unit['product_id'], $quantity, $arrivalDate !== '' ? $arrivalDate : null, $unit['variation_id']);
                $pdo->commit();

                app_redirect('/modules/customer-storage/index.php?added=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to add item to customer storage.';
            }
        }
    }
}

$storageStmt = $pdo->query("
    SELECT
        c.id AS customer_id,
        c.name AS customer_name,
        c.email AS customer_email,
        COUNT(DISTINCT cs.product_id) AS product_count,
        SUM(cs.quantity) AS total_quantity
    FROM customers c
    INNER JOIN customer_storage cs ON cs.customer_id = c.id AND cs.status = 'stored' AND cs.quantity > 0
    GROUP BY c.id, c.name, c.email
    ORDER BY c.name ASC
");
$customerStorage = $storageStmt->fetchAll(PDO::FETCH_ASSOC);

$customersStmt = $pdo->query('SELECT id, name, email FROM customers ORDER BY name ASC LIMIT 200');
$allCustomers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$sellableUnits = catalog_sellable_units($pdo);

$canManage = app_has_permission('customer-storage.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Customer Storage</h2>
        <p class="text-muted mb-0">Items physically stored in the warehouse on behalf of customers.</p>
    </div>
</div>

<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success">Item added to customer storage.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">Add Item to Storage</h5>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

            <div class="col-md-4">
                <label class="form-label">Customer</label>
                <select class="form-select" name="customer_id" required>
                    <option value="">Select a customer&hellip;</option>
                    <?php foreach ($allCustomers as $customer): ?>
                        <option value="<?php echo (int) $customer['id']; ?>">
                            <?php echo app_escape($customer['name']); ?><?php if (!empty($customer['email'])): ?> (<?php echo app_escape($customer['email']); ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Product</label>
                <select class="form-select" name="unit_key" required>
                    <option value="">Select a product&hellip;</option>
                    <?php foreach ($sellableUnits as $unit): ?>
                        <option value="<?php echo app_escape($unit['key']); ?>">
                            <?php echo app_escape($unit['sku']); ?> &mdash; <?php echo app_escape($unit['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Quantity</label>
                <input type="number" class="form-control" name="quantity" min="1" required>
            </div>

            <div class="col-md-2">
                <label class="form-label">Arrival Date</label>
                <input type="date" class="form-control" name="arrival_date">
            </div>

            <div class="col-12">
                <button class="btn btn-primary" type="submit">Add to Storage</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Products Stored</th>
                <th>Total Quantity</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customerStorage as $row): ?>
                <tr>
                    <td>
                        <?php echo app_escape($row['customer_name']); ?>
                        <?php if (!empty($row['customer_email'])): ?>
                            <div class="text-muted small"><?php echo app_escape($row['customer_email']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape((string) $row['product_count']); ?></td>
                    <td><?php echo app_escape((string) $row['total_quantity']); ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/modules/customer-storage/view.php?customer_id=<?php echo (int) $row['customer_id']; ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($customerStorage === []): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-state-title">No Items in Storage</div>
                            <p class="empty-state-text">Customers with items currently stored in the warehouse will appear here.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
