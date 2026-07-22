<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_storage.php';
app_require_permission('customer-storage.view');

$appTitle = 'Customer Storage Detail';
$error = '';

$customerId = (int) ($_GET['customer_id'] ?? 0);

if ($customerId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Customer not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$customerStmt = $pdo->prepare('SELECT id, name, email, phone FROM customers WHERE id = ? LIMIT 1');
$customerStmt->execute([$customerId]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Customer not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !app_has_permission('customer-storage.manage')) {
        http_response_code(403);
        $error = 'You do not have permission to manage customer storage.';
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $arrivalDate = trim((string) ($_POST['arrival_date'] ?? ''));

            if ($productId < 1) {
                $error = 'Select a product.';
            } elseif ($quantity < 1) {
                $error = 'Enter a quantity of at least 1.';
            } else {
                $pdo->beginTransaction();

                try {
                    customer_storage_add($pdo, $customerId, $productId, $quantity, $arrivalDate !== '' ? $arrivalDate : null);
                    $pdo->commit();

                    app_redirect('/modules/customer-storage/view.php?customer_id=' . $customerId . '&updated=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to add item to customer storage.';
                }
            }
        } elseif ($action === 'remove') {
            $storageId = (int) ($_POST['storage_id'] ?? 0);
            $quantity = (int) ($_POST['quantity'] ?? 0);

            if ($storageId < 1) {
                $error = 'Invalid storage record.';
            } elseif ($quantity < 1) {
                $error = 'Enter a quantity of at least 1.';
            } else {
                $pdo->beginTransaction();

                try {
                    customer_storage_remove($pdo, $storageId, $quantity);
                    $pdo->commit();

                    app_redirect('/modules/customer-storage/view.php?customer_id=' . $customerId . '&updated=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to remove item from customer storage.';
                }
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$storedStmt = $pdo->prepare("
    SELECT cs.id, cs.quantity, cs.arrival_date, cs.created_at, p.sku, p.name AS product_name
    FROM customer_storage cs
    INNER JOIN products p ON p.id = cs.product_id
    WHERE cs.customer_id = ? AND cs.status = 'stored' AND cs.quantity > 0
    ORDER BY cs.created_at DESC
");
$storedStmt->execute([$customerId]);
$storedItems = $storedStmt->fetchAll(PDO::FETCH_ASSOC);

$historyStmt = $pdo->prepare('
    SELECT cs.id, cs.quantity, cs.status, cs.arrival_date, cs.created_at, p.sku, p.name AS product_name
    FROM customer_storage cs
    INNER JOIN products p ON p.id = cs.product_id
    WHERE cs.customer_id = ?
    ORDER BY cs.created_at DESC
');
$historyStmt->execute([$customerId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$activityStmt = $pdo->prepare("
    SELECT it.transaction_type, it.quantity, it.created_at, p.sku, p.name AS product_name
    FROM inventory_transactions it
    INNER JOIN products p ON p.id = it.product_id
    WHERE it.reference_type = 'customer_storage'
      AND it.reference_id IN (SELECT id FROM customer_storage WHERE customer_id = ?)
    ORDER BY it.created_at DESC, it.id DESC
");
$activityStmt->execute([$customerId]);
$activity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

$productsStmt = $pdo->query('SELECT id, sku, name FROM products ORDER BY name ASC LIMIT 200');
$allProducts = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = app_has_permission('customer-storage.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1"><?php echo app_escape($customer['name']); ?></h2>
        <p class="text-muted mb-0"><?php echo app_escape($customer['email'] ?? '-'); ?> &middot; <?php echo app_escape($customer['phone'] ?? '-'); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/customer-storage/index.php">Back to Customer Storage</a>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Customer storage updated.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Currently Stored</h5>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Arrival</th>
                        <?php if ($canManage): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($storedItems as $item): ?>
                        <tr>
                            <td><?php echo app_escape($item['sku']); ?></td>
                            <td><?php echo app_escape($item['product_name']); ?></td>
                            <td><?php echo app_escape((string) $item['quantity']); ?></td>
                            <td><?php echo app_escape($item['arrival_date'] ?? '-'); ?></td>
                            <?php if ($canManage): ?>
                                <td class="text-end">
                                    <form method="post" class="d-flex gap-1 justify-content-end">
                                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="storage_id" value="<?php echo (int) $item['id']; ?>">
                                        <input type="number" class="form-control form-control-sm" style="width: 80px;" name="quantity" min="1" max="<?php echo (int) $item['quantity']; ?>" placeholder="Qty" required>
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($storedItems === []): ?>
                        <tr><td colspan="<?php echo $canManage ? 5 : 4; ?>" class="text-muted">No items currently stored for this customer.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canManage): ?>
            <div class="card p-4">
                <h5 class="mb-3">Add Item for This Customer</h5>
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="add">

                    <div class="col-md-5">
                        <label class="form-label">Product</label>
                        <select class="form-select" name="product_id" required>
                            <option value="">Select a product&hellip;</option>
                            <?php foreach ($allProducts as $product): ?>
                                <option value="<?php echo (int) $product['id']; ?>">
                                    <?php echo app_escape($product['sku']); ?> &mdash; <?php echo app_escape($product['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control" name="quantity" min="1" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Arrival Date</label>
                        <input type="date" class="form-control" name="arrival_date">
                    </div>

                    <div class="col-md-1">
                        <button class="btn btn-primary w-100" type="submit">Add</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Storage History</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($history as $entry): ?>
                    <li class="mb-3">
                        <div class="fw-semibold">
                            <?php echo app_escape($entry['sku']); ?> &mdash; <?php echo app_escape($entry['product_name']); ?>
                        </div>
                        <div>Qty: <?php echo app_escape((string) $entry['quantity']); ?> &middot; Status: <?php echo app_escape($entry['status']); ?></div>
                        <div class="text-muted small">Added <?php echo app_escape($entry['created_at']); ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if ($history === []): ?>
                    <li class="text-muted">No storage history for this customer yet.</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card p-4">
            <h5 class="mb-3">Inventory Activity</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($activity as $tx): ?>
                    <li class="mb-3">
                        <div class="fw-semibold">
                            <?php echo app_escape($tx['transaction_type']); ?>
                            &middot; <?php echo app_escape($tx['sku']); ?> (<?php echo app_escape($tx['product_name']); ?>)
                        </div>
                        <div>Qty: <?php echo app_escape((string) $tx['quantity']); ?></div>
                        <div class="text-muted small"><?php echo app_escape($tx['created_at']); ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if ($activity === []): ?>
                    <li class="text-muted">No inventory activity yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
