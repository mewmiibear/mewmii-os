<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../includes/global_search.php';

ajax_require_login();

$term = trim((string) ($_GET['q'] ?? ''));
$pdo = app_db();

// 5 per type is enough for a live dropdown - the full results page (modules/search/index.php)
// is where a longer list belongs.
$sections = global_search($pdo, $term, 5);

ajax_json(['sections' => array_values($sections)]);
