<?php

require_once __DIR__ . '/saved_views.php';

/**
 * Renders the Saved Views row for one list page: existing views as clickable pills (same
 * .btn-sm chip look already used by the Products lifecycle chips), each with a small delete
 * affordance, plus a "+ Save current view" pill that reveals an inline name input. Reused
 * as-is across Products/Orders/Supplier Orders/Shipments/Customers - the only per-page input
 * is $module (must be a key of SAVED_VIEWS_MODULES).
 */
function render_saved_views_widget(PDO $pdo, string $module): void
{
    if (!array_key_exists($module, SAVED_VIEWS_MODULES)) {
        return;
    }

    $views = saved_view_list($pdo, $module);

    // The current filter set, minus `page` - what "Save current view" actually stores, so
    // loading a saved view always starts back at page 1 rather than an arbitrary offset.
    $currentParams = $_GET;
    unset($currentParams['page']);
    $currentQueryString = http_build_query($currentParams);
    $basePath = '/modules/' . $module . '/index.php';
    ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <span class="text-muted small">Saved views:</span>
        <?php foreach ($views as $view): ?>
            <div class="d-flex align-items-center gap-1">
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo app_escape($basePath . '?' . $view['query_string']); ?>">
                    <?php echo app_escape($view['name']); ?>
                </a>
                <form method="post" action="/modules/saved-views/delete.php" class="d-inline" onsubmit="return confirm('Delete the saved view &quot;<?php echo app_escape(addslashes($view['name'])); ?>&quot;?');">
                    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                    <input type="hidden" name="module" value="<?php echo app_escape($module); ?>">
                    <input type="hidden" name="view_id" value="<?php echo (int) $view['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete saved view" aria-label="Delete saved view">&times;</button>
                </form>
            </div>
        <?php endforeach; ?>

        <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.getElementById('saved-view-save-form-<?php echo app_escape($module); ?>').classList.toggle('d-none')">+ Save current view</button>

        <form id="saved-view-save-form-<?php echo app_escape($module); ?>" method="post" action="/modules/saved-views/save.php" class="d-none d-flex align-items-center gap-1">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="module" value="<?php echo app_escape($module); ?>">
            <input type="hidden" name="query_string" value="<?php echo app_escape($currentQueryString); ?>">
            <input type="text" name="name" class="form-control form-control-sm" placeholder="View name" required maxlength="100" style="width: 160px;">
            <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </form>
    </div>
    <?php
}
