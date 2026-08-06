<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/wc_client.php';
require_once __DIR__ . '/../../includes/pricing_engine.php';
require_once __DIR__ . '/../../includes/product_image_queue.php';
app_require_permission('products.manage');

$appTitle = 'Add Product';
$error = '';
$pdo = app_db();
$canManage = true;

// Phase 7C.1 (Product Cost Data Entry) - same dropdown-plus-"Other" convention already used
// by modules/supplier-orders/create.php's SUPPLIER_ORDER_CURRENCY_OPTIONS. A product's cost
// currency is a separate field from supplier order currency, but both dropdowns use the
// configured currency settings list.

$isEdit = false;
$productId = null;
$product = null;
$existingAssignments = [];
$variations = [];
$mainImage = null;
$galleryImages = [];
$lowStock = false;
$statusOptions = ['draft', 'active', 'hidden', 'archived'];
$catalogTypes = ['simple', 'variable'];
$productTypes = ['ready_stock', 'preorder', 'early_bird'];
$availabilityOverrideOptions = ['auto', 'available', 'out_of_stock'];

$form = [
    'catalog_type' => 'simple',
    'name' => '',
    'sku' => '',
    'barcode' => '',
    'supplier_sku' => '',
    'internal_code' => '',
    'short_description' => '',
    'description' => '',
    'brand_id' => '',
    'category_id' => '',
    'collection_id' => '',
    'supplier_id' => '',
    'product_type' => 'ready_stock',
    // UX pass: new products default to Active - no manual enable step needed. Editing an
    // existing product still has the full status dropdown (see _form.php), so this only
    // changes what a brand-new create-form starts with, never anything already saved.
    'status' => 'active',
    'availability_override' => 'auto',
    'product_cost' => '',
    'cost_currency' => SYSTEM_BASE_CURRENCY,
    'cost_currency_other' => '',
    'exchange_rate' => '',
    'selling_price' => '',
    'sale_enabled' => false,
    'sale_price' => '',
    'sale_start_date' => '',
    'has_expiry' => false,
    'expiry_date' => '',
    'stock_quantity' => '',
    'min_stock_threshold' => '',
    'target_stock_level' => '',
    'estimated_arrival_date' => '',
    'estimated_release_month' => '',
    'moq' => '1',
    'preorder_closing_date' => '',
    // Phase 9D/9F/9F.1/9F.2/9G (Pricing Engine) - only raw amounts/currencies are collected
    // here. Exchange rates are never entered anywhere - includes/currency_rates.php looks
    // them up automatically from the centrally-managed rate table. Market Price has no
    // amount input at all (Phase 9G) - it's calculated from Original Price, only its
    // currency is picked here.
    'original_price' => '',
    'original_currency' => SYSTEM_BASE_CURRENCY,
    'original_currency_other' => '',
    'market_currency' => SYSTEM_BASE_CURRENCY,
    'market_currency_other' => '',
    'weight_grams' => '',
    'shipping_origin_country_id' => '',
];

// --- Create-form speed pass: defaults for a blank form ------------------------------------
// Everything below only seeds $form BEFORE the POST handler runs. On a POST the handler
// overwrites every one of these from $_POST, so a value the user cleared stays cleared and
// nothing here can survive into a save the user didn't ask for. Nothing is persisted and no
// schema changed - the remembered values live in the session only.
//
// cost_currency is already SYSTEM_BASE_CURRENCY, which includes/currency_rates.php defines as
// JPY, so the required "default JPY" needs no separate handling here.

// Last-used values from the previous create in this session (see the redirect block below).
$lastProductDefaults = $_SESSION['last_product_defaults'] ?? [];
if (is_array($lastProductDefaults)) {
    foreach (['supplier_id', 'brand_id', 'category_id', 'shipping_origin_country_id'] as $rememberedField) {
        $rememberedValue = trim((string) ($lastProductDefaults[$rememberedField] ?? ''));
        if ($rememberedValue !== '') {
            $form[$rememberedField] = $rememberedValue;
        }
    }
}

// Shipping origin falls back to Japan when there is no previous value to reuse - the common
// case for this business. Resolved by name against the existing shipping rate countries; if
// there is no Japan row the field simply stays empty, exactly as before.
if ($form['shipping_origin_country_id'] === '') {
    $japanStmt = $pdo->prepare("SELECT id FROM shipping_rate_countries WHERE country_name = 'Japan' LIMIT 1");
    $japanStmt->execute();
    $japanCountryId = $japanStmt->fetchColumn();
    if ($japanCountryId !== false) {
        $form['shipping_origin_country_id'] = (string) (int) $japanCountryId;
    }
}

// Suggest the next SKU in whichever prefix sequence was most recently used. Prefixes are
// separate sequences on purpose - JP0384 and HK0384 are different products, so the next JP is
// JP0385 no matter how high the HK numbers have climbed. The suggestion is written into the
// normal SKU input as an ordinary editable value: it is only a starting point, the field has
// no readonly/disabled attribute, and typing over it behaves exactly as it always did.
//
// Read-only lookup - no insert, no reservation, no schema. Because nothing is reserved, two
// people creating at once could be offered the same number; the existing unique-SKU validation
// in the POST handler below is still the thing that decides, unchanged.
$suggestedSku = '';
if ($form['sku'] === '') {
    // Most recent SKU shaped as <non-digits><digits>, e.g. JP0384, QA-002, PO-1.
    $latestSkuStmt = $pdo->query(
        "SELECT sku FROM products WHERE sku REGEXP '^[^0-9]+[0-9]+$' ORDER BY id DESC LIMIT 1"
    );
    $latestSku = (string) ($latestSkuStmt->fetchColumn() ?: '');

    if ($latestSku !== '' && preg_match('/^(.*?)(\d+)$/', $latestSku, $skuParts)) {
        $skuPrefix = $skuParts[1];
        $skuWidth = strlen($skuParts[2]);
        $skuHighest = 0;

        // Scan that prefix's own sequence only. LIKE wildcards in the prefix are escaped so a
        // prefix containing _ or % cannot widen the match into a neighbouring sequence.
        $prefixStmt = $pdo->prepare("SELECT sku FROM products WHERE sku LIKE ? ESCAPE '\\\\'");
        $prefixStmt->execute([addcslashes($skuPrefix, '%_\\') . '%']);
        foreach ($prefixStmt->fetchAll(PDO::FETCH_COLUMN) as $existingSku) {
            if (preg_match('/^' . preg_quote($skuPrefix, '/') . '(\d+)$/', (string) $existingSku, $existingParts)) {
                $skuHighest = max($skuHighest, (int) $existingParts[1]);
                $skuWidth = max($skuWidth, strlen($existingParts[1]));
            }
        }

        $candidateSku = $skuPrefix . str_pad((string) ($skuHighest + 1), $skuWidth, '0', STR_PAD_LEFT);

        // Only offer it if it is genuinely free - a gap-filled or manually-entered SKU could
        // already sit on the next number.
        $freeStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
        $freeStmt->execute([$candidateSku]);
        if ((int) $freeStmt->fetchColumn() === 0) {
            $suggestedSku = $candidateSku;
            $form['sku'] = $candidateSku;
        }
    }
}

$selectedTagIds = [];

$costCurrencyOptions = [SYSTEM_BASE_CURRENCY];
$currencyOptions = [SYSTEM_BASE_CURRENCY];
foreach (currency_rates_list_by_currency($pdo) as $currencyCode => $_currencyRows) {
    $costCurrencyOptions[] = $currencyCode;
    $currencyOptions[] = $currencyCode;
}
$costCurrencyOptions = array_values(array_unique($costCurrencyOptions));
$currencyOptions = array_values(array_unique($currencyOptions));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- TEMPORARY TIMING INSTRUMENTATION (product save performance audit) ----------------
    // Marks elapsed time (ms since this POST started) at each major step, logged as one line
    // right before the final redirect (never after - app_redirect() calls exit()). error_log
    // only - no response/behavior change. Remove this whole block, and the matching $__mark()
    // calls/log line below, once the slow step is identified. See modules/products/edit.php
    // for the same instrumentation on the update path.
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

    $form['catalog_type'] = (string) ($_POST['catalog_type'] ?? 'simple');
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
    $form['status'] = (string) ($_POST['status'] ?? 'active');
    // UX pass: "Save Draft" is a second submit button (see _form.php) that always saves as
    // draft regardless of what the Status dropdown shows - reuses this exact same handler,
    // just overriding the one field, so no new validation/insert path was added.
    if ((string) ($_POST['save_action'] ?? '') === 'draft') {
        $form['status'] = 'draft';
    }
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

    // Phase 7C.1 (Product Cost Data Entry) - same validation shape as
    // modules/supplier-orders/create.php's currency/exchange rate handling: MYR needs no
    // rate, any other currency must have one, and a missing rate is never silently defaulted
    // to 1:1 (this is exactly the "not configured" state includes/product_cost.php reports).
    $costCurrency = $form['cost_currency'] === 'OTHER' ? strtoupper($form['cost_currency_other']) : strtoupper($form['cost_currency']);
    if ($error === '') {
        if ($form['cost_currency'] === 'OTHER' && ($costCurrency === '' || strlen($costCurrency) > 10)) {
            $error = 'Enter a valid cost currency code (up to 10 characters).';
        } elseif ($form['cost_currency'] !== 'OTHER' && !in_array($form['cost_currency'], $costCurrencyOptions, true)) {
            $error = 'Invalid cost currency.';
        }
    }

    // Phase 9D/9F/9F.1 (Pricing Engine) - Original Price and Market Price are both optional
    // at creation; only amounts/currencies are collected here, never a rate (see
    // includes/currency_rates.php - rates are centrally managed, never entered on a product).
    $originalCurrency = $form['original_currency'] === 'OTHER' ? strtoupper($form['original_currency_other']) : strtoupper($form['original_currency']);
    if ($error === '' && $form['original_price'] !== '') {
        if (!is_numeric($form['original_price']) || (float) $form['original_price'] < 0) {
            $error = 'Original Price must be a valid non-negative number.';
        } elseif ($form['original_currency'] === 'OTHER' && ($originalCurrency === '' || strlen($originalCurrency) > 10)) {
            $error = 'Enter a valid Original Currency code (up to 10 characters).';
        } elseif ($form['original_currency'] !== 'OTHER' && !in_array($form['original_currency'], CURRENCY_RATE_OPTIONS, true)) {
            $error = 'Invalid Original Currency.';
        }
    }

    // Phase 9G - Market Price has no amount input at all (it's calculated from Original
    // Price) - only the currency selection is validated here.
    $marketCurrency = $form['market_currency'] === 'OTHER' ? strtoupper($form['market_currency_other']) : strtoupper($form['market_currency']);
    if ($error === '') {
        if ($form['market_currency'] === 'OTHER' && ($marketCurrency === '' || strlen($marketCurrency) > 10)) {
            $error = 'Enter a valid Market Currency code (up to 10 characters).';
        } elseif ($form['market_currency'] !== 'OTHER' && !in_array($form['market_currency'], CURRENCY_RATE_OPTIONS, true)) {
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
        $skuCheck = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
        $skuCheck->execute([$form['sku']]);
        if ((int) $skuCheck->fetchColumn() > 0) {
            $error = 'SKU already exists.';
        }
    }

    // Parsed unconditionally (not gated on $error === '') so it's available below to
    // restore the Attribute Builder if an earlier/later validation error sends the user
    // back to this same form - see the restore block after this POST handler.
    $attributeSelections = [];
    if ($form['catalog_type'] === 'variable') {
        $rawSelections = json_decode((string) ($_POST['attribute_selections'] ?? '[]'), true);
        if (is_array($rawSelections)) {
            foreach ($rawSelections as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $attributeSelections[] = [
                    'attribute_id' => (int) ($item['attribute_id'] ?? 0),
                    'is_variation_attribute' => !empty($item['is_variation_attribute']),
                    'value_ids' => array_map('intval', $item['value_ids'] ?? []),
                ];
            }
        }
        if ($error === '' && $attributeSelections === []) {
            $error = 'Select at least one attribute with values for a variable product.';
        }
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('
                INSERT INTO products (
                    sku, name, short_description, description, product_type, catalog_type, brand_id, barcode,
                    supplier_sku, internal_code,
                    supplier_id, product_cost, cost_currency, selling_price, sale_enabled, sale_price,
                    min_stock_threshold, target_stock_level, sale_start_date, estimated_arrival_date, estimated_release_month,
                    preorder_closing_date, expiry_date, moq, status, availability_override,
                    original_price, original_currency, market_currency, weight_grams, shipping_origin_country_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
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
                $form['preorder_closing_date'] !== '' ? $form['preorder_closing_date'] : null,
                ($form['has_expiry'] && $form['expiry_date'] !== '') ? $form['expiry_date'] : null,
                $form['moq'] !== '' ? max(1, (int) $form['moq']) : 1,
                $form['status'],
                $form['availability_override'],
                $form['original_price'] !== '' ? round((float) $form['original_price'], 2) : null,
                $form['original_price'] !== '' && $originalCurrency !== SYSTEM_SELLING_CURRENCY ? $originalCurrency : null,
                $marketCurrency !== SYSTEM_SELLING_CURRENCY ? $marketCurrency : null,
                $form['weight_grams'] !== '' ? round((float) $form['weight_grams'], 2) : null,
                $shippingOriginCountryId,
            ]);
            $productId = (int) $pdo->lastInsertId();

            // Phase 9F.1 - exchange_rate is never entered manually; it's auto-derived from
            // the centrally-managed currency_rates table right after every save, so
            // includes/product_cost.php's actual Landed Cost engine (untouched) always reads
            // an up-to-date rate.
            currency_rates_sync_product_exchange_rate($pdo, $productId, $costCurrency !== SYSTEM_SELLING_CURRENCY ? $costCurrency : null);

            catalog_sync_product_category($pdo, $productId, $categoryId);
            catalog_sync_product_collection($pdo, $productId, $collectionId);
            catalog_sync_product_tag_ids($pdo, $productId, $selectedTagIds);
            $__mark('database_product_insert'); // TEMPORARY TIMING INSTRUMENTATION

            // Background image processing - compression/WebP/resize was the measured save-time
            // bottleneck (see the timing instrumentation below). Each file is validated and
            // staged (fast, no GD - see image_upload_stage()) here, then queued for the worker
            // to run the actual (unchanged) pipeline. A bad/corrupt/oversized file still throws
            // here, exactly like before - only the "resize+convert+save" part moved out.
            //
            // --- TEMPORARY UPLOAD TIMING INSTRUMENTATION (image compression already queued;
            // investigating whether the remaining save-time delay on image uploads is the HTTP
            // upload itself, move_uploaded_file(), or something else). error_log only, no
            // response/behavior change. Remove alongside the step-timing block below once the
            // bottleneck is identified.
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
            // request_time_float_to_script_start_ms approximates time spent before this script
            // began executing (network transfer + PHP's own multipart body parsing, which
            // includes writing the initial tmp upload files) - $_SERVER['REQUEST_TIME_FLOAT'] is
            // the SAPI's own request-start timestamp, the closest signal available without
            // adding a client-side JS timestamp to the form. NOT a precise "browser upload time"
            // measurement on its own, but a large value here (seconds, not ms) on a multi-MB
            // upload points at network/PHP-body-parsing rather than anything below this line.
            if ($__uploadFileCount > 0) {
                $__requestTimeFloat = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? $__timingStart);
                error_log(sprintf(
                    '[product-upload-timing][create] product_id=%d file_count=%d total_upload_mb=%.2f avg_file_size_kb=%.1f move_uploaded_file_ms=[%s] request_time_float_to_script_start_ms=%d',
                    $productId,
                    $__uploadFileCount,
                    $__uploadTotalBytes / 1024 / 1024,
                    ($__uploadTotalBytes / $__uploadFileCount) / 1024,
                    implode(',', $__uploadMoveDurationsMs),
                    (int) round(($__timingStart - $__requestTimeFloat) * 1000)
                ));
            }
            // --- END TEMPORARY UPLOAD TIMING INSTRUMENTATION -----------------------------------

            $__mark('image_processing'); // TEMPORARY TIMING INSTRUMENTATION (covers main+gallery image handling above; stock init below is folded into the next mark)

            if ($form['catalog_type'] === 'simple' && $form['product_type'] === 'ready_stock' && $form['stock_quantity'] !== '' && is_numeric($form['stock_quantity'])) {
                $initialStock = max(0, (int) $form['stock_quantity']);
                inventory_get_or_create_row($pdo, $productId, null);
                if ($initialStock > 0) {
                    $pdo->prepare('UPDATE mewmii_inventory SET available_quantity = ? WHERE product_id = ? AND variation_id IS NULL')
                        ->execute([$initialStock, $productId]);
                    inventory_log_transaction($pdo, $productId, 'manual_adjustment', $initialStock, 'product_create', $productId, null);
                }
            }

            if ($form['catalog_type'] === 'variable') {
                catalog_set_product_attributes($pdo, $productId, $attributeSelections);
                $__mark('attribute_save'); // TEMPORARY TIMING INSTRUMENTATION
                $generated = variation_generate_combinations($pdo, $productId);
                variation_apply_preview_edits($pdo, $productId, $generated['variations']);
                $__mark('variation_generation'); // TEMPORARY TIMING INSTRUMENTATION
            }

            $pdo->commit();

            // Strictly after commit, never inside the transaction above - see the identical
            // comment in modules/products/edit.php.
            //
            // Phase 11A (Unified Outbound Job Queue) - queues the push instead of running it
            // inline. wc_client_auto_sync_product() itself is unchanged and still does the
            // actual work; cli/job_worker.php's handler calls it, just on the next worker tick
            // rather than synchronously in this request. See that file's own docblock.
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
            error_log('[product-save-timing][create] product_id=' . $productId . ' ' . implode(' ', $__parts));
            // --- END TEMPORARY TIMING INSTRUMENTATION ---------------------------------------

            // Create-form speed pass: remember the fields that stay the same across a run of
            // products entered back-to-back, so the next blank form arrives pre-filled. Stored
            // per-session only - no schema change, nothing persisted against the product, and
            // these are only ever used as DEFAULTS for a blank form (see the $form defaults
            // block above), never to overwrite anything the user typed.
            $_SESSION['last_product_defaults'] = [
                'supplier_id' => $form['supplier_id'],
                'brand_id' => $form['brand_id'],
                'category_id' => $form['category_id'],
                'shipping_origin_country_id' => $form['shipping_origin_country_id'],
            ];

            // "Save & add another" returns to a blank create form instead of the new product's
            // edit page. Everything above this point - validation, insert, images, WooCommerce
            // enqueue - has already run identically; this only picks the destination.
            if ((string) ($_POST['after_save'] ?? '') === 'new') {
                app_redirect('/modules/products/create.php?created=1' . $wcSyncStatus . $imagesQueuedStatus);
            }

            app_redirect('/modules/products/edit.php?id=' . $productId . '&created=1' . $wcSyncStatus . $imagesQueuedStatus);
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create product.';
        }
    }
}

// Sprint 11: if the submit above failed validation (or the transaction itself failed) for
// a variable product, restore the Attribute Builder blocks/checked values and the
// client-side variation preview table's edited fields instead of sending the user back to
// a blank form - the product's basic fields already survive via $form above; this is the
// same restore for the attribute/variation-preview state, which previously had no
// equivalent and was silently wiped on any error (the actual cause of "Generate Variations
// work disappears").
$previewFieldOverrides = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '' && $form['catalog_type'] === 'variable') {
    $existingAssignments = array_map(static function (array $selection): array {
        return [
            'attributeId' => $selection['attribute_id'],
            'isVariation' => $selection['is_variation_attribute'],
            'valueIds' => $selection['value_ids'],
        ];
    }, $attributeSelections);

    // Raw posted preview-table edits, keyed by combination signature - the exact same
    // shape variation_apply_preview_edits() reads, just handed back to the client so
    // renderPreviewTable() can pre-fill each row instead of resetting to defaults.
    $previewFieldOverrides = [
        'variation_sku' => $_POST['variation_sku'] ?? [],
        'variation_barcode' => $_POST['variation_barcode'] ?? [],
        'variation_supplier_sku' => $_POST['variation_supplier_sku'] ?? [],
        'variation_weight' => $_POST['variation_weight'] ?? [],
        'variation_price_mode' => $_POST['variation_price_mode'] ?? [],
        'variation_custom_price' => $_POST['variation_custom_price'] ?? [],
        'variation_cost_price' => $_POST['variation_cost_price'] ?? [],
        'variation_status' => $_POST['variation_status'] ?? [],
    ];
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

// Phase 9G (Inline Pricing & Inventory Calculation UI) - $currencyRateMaps feeds the inline
// calculator's live JS (see _form.php); no server-side $pricingPreview here - a brand-new
// product has nothing saved yet to look up, so the calculator starts blank and is entirely
// JS-driven from the first keystroke.
$shippingCountries = pricing_list_shipping_rate_countries($pdo);
$currencyRateMaps = currency_rates_all_maps($pdo);

require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_form.php';
require_once __DIR__ . '/../../includes/footer.php';
