<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_variations.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$variationId = (int) ($_POST['variation_id'] ?? 0);

if ($variationId < 1) {
    ajax_json(['error' => 'Invalid variation.'], 400);
}

try {
    $pdo->beginTransaction();
    variation_archive($pdo, $variationId);
    $pdo->commit();

    ajax_json(['ok' => true]);
} catch (Exception $exception) {
    $pdo->rollBack();
    ajax_json(['error' => 'Failed to archive variation.'], 500);
}
