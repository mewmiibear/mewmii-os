<?php

/**
 * Catalogue Manager > Brands tab. Same WordPress-admin-style Add/Edit-in-place pattern as
 * tabs/categories.php, minus the hierarchy - see that file's header comment for the overall
 * approach. Field is called "logo" here (logo_path column) rather than "image", matching the
 * original modules/brands/index.php + edit.php exactly.
 */

function catalog_tab_brands_find(PDO $pdo, int $brandId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM brands WHERE id = ? LIMIT 1');
    $stmt->execute([$brandId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function catalog_tab_brands_boot(PDO $pdo, bool $canManage): array
{
    $error = '';
    $editId = (int) ($_GET['edit'] ?? 0);
    $editFormOverride = null;

    if ($editId > 0) {
        app_require_permission('products.manage');
    }

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

                        app_redirect('/modules/catalog/index.php?tab=brands&created=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to create brand.';
                    }
                }
            } elseif ($action === 'update') {
                $brandId = (int) ($_POST['brand_id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                $editFormOverride = ['name' => $name, 'description' => $description];

                $existing = $brandId > 0 ? catalog_tab_brands_find($pdo, $brandId) : null;

                if ($existing === null) {
                    $error = 'Brand not found.';
                } elseif ($name === '' || strlen($name) > 120) {
                    $error = 'Name is required and must be 120 characters or fewer.';
                }

                if ($error === '') {
                    $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM brands WHERE LOWER(name) = LOWER(?) AND id != ?');
                    $dupCheck->execute([$name, $brandId]);
                    if ((int) $dupCheck->fetchColumn() > 0) {
                        $error = 'A brand with this name already exists.';
                    }
                }

                if ($error === '') {
                    $pdo->beginTransaction();

                    try {
                        $pdo->prepare('UPDATE brands SET name = ?, description = ? WHERE id = ?')
                            ->execute([$name, $description !== '' ? $description : null, $brandId]);

                        if (!empty($_POST['remove_logo'])) {
                            image_upload_delete($existing['logo_path']);
                            $pdo->prepare('UPDATE brands SET logo_path = NULL WHERE id = ?')->execute([$brandId]);
                        }
                        if (!empty($_FILES['logo']['name'])) {
                            $newLogoPath = image_upload_process($_FILES['logo'], 'brands');
                            image_upload_delete($existing['logo_path']);
                            $pdo->prepare('UPDATE brands SET logo_path = ? WHERE id = ?')->execute([$newLogoPath, $brandId]);
                        }

                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=brands&edit=' . $brandId . '&updated=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to update brand.';
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

                        app_redirect('/modules/catalog/index.php?tab=brands&moved=' . $moved);
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

                        app_redirect('/modules/catalog/index.php?tab=brands&deleted=1');
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

    $search = trim((string) ($_GET['q'] ?? ''));
    $brands = catalog_list_brands_with_counts($pdo);
    $displayedBrands = $brands;
    if ($search !== '') {
        $needle = strtolower($search);
        $displayedBrands = array_values(array_filter($brands, static function (array $brand) use ($needle): bool {
            return strpos(strtolower($brand['name']), $needle) !== false;
        }));
    }

    $editBrand = null;
    if ($editId > 0) {
        $dbBrand = catalog_tab_brands_find($pdo, $editId);

        if ($dbBrand === null) {
            http_response_code(404);
            require_once __DIR__ . '/../../../includes/header.php';
            echo '<div class="alert alert-danger">Brand not found.</div>';
            require_once __DIR__ . '/../../../includes/footer.php';
            exit;
        }

        $editBrand = $dbBrand;
        if ($editFormOverride !== null) {
            $editBrand['name'] = $editFormOverride['name'];
            $editBrand['description'] = $editFormOverride['description'];
        }
    }

    return [
        'error' => $error,
        'canManage' => $canManage,
        'search' => $search,
        'brands' => $brands,
        'displayedBrands' => $displayedBrands,
        'editBrand' => $editBrand,
    ];
}

function catalog_tab_brands_render(array $ctx): void
{
    extract($ctx);
    /**
     * @var string $error
     * @var bool $canManage
     * @var string $search
     * @var array $brands
     * @var array $displayedBrands
     * @var array|null $editBrand
     */
    ?>
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
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><?php echo $editBrand !== null ? 'Edit Brand' : 'Add Brand'; ?></h5>
                <?php if ($editBrand !== null): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=brands">Cancel</a>
                <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end"
                  action="/modules/catalog/index.php?tab=brands<?php echo $editBrand !== null ? '&edit=' . (int) $editBrand['id'] : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="<?php echo $editBrand !== null ? 'update' : 'add'; ?>">
                <?php if ($editBrand !== null): ?>
                    <input type="hidden" name="brand_id" value="<?php echo (int) $editBrand['id']; ?>">
                <?php endif; ?>
                <div class="col-md-10">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-control" name="name" maxlength="120" required
                           value="<?php echo $editBrand !== null ? app_escape($editBrand['name']) : ''; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><?php echo $editBrand !== null ? 'Save' : 'Add'; ?></button>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Description (optional)</label>
                    <textarea class="form-control" name="description" rows="2"><?php echo $editBrand !== null ? app_escape($editBrand['description'] ?? '') : ''; ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Logo (optional)</label>
                    <?php if ($editBrand !== null && ($editBrand['logo_path'] ?? '') !== ''): ?>
                        <div class="mb-2">
                            <img src="/<?php echo app_escape($editBrand['logo_path']); ?>" alt="" style="max-width: 90px; max-height: 90px;" class="border rounded d-block mb-1">
                            <label class="d-block small">
                                <input type="checkbox" name="remove_logo" value="1"> Remove current logo
                            </label>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" name="logo" accept="image/*">
                </div>
                <?php if ($editBrand !== null): ?>
                    <div class="col-12">
                        <span class="form-text">Slug: <?php echo app_escape($editBrand['slug']); ?> (not editable here - may already be synced to WooCommerce)</span>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <div class="card p-4 mb-4 filter-card">
        <form method="get" class="row g-2 align-items-end" action="/modules/catalog/index.php">
            <input type="hidden" name="tab" value="brands">
            <div class="col-md-6">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="q" value="<?php echo app_escape($search); ?>" placeholder="Search brands by name&hellip;">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-secondary w-100">Search</button>
            </div>
            <?php if ($search !== ''): ?>
                <div class="col-md-2">
                    <a class="btn btn-outline-secondary w-100" href="/modules/catalog/index.php?tab=brands">Clear</a>
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
                        <td><a href="/modules/products/index.php?brand_id=<?php echo (int) $brand['id']; ?>"><?php echo (int) $brand['product_count']; ?></a></td>
                        <td><?php echo $brand['created_at'] !== null ? app_escape($brand['created_at']) : '-'; ?></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                                <div class="d-flex gap-1 justify-content-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=brands&edit=<?php echo (int) $brand['id']; ?>">Edit</a>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#moveModal"
                                        data-source-id="<?php echo (int) $brand['id']; ?>"
                                        data-source-name="<?php echo app_escape($brand['name']); ?>"
                                        data-product-count="<?php echo (int) $brand['product_count']; ?>">Move</button>
                                    <form method="post" class="d-inline" action="/modules/catalog/index.php?tab=brands" onsubmit="return confirm('Delete this brand?');">
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
                    <form method="post" action="/modules/catalog/index.php?tab=brands">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="source_id" id="brandsMoveSourceId">
                        <div class="modal-header">
                            <h5 class="modal-title">Move Brand</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-1">Source: <strong id="brandsMoveSourceName"></strong></p>
                            <p class="mb-3">Products affected: <strong id="brandsMoveProductCount"></strong></p>
                            <label class="form-label">Destination</label>
                            <select class="form-select" name="destination_id" id="brandsMoveDestinationSelect" required>
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
                document.getElementById('brandsMoveSourceId').value = btn.dataset.sourceId;
                document.getElementById('brandsMoveSourceName').textContent = btn.dataset.sourceName;
                document.getElementById('brandsMoveProductCount').textContent = btn.dataset.productCount;

                var select = document.getElementById('brandsMoveDestinationSelect');
                select.value = '';
                Array.from(select.options).forEach(function (opt) {
                    opt.disabled = (opt.value !== '' && opt.value === btn.dataset.sourceId);
                });
            });
        });
        </script>
    <?php endif; ?>
    <?php
}
