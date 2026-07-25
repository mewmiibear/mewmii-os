<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/system_health.php';
app_require_permission('settings.manage');

$appTitle = 'System Health';
$pdo = app_db();
$health = system_health_check($pdo);

// Bugfix pass (WooCommerce image sync 404) - on-demand only, never run on a plain page
// load: a self-request from this same server can spuriously fail even when the URL is
// genuinely reachable from the internet, so this is only ever run when an admin explicitly
// clicks the button below, not folded into the automatic checks above.
$uploadsCheckError = '';
$uploadsSample = null;
$uploadsReachability = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $uploadsCheckError = $exception->getMessage();
    }

    if ($uploadsCheckError === '' && (string) ($_POST['action'] ?? '') === 'check_uploads') {
        $uploadsSample = system_health_sample_image($pdo);

        if ($uploadsSample === null) {
            $uploadsCheckError = 'No product images are stored yet - upload one first, then re-check.';
        } elseif ($uploadsSample['url'] === '') {
            $uploadsCheckError = 'Could not compute a public URL at all (app.url/app.uploads_url in config.php are both unset AND this ran outside a normal web request) - see includes/bootstrap.php\'s app_uploads_base_url().';
        } else {
            $uploadsReachability = system_health_check_url_reachable($uploadsSample['url']);
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">System Health</h2>
        <p class="text-muted mb-0">Checks whether the database actually has what this codebase currently expects - read-only, changes nothing.</p>
    </div>
</div>

<div class="d-flex gap-2 mb-4">
    <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/maintenance.php">Data Cleanup</a>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/export.php">Data Export</a>
    <a class="btn btn-secondary btn-sm" href="/modules/settings/system_health.php">System Health</a>
</div>

<?php if ($health['pending'] !== []): ?>
    <div class="alert alert-warning">
        <strong>Pending migration(s) detected.</strong> Run these against this database (via browser or CLI - see each script's own header comment):
        <ul class="mb-0">
            <?php foreach ($health['pending'] as $migrationFile): ?>
                <li><code>database/<?php echo app_escape($migrationFile); ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="alert alert-success">Every known migration appears to be applied.</div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Database</h5>
    <ul class="list-unstyled mb-0">
        <?php foreach ($health['migrations'] as $check): ?>
            <li class="mb-2">
                <?php if ($check['applied']): ?>
                    <span class="text-success">&#9989;</span>
                <?php else: ?>
                    <span class="text-danger">&#9888;</span>
                <?php endif; ?>
                <?php echo app_escape($check['label']); ?>
                <span class="text-muted small">(<?php echo $check['column'] !== null ? app_escape($check['table'] . '.' . $check['column']) : app_escape($check['table'] . ' table'); ?> &middot; <code>database/<?php echo app_escape($check['migration']); ?></code>)</span>
            </li>
        <?php endforeach; ?>
        <li class="mb-2">
            <?php if ($health['indexes']['missing'] === []): ?>
                <span class="text-success">&#9989;</span> Required performance indexes (<?php echo (int) $health['indexes']['present']; ?>/<?php echo (int) $health['indexes']['total']; ?>)
            <?php else: ?>
                <span class="text-danger">&#9888;</span> Required performance indexes (<?php echo (int) $health['indexes']['present']; ?>/<?php echo (int) $health['indexes']['total']; ?> present)
                <div class="text-muted small">Missing: <?php echo app_escape(implode(', ', $health['indexes']['missing'])); ?></div>
            <?php endif; ?>
        </li>
    </ul>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Uploads Accessibility</h5>
    <p class="text-muted small">Checks whether a real stored image's computed public URL is actually reachable over the internet - the exact thing WooCommerce needs when pulling a product image during sync. This makes one outbound request only when you click the button below.</p>

    <?php if ($uploadsCheckError !== ''): ?>
        <div class="alert alert-danger"><?php echo app_escape($uploadsCheckError); ?></div>
    <?php endif; ?>

    <?php if ($uploadsSample !== null && $uploadsReachability !== null): ?>
        <div class="alert <?php echo $uploadsReachability['reachable'] ? 'alert-success' : 'alert-warning'; ?>">
            <div><strong>Stored path:</strong> <code><?php echo app_escape($uploadsSample['path']); ?></code></div>
            <div><strong>Computed public URL:</strong> <code><?php echo app_escape($uploadsSample['url']); ?></code></div>
            <div class="mt-1">
                <?php if ($uploadsReachability['reachable']): ?>
                    <span class="text-success">&#9989; Reachable</span> (HTTP <?php echo (int) $uploadsReachability['http_status']; ?>)
                <?php else: ?>
                    <span class="text-danger">&#9888; Not reachable</span>
                    <?php echo $uploadsReachability['http_status'] !== null ? ('(HTTP ' . (int) $uploadsReachability['http_status'] . ')') : ''; ?>
                    <?php echo $uploadsReachability['error'] !== null ? (' - ' . app_escape($uploadsReachability['error'])) : ''; ?>
                    <div class="small mt-1">If this keeps 404ing, the admin app's own host (app.url) likely isn't where <code>/uploads</code> is publicly served from - set <code>app.uploads_url</code> in config.php to the correct public host and re-check.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="check_uploads">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Check Uploads Accessibility</button>
    </form>
</div>

<p class="text-muted small">This page checks one representative column/table per migration script, not the full historical schema - it's meant to catch a whole migration never having been run, not to audit every column ever added. See <a href="/modules/sync-logs/index.php">Sync Logs</a> for runtime sync activity.</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
