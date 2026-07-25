<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/image_upload.php';
app_require_permission('products.manage');

$appTitle = 'Edit Brand';
$error = '';

$brandId = (int) ($_GET['id'] ?? 0);

if ($brandId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Brand not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();

$brandStmt = $pdo->prepare('SELECT * FROM brands WHERE id = ? LIMIT 1');
$brandStmt->execute([$brandId]);
$brand = $brandStmt->fetch(PDO::FETCH_ASSOC);

if (!$brand) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Brand not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$form = [
    'name' => $brand['name'],
    'description' => (string) ($brand['description'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['description'] = trim((string) ($_POST['description'] ?? ''));

    if ($error === '' && ($form['name'] === '' || strlen($form['name']) > 120)) {
        $error = 'Name is required and must be 120 characters or fewer.';
    }

    if ($error === '') {
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM brands WHERE LOWER(name) = LOWER(?) AND id != ?');
        $dupCheck->execute([$form['name'], $brandId]);
        if ((int) $dupCheck->fetchColumn() > 0) {
            $error = 'A brand with this name already exists.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            // Slug is left untouched on rename - see modules/categories/edit.php's note.
            $pdo->prepare('UPDATE brands SET name = ?, description = ? WHERE id = ?')
                ->execute([$form['name'], $form['description'] !== '' ? $form['description'] : null, $brandId]);

            if (!empty($_POST['remove_logo'])) {
                image_upload_delete($brand['logo_path']);
                $pdo->prepare('UPDATE brands SET logo_path = NULL WHERE id = ?')->execute([$brandId]);
            }
            if (!empty($_FILES['logo']['name'])) {
                $newLogoPath = image_upload_process($_FILES['logo'], 'brands');
                image_upload_delete($brand['logo_path']);
                $pdo->prepare('UPDATE brands SET logo_path = ? WHERE id = ?')->execute([$newLogoPath, $brandId]);
            }

            $pdo->commit();

            app_redirect('/modules/brands/edit.php?id=' . $brandId . '&updated=1');
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to update brand.';
        }
    }
}

$productCount = catalog_brand_product_count($pdo, $brandId);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Edit Brand</h2>
        <p class="text-muted mb-0"><?php echo app_escape($brand['name']); ?> &middot; <?php echo (int) $productCount; ?> product(s)</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/brands/index.php">Back to Brands</a>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Brand updated.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" value="<?php echo app_escape($form['name']); ?>" maxlength="120" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Slug</label>
                <input type="text" class="form-control" value="<?php echo app_escape($brand['slug']); ?>" disabled>
                <div class="form-text">The slug isn't editable here - it may already be synced to WooCommerce.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="3"><?php echo app_escape($form['description']); ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Logo</label>
                <?php if ($brand['logo_path'] !== null && $brand['logo_path'] !== ''): ?>
                    <div class="mb-2">
                        <img src="/<?php echo app_escape($brand['logo_path']); ?>" alt="" style="max-width: 140px; max-height: 140px;" class="border rounded d-block mb-2">
                        <label class="d-block small">
                            <input type="checkbox" name="remove_logo" value="1"> Remove current logo
                        </label>
                    </div>
                <?php endif; ?>
                <input type="file" class="form-control" name="logo" accept="image/*" style="max-width: 400px;">
            </div>
        </div>

        <button class="btn btn-primary mt-4" type="submit">Save Changes</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
