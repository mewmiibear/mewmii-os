<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('products.view');

$appTitle = 'Tags';
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
        $error = 'You do not have permission to manage tags.';
    }

    if ($error === '') {
        $name = trim((string) ($_POST['tag_name'] ?? ''));

        if ($name === '') {
            $error = 'Enter a tag name.';
        } else {
            $pdo->beginTransaction();

            try {
                catalog_get_or_create_tag($pdo, $name);
                $pdo->commit();

                app_redirect('/modules/tags/index.php?saved=1');
            } catch (Exception $exception) {
                $pdo->rollBack();
                $error = 'Failed to save tag.';
            }
        }
    }
}

$tags = catalog_list_tags($pdo);
foreach ($tags as &$tag) {
    $tag['product_count'] = catalog_tag_product_count($pdo, (int) $tag['id']);
}
unset($tag);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Tags</h2>
        <p class="text-muted mb-0">Defined once here, picked as checkboxes on the product form - no free-text tags.</p>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Saved.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">New Tag</h5>
        <form method="post" class="d-flex gap-2">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="text" class="form-control" name="tag_name" placeholder="e.g. Cute, Limited Edition, Gift" required>
            <button class="btn btn-primary" type="submit">Add</button>
        </form>
    </div>
<?php endif; ?>

<div class="card p-4">
    <h5 class="mb-3">All Tags</h5>
    <?php if ($tags === []): ?>
        <p class="text-muted mb-0">No tags yet. Create one above (e.g. Cute, Limited Edition, Gift).</p>
    <?php else: ?>
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Tag</th>
                    <th>Products</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tags as $tag): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo app_escape($tag['name']); ?></td>
                        <td><?php echo (int) $tag['product_count']; ?> product(s)</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
