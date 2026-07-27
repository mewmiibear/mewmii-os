<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_variations.php';
require_once __DIR__ . '/../../../includes/wc_client.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$variationId = (int) ($_POST['variation_id'] ?? 0);

if ($variationId < 1) {
    ajax_json(['error' => 'Invalid variation.'], 400);
}

// Needed after the delete/archive below for the auto-sync call - product_variations doesn't
// keep a deleted row to look this up from afterward.
$productIdStmt = $pdo->prepare('SELECT product_id FROM product_variations WHERE id = ?');
$productIdStmt->execute([$variationId]);
$variationProductId = (int) $productIdStmt->fetchColumn();

try {
    $pdo->beginTransaction();
    // Phase 9I (Manual Variation Management) - never blocked by history anymore: hard-deletes
    // if the variation has none, otherwise archives it instead so historical orders/inventory
    // transactions/supplier order lines/customer storage entries keep showing the exact same
    // variation data they always have. See variation_delete_or_archive()'s own docblock.
    $outcome = variation_delete_or_archive($pdo, $variationId);
    $pdo->commit();

    // Full-automation pass - see add_variation_manual.php's own comment; same reasoning
    // (a removed/archived variation must stop showing as purchasable on WooCommerce too).
    if ($variationProductId > 0) {
        wc_client_auto_sync_product($pdo, $variationProductId);
    }

    ajax_json(['ok' => true, 'outcome' => $outcome]);
} catch (RuntimeException $exception) {
    $pdo->rollBack();
    ajax_json(['error' => $exception->getMessage()], 400);
} catch (Exception $exception) {
    $pdo->rollBack();
    ajax_json(['error' => 'Failed to delete variation.'], 500);
}
