<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Tag editing now happens in-place on the Tags tab of the Catalogue Manager
// (modules/catalog/index.php?tab=tags&edit=ID) - this stub only exists so old
// bookmarks/links to this URL keep working.
$query = $_GET;
$id = (int) ($query['id'] ?? 0);
unset($query['id']);
$params = ['tab' => 'tags', 'edit' => $id] + $query;
app_redirect('/modules/catalog/index.php?' . http_build_query($params));
