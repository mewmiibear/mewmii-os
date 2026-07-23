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
    <style>
        /* --- Mewmii OS design tokens ------------------------------------------------------
           Single source of truth for the brand palette. Everything below references these
           instead of hardcoded hex values, so a future palette change only touches this block. */
        :root {
            --mewmii-pink: #FF94C4;
            --mewmii-pink-hover: #F97DB7;
            --mewmii-pink-tint: #FFF1F7;
            --mewmii-blue: #3472EF;
            --mewmii-blue-hover: #2A5FCB;
            --sky-blue: #85D2FF;
            --berry-rose: #B2668C;
            --base-white: #FFFFFF;
            --text-main: #353535;
            --text-secondary: #66524E;
        }

        body {
            background: #FAF9FB;
            color: var(--text-main);
        }

        .navbar-brand {
            color: var(--mewmii-pink) !important;
            font-weight: 700;
        }

        /* --- Cards: rounded, soft-shadow "premium stationery" surface, used everywhere ---- */
        .card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(255, 148, 196, 0.14);
        }

        /* --- Buttons: Pink = primary action, Blue = secondary action, per design tokens --- */
        .btn {
            border-radius: 10px;
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
            background: linear-gradient(180deg, var(--base-white) 0%, var(--mewmii-pink-tint) 100%);
            min-height: 100vh;
        }

        /* Current-page highlight in the sidebar - set via an `active` class added per-link in
           the PHP below by comparing $_SERVER['REQUEST_URI'] to each link's own href. */
        .sidebar .btn-light.active {
            background: var(--mewmii-pink);
            color: var(--base-white);
            font-weight: 600;
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
            <a class="navbar-brand" href="/index.php">🌸 Mewmii OS</a>
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
                <aside class="col-lg-2 sidebar p-3">
                    <div class="d-grid gap-2">
                        <a class="btn btn-light text-start<?php echo $navActive('/index.php'); ?>" href="/index.php">Dashboard</a>
                        <a class="btn btn-light text-start<?php echo $navActive('/modules/products/index.php'); ?>" href="/modules/products/index.php">Products</a>
                        <?php if (app_has_permission('products.view')): ?>
                            <a class="btn btn-light btn-sm text-start ms-3<?php echo $navActive('/modules/categories/index.php'); ?>" href="/modules/categories/index.php">Categories</a>
                            <a class="btn btn-light btn-sm text-start ms-3<?php echo $navActive('/modules/brands/index.php'); ?>" href="/modules/brands/index.php">Brands</a>
                            <a class="btn btn-light btn-sm text-start ms-3<?php echo $navActive('/modules/collections/index.php'); ?>" href="/modules/collections/index.php">Collections</a>
                            <a class="btn btn-light btn-sm text-start ms-3<?php echo $navActive('/modules/tags/index.php'); ?>" href="/modules/tags/index.php">Tags</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('orders.view')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/orders/index.php'); ?>" href="/modules/orders/index.php">Orders</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('suppliers.view')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/suppliers/index.php'); ?>" href="/modules/suppliers/index.php">Suppliers</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('supplier-orders.view')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/supplier-orders/index.php'); ?>" href="/modules/supplier-orders/index.php">Supplier Orders</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('inventory.view')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/inventory/index.php'); ?>" href="/modules/inventory/index.php">Inventory</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('customers.view')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/customers/index.php'); ?>" href="/modules/customers/index.php">Customers</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('customer-storage.view')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/customer-storage/index.php'); ?>" href="/modules/customer-storage/index.php">Customer Storage</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('ship-my-box.view')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/ship-my-box/index.php'); ?>" href="/modules/ship-my-box/index.php">Ship My Box</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('shipments.view')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/shipments/index.php'); ?>" href="/modules/shipments/index.php">Shipments</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('settings.manage')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/sync-logs/index.php'); ?>" href="/modules/sync-logs/index.php">Sync Logs</a>
                        <?php endif; ?>
                        <?php if (app_has_permission('settings.manage')): ?>
                            <a class="btn btn-light text-start<?php echo $navActive('/modules/settings/maintenance.php'); ?>" href="/modules/settings/maintenance.php">Settings</a>
                        <?php endif; ?>
                    </div>
                </aside>
                <main class="col-lg-10 p-4">
                <?php else: ?>
                    <main class="col-12 p-4">
                    <?php endif; ?>