<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';

/**
 * Edits an existing attribute value's SKU prefix (product_attribute_values.code) - the
 * value itself is global/shared across every product that uses this attribute (see
 * includes/catalog.php's catalog_get_or_create_attribute_value()), so this changes the
 * prefix everywhere it's used, not just for one product. Existing variation SKUs already
 * generated from the old prefix are NOT retroactively renamed - only future SKU generation
 * picks up the new value (see catalog_attribute_value_sku_code()).
 */

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$valueId = (int) ($_POST['value_id'] ?? 0);
$code = strtoupper(trim((string) ($_POST['code'] ?? '')));

if ($valueId < 1) {
    ajax_json(['error' => 'Invalid value.'], 400);
}

if ($code === '') {
    ajax_json(['error' => 'Enter a SKU prefix (e.g. CN for Cinnamoroll).'], 400);
}

if (!preg_match('/^[A-Z0-9]{1,5}$/', $code)) {
    ajax_json(['error' => 'Prefix must be 1-5 letters/numbers only (e.g. CN).'], 400);
}

$valueStmt = $pdo->prepare('SELECT attribute_id FROM product_attribute_values WHERE id = ?');
$valueStmt->execute([$valueId]);
$attributeId = $valueStmt->fetchColumn();

if ($attributeId === false) {
    ajax_json(['error' => 'Value not found.'], 404);
}

$codeCheck = $pdo->prepare('SELECT COUNT(*) FROM product_attribute_values WHERE attribute_id = ? AND code = ? AND id != ?');
$codeCheck->execute([$attributeId, $code, $valueId]);
if ((int) $codeCheck->fetchColumn() > 0) {
    ajax_json(['error' => 'That prefix is already used by another value for this attribute.'], 400);
}

try {
    $pdo->prepare('UPDATE product_attribute_values SET code = ? WHERE id = ?')->execute([$code, $valueId]);

    ajax_json(['ok' => true, 'id' => $valueId, 'code' => $code]);
} catch (Exception $exception) {
    ajax_json(['error' => 'Failed to update prefix.'], 500);
}
