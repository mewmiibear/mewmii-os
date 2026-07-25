<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/product_import.php';
app_require_permission('products.manage');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="product_import_template.csv"');
echo product_import_template_csv();
