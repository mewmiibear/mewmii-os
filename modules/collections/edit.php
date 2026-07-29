<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Collection editing now happens in-place on the Collections tab of the Catalogue Manager
// (modules/catalog/index.php?tab=collections&edit=ID) - this stub only exists so old
// bookmarks/links to this URL keep working.
$query = $_GET;
$id = (int) ($query['id'] ?? 0);
unset($query['id']);
$params = ['tab' => 'collections', 'edit' => $id] + $query;
app_redirect('/modules/catalog/index.php?' . http_build_query($params));
