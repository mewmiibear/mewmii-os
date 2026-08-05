<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.view');

$appTitle = 'Expenses';
$pdo = app_db();

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = isset($_GET['category_id']) && (int) $_GET['category_id'] > 0 ? (int) $_GET['category_id'] : null;
$statusFilter = isset($_GET['status']) && in_array($_GET['status'], EXPENSE_STATUSES, true) ? $_GET['status'] : null;
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$perPage = 50;
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;

$where = [];
$params = [];

if ($searchTerm !== '') {
    $where[] = '(e.description LIKE ? OR e.reference_number LIKE ?)';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
}
if ($categoryFilter !== null) {
    $where[] = 'e.category_id = ?';
    $params[] = $categoryFilter;
}
if ($statusFilter !== null) {
    $where[] = 'e.status = ?';
    $params[] = $statusFilter;
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'e.expense_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'e.expense_date <= ?';
    $params[] = $dateTo;
}

$whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e{$whereSql}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT e.id, e.expense_date, e.description, e.amount, e.currency, e.status, e.tax_deductible,
           ec.name AS category_name, s.name AS supplier_name, ba.name AS bank_account_name
    FROM expenses e
    INNER JOIN expense_categories ec ON ec.id = e.category_id
    LEFT JOIN suppliers s ON s.id = e.supplier_id
    LEFT JOIN bank_accounts ba ON ba.id = e.bank_account_id
    {$whereSql}
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$periodTotalStmt = $pdo->prepare("SELECT COALESCE(SUM(e.amount), 0) FROM expenses e{$whereSql}");
$periodTotalStmt->execute($params);
$periodTotal = (float) $periodTotalStmt->fetchColumn();

$categories = expense_categories_flat($pdo);
$canManage = app_has_permission('finance.manage');

$statusLabels = ['draft' => 'Draft', 'paid' => 'Paid', 'archived' => 'Archived'];
$statusBadgeClass = ['draft' => 'bg-warning text-dark', 'paid' => 'bg-success', 'archived' => 'bg-secondary'];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">Expenses</h1>
        <p class="text-muted mb-0">Every business expense in one place.</p>
    </div>
    <?php if ($canManage): ?>
        <div class="action-bar">
            <a class="btn btn-primary" href="/modules/finance/create.php">Add Expense</a>
            <a class="btn btn-outline-secondary" href="/modules/finance/categories.php">Manage Categories</a>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Expense recorded.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Expense updated.</div>
<?php endif; ?>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Description or reference">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Category</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">All categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo (int) $category['id']; ?>" <?php echo $categoryFilter === $category['id'] ? 'selected' : ''; ?>>
                        <?php echo str_repeat('&nbsp;&nbsp;', $category['depth']) . ($category['depth'] > 0 ? '&#8627; ' : '') . app_escape($category['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All statuses</option>
                <?php foreach ($statusLabels as $value => $label): ?>
                    <option value="<?php echo app_escape($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo app_escape($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">From</label>
            <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo app_escape($dateFrom); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">To</label>
            <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo app_escape($dateTo); ?>">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/finance/index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="text-muted small mb-0">
            <?php echo (int) $totalCount; ?> expense<?php echo $totalCount === 1 ? '' : 's'; ?> matching these filters
        </p>
        <p class="fw-bold mb-0">Total: RM <?php echo app_escape(number_format($periodTotal, 2)); ?></p>
    </div>
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Category</th>
                <th>Description</th>
                <th>Supplier</th>
                <th>Bank Account</th>
                <th>Amount</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($expenses as $expense): ?>
                <tr>
                    <td data-label="Date"><?php echo app_escape($expense['expense_date']); ?></td>
                    <td data-label="Category"><?php echo app_escape($expense['category_name']); ?></td>
                    <td data-label="Description"><?php echo app_escape($expense['description']); ?></td>
                    <td data-label="Supplier"><?php echo app_escape($expense['supplier_name'] ?? '-'); ?></td>
                    <td data-label="Bank Account"><?php echo app_escape($expense['bank_account_name'] ?? '-'); ?></td>
                    <td data-label="Amount"><?php echo app_escape($expense['currency']); ?> <?php echo app_escape(number_format((float) $expense['amount'], 2)); ?></td>
                    <td data-label="Status"><span class="badge <?php echo $statusBadgeClass[$expense['status']]; ?>"><?php echo app_escape($statusLabels[$expense['status']]); ?></span></td>
                    <td data-label="" class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="/modules/finance/view.php?id=<?php echo (int) $expense['id']; ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($expenses === []): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <?php if ($searchTerm !== '' || $categoryFilter !== null || $statusFilter !== null): ?>
                                <div class="empty-state-title">No Expenses Match These Filters</div>
                            <?php else: ?>
                                <div class="empty-state-title">No Expenses Yet</div>
                                <p class="empty-state-text">Every packaging purchase, subscription, or bill recorded here builds your Profit &amp; Loss picture.</p>
                                <?php if ($canManage): ?>
                                    <a class="btn btn-primary btn-sm" href="/modules/finance/create.php">Add Expense</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    $financePageUrl = static function (int $targetPage): string {
        return '/modules/finance/index.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $financeRangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $financeRangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $financeRangeStart; ?>&ndash;<?php echo (int) $financeRangeEnd; ?> of <?php echo (int) $totalCount; ?>
            <?php else: ?>
                0 expenses
            <?php endif; ?>
        </p>
        <?php if ($totalPages > 1): ?>
            <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo app_escape($financePageUrl(max(1, $page - 1))); ?>">&laquo; Prev</a>
                <span class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo app_escape($financePageUrl(min($totalPages, $page + 1))); ?>">Next &raquo;</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
