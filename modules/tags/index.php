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

                    app_redirect('/modules/tags/index.php?created=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to create tag.';
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

                    app_redirect('/modules/tags/index.php?merged=' . $merged);
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

                    app_redirect('/modules/tags/index.php?deleted=1');
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

$tags = catalog_list_tags_with_counts($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Tags</h2>
        <p class="text-muted mb-0">Tag management - edit, merge duplicate tags together, or delete unused ones.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
</div>

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
        <h5 class="mb-3">Add Tag</h5>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-md-10">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" maxlength="100" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card p-4">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Products</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tags as $tag): ?>
                <tr>
                    <td><?php echo app_escape($tag['name']); ?></td>
                    <td><?php echo (int) $tag['product_count']; ?></td>
                    <td class="text-end">
                        <?php if ($canManage): ?>
                            <div class="d-flex gap-1 justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/tags/edit.php?id=<?php echo (int) $tag['id']; ?>">Edit</a>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mergeModal"
                                    data-source-id="<?php echo (int) $tag['id']; ?>"
                                    data-source-name="<?php echo app_escape($tag['name']); ?>"
                                    data-product-count="<?php echo (int) $tag['product_count']; ?>">Merge</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this tag?');">
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
            <?php if ($tags === []): ?>
                <tr><td colspan="3" class="text-muted">No tags yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($canManage): ?>
    <div class="modal fade" id="mergeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="merge">
                    <input type="hidden" name="source_id" id="mergeSourceId">
                    <div class="modal-header">
                        <h5 class="modal-title">Merge Tag</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Source: <strong id="mergeSourceName"></strong></p>
                        <p class="mb-3">Products affected: <strong id="mergeProductCount"></strong></p>
                        <label class="form-label">Destination</label>
                        <select class="form-select" name="destination_id" id="mergeDestinationSelect" required>
                            <option value="">Select a destination tag&hellip;</option>
                            <?php foreach ($tags as $tag): ?>
                                <option value="<?php echo (int) $tag['id']; ?>"><?php echo app_escape($tag['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Every product tagged "<span id="mergeSourceNameInline"></span>" will be retagged with the destination. Duplicates are skipped. The source tag is removed afterward.</div>
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
            document.getElementById('mergeSourceId').value = btn.dataset.sourceId;
            document.getElementById('mergeSourceName').textContent = btn.dataset.sourceName;
            document.getElementById('mergeSourceNameInline').textContent = btn.dataset.sourceName;
            document.getElementById('mergeProductCount').textContent = btn.dataset.productCount;

            var select = document.getElementById('mergeDestinationSelect');
            select.value = '';
            Array.from(select.options).forEach(function (opt) {
                opt.disabled = (opt.value !== '' && opt.value === btn.dataset.sourceId);
            });
        });
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
