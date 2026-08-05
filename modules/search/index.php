<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/global_search.php';
app_require_login();

$appTitle = 'Search Results';
$pdo = app_db();
$term = trim((string) ($_GET['q'] ?? ''));
$limitPerType = 25;
$sections = global_search($pdo, $term, $limitPerType);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4">
    <h1 class="mb-1">Search Results</h1>
    <p class="text-muted mb-0">
        <?php if ($term !== ''): ?>
            Results for "<?php echo app_escape($term); ?>"
        <?php else: ?>
            Type something into the search box above to get started.
        <?php endif; ?>
    </p>
</div>

<?php if ($term !== '' && $sections === []): ?>
    <div class="card p-4">
        <div class="empty-state">
            <div class="empty-state-title">No Results</div>
            <p class="empty-state-text">Nothing matched "<?php echo app_escape($term); ?>" in Products, Orders, Customers, Supplier Orders, Shipments, or the Catalogue.</p>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <?php foreach ($sections as $section): ?>
        <div class="col-md-6">
            <div class="card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><?php echo app_escape($section['label']); ?></h5>
                    <?php if (count($section['items']) >= $limitPerType): ?>
                        <a class="small" href="<?php echo app_escape($section['see_all_url']); ?>">See all &rarr;</a>
                    <?php endif; ?>
                </div>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($section['items'] as $item): ?>
                        <li class="mb-2 pb-2 border-bottom">
                            <a href="<?php echo app_escape($item['url']); ?>"><?php echo app_escape($item['title']); ?></a>
                            <?php if (!empty($item['subtitle'])): ?>
                                <div class="text-muted small"><?php echo app_escape($item['subtitle']); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
