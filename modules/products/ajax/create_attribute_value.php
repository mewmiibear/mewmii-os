<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/catalog.php';

ajax_require_permission('products.manage');
ajax_require_csrf();

$pdo = app_db();
$attributeId = (int) ($_POST['attribute_id'] ?? 0);
$value = trim((string) ($_POST['value'] ?? ''));
$code = strtoupper(trim((string) ($_POST['code'] ?? '')));

if ($attributeId < 1 || $value === '') {
    ajax_json(['error' => 'Select an attribute and enter a value.'], 400);
}

// A prefix is required for every newly-created value (existing values created before
// this requirement can still have a NULL code - only new ones are enforced) - see
// catalog_attribute_value_sku_code() for how it drives variation SKU generation.
if ($code === '') {
    ajax_json(['error' => 'Enter a SKU prefix (e.g. CN for Cinnamoroll).'], 400);
}

if (!preg_match('/^[A-Z0-9]{1,5}$/', $code)) {
    ajax_json(['error' => 'Prefix must be 1-5 letters/numbers only (e.g. CN).'], 400);
}

$codeCheck = $pdo->prepare('SELECT COUNT(*) FROM product_attribute_values WHERE attribute_id = ? AND code = ?');
$codeCheck->execute([$attributeId, $code]);
if ((int) $codeCheck->fetchColumn() > 0) {
    ajax_json(['error' => 'That prefix is already used by another value for this attribute.'], 400);
}

try {
    $pdo->beginTransaction();
    $id = catalog_get_or_create_attribute_value($pdo, $attributeId, $value, $code);
    $pdo->commit();

    ajax_json(['id' => $id, 'value' => $value, 'code' => $code, 'attribute_id' => $attributeId]);
} catch (Exception $exception) {
    $pdo->rollBack();
    ajax_json(['error' => 'Failed to create value.'], 500);
}
