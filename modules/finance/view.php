<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.view');

$pdo = app_db();
$expenseId = (int) ($_GET['id'] ?? 0);
$expense = $expenseId > 0 ? expense_get($pdo, $expenseId) : null;

if ($expense === null) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="empty-state"><div class="empty-state-title">Expense Not Found</div></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$appTitle = 'Expense: ' . $expense['description'];
$canManage = app_has_permission('finance.manage');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    try {
        app_require_csrf();

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'mark_paid') {
            expense_set_status($pdo, $expenseId, 'paid');
            app_redirect('/modules/finance/view.php?id=' . $expenseId . '&status_updated=1');
        } elseif ($action === 'archive') {
            expense_set_status($pdo, $expenseId, 'archived');
            app_redirect('/modules/finance/view.php?id=' . $expenseId . '&status_updated=1');
        } elseif ($action === 'upload_receipt') {
            if (isset($_FILES['receipt']) && (int) ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                expense_attachment_store($pdo, $expenseId, $_FILES['receipt'], $_SESSION['user_id'] ?? null);
                app_redirect('/modules/finance/view.php?id=' . $expenseId . '&receipt_added=1');
            } else {
                $error = 'Choose a file to upload.';
            }
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Exception $exception) {
        $error = 'Action failed: ' . $exception->getMessage();
    }

    // Re-fetch in case a status transition just happened and we fell through to render
    // (e.g. the upload_receipt error path) rather than redirecting.
    $expense = expense_get($pdo, $expenseId);
}

$attachments = expense_attachments_list($pdo, $expenseId);

$statusLabels = ['draft' => 'Draft', 'paid' => 'Paid', 'archived' => 'Archived'];
$statusBadgeClass = ['draft' => 'bg-warning text-dark', 'paid' => 'bg-success', 'archived' => 'bg-secondary'];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1"><?php echo app_escape($expense['description']); ?></h2>
        <p class="page-description">
            <span class="badge <?php echo $statusBadgeClass[$expense['status']]; ?>"><?php echo app_escape($statusLabels[$expense['status']]); ?></span>
        </p>
    </div>
    <div class="action-bar">
        <?php if ($canManage): ?>
            <a class="btn btn-outline-primary btn-sm" href="/modules/finance/edit.php?id=<?php echo (int) $expenseId; ?>">Edit</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/finance/index.php">Back to Expenses</a>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Expense updated.</div>
<?php endif; ?>
<?php if (isset($_GET['status_updated'])): ?>
    <div class="alert alert-success">Status updated.</div>
<?php endif; ?>
<?php if (isset($_GET['receipt_added'])): ?>
    <div class="alert alert-success">Receipt attached.</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Details</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Date</div>
                    <div class="fw-semibold"><?php echo app_escape($expense['expense_date']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Category</div>
                    <div class="fw-semibold"><?php echo app_escape($expense['category_name']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Supplier</div>
                    <div class="fw-semibold"><?php echo app_escape($expense['supplier_name'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Bank Account</div>
                    <div class="fw-semibold"><?php echo app_escape($expense['bank_account_name'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Amount</div>
                    <div class="fw-semibold"><?php echo app_escape($expense['currency']); ?> <?php echo app_escape(number_format((float) $expense['amount'], 2)); ?></div>
                </div>
                <?php if ($expense['exchange_rate'] !== null): ?>
                    <div class="col-md-4">
                        <div class="text-muted small">Exchange Rate</div>
                        <div class="fw-semibold"><?php echo app_escape((string) $expense['exchange_rate']); ?></div>
                    </div>
                <?php endif; ?>
                <div class="col-md-4">
                    <div class="text-muted small">Payment Method</div>
                    <div class="fw-semibold"><?php echo app_escape($expense['payment_method'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Reference Number</div>
                    <div class="fw-semibold"><?php echo app_escape($expense['reference_number'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Tax Deductible</div>
                    <div class="fw-semibold"><?php echo $expense['tax_deductible'] ? 'Yes' : 'No'; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Status</h5>
            <p class="text-muted small">Draft &rarr; Paid &rarr; Archived. Draft and Paid both count toward Profit &amp; Loss; only Paid counts toward Cash Flow.</p>
            <?php if ($canManage): ?>
                <div class="d-flex flex-column gap-2">
                    <?php if ($expense['status'] === 'draft'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="mark_paid">
                            <button type="submit" class="btn btn-success btn-sm w-100">Mark Paid</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($expense['status'] !== 'archived'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="archive">
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Archive</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($expense['status'] === 'archived'): ?>
                        <p class="text-muted small mb-0">This expense is archived - hidden from active views, but still counted in every report.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card p-4">
    <h5 class="mb-3">Receipts</h5>
    <?php if ($attachments === []): ?>
        <p class="text-muted small">No receipts attached.</p>
    <?php else: ?>
        <ul class="list-unstyled mb-3">
            <?php foreach ($attachments as $attachment): ?>
                <li class="d-flex justify-content-between align-items-center border-bottom py-2">
                    <span><?php echo app_escape($attachment['original_filename']); ?></span>
                    <a class="btn btn-sm btn-outline-secondary" href="/modules/finance/receipt_download.php?id=<?php echo (int) $attachment['id']; ?>" target="_blank">View / Download</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canManage): ?>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="upload_receipt">
            <div class="flex-grow-1">
                <label class="form-label small mb-1">Attach another receipt</label>
                <input type="file" class="form-control form-control-sm" name="receipt" accept="image/jpeg,image/png,application/pdf">
            </div>
            <button type="submit" class="btn btn-sm btn-outline-primary">Upload</button>
        </form>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
