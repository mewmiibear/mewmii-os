<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/system_health.php';
app_require_permission('settings.manage');

$appTitle = 'System Health';
$pdo = app_db();
$health = system_health_check($pdo);

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

<p class="text-muted small">This page checks one representative column/table per migration script, not the full historical schema - it's meant to catch a whole migration never having been run, not to audit every column ever added. See <a href="/modules/sync-logs/index.php">Sync Logs</a> for runtime sync activity.</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
