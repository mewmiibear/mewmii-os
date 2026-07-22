<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_variations.php';
require_once __DIR__ . '/../../../includes/product_images.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);
$variationIds = array_map('intval', $_POST['variation_ids'] ?? []);

if ($productId < 1 || $variationIds === []) {
    ajax_json(['error' => 'Select at least one variation.'], 400);
}

$productStmt = $pdo->prepare('SELECT product_type FROM products WHERE id = ?');
$productStmt->execute([$productId]);
$productType = (string) $productStmt->fetchColumn();

try {
    $pdo->beginTransaction();

    $changes = [];
    if (!empty($_POST['price_mode'])) {
        $changes['price_mode'] = (string) $_POST['price_mode'];
        $changes['custom_price'] = (string) ($_POST['custom_price'] ?? '');
    }
    if (isset($_POST['weight']) && $_POST['weight'] !== '') {
        $changes['weight'] = (string) $_POST['weight'];
    }
    if (!empty($_POST['status'])) {
        $changes['status'] = (string) $_POST['status'];
    }
    // Stock is only settable for ready_stock - preorder/early_bird never request
    // available stock in bulk either, regardless of what was posted.
    if ($productType === 'ready_stock' && isset($_POST['stock']) && $_POST['stock'] !== '') {
        $changes['stock'] = (string) $_POST['stock'];
    }
    if (!empty($_POST['clear_barcode'])) {
        $changes['clear_barcode'] = true;
    }

    if ($changes !== []) {
        variation_bulk_apply($pdo, $variationIds, $changes);
    }

    if (!empty($_FILES['image']['name'])) {
        variation_bulk_set_image($pdo, $productId, $variationIds, $_FILES['image']);
    }

    $pdo->commit();

    ajax_json(['ok' => true]);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ajax_json(['error' => $exception->getMessage()], 400);
} catch (Exception $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ajax_json(['error' => 'Bulk update failed.'], 500);
}
