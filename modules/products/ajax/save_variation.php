<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_variations.php';
require_once __DIR__ . '/../../../includes/product_images.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);
$variationId = (int) ($_POST['variation_id'] ?? 0);

if ($productId < 1 || $variationId < 1) {
    ajax_json(['error' => 'Invalid variation.'], 400);
}

$sku = trim((string) ($_POST['sku'] ?? ''));
$barcode = trim((string) ($_POST['barcode'] ?? ''));
$supplierSku = trim((string) ($_POST['supplier_sku'] ?? ''));
$weight = trim((string) ($_POST['weight'] ?? ''));
$priceMode = (string) ($_POST['price_mode'] ?? 'inherit');
$customPrice = trim((string) ($_POST['custom_price'] ?? ''));
$costPrice = trim((string) ($_POST['cost_price'] ?? ''));
$status = (string) ($_POST['status'] ?? 'draft');

if ($sku === '') {
    ajax_json(['error' => 'Every variation needs a SKU.'], 400);
}
if (!in_array($priceMode, ['inherit', 'custom'], true)) {
    $priceMode = 'inherit';
}
if (!in_array($status, ['draft', 'active', 'inactive'], true)) {
    $status = 'draft';
}
if ($priceMode === 'custom' && ($customPrice === '' || !is_numeric($customPrice) || (float) $customPrice < 0)) {
    ajax_json(['error' => 'Enter a valid custom price when using "Custom Price" mode.'], 400);
}
// Left blank, this variation has no cost of its own and falls back to the parent
// product's cost_price wherever Unit Cost is auto-filled (Supplier Order picker, order
// cost_snapshot) - see catalog_sellable_units()/supplier_order_picker_products().
if ($costPrice !== '' && (!is_numeric($costPrice) || (float) $costPrice < 0)) {
    ajax_json(['error' => 'Enter a valid non-negative cost price, or leave it blank to use the parent product cost.'], 400);
}

try {
    $pdo->beginTransaction();

    $dupVariation = $pdo->prepare('SELECT COUNT(*) FROM product_variations WHERE sku = ? AND id != ?');
    $dupVariation->execute([$sku, $variationId]);
    if ((int) $dupVariation->fetchColumn() > 0) {
        throw new RuntimeException('SKU "' . $sku . '" is already used by another variation.');
    }

    $dupProduct = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
    $dupProduct->execute([$sku]);
    if ((int) $dupProduct->fetchColumn() > 0) {
        throw new RuntimeException('SKU "' . $sku . '" is already used by a product.');
    }

    $pdo->prepare('
        UPDATE product_variations
        SET sku = ?, barcode = ?, supplier_sku = ?, weight = ?, price_mode = ?, custom_price = ?, cost_price = ?, status = ?, is_system_generated = 0
        WHERE id = ? AND product_id = ?
    ')->execute([
        $sku,
        $barcode !== '' ? $barcode : null,
        $supplierSku !== '' ? $supplierSku : null,
        ($weight !== '' && is_numeric($weight)) ? round((float) $weight, 3) : null,
        $priceMode,
        $priceMode === 'custom' ? round((float) $customPrice, 2) : null,
        $costPrice !== '' ? round((float) $costPrice, 2) : null,
        $status,
        $variationId,
        $productId,
    ]);

    // Stock is no longer settable from the product form - inventory quantities are only
    // ever adjusted via the Inventory module's Adjust Stock action (see
    // modules/inventory/index.php), never here.
    if (!empty($_POST['remove_image'])) {
        variation_image_remove($pdo, $variationId);
    } elseif (!empty($_FILES['variation_image']['name'])) {
        variation_image_set($pdo, $productId, $variationId, $_FILES['variation_image']);
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
    ajax_json(['error' => 'Failed to save variation.'], 500);
}
