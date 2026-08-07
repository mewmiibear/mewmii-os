<?php
if (!defined('APP_START')) {
    require_once __DIR__ . '/bootstrap.php';
}
require_once __DIR__ . '/notifications.php';

$appTitle = 'Mewmii OS';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo app_escape($appTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?php
    /* V3 Phase 1: the design system moved out of this file into assets/css/ (was a 484-line
       inline <style> block here). Load order is significant - tokens.css defines the custom
       properties every later file references. filemtime() cache-busting follows the existing
       convention already used for drawer.js further down this file. */
    foreach (['tokens', 'base', 'components', 'layout'] as $mewmiiStylesheet): ?>
        <link href="/assets/css/<?php echo $mewmiiStylesheet; ?>.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/' . $mewmiiStylesheet . '.css'); ?>" rel="stylesheet">
    <?php endforeach; ?>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container-fluid px-4">
            <?php if (app_is_logged_in()): ?>
                <button type="button" class="btn btn-outline-secondary d-lg-none me-2" id="sidebar-toggle-btn" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="app-sidebar">
                    <i class="bi bi-list"></i>
                </button>
            <?php endif; ?>
            <a class="navbar-brand" href="/index.php">🌸 Mewmii OS</a>
            <?php if (app_is_logged_in()): ?>
                <div class="position-relative mx-3 flex-grow-1" style="max-width: 420px;" id="global-search-wrapper">
                    <form method="get" action="/modules/search/index.php" role="search" autocomplete="off">
                        <input type="search" id="global-search-input" name="q" class="form-control form-control-sm" placeholder="Search products, orders, customers...">
                    </form>
                    <div id="global-search-dropdown" class="card shadow-sm d-none" style="position: absolute; top: 100%; left: 0; right: 0; z-index: 1050; max-height: 420px; overflow-y: auto;"></div>
                </div>
            <?php endif; ?>
            <div class="ms-auto">
                <?php if (app_is_logged_in()): ?>
                    <span class="me-3 text-muted">Hello, <?php echo app_escape($_SESSION['user_name'] ?? 'Admin'); ?></span>
                    <a class="btn btn-outline-secondary btn-sm" href="/logout.php">Logout</a>
                <?php else: ?>
                    <a class="btn btn-outline-primary btn-sm" href="/login.php">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    <?php if (app_is_logged_in()): ?>
        <script src="/assets/js/global_search.js"></script>
        <script src="/assets/js/sidebar.js"></script>
        <script src="/assets/js/drawer.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/drawer.js'); ?>"></script>
        <?php /* V3 Phase 3.5 - shared loading/pending helpers. Loaded here, alongside the other
                 app scripts and before any page-specific JS, so window.LoadingUI already exists
                 when inventory.js / product-form.js / supplier-order-form.js are parsed. It has
                 no Bootstrap dependency of its own (the spinner is CSS), so it does not need to
                 wait for the bundle in footer.php. */ ?>
        <script src="/assets/js/loading.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/loading.js'); ?>"></script>
        <?php /* Phase 3.6b - active filter chips. Self-contained and opt-in: it only acts on
                 .filter-card[data-filter-chips="1"], so loading it app-wide is inert on every page
                 that has not opted in. No Bootstrap dependency, so like loading.js it does not
                 need to wait for footer.php's bundle. */ ?>
        <script src="/assets/js/filter-chips.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/filter-chips.js'); ?>"></script>
        <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
        <!-- Mewmii OS v2 Phase 2 - the one shared Drawer container every module's Quick View
             loads into (docs/PHASE2_IMPLEMENTATION.md). A real bootstrap.Offcanvas instance -
             see assets/js/drawer.js for why this isn't hand-rolled like the sidebar above. -->
        <div class="offcanvas offcanvas-end app-drawer" tabindex="-1" id="app-drawer" aria-labelledby="app-drawer-title">
            <div class="offcanvas-header">
                <h5 class="offcanvas-title" id="app-drawer-title"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body p-0" id="app-drawer-body"></div>
        </div>
    <?php endif; ?>
    <div class="container-fluid">
        <div class="row">
            <?php if (app_is_logged_in()): ?>
                <?php
                // Sidebar active-state: exact match for a top-level page (e.g. /index.php), or a
                // prefix match against a module's own directory for its index.php link, so the
                // section stays highlighted while on any of that module's other pages (edit/view/
                // create/etc.), not just its exact index. Pure presentation - no route was renamed.
                $currentNavPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
                $navActive = static function (string $href) use ($currentNavPath): string {
                    if ($href === $currentNavPath) {
                        return ' active';
                    }
                    if (str_ends_with($href, '/index.php')) {
                        $moduleDir = substr($href, 0, -strlen('index.php'));
                        if ($moduleDir !== '/' && str_starts_with($currentNavPath, $moduleDir)) {
                            return ' active';
                        }
                    }
                    return '';
                };

                // Header notification badge: reuses the existing notification_unread_count()
                // (includes/notifications.php) - same query the dashboard's own Notifications
                // card already runs. Read-only COUNT, never triggers alert generation/auto-resolve.
                $headerUnreadNotifications = app_has_permission('dashboard.view')
                    ? notification_unread_count(app_db())
                    : 0;
                ?>
                <aside class="col-lg-2 sidebar p-3" id="app-sidebar">
                    <div class="d-flex flex-column">
                        <a class="nav-link<?php echo $navActive('/index.php'); ?>" href="/index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                        <?php if (app_has_permission('dashboard.view')): ?>
                            <a class="nav-link nav-link-sub<?php echo $navActive('/modules/notifications/index.php'); ?>" href="/modules/notifications/index.php">
                                Notifications
                                <?php if ($headerUnreadNotifications > 0): ?>
                                    <span class="badge badge-count ms-1"><?php echo $headerUnreadNotifications; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>

                        <div class="nav-section-label">Catalog</div>
                        <a class="nav-link<?php echo $navActive('/modules/products/index.php'); ?>" href="/modules/products/index.php"><i class="bi bi-box-seam"></i> Products</a>
                        <?php if (app_has_permission('products.view')): ?>
                            <a class="nav-link nav-link-sub<?php echo $navActive('/modules/catalog/index.php'); ?>" href="/modules/catalog/index.php">Catalogue</a>
                        <?php endif; ?>

                        <?php if (app_has_permission('orders.view') || app_has_permission('customers.view')): ?>
                            <div class="nav-section-label">Sales</div>
                            <?php if (app_has_permission('orders.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/orders/index.php'); ?>" href="/modules/orders/index.php"><i class="bi bi-cart3"></i> Orders</a>
                            <?php endif; ?>
                            <?php if (app_has_permission('customers.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/customers/index.php'); ?>" href="/modules/customers/index.php"><i class="bi bi-people"></i> Customers</a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (app_has_permission('orders.view') || app_has_permission('inventory.view') || app_has_permission('products.view') || app_has_permission('supplier-orders.view')): ?>
                            <div class="nav-section-label">Reports</div>
                            <?php if (app_has_permission('orders.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/reports/sales.php'); ?>" href="/modules/reports/sales.php"><i class="bi bi-bar-chart"></i> Sales Report</a>
                            <?php endif; ?>
                            <?php if (app_has_permission('inventory.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/reports/inventory.php'); ?>" href="/modules/reports/inventory.php"><i class="bi bi-graph-up"></i> Inventory Intelligence</a>
                                <a class="nav-link<?php echo $navActive('/modules/reports/forecast.php'); ?>" href="/modules/reports/forecast.php"><i class="bi bi-signpost-split"></i> Demand Forecast</a>
                            <?php endif; ?>
                            <?php if (app_has_permission('products.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/reports/margins.php'); ?>" href="/modules/reports/margins.php"><i class="bi bi-graph-up-arrow"></i> Margin Report</a>
                                <a class="nav-link<?php echo $navActive('/modules/reports/cost_history.php'); ?>" href="/modules/reports/cost_history.php"><i class="bi bi-clock-history"></i> Cost History</a>
                            <?php endif; ?>
                            <?php if (app_has_permission('supplier-orders.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/reports/suppliers.php'); ?>" href="/modules/reports/suppliers.php"><i class="bi bi-truck"></i> Supplier Performance</a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (app_has_permission('suppliers.view') || app_has_permission('supplier-orders.view') || app_has_permission('inventory.view')): ?>
                            <div class="nav-section-label">Operations</div>
                            <?php if (app_has_permission('suppliers.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/suppliers/index.php'); ?>" href="/modules/suppliers/index.php"><i class="bi bi-truck"></i> Suppliers</a>
                            <?php endif; ?>
                            <?php if (app_has_permission('supplier-orders.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/supplier-orders/index.php'); ?>" href="/modules/supplier-orders/index.php"><i class="bi bi-clipboard-check"></i> Supplier Orders</a>
                            <?php endif; ?>
                            <?php if (app_has_permission('inventory.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/inventory/index.php'); ?>" href="/modules/inventory/index.php"><i class="bi bi-boxes"></i> Inventory</a>
                                <a class="nav-link nav-link-sub<?php echo $navActive('/modules/inventory/reservation-center.php'); ?>" href="/modules/inventory/reservation-center.php">Reservation Center</a>
                                <a class="nav-link nav-link-sub<?php echo $navActive('/modules/inventory/allocation-center.php'); ?>" href="/modules/inventory/allocation-center.php">Allocation Center</a>
                                <a class="nav-link<?php echo $navActive('/modules/purchasing/index.php'); ?>" href="/modules/purchasing/index.php"><i class="bi bi-cart-check"></i> Purchasing</a>
                                <a class="nav-link nav-link-sub<?php echo $navActive('/modules/purchasing/control-center.php'); ?>" href="/modules/purchasing/control-center.php">Control Center</a>
                            <?php endif; ?>
                            <?php if (app_has_permission('supplier-orders.manage')): ?>
                                <a class="nav-link nav-link-sub<?php echo $navActive('/modules/purchase-planning/generate.php'); ?>" href="/modules/purchase-planning/generate.php">Purchase Planning</a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (app_has_permission('customer-storage.view') || app_has_permission('ship-my-box.view') || app_has_permission('shipments.view')): ?>
                            <div class="nav-section-label">Fulfilment</div>
                            <?php if (app_has_permission('customer-storage.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/customer-storage/index.php'); ?>" href="/modules/customer-storage/index.php"><i class="bi bi-archive"></i> Customer Storage</a>
                            <?php endif; ?>
                            <?php if (app_has_permission('ship-my-box.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/ship-my-box/index.php'); ?>" href="/modules/ship-my-box/index.php"><i class="bi bi-box2"></i> Ship My Box</a>
                            <?php endif; ?>
                            <?php if (app_has_permission('shipments.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/shipments/index.php'); ?>" href="/modules/shipments/index.php"><i class="bi bi-send"></i> Shipments</a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (app_has_permission('finance.view')): ?>
                            <div class="nav-section-label">Finance</div>
                            <a class="nav-link<?php echo $navActive('/modules/finance/index.php'); ?>" href="/modules/finance/index.php"><i class="bi bi-receipt"></i> Expenses</a>
                            <a class="nav-link<?php echo $navActive('/modules/finance/manual_income.php'); ?>" href="/modules/finance/manual_income.php"><i class="bi bi-cash-coin"></i> Manual Income</a>
                            <a class="nav-link<?php echo $navActive('/modules/finance/bank_accounts.php'); ?>" href="/modules/finance/bank_accounts.php"><i class="bi bi-bank"></i> Bank Accounts</a>
                            <a class="nav-link<?php echo $navActive('/modules/finance/assets.php'); ?>" href="/modules/finance/assets.php"><i class="bi bi-box-seam"></i> Assets</a>
                            <?php if (app_has_permission('finance.manage')): ?>
                                <a class="nav-link nav-link-sub<?php echo $navActive('/modules/finance/categories.php'); ?>" href="/modules/finance/categories.php">Categories</a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (app_has_permission('settings.manage')): ?>
                            <div class="nav-section-label">Integrations</div>
                            <a class="nav-link<?php echo $navActive('/modules/integrations/woocommerce.php'); ?>" href="/modules/integrations/woocommerce.php"><i class="bi bi-arrow-repeat"></i> WooCommerce Sync</a>
                            <a class="nav-link nav-link-sub<?php echo $navActive('/modules/webhooks/events.php'); ?>" href="/modules/webhooks/events.php">Webhook Events</a>
                            <a class="nav-link<?php echo $navActive('/modules/sync-logs/index.php'); ?>" href="/modules/sync-logs/index.php"><i class="bi bi-list-check"></i> Sync Logs</a>

                            <div class="nav-section-label">System</div>
                            <a class="nav-link<?php echo $navActive('/modules/operations/job_queue.php'); ?>" href="/modules/operations/job_queue.php"><i class="bi bi-stack"></i> Job Queue</a>
                            <a class="nav-link<?php echo $navActive('/modules/settings/system_health.php'); ?>" href="/modules/settings/system_health.php"><i class="bi bi-heart-pulse"></i> System Health</a>
                            <a class="nav-link<?php echo $navActive('/modules/settings/currency_rates.php'); ?>" href="/modules/settings/currency_rates.php"><i class="bi bi-cash-stack"></i> Currency Rates</a>
                            <a class="nav-link<?php echo $navActive('/modules/settings/inventory_reconciliation.php'); ?>" href="/modules/settings/inventory_reconciliation.php"><i class="bi bi-clipboard-data"></i> Inventory Reconciliation</a>
                            <a class="nav-link<?php echo $navActive('/modules/settings/maintenance.php'); ?>" href="/modules/settings/maintenance.php"><i class="bi bi-gear"></i> Settings</a>
                            <a class="nav-link<?php echo $navActive('/modules/settings/reset_test_data.php'); ?>" href="/modules/settings/reset_test_data.php"><i class="bi bi-exclamation-triangle"></i> Reset Test Data</a>
                        <?php endif; ?>
                    </div>
                </aside>
                <main class="col-lg-10 p-4">
                <?php else: ?>
                    <main class="col-12 p-4">
                    <?php endif; ?>