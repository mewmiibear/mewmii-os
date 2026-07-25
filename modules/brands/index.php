<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/image_upload.php';
app_require_permission('products.view');

$appTitle = 'Brands';
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
        $error = 'You do not have permission to manage brands.';
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($name === '') {
                $error = 'Enter a brand name.';
            }

            if ($error === '') {
                $pdo->beginTransaction();

                try {
                    $newId = catalog_get_or_create_brand($pdo, $name);

                    if ($description !== '') {
                        $pdo->prepare('UPDATE brands SET description = ? WHERE id = ?')->execute([$description, $newId]);
                    }
                    if (!empty($_FILES['logo']['name'])) {
                        $logoPath = image_upload_process($_FILES['logo'], 'brands');
                        $pdo->prepare('UPDATE brands SET logo_path = ? WHERE id = ?')->execute([$logoPath, $newId]);
                    }

                    $pdo->commit();

                    app_redirect('/modules/brands/index.php?created=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to create brand.';
                }
            }
        } elseif ($action === 'move') {
            $sourceId = (int) ($_POST['source_id'] ?? 0);
            $destinationId = (int) ($_POST['destination_id'] ?? 0);

            if ($sourceId < 1 || $destinationId < 1) {
                $error = 'Select a destination brand.';
            } else {
                $pdo->beginTransaction();

                try {
                    $moved = catalog_brand_move_products($pdo, $sourceId, $destinationId);
                    $pdo->commit();

                    app_redirect('/modules/brands/index.php?moved=' . $moved);
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to move products.';
                }
            }
        } elseif ($action === 'delete') {
            $brandId = (int) ($_POST['brand_id'] ?? 0);

            if ($brandId < 1) {
                $error = 'Invalid brand.';
            } else {
                $pdo->beginTransaction();

                try {
                    catalog_brand_delete_if_unused($pdo, $brandId);
                    $pdo->commit();

                    app_redirect('/modules/brands/index.php?deleted=1');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = $exception->getMessage();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to delete brand.';
                }
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

// $brands stays the full, unfiltered list - it also feeds the Move modal's destination
// dropdown below, which must always be able to move products to any brand, not just ones
// matching the current search. $displayedBrands is the search-filtered subset shown in the
// table itself.
$search = trim((string) ($_GET['q'] ?? ''));
$brands = catalog_list_brands_with_counts($pdo);
$displayedBrands = $brands;
if ($search !== '') {
    $needle = strtolower($search);
    $displayedBrands = array_values(array_filter($brands, static function (array $brand) use ($needle): bool {
        return strpos(strtolower($brand['name']), $needle) !== false;
    }));
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Brands</h2>
        <p class="text-muted mb-0">Brand management - edit, move products between brands, or delete unused ones.</p>
    </div>
    <div class="action-bar">
        <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Brand created.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Brand updated.</div>
<?php endif; ?>
<?php if (isset($_GET['moved'])): ?>
    <div class="alert alert-success"><?php echo (int) $_GET['moved']; ?> product(s) moved.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Brand deleted.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3">Add Brand</h5>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-md-10">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" maxlength="120" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
            <div class="col-md-8">
                <label class="form-label">Description (optional)</label>
                <textarea class="form-control" name="description" rows="2"></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label">Logo (optional)</label>
                <input type="file" class="form-control" name="logo" accept="image/*">
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?php echo app_escape($search); ?>" placeholder="Search brands by name&hellip;">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-secondary w-100">Search</button>
        </div>
        <?php if ($search !== ''): ?>
            <div class="col-md-2">
                <a class="btn btn-outline-secondary w-100" href="/modules/brands/index.php">Clear</a>
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
                <th>Products</th>
                <th>Date Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($displayedBrands as $brand): ?>
                <tr>
                    <td><?php echo app_escape($brand['name']); ?></td>
                    <td><?php echo (int) $brand['product_count']; ?></td>
                    <td><?php echo $brand['created_at'] !== null ? app_escape($brand['created_at']) : '-'; ?></td>
                    <td class="text-end">
                        <?php if ($canManage): ?>
                            <div class="d-flex gap-1 justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/brands/edit.php?id=<?php echo (int) $brand['id']; ?>">Edit</a>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#moveModal"
                                    data-source-id="<?php echo (int) $brand['id']; ?>"
                                    data-source-name="<?php echo app_escape($brand['name']); ?>"
                                    data-product-count="<?php echo (int) $brand['product_count']; ?>">Move</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this brand?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="brand_id" value="<?php echo (int) $brand['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($displayedBrands === []): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <?php if ($search !== ''): ?>
                                <div class="empty-state-title">No Brands Match "<?php echo app_escape($search); ?>"</div>
                            <?php else: ?>
                                <div class="empty-state-title">No Brands Yet</div>
                                <p class="empty-state-text">Brands help customers browse your catalogue - add one to get started.</p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($canManage): ?>
    <div class="modal fade" id="moveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="move">
                    <input type="hidden" name="source_id" id="moveSourceId">
                    <div class="modal-header">
                        <h5 class="modal-title">Move Brand</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Source: <strong id="moveSourceName"></strong></p>
                        <p class="mb-3">Products affected: <strong id="moveProductCount"></strong></p>
                        <label class="form-label">Destination</label>
                        <select class="form-select" name="destination_id" id="moveDestinationSelect" required>
                            <option value="">Select a destination brand&hellip;</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?php echo (int) $brand['id']; ?>"><?php echo app_escape($brand['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Move Products</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.querySelectorAll('[data-bs-target="#moveModal"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('moveSourceId').value = btn.dataset.sourceId;
            document.getElementById('moveSourceName').textContent = btn.dataset.sourceName;
            document.getElementById('moveProductCount').textContent = btn.dataset.productCount;

            var select = document.getElementById('moveDestinationSelect');
            select.value = '';
            Array.from(select.options).forEach(function (opt) {
                opt.disabled = (opt.value !== '' && opt.value === btn.dataset.sourceId);
            });
        });
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
