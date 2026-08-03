<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.view');

$pdo = app_db();
$assetId = (int) ($_GET['id'] ?? 0);
$asset = $assetId > 0 ? asset_get($pdo, $assetId) : null;

if ($asset === null) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="empty-state"><div class="empty-state-title">Asset Not Found</div></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$appTitle = 'Asset: ' . $asset['name'];
$canManage = app_has_permission('finance.manage');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Defence in depth: the page itself only needs finance.view, but every write action on it
    // requires finance.manage. app_require_permission() emits 403 and exits, so a forged POST
    // from a view-only session never reaches an action branch.
    app_require_permission('finance.manage');

    try {
        app_require_csrf();

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'dispose') {
            // asset_dispose() re-checks status = 'in_use' in the UPDATE itself, so a replayed
            // or forged POST cannot overwrite an existing disposal date.
            asset_dispose($pdo, $assetId, trim((string) ($_POST['disposal_date'] ?? '')));
            app_redirect('/modules/finance/asset_view.php?id=' . $assetId . '&disposed=1');
        } elseif ($action === 'upload_document') {
            if (isset($_FILES['document']) && (int) ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                asset_attachment_store($pdo, $assetId, $_FILES['document'], $_SESSION['user_id'] ?? null);
                activity_log($pdo, 'finance', 'asset_attachment_uploaded', $assetId, 'Document attached to asset #' . $assetId . '.');
                app_redirect('/modules/finance/asset_view.php?id=' . $assetId . '&document_added=1');
            } else {
                $error = 'Choose a file to upload.';
            }
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Exception $exception) {
        $error = 'Action failed: ' . $exception->getMessage();
    }

    // Re-fetch in case we fell through to render after a failed action rather than redirecting.
    $asset = asset_get($pdo, $assetId);
}

$attachments = asset_attachments_list($pdo, $assetId);
$statusLabels = asset_status_labels();
$statusBadgeClass = ['in_use' => 'bg-success', 'disposed' => 'bg-secondary'];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1"><?php echo app_escape($asset['name']); ?></h2>
        <p class="page-description">
            <?php if (!empty($asset['asset_code'])): ?>
                <span class="text-muted me-2"><?php echo app_escape($asset['asset_code']); ?></span>
            <?php endif; ?>
            <span class="badge <?php echo $statusBadgeClass[$asset['status']] ?? 'bg-secondary'; ?>"><?php echo app_escape($statusLabels[$asset['status']] ?? $asset['status']); ?></span>
        </p>
    </div>
    <div class="action-bar">
        <?php if ($canManage): ?>
            <a class="btn btn-outline-primary btn-sm" href="/modules/finance/asset_edit.php?id=<?php echo (int) $assetId; ?>">Edit</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/finance/assets.php">Back to Assets</a>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Asset updated.</div>
<?php endif; ?>
<?php if (isset($_GET['disposed'])): ?>
    <div class="alert alert-success">Asset marked as disposed.</div>
<?php endif; ?>
<?php if (isset($_GET['document_added'])): ?>
    <div class="alert alert-success">Document attached.</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Details</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Asset Code</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['asset_code'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Category</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['category']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Purchase Date</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['purchase_date']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Purchase Amount</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['currency']); ?> <?php echo app_escape(number_format((float) $asset['purchase_amount'], 2)); ?></div>
                </div>
                <?php if ($asset['exchange_rate'] !== null): ?>
                    <div class="col-md-4">
                        <div class="text-muted small">Exchange Rate</div>
                        <div class="fw-semibold"><?php echo app_escape((string) $asset['exchange_rate']); ?></div>
                    </div>
                <?php endif; ?>
                <div class="col-md-4">
                    <div class="text-muted small">Supplier</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['supplier_name'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Bank Account</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['bank_account_name'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Assigned To</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['assigned_to_name'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Location</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['location'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Warranty Expiry</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['warranty_expiry'] ?? '-'); ?></div>
                </div>
                <div class="col-12">
                    <div class="text-muted small">Description</div>
                    <div class="fw-semibold"><?php echo app_escape($asset['description']); ?></div>
                </div>
                <?php if (!empty($asset['notes'])): ?>
                    <div class="col-12">
                        <div class="text-muted small">Notes</div>
                        <div><?php echo nl2br(app_escape($asset['notes'])); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Status</h5>
            <?php if ($asset['status'] === 'disposed'): ?>
                <p class="text-muted small mb-2">This asset was disposed on <strong><?php echo app_escape($asset['disposal_date'] ?? '-'); ?></strong>.</p>
                <p class="text-muted small mb-0">Disposal is final - an asset that returns to service is recorded as a new asset, not reactivated.</p>
            <?php else: ?>
                <p class="text-muted small">In Use &rarr; Disposed. Disposing an asset is final and cannot be undone.</p>
                <?php if ($canManage): ?>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="dispose">
                        <label class="form-label small mb-1">Disposal Date</label>
                        <input type="date" class="form-control form-control-sm mb-2" name="disposal_date" value="<?php echo app_escape(date('Y-m-d')); ?>" required>
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100" onclick="return confirm('Dispose this asset? This cannot be undone.');">Dispose Asset</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card p-4">
    <h5 class="mb-3">Invoices &amp; Documents</h5>
    <?php if ($attachments === []): ?>
        <p class="text-muted small">No documents attached.</p>
    <?php else: ?>
        <ul class="list-unstyled mb-3">
            <?php foreach ($attachments as $attachment): ?>
                <li class="d-flex justify-content-between align-items-center border-bottom py-2">
                    <span><?php echo app_escape($attachment['original_filename']); ?></span>
                    <a class="btn btn-sm btn-outline-secondary" href="/modules/finance/asset_attachment_download.php?id=<?php echo (int) $attachment['id']; ?>" target="_blank">View / Download</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canManage): ?>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="upload_document">
            <div class="flex-grow-1">
                <label class="form-label small mb-1">Attach another document</label>
                <input type="file" class="form-control form-control-sm" name="document" accept="image/jpeg,image/png,application/pdf">
            </div>
            <button type="submit" class="btn btn-sm btn-outline-primary">Upload</button>
        </form>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
