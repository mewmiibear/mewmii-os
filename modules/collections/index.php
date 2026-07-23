<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('products.view');

$appTitle = 'Collections';
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
        $error = 'You do not have permission to manage collections.';
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add') {
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                $error = 'Enter a collection name.';
            } else {
                $pdo->beginTransaction();

                try {
                    catalog_get_or_create_collection($pdo, $name);
                    $pdo->commit();

                    app_redirect('/modules/collections/index.php?created=1');
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Failed to create collection.';
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

                    app_redirect('/modules/collections/index.php?moved=' . $moved);
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

                    app_redirect('/modules/collections/index.php?deleted=1');
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

$collections = catalog_list_collections_with_counts($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Collections</h2>
        <p class="text-muted mb-0">Collection management - edit, move products between collections, or delete unused ones.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
</div>

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
        <h5 class="mb-3">Add Collection</h5>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-md-10">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" maxlength="120" required>
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
                <th>Date Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($collections as $collection): ?>
                <tr>
                    <td><?php echo app_escape($collection['name']); ?></td>
                    <td><?php echo (int) $collection['product_count']; ?></td>
                    <td><?php echo $collection['created_at'] !== null ? app_escape($collection['created_at']) : '-'; ?></td>
                    <td class="text-end">
                        <?php if ($canManage): ?>
                            <div class="d-flex gap-1 justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/collections/edit.php?id=<?php echo (int) $collection['id']; ?>">Edit</a>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#moveModal"
                                    data-source-id="<?php echo (int) $collection['id']; ?>"
                                    data-source-name="<?php echo app_escape($collection['name']); ?>"
                                    data-product-count="<?php echo (int) $collection['product_count']; ?>">Move</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this collection?');">
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
            <?php if ($collections === []): ?>
                <tr><td colspan="4" class="text-muted">No collections yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
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
                        <h5 class="modal-title">Move Collection</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Source: <strong id="moveSourceName"></strong></p>
                        <p class="mb-3">Products affected: <strong id="moveProductCount"></strong></p>
                        <label class="form-label">Destination</label>
                        <select class="form-select" name="destination_id" id="moveDestinationSelect" required>
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
