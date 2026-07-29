<?php
/**
 * Retired: variation management now lives entirely on the product edit page (one page,
 * one save - see modules/products/edit.php and assets/js/product-form.js). This redirect
 * exists only for backward compatibility with old bookmarks/links.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$productId = (int) ($_GET['product_id'] ?? 0);

app_redirect($productId > 0 ? ('/modules/products/edit.php?id=' . $productId) : '/modules/products/index.php');
