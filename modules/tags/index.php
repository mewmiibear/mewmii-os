<?php
/**
 * Retired: tags are now managed inline on the product page ("+ Add Tag"). This redirect
 * exists only for backward compatibility with old bookmarks/links.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

app_redirect('/modules/products/index.php');
