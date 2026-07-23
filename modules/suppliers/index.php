<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('suppliers.view');

$appTitle = 'Suppliers';
require_once __DIR__ . '/../../includes/header.php';

$stmt = app_db()->query('SELECT id, name, contact, country, notes FROM suppliers ORDER BY id DESC LIMIT 20');
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canManage = app_has_permission('suppliers.manage');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Suppliers</h2>
        <p class="text-muted mb-0">Purchase planning and supplier relationship foundation.</p>
    </div>
    <?php if ($canManage): ?>
        <div class="d-flex gap-2">
            <a class="btn btn-primary" href="/modules/suppliers/create.php">Add Supplier</a>
            <a class="btn btn-outline-secondary" href="/modules/suppliers/import.php">Import CSV</a>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Supplier created.</div>
<?php endif; ?>

<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Country</th>
                <th>Notes</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($suppliers as $supplier): ?>
                <tr>
                    <td><?php echo app_escape($supplier['name']); ?></td>
                    <td><?php echo app_escape($supplier['contact'] ?? '-'); ?></td>
                    <td><?php echo app_escape($supplier['country'] ?? '-'); ?></td>
                    <td><?php echo app_escape($supplier['notes'] ?? '-'); ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" href="/modules/suppliers/view.php?id=<?php echo (int) $supplier['id']; ?>">View</a>
                            <?php if ($canManage): ?>
                                <a class="btn btn-sm btn-outline-primary" href="/modules/suppliers/edit.php?id=<?php echo (int) $supplier['id']; ?>">Edit</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($suppliers === []): ?>
                <tr><td colspan="5" class="text-muted">No suppliers yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>