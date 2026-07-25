<?php

/**
 * Catalogue Manager > Categories tab. Combines the old modules/categories/index.php
 * (list/add/move/delete) and edit.php (rename/reparent/description/image) into one
 * WordPress-admin-style flow: the "Add Category" card becomes an "Edit Category" card in
 * place when ?tab=categories&edit=ID is present, posting an `update` action instead of
 * `add`. Move and Delete stay list-row actions, unchanged from the original. All data access
 * still goes through the same includes/catalog.php functions the old pages called directly.
 */

function catalog_tab_categories_find(PDO $pdo, int $categoryId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function catalog_tab_categories_boot(PDO $pdo, bool $canManage): array
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
            $error = 'You do not have permission to manage categories.';
        }

        if ($error === '') {
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'add') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $parentId = ((string) ($_POST['parent_id'] ?? '')) !== '' ? (int) $_POST['parent_id'] : null;
                $description = trim((string) ($_POST['description'] ?? ''));

                if ($name === '') {
                    $error = 'Enter a category name.';
                }

                if ($error === '') {
                    $pdo->beginTransaction();

                    try {
                        $newId = catalog_get_or_create_category($pdo, $name, $parentId);

                        if ($description !== '') {
                            $pdo->prepare('UPDATE categories SET description = ? WHERE id = ?')->execute([$description, $newId]);
                        }
                        if (!empty($_FILES['image']['name'])) {
                            $imagePath = image_upload_process($_FILES['image'], 'categories');
                            $pdo->prepare('UPDATE categories SET image_path = ? WHERE id = ?')->execute([$imagePath, $newId]);
                        }

                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=categories&created=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to create category.';
                    }
                }
            } elseif ($action === 'update') {
                $categoryId = (int) ($_POST['category_id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $parentIdRaw = (string) ($_POST['parent_id'] ?? '');
                $parentId = $parentIdRaw !== '' ? (int) $parentIdRaw : null;
                $description = trim((string) ($_POST['description'] ?? ''));
                $editFormOverride = ['name' => $name, 'parent_id' => $parentIdRaw, 'description' => $description];

                $existing = $categoryId > 0 ? catalog_tab_categories_find($pdo, $categoryId) : null;

                if ($existing === null) {
                    $error = 'Category not found.';
                } elseif ($name === '' || strlen($name) > 120) {
                    $error = 'Name is required and must be 120 characters or fewer.';
                } elseif ($parentId === $categoryId) {
                    $error = 'A category cannot be its own parent.';
                }

                if ($error === '') {
                    $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?) AND id != ?');
                    $dupCheck->execute([$name, $categoryId]);
                    if ((int) $dupCheck->fetchColumn() > 0) {
                        $error = 'A category with this name already exists.';
                    }
                }

                if ($error === '') {
                    $pdo->beginTransaction();

                    try {
                        // Slug is left untouched on rename - it may already be synced to WooCommerce.
                        $pdo->prepare('UPDATE categories SET name = ?, parent_id = ?, description = ? WHERE id = ?')
                            ->execute([$name, $parentId, $description !== '' ? $description : null, $categoryId]);

                        if (!empty($_POST['remove_image'])) {
                            image_upload_delete($existing['image_path']);
                            $pdo->prepare('UPDATE categories SET image_path = NULL WHERE id = ?')->execute([$categoryId]);
                        }
                        if (!empty($_FILES['image']['name'])) {
                            $newImagePath = image_upload_process($_FILES['image'], 'categories');
                            image_upload_delete($existing['image_path']);
                            $pdo->prepare('UPDATE categories SET image_path = ? WHERE id = ?')->execute([$newImagePath, $categoryId]);
                        }

                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=categories&edit=' . $categoryId . '&updated=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to update category.';
                    }
                }
            } elseif ($action === 'move') {
                $sourceId = (int) ($_POST['source_id'] ?? 0);
                $destinationId = (int) ($_POST['destination_id'] ?? 0);

                if ($sourceId < 1 || $destinationId < 1) {
                    $error = 'Select a destination category.';
                } else {
                    $pdo->beginTransaction();

                    try {
                        $moved = catalog_category_move_products($pdo, $sourceId, $destinationId);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=categories&moved=' . $moved);
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to move products.';
                    }
                }
            } elseif ($action === 'delete') {
                $categoryId = (int) ($_POST['category_id'] ?? 0);

                if ($categoryId < 1) {
                    $error = 'Invalid category.';
                } else {
                    $pdo->beginTransaction();

                    try {
                        catalog_category_delete_if_unused($pdo, $categoryId);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=categories&deleted=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to delete category.';
                    }
                }
            } else {
                $error = 'Unknown action.';
            }
        }
    }

    // Hierarchy-ordered (parent immediately followed by its children), each row still
    // carrying its own product_count from the single query in catalog_list_categories_with_counts().
    $categories = catalog_list_categories_with_counts($pdo);
    $byParent = [];
    foreach ($categories as $category) {
        $parentKey = $category['parent_id'] !== null ? (int) $category['parent_id'] : 0;
        $byParent[$parentKey][] = $category;
    }
    $orderedCategories = [];
    $walkCategories = static function (int $parentKey, int $depth) use (&$walkCategories, &$byParent, &$orderedCategories): void {
        foreach ($byParent[$parentKey] ?? [] as $category) {
            $category['depth'] = $depth;
            $orderedCategories[] = $category;
            $walkCategories((int) $category['id'], $depth + 1);
        }
    };
    $walkCategories(0, 0);

    $search = trim((string) ($_GET['q'] ?? ''));
    $displayedCategories = $orderedCategories;
    if ($search !== '') {
        $needle = strtolower($search);
        $displayedCategories = array_values(array_filter($displayedCategories, static function (array $category) use ($needle): bool {
            return strpos(strtolower($category['name']), $needle) !== false;
        }));
    }

    $editCategory = null;
    $parentOptions = $orderedCategories;

    if ($editId > 0) {
        $dbCategory = catalog_tab_categories_find($pdo, $editId);

        if ($dbCategory === null) {
            http_response_code(404);
            require_once __DIR__ . '/../../../includes/header.php';
            echo '<div class="alert alert-danger">Category not found.</div>';
            require_once __DIR__ . '/../../../includes/footer.php';
            exit;
        }

        $editCategory = $dbCategory;
        if ($editFormOverride !== null) {
            $editCategory['name'] = $editFormOverride['name'];
            $editCategory['parent_id'] = $editFormOverride['parent_id'] !== '' ? $editFormOverride['parent_id'] : null;
            $editCategory['description'] = $editFormOverride['description'];
        }

        // Parent options exclude this category itself and (to avoid a cycle) its own descendants.
        $allCategories = $pdo->query('SELECT id, name, parent_id FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
        $descendantIds = [$editId => true];
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
        $parentOptions = array_values(array_filter($orderedCategories, static fn (array $row): bool => !isset($descendantIds[(int) $row['id']])));
    }

    return [
        'error' => $error,
        'canManage' => $canManage,
        'search' => $search,
        'orderedCategories' => $orderedCategories,
        'displayedCategories' => $displayedCategories,
        'editCategory' => $editCategory,
        'editId' => $editId,
        'parentOptions' => $parentOptions,
    ];
}

function catalog_tab_categories_render(array $ctx): void
{
    extract($ctx);
    /**
     * @var string $error
     * @var bool $canManage
     * @var string $search
     * @var array $orderedCategories
     * @var array $displayedCategories
     * @var array|null $editCategory
     * @var int $editId
     * @var array $parentOptions
     */
    ?>
    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Category created.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Category updated.</div>
    <?php endif; ?>
    <?php if (isset($_GET['moved'])): ?>
        <div class="alert alert-success"><?php echo (int) $_GET['moved']; ?> product(s) moved.</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Category deleted.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <div class="card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><?php echo $editCategory !== null ? 'Edit Category' : 'Add Category'; ?></h5>
                <?php if ($editCategory !== null): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=categories">Cancel</a>
                <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end"
                  action="/modules/catalog/index.php?tab=categories<?php echo $editCategory !== null ? '&edit=' . (int) $editCategory['id'] : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="<?php echo $editCategory !== null ? 'update' : 'add'; ?>">
                <?php if ($editCategory !== null): ?>
                    <input type="hidden" name="category_id" value="<?php echo (int) $editCategory['id']; ?>">
                <?php endif; ?>
                <div class="col-md-5">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-control" name="name" maxlength="120" required
                           value="<?php echo $editCategory !== null ? app_escape($editCategory['name']) : ''; ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Parent Category (optional)</label>
                    <select class="form-select" name="parent_id">
                        <option value="">None (top-level)</option>
                        <?php foreach ($parentOptions as $category): ?>
                            <option value="<?php echo (int) $category['id']; ?>" <?php echo ($editCategory !== null && (string) $editCategory['parent_id'] === (string) $category['id']) ? 'selected' : ''; ?>><?php echo str_repeat('&mdash; ', $category['depth']); ?><?php echo app_escape($category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><?php echo $editCategory !== null ? 'Save' : 'Add'; ?></button>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Description (optional)</label>
                    <textarea class="form-control" name="description" rows="2"><?php echo $editCategory !== null ? app_escape($editCategory['description'] ?? '') : ''; ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Image (optional)</label>
                    <?php if ($editCategory !== null && ($editCategory['image_path'] ?? '') !== ''): ?>
                        <div class="mb-2">
                            <img src="/<?php echo app_escape($editCategory['image_path']); ?>" alt="" style="max-width: 90px; max-height: 90px;" class="border rounded d-block mb-1">
                            <label class="d-block small">
                                <input type="checkbox" name="remove_image" value="1"> Remove current image
                            </label>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" name="image" accept="image/*">
                </div>
                <?php if ($editCategory !== null): ?>
                    <div class="col-12">
                        <span class="form-text">Slug: <?php echo app_escape($editCategory['slug']); ?> (not editable here - may already be synced to WooCommerce)</span>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <div class="card p-4 mb-4 filter-card">
        <form method="get" class="row g-2 align-items-end" action="/modules/catalog/index.php">
            <input type="hidden" name="tab" value="categories">
            <div class="col-md-6">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="q" value="<?php echo app_escape($search); ?>" placeholder="Search categories by name&hellip;">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-secondary w-100">Search</button>
            </div>
            <?php if ($search !== ''): ?>
                <div class="col-md-2">
                    <a class="btn btn-outline-secondary w-100" href="/modules/catalog/index.php?tab=categories">Clear</a>
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
                <?php foreach ($displayedCategories as $category): ?>
                    <tr>
                        <td><?php echo str_repeat('&mdash; ', $category['depth']); ?><?php echo app_escape($category['name']); ?></td>
                        <td><?php echo (int) $category['product_count']; ?></td>
                        <td><?php echo $category['created_at'] !== null ? app_escape($category['created_at']) : '-'; ?></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                                <div class="d-flex gap-1 justify-content-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=categories&edit=<?php echo (int) $category['id']; ?>">Edit</a>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#moveModal"
                                        data-source-id="<?php echo (int) $category['id']; ?>"
                                        data-source-name="<?php echo app_escape($category['name']); ?>"
                                        data-product-count="<?php echo (int) $category['product_count']; ?>">Move</button>
                                    <form method="post" class="d-inline" action="/modules/catalog/index.php?tab=categories" onsubmit="return confirm('Delete this category?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="category_id" value="<?php echo (int) $category['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($displayedCategories === []): ?>
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <?php if ($search !== ''): ?>
                                    <div class="empty-state-title">No Categories Match "<?php echo app_escape($search); ?>"</div>
                                <?php else: ?>
                                    <div class="empty-state-title">No Categories Yet</div>
                                    <p class="empty-state-text">Categories help organise your product catalogue - add one to get started.</p>
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
                    <form method="post" action="/modules/catalog/index.php?tab=categories">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="source_id" id="categoriesMoveSourceId">
                        <div class="modal-header">
                            <h5 class="modal-title">Move Category</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-1">Source: <strong id="categoriesMoveSourceName"></strong></p>
                            <p class="mb-3">Products affected: <strong id="categoriesMoveProductCount"></strong></p>
                            <label class="form-label">Destination</label>
                            <select class="form-select" name="destination_id" id="categoriesMoveDestinationSelect" required>
                                <option value="">Select a destination category&hellip;</option>
                                <?php foreach ($orderedCategories as $category): ?>
                                    <option value="<?php echo (int) $category['id']; ?>"><?php echo str_repeat('&mdash; ', $category['depth']); ?><?php echo app_escape($category['name']); ?></option>
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
                document.getElementById('categoriesMoveSourceId').value = btn.dataset.sourceId;
                document.getElementById('categoriesMoveSourceName').textContent = btn.dataset.sourceName;
                document.getElementById('categoriesMoveProductCount').textContent = btn.dataset.productCount;

                var select = document.getElementById('categoriesMoveDestinationSelect');
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
