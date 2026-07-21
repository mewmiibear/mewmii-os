<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_login();

$appTitle = 'Suppliers';
require_once __DIR__ . '/../../includes/header.php';

$stmt = app_db()->query('SELECT id, name, contact_person, phone, email, status FROM suppliers ORDER BY id DESC LIMIT 20');
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Suppliers</h2>
        <p class="text-muted mb-0">Purchase planning and supplier relationship foundation.</p>
    </div>
</div>
<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($suppliers as $supplier): ?>
                <tr>
                    <td><?php echo app_escape($supplier['name']); ?></td>
                    <td><?php echo app_escape($supplier['contact_person'] ?? '-'); ?></td>
                    <td><?php echo app_escape($supplier['phone'] ?? '-'); ?></td>
                    <td><?php echo app_escape($supplier['email'] ?? '-'); ?></td>
                    <td><?php echo app_escape($supplier['status']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>