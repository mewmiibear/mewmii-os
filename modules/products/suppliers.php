<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supplier_products.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('products.view');

/**
 * SO-C - manage which suppliers can supply this product, at what quoted price and SKU.
 *
 * This is a SOURCING CATALOGUE, not a costing surface. Nothing entered here affects landed cost,
 * inventory valuation, margins, or product_cost_history - those still come from actual purchase
 * order lines (SO-A1/SO-A2). products.supplier_id remains the preferred supplier and is edited on
 * the product form, not here.
 */

const SUPPLIER_PRODUCT_CURRENCY_OPTIONS = ['MYR', 'JPY', 'CNY', 'USD', 'EUR', 'GBP'];

$pdo = app_db();
$productId = (int) ($_GET['id'] ?? 0);

$productStmt = $pdo->prepare('SELECT id, sku, name, supplier_id, catalog_type, moq FROM products WHERE id = ? LIMIT 1');
$productStmt->execute([$productId]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($product === null) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$appTitle = 'Suppliers: ' . $product['name'];
$canManage = app_has_permission('products.manage');
$error = '';

$editId = isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = $editId > 0 ? supplier_product_get($pdo, $editId) : null;
if ($editing !== null && (int) $editing['product_id'] !== $productId) {
    $editing = null;
}

$form = [
    'supplier_id' => $editing['supplier_id'] ?? '',
    'variation_id' => $editing['variation_id'] ?? '',
    'supplier_sku' => $editing['supplier_sku'] ?? '',
    'unit_cost' => $editing['unit_cost'] ?? '',
    'currency' => $editing['currency'] ?? '',
    'exchange_rate' => $editing['exchange_rate'] ?? '',
    'priority' => $editing['priority'] ?? 0,
    'moq' => $editing['moq'] ?? '',
    'notes' => $editing['notes'] ?? '',
    'is_active' => $editing !== null ? (int) $editing['is_active'] : 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_permission('products.manage');

    try {
        app_require_csrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save') {
            $form = array_merge($form, array_intersect_key($_POST, $form));
            $form['is_active'] = !empty($_POST['is_active']) ? 1 : 0;

            $payload = array_merge($_POST, ['product_id' => $productId]);
            $validated = supplier_product_validate_form($pdo, $payload);

            if ($validated['errors'] !== []) {
                $error = implode(' ', $validated['errors']);
            } else {
                supplier_product_upsert($pdo, $validated['data'], $_SESSION['user_id'] ?? null);
                app_redirect('/modules/products/suppliers.php?id=' . $productId . '&saved=1');
            }
        } elseif ($action === 'delete') {
            $rowId = (int) ($_POST['sourcing_id'] ?? 0);
            $row = $rowId > 0 ? supplier_product_get($pdo, $rowId) : null;
            if ($row === null || (int) $row['product_id'] !== $productId) {
                $error = 'Sourcing entry not found.';
            } else {
                supplier_product_delete($pdo, $rowId);
                app_redirect('/modules/products/suppliers.php?id=' . $productId . '&removed=1');
            }
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Exception $exception) {
        $error = 'Failed to save sourcing entry: ' . $exception->getMessage();
    }
}

$sources = supplier_products_list($pdo, $productId);
$history = supplier_product_purchase_history($pdo, $productId, null, 25);
$summary = supplier_product_supplier_summary($pdo, $productId);
$suppliers = $pdo->query('SELECT id, name, currency FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$variations = [];
if ($product['catalog_type'] === 'variable') {
    $variationStmt = $pdo->prepare("SELECT id, sku FROM product_variations WHERE product_id = ? AND status <> 'archived' ORDER BY id ASC");
    $variationStmt->execute([$productId]);
    $variations = $variationStmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1">Suppliers</h1>
        <p class="page-description"><?php echo app_escape($product['name']); ?> &middot; <?php echo app_escape($product['sku']); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/products/view.php?id=<?php echo (int) $productId; ?>">Back to Product</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Sourcing entry saved.</div>
<?php endif; ?>
<?php if (isset($_GET['removed'])): ?>
    <div class="alert alert-success">Sourcing entry removed.</div>
<?php endif; ?>

<div class="alert alert-secondary small">
    Quoted prices here are for <strong>purchasing decisions only</strong>. They do not affect landed cost,
    inventory valuation, or margins &mdash; those always come from actual purchase order lines.
    The preferred supplier is set on the product itself, not here.
</div>

<div class="row g-4">
    <div class="col-lg-<?php echo $canManage ? '8' : '12'; ?>">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Sourcing Catalogue</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle responsive-stack-table">
                    <thead>
                        <tr>
                            <th>Priority</th>
                            <th>Supplier</th>
                            <th>Applies To</th>
                            <th>Supplier SKU</th>
                            <th>Quoted Cost</th>
                            <th>MOQ</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sources as $source): ?>
                            <tr>
                                <td data-label="Priority"><?php echo (int) $source['priority']; ?></td>
                                <td data-label="Supplier">
                                    <?php echo app_escape($source['supplier_name']); ?>
                                    <?php if ((int) $source['supplier_id'] === (int) $product['supplier_id']): ?>
                                        <span class="badge bg-secondary">Preferred</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Applies To"><?php echo $source['variation_id'] !== null ? app_escape($source['variation_sku'] ?? ('Variation #' . (int) $source['variation_id'])) : 'All variations'; ?></td>
                                <td data-label="Supplier SKU"><?php echo app_escape($source['supplier_sku'] ?? '-'); ?></td>
                                <td data-label="Quoted Cost">
                                    <?php if ($source['unit_cost'] !== null): ?>
                                        <?php echo app_escape($source['currency'] ?? 'MYR'); ?> <?php echo app_escape(number_format((float) $source['unit_cost'], 2)); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td data-label="MOQ"><?php echo $source['moq'] !== null ? (int) $source['moq'] : '-'; ?></td>
                                <td data-label="Status">
                                    <span class="badge <?php echo (int) $source['is_active'] === 1 ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo (int) $source['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td data-label="" class="text-end">
                                    <?php if ($canManage): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="/modules/products/suppliers.php?id=<?php echo (int) $productId; ?>&edit=<?php echo (int) $source['id']; ?>">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Remove this supplier from the sourcing catalogue?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="sourcing_id" value="<?php echo (int) $source['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($sources === []): ?>
                            <tr><td colspan="8"><div class="empty-state"><div class="empty-state-title">No Suppliers On File</div><p class="empty-state-text">Add the suppliers who can supply this product to compare prices when reordering.</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card p-4 mb-4">
            <h5 class="mb-3">Purchase History by Supplier</h5>
            <p class="text-muted small">Derived from actual purchase orders &mdash; this is what was really paid, not a quote.</p>
            <?php if ($summary !== []): ?>
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Supplier</th><th>Orders</th><th>Units</th><th>Last Ordered</th></tr></thead>
                        <tbody>
                            <?php foreach ($summary as $entry): ?>
                                <tr>
                                    <td><?php echo app_escape($entry['supplier_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo (int) $entry['order_count']; ?></td>
                                    <td><?php echo (int) $entry['total_units']; ?></td>
                                    <td><?php echo app_escape($entry['last_order_date'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($history === []): ?>
                <p class="text-muted small mb-0">No purchase history yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Date</th><th>PO</th><th>Supplier</th><th>Applies To</th><th>Qty</th><th>Unit Cost</th></tr></thead>
                        <tbody>
                            <?php foreach ($history as $entry): ?>
                                <tr>
                                    <td><?php echo app_escape($entry['order_date'] ?? '-'); ?></td>
                                    <td><?php echo app_escape($entry['purchase_number']); ?></td>
                                    <td><?php echo app_escape($entry['supplier_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo $entry['variation_id'] !== null ? app_escape($entry['variation_sku'] ?? ('#' . (int) $entry['variation_id'])) : 'Simple'; ?></td>
                                    <td><?php echo (int) $entry['total_quantity']; ?></td>
                                    <td>
                                        <?php if ($entry['unit_cost_foreign'] !== null && strtoupper((string) $entry['currency']) !== 'MYR'): ?>
                                            <?php echo app_escape($entry['currency']); ?> <?php echo app_escape(number_format((float) $entry['unit_cost_foreign'], 2)); ?>
                                            <span class="text-muted small">(RM <?php echo app_escape(number_format((float) $entry['supplier_price'], 2)); ?>)</span>
                                        <?php else: ?>
                                            RM <?php echo app_escape(number_format((float) $entry['supplier_price'], 2)); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canManage): ?>
        <div class="col-lg-4">
            <div class="card p-4">
                <h5 class="mb-3"><?php echo $editing !== null ? 'Edit Supplier Entry' : 'Add Supplier'; ?></h5>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save">

                    <div class="mb-3">
                        <label class="form-label">Supplier</label>
                        <select class="form-select" name="supplier_id" required <?php echo $editing !== null ? 'disabled' : ''; ?>>
                            <option value="">Select a supplier&hellip;</option>
                            <?php foreach ($suppliers as $supplierOption): ?>
                                <option value="<?php echo (int) $supplierOption['id']; ?>" <?php echo (string) $form['supplier_id'] === (string) $supplierOption['id'] ? 'selected' : ''; ?>>
                                    <?php echo app_escape($supplierOption['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editing !== null): ?>
                            <input type="hidden" name="supplier_id" value="<?php echo (int) $form['supplier_id']; ?>">
                            <div class="form-text">Supplier cannot be changed &mdash; remove and re-add instead.</div>
                        <?php endif; ?>
                    </div>

                    <?php if ($variations !== []): ?>
                        <div class="mb-3">
                            <label class="form-label">Applies To</label>
                            <select class="form-select" name="variation_id" <?php echo $editing !== null ? 'disabled' : ''; ?>>
                                <option value="">All variations</option>
                                <?php foreach ($variations as $variation): ?>
                                    <option value="<?php echo (int) $variation['id']; ?>" <?php echo (string) $form['variation_id'] === (string) $variation['id'] ? 'selected' : ''; ?>>
                                        <?php echo app_escape($variation['sku']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($editing !== null): ?>
                                <input type="hidden" name="variation_id" value="<?php echo $form['variation_id'] !== '' && $form['variation_id'] !== null ? (int) $form['variation_id'] : ''; ?>">
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Supplier SKU</label>
                        <input type="text" class="form-control" name="supplier_sku" maxlength="100" value="<?php echo app_escape((string) $form['supplier_sku']); ?>">
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Quoted Unit Cost</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="unit_cost" value="<?php echo app_escape((string) $form['unit_cost']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Currency</label>
                            <select class="form-select" name="currency">
                                <option value="">Supplier default</option>
                                <?php foreach (SUPPLIER_PRODUCT_CURRENCY_OPTIONS as $currencyOption): ?>
                                    <option value="<?php echo app_escape($currencyOption); ?>" <?php echo (string) $form['currency'] === $currencyOption ? 'selected' : ''; ?>><?php echo app_escape($currencyOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <label class="form-label">Exchange Rate</label>
                            <input type="number" step="0.000001" min="0" class="form-control" name="exchange_rate" value="<?php echo app_escape((string) $form['exchange_rate']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Priority</label>
                            <input type="number" class="form-control" name="priority" value="<?php echo (int) $form['priority']; ?>">
                            <div class="form-text">Lower = preferred.</div>
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label">Supplier MOQ (optional)</label>
                        <input type="number" min="1" class="form-control" name="moq" value="<?php echo app_escape((string) $form['moq']); ?>">
                        <div class="form-text">Recorded for reference. Product MOQ (<?php echo (int) $product['moq']; ?>) still applies to purchasing.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" maxlength="255"><?php echo app_escape((string) $form['notes']); ?></textarea>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="sp-active" name="is_active" value="1" <?php echo (int) $form['is_active'] === 1 ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="sp-active">Active</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1"><?php echo $editing !== null ? 'Save Changes' : 'Add Supplier'; ?></button>
                        <?php if ($editing !== null): ?>
                            <a class="btn btn-outline-secondary" href="/modules/products/suppliers.php?id=<?php echo (int) $productId; ?>">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
