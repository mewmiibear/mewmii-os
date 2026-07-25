<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('suppliers.view');

$appTitle = 'Suppliers';
$pdo = app_db();

// Production Hardening Phase 2: this page used to be a hard `LIMIT 20` with no search, no
// sort, and no way to reach a supplier past the 20 most recently created - unreachable from
// the UI at all once a shop has more than 20 suppliers. Search/sort/pagination below mirror
// the exact pattern already established in modules/products/index.php (and reused by
// modules/inventory/index.php) rather than inventing a new one.
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$sortColumns = [
    'name' => 'name',
    'contact' => 'contact',
    'country' => 'country',
    'created' => 'created_at',
];
$sortKey = isset($_GET['sort']) && array_key_exists($_GET['sort'], $sortColumns) ? $_GET['sort'] : null;
$sortDir = ($_GET['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';
$orderSql = $sortKey !== null ? ($sortColumns[$sortKey] . ' ' . $sortDir . ', id DESC') : 'id DESC';

$perPage = 50;
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;

$whereSql = '';
$params = [];
if ($searchTerm !== '') {
    $whereSql = ' WHERE name LIKE ? OR contact LIKE ? OR country LIKE ?';
    $likeTerm = '%' . $searchTerm . '%';
    $params = [$likeTerm, $likeTerm, $likeTerm];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM suppliers{$whereSql}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT id, name, contact, country, notes FROM suppliers{$whereSql} ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = app_has_permission('suppliers.manage');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Suppliers</h2>
        <p class="text-muted mb-0">Purchase planning and supplier relationship foundation.</p>
    </div>
    <?php if ($canManage): ?>
        <div class="action-bar">
            <a class="btn btn-primary" href="/modules/suppliers/create.php">Add Supplier</a>
            <a class="btn btn-outline-secondary" href="/modules/suppliers/import.php">Import CSV</a>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Supplier created.</div>
<?php endif; ?>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Name, contact, or country">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Sort by</label>
            <select name="sort" class="form-select form-select-sm">
                <option value="">Newest first (default)</option>
                <option value="name" <?php echo $sortKey === 'name' ? 'selected' : ''; ?>>Name</option>
                <option value="contact" <?php echo $sortKey === 'contact' ? 'selected' : ''; ?>>Contact</option>
                <option value="country" <?php echo $sortKey === 'country' ? 'selected' : ''; ?>>Country</option>
                <option value="created" <?php echo $sortKey === 'created' ? 'selected' : ''; ?>>Date Created</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Direction</label>
            <select name="dir" class="form-select form-select-sm">
                <option value="asc" <?php echo $sortDir === 'ASC' ? 'selected' : ''; ?>>Asc</option>
                <option value="desc" <?php echo $sortDir === 'DESC' ? 'selected' : ''; ?>>Desc</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/suppliers/index.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Country</th>
                <th>Notes</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($suppliers as $supplier): ?>
                <tr>
                    <td><?php echo app_escape($supplier['name']); ?></td>
                    <td><?php echo app_escape($supplier['contact'] ?? '-'); ?></td>
                    <td><?php echo app_escape($supplier['country'] ?? '-'); ?></td>
                    <td><?php echo app_escape($supplier['notes'] ?? '-'); ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" href="/modules/suppliers/view.php?id=<?php echo (int) $supplier['id']; ?>">View</a>
                            <?php if ($canManage): ?>
                                <a class="btn btn-sm btn-outline-primary" href="/modules/suppliers/edit.php?id=<?php echo (int) $supplier['id']; ?>">Edit</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($suppliers === []): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <?php if ($searchTerm !== ''): ?>
                                <div class="empty-state-title">No Suppliers Match "<?php echo app_escape($searchTerm); ?>"</div>
                            <?php else: ?>
                                <div class="empty-state-title">No Suppliers Yet</div>
                                <p class="empty-state-text">Suppliers you purchase stock from will appear here.</p>
                                <?php if ($canManage): ?>
                                    <a class="btn btn-primary btn-sm" href="/modules/suppliers/create.php">Add Supplier</a>
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
    $suppliersPageUrl = static function (int $targetPage): string {
        return '/modules/suppliers/index.php?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    $suppliersRangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $suppliersRangeEnd = min($totalCount, $page * $perPage);
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $suppliersRangeStart; ?>&ndash;<?php echo (int) $suppliersRangeEnd; ?> of <?php echo (int) $totalCount; ?> supplier<?php echo $totalCount === 1 ? '' : 's'; ?>
            <?php else: ?>
                0 suppliers
            <?php endif; ?>
        </p>
        <?php if ($totalPages > 1): ?>
            <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo app_escape($suppliersPageUrl(max(1, $page - 1))); ?>">&laquo; Prev</a>
                <span class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo app_escape($suppliersPageUrl(min($totalPages, $page + 1))); ?>">Next &raquo;</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
