<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/product_variations.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

/**
 * Phase 9I (Manual Variation Management) - lets an admin change which attribute values
 * define an EXISTING variation after it's been created: swap a value, or omit an attribute
 * entirely to remove it from the variation. See variation_set_attribute_values() for the
 * actual logic (shared with variation_create_manual()'s initial assignment, so both paths
 * always agree on the same de-duplication rule) - this endpoint is just request parsing.
 */

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);
$variationId = (int) ($_POST['variation_id'] ?? 0);

if ($productId < 1 || $variationId < 1) {
    ajax_json(['error' => 'Invalid variation.'], 400);
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

try {
    $pdo->beginTransaction();

    $ownerCheck = $pdo->prepare('SELECT COUNT(*) FROM product_variations WHERE id = ? AND product_id = ?');
    $ownerCheck->execute([$variationId, $productId]);
    if ((int) $ownerCheck->fetchColumn() === 0) {
        throw new RuntimeException('Variation not found for this product.');
    }

    variation_set_attribute_values($pdo, $productId, $variationId, $attributeValueMap);
    $pdo->commit();

    ajax_json([
        'ok' => true,
        'label' => variation_build_label($pdo, $variationId),
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
    ajax_json(['error' => 'Failed to update variation attributes.'], 500);
}
