<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('products.manage');

$appTitle = 'Edit Category';
$error = '';

$categoryId = (int) ($_GET['id'] ?? 0);

if ($categoryId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Category not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$categoryStmt = $pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
$categoryStmt->execute([$categoryId]);
$category = $categoryStmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Category not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$form = [
    'name' => $category['name'],
    'parent_id' => $category['parent_id'] !== null ? (string) $category['parent_id'] : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['parent_id'] = (string) ($_POST['parent_id'] ?? '');
    $parentId = $form['parent_id'] !== '' ? (int) $form['parent_id'] : null;

    if ($error === '') {
        if ($form['name'] === '' || strlen($form['name']) > 120) {
            $error = 'Name is required and must be 120 characters or fewer.';
        } elseif ($parentId === $categoryId) {
            $error = 'A category cannot be its own parent.';
        }
    }

    if ($error === '') {
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?) AND id != ?');
        $dupCheck->execute([$form['name'], $categoryId]);
        if ((int) $dupCheck->fetchColumn() > 0) {
            $error = 'A category with this name already exists.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            // Slug is left untouched on rename - it may already be synced to WooCommerce
            // (categories.woocommerce_term_id), so only the display name changes here.
            $pdo->prepare('UPDATE categories SET name = ?, parent_id = ? WHERE id = ?')
                ->execute([$form['name'], $parentId, $categoryId]);

            $pdo->commit();

            app_redirect('/modules/categories/edit.php?id=' . $categoryId . '&updated=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to update category.';
        }
    }
}

$productCount = catalog_category_product_count($pdo, $categoryId);

// Parent options exclude this category itself and (to avoid creating a cycle) its own
// descendants - computed from the same flat category list, no extra query.
$allCategories = $pdo->query('SELECT id, name, parent_id FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$descendantIds = [$categoryId => true];
$changed = true;
while ($changed) {
    $changed = false;
    foreach ($allCategories as $row) {
        $parentKey = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        if (isset($descendantIds[$parentKey]) && !isset($descendantIds[(int) $row['id']])) {
            $descendantIds[(int) $row['id']] = true;
            $changed = true;
        }
    }
}
$parentOptions = array_filter($allCategories, static fn (array $row): bool => !isset($descendantIds[(int) $row['id']]));

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Edit Category</h2>
        <p class="text-muted mb-0"><?php echo app_escape($category['name']); ?> &middot; <?php echo (int) $productCount; ?> product(s)</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/categories/index.php">Back to Categories</a>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Category updated.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" value="<?php echo app_escape($form['name']); ?>" maxlength="120" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Parent Category</label>
                <select class="form-select" name="parent_id">
                    <option value="">None (top-level)</option>
                    <?php foreach ($parentOptions as $option): ?>
                        <option value="<?php echo (int) $option['id']; ?>" <?php echo $form['parent_id'] === (string) $option['id'] ? 'selected' : ''; ?>><?php echo app_escape($option['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Slug</label>
                <input type="text" class="form-control" value="<?php echo app_escape($category['slug']); ?>" disabled>
                <div class="form-text">The slug isn't editable here - it may already be synced to WooCommerce.</div>
            </div>
        </div>

        <button class="btn btn-primary mt-4" type="submit">Save Changes</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
