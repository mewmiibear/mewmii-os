<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/system_health.php';
app_require_permission('settings.manage');

$appTitle = 'System Health';
$pdo = app_db();
$health = system_health_check($pdo);

// Bugfix pass (WooCommerce image sync still 404s after two URL-config attempts) - the
// filesystem diagnostic (does the file exist on disk, is that location even inside the
// directory this request's web server is serving) is cheap/local and safe to compute on
// every load. The HTTP reachability probe is a real outbound request, so that part stays
// on-demand only - see system_health_check_url_reachable()'s own docblock for why.
$uploadsSample = system_health_sample_image($pdo);
$uploadsFsInfo = system_health_uploads_filesystem_info($uploadsSample['path'] ?? null);

$uploadsCheckError = '';
$uploadsReachability = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $uploadsCheckError = $exception->getMessage();
    }

    if ($uploadsCheckError === '' && (string) ($_POST['action'] ?? '') === 'check_uploads') {
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

    <h6 class="text-muted">Filesystem</h6>
    <ul class="list-unstyled small">
        <li><strong>uploads/ resolves to:</strong> <code><?php echo app_escape($uploadsFsInfo['base_dir']); ?></code></li>
        <li>
            <strong>Web server's document root for this request:</strong>
            <?php echo $uploadsFsInfo['document_root'] !== null ? ('<code>' . app_escape($uploadsFsInfo['document_root']) . '</code>') : '<span class="text-muted">unknown (DOCUMENT_ROOT not set)</span>'; ?>
        </li>
        <li>
            <?php if ($uploadsFsInfo['inside_document_root'] === true): ?>
                <span class="text-success">&#9989;</span> uploads/ is inside the document root - a file that exists there should be servable as a static file on whichever domain this request came in on.
            <?php elseif ($uploadsFsInfo['inside_document_root'] === false): ?>
                <span class="text-danger">&#9888;</span> <strong>uploads/ is OUTSIDE the document root.</strong> No URL on any domain can reach a file here directly - this is a hosting/deployment issue, not a config value. See the recommendations below.
            <?php else: ?>
                <span class="text-muted">Could not determine (document root unknown for this request).</span>
            <?php endif; ?>
        </li>
        <?php if ($uploadsFsInfo['sample_relative_path'] !== null): ?>
            <li class="mt-2"><strong>Sample stored image:</strong> <code><?php echo app_escape($uploadsFsInfo['sample_relative_path']); ?></code></li>
            <li><strong>Resolved absolute path:</strong> <code><?php echo app_escape((string) $uploadsFsInfo['sample_absolute_path']); ?></code></li>
            <li>
                <?php if ($uploadsFsInfo['sample_exists_on_disk']): ?>
                    <span class="text-success">&#9989;</span> File exists on disk at that path.
                <?php else: ?>
                    <span class="text-danger">&#9888;</span> File does NOT exist on disk at that path - the upload may have failed, or the app is reading/writing a different location than expected (e.g. a symlink pointing elsewhere).
                <?php endif; ?>
            </li>
        <?php else: ?>
            <li class="text-muted">No product images are stored yet - upload one first to run this check.</li>
        <?php endif; ?>
    </ul>

    <?php if ($uploadsFsInfo['inside_document_root'] === false): ?>
        <div class="alert alert-warning small mb-3">
            <strong>Recommended hosting fixes</strong> (pick whichever fits your setup - this needs a hosting/deployment change, not another <code>app.uploads_url</code> guess):
            <ul class="mb-0 mt-1">
                <li><strong>Symlink</strong>: create a symlink from inside a domain's public document root pointing at this app's real <code>uploads/</code> folder (e.g. <code>ln -s /path/to/mewmii-os/uploads /path/to/public_html/uploads</code>).</li>
                <li><strong>Move the document root</strong>: point the admin subdomain's document root directly at this app's own folder (so <code>uploads/</code> becomes a normal subfolder of it) - only safe if nothing else on that domain depends on the current document root.</li>
                <li><strong>URL mapping via a streaming endpoint</strong>: add a small PHP script (e.g. <code>media.php?path=...</code>) that validates the requested path and streams the file from wherever it actually lives, then point <code>app.uploads_url</code> at that script instead of a raw static path - works regardless of document root placement, at the cost of every image request running through PHP instead of being served as a static file.</li>
            </ul>
        </div>
    <?php endif; ?>

    <h6 class="text-muted mt-3">Public reachability (live check)</h6>
    <p class="text-muted small">Makes one outbound request to the computed public URL, only when you click the button below.</p>

    <?php if ($uploadsCheckError !== ''): ?>
        <div class="alert alert-danger"><?php echo app_escape($uploadsCheckError); ?></div>
    <?php endif; ?>

    <?php if ($uploadsSample !== null && $uploadsReachability !== null): ?>
        <div class="alert <?php echo $uploadsReachability['reachable'] ? 'alert-success' : 'alert-warning'; ?>">
            <div><strong>Computed public URL:</strong> <code><?php echo app_escape($uploadsSample['url']); ?></code></div>
            <div class="mt-1">
                <?php if ($uploadsReachability['reachable']): ?>
                    <span class="text-success">&#9989; Reachable</span> (HTTP <?php echo (int) $uploadsReachability['http_status']; ?>)
                <?php else: ?>
                    <span class="text-danger">&#9888; Not reachable</span>
                    <?php echo $uploadsReachability['http_status'] !== null ? ('(HTTP ' . (int) $uploadsReachability['http_status'] . ')') : ''; ?>
                    <?php echo $uploadsReachability['error'] !== null ? (' - ' . app_escape($uploadsReachability['error'])) : ''; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="check_uploads">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Check Public Reachability</button>
    </form>
</div>

<p class="text-muted small">This page checks one representative column/table per migration script, not the full historical schema - it's meant to catch a whole migration never having been run, not to audit every column ever added. See <a href="/modules/sync-logs/index.php">Sync Logs</a> for runtime sync activity.</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
