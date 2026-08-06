<?php

/**
 * Catalogue Manager > Tags tab. Same WordPress-admin-style Add/Edit-in-place pattern as
 * tabs/categories.php, minus image/hierarchy, with Merge in place of Move (product_tags has
 * no natural "safe to reassign then delete" story other than merging duplicates together).
 */

function catalog_tab_tags_find(PDO $pdo, int $tagId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM product_tags WHERE id = ? LIMIT 1');
    $stmt->execute([$tagId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function catalog_tab_tags_boot(PDO $pdo, bool $canManage): array
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
            $error = 'You do not have permission to manage tags.';
        }

        if ($error === '') {
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'add') {
                $name = trim((string) ($_POST['name'] ?? ''));

                if ($name === '') {
                    $error = 'Enter a tag name.';
                } else {
                    $pdo->beginTransaction();

                    try {
                        catalog_get_or_create_tag($pdo, $name);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=tags&created=1');
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to create tag.';
                    }
                }
            } elseif ($action === 'update') {
                $tagId = (int) ($_POST['tag_id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $editFormOverride = ['name' => $name];

                $existing = $tagId > 0 ? catalog_tab_tags_find($pdo, $tagId) : null;

                if ($existing === null) {
                    $error = 'Tag not found.';
                } elseif ($name === '' || strlen($name) > 100) {
                    $error = 'Name is required and must be 100 characters or fewer.';
                }

                if ($error === '') {
                    $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM product_tags WHERE LOWER(name) = LOWER(?) AND id != ?');
                    $dupCheck->execute([$name, $tagId]);
                    if ((int) $dupCheck->fetchColumn() > 0) {
                        $error = 'A tag with this name already exists - use Merge instead to combine them.';
                    }
                }

                if ($error === '') {
                    $pdo->beginTransaction();

                    try {
                        $pdo->prepare('UPDATE product_tags SET name = ? WHERE id = ?')->execute([$name, $tagId]);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=tags&edit=' . $tagId . '&updated=1');
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to update tag.';
                    }
                }
            } elseif ($action === 'merge') {
                $sourceId = (int) ($_POST['source_id'] ?? 0);
                $destinationId = (int) ($_POST['destination_id'] ?? 0);

                if ($sourceId < 1 || $destinationId < 1) {
                    $error = 'Select a destination tag.';
                } else {
                    $pdo->beginTransaction();

                    try {
                        $merged = catalog_tag_merge($pdo, $sourceId, $destinationId);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=tags&merged=' . $merged);
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to merge tags.';
                    }
                }
            } elseif ($action === 'delete') {
                $tagId = (int) ($_POST['tag_id'] ?? 0);

                if ($tagId < 1) {
                    $error = 'Invalid tag.';
                } else {
                    $pdo->beginTransaction();

                    try {
                        catalog_tag_delete_if_unused($pdo, $tagId);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=tags&deleted=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to delete tag.';
                    }
                }
            } else {
                $error = 'Unknown action.';
            }
        }
    }

    // $tags stays the full, unfiltered list - it also feeds the Merge modal's destination
    // dropdown below. $displayedTags is the search-filtered subset shown in the table.
    $search = trim((string) ($_GET['q'] ?? ''));
    $tags = catalog_list_tags_with_counts($pdo);
    $displayedTags = $tags;
    if ($search !== '') {
        $needle = strtolower($search);
        $displayedTags = array_values(array_filter($tags, static function (array $tag) use ($needle): bool {
            return strpos(strtolower($tag['name']), $needle) !== false;
        }));
    }

    $editTag = null;
    if ($editId > 0) {
        $dbTag = catalog_tab_tags_find($pdo, $editId);

        if ($dbTag === null) {
            http_response_code(404);
            require_once __DIR__ . '/../../../includes/header.php';
            echo '<div class="alert alert-danger">Tag not found.</div>';
            require_once __DIR__ . '/../../../includes/footer.php';
            exit;
        }

        $editTag = $dbTag;
        if ($editFormOverride !== null) {
            $editTag['name'] = $editFormOverride['name'];
        }
    }

    return [
        'error' => $error,
        'canManage' => $canManage,
        'search' => $search,
        'tags' => $tags,
        'displayedTags' => $displayedTags,
        'editTag' => $editTag,
    ];
}

function catalog_tab_tags_render(array $ctx): void
{
    extract($ctx);
    /**
     * @var string $error
     * @var bool $canManage
     * @var string $search
     * @var array $tags
     * @var array $displayedTags
     * @var array|null $editTag
     */
    ?>
    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Tag created.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Tag updated.</div>
    <?php endif; ?>
    <?php if (isset($_GET['merged'])): ?>
        <div class="alert alert-success"><?php echo (int) $_GET['merged']; ?> product(s) reassigned. Source tag removed.</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Tag deleted.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <div class="card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><?php echo $editTag !== null ? 'Edit Tag' : 'Add Tag'; ?></h5>
                <?php if ($editTag !== null): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=tags">Cancel</a>
                <?php endif; ?>
            </div>
            <form method="post" class="row g-2 align-items-end"
                  action="/modules/catalog/index.php?tab=tags<?php echo $editTag !== null ? '&edit=' . (int) $editTag['id'] : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="<?php echo $editTag !== null ? 'update' : 'add'; ?>">
                <?php if ($editTag !== null): ?>
                    <input type="hidden" name="tag_id" value="<?php echo (int) $editTag['id']; ?>">
                <?php endif; ?>
                <div class="col-md-10">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-control" name="name" maxlength="100" required
                           value="<?php echo $editTag !== null ? app_escape($editTag['name']) : ''; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><?php echo $editTag !== null ? 'Save' : 'Add'; ?></button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="card p-4 mb-4 filter-card">
        <form method="get" class="row g-2 align-items-end" action="/modules/catalog/index.php">
            <input type="hidden" name="tab" value="tags">
            <div class="col-md-6">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="q" value="<?php echo app_escape($search); ?>" placeholder="Search tags by name&hellip;">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-secondary w-100">Search</button>
            </div>
            <?php if ($search !== ''): ?>
                <div class="col-md-2">
                    <a class="btn btn-outline-secondary w-100" href="/modules/catalog/index.php?tab=tags">Clear</a>
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
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($displayedTags as $tag): ?>
                    <tr>
                        <td><?php echo app_escape($tag['name']); ?></td>
                        <td><a href="/modules/products/index.php?<?php echo http_build_query(['tag_ids' => [(int) $tag['id']]]); ?>"><?php echo (int) $tag['product_count']; ?></a></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                                <div class="d-flex gap-1 justify-content-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=tags&edit=<?php echo (int) $tag['id']; ?>">Edit</a>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mergeModal"
                                        data-source-id="<?php echo (int) $tag['id']; ?>"
                                        data-source-name="<?php echo app_escape($tag['name']); ?>"
                                        data-product-count="<?php echo (int) $tag['product_count']; ?>">Merge</button>
                                    <form method="post" class="d-inline" action="/modules/catalog/index.php?tab=tags" data-confirm="This cannot be undone. A record still assigned to products cannot be deleted." data-confirm-title="Delete tag &quot;<?php echo app_escape($tag['name']); ?>&quot;?" data-confirm-label="Delete tag" data-confirm-tone="danger">
                                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="tag_id" value="<?php echo (int) $tag['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($displayedTags === []): ?>
                    <tr>
                        <td colspan="3">
                            <div class="empty-state">
                                <?php if ($search !== ''): ?>
                                    <div class="empty-state-title">No Tags Match "<?php echo app_escape($search); ?>"</div>
                                <?php else: ?>
                                    <div class="empty-state-title">No Tags Yet</div>
                                    <p class="empty-state-text">Tags help you label and filter products - add one to get started.</p>
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
        <div class="modal fade" id="mergeModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post" action="/modules/catalog/index.php?tab=tags">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="merge">
                        <input type="hidden" name="source_id" id="tagsMergeSourceId">
                        <div class="modal-header">
                            <h5 class="modal-title">Merge Tag</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-1">Source: <strong id="tagsMergeSourceName"></strong></p>
                            <p class="mb-3">Products affected: <strong id="tagsMergeProductCount"></strong></p>
                            <label class="form-label">Destination</label>
                            <select class="form-select" name="destination_id" id="tagsMergeDestinationSelect" required>
                                <option value="">Select a destination tag&hellip;</option>
                                <?php foreach ($tags as $tag): ?>
                                    <option value="<?php echo (int) $tag['id']; ?>"><?php echo app_escape($tag['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Every product tagged "<span id="tagsMergeSourceNameInline"></span>" will be retagged with the destination. Duplicates are skipped. The source tag is removed afterward.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Merge Tags</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        document.querySelectorAll('[data-bs-target="#mergeModal"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('tagsMergeSourceId').value = btn.dataset.sourceId;
                document.getElementById('tagsMergeSourceName').textContent = btn.dataset.sourceName;
                document.getElementById('tagsMergeSourceNameInline').textContent = btn.dataset.sourceName;
                document.getElementById('tagsMergeProductCount').textContent = btn.dataset.productCount;

                var select = document.getElementById('tagsMergeDestinationSelect');
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
