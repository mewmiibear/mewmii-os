<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('products.view');

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

        try {
            if ($action === 'create_attribute') {
                $name = trim((string) ($_POST['attribute_name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Enter an attribute name.');
                }

                $pdo->beginTransaction();
                catalog_get_or_create_attribute($pdo, $name);
                $pdo->commit();

                app_redirect('/modules/attributes/index.php?saved=1');
            } elseif ($action === 'add_value') {
                $attributeId = (int) ($_POST['attribute_id'] ?? 0);
                $value = trim((string) ($_POST['value'] ?? ''));
                if ($attributeId < 1 || $value === '') {
                    throw new RuntimeException('Select an attribute and enter a value.');
                }

                $pdo->beginTransaction();
                catalog_get_or_create_attribute_value($pdo, $attributeId, $value);
                $pdo->commit();

                app_redirect('/modules/attributes/index.php?saved=1');
            } else {
                $error = 'Unknown action.';
            }
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to save changes.';
        }
    }
}

$attributes = catalog_list_attributes($pdo);
foreach ($attributes as &$attribute) {
    $attribute['values'] = catalog_list_attribute_values($pdo, (int) $attribute['id']);

    $usageStmt = $pdo->prepare('SELECT COUNT(DISTINCT product_id) FROM product_attribute_assignments WHERE attribute_id = ?');
    $usageStmt->execute([(int) $attribute['id']]);
    $attribute['product_count'] = (int) $usageStmt->fetchColumn();
}
unset($attribute);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Attributes</h2>
        <p class="text-muted mb-0">Character, Color, Size, and any other attribute used to build variations. Defined once here, reused across every variable product.</p>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Saved.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card p-4 h-100">
                <h5 class="mb-3">New Attribute</h5>
                <form method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_attribute">
                    <input type="text" class="form-control" name="attribute_name" placeholder="e.g. Character, Color, Size" required>
                    <button class="btn btn-primary" type="submit">Add</button>
                </form>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card p-4 h-100">
                <h5 class="mb-3">New Value</h5>
                <form method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="add_value">
                    <select class="form-select" name="attribute_id" required>
                        <option value="">Attribute&hellip;</option>
                        <?php foreach ($attributes as $attribute): ?>
                            <option value="<?php echo (int) $attribute['id']; ?>"><?php echo app_escape($attribute['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control" name="value" placeholder="e.g. Hello Kitty, Pink, Small" required>
                    <button class="btn btn-primary" type="submit">Add</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card p-4">
    <h5 class="mb-3">All Attributes</h5>
    <?php if ($attributes === []): ?>
        <p class="text-muted mb-0">No attributes yet. Create one above (e.g. Character, Color, Size).</p>
    <?php else: ?>
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Attribute</th>
                    <th>Values</th>
                    <th>Used By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attributes as $attribute): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo app_escape($attribute['name']); ?></td>
                        <td>
                            <?php if ($attribute['values'] === []): ?>
                                <span class="text-muted small">No values yet.</span>
                            <?php else: ?>
                                <?php foreach ($attribute['values'] as $value): ?>
                                    <span class="badge bg-light text-dark border me-1"><?php echo app_escape($value['value']); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int) $attribute['product_count']; ?> product(s)</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
