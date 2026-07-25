<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Attribute value management now happens on the Attributes tab of the Catalogue Manager
// (modules/catalog/index.php?tab=attributes&manage=ID) - this stub only exists so old
// bookmarks/links to this URL keep working.
$query = $_GET;
$id = (int) ($query['id'] ?? 0);
unset($query['id']);
$params = ['tab' => 'attributes', 'manage' => $id] + $query;
app_redirect('/modules/catalog/index.php?' . http_build_query($params));
