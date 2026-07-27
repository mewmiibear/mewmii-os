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
    // Phase 9I (Manual Variation Management) - never blocked by history anymore: hard-deletes
    // if the variation has none, otherwise archives it instead so historical orders/inventory
    // transactions/supplier order lines/customer storage entries keep showing the exact same
    // variation data they always have. See variation_delete_or_archive()'s own docblock.
    $outcome = variation_delete_or_archive($pdo, $variationId);
    $pdo->commit();

    ajax_json(['ok' => true, 'outcome' => $outcome]);
} catch (RuntimeException $exception) {
    $pdo->rollBack();
    ajax_json(['error' => $exception->getMessage()], 400);
} catch (Exception $exception) {
    $pdo->rollBack();
    ajax_json(['error' => 'Failed to delete variation.'], 500);
}
