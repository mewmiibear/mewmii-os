<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
app_require_permission('settings.manage');

/**
 * Settings -> Maintenance -> Data Cleanup: a development-mode tool for bulk-clearing test
 * products/orders/supplier orders while this system is still being set up. Everything
 * shown here is relationship-based (no test-flag column anywhere) - a record only ever
 * appears if catalog_list_deletable_products()/order_list_deletable()/
 * supplier_order_list_deletable() found zero rows of real business history against it, and
 * the actual delete below re-validates via the same *_delete_if_unused() functions used
 * everywhere else in the app, never trusting the list alone.
 */

$appTitle = 'Data Cleanup';
$pdo = app_db();
$error = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '') {
        $type = (string) ($_POST['type'] ?? '');
        $ids = array_map('intval', $_POST['ids'] ?? []);
        $deleted = 0;
        $failed = [];

        foreach ($ids as $id) {
            if ($id < 1) {
                continue;
            }

            $pdo->beginTransaction();

            try {
                if ($type === 'products') {
                    product_delete_if_unused($pdo, $id);
                } elseif ($type === 'orders') {
                    order_delete_if_unused($pdo, $id);
                } elseif ($type === 'supplier_orders') {
                    supplier_order_delete_if_unreceived($pdo, $id);
                } else {
                    throw new RuntimeException('Unknown record type.');
                }

                $pdo->commit();
                $deleted++;
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                $failed[] = '#' . $id . ': ' . $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                $failed[] = '#' . $id . ': failed to delete.';
            }
        }

        $results[] = ['type' => $type, 'deleted' => $deleted, 'failed' => $failed];
    }
}

$deletableProducts = catalog_list_deletable_products($pdo);
$deletableOrders = order_list_deletable($pdo);
$deletableSupplierOrders = supplier_order_list_deletable($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Data Cleanup</h2>
        <p class="text-muted mb-0">Development-mode tool: only records with zero real business history are ever listed here.</p>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php foreach ($results as $result): ?>
    <div class="alert alert-<?php echo $result['failed'] === [] ? 'success' : 'warning'; ?>">
        <?php echo (int) $result['deleted']; ?> record(s) deleted.
        <?php if ($result['failed'] !== []): ?>
            <div class="mt-2 small">
                Could not delete:
                <ul class="mb-0">
                    <?php foreach ($result['failed'] as $line): ?>
                        <li><?php echo app_escape($line); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Products safe to delete</h5>
    <p class="text-muted small">No customer orders, no supplier orders, no inventory transactions, no customer storage records.</p>
    <?php if ($deletableProducts === []): ?>
        <p class="text-muted mb-0">Nothing to clean up.</p>
    <?php else: ?>
        <form method="post" onsubmit="return confirm('Permanently delete the selected products? This cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="type" value="products">
            <table class="table table-sm align-middle">
                <thead><tr><th></th><th>Name</th><th>SKU</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                    <?php foreach ($deletableProducts as $product): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?php echo (int) $product['id']; ?>"></td>
                            <td><?php echo app_escape($product['name']); ?></td>
                            <td><?php echo app_escape($product['sku']); ?></td>
                            <td><?php echo app_escape(ucfirst($product['status'])); ?></td>
                            <td class="text-muted small"><?php echo app_escape($product['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-outline-danger btn-sm">Delete Selected Products</button>
        </form>
    <?php endif; ?>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Orders safe to delete</h5>
    <p class="text-muted small">Still Pending with no payment approved, no shipment, no inventory reservation.</p>
    <?php if ($deletableOrders === []): ?>
        <p class="text-muted mb-0">Nothing to clean up.</p>
    <?php else: ?>
        <form method="post" onsubmit="return confirm('Permanently delete the selected orders? This cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="type" value="orders">
            <table class="table table-sm align-middle">
                <thead><tr><th></th><th>Order #</th><th>Order Status</th><th>Payment Status</th><th>Created</th></tr></thead>
                <tbody>
                    <?php foreach ($deletableOrders as $order): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?php echo (int) $order['id']; ?>"></td>
                            <td><?php echo app_escape($order['order_number']); ?></td>
                            <td><?php echo app_escape(ucfirst($order['order_status'])); ?></td>
                            <td><?php echo app_escape(ucfirst($order['payment_status'])); ?></td>
                            <td class="text-muted small"><?php echo app_escape($order['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-outline-danger btn-sm">Delete Selected Orders</button>
        </form>
    <?php endif; ?>
</div>

<div class="card p-4">
    <h5 class="mb-3">Supplier Orders safe to delete</h5>
    <p class="text-muted small">No receiving transaction recorded against any line.</p>
    <?php if ($deletableSupplierOrders === []): ?>
        <p class="text-muted mb-0">Nothing to clean up.</p>
    <?php else: ?>
        <form method="post" onsubmit="return confirm('Permanently delete the selected supplier orders? This cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="type" value="supplier_orders">
            <table class="table table-sm align-middle">
                <thead><tr><th></th><th>Purchase #</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                    <?php foreach ($deletableSupplierOrders as $supplierOrder): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?php echo (int) $supplierOrder['id']; ?>"></td>
                            <td><?php echo app_escape($supplierOrder['purchase_number']); ?></td>
                            <td><?php echo supplier_order_status_badge($supplierOrder['status']); ?></td>
                            <td class="text-muted small"><?php echo app_escape($supplierOrder['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-outline-danger btn-sm">Delete Selected Supplier Orders</button>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
