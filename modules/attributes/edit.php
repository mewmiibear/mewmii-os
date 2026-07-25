<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('products.manage');

/**
 * Catalog Management > Attributes > Manage Values - one attribute's own name plus its full
 * list of values (Add/Edit/Delete, including each value's SKU prefix - see
 * catalog_attribute_value_sku_code() in includes/product_variations.php for how the prefix
 * drives variation SKU generation). This is now the single place values are created/edited;
 * modules/products/_form.php's Attribute Builder only ever SELECTS from what's here.
 */

$appTitle = 'Manage Attribute';
$error = '';
$pdo = app_db();

$attributeId = (int) ($_GET['id'] ?? 0);
$attribute = $attributeId > 0 ? catalog_get_attribute($pdo, $attributeId) : null;

if ($attribute === null) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Attribute not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

function attributes_validate_code(PDO $pdo, int $attributeId, string $code, ?int $excludeValueId = null): ?string
{
    $code = strtoupper(trim($code));

    if ($code === '') {
        return 'Enter a SKU prefix (e.g. CN for Cinnamoroll).';
    }
    if (!preg_match('/^[A-Z0-9]{1,5}$/', $code)) {
        return 'Prefix must be 1-5 letters/numbers only (e.g. CN).';
    }

    $sql = 'SELECT COUNT(*) FROM product_attribute_values WHERE attribute_id = ? AND code = ?';
    $params = [$attributeId, $code];
    if ($excludeValueId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeValueId;
    }
    $check = $pdo->prepare($sql);
    $check->execute($params);
    if ((int) $check->fetchColumn() > 0) {
        return 'That prefix is already used by another value for this attribute.';
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'rename') {
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '' || strlen($name) > 100) {
                $error = 'Name is required and must be 100 characters or fewer.';
            } else {
                $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM product_attributes WHERE LOWER(name) = LOWER(?) AND id != ?');
                $dupCheck->execute([$name, $attributeId]);
                if ((int) $dupCheck->fetchColumn() > 0) {
                    $error = 'An attribute with this name already exists.';
                }
            }

            if ($error === '') {
                $pdo->beginTransaction();

                try {
                    $pdo->prepare('UPDATE product_attributes SET name = ? WHERE id = ?')->execute([$name, $attributeId]);
                    $pdo->commit();

                    app_redirect('/modules/attributes/edit.php?id=' . $attributeId . '&renamed=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to rename attribute.';
                }
            }
        } elseif ($action === 'add_value') {
            $value = trim((string) ($_POST['value'] ?? ''));
            $code = (string) ($_POST['code'] ?? '');

            if ($value === '' || strlen($value) > 150) {
                $error = 'Enter a value (150 characters or fewer).';
            } else {
                $codeError = attributes_validate_code($pdo, $attributeId, $code);
                if ($codeError !== null) {
                    $error = $codeError;
                }
            }

            if ($error === '') {
                $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM product_attribute_values WHERE attribute_id = ? AND value = ?');
                $dupCheck->execute([$attributeId, $value]);
                if ((int) $dupCheck->fetchColumn() > 0) {
                    $error = 'This attribute already has that value.';
                }
            }

            if ($error === '') {
                $pdo->beginTransaction();

                try {
                    catalog_get_or_create_attribute_value($pdo, $attributeId, $value, strtoupper(trim($code)));
                    $pdo->commit();

                    app_redirect('/modules/attributes/edit.php?id=' . $attributeId . '&value_added=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to add value.';
                }
            }
        } elseif ($action === 'update_value') {
            $valueId = (int) ($_POST['value_id'] ?? 0);
            $value = trim((string) ($_POST['value'] ?? ''));
            $code = (string) ($_POST['code'] ?? '');
            $existingValue = $valueId > 0 ? catalog_get_attribute_value($pdo, $valueId) : null;

            if ($existingValue === null || (int) $existingValue['attribute_id'] !== $attributeId) {
                $error = 'Invalid value.';
            } elseif ($value === '' || strlen($value) > 150) {
                $error = 'Enter a value (150 characters or fewer).';
            } else {
                $codeError = attributes_validate_code($pdo, $attributeId, $code, $valueId);
                if ($codeError !== null) {
                    $error = $codeError;
                }
            }

            if ($error === '') {
                $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM product_attribute_values WHERE attribute_id = ? AND value = ? AND id != ?');
                $dupCheck->execute([$attributeId, $value, $valueId]);
                if ((int) $dupCheck->fetchColumn() > 0) {
                    $error = 'This attribute already has that value.';
                }
            }

            if ($error === '') {
                $pdo->beginTransaction();

                try {
                    $pdo->prepare('UPDATE product_attribute_values SET value = ?, code = ? WHERE id = ?')
                        ->execute([$value, strtoupper(trim($code)), $valueId]);
                    $pdo->commit();

                    app_redirect('/modules/attributes/edit.php?id=' . $attributeId . '&value_updated=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to update value.';
                }
            }
        } elseif ($action === 'delete_value') {
            $valueId = (int) ($_POST['value_id'] ?? 0);
            $existingValue = $valueId > 0 ? catalog_get_attribute_value($pdo, $valueId) : null;

            if ($existingValue === null || (int) $existingValue['attribute_id'] !== $attributeId) {
                $error = 'Invalid value.';
            } else {
                $pdo->beginTransaction();

                try {
                    catalog_attribute_value_delete_if_unused($pdo, $valueId);
                    $pdo->commit();

                    app_redirect('/modules/attributes/edit.php?id=' . $attributeId . '&value_deleted=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to delete value.';
                }
            }
        } else {
            $error = 'Unknown action.';
        }
    }

    // Re-read after a POST that ended in an error (redirects already returned above on success).
    $attribute = catalog_get_attribute($pdo, $attributeId);
}

$productCount = catalog_attribute_product_count($pdo, $attributeId);

$values = catalog_list_attribute_values($pdo, $attributeId);
foreach ($values as &$value) {
    $value['product_count'] = catalog_attribute_value_product_count($pdo, (int) $value['id']);
}
unset($value);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Manage Attribute</h2>
        <p class="text-muted mb-0"><?php echo app_escape($attribute['name']); ?> &middot; <?php echo (int) $productCount; ?> product(s) &middot; <?php echo count($values); ?> value(s)</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/attributes/index.php">Back to Attributes</a>
</div>

<?php if (isset($_GET['renamed'])): ?>
    <div class="alert alert-success">Attribute renamed.</div>
<?php endif; ?>
<?php if (isset($_GET['value_added'])): ?>
    <div class="alert alert-success">Value added.</div>
<?php endif; ?>
<?php if (isset($_GET['value_updated'])): ?>
    <div class="alert alert-success">Value updated.</div>
<?php endif; ?>
<?php if (isset($_GET['value_deleted'])): ?>
    <div class="alert alert-success">Value deleted.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Attribute Name</h5>
    <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="rename">
        <div class="col-md-8">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" value="<?php echo app_escape($attribute['name']); ?>" maxlength="100" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Slug</label>
            <input type="text" class="form-control" value="<?php echo app_escape($attribute['slug']); ?>" disabled>
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary">Save Name</button>
        </div>
    </form>
</div>

<?php if ($values !== []): ?>
    <?php foreach ($values as $value): ?>
        <form method="post" id="value-form-<?php echo (int) $value['id']; ?>" class="d-none">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="update_value">
            <input type="hidden" name="value_id" value="<?php echo (int) $value['id']; ?>">
        </form>
        <form method="post" id="delete-value-form-<?php echo (int) $value['id']; ?>" class="d-none" onsubmit="return confirm('Delete this value? This only works if no product currently has it selected.');">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="delete_value">
            <input type="hidden" name="value_id" value="<?php echo (int) $value['id']; ?>">
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Values</h5>
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th style="width: 45%;">Value</th>
                <th style="width: 15%;">SKU Prefix</th>
                <th>Products</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($values as $value): ?>
                <tr>
                    <td><input type="text" class="form-control form-control-sm" form="value-form-<?php echo (int) $value['id']; ?>" name="value" value="<?php echo app_escape($value['value']); ?>" maxlength="150" required></td>
                    <td><input type="text" class="form-control form-control-sm" form="value-form-<?php echo (int) $value['id']; ?>" name="code" value="<?php echo app_escape($value['code'] ?? ''); ?>" maxlength="5" style="text-transform: uppercase;" required></td>
                    <td><?php echo (int) $value['product_count']; ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="submit" class="btn btn-sm btn-outline-primary" form="value-form-<?php echo (int) $value['id']; ?>">Save</button>
                            <button type="submit" class="btn btn-sm btn-outline-danger" form="delete-value-form-<?php echo (int) $value['id']; ?>">Delete</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($values === []): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-state-title">No Values Yet</div>
                            <p class="empty-state-text">Add the first value below (e.g. "Hello Kitty" for a Character attribute).</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card p-4">
    <h5 class="mb-3">Add Value</h5>
    <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="add_value">
        <div class="col-md-7">
            <label class="form-label">Value</label>
            <input type="text" class="form-control" name="value" maxlength="150" placeholder="e.g. Cinnamoroll" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">SKU Prefix</label>
            <input type="text" class="form-control" name="code" maxlength="5" style="text-transform: uppercase;" placeholder="e.g. CN" required>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Add</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
