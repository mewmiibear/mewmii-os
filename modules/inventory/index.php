<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_login();

$appTitle = 'Inventory';
require_once __DIR__ . '/../../includes/header.php';

$stmt = app_db()->query('SELECT p.id, p.sku, p.name, p.stock_quantity, p.reserved_stock FROM products p ORDER BY p.id DESC LIMIT 20');
$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Inventory</h2>
        <p class="text-muted mb-0">Available, reserved, and incoming stock tracking foundation.</p>
    </div>
</div>
<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Name</th>
                <th>Available</th>
                <th>Reserved</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventory as $item): ?>
                <tr>
                    <td><?php echo app_escape($item['sku']); ?></td>
                    <td><?php echo app_escape($item['name']); ?></td>
                    <td><?php echo app_escape((string) $item['stock_quantity']); ?></td>
                    <td><?php echo app_escape((string) $item['reserved_stock']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>