<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/system_health.php';
app_require_permission('settings.manage');

$appTitle = 'System Health';
$pdo = app_db();
$health = system_health_check($pdo);
$uploadsDirCheck = system_health_check_uploads_directory();

// Bugfix pass (WooCommerce image sync still 404s after two URL-config attempts) - the
// filesystem diagnostic (does the file exist on disk, is that location even inside the
// directory this request's web server is serving) is cheap/local and safe to compute on
// every load. The HTTP reachability probe is a real outbound request, so that part stays
// on-demand only - see system_health_check_url_reachable()'s own docblock for why.
$uploadsSample = system_health_sample_image($pdo);
$uploadsFsInfo = system_health_uploads_filesystem_info($uploadsSample['path'] ?? null);

$uploadsCheckError = '';
$uploadsReachability = null;

// Bugfix pass ("stop relying on static code tracing") - a real disk write, right now, on
// this server - see system_health_test_image_write()'s own docblock. On-demand only (POST),
// same reasoning as the reachability probe below: this one actually writes a file, so it
// should never run on a plain page load.
$writeTestResults = null;

// Cheap (DB + is_file() only, no network/disk writes) - safe to compute on every load, same
// as the filesystem info above. Shows every stored image's on-disk status at a glance,
// including brand-new ones, so a systemic failure is visibly different from a couple of old
// orphaned rows.
$allImages = system_health_list_all_images($pdo);

// Domain-vs-subdomain uploads mapping - cheap (is_dir/is_link only), safe on every load.
$uploadsDualLocation = system_health_check_uploads_dual_location();
$urlMappingTestResult = null;
$symlinkResult = null;
// The exact root domain named in this incident - not derived from config, since the whole
// point is testing the real mapping independently of whatever app.uploads_url currently says.
const SYSTEM_HEALTH_ROOT_DOMAIN = 'https://mewmiibear.com';

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
    } elseif ($uploadsCheckError === '' && (string) ($_POST['action'] ?? '') === 'test_write') {
        $writeTestResults = [
            'products' => system_health_test_image_write('products'),
            'variations' => system_health_test_image_write('variations'),
        ];
    } elseif ($uploadsCheckError === '' && (string) ($_POST['action'] ?? '') === 'test_url_mapping') {
        $urlMappingTestResult = system_health_test_uploads_url_mapping(SYSTEM_HEALTH_ROOT_DOMAIN);
    } elseif ($uploadsCheckError === '' && (string) ($_POST['action'] ?? '') === 'create_uploads_symlink') {
        $symlinkResult = system_health_create_uploads_symlink();
        $uploadsDualLocation = system_health_check_uploads_dual_location();
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

<?php if (!$uploadsDirCheck['ok']): ?>
    <div class="alert alert-danger">
        <strong>&#9888; Uploads directory problem detected.</strong>
        <?php if (!$uploadsDirCheck['exists']): ?>
            <code><?php echo app_escape($uploadsDirCheck['path']); ?></code> does not exist. Product/variation images cannot be uploaded or served until this is restored - see <code>DEPLOYMENT.md</code>.
        <?php elseif (!$uploadsDirCheck['is_dir']): ?>
            <code><?php echo app_escape($uploadsDirCheck['path']); ?></code> exists but is not a directory.
        <?php elseif (!$uploadsDirCheck['writable']): ?>
            <code><?php echo app_escape($uploadsDirCheck['path']); ?></code> exists but is not writable - new image uploads will fail. Check filesystem permissions.
        <?php endif; ?>
    </div>
<?php endif; ?>

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
                <span class="text-muted small">(<?php
                    // Mirrors the unique_column -> column -> table detection priority used by
                    // system_health_check in includes/system_health.php, so the artifact named
                    // here is always the one actually probed. Without the first branch, a
                    // unique-constraint check would describe itself as a table-existence check.
                    if (isset($check['unique_column'])) {
                        echo app_escape($check['table'] . '.' . $check['unique_column']) . ' unique';
                    } elseif (isset($check['index'])) {
                        echo app_escape($check['table'] . '.' . $check['index']) . ' index';
                    } elseif ($check['column'] !== null) {
                        echo app_escape($check['table'] . '.' . $check['column']);
                    } else {
                        echo app_escape($check['table'] . ' table');
                    }
                ?> &middot; <code>database/<?php echo app_escape($check['migration']); ?></code>)</span>
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

<div class="card p-4 mb-4">
    <h5 class="mb-3">Uploads URL Mapping (domain vs. subdomain)</h5>
    <p class="text-muted small">If this app lives in a subfolder of the main domain's document root (e.g. <code>public_html/admin</code>), the main domain's own <code>public_html/uploads/</code> is a completely different, sibling directory that was never written to - this checks both directly on disk, then tests which one a real URL on the main domain actually serves from.</p>

    <ul class="list-unstyled small">
        <li><strong>Admin app's uploads (known working):</strong> <code><?php echo app_escape($uploadsDualLocation['admin_uploads_dir']); ?></code>
            &mdash; <?php echo $uploadsDualLocation['admin_uploads_exists'] ? '<span class="text-success">exists</span>' : '<span class="text-danger">missing</span>'; ?>
        </li>
        <li class="mt-1"><strong>Main domain's uploads (root):</strong> <code><?php echo app_escape($uploadsDualLocation['root_uploads_dir']); ?></code>
            &mdash;
            <?php if ($uploadsDualLocation['root_uploads_exists']): ?>
                <span class="text-success">exists</span>
                <?php if ($uploadsDualLocation['root_uploads_is_symlink']): ?>
                    (symlink &rarr; <code><?php echo app_escape((string) $uploadsDualLocation['root_uploads_symlink_target']); ?></code>)
                <?php else: ?>
                    (a real directory, not a symlink)
                <?php endif; ?>
            <?php else: ?>
                <span class="text-danger">does not exist</span>
            <?php endif; ?>
        </li>
    </ul>

    <?php if (!$uploadsDualLocation['root_uploads_exists']): ?>
        <div class="alert alert-warning small">
            <code><?php echo app_escape($uploadsDualLocation['root_uploads_dir']); ?></code> does not exist - if the main domain serves uploads from here, that's the 404 explained directly: nothing was ever written to this path.
            <form method="post" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="create_uploads_symlink">
                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Create a symlink at ' + <?php echo json_encode($uploadsDualLocation['root_uploads_dir']); ?> + ' pointing to ' + <?php echo json_encode($uploadsDualLocation['admin_uploads_dir']); ?> + '? This does not move or copy any files.');">Create Symlink (safest fix)</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($symlinkResult !== null): ?>
        <div class="alert <?php echo $symlinkResult['ok'] ? 'alert-success' : 'alert-danger'; ?> small"><?php echo app_escape($symlinkResult['message']); ?></div>
    <?php endif; ?>

    <p class="text-muted small mt-3 mb-1">Test which directory <code><?php echo app_escape(SYSTEM_HEALTH_ROOT_DOMAIN); ?>/uploads/products/...</code> actually serves from - writes a uniquely-named marker file into each existing candidate, fetches it over the real internet, then deletes it.</p>

    <?php if ($urlMappingTestResult !== null): ?>
        <div class="alert alert-info small">
            <div><strong>Admin uploads (<?php echo app_escape($uploadsDualLocation['admin_uploads_dir']); ?>):</strong>
                <?php if ($urlMappingTestResult['admin_marker_skipped_reason'] !== null): ?>
                    skipped - <?php echo app_escape($urlMappingTestResult['admin_marker_skipped_reason']); ?>
                <?php elseif ($urlMappingTestResult['admin_marker_result']['reachable']): ?>
                    <span class="text-success">&#9989; This IS what <?php echo app_escape(SYSTEM_HEALTH_ROOT_DOMAIN); ?>/uploads/products/ serves.</span>
                <?php else: ?>
                    <span class="text-danger">Not reached via this URL prefix.</span>
                <?php endif; ?>
            </div>
            <div class="mt-1"><strong>Root uploads (<?php echo app_escape($uploadsDualLocation['root_uploads_dir']); ?>):</strong>
                <?php if ($urlMappingTestResult['root_marker_skipped_reason'] !== null): ?>
                    skipped - <?php echo app_escape($urlMappingTestResult['root_marker_skipped_reason']); ?>
                <?php elseif ($urlMappingTestResult['root_marker_result']['reachable']): ?>
                    <span class="text-success">&#9989; This IS what <?php echo app_escape(SYSTEM_HEALTH_ROOT_DOMAIN); ?>/uploads/products/ serves.</span>
                <?php else: ?>
                    <span class="text-danger">Not reached via this URL prefix.</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="test_url_mapping">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Test URL Mapping</button>
    </form>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Live Upload Write Test</h5>
    <p class="text-muted small">Writes a tiny real test image to <code>uploads/products/</code> and <code>uploads/variations/</code> on THIS server right now (deleted again immediately after), and reports every step - this is the actual runtime behaviour, not a re-read of the source code.</p>

    <?php if ($writeTestResults !== null): ?>
        <?php foreach ($writeTestResults as $label => $r): ?>
            <div class="alert <?php echo $r['file_exists_after_write'] ? 'alert-success' : 'alert-danger'; ?> mb-3">
                <h6 class="mb-2">uploads/<?php echo app_escape($label); ?>/</h6>
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td>GD available</td><td><?php echo $r['gd_available'] ? 'Yes' : 'No'; ?></td></tr>
                        <tr><td>WebP support available</td><td><?php echo $r['webp_available'] ? 'Yes' : 'No'; ?></td></tr>
                        <tr><td>image_upload_base_dir()</td><td><code><?php echo app_escape($r['base_dir']); ?></code></td></tr>
                        <tr><td>Target directory</td><td><code><?php echo app_escape($r['target_dir']); ?></code></td></tr>
                        <tr><td>Directory create/ensure succeeded</td><td><?php echo $r['target_dir_created_ok'] ? 'Yes' : ('No - ' . app_escape((string) $r['target_dir_error'])); ?></td></tr>
                        <tr><td>is_dir()</td><td><?php echo $r['is_dir'] ? 'true' : 'false'; ?></td></tr>
                        <tr><td>is_writable()</td><td><?php echo $r['is_writable'] ? 'true' : 'false'; ?></td></tr>
                        <tr><td>Destination filename</td><td><code><?php echo app_escape($r['filename']); ?></code></td></tr>
                        <tr><td>Full destination path</td><td><code><?php echo app_escape($r['full_path']); ?></code></td></tr>
                        <tr><td>imagewebp() return value</td><td><?php echo $r['imagewebp_returned'] === null ? '&mdash;' : ($r['imagewebp_returned'] ? 'true' : 'false'); ?></td></tr>
                        <tr><td><strong>file_exists() immediately after write</strong></td><td><strong><?php echo $r['file_exists_after_write'] ? 'true' : 'FALSE'; ?></strong></td></tr>
                        <tr><td>filesize()</td><td><?php echo $r['filesize_after_write'] !== null ? app_escape(number_format($r['filesize_after_write']) . ' bytes') : '&mdash;'; ?></td></tr>
                        <tr><td>Generated public URL</td><td><code><?php echo app_escape($r['public_url']); ?></code></td></tr>
                        <tr><td>Test file cleaned up afterward</td><td><?php echo $r['cleaned_up'] ? 'Yes' : ($r['file_exists_after_write'] ? 'No - left in place, remove manually' : 'N/A'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="test_write">
        <button type="submit" class="btn btn-outline-danger btn-sm">Run Live Write Test</button>
    </form>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Stored Images Audit (last <?php echo count($allImages); ?>)</h5>
    <p class="text-muted small">Every recent <code>product_images</code> row checked against the real filesystem, right now - a missing file on a brand-new row points at the write flow itself; only old rows missing points at something that removed files afterward (a deploy that didn't preserve uploads/, a manual cleanup, etc).</p>
    <?php if ($allImages === []): ?>
        <p class="text-muted mb-0">No images stored yet.</p>
    <?php else: ?>
        <?php $missingCount = count(array_filter($allImages, static fn (array $img): bool => !$img['exists_on_disk'])); ?>
        <p class="mb-2"><?php echo $missingCount > 0 ? ('<span class="text-danger">' . (int) $missingCount . ' of ' . count($allImages) . ' missing on disk.</span>') : '<span class="text-success">All present on disk.</span>'; ?></p>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th>Variation</th>
                        <th>Type</th>
                        <th>Path</th>
                        <th>On disk?</th>
                        <th>Size</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allImages as $img): ?>
                        <tr class="<?php echo $img['exists_on_disk'] ? '' : 'table-danger'; ?>">
                            <td><?php echo (int) $img['id']; ?></td>
                            <td><a href="/modules/products/edit.php?id=<?php echo (int) $img['product_id']; ?>"><?php echo (int) $img['product_id']; ?></a></td>
                            <td><?php echo $img['variation_id'] !== null ? (int) $img['variation_id'] : '&mdash;'; ?></td>
                            <td><?php echo app_escape($img['image_type']); ?></td>
                            <td class="small"><code><?php echo app_escape($img['image_path']); ?></code></td>
                            <td><?php echo $img['exists_on_disk'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>'; ?></td>
                            <td><?php echo $img['filesize'] !== null ? app_escape(number_format($img['filesize'])) : '&mdash;'; ?></td>
                            <td class="small text-muted"><?php echo app_escape($img['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small">This page checks one representative column/table per migration script, not the full historical schema - it's meant to catch a whole migration never having been run, not to audit every column ever added. See <a href="/modules/sync-logs/index.php">Sync Logs</a> for runtime sync activity.</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
