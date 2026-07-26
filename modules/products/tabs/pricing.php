<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/pricing_engine.php';
app_require_permission('products.manage');

/**
 * Phase 9F - Price Calculation Setting tab. Everything pricing-engine-related EXCEPT the raw
 * Original Price amount/currency (kept on modules/products/edit.php's basic form - see that
 * file's Pricing group) lives here instead: exchange rates, Market Exchange Rate, Weight, and
 * Shipping Origin. Reuses includes/pricing_engine.php, shipping_rate_countries, and the same
 * currency conventions as the rest of the app - only the UI location changed, no new
 * calculation logic. Never touches products.selling_price - that stays manually controlled,
 * set only from modules/products/edit.php. No selling multiplier, no recommended selling
 * price, no auto-fill of selling_price - all of that was explicitly removed, not just moved.
 */

$appTitle = 'Price Calculation Setting';
$pdo = app_db();

$productId = (int) ($_GET['id'] ?? 0);

if ($productId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../../includes/footer.php';
    exit;
}

$productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
$productStmt->execute([$productId]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    require_once __DIR__ . '/../../../includes/header.php';
    echo '<div class="alert alert-danger">Product not found.</div>';
    require_once __DIR__ . '/../../../includes/footer.php';
    exit;
}

$error = '';

$form = [
    'original_exchange_rate' => $product['original_exchange_rate'] !== null ? (string) $product['original_exchange_rate'] : '',
    'exchange_rate' => $product['exchange_rate'] !== null ? (string) $product['exchange_rate'] : '',
    'market_exchange_rate' => $product['market_exchange_rate'] !== null ? (string) $product['market_exchange_rate'] : '',
    'weight_grams' => $product['weight_grams'] !== null ? (string) $product['weight_grams'] : '',
    'shipping_origin_country_id' => $product['shipping_origin_country_id'] !== null ? (string) $product['shipping_origin_country_id'] : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['original_exchange_rate'] = trim((string) ($_POST['original_exchange_rate'] ?? ''));
    $form['exchange_rate'] = trim((string) ($_POST['exchange_rate'] ?? ''));
    $form['market_exchange_rate'] = trim((string) ($_POST['market_exchange_rate'] ?? ''));
    $form['weight_grams'] = trim((string) ($_POST['weight_grams'] ?? ''));
    $form['shipping_origin_country_id'] = trim((string) ($_POST['shipping_origin_country_id'] ?? ''));

    // Every field here is optional (left blank = "not configured yet", never an error) - only
    // validated for being well-formed if an admin actually enters something.
    if ($error === '' && $form['original_exchange_rate'] !== '' && (!is_numeric($form['original_exchange_rate']) || (float) $form['original_exchange_rate'] <= 0)) {
        $error = 'Original Exchange Rate must be a valid number greater than 0, or left blank.';
    }
    if ($error === '' && $form['exchange_rate'] !== '' && (!is_numeric($form['exchange_rate']) || (float) $form['exchange_rate'] <= 0)) {
        $error = 'Supplier Exchange Rate must be a valid number greater than 0, or left blank.';
    }
    if ($error === '' && $form['market_exchange_rate'] !== '' && (!is_numeric($form['market_exchange_rate']) || (float) $form['market_exchange_rate'] <= 0)) {
        $error = 'Market Exchange Rate must be a valid number greater than 0, or left blank.';
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

    if ($error === '') {
        $pdo->prepare('
            UPDATE products
            SET original_exchange_rate = ?, exchange_rate = ?, market_exchange_rate = ?, weight_grams = ?, shipping_origin_country_id = ?
            WHERE id = ?
        ')->execute([
            $form['original_exchange_rate'] !== '' ? (float) $form['original_exchange_rate'] : null,
            $form['exchange_rate'] !== '' ? (float) $form['exchange_rate'] : null,
            $form['market_exchange_rate'] !== '' ? (float) $form['market_exchange_rate'] : null,
            $form['weight_grams'] !== '' ? round((float) $form['weight_grams'], 2) : null,
            $shippingOriginCountryId,
            $productId,
        ]);

        app_redirect('/modules/products/tabs/pricing.php?id=' . $productId . '&updated=1');
    }
}

$shippingCountries = pricing_list_shipping_rate_countries($pdo);
$pricingBreakdown = pricing_calculate($pdo, $productId);
$notConfiguredBadge = '<span class="badge bg-secondary">Not configured</span>';

require_once __DIR__ . '/../../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Price Calculation Setting</h2>
        <p class="text-muted mb-0"><?php echo app_escape($product['name']); ?> &middot; <?php echo app_escape($product['sku']); ?></p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="/modules/products/edit.php?id=<?php echo (int) $productId; ?>">Edit Product</a>
        <a class="btn btn-outline-primary btn-sm" href="/modules/products/view.php?id=<?php echo (int) $productId; ?>">View Product</a>
        <a class="btn btn-outline-secondary btn-sm" href="/modules/products/index.php">Back to Products</a>
    </div>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Price calculation settings updated.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Reference Prices (set on Edit Product)</h5>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="text-muted small">Original Price</div>
            <div><?php echo $product['original_price'] !== null ? app_escape(number_format((float) $product['original_price'], 2)) . ' ' . app_escape($product['original_currency'] ?? 'MYR') : 'Not set'; ?></div>
        </div>
        <div class="col-md-6">
            <div class="text-muted small">Supplier Price</div>
            <div><?php echo app_escape(number_format((float) $product['product_cost'], 2)) . ' ' . app_escape($product['cost_currency'] ?? 'MYR'); ?></div>
        </div>
    </div>
</div>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Exchange Rates</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Original Exchange Rate</label>
                <input type="number" step="0.0001" min="0" class="form-control" name="original_exchange_rate" value="<?php echo app_escape($form['original_exchange_rate']); ?>" placeholder="e.g. 0.0260">
                <div class="form-text">1 <?php echo app_escape($product['original_currency'] ?? 'MYR'); ?> = ? MYR. Only needed if Original Currency above isn't MYR.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Supplier Exchange Rate</label>
                <input type="number" step="0.0001" min="0" class="form-control" name="exchange_rate" value="<?php echo app_escape($form['exchange_rate']); ?>" placeholder="e.g. 0.0320">
                <div class="form-text">1 <?php echo app_escape($product['cost_currency'] ?? 'MYR'); ?> = ? MYR. Only needed if Supplier Currency above isn't MYR.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Market Exchange Rate</label>
                <input type="number" step="0.0001" min="0" class="form-control" name="market_exchange_rate" value="<?php echo app_escape($form['market_exchange_rate']); ?>" placeholder="e.g. 0.0350">
                <div class="form-text">Market Price RM = Original Price &times; this rate (a separate rate assumption on the same Original Price amount above).</div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Shipping</h5>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Weight (grams)</label>
                <input type="number" step="0.01" min="0" class="form-control" name="weight_grams" value="<?php echo app_escape($form['weight_grams']); ?>" placeholder="0.00">
            </div>
            <div class="col-md-6">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label mb-1">Shipping Origin</label>
                    <a class="small" href="/modules/settings/shipping_rates.php" target="_blank" rel="noopener">Manage &#8599;</a>
                </div>
                <select class="form-select" name="shipping_origin_country_id">
                    <option value="">None</option>
                    <?php foreach ($shippingCountries as $shippingCountry): ?>
                        <option value="<?php echo (int) $shippingCountry['id']; ?>" <?php echo $form['shipping_origin_country_id'] === (string) $shippingCountry['id'] ? 'selected' : ''; ?>><?php echo app_escape($shippingCountry['country_name']); ?> (RM <?php echo app_escape(number_format((float) $shippingCountry['rate_per_gram'], 4)); ?>/g)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save Price Calculation Settings</button>
</form>

<div class="card p-4 mt-4">
    <h5 class="mb-3">Calculated Result</h5>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="text-muted small">Original Price RM</div>
            <div>
                <?php if ($pricingBreakdown['original_price'] === null): ?>
                    <span class="text-muted">Not set</span>
                <?php elseif ($pricingBreakdown['original_price_configured'] && $pricingBreakdown['original_price_myr'] !== null): ?>
                    RM <?php echo app_escape(number_format($pricingBreakdown['original_price_myr'], 2)); ?>
                <?php else: ?>
                    <?php echo $notConfiguredBadge; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Supplier Price RM</div>
            <div>
                <?php if ($pricingBreakdown['supplier_price_configured'] && $pricingBreakdown['supplier_price_myr'] !== null): ?>
                    RM <?php echo app_escape(number_format($pricingBreakdown['supplier_price_myr'], 2)); ?>
                <?php else: ?>
                    <?php echo $notConfiguredBadge; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Supplier Discount vs Original</div>
            <div><?php echo $pricingBreakdown['supplier_discount_percent'] !== null ? (app_escape(number_format($pricingBreakdown['supplier_discount_percent'], 1)) . '%') : '—'; ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Market Price RM</div>
            <div>
                <?php if ($pricingBreakdown['market_price_myr'] !== null): ?>
                    RM <?php echo app_escape(number_format($pricingBreakdown['market_price_myr'], 2)); ?>
                <?php else: ?>
                    <?php echo $notConfiguredBadge; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Estimated Shipping Cost</div>
            <div>
                <?php if ($pricingBreakdown['shipping_cost'] !== null): ?>
                    RM <?php echo app_escape(number_format($pricingBreakdown['shipping_cost'], 2)); ?>
                    <span class="text-muted small">(<?php echo app_escape($pricingBreakdown['weight_grams']); ?>g &times; RM<?php echo app_escape(number_format($pricingBreakdown['shipping_rate_per_gram'], 4)); ?>/g from <?php echo app_escape($pricingBreakdown['shipping_origin_country_name']); ?>)</span>
                <?php else: ?>
                    <?php echo $notConfiguredBadge; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Estimated Cost</div>
            <div>
                <?php if ($pricingBreakdown['estimated_cost'] !== null): ?>
                    <strong>RM <?php echo app_escape(number_format($pricingBreakdown['estimated_cost'], 2)); ?></strong>
                    <?php if ($pricingBreakdown['estimated_cost_is_partial']): ?>
                        <span class="badge bg-warning text-dark ms-1">Estimated</span>
                    <?php endif; ?>
                <?php else: ?>
                    <?php echo $notConfiguredBadge; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Current Selling Price</div>
            <div>RM <?php echo app_escape(number_format($pricingBreakdown['selling_price'], 2)); ?> <span class="text-muted small">(manually controlled on Edit Product)</span></div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
