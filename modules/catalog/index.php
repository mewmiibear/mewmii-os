<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/image_upload.php';
app_require_permission('products.view');

/**
 * Catalogue Manager - single entry point for the five taxonomy screens (Categories, Brands,
 * Collections, Tags, Attributes) that used to live at modules/{categories,brands,collections,
 * tags,attributes}/index.php + edit.php. Each tab's actual CRUD logic lives in
 * modules/catalog/tabs/{tab}.php as a pair of catalog_tab_{tab}_boot()/_render() functions -
 * this file only resolves which tab is active, runs its boot() (which handles POST, including
 * redirect-on-success, before any HTML is emitted), then renders the shared chrome (title, tab
 * nav) around that tab's render(). None of the underlying data access changed: every tab still
 * calls the same includes/catalog.php functions the old per-taxonomy pages called directly.
 * The old pages themselves now just redirect here (see e.g. modules/categories/index.php).
 */

$appTitle = 'Catalogue';
$pdo = app_db();
$canManage = app_has_permission('products.manage');

$tabs = [
    'categories' => 'Categories',
    'brands' => 'Brands',
    'collections' => 'Collections',
    'tags' => 'Tags',
    'attributes' => 'Attributes',
];
$tab = (string) ($_GET['tab'] ?? 'categories');
if (!isset($tabs[$tab])) {
    $tab = 'categories';
}

require __DIR__ . '/tabs/' . $tab . '.php';

$ctx = call_user_func('catalog_tab_' . $tab . '_boot', $pdo, $canManage);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1">Catalogue</h1>
        <p class="page-description">Manage categories, brands, collections, tags, and attributes for your product catalogue.</p>
    </div>
    <div class="action-bar">
        <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
    </div>
</div>

<ul class="nav nav-tabs mb-4">
    <?php foreach ($tabs as $tabKey => $tabLabel): ?>
        <li class="nav-item">
            <a class="nav-link<?php echo $tabKey === $tab ? ' active' : ''; ?>" href="/modules/catalog/index.php?tab=<?php echo app_escape($tabKey); ?>"><?php echo app_escape($tabLabel); ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php call_user_func('catalog_tab_' . $tab . '_render', $ctx); ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
