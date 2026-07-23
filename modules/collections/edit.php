<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('products.manage');

$appTitle = 'Edit Collection';
$error = '';

$collectionId = (int) ($_GET['id'] ?? 0);

if ($collectionId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Collection not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$collectionStmt = $pdo->prepare('SELECT * FROM collections WHERE id = ? LIMIT 1');
$collectionStmt->execute([$collectionId]);
$collection = $collectionStmt->fetch(PDO::FETCH_ASSOC);

if (!$collection) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Collection not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$form = ['name' => $collection['name']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['name'] = trim((string) ($_POST['name'] ?? ''));

    if ($error === '' && ($form['name'] === '' || strlen($form['name']) > 120)) {
        $error = 'Name is required and must be 120 characters or fewer.';
    }

    if ($error === '') {
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE LOWER(name) = LOWER(?) AND id != ?');
        $dupCheck->execute([$form['name'], $collectionId]);
        if ((int) $dupCheck->fetchColumn() > 0) {
            $error = 'A collection with this name already exists.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            // Slug is left untouched on rename - see modules/categories/edit.php's note.
            $pdo->prepare('UPDATE collections SET name = ? WHERE id = ?')->execute([$form['name'], $collectionId]);
            $pdo->commit();

            app_redirect('/modules/collections/edit.php?id=' . $collectionId . '&updated=1');
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to update collection.';
        }
    }
}

$productCount = catalog_collection_product_count($pdo, $collectionId);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Edit Collection</h2>
        <p class="text-muted mb-0"><?php echo app_escape($collection['name']); ?> &middot; <?php echo (int) $productCount; ?> product(s)</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/collections/index.php">Back to Collections</a>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Collection updated.</div>
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
                <label class="form-label">Slug</label>
                <input type="text" class="form-control" value="<?php echo app_escape($collection['slug']); ?>" disabled>
                <div class="form-text">The slug isn't editable here - it may already be synced to WooCommerce.</div>
            </div>
        </div>

        <button class="btn btn-primary mt-4" type="submit">Save Changes</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
