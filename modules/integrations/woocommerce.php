<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/wc_client.php';
require_once __DIR__ . '/../../includes/wc_order_import.php';
require_once __DIR__ . '/../../includes/wc_product_import.php';
app_require_permission('settings.manage');

/**
 * WooCommerce sync diagnostics + manual triggers (Phase 1: polling only, no webhook).
 * Read-only status page plus three POST actions (Test Connection, Import Orders Now, Import
 * Products Now) - all the actual import logic lives in includes/wc_order_import.php and
 * includes/wc_product_import.php, this file is presentation only.
 */

$appTitle = 'WooCommerce Sync';
$pdo = app_db();
$error = '';

/**
 * wc_order_import_run() stores its cursor/last-run stats as GMT (see
 * WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT's docblock) - display only, converts to whatever
 * timezone this app is configured for (see includes/bootstrap.php's date_default_timezone_set())
 * so staff never have to mentally convert from UTC.
 */
function wc_order_import_format_gmt_setting(?string $gmtTimestamp): ?string
{
    if ($gmtTimestamp === null || $gmtTimestamp === '') {
        return null;
    }

    try {
        $dt = new DateTime($gmtTimestamp, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $dt->format('Y-m-d H:i:s');
    } catch (Exception) {
        return $gmtTimestamp;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !empty($_POST['test_connection'])) {
        try {
            wc_client_get('orders', ['per_page' => 1]);
            app_redirect('/modules/integrations/woocommerce.php?tested=1&ok=1');
        } catch (Throwable $exception) {
            app_redirect('/modules/integrations/woocommerce.php?tested=1&ok=0&message=' . urlencode($exception->getMessage()));
        }
    } elseif ($error === '' && !empty($_POST['import_orders'])) {
        try {
            $summary = wc_order_import_run($pdo);
            app_redirect('/modules/integrations/woocommerce.php?imported=1&created=' . $summary['created'] . '&updated=' . $summary['updated'] . '&skipped=' . $summary['skipped'] . '&failed=' . $summary['failed']);
        } catch (Throwable $exception) {
            app_redirect('/modules/integrations/woocommerce.php?imported=1&created=0&updated=0&skipped=0&failed=0&message=' . urlencode($exception->getMessage()));
        }
    } elseif ($error === '' && !empty($_POST['import_products'])) {
        try {
            $summary = wc_product_import_run($pdo);
            app_redirect('/modules/integrations/woocommerce.php?product_imported=1&created=' . $summary['products_created'] . '&updated=' . $summary['products_updated'] . '&skipped=' . $summary['products_skipped'] . '&failed=' . $summary['products_failed']);
        } catch (Throwable $exception) {
            app_redirect('/modules/integrations/woocommerce.php?product_imported=1&created=0&updated=0&skipped=0&failed=0&message=' . urlencode($exception->getMessage()));
        }
    } elseif ($error === '' && isset($_POST['set_auto_sync'])) {
        wc_client_set_setting($pdo, WC_CLIENT_AUTO_SYNC_SETTING_KEY, !empty($_POST['auto_sync_enabled']) ? '1' : '0');
        app_redirect('/modules/integrations/woocommerce.php?auto_sync_saved=1');
    }
}

$isConfigured = wc_client_is_configured();

$statsStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        MAX(created_at) AS last_sync_at
    FROM sync_logs
    WHERE sync_type = ?
");
$statsStmt->execute([WC_ORDER_IMPORT_SYNC_TYPE]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$recentLogsStmt = $pdo->prepare('
    SELECT id, reference_id, status, error_message, created_at
    FROM sync_logs
    WHERE sync_type = ?
    ORDER BY id DESC
    LIMIT 10
');
$recentLogsStmt->execute([WC_ORDER_IMPORT_SYNC_TYPE]);
$recentLogs = $recentLogsStmt->fetchAll(PDO::FETCH_ASSOC);

// Delta-sync visibility (Sync Automation Phase 1) - sourced from the `settings` table cursor/
// summary wc_order_import_run() now maintains, not from sync_logs. sync_logs only gains a row
// per order actually touched, so a run that found nothing new would never move "Last Sync"
// under the old sync_logs-only reading - these settings-backed values instead reflect every
// run attempt, regardless of whether it found any orders to process.
$lastSyncCursor = wc_order_import_get_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_SYNCED_AT);
$lastRunSummaryRaw = wc_order_import_get_setting($pdo, WC_ORDER_IMPORT_SETTING_LAST_RUN_SUMMARY);
$lastRunSummary = $lastRunSummaryRaw !== null ? json_decode($lastRunSummaryRaw, true) : null;
if (!is_array($lastRunSummary)) {
    $lastRunSummary = null;
}

// Time-based health (Sync Automation Phase 4A) - see wc_order_import_sync_health()'s own
// docblock for why this is measured against the cursor, not sync_logs' failure counts.
$syncHealth = wc_order_import_sync_health($lastSyncCursor);

// --- Product import stats - same shape as the order stats above, just scoped to
// woocommerce_product_import (see includes/wc_product_import.php). ---
$productStatsStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        MAX(created_at) AS last_sync_at
    FROM sync_logs
    WHERE sync_type = ?
");
$productStatsStmt->execute([WC_PRODUCT_IMPORT_SYNC_TYPE]);
$productStats = $productStatsStmt->fetch(PDO::FETCH_ASSOC);

$recentProductLogsStmt = $pdo->prepare('
    SELECT id, reference_id, status, error_message, created_at
    FROM sync_logs
    WHERE sync_type = ?
    ORDER BY id DESC
    LIMIT 10
');
$recentProductLogsStmt->execute([WC_PRODUCT_IMPORT_SYNC_TYPE]);
$recentProductLogs = $recentProductLogsStmt->fetchAll(PDO::FETCH_ASSOC);

$lastProductSyncCursor = wc_product_import_get_setting($pdo, WC_PRODUCT_IMPORT_SETTING_LAST_SYNCED_AT);
$lastProductRunSummaryRaw = wc_product_import_get_setting($pdo, WC_PRODUCT_IMPORT_SETTING_LAST_RUN_SUMMARY);
$lastProductRunSummary = $lastProductRunSummaryRaw !== null ? json_decode($lastProductRunSummaryRaw, true) : null;
if (!is_array($lastProductRunSummary)) {
    $lastProductRunSummary = null;
}

// --- Push-sync ("Sync to WooCommerce" / Auto Sync) stats - scoped to woocommerce_product_sync
// (see includes/wc_client.php), the opposite direction from the Products import section above. ---
$pushStatsStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        MAX(created_at) AS last_sync_at
    FROM sync_logs
    WHERE sync_type = 'woocommerce_product_sync'
");
$pushStatsStmt->execute();
$pushStats = $pushStatsStmt->fetch(PDO::FETCH_ASSOC);

$recentPushLogsStmt = $pdo->prepare("
    SELECT id, reference_id, status, error_message, created_at
    FROM sync_logs
    WHERE sync_type = 'woocommerce_product_sync'
    ORDER BY id DESC
    LIMIT 10
");
$recentPushLogsStmt->execute();
$recentPushLogs = $recentPushLogsStmt->fetchAll(PDO::FETCH_ASSOC);

$autoSyncEnabled = wc_client_auto_sync_enabled($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">WooCommerce Sync</h2>
        <p class="text-muted mb-0">Import orders and products between mewmiibear.com and Mewmii OS. Manual, admin-triggered - no webhook yet.</p>
    </div>
    <div class="action-bar">
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="test_connection" value="1">
            <button type="submit" class="btn btn-outline-secondary" <?php echo $isConfigured ? '' : 'disabled'; ?>>Test Connection</button>
        </form>
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="import_orders" value="1">
            <button type="submit" class="btn btn-primary" <?php echo $isConfigured ? '' : 'disabled'; ?>>Import Orders Now</button>
        </form>
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="import_products" value="1">
            <button type="submit" class="btn btn-primary" <?php echo $isConfigured ? '' : 'disabled'; ?>>Import Products Now</button>
        </form>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if (isset($_GET['tested'])): ?>
    <?php if (($_GET['ok'] ?? '') === '1'): ?>
        <div class="alert alert-success">Connection test succeeded - WooCommerce API responded.</div>
    <?php else: ?>
        <div class="alert alert-danger">Connection test failed<?php echo isset($_GET['message']) ? ': ' . app_escape($_GET['message']) : '.'; ?></div>
    <?php endif; ?>
<?php endif; ?>

<?php if (isset($_GET['imported'])): ?>
    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-danger">Order import failed: <?php echo app_escape($_GET['message']); ?></div>
    <?php else: ?>
        <div class="alert alert-success">
            Order import finished - <?php echo (int) $_GET['created']; ?> created, <?php echo (int) $_GET['updated']; ?> updated,
            <?php echo (int) $_GET['skipped']; ?> skipped, <?php echo (int) $_GET['failed']; ?> failed.
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (isset($_GET['auto_sync_saved'])): ?>
    <div class="alert alert-success">Auto Sync setting saved.</div>
<?php endif; ?>

<?php if (isset($_GET['product_imported'])): ?>
    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-danger">Product import failed: <?php echo app_escape($_GET['message']); ?></div>
    <?php else: ?>
        <div class="alert alert-success">
            Product import finished - <?php echo (int) $_GET['created']; ?> created, <?php echo (int) $_GET['updated']; ?> updated,
            <?php echo (int) $_GET['skipped']; ?> skipped, <?php echo (int) $_GET['failed']; ?> failed.
        </div>
    <?php endif; ?>
<?php endif; ?>

<h5 class="mb-3">Orders</h5>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Connection</div>
            <div class="stat-value <?php echo $isConfigured ? '' : 'stat-value-alert'; ?>" style="font-size: 1.5rem;">
                <?php echo $isConfigured ? 'Configured' : 'Not Configured'; ?>
            </div>
            <div class="stat-helper mb-0"><?php echo $isConfigured ? 'WooCommerce API credentials are set.' : 'Set WC_URL / WC_CONSUMER_KEY / WC_CONSUMER_SECRET.'; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Last Sync</div>
            <div class="stat-value" style="font-size: 1.5rem;"><?php echo app_escape($stats['last_sync_at'] ?? 'Never'); ?></div>
            <div class="stat-helper mb-0">Most recent order import activity.</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Imported Orders</div>
            <div class="stat-value"><?php echo (int) ($stats['success_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Successful create/update events.</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Failed Sync</div>
            <div class="stat-value <?php echo (int) ($stats['failed_count'] ?? 0) > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) ($stats['failed_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Orders that failed to import.</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Last Sync Run</div>
            <div class="stat-value" style="font-size: 1.5rem;">
                <?php echo app_escape(wc_order_import_format_gmt_setting($lastRunSummary['ran_at'] ?? null) ?? 'Never'); ?>
            </div>
            <div class="stat-helper mb-0">When the importer last attempted to run (manual or automated).</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Sync Cursor</div>
            <div class="stat-value <?php echo $syncHealth['level'] !== 'healthy' ? 'stat-value-alert' : ''; ?>" style="font-size: 1.5rem;">
                <?php echo app_escape(wc_order_import_format_gmt_setting($lastSyncCursor) ?? 'Not synced yet'); ?>
            </div>
            <div class="stat-helper mb-0">
                <?php echo app_escape($syncHealth['message']); ?>
                <?php if ($syncHealth['level'] === 'warning'): ?>
                    <span class="badge bg-warning text-dark ms-1">Warning</span>
                <?php elseif ($syncHealth['level'] === 'critical'): ?>
                    <span class="badge bg-danger ms-1">Critical</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Orders Processed (Last Run)</div>
            <div class="stat-value">
                <?php echo $lastRunSummary !== null ? (int) $lastRunSummary['created'] + (int) $lastRunSummary['updated'] : 0; ?>
            </div>
            <div class="stat-helper mb-0">
                <?php if ($lastRunSummary !== null): ?>
                    <?php echo (int) $lastRunSummary['created']; ?> created, <?php echo (int) $lastRunSummary['updated']; ?> updated,
                    <?php echo (int) $lastRunSummary['skipped']; ?> skipped, <?php echo (int) $lastRunSummary['failed']; ?> failed.
                <?php else: ?>
                    No sync run yet.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card p-4">
    <h5 class="mb-3">Recent Sync Activity</h5>
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Order</th>
                <th>Status</th>
                <th>Error</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td>
                        <?php if ($log['reference_id'] !== null): ?>
                            <a href="/modules/orders/view.php?id=<?php echo (int) $log['reference_id']; ?>">#<?php echo (int) $log['reference_id']; ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($log['status'] === 'success'): ?>
                            <span class="badge bg-success">success</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?php echo app_escape($log['status']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($log['error_message'] ?? '-'); ?></td>
                    <td><?php echo app_escape($log['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($recentLogs === []): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-state-title">No Import Activity Yet</div>
                            <p class="empty-state-text">Click "Import Orders Now" to pull recent orders from WooCommerce.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<h5 class="mb-3 mt-5">Products: Import from WooCommerce</h5>
<p class="text-muted small">
    v1 imports products, variations, images, prices, and stock from WooCommerce. Brand,
    category, and collection assignments are not imported yet - a product's existing
    (or blank) assignment in Mewmii OS is left untouched either way.
</p>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Last Product Sync Run</div>
            <div class="stat-value" style="font-size: 1.5rem;">
                <?php echo app_escape(wc_order_import_format_gmt_setting($lastProductRunSummary['ran_at'] ?? null) ?? 'Never'); ?>
            </div>
            <div class="stat-helper mb-0">When the product importer last attempted to run (manual or automated).</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Product Sync Cursor</div>
            <div class="stat-value" style="font-size: 1.5rem;">
                <?php echo app_escape(wc_order_import_format_gmt_setting($lastProductSyncCursor) ?? 'Not synced yet'); ?>
            </div>
            <div class="stat-helper mb-0">Only products modified after this point are fetched on the next import.</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Products Processed (Last Run)</div>
            <div class="stat-value">
                <?php echo $lastProductRunSummary !== null ? (int) $lastProductRunSummary['imported'] : 0; ?>
            </div>
            <div class="stat-helper mb-0">
                <?php if ($lastProductRunSummary !== null): ?>
                    <?php echo (int) $lastProductRunSummary['created']; ?> created, <?php echo (int) $lastProductRunSummary['updated']; ?> updated,
                    <?php echo (int) $lastProductRunSummary['skipped']; ?> skipped, <?php echo (int) $lastProductRunSummary['failed']; ?> failed.
                <?php else: ?>
                    No sync run yet.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Imported Products</div>
            <div class="stat-value"><?php echo (int) ($productStats['success_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Successful create/update events.</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Failed Product Sync</div>
            <div class="stat-value <?php echo (int) ($productStats['failed_count'] ?? 0) > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) ($productStats['failed_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Products that failed to import - see error detail below.</div>
        </div>
    </div>
</div>

<div class="card p-4">
    <h5 class="mb-3">Recent Product Sync Activity</h5>
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Product</th>
                <th>Status</th>
                <th>Error</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentProductLogs as $log): ?>
                <tr>
                    <td>
                        <?php if ($log['reference_id'] !== null): ?>
                            <a href="/modules/products/edit.php?id=<?php echo (int) $log['reference_id']; ?>">#<?php echo (int) $log['reference_id']; ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($log['status'] === 'success'): ?>
                            <span class="badge bg-success">success</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?php echo app_escape($log['status']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($log['error_message'] ?? '-'); ?></td>
                    <td><?php echo app_escape($log['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($recentProductLogs === []): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-state-title">No Import Activity Yet</div>
                            <p class="empty-state-text">Click "Import Products Now" to pull products from WooCommerce.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<h5 class="mb-3 mt-5">Products: Push to WooCommerce</h5>
<p class="text-muted small">
    Mewmii OS is the source of truth for product data. Matches by WooCommerce product id
    first (falling back to SKU only when no id is stored yet), so editing a SKU here never
    creates a duplicate on the WooCommerce side. A product whose WooCommerce-relevant fields
    haven't changed since its last successful push is skipped automatically, without an API
    call. The <code>woocommerce_product_id</code> WooCommerce assigns is never changed by
    this app once set.
</p>

<div class="card p-4 mb-4">
    <h6 class="mb-2">Auto Sync</h6>
    <p class="text-muted small">When enabled, saving a product in Mewmii OS (create or edit) automatically pushes just that one product to WooCommerce. When disabled, products only sync when you click "Sync to WooCommerce" on the Products page.</p>
    <form method="post" class="d-flex align-items-center gap-2">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <input type="hidden" name="set_auto_sync" value="1">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="auto-sync-toggle" name="auto_sync_enabled" value="1" <?php echo $autoSyncEnabled ? 'checked' : ''; ?> onchange="this.form.requestSubmit()">
            <label class="form-check-label" for="auto-sync-toggle"><?php echo $autoSyncEnabled ? 'Enabled' : 'Disabled'; ?></label>
        </div>
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Save</button></noscript>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Last Push</div>
            <div class="stat-value" style="font-size: 1.5rem;"><?php echo app_escape($pushStats['last_sync_at'] ?? 'Never'); ?></div>
            <div class="stat-helper mb-0">Most recent push activity (manual button or Auto Sync).</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Pushed Products</div>
            <div class="stat-value"><?php echo (int) ($pushStats['success_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Successful create/update events (skipped-unchanged products are not counted here).</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card p-4 h-100 d-flex flex-column">
            <div class="stat-label">Failed Push</div>
            <div class="stat-value <?php echo (int) ($pushStats['failed_count'] ?? 0) > 0 ? 'stat-value-alert' : ''; ?>"><?php echo (int) ($pushStats['failed_count'] ?? 0); ?></div>
            <div class="stat-helper mb-0">Products that failed to push - see error detail below.</div>
        </div>
    </div>
</div>

<div class="card p-4">
    <h6 class="mb-3">Recent Push Activity</h6>
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Product</th>
                <th>Status</th>
                <th>Error</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentPushLogs as $log): ?>
                <tr>
                    <td>
                        <?php if ($log['reference_id'] !== null): ?>
                            <a href="/modules/products/edit.php?id=<?php echo (int) $log['reference_id']; ?>">#<?php echo (int) $log['reference_id']; ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($log['status'] === 'success'): ?>
                            <span class="badge bg-success">success</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?php echo app_escape($log['status']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo app_escape($log['error_message'] ?? '-'); ?></td>
                    <td><?php echo app_escape($log['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($recentPushLogs === []): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-state-title">No Push Activity Yet</div>
                            <p class="empty-state-text">Use "Sync to WooCommerce" on the Products page, or enable Auto Sync above.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
