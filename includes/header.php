<?php
if (!defined('APP_START')) {
    require_once __DIR__ . '/bootstrap.php';
}

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
    <style>
        /* --- Mewmii OS design tokens ------------------------------------------------------
           Single source of truth for the brand/neutral palette. Everything below references
           these instead of hardcoded hex values, so a future palette change only touches this
           block. UI/UX Phase 5A: shifted toward a flatter, neutral "admin SaaS" surface -
           brand pink is now reserved for primary actions/active states rather than used as an
           ambient tint on every card/shadow/background. Class names are all unchanged from
           before this phase, so every other page's markup keeps working without edits. */
        :root {
            --mewmii-pink: #FF94C4;
            --mewmii-pink-hover: #F97DB7;
            --mewmii-pink-tint: #FFF1F7;
            --mewmii-blue: #3472EF;
            --mewmii-blue-hover: #2A5FCB;
            --sky-blue: #85D2FF;
            --berry-rose: #B2668C;
            --base-white: #FFFFFF;
            --text-main: #202223;
            --text-secondary: #6B6F76;
            --neutral-bg: #F6F6F7;
            --neutral-border: #E3E3E3;
        }

        body {
            background: var(--neutral-bg);
            color: var(--text-main);
        }

        .navbar-brand {
            color: var(--mewmii-pink) !important;
            font-weight: 700;
        }

        /* --- Cards: flat, bordered "admin SaaS" surface (was a heavy pink drop-shadow) ---- */
        .card {
            border: 1px solid var(--neutral-border);
            border-radius: 12px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        /* --- Buttons: Pink = primary action, Blue = secondary action, per design tokens --- */
        .btn {
            border-radius: 8px;
        }

        .btn-primary {
            background: var(--mewmii-pink);
            border-color: var(--mewmii-pink);
            color: var(--base-white);
        }

        .btn-primary:hover,
        .btn-primary:focus {
            background: var(--mewmii-pink-hover);
            border-color: var(--mewmii-pink-hover);
            color: var(--base-white);
        }

        .btn-outline-primary {
            color: var(--mewmii-blue);
            border-color: var(--mewmii-blue);
        }

        .btn-outline-primary:hover,
        .btn-outline-primary:focus {
            background: var(--mewmii-blue);
            border-color: var(--mewmii-blue);
            color: var(--base-white);
        }

        /* --- Tables: calmer borders, more breathing room, muted header text --------------- */
        .table > :not(caption) > * > * {
            padding: 0.85rem 1rem;
            border-bottom-color: #F1E7ED;
        }

        .table thead th {
            color: var(--text-secondary);
            font-size: 0.82rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            border-bottom-width: 2px;
        }

        /* --- Alerts: soft pastel tints instead of default saturated Bootstrap colours ----- */
        .alert {
            border: 0;
            border-radius: 14px;
        }

        .alert-success {
            background: #E6F6EC;
            color: #1F7A43;
        }

        .alert-danger {
            background: #FDEAF1;
            color: #A32458;
        }

        .alert-warning {
            background: #FFF6E5;
            color: #8A6116;
        }

        .alert-info {
            background: #EAF2FF;
            color: #1D57B0;
        }

        /* --- Page header (Title / Description / Primary actions) - the top of every module
           list page. Markup stays the existing `d-flex justify-content-between` row already
           used everywhere; these classes just standardise the spacing/typography instead of
           relying on ad-hoc mb-* utilities repeated per page. */
        .page-header {
            margin-bottom: 2rem;
            flex-wrap: wrap;
            row-gap: 0.75rem;
        }

        .page-header h2 {
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .page-description {
            color: var(--text-secondary);
            margin-bottom: 0;
        }

        /* --- Action bar - primary/secondary/import/export buttons grouped near the title,
           same gap and alignment everywhere they appear. */
        .action-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.6rem;
        }

        /* --- Filter card - a visually quieter card than content cards (no heavy shadow),
           so filters read as a secondary, supporting control rather than competing with the
           page's actual content below it. Behaviour of the form inside is unchanged. */
        .filter-card {
            background: #FBFAFC;
            border: 1px solid #F1E7ED;
            box-shadow: none;
        }

        .filter-card .form-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 0.3rem;
        }

        /* --- Stat card: small label / large value / small helper text, the one pattern every
           KPI tile (dashboard, reports, module summaries) should use. */
        .stat-card .stat-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 0.4rem;
        }

        .stat-card .stat-value {
            font-size: 1.9rem;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.15;
        }

        .stat-card .stat-value.stat-value-alert {
            color: #D9486E;
        }

        .stat-card .stat-helper {
            font-size: 0.82rem;
            color: var(--text-secondary);
            margin-top: 0.3rem;
        }

        /* --- Clickable stat-card (UI/UX Phase 5A) - whole card is the link target (Dashboard
           Top Summary strip), no separate button needed - "reduce clicks" per the design
           brief. Same .card/.stat-card look, just neutralises the default <a> underline/color
           and adds a subtle lift on hover so it still reads as interactive. */
        a.stat-card-link {
            display: block;
            text-decoration: none;
            color: inherit;
            transition: box-shadow 0.15s ease, transform 0.15s ease;
        }

        a.stat-card-link:hover {
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            transform: translateY(-1px);
        }

        /* --- Receipt preview thumbnail (order view Receipt row) - max-width/max-height cap the
           box, width/height:auto let the element follow the receipt photo's real aspect ratio
           instead of forcing a fixed square, object-fit:contain as a defensive backstop. Wrapped
           in a link to the original receipt_url so clicking it (thumbnail or "View Receipt"
           text) opens the full-size original in a new tab. */
        .receipt-preview-thumb {
            display: block;
            max-width: 160px;
            max-height: 160px;
            width: auto;
            height: auto;
            object-fit: contain;
            background: var(--bs-tertiary-bg, #f5f5f5);
            border: 1px solid var(--border-color, #dee2e6);
            border-radius: 8px;
            cursor: zoom-in;
        }

        /* --- Empty state card - replaces plain "No X yet." text rows with a calmer, centred
           message + optional single call-to-action, used inside an existing table's empty
           <tr>/<td> or in place of a table entirely. */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .empty-state .empty-state-title {
            font-weight: 700;
            color: var(--text-main);
            font-size: 1.05rem;
            margin-bottom: 0.35rem;
        }

        .empty-state .empty-state-text {
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .empty-state .empty-state-text:last-child {
            margin-bottom: 0;
        }

        /* --- Table refinement: comfortable vertical alignment (belt-and-suspenders on top of
           the align-middle utility class already used on every table), breathing room around
           badges and grouped action buttons/forms that aren't already using a gap utility. */
        .table td {
            vertical-align: middle;
        }

        .table .badge + .badge {
            margin-left: 0.3rem;
        }

        .table td .btn + .btn,
        .table td .btn + form,
        .table td form + .btn,
        .table td form + form {
            margin-left: 0.35rem;
        }

        /* --- Form refinement: consistent label weight/colour and section headings, softer
           input borders with a brand-coloured focus ring - presentation only, no field, name,
           or validation behaviour changes anywhere this applies. */
        .card .form-label {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 0.35rem;
        }

        .card h5 {
            color: var(--text-main);
            font-weight: 700;
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            border-color: #E8DCE3;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--mewmii-blue);
            box-shadow: 0 0 0 0.2rem rgba(52, 114, 239, 0.15);
        }

        .form-text {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .sidebar {
            background: var(--base-white);
            border-right: 1px solid var(--neutral-border);
            min-height: 100vh;
        }

        /* --- Sidebar nav links (UI/UX Phase 5A) - flat text + icon rows instead of stacked
           Bootstrap buttons, grouped under small uppercase section labels for scan-ability.
           $navActive()'s logic in the PHP below is unchanged - only which CSS class receives
           the resulting " active" string changed (was .btn-light.active, now .nav-link.active). */
        .sidebar .nav-section-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 1.1rem 0 0.35rem 0.75rem;
        }

        .sidebar .nav-section-label:first-child {
            margin-top: 0.25rem;
        }

        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            color: var(--text-main);
            font-size: 0.92rem;
            border-left: 3px solid transparent;
        }

        .sidebar .nav-link:hover {
            background: var(--neutral-bg);
            color: var(--text-main);
        }

        .sidebar .nav-link.active {
            background: var(--mewmii-pink-tint);
            color: var(--mewmii-pink-hover);
            font-weight: 600;
            border-left-color: var(--mewmii-pink);
        }

        .sidebar .nav-link i {
            font-size: 0.95rem;
            width: 1.1rem;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar .nav-link.nav-link-sub {
            padding-left: 2.1rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* --- Mobile sidebar drawer (Responsive Improvement pass) --------------------------
           Desktop (>=992px) is completely untouched - .sidebar keeps its normal static
           column behaviour (col-lg-2) exactly as before this block exists. Below 992px, the
           same <aside class="sidebar"> element is pulled out of the row's flow and turned
           into a fixed off-canvas drawer instead, toggled by #sidebar-toggle-btn via
           assets/js/sidebar.js. Nothing about the sidebar's own links/markup/permissions
           logic changed - only how it's positioned/shown on a narrow viewport. */
        @media (max-width: 991.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 280px;
                max-width: 82vw;
                height: 100vh;
                z-index: 1045;
                transform: translateX(-100%);
                transition: transform 0.25s ease;
                box-shadow: 2px 0 16px rgba(0, 0, 0, 0.15);
                overflow-y: auto;
            }

            .sidebar.is-open {
                transform: translateX(0);
            }
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(17, 17, 20, 0.45);
            z-index: 1040;
        }

        .sidebar-backdrop.is-open {
            display: block;
        }

        /* --- Global responsive tweaks (Responsive Improvement pass) - small-viewport-only
           spacing/width adjustments layered on top of existing desktop rules; nothing here
           changes anything at >=992px, so desktop UI is unaffected. */
        @media (max-width: 575.98px) {
            .navbar .container-fluid {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            main.p-4 {
                padding: 1rem !important;
            }

            .card.p-4 {
                padding: 1.1rem !important;
            }

            .page-header {
                flex-direction: column !important;
                align-items: stretch !important;
                margin-bottom: 1.25rem;
            }

            .page-header .action-bar {
                justify-content: flex-start;
            }
        }

        /* --- Needs Attention list rows (dashboard) - a coloured left border communicates
           urgency without a wall of bright badge colours. */
        .attention-item {
            border-left: 4px solid var(--mewmii-blue);
            border-radius: 10px;
            background: #FAFAFB;
        }

        .attention-item.tone-danger {
            border-left-color: #D9486E;
        }

        .attention-item.tone-warning {
            border-left-color: var(--berry-rose);
        }

        /* Reusable "stacked card" responsive table: below 768px, each row becomes its own
           block and every cell stacks with its column header as a label (via data-label on
           the <td>) instead of scrolling horizontally. Opt in per-table with this class. */
        @media (max-width: 767.98px) {
            .responsive-stack-table thead {
                display: none;
            }

            .responsive-stack-table tr {
                display: block;
                margin-bottom: 1rem;
                border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            }

            .responsive-stack-table td {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                text-align: right;
                border: none !important;
                padding-left: 0 !important;
            }

            .responsive-stack-table td[data-label]:not([data-label=""])::before {
                content: attr(data-label);
                font-weight: 600;
                text-align: left;
                color: var(--text-main);
            }

            .responsive-stack-table td:not([data-label]),
            .responsive-stack-table td[data-label=""] {
                justify-content: flex-end;
            }
        }
    </style>
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
        <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
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
                ?>
                <aside class="col-lg-2 sidebar p-3" id="app-sidebar">
                    <div class="d-flex flex-column">
                        <a class="nav-link<?php echo $navActive('/index.php'); ?>" href="/index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>

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
                            <?php endif; ?>
                            <?php if (app_has_permission('products.view')): ?>
                                <a class="nav-link<?php echo $navActive('/modules/reports/margins.php'); ?>" href="/modules/reports/margins.php"><i class="bi bi-graph-up-arrow"></i> Margin Report</a>
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
                                <a class="nav-link<?php echo $navActive('/modules/purchasing/index.php'); ?>" href="/modules/purchasing/index.php"><i class="bi bi-cart-check"></i> Purchasing</a>
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

                        <?php if (app_has_permission('settings.manage')): ?>
                            <div class="nav-section-label">System</div>
                            <a class="nav-link<?php echo $navActive('/modules/integrations/woocommerce.php'); ?>" href="/modules/integrations/woocommerce.php"><i class="bi bi-arrow-repeat"></i> WooCommerce Sync</a>
                            <a class="nav-link nav-link-sub<?php echo $navActive('/modules/webhooks/events.php'); ?>" href="/modules/webhooks/events.php">Webhook Events</a>
                            <a class="nav-link<?php echo $navActive('/modules/sync-logs/index.php'); ?>" href="/modules/sync-logs/index.php"><i class="bi bi-list-check"></i> Sync Logs</a>
                            <a class="nav-link<?php echo $navActive('/modules/settings/system_health.php'); ?>" href="/modules/settings/system_health.php"><i class="bi bi-heart-pulse"></i> System Health</a>
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