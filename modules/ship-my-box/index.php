<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('ship-my-box.view');

$appTitle = 'Ship My Box';
$pdo = app_db();

$stmt = $pdo->query('
    SELECT
        sr.id,
        sr.request_number,
        sr.status,
        sr.created_at,
        c.name AS customer_name,
        (SELECT COUNT(*) FROM ship_request_items sri WHERE sri.ship_request_id = sr.id) AS item_count,
        (SELECT COALESCE(SUM(sri.quantity), 0) FROM ship_request_items sri WHERE sri.ship_request_id = sr.id) AS total_quantity
    FROM ship_requests sr
    INNER JOIN customers c ON c.id = sr.customer_id
    ORDER BY sr.id DESC
    LIMIT 20
');
$shipRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = app_has_permission('ship-my-box.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Ship My Box</h2>
        <p class="text-muted mb-0">Requests to ship items currently held in customer storage.</p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-primary" href="/modules/ship-my-box/create.php">New Ship Request</a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Ship request created.</div>
<?php endif; ?>

<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Status</th>
                <th>Items</th>
                <th>Total Qty</th>
                <th>Requested</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($shipRequests as $request): ?>
                <tr>
                    <td>
                        <?php echo app_escape($request['customer_name']); ?>
                        <div class="text-muted small"><?php echo app_escape($request['request_number'] ?? ('#' . $request['id'])); ?></div>
                    </td>
                    <td><?php echo app_escape($request['status']); ?></td>
                    <td><?php echo app_escape((string) $request['item_count']); ?></td>
                    <td><?php echo app_escape((string) $request['total_quantity']); ?></td>
                    <td><?php echo app_escape($request['created_at']); ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/modules/ship-my-box/view.php?id=<?php echo (int) $request['id']; ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($shipRequests === []): ?>
                <tr><td colspan="6" class="text-muted">No ship requests yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
