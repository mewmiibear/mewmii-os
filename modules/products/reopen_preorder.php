<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
app_require_permission('products.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('/modules/products/index.php');
}

try {
    app_require_csrf();
} catch (RuntimeException $exception) {
    app_redirect('/modules/products/index.php');
}

$pdo = app_db();
$productId = (int) ($_POST['product_id'] ?? 0);

if ($productId < 1) {
    app_redirect('/modules/products/index.php');
}

// Manually reopening is the ONLY way ordering resumes after preorder_closing_date passes -
// never automatic, never tied to estimated_release_month arriving. Regular Price applies
// from here on; Early Bird pricing does not come back (see catalog_product_effective_price()).
$updateStmt = $pdo->prepare("UPDATE products SET preorder_reopened_at = NOW() WHERE id = ? AND product_type IN ('preorder', 'early_bird')");
$updateStmt->execute([$productId]);

// --- TEMPORARY DEBUG INSTRUMENTATION (stale "Waiting Release" badge trace) -------------
// Re-reads the row in the SAME request, right after the UPDATE, to answer two questions
// with real data instead of speculation: (a) did the UPDATE actually match/change a row
// (affected_rows), and (b) what does the DB contain immediately afterward. Both the raw
// log line and the query-string copy exist so the values are visible without needing
// access to wherever this server's PHP error log lives. Remove this whole block, and the
// matching blocks marked the same way in edit.php/_form.php/view.php, once the mismatch
// is found.
$debugAffectedRows = $updateStmt->rowCount();
$debugVerifyStmt = $pdo->prepare('SELECT product_type, preorder_closing_date, preorder_reopened_at FROM products WHERE id = ?');
$debugVerifyStmt->execute([$productId]);
$debugVerifyRow = $debugVerifyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
error_log(sprintf(
    '[LIFECYCLE-DEBUG reopen_preorder] product_id=%d affected_rows=%d product_type=%s preorder_closing_date=%s preorder_reopened_at=%s',
    $productId,
    $debugAffectedRows,
    $debugVerifyRow['product_type'] ?? 'NULL',
    $debugVerifyRow['preorder_closing_date'] ?? 'NULL',
    $debugVerifyRow['preorder_reopened_at'] ?? 'NULL'
));
$debugQuery = http_build_query([
    'id' => $productId,
    'reopened' => 1,
    'debug_lifecycle' => 1,
    'dbg_rows' => $debugAffectedRows,
    'dbg_type' => $debugVerifyRow['product_type'] ?? '',
    'dbg_closing' => $debugVerifyRow['preorder_closing_date'] ?? '',
    'dbg_reopened_at' => $debugVerifyRow['preorder_reopened_at'] ?? '',
]);
app_redirect('/modules/products/edit.php?' . $debugQuery);
// --- END TEMPORARY DEBUG INSTRUMENTATION ------------------------------------------------
