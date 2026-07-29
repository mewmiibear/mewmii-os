<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/wc_client.php';
require_once __DIR__ . '/../../includes/pricing_engine.php';
require_once __DIR__ . '/../../includes/product_image_queue.php';
app_require_permission('products.manage');

$appTitle = 'Edit Product';
$error = '';
$pdo = app_db();
$canManage = true;
$isEdit = true;

// Phase 7C.1 (Product Cost Data Entry) - same convention as
// modules/products/create.php/modules/supplier-orders/create.php's currency dropdown.
const PRODUCT_COST_CURRENCY_OPTIONS = ['MYR', 'JPY', 'CNY', 'USD', 'EUR', 'GBP'];

$productId = (int) ($_GET['id'] ?? 0);

if ($productId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
$productStmt->execute([$productId]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// --- TEMPORARY DEBUG INSTRUMENTATION (stale "Waiting Release" badge trace) -------------
// Gated behind ?debug_lifecycle=1, which reopen_preorder.php's redirect now sets automatically.
// Shows, side by side: (1) the values reopen_preorder.php verified immediately after its own
// UPDATE, carried here via the redirect query string (dbg_* params), and (2) this page's own,
// completely independent SELECT above (line ~29-31) for the exact same product/fields, plus
// (3) the lifecycle stage catalog_product_lifecycle_stage() computes from that fresh row right
// now. If (1) and (2) disagree, the UPDATE isn't persisting/reopen_preorder.php's WHERE clause
// isn't matching. If (1) and (2) agree but (3) is still "waiting_release", the bug is inside
// catalog_product_lifecycle_stage() itself, not the data. Remove this block (and the matching
// ones in reopen_preorder.php/_form.php/view.php) once the mismatch is found.
if (isset($_GET['debug_lifecycle'])) {
    $debugComputedStage = catalog_product_lifecycle_stage($product);
    error_log(sprintf(
        '[LIFECYCLE-DEBUG edit.php] product_id=%d fresh_select: product_type=%s preorder_closing_date=%s preorder_reopened_at=%s status=%s availability_override=%s computed_stage=%s',
        $productId,
        $product['product_type'] ?? 'NULL',
        $product['preorder_closing_date'] ?? 'NULL',
        $product['preorder_reopened_at'] ?? 'NULL',
        $product['status'] ?? 'NULL',
        $product['availability_override'] ?? 'NULL',
        $debugComputedStage
    ));
    echo '<pre style="background:#111;color:#0f0;padding:1rem;white-space:pre-wrap;font-size:.85rem;">';
    echo "[LIFECYCLE-DEBUG] product_id={$productId}\n\n";
    echo "-- carried from reopen_preorder.php's post-UPDATE verify SELECT (same request as the UPDATE) --\n";
    echo 'dbg_rows (UPDATE affected_rows): ' . var_export($_GET['dbg_rows'] ?? '(not set - page not reached via Open Preorder redirect)', true) . "\n";
    echo 'dbg_type: ' . var_export($_GET['dbg_type'] ?? null, true) . "\n";
    echo 'dbg_closing: ' . var_export($_GET['dbg_closing'] ?? null, true) . "\n";
    echo 'dbg_reopened_at: ' . var_export($_GET['dbg_reopened_at'] ?? null, true) . "\n";
    echo "\n-- this page's own fresh SELECT * FROM products WHERE id = ? (independent request) --\n";
    echo 'status: ' . var_export($product['status'] ?? null, true) . "\n";
    echo 'availability_override: ' . var_export($product['availability_override'] ?? null, true) . "\n";
    echo 'product_type: ' . var_export($product['product_type'] ?? null, true) . "\n";
    echo 'preorder_closing_date: ' . var_export($product['preorder_closing_date'] ?? null, true) . "\n";
    echo 'preorder_reopened_at: ' . var_export($product['preorder_reopened_at'] ?? null, true) . "\n";
    echo 'estimated_release_month (NOT read by catalog_product_lifecycle_stage()): ' . var_export($product['estimated_release_month'] ?? null, true) . "\n";
    echo "\ncomputed stage (catalog_product_lifecycle_stage() called on this exact fresh row): " . var_export($debugComputedStage, true) . "\n";
    echo '</pre>';
}
// --- END TEMPORARY DEBUG INSTRUMENTATION ------------------------------------------------

// Older rows predating these columns won't have the keys at all (not just NULL values),
// so ?? is required here rather than a plain null check.
$product['sale_enabled'] = $product['sale_enabled'] ?? 0;
$product['sale_price'] = $product['sale_price'] ?? null;
$product['min_stock_threshold'] = $product['min_stock_threshold'] ?? null;
$product['preorder_closing_date'] = $product['preorder_closing_date'] ?? null;
$product['preorder_reopened_at'] = $product['preorder_reopened_at'] ?? null;
$product['availability_override'] = $product['availability_override'] ?? 'auto';
$product['cost_currency'] = $product['cost_currency'] ?? null;
$product['exchange_rate'] = $product['exchange_rate'] ?? null;
// Phase 9D/9F/9F.1/9F.2/9G (Pricing Engine) - older rows predate these columns, same ??
// requirement as above. products.market_price is left unused (Phase 9G - Market Price is
// calculated from Original Price, no separate amount input exists).
$product['original_price'] = $product['original_price'] ?? null;
$product['original_currency'] = $product['original_currency'] ?? null;
$product['market_currency'] = $product['market_currency'] ?? null;
$product['weight_grams'] = $product['weight_grams'] ?? null;
$product['shipping_origin_country_id'] = $product['shipping_origin_country_id'] ?? null;

$catalogTypes = ['simple', 'variable'];
$productTypes = ['ready_stock', 'preorder', 'early_bird'];
$availabilityOverrideOptions = ['auto', 'available', 'out_of_stock'];

$baseStatusOptions = ['draft', 'active', 'hidden', 'archived'];
$statusOptions = in_array($product['status'], $baseStatusOptions, true)
    ? $baseStatusOptions
    : array_merge($baseStatusOptions, [$product['status']]);

$currentStock = product_effective_stock($pdo, $productId);
$lowStock = $product['product_type'] === 'ready_stock'
    && $product['min_stock_threshold'] !== null
    && (int) $currentStock['available_quantity'] < (int) $product['min_stock_threshold'];

$form = [
    'catalog_type' => $product['catalog_type'] ?? 'simple',
    'name' => $product['name'],
    'sku' => $product['sku'],
    'barcode' => (string) ($product['barcode'] ?? ''),
    'supplier_sku' => (string) ($product['supplier_sku'] ?? ''),
    'internal_code' => (string) ($product['internal_code'] ?? ''),
    'short_description' => (string) ($product['short_description'] ?? ''),
    'description' => (string) $product['description'],
    'brand_id' => $product['brand_id'] !== null ? (string) $product['brand_id'] : '',
    'category_id' => (string) (catalog_get_product_category_id($pdo, $productId) ?? ''),
    'collection_id' => (string) (catalog_get_product_collection_id($pdo, $productId) ?? ''),
    'supplier_id' => $product['supplier_id'] !== null ? (string) $product['supplier_id'] : '',
    'product_type' => $product['product_type'],
    'status' => $product['status'],
    'availability_override' => $product['availability_override'],
    'product_cost' => (string) $product['product_cost'],
    // Phase 7C.1 (Product Cost Data Entry) - NULL cost_currency means "already in the base
    // currency" (see includes/product_cost.php), shown as MYR selected with nothing in the
    // free-text box - same "known value or OTHER + free text" pattern already used by
    // modules/supplier-orders/edit.php's currency field.
    'cost_currency' => $product['cost_currency'] === null || in_array($product['cost_currency'], PRODUCT_COST_CURRENCY_OPTIONS, true)
        ? ($product['cost_currency'] ?? SYSTEM_BASE_CURRENCY)
        : 'OTHER',
    'cost_currency_other' => $product['cost_currency'] !== null && !in_array($product['cost_currency'], PRODUCT_COST_CURRENCY_OPTIONS, true)
        ? $product['cost_currency']
        : '',
    'exchange_rate' => $product['exchange_rate'] !== null ? (string) $product['exchange_rate'] : '',
    'selling_price' => (string) $product['selling_price'],
    'sale_enabled' => (bool) $product['sale_enabled'],
    'sale_price' => $product['sale_price'] !== null ? (string) $product['sale_price'] : '',
    'sale_start_date' => (string) ($product['sale_start_date'] ?? ''),
    'has_expiry' => !empty($product['expiry_date']),
    'expiry_date' => (string) ($product['expiry_date'] ?? ''),
    'stock_quantity' => (string) (int) $currentStock['available_quantity'],
    'min_stock_threshold' => $product['min_stock_threshold'] !== null ? (string) $product['min_stock_threshold'] : '',
    'target_stock_level' => $product['target_stock_level'] !== null ? (string) $product['target_stock_level'] : '',
    'estimated_arrival_date' => (string) ($product['estimated_arrival_date'] ?? ''),
    'estimated_release_month' => (string) ($product['estimated_release_month'] ?? ''),
    'moq' => (string) $product['moq'],
    'preorder_closing_date' => (string) ($product['preorder_closing_date'] ?? ''),
    // Phase 9D/9F/9F.1/9F.2/9G (Pricing Engine) - only raw amounts/currencies are editable on
    // this form; exchange rates are never entered anywhere (see includes/currency_rates.php).
    // Market Price has no amount input (Phase 9G - calculated from Original Price). Same
    // "known value or OTHER + free text" pattern as cost_currency above.
    'original_price' => $product['original_price'] !== null ? (string) $product['original_price'] : '',
    'original_currency' => $product['original_currency'] === null || in_array($product['original_currency'], CURRENCY_RATE_OPTIONS, true)
        ? ($product['original_currency'] ?? SYSTEM_BASE_CURRENCY)
        : 'OTHER',
    'original_currency_other' => $product['original_currency'] !== null && !in_array($product['original_currency'], CURRENCY_RATE_OPTIONS, true)
        ? $product['original_currency']
        : '',
    'market_currency' => $product['market_currency'] === null || in_array($product['market_currency'], CURRENCY_RATE_OPTIONS, true)
        ? ($product['market_currency'] ?? SYSTEM_BASE_CURRENCY)
        : 'OTHER',
    'market_currency_other' => $product['market_currency'] !== null && !in_array($product['market_currency'], CURRENCY_RATE_OPTIONS, true)
        ? $product['market_currency']
        : '',
    'weight_grams' => $product['weight_grams'] !== null ? (string) $product['weight_grams'] : '',
    'shipping_origin_country_id' => $product['shipping_origin_country_id'] !== null ? (string) $product['shipping_origin_country_id'] : '',
];
$selectedTagIds = catalog_get_product_tag_ids($pdo, $productId);

$costCurrencyOptions = [SYSTEM_BASE_CURRENCY];
foreach (currency_rates_list_by_currency($pdo) as $currencyCode => $_currencyRows) {
    $costCurrencyOptions[] = $currencyCode;
}
$costCurrencyOptions = array_values(array_unique($costCurrencyOptions));

$currencyOptions = [SYSTEM_BASE_CURRENCY];
foreach (currency_rates_list_by_currency($pdo) as $currencyCode => $_currencyRows) {
    $currencyOptions[] = $currencyCode;
}
$currencyOptions = array_values(array_unique($currencyOptions));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- TEMPORARY TIMING INSTRUMENTATION (product save performance audit) ----------------
    // Same instrumentation as modules/products/create.php - see that file's own comment for
    // the full explanation. Note: attribute save/variation generation are NOT part of this
    // page's save flow (edit mode manages variations via separate AJAX endpoints - see
    // modules/products/_form.php's own docblock), so only the marks that actually apply here
    // are used.
    $__timingStart = microtime(true);
    $__timings = [];
    $__mark = static function (string $label) use (&$__timings, $__timingStart): void {
        $__timings[$label] = microtime(true) - $__timingStart;
    };
    $__mark('form_submit_start');
    // --- END TEMPORARY TIMING INSTRUMENTATION (setup only - marks continue below) ---------

    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['catalog_type'] = (string) ($_POST['catalog_type'] ?? $form['catalog_type']);
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['sku'] = trim((string) ($_POST['sku'] ?? ''));
    $form['barcode'] = trim((string) ($_POST['barcode'] ?? ''));
    $form['supplier_sku'] = trim((string) ($_POST['supplier_sku'] ?? ''));
    $form['internal_code'] = trim((string) ($_POST['internal_code'] ?? ''));
    $form['short_description'] = trim((string) ($_POST['short_description'] ?? ''));
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['brand_id'] = trim((string) ($_POST['brand_id'] ?? ''));
    $form['category_id'] = trim((string) ($_POST['category_id'] ?? ''));
    $form['collection_id'] = trim((string) ($_POST['collection_id'] ?? ''));
    $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));
    $form['product_type'] = (string) ($_POST['product_type'] ?? 'ready_stock');
    $form['status'] = (string) ($_POST['status'] ?? 'draft');
    $form['availability_override'] = (string) ($_POST['availability_override'] ?? 'auto');
    $form['product_cost'] = trim((string) ($_POST['product_cost'] ?? ''));
    $form['cost_currency'] = trim((string) ($_POST['cost_currency'] ?? SYSTEM_BASE_CURRENCY));
    $form['cost_currency_other'] = trim((string) ($_POST['cost_currency_other'] ?? ''));
    $form['exchange_rate'] = trim((string) ($_POST['exchange_rate'] ?? ''));
    $form['selling_price'] = trim((string) ($_POST['selling_price'] ?? ''));
    $form['sale_enabled'] = !empty($_POST['sale_enabled']);
    $form['sale_price'] = trim((string) ($_POST['sale_price'] ?? ''));
    $form['sale_start_date'] = trim((string) ($_POST['sale_start_date'] ?? ''));
    $form['has_expiry'] = !empty($_POST['has_expiry']);
    $form['expiry_date'] = trim((string) ($_POST['expiry_date'] ?? ''));
    $form['stock_quantity'] = trim((string) ($_POST['stock_quantity'] ?? ''));
    $form['min_stock_threshold'] = trim((string) ($_POST['min_stock_threshold'] ?? ''));
    $form['target_stock_level'] = trim((string) ($_POST['target_stock_level'] ?? ''));
    $form['estimated_arrival_date'] = trim((string) ($_POST['estimated_arrival_date'] ?? ''));
    $form['estimated_release_month'] = trim((string) ($_POST['estimated_release_month'] ?? ''));
    $form['moq'] = trim((string) ($_POST['moq'] ?? '1'));
    $form['preorder_closing_date'] = trim((string) ($_POST['preorder_closing_date'] ?? ''));
    $selectedTagIds = array_map('intval', $_POST['tag_ids'] ?? []);

    // Phase 9D/9F/9F.1/9F.2/9G (Pricing Engine)
    $form['original_price'] = trim((string) ($_POST['original_price'] ?? ''));
    $form['original_currency'] = trim((string) ($_POST['original_currency'] ?? SYSTEM_BASE_CURRENCY));
    $form['original_currency_other'] = trim((string) ($_POST['original_currency_other'] ?? ''));
    $form['market_currency'] = trim((string) ($_POST['market_currency'] ?? SYSTEM_BASE_CURRENCY));
    $form['market_currency_other'] = trim((string) ($_POST['market_currency_other'] ?? ''));
    $form['weight_grams'] = trim((string) ($_POST['weight_grams'] ?? ''));
    $form['shipping_origin_country_id'] = trim((string) ($_POST['shipping_origin_country_id'] ?? ''));

    if ($error === '') {
        if ($form['sku'] === '' || strlen($form['sku']) > 100) {
            $error = 'SKU is required and must be 100 characters or fewer.';
        } elseif ($form['name'] === '' || strlen($form['name']) > 255) {
            $error = 'Name is required and must be 255 characters or fewer.';
        } elseif (strlen($form['short_description']) > 500) {
            $error = 'Short description must be 500 characters or fewer.';
        } elseif (!in_array($form['catalog_type'], $catalogTypes, true)) {
            $error = 'Invalid product structure (simple/variable).';
        } elseif (!in_array($form['product_type'], $productTypes, true)) {
            $error = 'Invalid availability type.';
        } elseif (!in_array($form['status'], $statusOptions, true)) {
            $error = 'Invalid status.';
        } elseif (!in_array($form['availability_override'], $availabilityOverrideOptions, true)) {
            $error = 'Invalid availability override.';
        } elseif (!is_numeric($form['product_cost']) || (float) $form['product_cost'] < 0) {
            $error = 'Cost price must be a valid non-negative number.';
        } elseif (!is_numeric($form['selling_price']) || (float) $form['selling_price'] < 0) {
            $error = 'Selling price must be a valid non-negative number.';
        } elseif ($form['sale_enabled'] && (!is_numeric($form['sale_price']) || (float) $form['sale_price'] < 0)) {
            $error = 'Enter a valid sale price, or disable Enable Sale.';
        } elseif ($form['estimated_release_month'] !== '' && !preg_match('/^\d{4}-\d{2}$/', $form['estimated_release_month'])) {
            $error = 'Estimated Release Month must be a valid month.';
        }
    }

    // Phase 7C.1 (Product Cost Data Entry) - Supplier Currency code validity only. Phase
    // 9F.1 removed exchange rate entry everywhere - a foreign cost currency automatically
    // gets its rate from includes/currency_rates.php (see currency_rates_sync_product_
    // exchange_rate() below), never a manual value blocking save.
    $costCurrency = $form['cost_currency'] === 'OTHER' ? strtoupper($form['cost_currency_other']) : strtoupper($form['cost_currency']);
    if ($error === '') {
        if ($form['cost_currency'] === 'OTHER' && ($costCurrency === '' || strlen($costCurrency) > 10)) {
            $error = 'Enter a valid cost currency code (up to 10 characters).';
        } elseif ($form['cost_currency'] !== 'OTHER' && !in_array($form['cost_currency'], $costCurrencyOptions, true)) {
            $error = 'Invalid cost currency.';
        }
    }

    // Phase 9D/9F/9F.1 (Pricing Engine) - same shape as modules/products/create.php. Only
    // amounts/currencies are validated here; rates are never entered anywhere.
    $originalCurrency = $form['original_currency'] === 'OTHER' ? strtoupper($form['original_currency_other']) : strtoupper($form['original_currency']);
    if ($error === '' && $form['original_price'] !== '') {
        if (!is_numeric($form['original_price']) || (float) $form['original_price'] < 0) {
            $error = 'Original Price must be a valid non-negative number.';
        } elseif ($form['original_currency'] === 'OTHER' && ($originalCurrency === '' || strlen($originalCurrency) > 10)) {
            $error = 'Enter a valid Original Currency code (up to 10 characters).';
        } elseif ($form['original_currency'] !== 'OTHER' && !in_array($form['original_currency'], $currencyOptions, true)) {
            $error = 'Invalid Original Currency.';
        }
    }

    // Phase 9G - Market Price has no amount input at all (calculated from Original Price) -
    // only the currency selection is validated here.
    $marketCurrency = $form['market_currency'] === 'OTHER' ? strtoupper($form['market_currency_other']) : strtoupper($form['market_currency']);
    if ($error === '') {
        if ($form['market_currency'] === 'OTHER' && ($marketCurrency === '' || strlen($marketCurrency) > 10)) {
            $error = 'Enter a valid Market Currency code (up to 10 characters).';
        } elseif ($form['market_currency'] !== 'OTHER' && !in_array($form['market_currency'], $currencyOptions, true)) {
            $error = 'Invalid Market Currency.';
        }
    }

    if ($error === '' && $form['weight_grams'] !== '' && (!is_numeric($form['weight_grams']) || (float) $form['weight_grams'] < 0)) {
        $error = 'Weight cannot be negative.';
    }

    $shippingOriginCountryId = null;
    if ($error === '' && $form['shipping_origin_country_id'] !== '') {
        $shippingOriginCountryId = (int) $form['shipping_origin_country_id'];
        $check = $pdo->prepare('SELECT COUNT(*) FROM shipping_rate_countries WHERE id = ?');
        $check->execute([$shippingOriginCountryId]);
        if ((int) $check->fetchColumn() === 0) {
            $error = 'Selected shipping origin does not exist.';
        }
    }

    $supplierId = null;
    if ($error === '' && $form['supplier_id'] !== '') {
        $supplierId = (int) $form['supplier_id'];
        $check = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id = ?');
        $check->execute([$supplierId]);
        if ((int) $check->fetchColumn() === 0) {
            $error = 'Selected supplier does not exist.';
        }
    }

    $brandId = null;
    if ($error === '' && $form['brand_id'] !== '') {
        $brandId = (int) $form['brand_id'];
        $check = $pdo->prepare('SELECT COUNT(*) FROM brands WHERE id = ?');
        $check->execute([$brandId]);
        if ((int) $check->fetchColumn() === 0) {
            $error = 'Selected brand does not exist.';
        }
    }

    $categoryId = null;
    if ($error === '' && $form['category_id'] !== '') {
        $categoryId = (int) $form['category_id'];
        $check = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
        $check->execute([$categoryId]);
        if ((int) $check->fetchColumn() === 0) {
            $error = 'Selected category does not exist.';
        }
    }

    $collectionId = null;
    if ($error === '' && $form['collection_id'] !== '') {
        $collectionId = (int) $form['collection_id'];
        $check = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE id = ?');
        $check->execute([$collectionId]);
        if ((int) $check->fetchColumn() === 0) {
            $error = 'Selected collection does not exist.';
        }
    }

    if ($error === '') {
        $skuCheck = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ? AND id != ?');
        $skuCheck->execute([$form['sku'], $productId]);
        if ((int) $skuCheck->fetchColumn() > 0) {
            $error = 'SKU already exists.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $newClosingDate = $form['preorder_closing_date'] !== '' ? $form['preorder_closing_date'] : null;

            // Reopening is scoped to one closing-date cycle: if the closing date itself is
            // being changed (a new Early Bird cycle), a stale reopen from the previous cycle
            // must not carry over - the new cycle needs its own fresh manual reopen. If the
            // closing date is untouched, preserve whatever reopened state already exists.
            $preorderReopenedAt = $newClosingDate === $product['preorder_closing_date']
                ? $product['preorder_reopened_at']
                : null;

            $stmt = $pdo->prepare('
                UPDATE products
                SET sku = ?, name = ?, short_description = ?, description = ?, product_type = ?, catalog_type = ?, brand_id = ?, barcode = ?,
                    supplier_sku = ?, internal_code = ?,
                    supplier_id = ?, product_cost = ?, cost_currency = ?, selling_price = ?, sale_enabled = ?, sale_price = ?,
                    min_stock_threshold = ?, target_stock_level = ?, sale_start_date = ?, estimated_arrival_date = ?, estimated_release_month = ?,
                    preorder_closing_date = ?, preorder_reopened_at = ?, expiry_date = ?, moq = ?, status = ?, availability_override = ?,
                    original_price = ?, original_currency = ?, market_currency = ?, weight_grams = ?, shipping_origin_country_id = ?
                WHERE id = ?
            ');
            // Phase 9G - exchange_rate/original_exchange_rate/market_exchange_rate/
            // selling_multiplier are deliberately NOT in this UPDATE. Exchange rates are never
            // entered anywhere (auto-synced below instead). market_price is also never set -
            // Market Price is calculated from Original Price (see includes/pricing_engine.php),
            // there is no separate amount column to write. Weight/Shipping Origin ARE part of
            // this form again now that the separate Pricing tab was removed.
            $stmt->execute([
                $form['sku'],
                $form['name'],
                $form['short_description'] !== '' ? $form['short_description'] : null,
                $form['description'] !== '' ? $form['description'] : null,
                $form['product_type'],
                $form['catalog_type'],
                $brandId,
                $form['barcode'] !== '' ? $form['barcode'] : null,
                $form['supplier_sku'] !== '' ? $form['supplier_sku'] : null,
                $form['internal_code'] !== '' ? $form['internal_code'] : null,
                $supplierId,
                round((float) $form['product_cost'], 2),
                $costCurrency !== SYSTEM_SELLING_CURRENCY ? $costCurrency : null,
                round((float) $form['selling_price'], 2),
                $form['sale_enabled'] ? 1 : 0,
                ($form['sale_enabled'] && $form['sale_price'] !== '') ? round((float) $form['sale_price'], 2) : null,
                $form['min_stock_threshold'] !== '' ? (int) $form['min_stock_threshold'] : null,
                $form['target_stock_level'] !== '' ? (int) $form['target_stock_level'] : null,
                $form['sale_start_date'] !== '' ? $form['sale_start_date'] : null,
                $form['estimated_arrival_date'] !== '' ? $form['estimated_arrival_date'] : null,
                $form['estimated_release_month'] !== '' ? $form['estimated_release_month'] : null,
                $newClosingDate,
                $preorderReopenedAt,
                ($form['has_expiry'] && $form['expiry_date'] !== '') ? $form['expiry_date'] : null,
                $form['moq'] !== '' ? max(1, (int) $form['moq']) : 1,
                $form['status'],
                $form['availability_override'],
                $form['original_price'] !== '' ? round((float) $form['original_price'], 2) : null,
                $form['original_price'] !== '' && $originalCurrency !== SYSTEM_SELLING_CURRENCY ? $originalCurrency : null,
                $marketCurrency !== SYSTEM_SELLING_CURRENCY ? $marketCurrency : null,
                $form['weight_grams'] !== '' ? round((float) $form['weight_grams'], 2) : null,
                $shippingOriginCountryId,
                $productId,
            ]);

            // Phase 9F.1 - exchange_rate is never entered manually; it's auto-derived from
            // the centrally-managed currency_rates table right after every save, so
            // includes/product_cost.php's actual Landed Cost engine (untouched) always reads
            // an up-to-date rate.
            currency_rates_sync_product_exchange_rate($pdo, $productId, $costCurrency !== SYSTEM_SELLING_CURRENCY ? $costCurrency : null);

            catalog_sync_product_category($pdo, $productId, $categoryId);
            catalog_sync_product_collection($pdo, $productId, $collectionId);
            catalog_sync_product_tag_ids($pdo, $productId, $selectedTagIds);

            // Phase 9E (Product Weight & Variation SKU Logic) - converting variable -> simple
            // no longer requires manually archiving variations first; they're archived here
            // automatically instead of being deleted. Weight, Supplier SKU, pricing fields, and
            // every order/supplier-order/inventory-transaction/customer-storage row that
            // references variation_id are left untouched - only status/archived_at change, so
            // historical transactions keep showing the exact same variation data as before.
            if ($product['catalog_type'] === 'variable' && $form['catalog_type'] === 'simple') {
                variation_archive_all_for_product($pdo, $productId);
            }
            $__mark('database_product_update'); // TEMPORARY TIMING INSTRUMENTATION

            // Images: normal AJAX handles the "instant" experience in the browser, but the
            // plain form submit still applies these directly too (progressive enhancement -
            // works without JS, just with a full page reload). Removal and reorder/delete are
            // cheap DB-only operations (no GD/compression involved) and stay synchronous
            // unchanged - only a NEW upload's heavy resize+WebP work is deferred (see
            // image_upload_stage()'s own docblock).
            if (!empty($_POST['remove_main_image'])) {
                product_image_remove_main($pdo, $productId);
            }
            // --- TEMPORARY UPLOAD TIMING INSTRUMENTATION (image compression already queued;
            // investigating whether the remaining save-time delay on image uploads is the HTTP
            // upload itself, move_uploaded_file(), or something else). error_log only, no
            // response/behavior change. Remove alongside the step-timing block below once the
            // bottleneck is identified. See the identical block in modules/products/create.php.
            $__uploadFileCount = 0;
            $__uploadTotalBytes = 0;
            $__uploadMoveDurationsMs = [];
            // --- END SETUP - populated inline in the staging loop below -----------------------
            $pendingImages = [];
            if (!empty($_FILES['main_image']['name'])) {
                $__uploadFileCount++;
                $__uploadTotalBytes += (int) ($_FILES['main_image']['size'] ?? 0);
                $__moveStart = microtime(true); // TEMPORARY UPLOAD TIMING INSTRUMENTATION
                $stagedPath = image_upload_stage($_FILES['main_image'], 'products');
                $__uploadMoveDurationsMs[] = round((microtime(true) - $__moveStart) * 1000, 1); // TEMPORARY UPLOAD TIMING INSTRUMENTATION
                $pendingImages[] = ['role' => 'main', 'staged_path' => $stagedPath];
            }
            $galleryFiles = image_upload_normalize_multi($_FILES['gallery_images'] ?? []);
            foreach ($galleryFiles as $galleryFile) {
                $__uploadFileCount++;
                $__uploadTotalBytes += (int) ($galleryFile['size'] ?? 0);
                $__moveStart = microtime(true); // TEMPORARY UPLOAD TIMING INSTRUMENTATION
                $stagedPath = image_upload_stage($galleryFile, 'products');
                $__uploadMoveDurationsMs[] = round((microtime(true) - $__moveStart) * 1000, 1); // TEMPORARY UPLOAD TIMING INSTRUMENTATION
                $pendingImages[] = ['role' => 'gallery', 'staged_path' => $stagedPath];
            }
            if ($pendingImages !== []) {
                product_image_enqueue_processing($pdo, $productId, $pendingImages);
            }

            // --- TEMPORARY UPLOAD TIMING INSTRUMENTATION - log line ---------------------------
            // See modules/products/create.php's identical block for the full explanation of
            // request_time_float_to_script_start_ms's meaning and limits.
            if ($__uploadFileCount > 0) {
                $__requestTimeFloat = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? $__timingStart);
                error_log(sprintf(
                    '[product-upload-timing][edit] product_id=%d file_count=%d total_upload_mb=%.2f avg_file_size_kb=%.1f move_uploaded_file_ms=[%s] request_time_float_to_script_start_ms=%d',
                    $productId,
                    $__uploadFileCount,
                    $__uploadTotalBytes / 1024 / 1024,
                    ($__uploadTotalBytes / $__uploadFileCount) / 1024,
                    implode(',', $__uploadMoveDurationsMs),
                    (int) round(($__timingStart - $__requestTimeFloat) * 1000)
                ));
            }
            // --- END TEMPORARY UPLOAD TIMING INSTRUMENTATION -----------------------------------

            $gallerySortOrders = $_POST['gallery_sort_order'] ?? [];
            $galleryDeleteIds = $_POST['gallery_delete'] ?? [];
            if ($gallerySortOrders !== [] || $galleryDeleteIds !== []) {
                product_image_update_gallery($pdo, $productId, $gallerySortOrders, $galleryDeleteIds);
            }
            $__mark('image_processing'); // TEMPORARY TIMING INSTRUMENTATION (stock init below is folded into the next mark)

            // Simple product stock: only settable for ready_stock, never for preorder/
            // early_bird regardless of what was posted.
            if ($form['catalog_type'] === 'simple' && $form['product_type'] === 'ready_stock' && $form['stock_quantity'] !== '' && is_numeric($form['stock_quantity'])) {
                $targetStock = max(0, (int) $form['stock_quantity']);
                $row = inventory_get_or_create_row($pdo, $productId, null);
                $delta = $targetStock - (int) $row['available_quantity'];
                if ($delta !== 0) {
                    $pdo->prepare('UPDATE mewmii_inventory SET available_quantity = ? WHERE product_id = ? AND variation_id IS NULL')
                        ->execute([$targetStock, $productId]);
                    inventory_log_transaction($pdo, $productId, 'manual_adjustment', $delta, 'product_edit', $productId, null);
                }
            }

            $pdo->commit();

            // Strictly after commit, never inside the transaction above - a WooCommerce push
            // can't be undone by a later rollback, so it must only ever happen once the local
            // save is truly final.
            //
            // Phase 11A (Unified Outbound Job Queue) - queues the push instead of running it
            // inline (see the identical comment in modules/products/create.php). The fingerprint
            // gate inside wc_client_sync_if_changed() still means a save with nothing
            // WooCommerce-relevant changed costs the worker nothing beyond one cheap check.
            $wcSyncStatus = '';
            if (wc_client_auto_sync_enabled($pdo)) {
                wc_client_enqueue_product_sync($pdo, $productId);
                $wcSyncStatus = '&wc_sync=queued';
            }
            // A queued image job will enqueue its own WooCommerce sync once processing finishes
            // (see product_image_process_pending_job()), so this flag is purely informational -
            // it never duplicates or replaces the wc_sync flag above.
            $imagesQueuedStatus = $pendingImages !== [] ? '&images_queued=1' : '';
            $__mark('outbound_jobs_creation'); // TEMPORARY TIMING INSTRUMENTATION (same call as wc_sync_enqueue post-Phase-11A - see includes/wc_client.php's wc_client_enqueue_product_sync())

            // --- TEMPORARY TIMING INSTRUMENTATION - final log line, right before redirect ---
            $__mark('response_redirect_start');
            $__prevElapsed = 0.0;
            $__parts = [];
            foreach ($__timings as $__label => $__elapsed) {
                $__parts[] = sprintf('%s=+%dms(total %dms)', $__label, (int) round(($__elapsed - $__prevElapsed) * 1000), (int) round($__elapsed * 1000));
                $__prevElapsed = $__elapsed;
            }
            error_log('[product-save-timing][edit] product_id=' . $productId . ' ' . implode(' ', $__parts));
            // --- END TEMPORARY TIMING INSTRUMENTATION ---------------------------------------

            app_redirect('/modules/products/edit.php?id=' . $productId . '&updated=1' . $wcSyncStatus . $imagesQueuedStatus);
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to update product.';
        }
    }
}

$brands = catalog_list_brands($pdo);
$categoriesTree = catalog_list_categories_tree($pdo);
$collections = catalog_list_collections($pdo);
$tags = catalog_list_tags($pdo);
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
$attributes = array_map(static function (array $attribute) use ($pdo): array {
    $attribute['values'] = catalog_list_attribute_values($pdo, (int) $attribute['id']);

    return $attribute;
}, catalog_list_attributes($pdo));

$existingAssignments = [];
foreach (catalog_get_product_attribute_assignments($pdo, $productId) as $assignment) {
    $existingAssignments[] = [
        'attributeId' => (int) $assignment['attribute_id'],
        'isVariation' => (bool) $assignment['is_variation_attribute'],
        'valueIds' => catalog_get_assignment_value_ids($pdo, (int) $assignment['assignment_id']),
    ];
}

$variations = $product['catalog_type'] === 'variable' ? variation_list_for_product($pdo, $productId) : [];

// Computed server-side (not in JS from raw available_quantity) since availability depends
// on the PARENT product's type/override/lifecycle state, none of which the variation table
// otherwise has access to - see catalog_product_availability_status(). Every variation
// shares the same parent, so this is purchasable/not-purchasable, never a per-variation
// quantity check for preorder/early_bird.
foreach ($variations as &$variation) {
    $variation['is_available'] = catalog_product_availability_status($product, (int) $variation['available_quantity']) === 'available';
}
unset($variation);

$mainImage = product_image_get_main($pdo, $productId);
$galleryImages = product_image_list_gallery($pdo, $productId);

// Phase 9G (Inline Pricing & Inventory Calculation UI) - feeds the inline calculator's live
// JS (see _form.php); the JS itself computes the initial display from these same rate maps
// and the form's own pre-filled values on load, so there's no separate server-computed
// preview to keep in sync with it.
$shippingCountries = pricing_list_shipping_rate_countries($pdo);
$currencyRateMaps = currency_rates_all_maps($pdo);

require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_form.php';
require_once __DIR__ . '/../../includes/footer.php';
