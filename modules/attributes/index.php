<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Attributes now lives inside the Catalogue Manager (modules/catalog/index.php) as a tab -
// this stub only exists so old bookmarks/links to this URL keep working.
$params = ['tab' => 'attributes'] + $_GET;
app_redirect('/modules/catalog/index.php?' . http_build_query($params));
