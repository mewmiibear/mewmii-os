<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/saved_views.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('/index.php');
}

try {
    app_require_csrf();
} catch (RuntimeException $exception) {
    app_redirect('/index.php');
}

$module = (string) ($_POST['module'] ?? '');

// Server-side whitelist - never trust a client-supplied permission string. Each module maps
// to the exact .view permission that module's own list page already requires.
if (!array_key_exists($module, SAVED_VIEWS_MODULES)) {
    app_redirect('/index.php');
}

app_require_permission(SAVED_VIEWS_MODULES[$module]);

$name = trim((string) ($_POST['name'] ?? ''));
$queryString = (string) ($_POST['query_string'] ?? '');
$redirectPath = '/modules/' . $module . '/index.php';

if ($name === '') {
    app_redirect($redirectPath);
}

$pdo = app_db();
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
saved_view_create($pdo, $module, $name, $queryString, $userId);

app_redirect($redirectPath);
