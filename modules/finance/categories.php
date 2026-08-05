<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.manage');

$appTitle = 'Expense Categories';
$pdo = app_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $parentId = isset($_POST['parent_id']) && (int) $_POST['parent_id'] > 0 ? (int) $_POST['parent_id'] : null;

            if ($name === '' || strlen($name) > 100) {
                $error = 'Category name is required and must be 100 characters or fewer.';
            } elseif ($parentId !== null && expense_category_get($pdo, $parentId) === null) {
                $error = 'Choose a valid parent category.';
            } else {
                $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE name = ? AND parent_id <=> ?');
                $dupStmt->execute([$name, $parentId]);
                if ((int) $dupStmt->fetchColumn() > 0) {
                    $error = 'A category with this name already exists at this level.';
                } else {
                    $pdo->prepare('INSERT INTO expense_categories (name, parent_id) VALUES (?, ?)')->execute([$name, $parentId]);
                    app_redirect('/modules/finance/categories.php?created=1');
                }
            }
        } elseif ($action === 'toggle_active') {
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $category = expense_category_get($pdo, $categoryId);
            if ($category !== null) {
                $pdo->prepare('UPDATE expense_categories SET is_active = ? WHERE id = ?')->execute([$category['is_active'] ? 0 : 1, $categoryId]);
                app_redirect('/modules/finance/categories.php?updated=1');
            }
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$allCategories = $pdo->query('SELECT id, name, parent_id, is_active FROM expense_categories ORDER BY parent_id IS NOT NULL, sort_order ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
$parentOptions = expense_categories_flat($pdo, false);

$byParent = [];
foreach ($allCategories as $row) {
    $byParent[$row['parent_id'] === null ? 0 : (int) $row['parent_id']][] = $row;
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1">Expense Categories</h1>
        <p class="page-description">Manage the categories used to record expenses.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/finance/index.php">Back to Expenses</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Category added.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Category updated.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-4">
            <h5 class="mb-3">Categories</h5>
            <?php foreach ($byParent[0] ?? [] as $parent): ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <span class="fw-semibold <?php echo $parent['is_active'] ? '' : 'text-muted text-decoration-line-through'; ?>"><?php echo app_escape($parent['name']); ?></span>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="category_id" value="<?php echo (int) $parent['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo $parent['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                    </form>
                </div>
                <?php foreach ($byParent[(int) $parent['id']] ?? [] as $child): ?>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom ps-4">
                        <span class="<?php echo $child['is_active'] ? '' : 'text-muted text-decoration-line-through'; ?>">&#8627; <?php echo app_escape($child['name']); ?></span>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="category_id" value="<?php echo (int) $child['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo $child['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($allCategories === []): ?>
                <div class="empty-state">
                    <div class="empty-state-title">No Categories Yet</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-4">
            <h5 class="mb-3">Add Category</h5>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-control" name="name" maxlength="100" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Parent (optional)</label>
                    <select class="form-select" name="parent_id">
                        <option value="">None - top-level category</option>
                        <?php foreach ($parentOptions as $option): ?>
                            <?php if ($option['depth'] === 0): ?>
                                <option value="<?php echo (int) $option['id']; ?>"><?php echo app_escape($option['name']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Only top-level categories can be a parent - one level of nesting.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Add Category</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
