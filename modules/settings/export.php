<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('settings.manage');

/**
 * Settings -> Data Export: read-only CSV downloads. Every link here is a plain GET to a
 * dedicated export script (or, for Inventory, the existing modules/inventory/export.php) -
 * nothing on this page writes to the database.
 */
$appTitle = 'Data Export';

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Data Export</h2>
        <p class="text-muted mb-0">Read-only CSV exports.</p>
    </div>
</div>

<div class="d-flex gap-2 mb-4">
    <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/maintenance.php">Data Cleanup</a>
    <a class="btn btn-secondary btn-sm" href="/modules/settings/export.php">Data Export</a>
</div>

<div class="card p-4">
    <div class="list-group">
        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/modules/settings/export_products.php">
            Products CSV
            <span class="text-muted small">SKU, internal code, supplier SKU, category, brand, supplier, pricing</span>
        </a>
        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/modules/inventory/export.php">
            Inventory CSV
            <span class="text-muted small">Current/available/reserved/incoming/arrived stock per product/variation</span>
        </a>
        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/modules/settings/export_customer_orders.php">
            Customer Orders CSV
            <span class="text-muted small">Order status, payment status, subtotal/discount/shipping/total</span>
        </a>
        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/modules/settings/export_supplier_orders.php">
            Supplier Orders CSV
            <span class="text-muted small">Status, payment status, total purchase amount, paid/remaining</span>
        </a>
        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/modules/settings/export_customers.php">
            Customers CSV
            <span class="text-muted small">Name, email, phone, address</span>
        </a>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
