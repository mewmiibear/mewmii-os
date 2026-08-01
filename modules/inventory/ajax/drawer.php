<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';
require_once __DIR__ . '/../../../includes/inventory.php';
require_once __DIR__ . '/../../../includes/product_variations.php';

/**
 * Mewmii OS v2 Phase 2 - Drawer Controller for Inventory (docs/PHASE2_IMPLEMENTATION.md).
 * Its only jobs: check permission, load data via existing functions/plain reads, hand it to
 * the View (modules/inventory/views/drawer.php) to render. No HTML is built here.
 */

ajax_require_permission_html('inventory.view');

$pdo = app_db();

$productId = (int) ($_GET['product_id'] ?? 0);
$variationId = isset($_GET['variation_id']) && (int) $_GET['variation_id'] > 0 ? (int) $_GET['variation_id'] : null;

if ($productId < 1) {
    http_response_code(400);
    echo '<div class="empty-state"><div class="empty-state-title">Nothing to show</div><div class="empty-state-text">No product was specified.</div></div>';
    exit;
}

$productStmt = $pdo->prepare('SELECT id, name, sku, product_type, catalog_type, status FROM products WHERE id = ?');
$productStmt->execute([$productId]);
$productRow = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$productRow) {
    http_response_code(404);
    echo '<div class="empty-state"><div class="empty-state-title">Not found</div><div class="empty-state-text">This product no longer exists.</div></div>';
    exit;
}

$variationSku = null;
$variationLabel = null;
if ($variationId !== null) {
    $variationStmt = $pdo->prepare('SELECT sku FROM product_variations WHERE id = ? AND product_id = ?');
    $variationStmt->execute([$variationId, $productId]);
    $variationRow = $variationStmt->fetch(PDO::FETCH_ASSOC);

    if (!$variationRow) {
        http_response_code(404);
        echo '<div class="empty-state"><div class="empty-state-title">Not found</div><div class="empty-state-text">This variation no longer exists.</div></div>';
        exit;
    }

    $variationSku = $variationRow['sku'];
    $variationLabel = variation_build_label($pdo, $variationId);
}

// Deliberately NOT inventory_get_or_create_row() - that function takes a row lock (SELECT
// ... FOR UPDATE) and creates a row if one is missing, both appropriate for a mutation but
// not for a read-only Quick View triggered by opening the drawer. A missing row here just
// means "no stock activity yet" - shown as zeros, nothing is written.
$stockStmt = $pdo->prepare('SELECT available_quantity, reserved_quantity, incoming_quantity, arrived_quantity FROM mewmii_inventory WHERE product_id = ? AND variation_id <=> ?');
$stockStmt->execute([$productId, $variationId]);
$stock = $stockStmt->fetch(PDO::FETCH_ASSOC) ?: ['available_quantity' => 0, 'reserved_quantity' => 0, 'incoming_quantity' => 0, 'arrived_quantity' => 0];

$recentTransactions = inventory_transactions_recent($pdo, $productId, $variationId, 5);

$productTypeLabels = ['ready_stock' => 'Ready Stock', 'preorder' => 'Preorder', 'early_bird' => 'Early Bird'];
$productTypeLabel = $productTypeLabels[$productRow['product_type']] ?? $productRow['product_type'];

$canManage = app_has_permission('inventory.manage');
$canManageProducts = app_has_permission('products.manage');

$unitKey = $productId . ':' . ($variationId ?? 0);

require __DIR__ . '/../views/drawer.php';
