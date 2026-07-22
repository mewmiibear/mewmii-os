<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_variations.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);

if ($productId < 1) {
    ajax_json(['error' => 'Invalid product.'], 400);
}

try {
    $pdo->beginTransaction();
    $result = variation_generate_combinations($pdo, $productId);
    $pdo->commit();

    ajax_json([
        'created' => $result['created'],
        'skipped' => $result['skipped'],
        'variations' => variation_list_for_product($pdo, $productId),
    ]);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ajax_json(['error' => $exception->getMessage()], 400);
} catch (Exception $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ajax_json(['error' => 'Failed to generate variations.'], 500);
}
