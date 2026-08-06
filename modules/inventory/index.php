<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/pagination.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/product_images.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/activity_log.php';
require_once __DIR__ . '/../../includes/purchase_planning.php';
app_require_permission('inventory.view');

$appTitle = 'Inventory';
$error = '';
$pdo = app_db();

$adjustmentReasons = ['Damaged', 'Lost', 'Stock Correction', 'Sample', 'Giveaway', 'Return', 'Transfer', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !app_has_permission('inventory.manage')) {
        http_response_code(403);
        $error = 'You do not have permission to adjust inventory.';
    }

    if ($error === '') {
        $unitKey = trim((string) ($_POST['unit_key'] ?? ''));
        $adjustmentType = (string) ($_POST['adjustment_type'] ?? 'increase');
        $quantityInput = (int) ($_POST['quantity'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $unit = $unitKey !== '' ? catalog_parse_sellable_key($unitKey) : null;

        if ($unit === null || $unit['product_id'] < 1) {
            $error = 'Select a product.';
        } elseif (!in_array($adjustmentType, ['increase', 'decrease', 'set_exact'], true)) {
            $error = 'Invalid adjustment type.';
        } elseif ($quantityInput < 0 || ($adjustmentType !== 'set_exact' && $quantityInput === 0)) {
            $error = 'Enter a valid quantity.';
        } elseif ($reason === '') {
            $error = 'Select a reason for this adjustment.';
        } else {
            $productId = $unit['product_id'];
            $variationId = $unit['variation_id'];

            $pdo->beginTransaction();

            try {
                $row = inventory_get_or_create_row($pdo, $productId, $variationId);

                $delta = match ($adjustmentType) {
                    'decrease' => -$quantityInput,
                    'set_exact' => $quantityInput - (int) $row['available_quantity'],
                    default => $quantityInput,
                };

                if ($delta === 0) {
                    throw new RuntimeException('This adjustment would not change the current quantity.');
                }

                if ($delta < 0 && (int) $row['available_quantity'] + $delta < 0) {
                    throw new RuntimeException('Adjustment would result in negative available stock.');
                }

                $pdo->prepare('
                    UPDATE mewmii_inventory
                    SET available_quantity = available_quantity + ?
                    WHERE product_id = ? AND variation_id <=> ?
                ')->execute([$delta, $productId, $variationId]);

                inventory_log_transaction($pdo, $productId, 'adjustment', $delta, 'manual_adjustment', (int) ($_SESSION['user_id'] ?? 0), $variationId, $reason, $notes !== '' ? $notes : null);
                activity_log($pdo, 'inventory', 'adjustment', $productId, 'Stock adjustment (' . $reason . ') on product #' . $productId . ($variationId !== null ? (' variation #' . $variationId) : '') . ': ' . ($delta > 0 ? '+' : '') . $delta);

                $pdo->commit();
                inventory_flush_woocommerce_resync($pdo);

                app_redirect('/modules/inventory/index.php?adjusted=1');
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                inventory_discard_pending_woocommerce_resync();
                $error = $exception->getMessage();
            } catch (Exception $exception) {
                $pdo->rollBack();
                inventory_discard_pending_woocommerce_resync();
                $error = 'Failed to adjust inventory.';
            }
        }
    }
}

// Products drive the listing (grouping variations under their parent) rather than a flat
// union of "sellable units" - the filtered product set is fetched first, then each variable
// product's variations are fetched underneath it. Simple products are still simple single rows.
$productTypeLabels = ['ready_stock' => 'Ready Stock', 'preorder' => 'Preorder', 'early_bird' => 'Early Bird'];

// --- Filters: same whitelist-before-query approach as modules/products/index.php - an
// invalid/garbage GET value is silently treated as "no filter" rather than coerced. ---
$catalogTypeOptions = ['simple', 'variable'];
$stockStatusOptions = ['in_stock', 'low_stock', 'out_of_stock'];
$stageOptions = ['on_hand', 'reserved', 'incoming', 'arrived'];
$productTypeOptions = ['ready_stock', 'preorder', 'early_bird'];

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$filterCatalogType = in_array($_GET['catalog_type'] ?? '', $catalogTypeOptions, true) ? $_GET['catalog_type'] : null;
$filterStockStatus = in_array($_GET['stock_status'] ?? '', $stockStatusOptions, true) ? $_GET['stock_status'] : null;
$filterStage = in_array($_GET['stage'] ?? '', $stageOptions, true) ? $_GET['stage'] : null;
$filterSupplierId = isset($_GET['supplier_id']) && ctype_digit((string) $_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
$filterCategoryId = isset($_GET['category_id']) && ctype_digit((string) $_GET['category_id']) ? (int) $_GET['category_id'] : null;
$filterProductType = in_array($_GET['product_type'] ?? '', $productTypeOptions, true) ? $_GET['product_type'] : null;
// "Need Ordering" - Purchase Planning quick filter (see includes/purchase_planning.php).
$filterNeedsOrdering = isset($_GET['needs_ordering']);

// --- Product-level filter fragment (catalog_type/product_type/supplier/category/search) -
// reused, unchanged, in both the attention-summary aggregate below and the candidate-id
// query for the table, so the two can never drift out of sync with each other. ---
$productFilterSql = '';
$productFilterParams = [];
if ($filterCatalogType !== null) {
    $productFilterSql .= ' AND p.catalog_type = ?';
    $productFilterParams[] = $filterCatalogType;
}
if ($filterProductType !== null) {
    $productFilterSql .= ' AND p.product_type = ?';
    $productFilterParams[] = $filterProductType;
}
if ($filterSupplierId !== null) {
    $productFilterSql .= ' AND p.supplier_id = ?';
    $productFilterParams[] = $filterSupplierId;
}
if ($filterCategoryId !== null) {
    $productFilterSql .= ' AND EXISTS (SELECT 1 FROM product_category_relationships r WHERE r.product_id = p.id AND r.category_id = ?)';
    $productFilterParams[] = $filterCategoryId;
}
if ($searchTerm !== '') {
    // Matches the product's own name/SKU, or any of its variations' SKUs - a variation-only
    // match still surfaces its parent product (auto-expanded below) rather than being
    // invisible under a collapsed group.
    $productFilterSql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR EXISTS (SELECT 1 FROM product_variations pv2 WHERE pv2.product_id = p.id AND pv2.sku LIKE ?))';
    $likeTerm = '%' . $searchTerm . '%';
    $productFilterParams[] = $likeTerm;
    $productFilterParams[] = $likeTerm;
    $productFilterParams[] = $likeTerm;
}

/**
 * Production Readiness Phase 1, Critical #2: the original query applied `ORDER BY p.id DESC
 * LIMIT 500` BEFORE the Stock Status/Stage/Needs Ordering filters ran (in PHP, over that
 * already-capped array) - a product outside the newest 500 could never appear in a "Low
 * Stock" or "Needs Ordering" view no matter how badly it needed attention, and the attention
 * summary below it was silently undercounting the same way. This rewrite computes one
 * unit-level query (a simple product's own row, or one non-archived variation row) carrying
 * every column Stock Status/Stage/the attention summary need, applies the product-level
 * filters above to it, and reuses that SAME base query for two unbounded (uncapped) purposes:
 * the attention summary aggregate, and the candidate product-id list the table paginates
 * over. Pagination now happens last, over a plain array of ids - correct regardless of catalog
 * size, and still cheap to hold in full even at 10,000+ matching products.
 */
$unitBaseSql = "
    SELECT p.id AS product_id, NULL AS variation_id, p.product_type AS product_type,
           p.min_stock_threshold AS min_stock_threshold, p.availability_override AS availability_override,
           COALESCE(inv.available_quantity, 0) AS available_quantity,
           COALESCE(inv.reserved_quantity, 0) AS reserved_quantity,
           COALESCE(inv.incoming_quantity, 0) AS incoming_quantity,
           COALESCE(inv.arrived_quantity, 0) AS arrived_quantity
    FROM products p
    LEFT JOIN mewmii_inventory inv ON inv.product_id = p.id AND inv.variation_id IS NULL
    WHERE p.catalog_type = 'simple' {$productFilterSql}

    UNION ALL

    SELECT p.id AS product_id, pv.id AS variation_id, p.product_type AS product_type,
           p.min_stock_threshold AS min_stock_threshold, p.availability_override AS availability_override,
           COALESCE(inv.available_quantity, 0) AS available_quantity,
           COALESCE(inv.reserved_quantity, 0) AS reserved_quantity,
           COALESCE(inv.incoming_quantity, 0) AS incoming_quantity,
           COALESCE(inv.arrived_quantity, 0) AS arrived_quantity
    FROM products p
    INNER JOIN product_variations pv ON pv.product_id = p.id AND pv.status <> 'archived'
    LEFT JOIN mewmii_inventory inv ON inv.product_id = p.id AND inv.variation_id = pv.id
    WHERE p.catalog_type = 'variable' {$productFilterSql}
";
$unitBaseParams = array_merge($productFilterParams, $productFilterParams);

// Attention summary: same rule inventory_stock_badges() applies per row, aggregated in SQL
// over every matching unit in the whole catalog (not just whatever the table happens to be
// paginated to) - reflects the catalog_type/product_type/supplier/category/search filters
// above, but deliberately NOT the Stock Status/Stage/Needs Ordering quick filters below, same
// as before this fix.
$attentionStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN product_type = 'ready_stock' AND COALESCE(availability_override, 'auto') <> 'available'
                      AND (COALESCE(availability_override, 'auto') = 'out_of_stock' OR available_quantity = 0)
                 THEN 1 ELSE 0 END) AS out_of_stock_count,
        SUM(CASE WHEN product_type = 'ready_stock' AND COALESCE(availability_override, 'auto') <> 'available'
                      AND COALESCE(availability_override, 'auto') <> 'out_of_stock' AND available_quantity > 0
                      AND min_stock_threshold IS NOT NULL AND available_quantity < min_stock_threshold
                 THEN 1 ELSE 0 END) AS low_stock_count
    FROM ({$unitBaseSql}) AS attention_units
");
$attentionStmt->execute($unitBaseParams);
$attentionRow = $attentionStmt->fetch(PDO::FETCH_ASSOC) ?: ['out_of_stock_count' => 0, 'low_stock_count' => 0];
$attentionOutOfStockCount = (int) $attentionRow['out_of_stock_count'];
$attentionLowStockCount = (int) $attentionRow['low_stock_count'];

// Stock Status / Stage, now expressed as SQL predicates against the same unbounded unit set
// above (inventory_unit_matches_filters() below still encodes the identical rule, reused
// further down purely to decide which variations to DISPLAY under an already-qualifying
// product, not whether the product qualifies at all).
$stockFilterSql = '';
$stockFilterParams = [];
if ($filterStockStatus !== null) {
    $stockFilterSql .= " AND (
        CASE
            WHEN product_type <> 'ready_stock' THEN 'in_stock'
            WHEN available_quantity = 0 THEN 'out_of_stock'
            WHEN min_stock_threshold IS NOT NULL AND available_quantity < min_stock_threshold THEN 'low_stock'
            ELSE 'in_stock'
        END
    ) = ?";
    $stockFilterParams[] = $filterStockStatus;
}
if ($filterStage !== null) {
    $stageColumn = [
        'on_hand' => 'available_quantity',
        'reserved' => 'reserved_quantity',
        'incoming' => 'incoming_quantity',
        'arrived' => 'arrived_quantity',
    ][$filterStage];
    $stockFilterSql .= " AND {$stageColumn} > 0";
}

$candidateStmt = $pdo->prepare("SELECT DISTINCT product_id FROM ({$unitBaseSql}) AS candidate_units WHERE 1 = 1 {$stockFilterSql}");
$candidateStmt->execute(array_merge($unitBaseParams, $stockFilterParams));
$matchingProductIds = array_map('intval', $candidateStmt->fetchAll(PDO::FETCH_COLUMN));

// Needs Ordering: purchase_planning_needs() already runs unbounded across the whole catalog
// (Critical #3), so intersecting its product ids here is a genuine pre-filter, not a
// post-filter on an already-capped page. $needyKeys (unit-level) is kept for the
// per-variation display trim further below.
$needyKeys = [];
if ($filterNeedsOrdering) {
    $needyKeys = array_column(purchase_planning_needs($pdo), null, 'key');
    $needyProductIds = [];
    foreach ($needyKeys as $need) {
        $needyProductIds[(int) $need['product_id']] = true;
    }
    $matchingProductIds = array_values(array_intersect($matchingProductIds, array_keys($needyProductIds)));
}

// Pagination: over the id list (a plain array of ints - cheap to hold in full even for a
// large catalog), not over full row data. Same ORDER BY p.id DESC as before this fix.
rsort($matchingProductIds, SORT_NUMERIC);
$perPage = 100;
$totalCount = count($matchingProductIds);
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$page = min($page, $totalPages);
$pageProductIds = array_slice($matchingProductIds, ($page - 1) * $perPage, $perPage);

$inventory = [];
if ($pageProductIds !== []) {
    $pagePlaceholders = implode(',', array_fill(0, count($pageProductIds), '?'));
    $pageStmt = $pdo->prepare("
        SELECT p.id, p.sku, p.name, p.catalog_type, p.product_type, p.min_stock_threshold,
               p.status, p.preorder_closing_date, p.preorder_reopened_at, p.availability_override
        FROM products p
        WHERE p.id IN ({$pagePlaceholders})
    ");
    $pageStmt->execute($pageProductIds);
    $productsById = [];
    foreach ($pageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productsById[(int) $row['id']] = $row;
    }
    foreach ($pageProductIds as $pid) {
        if (isset($productsById[$pid])) {
            $inventory[] = $productsById[$pid];
        }
    }
}

$simpleIds = [];
$variableIds = [];
foreach ($inventory as $product) {
    if ($product['catalog_type'] === 'variable') {
        $variableIds[] = (int) $product['id'];
    } else {
        $simpleIds[] = (int) $product['id'];
    }
}

$simpleInventoryByProduct = [];
if ($simpleIds !== []) {
    $placeholders = implode(',', array_fill(0, count($simpleIds), '?'));
    $stmt = $pdo->prepare("
        SELECT product_id,
               COALESCE(available_quantity, 0) AS stock_quantity,
               COALESCE(reserved_quantity, 0) AS reserved_stock,
               COALESCE(incoming_quantity, 0) AS incoming_stock,
               COALESCE(arrived_quantity, 0) AS arrived_stock,
               updated_at
        FROM mewmii_inventory
        WHERE product_id IN ($placeholders) AND variation_id IS NULL
    ");
    $stmt->execute($simpleIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $simpleInventoryByProduct[(int) $row['product_id']] = $row;
    }
}

$variationsByProduct = [];
if ($variableIds !== []) {
    $placeholders = implode(',', array_fill(0, count($variableIds), '?'));
    $stmt = $pdo->prepare("
        SELECT pv.id AS variation_id, pv.product_id, pv.sku,
               COALESCE(inv.available_quantity, 0) AS stock_quantity,
               COALESCE(inv.reserved_quantity, 0) AS reserved_stock,
               COALESCE(inv.incoming_quantity, 0) AS incoming_stock,
               COALESCE(inv.arrived_quantity, 0) AS arrived_stock,
               inv.updated_at
        FROM product_variations pv
        LEFT JOIN mewmii_inventory inv ON inv.variation_id = pv.id
        WHERE pv.product_id IN ($placeholders) AND pv.status <> 'archived'
        ORDER BY pv.product_id ASC, pv.id ASC
    ");
    $stmt->execute($variableIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // variation_build_full_label() spells out each attribute's name (e.g. "Character:
        // My Melody") rather than the compact value-only label used elsewhere in the app,
        // since a bare "My Melody" wouldn't say which attribute it is at a glance here.
        $row['variation_label'] = variation_build_full_label($pdo, (int) $row['variation_id']);
        $variationsByProduct[(int) $row['product_id']][] = $row;
    }
}

foreach ($inventory as &$product) {
    $productId = (int) $product['id'];
    $mainImage = product_image_get_main($pdo, $productId);
    $product['thumb_path'] = $mainImage['image_path'] ?? null;
    $product['auto_expand'] = false;

    if ($product['catalog_type'] === 'variable') {
        $product['variations'] = $variationsByProduct[$productId] ?? [];

        // A variable product never holds its own mewmii_inventory row (see
        // inventory_get_or_create_row()) - these are a read-only rollup across its
        // variations for the group heading row, purely for at-a-glance scanning. The real,
        // adjustable quantities stay on each variation row underneath.
        $totals = product_effective_stock($pdo, $productId);
        $product['stock_quantity'] = (int) $totals['available_quantity'];
        $product['reserved_stock'] = (int) $totals['reserved_quantity'];
        $product['incoming_stock'] = (int) $totals['incoming_quantity'];
        $product['arrived_stock'] = (int) $totals['arrived_quantity'];
        $product['updated_at'] = null;

        // Auto-expand a group when the search term only matched inside a variation's SKU -
        // otherwise the matching row would stay hidden behind a collapsed parent.
        if ($searchTerm !== '') {
            foreach ($product['variations'] as $variation) {
                if (stripos($variation['sku'], $searchTerm) !== false) {
                    $product['auto_expand'] = true;
                    break;
                }
            }
        }
    } else {
        $row = $simpleInventoryByProduct[$productId] ?? ['stock_quantity' => 0, 'reserved_stock' => 0, 'incoming_stock' => 0, 'arrived_stock' => 0, 'updated_at' => null];
        $product['stock_quantity'] = (int) $row['stock_quantity'];
        $product['reserved_stock'] = (int) $row['reserved_stock'];
        $product['incoming_stock'] = (int) $row['incoming_stock'];
        $product['arrived_stock'] = (int) $row['arrived_stock'];
        $product['updated_at'] = $row['updated_at'];
    }
}
unset($product);

// Earliest expected delivery date already on file for a product's incoming stock - same
// read-only lookup pattern already used on modules/purchase-planning/generate.php (same
// query shape, same supplier_order_items/supplier_orders join), just batched here for
// whatever products in THIS filtered list currently have incoming_stock > 0. Purely
// informational context next to the existing Incoming number - never affects any quantity.
$incomingProductIds = [];
foreach ($inventory as $incomingProduct) {
    if ((int) $incomingProduct['incoming_stock'] > 0) {
        $incomingProductIds[] = (int) $incomingProduct['id'];
    }
}
$incomingProductIds = array_values(array_unique($incomingProductIds));
$earliestDeliveryByProductVariation = [];
if ($incomingProductIds !== []) {
    $incomingPlaceholders = implode(',', array_fill(0, count($incomingProductIds), '?'));
    $incomingDeliveryStmt = $pdo->prepare("
        SELECT soi.product_id, soi.variation_id, MIN(so.expected_delivery_date) AS earliest_expected
        FROM supplier_order_items soi
        INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id
        WHERE so.status NOT IN ('received', 'cancelled')
          AND so.expected_delivery_date IS NOT NULL
          AND soi.product_id IN ({$incomingPlaceholders})
        GROUP BY soi.product_id, soi.variation_id
    ");
    $incomingDeliveryStmt->execute($incomingProductIds);
    foreach ($incomingDeliveryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['product_id'] . ':' . (int) ($row['variation_id'] ?? 0);
        $earliestDeliveryByProductVariation[$key] = $row['earliest_expected'];
    }
}

/**
 * Stock Status (ready_stock only - mirrors inventory_stock_badges() below) or Inventory
 * Stage (any bucket with a positive quantity, any product type) - used to filter both
 * simple-product rows and individual variation rows post-fetch, since both depend on
 * ledger-derived quantities already merged above rather than raw SQL columns.
 */
function inventory_unit_matches_filters(array $unit, string $productType, ?int $minStockThreshold, ?string $filterStockStatus, ?string $filterStage): bool
{
    if ($filterStockStatus !== null) {
        $available = (int) $unit['stock_quantity'];
        if ($productType !== 'ready_stock') {
            $status = 'in_stock';
        } elseif ($available === 0) {
            $status = 'out_of_stock';
        } elseif ($minStockThreshold !== null && $available < $minStockThreshold) {
            $status = 'low_stock';
        } else {
            $status = 'in_stock';
        }

        if ($status !== $filterStockStatus) {
            return false;
        }
    }

    if ($filterStage !== null) {
        $stageQty = match ($filterStage) {
            'on_hand' => (int) $unit['stock_quantity'],
            'reserved' => (int) $unit['reserved_stock'],
            'incoming' => (int) $unit['incoming_stock'],
            'arrived' => (int) $unit['arrived_stock'],
            default => 0,
        };
        if ($stageQty <= 0) {
            return false;
        }
    }

    return true;
}

/**
 * Every product reaching this point already qualified via the SQL candidate query above
 * (guaranteed at least one matching unit for Stock Status/Stage, and/or at least one needy
 * unit for Needs Ordering) - this pass only decides which of a matching VARIABLE product's
 * variations to actually DISPLAY (a simple product's own single row is always already a
 * match, nothing further to trim), combining both criteria in one filter so a variation must
 * satisfy every currently-active filter to stay visible, same AND semantics as before this
 * fix. A variable product whose variations all get trimmed away here is dropped entirely,
 * matching the original behaviour of never showing an empty group for a filter it doesn't
 * actually satisfy.
 */
if ($filterStockStatus !== null || $filterStage !== null || $filterNeedsOrdering) {
    $filteredInventory = [];
    foreach ($inventory as $product) {
        if ($product['catalog_type'] === 'variable') {
            $minThreshold = $product['min_stock_threshold'] !== null ? (int) $product['min_stock_threshold'] : null;
            $product['variations'] = array_values(array_filter(
                $product['variations'],
                static function (array $variation) use ($product, $minThreshold, $filterStockStatus, $filterStage, $filterNeedsOrdering, $needyKeys): bool {
                    if (($filterStockStatus !== null || $filterStage !== null)
                        && !inventory_unit_matches_filters($variation, $product['product_type'], $minThreshold, $filterStockStatus, $filterStage)) {
                        return false;
                    }
                    if ($filterNeedsOrdering && !isset($needyKeys[(int) $product['id'] . ':' . (int) $variation['variation_id']])) {
                        return false;
                    }

                    return true;
                }
            ));
            if ($product['variations'] === []) {
                continue;
            }
            $product['auto_expand'] = true;
        }

        $filteredInventory[] = $product;
    }
    $inventory = $filteredInventory;
}

$sellableUnits = catalog_sellable_units($pdo);
$canManage = app_has_permission('inventory.manage');
// Separate from $canManage above: modules/purchase-planning/generate.php requires
// supplier-orders.manage, not inventory.manage - a user with only the latter must not be
// shown a button that leads straight to a 403.
$canManageSupplierOrders = app_has_permission('supplier-orders.manage');
// Same reasoning as $canManageSupplierOrders above: the "Edit Product" links below go to
// modules/products/edit.php, which requires products.manage - not inventory.manage.
$canManageProducts = app_has_permission('products.manage');
$filterSuppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$filterCategories = catalog_list_categories_tree($pdo);

/**
 * Low Stock / Out of Stock badges: exact rule already established in
 * modules/products/edit.php, reused verbatim here for consistency. Only ready_stock is
 * ever flagged - preorder/early_bird are deliberately purchasable at 0 stock (see
 * catalog_product_is_orderable()) and get their own phrase instead (see
 * inventory_availability_phrase() below). A manual availability_override on a Ready Stock
 * product takes priority over the real quantity, per catalog_product_availability_status().
 */
function inventory_stock_badges(string $productType, int $availableQuantity, ?int $minStockThreshold, string $overrideValue = 'auto'): string
{
    if ($productType !== 'ready_stock') {
        return '';
    }

    if ($overrideValue === 'available') {
        return '';
    }
    if ($overrideValue === 'out_of_stock') {
        return '<span class="badge bg-danger ms-1">Out of Stock (Manual)</span>';
    }

    if ($availableQuantity === 0) {
        return '<span class="badge bg-danger ms-1">Out of Stock</span>';
    }

    if ($minStockThreshold !== null && $availableQuantity < $minStockThreshold) {
        return '<span class="badge bg-warning text-dark ms-1">Low Stock</span>';
    }

    return '';
}

/**
 * What the On Hand cell shows for a preorder/early_bird row instead of a raw quantity -
 * these product types are never gated on physical stock (see
 * catalog_product_availability_status()), so a bare "0" there would misleadingly read
 * like "out of stock" when it's actually still purchasable. Returns null for ready_stock
 * (the caller shows the real number as usual). $isOrderable is
 * catalog_product_is_orderable($product) - the separate closing-date/reopen gate, which
 * this phrase also reflects ("Waiting for Release" beats "Open" once that's closed),
 * independent of the manual override.
 */
function inventory_availability_phrase(string $productType, string $overrideValue, bool $isOrderable): ?string
{
    if ($productType !== 'ready_stock' && $productType !== 'preorder' && $productType !== 'early_bird') {
        return null;
    }
    if ($productType === 'ready_stock') {
        return null;
    }

    $typeLabel = $productType === 'early_bird' ? 'Early Bird' : 'Preorder';

    // Priority: manual override first (authoritative, bypasses lifecycle entirely - see
    // catalog_product_availability_status()), then the closing-date/reopen lifecycle gate.
    if ($overrideValue === 'out_of_stock') {
        return 'Closed (Manual)';
    }
    if ($overrideValue === 'available') {
        return $typeLabel . ' Open';
    }
    if (!$isOrderable) {
        return 'Waiting for Release';
    }

    return $typeLabel . ' Open';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1">Inventory</h1>
        <p class="page-description">Current stock at a glance - adjustments and history stay one click away.</p>
    </div>
    <div class="action-bar">
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-primary" onclick="InventoryUI.openAdjustModal('')">Adjust Stock</button>
        <?php endif; ?>
        <?php if ($canManageSupplierOrders): ?>
            <a class="btn btn-outline-primary" href="/modules/purchase-planning/generate.php">View Purchase Planning</a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a class="btn btn-outline-secondary" href="/modules/inventory/import_opening_stock.php">Import Opening Stock</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($attentionOutOfStockCount > 0 || $attentionLowStockCount > 0): ?>
    <div class="attention-item tone-danger d-flex justify-content-between align-items-center p-3 mb-4">
        <span>
            <?php if ($attentionOutOfStockCount > 0): ?>
                <strong><?php echo (int) $attentionOutOfStockCount; ?></strong> out of stock
            <?php endif; ?>
            <?php if ($attentionOutOfStockCount > 0 && $attentionLowStockCount > 0): ?> &middot; <?php endif; ?>
            <?php if ($attentionLowStockCount > 0): ?>
                <strong><?php echo (int) $attentionLowStockCount; ?></strong> low stock
            <?php endif; ?>
            <?php echo ($filterCatalogType !== null || $filterProductType !== null || $filterSupplierId !== null || $filterCategoryId !== null || $searchTerm !== '') ? 'within the current filters' : 'across ready-stock products'; ?>
        </span>
        <a class="btn btn-outline-primary btn-sm" href="/modules/inventory/index.php?stock_status=low_stock">Review &rarr;</a>
    </div>
<?php endif; ?>

<?php if (isset($_GET['adjusted'])): ?>
    <div class="alert alert-success">Inventory adjusted.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm btn-filter<?php echo $filterNeedsOrdering ? ' is-active' : ''; ?>" href="/modules/inventory/index.php?needs_ordering=1">Need Ordering</a>
    <a class="btn btn-sm btn-filter<?php echo $filterStage === 'incoming' ? ' is-active' : ''; ?>" href="/modules/inventory/index.php?stage=incoming">Waiting Supplier</a>
    <a class="btn btn-sm btn-filter<?php echo $filterStockStatus === 'low_stock' ? ' is-active' : ''; ?>" href="/modules/inventory/index.php?stock_status=low_stock">Low Stock</a>
    <a class="btn btn-sm btn-filter<?php echo $filterStockStatus === 'out_of_stock' ? ' is-active' : ''; ?>" href="/modules/inventory/index.php?stock_status=out_of_stock">Out of Stock</a>
    <a class="btn btn-sm btn-filter<?php echo $filterStage === 'arrived' ? ' is-active' : ''; ?>" href="/modules/inventory/index.php?stage=arrived">Arrived (Ready to Allocate)</a>
    <a class="btn btn-sm btn-filter<?php echo $filterProductType === 'preorder' ? ' is-active' : ''; ?>" href="/modules/inventory/index.php?product_type=preorder">Preorder</a>
    <?php if ($filterNeedsOrdering || $filterStage !== null || $filterStockStatus !== null || $filterProductType !== null): ?>
        <a class="btn btn-sm btn-outline-secondary" href="/modules/inventory/index.php">Clear quick filters</a>
    <?php endif; ?>
</div>

<div class="card filter-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo app_escape($searchTerm); ?>" placeholder="Product name, SKU, or variation SKU">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Product Type</label>
            <select name="catalog_type" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="simple" <?php echo $filterCatalogType === 'simple' ? 'selected' : ''; ?>>Simple</option>
                <option value="variable" <?php echo $filterCatalogType === 'variable' ? 'selected' : ''; ?>>Variable</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Stock Status</label>
            <select name="stock_status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="in_stock" <?php echo $filterStockStatus === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                <option value="low_stock" <?php echo $filterStockStatus === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                <option value="out_of_stock" <?php echo $filterStockStatus === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Inventory Stage</label>
            <select name="stage" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="on_hand" <?php echo $filterStage === 'on_hand' ? 'selected' : ''; ?>>On Hand</option>
                <option value="reserved" <?php echo $filterStage === 'reserved' ? 'selected' : ''; ?>>Reserved</option>
                <option value="incoming" <?php echo $filterStage === 'incoming' ? 'selected' : ''; ?>>Incoming</option>
                <option value="arrived" <?php echo $filterStage === 'arrived' ? 'selected' : ''; ?>>Arrived</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Supplier</label>
            <select name="supplier_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($filterSuppliers as $supplier): ?>
                    <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $filterSupplierId === (int) $supplier['id'] ? 'selected' : ''; ?>><?php echo app_escape($supplier['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Category</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($filterCategories as $cat): ?>
                    <option value="<?php echo (int) $cat['id']; ?>" <?php echo $filterCategoryId === $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo str_repeat('&nbsp;&nbsp;', $cat['depth']) . app_escape($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Availability Type</label>
            <select name="product_type" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($productTypeOptions as $typeOption): ?>
                    <option value="<?php echo app_escape($typeOption); ?>" <?php echo $filterProductType === $typeOption ? 'selected' : ''; ?>><?php echo app_escape($productTypeLabels[$typeOption]); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1 d-block">&nbsp;</label>
            <label class="form-check">
                <input type="checkbox" class="form-check-input" name="needs_ordering" value="1" <?php echo $filterNeedsOrdering ? 'checked' : ''; ?>>
                <span class="form-check-label small">Needs Ordering only</span>
            </label>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="/modules/inventory/index.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
    <table class="table table-hover align-middle responsive-stack-table" id="inventory-table">
        <thead>
            <tr>
                <th></th>
                <th>Product</th>
                <th>Variation</th>
                <th>SKU</th>
                <th>Type</th>
                <th>Stage</th>
                <th>On Hand</th>
                <th>Reserved</th>
                <th>Incoming</th>
                <th>Arrived</th>
                <th>Last Updated</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventory as $product): ?>
                <?php
                $isVariable = $product['catalog_type'] === 'variable';
                $productTypeLabel = $productTypeLabels[$product['product_type']] ?? $product['product_type'];
                $groupKey = 'vg-' . (int) $product['id'];
                $autoExpand = !empty($product['auto_expand']);
                $overrideValue = $product['availability_override'] ?? 'auto';
                $isOrderable = catalog_product_is_orderable($product);
                $availabilityPhrase = inventory_availability_phrase($product['product_type'], $overrideValue, $isOrderable);
                // UI/UX Phase 5B: row highlight reuses inventory_stock_badges()'s own verdict
                // (captured once, then both echoed as the badge AND used to color the row) -
                // not a second stock-status rule, the exact same function call this row already
                // made, just assigned to a variable first instead of echoed inline immediately.
                $stockBadgeHtml = !$isVariable
                    ? inventory_stock_badges($product['product_type'], $product['stock_quantity'], $product['min_stock_threshold'] !== null ? (int) $product['min_stock_threshold'] : null, $overrideValue)
                    : '';
                $rowAttentionClass = $stockBadgeHtml !== '' ? ' table-danger' : '';
                $productDeliveryKey = (int) $product['id'] . ':0';
                ?>
                <tr class="<?php echo $isVariable ? 'table-light js-inventory-parent' : $rowAttentionClass; ?>"
                    <?php if ($isVariable): ?>
                        data-group="<?php echo app_escape($groupKey); ?>" data-expanded="<?php echo $autoExpand ? '1' : '0'; ?>" style="cursor:pointer;"
                    <?php endif; ?>
                >
                    <td data-label="">
                        <?php if (!empty($product['thumb_path'])): ?>
                            <img src="/<?php echo app_escape($product['thumb_path']); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;">
                        <?php else: ?>
                            <div class="bg-light text-muted border rounded d-flex align-items-center justify-content-center" style="width:40px;height:40px;font-size:.6rem;">No image</div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Product">
                        <?php if ($isVariable): ?>
                            <span class="js-inventory-caret text-muted me-1"><?php echo $autoExpand ? '&#9660;' : '&#9654;'; ?></span>
                        <?php endif; ?>
                        <span class="fw-semibold"><?php echo app_escape($product['name']); ?></span>
                        <?php if ($isVariable): ?>
                            <span class="badge bg-info text-dark ms-1">Variable</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Variation" class="text-muted">&mdash;</td>
                    <td data-label="SKU"><?php echo app_escape($product['sku']); ?></td>
                    <td data-label="Type"><?php echo app_escape($productTypeLabel); ?></td>
                    <td data-label="Stage"><?php echo catalog_lifecycle_badge($product); ?></td>
                    <td data-label="On Hand"<?php echo $isVariable ? ' class="text-muted"' : ''; ?>>
                        <?php if ($availabilityPhrase !== null): ?>
                            <span class="text-muted"><?php echo app_escape($availabilityPhrase); ?></span>
                        <?php else: ?>
                            <?php echo app_escape((string) $product['stock_quantity']); ?>
                            <?php if (!$isVariable): ?>
                                <?php echo $stockBadgeHtml; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Reserved"<?php echo $isVariable ? ' class="text-muted"' : ''; ?>><?php echo app_escape((string) $product['reserved_stock']); ?></td>
                    <td data-label="Incoming"<?php echo $isVariable ? ' class="text-muted"' : ''; ?>>
                        <?php echo app_escape((string) $product['incoming_stock']); ?>
                        <?php if (!$isVariable && (int) $product['incoming_stock'] > 0): ?>
                            <span class="badge bg-info text-dark ms-1">Incoming</span>
                            <?php if (isset($earliestDeliveryByProductVariation[$productDeliveryKey])): ?>
                                <div class="text-muted small">Expected <?php echo app_escape($earliestDeliveryByProductVariation[$productDeliveryKey]); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Arrived"<?php echo $isVariable ? ' class="text-muted"' : ''; ?>><?php echo app_escape((string) $product['arrived_stock']); ?></td>
                    <td data-label="Last Updated" class="text-muted small"><?php echo $product['updated_at'] !== null ? app_escape($product['updated_at']) : '&mdash;'; ?></td>
                    <td data-label="" class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <?php if (!$isVariable): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="Quick View" onclick="DrawerUI.open({url: '/modules/inventory/ajax/drawer.php?product_id=<?php echo (int) $product['id']; ?>', title: '<?php echo app_escape(addslashes($product['name'])); ?>'})">&#128269;</button>
                                <?php if ($canManage): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" title="Adjust Stock" onclick="InventoryUI.openAdjustModal('<?php echo (int) $product['id']; ?>:0')">&plusmn;</button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="View History" onclick="InventoryUI.openHistoryModal(<?php echo (int) $product['id']; ?>, 0, '<?php echo app_escape(addslashes($product['sku'])); ?>')">&#128337;</button>
                            <?php endif; ?>
                            <?php if ($canManageProducts): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="/modules/products/edit.php?id=<?php echo (int) $product['id']; ?>" title="Edit Product">&#9998;</a>
                            <?php endif; ?>
                            <?php if (!$isVariable && (int) $product['arrived_stock'] > 0): ?>
                                <a class="btn btn-sm btn-outline-primary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['id']; ?>">Allocate</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if ($isVariable): ?>
                    <?php foreach ($product['variations'] as $variation): ?>
                        <?php
                        $unitKey = (int) $product['id'] . ':' . (int) $variation['variation_id'];
                        $variationThumb = variation_effective_image($pdo, (int) $product['id'], (int) $variation['variation_id']);
                        $variationBadgeHtml = inventory_stock_badges($product['product_type'], (int) $variation['stock_quantity'], $product['min_stock_threshold'] !== null ? (int) $product['min_stock_threshold'] : null, $overrideValue);
                        $variationDeliveryKey = (int) $product['id'] . ':' . (int) $variation['variation_id'];
                        ?>
                        <tr class="inventory-variation-row<?php echo $autoExpand ? '' : ' d-none'; ?><?php echo $variationBadgeHtml !== '' ? ' table-danger' : ''; ?>" data-group="<?php echo app_escape($groupKey); ?>">
                            <td data-label="">
                                <?php if ($variationThumb !== null): ?>
                                    <img src="/<?php echo app_escape($variationThumb); ?>" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:6px;">
                                <?php endif; ?>
                            </td>
                            <td data-label="Product"></td>
                            <td data-label="Variation" style="padding-left: 2rem;">
                                &#8627; <?php echo $variation['variation_label'] !== '' ? app_escape($variation['variation_label']) : '<span class="text-muted">&mdash;</span>'; ?>
                            </td>
                            <td data-label="SKU"><?php echo app_escape($variation['sku']); ?></td>
                            <td data-label="Type"></td>
                            <td data-label="Stage"></td>
                            <td data-label="On Hand">
                                <?php if ($availabilityPhrase !== null): ?>
                                    <span class="text-muted"><?php echo app_escape($availabilityPhrase); ?></span>
                                <?php else: ?>
                                    <?php echo app_escape((string) $variation['stock_quantity']); ?>
                                    <?php echo $variationBadgeHtml; ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="Reserved"><?php echo app_escape((string) $variation['reserved_stock']); ?></td>
                            <td data-label="Incoming">
                                <?php echo app_escape((string) $variation['incoming_stock']); ?>
                                <?php if ((int) $variation['incoming_stock'] > 0): ?>
                                    <span class="badge bg-info text-dark ms-1">Incoming</span>
                                    <?php if (isset($earliestDeliveryByProductVariation[$variationDeliveryKey])): ?>
                                        <div class="text-muted small">Expected <?php echo app_escape($earliestDeliveryByProductVariation[$variationDeliveryKey]); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="Arrived"><?php echo app_escape((string) $variation['arrived_stock']); ?></td>
                            <td data-label="Last Updated" class="text-muted small"><?php echo $variation['updated_at'] !== null ? app_escape($variation['updated_at']) : '&mdash;'; ?></td>
                            <td data-label="" class="text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Quick View" onclick="DrawerUI.open({url: '/modules/inventory/ajax/drawer.php?product_id=<?php echo (int) $product['id']; ?>&variation_id=<?php echo (int) $variation['variation_id']; ?>', title: '<?php echo app_escape(addslashes($product['name'])); ?>'})">&#128269;</button>
                                    <?php if ($canManage): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" title="Adjust Stock" onclick="InventoryUI.openAdjustModal('<?php echo app_escape($unitKey); ?>')">&plusmn;</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="View History" onclick="InventoryUI.openHistoryModal(<?php echo (int) $product['id']; ?>, <?php echo (int) $variation['variation_id']; ?>, '<?php echo app_escape(addslashes($variation['sku'])); ?>')">&#128337;</button>
                                    <?php if ($canManageProducts): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="/modules/products/edit.php?id=<?php echo (int) $product['id']; ?>" title="Edit Product">&#9998;</a>
                                    <?php endif; ?>
                                    <?php if ((int) $variation['arrived_stock'] > 0): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="/modules/inventory/allocate.php?product_id=<?php echo (int) $product['id']; ?>&variation_id=<?php echo (int) $variation['variation_id']; ?>">Allocate</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($product['variations'] === []): ?>
                        <tr class="inventory-variation-row<?php echo $autoExpand ? '' : ' d-none'; ?>" data-group="<?php echo app_escape($groupKey); ?>">
                            <td data-label=""></td>
                            <td data-label="" colspan="11" class="text-muted small">No active variations.</td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($inventory === []): ?>
                <tr>
                    <td colspan="12">
                        <div class="empty-state">
                            <div class="empty-state-title">No Products Match These Filters</div>
                            <p class="empty-state-text">Try adjusting or clearing your filters to see more results.</p>
                            <a class="btn btn-outline-secondary btn-sm" href="/modules/inventory/index.php">Clear filters</a>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    render_pagination('/modules/inventory/index.php', $page, $totalPages, $totalCount, $perPage, 'product');
        ?>
</div>

<?php if ($canManage): ?>
<div class="modal fade modal-fullscreen-sm-down" id="adjustStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select class="form-select" name="unit_key" id="adjust-unit-key" required>
                            <option value="">Select a product&hellip;</option>
                            <?php foreach ($sellableUnits as $unit): ?>
                                <option value="<?php echo app_escape($unit['key']); ?>">
                                    <?php echo app_escape($unit['sku']); ?> &mdash; <?php echo app_escape($unit['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Variable products don't hold stock themselves - pick the specific variation to adjust.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select class="form-select" name="adjustment_type" required>
                            <option value="increase">Increase</option>
                            <option value="decrease">Decrease</option>
                            <option value="set_exact">Set Exact Quantity</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control" name="quantity" min="0" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <select class="form-select" name="reason" required>
                            <option value="">Select a reason&hellip;</option>
                            <?php foreach ($adjustmentReasons as $reasonOption): ?>
                                <option value="<?php echo app_escape($reasonOption); ?>"><?php echo app_escape($reasonOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Notes (optional)</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade modal-fullscreen-sm-down" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="history-modal-title">Transaction History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <input type="text" class="form-control form-control-sm" id="history-search" placeholder="Search reason, notes, reference&hellip;">
                    </div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" id="history-type-filter">
                            <option value="">All transaction types</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" id="history-filter-apply">Filter</button>
                    </div>
                </div>
                <div id="history-body">
                    <p class="text-muted">Loading&hellip;</p>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="history-prev">&larr; Newer</button>
                    <span class="text-muted small" id="history-page-info"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="history-next">Older &rarr;</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$inventoryJsPath = __DIR__ . '/../../assets/js/inventory.js';
$inventoryJsVersion = is_file($inventoryJsPath) ? filemtime($inventoryJsPath) : time();
?>
<script src="/assets/js/inventory.js?v=<?php echo (int) $inventoryJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
