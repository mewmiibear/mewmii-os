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
        body {
            background: #fff7fb;
            color: #4a2c3a;
        }

        .navbar-brand {
            color: #d9487b !important;
            font-weight: 700;
        }

        .card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(217, 72, 123, 0.12);
        }

        .btn-primary {
            background: #d9487b;
            border-color: #d9487b;
        }

        .btn-primary:hover {
            background: #c53b6e;
            border-color: #c53b6e;
        }

        .sidebar {
            background: linear-gradient(180deg, #fff 0%, #ffeef6 100%);
            min-height: 100vh;
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
                <aside class="col-lg-2 sidebar p-3">
                    <div class="d-grid gap-2">
                        <a class="btn btn-light text-start" href="/index.php">Dashboard</a>
                        <a class="btn btn-light text-start" href="/modules/products/index.php">Products</a>
                        <a class="btn btn-light text-start" href="/modules/orders/index.php">Orders</a>
                        <a class="btn btn-light text-start" href="/modules/suppliers/index.php">Suppliers</a>
                        <a class="btn btn-light text-start" href="/modules/inventory/index.php">Inventory</a>
                        <a class="btn btn-light text-start" href="/modules/customers/index.php">Customers</a>
                    </div>
                </aside>
                <main class="col-lg-10 p-4">
                <?php else: ?>
                    <main class="col-12 p-4">
                    <?php endif; ?>