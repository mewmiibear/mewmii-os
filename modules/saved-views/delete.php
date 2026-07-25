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

if (!array_key_exists($module, SAVED_VIEWS_MODULES)) {
    app_redirect('/index.php');
}

app_require_permission(SAVED_VIEWS_MODULES[$module]);

$viewId = (int) ($_POST['view_id'] ?? 0);
$redirectPath = '/modules/' . $module . '/index.php';

if ($viewId > 0) {
    saved_view_delete(app_db(), $viewId, $module);
}

app_redirect($redirectPath);
