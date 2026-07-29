<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_variations.php';
require_once __DIR__ . '/../../../includes/wc_client.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

/**
 * Phase 9I (Manual Variation Management) - creates exactly ONE variation for a specific,
 * admin-picked attribute-value combination, instead of "Generate Variations"'s full
 * cartesian product. See variation_create_manual() for the actual logic - this endpoint is
 * just request parsing/validation.
 */

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);

if ($productId < 1) {
    ajax_json(['error' => 'Invalid product.'], 400);
}

$attributeValues = json_decode((string) ($_POST['attribute_values'] ?? '{}'), true);
if (!is_array($attributeValues)) {
    ajax_json(['error' => 'Invalid attribute selection.'], 400);
}

$attributeValueMap = [];
foreach ($attributeValues as $attributeId => $valueId) {
    $attributeId = (int) $attributeId;
    $valueId = (int) $valueId;
    if ($attributeId > 0 && $valueId > 0) {
        $attributeValueMap[$attributeId] = $valueId;
    }
}

$weightMode = (string) ($_POST['weight_mode'] ?? 'inherit');
$weight = trim((string) ($_POST['weight'] ?? ''));
if ($weight !== '' && (!is_numeric($weight) || (float) $weight < 0)) {
    ajax_json(['error' => 'Weight cannot be negative.'], 400);
}

try {
    $pdo->beginTransaction();
    $created = variation_create_manual($pdo, $productId, $attributeValueMap, [
        'barcode' => (string) ($_POST['barcode'] ?? ''),
        'supplier_sku' => (string) ($_POST['supplier_sku'] ?? ''),
        'weight_mode' => $weightMode,
        'weight' => $weight,
        'status' => (string) ($_POST['status'] ?? 'active'),
    ]);
    $pdo->commit();

    // Full-automation pass - a new variation changes what gets pushed for this product's next
    // sync (wc_client_sync_variable_product_from_mewmii() reads variation_list_for_product()
    // itself); never throws, see wc_client_auto_sync_product()'s own docblock.
    wc_client_auto_sync_product($pdo, $productId);

    ajax_json([
        'ok' => true,
        'variation_id' => $created['id'],
        'sku' => $created['sku'],
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
    ajax_json(['error' => 'Failed to create variation.'], 500);
}
