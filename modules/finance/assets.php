<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/pagination.php';
require_once __DIR__ . '/../../includes/finance.php';
app_require_permission('finance.view');

$appTitle = 'Assets';
$pdo = app_db();
$canManage = app_has_permission('finance.manage');

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$locationFilter = trim((string) ($_GET['location'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$perPage = 50;
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;

$filters = [
    'q' => $searchTerm,
    'category' => $categoryFilter,
    'status' => $statusFilter,
    'location' => $locationFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

// assets_list() owns the WHERE clause and returns its own totals, so the count and the rows can
// never be filtered differently - one query pass, no second WHERE to drift out of sync.
$result = assets_list($pdo, $filters, $perPage, ($page - 1) * $perPage);
$totalCount = $result['total_count'];
$totalPages = max(1, (int) ceil($totalCount / $perPage));

// An out-of-range ?page= (hand-typed, or a stale link after rows were removed) redirects to the
// last real page rather than silently rendering an empty table.
if ($page > $totalPages) {
    app_redirect('/modules/finance/assets.php?' . http_build_query(array_merge($_GET, ['page' => $totalPages])));
}

$assets = $result['rows'];
$totalAmount = $result['total_amount'];

$statusLabels = asset_status_labels();
$statusBadgeClass = ['in_use' => 'bg-success', 'disposed' => 'bg-secondary'];

$hasFilters = $searchTerm !== '' || $categoryFilter !== '' || $statusFilter !== '' || $locationFilter !== '' || $dateFrom !== '' || $dateTo !== '';

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1">Assets</h1>
        <p class="page-description">Equipment and furniture the business owns - what it is, where it is, and who has it.</p>
    </div>
    <?php if ($canManage): ?>
        <div class="action-bar">
            <a class="btn btn-primary" href="/modules/finance/asset_create.php">Add Asset</a>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Asset recorded.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Asset updated.</div>
<?php endif; ?>

<div class="card filter-card p-3 mb-4" data-filter-chips="1">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Name, description, or code">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Category</label>
            <select name="category" class="form-select form-select-sm">
                <option value="">All categories</option>
                <?php foreach (ASSET_CATEGORIES as $category): ?>
                    <option value="<?php echo app_escape($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo app_escape($category); ?></option>
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
            <label class="form-label small mb-1">Location</label>
            <input type="text" class="form-control form-control-sm" name="location" value="<?php echo app_escape($locationFilter); ?>" placeholder="e.g. Office">
        </div>
        <div class="col-md-3 row g-2">
            <div class="col-6">
                <label class="form-label small mb-1">From</label>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo app_escape($dateFrom); ?>">
            </div>
            <div class="col-6">
                <label class="form-label small mb-1">To</label>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo app_escape($dateTo); ?>">
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/finance/assets.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="text-muted small mb-0">
            <?php echo (int) $totalCount; ?> asset<?php echo $totalCount === 1 ? '' : 's'; ?> matching these filters
        </p>
        <p class="fw-bold mb-0">Total purchase value: RM <?php echo app_escape(number_format($totalAmount, 2)); ?></p>
    </div>
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Category</th>
                <th>Purchase Date</th>
                <th>Amount</th>
                <th>Supplier</th>
                <th>Location</th>
                <th>Assigned To</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($assets as $asset): ?>
                <tr>
                    <td data-label="Code"><?php echo app_escape($asset['asset_code'] ?? '-'); ?></td>
                    <td data-label="Name"><?php echo app_escape($asset['name']); ?></td>
                    <td data-label="Category"><?php echo app_escape($asset['category']); ?></td>
                    <td data-label="Purchase Date"><?php echo app_escape($asset['purchase_date']); ?></td>
                    <td data-label="Amount"><?php echo app_escape($asset['currency']); ?> <?php echo app_escape(number_format((float) $asset['purchase_amount'], 2)); ?></td>
                    <td data-label="Supplier"><?php echo app_escape($asset['supplier_name'] ?? '-'); ?></td>
                    <td data-label="Location"><?php echo app_escape($asset['location'] ?? '-'); ?></td>
                    <td data-label="Assigned To"><?php echo app_escape($asset['assigned_to_name'] ?? '-'); ?></td>
                    <td data-label="Status"><span class="badge <?php echo $statusBadgeClass[$asset['status']] ?? 'bg-secondary'; ?>"><?php echo app_escape($statusLabels[$asset['status']] ?? $asset['status']); ?></span></td>
                    <td data-label="" class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="/modules/finance/asset_view.php?id=<?php echo (int) $asset['id']; ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($assets === []): ?>
                <tr>
                    <td colspan="10">
                        <div class="empty-state">
                            <?php if ($hasFilters): ?>
                                <div class="empty-state-title">No Assets Match These Filters</div>
                            <?php else: ?>
                                <div class="empty-state-title">No Assets Yet</div>
                                <p class="empty-state-text">Shelving, laptops, cameras, and display furniture recorded here stay separate from day-to-day expenses.</p>
                                <?php if ($canManage): ?>
                                    <a class="btn btn-primary btn-sm" href="/modules/finance/asset_create.php">Add Asset</a>
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
    render_pagination('/modules/finance/assets.php', $page, $totalPages, $totalCount, $perPage, 'asset');
        ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
