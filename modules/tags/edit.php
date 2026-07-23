<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('products.manage');

$appTitle = 'Edit Tag';
$error = '';

$tagId = (int) ($_GET['id'] ?? 0);

if ($tagId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Tag not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$tagStmt = $pdo->prepare('SELECT * FROM product_tags WHERE id = ? LIMIT 1');
$tagStmt->execute([$tagId]);
$tag = $tagStmt->fetch(PDO::FETCH_ASSOC);

if (!$tag) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Tag not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$form = ['name' => $tag['name']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['name'] = trim((string) ($_POST['name'] ?? ''));

    if ($error === '' && ($form['name'] === '' || strlen($form['name']) > 100)) {
        $error = 'Name is required and must be 100 characters or fewer.';
    }

    if ($error === '') {
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM product_tags WHERE LOWER(name) = LOWER(?) AND id != ?');
        $dupCheck->execute([$form['name'], $tagId]);
        if ((int) $dupCheck->fetchColumn() > 0) {
            $error = 'A tag with this name already exists - use Merge instead to combine them.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $pdo->prepare('UPDATE product_tags SET name = ? WHERE id = ?')->execute([$form['name'], $tagId]);
            $pdo->commit();

            app_redirect('/modules/tags/edit.php?id=' . $tagId . '&updated=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to update tag.';
        }
    }
}

$productCount = catalog_tag_product_count($pdo, $tagId);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Edit Tag</h2>
        <p class="text-muted mb-0"><?php echo app_escape($tag['name']); ?> &middot; <?php echo (int) $productCount; ?> product(s)</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/tags/index.php">Back to Tags</a>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Tag updated.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" value="<?php echo app_escape($form['name']); ?>" maxlength="100" required>
        </div>

        <button class="btn btn-primary mt-4" type="submit">Save Changes</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
