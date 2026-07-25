<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('products.view');

/**
 * Catalog Management > Attributes - global attributes (Character, Color, Size, Material,
 * ...) that products select values from via the Attribute Builder (see
 * modules/products/_form.php / assets/js/product-form.js). Values (and their SKU prefixes)
 * are managed per-attribute on modules/attributes/edit.php - this page only lists/adds/
 * deletes the attributes themselves, matching modules/{categories,brands,collections,tags}/
 * index.php's exact structure.
 */

$appTitle = 'Attributes';
$error = '';
$pdo = app_db();

$canManage = app_has_permission('products.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !$canManage) {
        http_response_code(403);
        $error = 'You do not have permission to manage attributes.';
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add') {
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                $error = 'Enter an attribute name.';
            } else {
                $pdo->beginTransaction();

                try {
                    $newId = catalog_get_or_create_attribute($pdo, $name);
                    $pdo->commit();

                    app_redirect('/modules/attributes/edit.php?id=' . $newId . '&created=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to create attribute.';
                }
            }
        } elseif ($action === 'delete') {
            $attributeId = (int) ($_POST['attribute_id'] ?? 0);

            if ($attributeId < 1) {
                $error = 'Invalid attribute.';
            } else {
                $pdo->beginTransaction();

                try {
                    catalog_attribute_delete_if_unused($pdo, $attributeId);
                    $pdo->commit();

                    app_redirect('/modules/attributes/index.php?deleted=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to delete attribute.';
                }
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$attributes = catalog_list_attributes_with_counts($pdo);
if ($search !== '') {
    $needle = strtolower($search);
    $attributes = array_values(array_filter($attributes, static function (array $attribute) use ($needle): bool {
        return strpos(strtolower($attribute['name']), $needle) !== false;
    }));
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Attributes</h2>
        <p class="text-muted mb-0">Character, Color, Size, Material, and every other attribute products select values from. Click an attribute to manage its values.</p>
    </div>
    <div class="action-bar">
        <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Attribute created.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Attribute deleted.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">Add Attribute</h5>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-md-10">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" maxlength="100" placeholder="e.g. Character, Color, Size" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?php echo app_escape($search); ?>" placeholder="Search attributes by name&hellip;">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-secondary w-100">Search</button>
        </div>
        <?php if ($search !== ''): ?>
            <div class="col-md-2">
                <a class="btn btn-outline-secondary w-100" href="/modules/attributes/index.php">Clear</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Values</th>
                <th>Products</th>
                <th>Date Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($attributes as $attribute): ?>
                <tr>
                    <td><a href="/modules/attributes/edit.php?id=<?php echo (int) $attribute['id']; ?>"><?php echo app_escape($attribute['name']); ?></a></td>
                    <td><?php echo (int) $attribute['value_count']; ?></td>
                    <td><?php echo (int) $attribute['product_count']; ?></td>
                    <td><?php echo $attribute['created_at'] !== null ? app_escape($attribute['created_at']) : '-'; ?></td>
                    <td class="text-end">
                        <?php if ($canManage): ?>
                            <div class="d-flex gap-1 justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/attributes/edit.php?id=<?php echo (int) $attribute['id']; ?>">Manage Values</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this attribute? This only works if no product currently has it assigned.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="attribute_id" value="<?php echo (int) $attribute['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($attributes === []): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <?php if ($search !== ''): ?>
                                <div class="empty-state-title">No Attributes Match "<?php echo app_escape($search); ?>"</div>
                            <?php else: ?>
                                <div class="empty-state-title">No Attributes Yet</div>
                                <p class="empty-state-text">Attributes (Character, Color, Size, ...) let variable products define variations - add one to get started.</p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
