<?php

/**
 * Catalogue Manager > Collections tab. Same WordPress-admin-style Add/Edit-in-place pattern
 * as tabs/categories.php, minus the hierarchy - see that file's header comment for the
 * overall approach.
 */

function catalog_tab_collections_find(PDO $pdo, int $collectionId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM collections WHERE id = ? LIMIT 1');
    $stmt->execute([$collectionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function catalog_tab_collections_boot(PDO $pdo, bool $canManage): array
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
            $error = 'You do not have permission to manage collections.';
        }

        if ($error === '') {
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'add') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));

                if ($name === '') {
                    $error = 'Enter a collection name.';
                }

                if ($error === '') {
                    $pdo->beginTransaction();

                    try {
                        $newId = catalog_get_or_create_collection($pdo, $name);

                        if ($description !== '') {
                            $pdo->prepare('UPDATE collections SET description = ? WHERE id = ?')->execute([$description, $newId]);
                        }
                        if (!empty($_FILES['image']['name'])) {
                            $imagePath = image_upload_process($_FILES['image'], 'collections');
                            $pdo->prepare('UPDATE collections SET image_path = ? WHERE id = ?')->execute([$imagePath, $newId]);
                        }

                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=collections&created=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to create collection.';
                    }
                }
            } elseif ($action === 'update') {
                $collectionId = (int) ($_POST['collection_id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                $editFormOverride = ['name' => $name, 'description' => $description];

                $existing = $collectionId > 0 ? catalog_tab_collections_find($pdo, $collectionId) : null;

                if ($existing === null) {
                    $error = 'Collection not found.';
                } elseif ($name === '' || strlen($name) > 120) {
                    $error = 'Name is required and must be 120 characters or fewer.';
                }

                if ($error === '') {
                    $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE LOWER(name) = LOWER(?) AND id != ?');
                    $dupCheck->execute([$name, $collectionId]);
                    if ((int) $dupCheck->fetchColumn() > 0) {
                        $error = 'A collection with this name already exists.';
                    }
                }

                if ($error === '') {
                    $pdo->beginTransaction();

                    try {
                        $pdo->prepare('UPDATE collections SET name = ?, description = ? WHERE id = ?')
                            ->execute([$name, $description !== '' ? $description : null, $collectionId]);

                        if (!empty($_POST['remove_image'])) {
                            image_upload_delete($existing['image_path']);
                            $pdo->prepare('UPDATE collections SET image_path = NULL WHERE id = ?')->execute([$collectionId]);
                        }
                        if (!empty($_FILES['image']['name'])) {
                            $newImagePath = image_upload_process($_FILES['image'], 'collections');
                            image_upload_delete($existing['image_path']);
                            $pdo->prepare('UPDATE collections SET image_path = ? WHERE id = ?')->execute([$newImagePath, $collectionId]);
                        }

                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=collections&edit=' . $collectionId . '&updated=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to update collection.';
                    }
                }
            } elseif ($action === 'move') {
                $sourceId = (int) ($_POST['source_id'] ?? 0);
                $destinationId = (int) ($_POST['destination_id'] ?? 0);

                if ($sourceId < 1 || $destinationId < 1) {
                    $error = 'Select a destination collection.';
                } else {
                    $pdo->beginTransaction();

                    try {
                        $moved = catalog_collection_move_products($pdo, $sourceId, $destinationId);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=collections&moved=' . $moved);
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to move products.';
                    }
                }
            } elseif ($action === 'delete') {
                $collectionId = (int) ($_POST['collection_id'] ?? 0);

                if ($collectionId < 1) {
                    $error = 'Invalid collection.';
                } else {
                    $pdo->beginTransaction();

                    try {
                        catalog_collection_delete_if_unused($pdo, $collectionId);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=collections&deleted=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to delete collection.';
                    }
                }
            } else {
                $error = 'Unknown action.';
            }
        }
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $collections = catalog_list_collections_with_counts($pdo);
    $displayedCollections = $collections;
    if ($search !== '') {
        $needle = strtolower($search);
        $displayedCollections = array_values(array_filter($collections, static function (array $collection) use ($needle): bool {
            return strpos(strtolower($collection['name']), $needle) !== false;
        }));
    }

    $editCollection = null;
    if ($editId > 0) {
        $dbCollection = catalog_tab_collections_find($pdo, $editId);

        if ($dbCollection === null) {
            http_response_code(404);
            require_once __DIR__ . '/../../../includes/header.php';
            echo '<div class="alert alert-danger">Collection not found.</div>';
            require_once __DIR__ . '/../../../includes/footer.php';
            exit;
        }

        $editCollection = $dbCollection;
        if ($editFormOverride !== null) {
            $editCollection['name'] = $editFormOverride['name'];
            $editCollection['description'] = $editFormOverride['description'];
        }
    }

    return [
        'error' => $error,
        'canManage' => $canManage,
        'search' => $search,
        'collections' => $collections,
        'displayedCollections' => $displayedCollections,
        'editCollection' => $editCollection,
    ];
}

function catalog_tab_collections_render(array $ctx): void
{
    extract($ctx);
    /**
     * @var string $error
     * @var bool $canManage
     * @var string $search
     * @var array $collections
     * @var array $displayedCollections
     * @var array|null $editCollection
     */
    ?>
    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Collection created.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Collection updated.</div>
    <?php endif; ?>
    <?php if (isset($_GET['moved'])): ?>
        <div class="alert alert-success"><?php echo (int) $_GET['moved']; ?> product(s) moved.</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Collection deleted.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <div class="card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><?php echo $editCollection !== null ? 'Edit Collection' : 'Add Collection'; ?></h5>
                <?php if ($editCollection !== null): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=collections">Cancel</a>
                <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end"
                  action="/modules/catalog/index.php?tab=collections<?php echo $editCollection !== null ? '&edit=' . (int) $editCollection['id'] : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="<?php echo $editCollection !== null ? 'update' : 'add'; ?>">
                <?php if ($editCollection !== null): ?>
                    <input type="hidden" name="collection_id" value="<?php echo (int) $editCollection['id']; ?>">
                <?php endif; ?>
                <div class="col-md-10">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-control" name="name" maxlength="120" required
                           value="<?php echo $editCollection !== null ? app_escape($editCollection['name']) : ''; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><?php echo $editCollection !== null ? 'Save' : 'Add'; ?></button>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Description (optional)</label>
                    <textarea class="form-control" name="description" rows="2"><?php echo $editCollection !== null ? app_escape($editCollection['description'] ?? '') : ''; ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Image (optional)</label>
                    <?php if ($editCollection !== null && ($editCollection['image_path'] ?? '') !== ''): ?>
                        <div class="mb-2">
                            <img src="/<?php echo app_escape($editCollection['image_path']); ?>" alt="" style="max-width: 90px; max-height: 90px;" class="border rounded d-block mb-1">
                            <label class="d-block small">
                                <input type="checkbox" name="remove_image" value="1"> Remove current image
                            </label>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" name="image" accept="image/*">
                </div>
                <?php if ($editCollection !== null): ?>
                    <div class="col-12">
                        <span class="form-text">Slug: <?php echo app_escape($editCollection['slug']); ?> (not editable here - may already be synced to WooCommerce)</span>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <p class="text-muted small">To assign products to a collection, select them on the <a href="/modules/products/index.php">Products</a> list and use the "Set Collection" bulk action. The count below links to that collection's currently-assigned products.</p>

    <div class="card p-4 mb-4 filter-card">
        <form method="get" class="row g-2 align-items-end" action="/modules/catalog/index.php">
            <input type="hidden" name="tab" value="collections">
            <div class="col-md-6">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="q" value="<?php echo app_escape($search); ?>" placeholder="Search collections by name&hellip;">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-secondary w-100">Search</button>
            </div>
            <?php if ($search !== ''): ?>
                <div class="col-md-2">
                    <a class="btn btn-outline-secondary w-100" href="/modules/catalog/index.php?tab=collections">Clear</a>
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
                <?php foreach ($displayedCollections as $collection): ?>
                    <tr>
                        <td><?php echo app_escape($collection['name']); ?></td>
                        <td><a href="/modules/products/index.php?collection_id=<?php echo (int) $collection['id']; ?>"><?php echo (int) $collection['product_count']; ?></a></td>
                        <td><?php echo $collection['created_at'] !== null ? app_escape($collection['created_at']) : '-'; ?></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                                <div class="d-flex gap-1 justify-content-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=collections&edit=<?php echo (int) $collection['id']; ?>">Edit</a>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#moveModal"
                                        data-source-id="<?php echo (int) $collection['id']; ?>"
                                        data-source-name="<?php echo app_escape($collection['name']); ?>"
                                        data-product-count="<?php echo (int) $collection['product_count']; ?>">Move</button>
                                    <form method="post" class="d-inline" action="/modules/catalog/index.php?tab=collections" data-confirm="This cannot be undone. A record still assigned to products cannot be deleted." data-confirm-title="Delete collection &quot;<?php echo app_escape($collection['name']); ?>&quot;?" data-confirm-label="Delete collection" data-confirm-tone="danger">
                                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="collection_id" value="<?php echo (int) $collection['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($displayedCollections === []): ?>
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <?php if ($search !== ''): ?>
                                    <div class="empty-state-title">No Collections Match "<?php echo app_escape($search); ?>"</div>
                                <?php else: ?>
                                    <div class="empty-state-title">No Collections Yet</div>
                                    <p class="empty-state-text">Collections group related products together - add one to get started.</p>
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
                    <form method="post" action="/modules/catalog/index.php?tab=collections">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="source_id" id="collectionsMoveSourceId">
                        <div class="modal-header">
                            <h5 class="modal-title">Move Collection</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-1">Source: <strong id="collectionsMoveSourceName"></strong></p>
                            <p class="mb-3">Products affected: <strong id="collectionsMoveProductCount"></strong></p>
                            <label class="form-label">Destination</label>
                            <select class="form-select" name="destination_id" id="collectionsMoveDestinationSelect" required>
                                <option value="">Select a destination collection&hellip;</option>
                                <?php foreach ($collections as $collection): ?>
                                    <option value="<?php echo (int) $collection['id']; ?>"><?php echo app_escape($collection['name']); ?></option>
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
                document.getElementById('collectionsMoveSourceId').value = btn.dataset.sourceId;
                document.getElementById('collectionsMoveSourceName').textContent = btn.dataset.sourceName;
                document.getElementById('collectionsMoveProductCount').textContent = btn.dataset.productCount;

                var select = document.getElementById('collectionsMoveDestinationSelect');
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
