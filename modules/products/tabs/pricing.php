<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/pricing_engine.php';
app_require_permission('products.manage');

/**
 * Phase 9F.1 - Price Calculation Setting tab. No exchange rate input exists anywhere in this
 * app anymore - includes/currency_rates.php's centrally-managed rate table (modules/settings/
 * currency_rates.php) is looked up automatically for every conversion shown below. The only
 * fields still editable here are Weight and Shipping Origin (unrelated to currency). Original
 * Price/Supplier Price/Market Price amounts and currencies are set on modules/products/edit.php
 * instead - this page shows them read-only alongside their looked-up rate and converted value.
 * Never touches products.selling_price - that stays manually controlled, set only from
 * modules/products/edit.php. No selling multiplier, no recommended selling price, no auto-fill
 * of selling_price.
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
    'weight_grams' => $product['weight_grams'] !== null ? (string) $product['weight_grams'] : '',
    'shipping_origin_country_id' => $product['shipping_origin_country_id'] !== null ? (string) $product['shipping_origin_country_id'] : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['weight_grams'] = trim((string) ($_POST['weight_grams'] ?? ''));
    $form['shipping_origin_country_id'] = trim((string) ($_POST['shipping_origin_country_id'] ?? ''));

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
        $pdo->prepare('UPDATE products SET weight_grams = ?, shipping_origin_country_id = ? WHERE id = ?')
            ->execute([
                $form['weight_grams'] !== '' ? round((float) $form['weight_grams'], 2) : null,
                $shippingOriginCountryId,
                $productId,
            ]);

        app_redirect('/modules/products/tabs/pricing.php?id=' . $productId . '&updated=1');
    }
}

$shippingCountries = pricing_list_shipping_rate_countries($pdo);
$pricingBreakdown = pricing_calculate($pdo, $productId);
$notConfiguredBadge = '<span class="badge bg-secondary">Exchange rate not configured</span>';

/**
 * One reference-price row (Original/Supplier/Market): raw amount+currency, the looked-up
 * rate (or "not configured"), and the converted MYR value. $currency === null means already
 * MYR (rate 1.0, no lookup needed) - same convention as the rest of the engine.
 */
function pricing_tab_render_reference_row(string $label, ?float $amount, ?string $currency, bool $configured, ?float $exchangeRate, ?float $converted, string $notConfiguredBadge): void
{
    ?>
    <div class="col-md-4">
        <div class="text-muted small"><?php echo app_escape($label); ?></div>
        <?php if ($amount === null): ?>
            <div class="text-muted">Not set</div>
        <?php else: ?>
            <div><?php echo app_escape(number_format($amount, 2)); ?> <?php echo app_escape($currency ?? 'MYR'); ?></div>
            <div class="text-muted small">
                Rate: <?php echo $configured ? app_escape(number_format((float) $exchangeRate, 6)) : $notConfiguredBadge; ?>
            </div>
            <div class="fw-semibold">
                <?php echo $configured && $converted !== null ? ('RM ' . app_escape(number_format($converted, 2))) : $notConfiguredBadge; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

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
        <a class="btn btn-outline-secondary btn-sm" href="/modules/settings/currency_rates.php">Manage Exchange Rates</a>
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
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Reference Prices &amp; Calculated Result</h5>
        <span class="text-muted small">Amounts/currencies are set on Edit Product. Exchange rates are looked up automatically - never entered here.</span>
    </div>
    <div class="row g-3">
        <?php
        pricing_tab_render_reference_row('Original Price', $pricingBreakdown['original_price'], $pricingBreakdown['original_currency'], $pricingBreakdown['original_price_configured'], $pricingBreakdown['original_exchange_rate'], $pricingBreakdown['original_price_myr'], $notConfiguredBadge);
        pricing_tab_render_reference_row('Supplier Price', $pricingBreakdown['supplier_price'], $pricingBreakdown['supplier_currency'], $pricingBreakdown['supplier_price_configured'], $pricingBreakdown['supplier_exchange_rate'], $pricingBreakdown['supplier_price_myr'], $notConfiguredBadge);
        pricing_tab_render_reference_row('Market Price', $pricingBreakdown['market_price'], $pricingBreakdown['market_currency'], $pricingBreakdown['market_price_configured'], $pricingBreakdown['market_exchange_rate'], $pricingBreakdown['market_price_myr'], $notConfiguredBadge);
        ?>
        <div class="col-md-4">
            <div class="text-muted small">Supplier Discount vs Original</div>
            <div class="fw-semibold"><?php echo $pricingBreakdown['supplier_discount_percent'] !== null ? (app_escape(number_format($pricingBreakdown['supplier_discount_percent'], 1)) . '%') : '—'; ?></div>
        </div>
        <div class="col-md-4">
            <div class="text-muted small">Estimated Shipping Cost</div>
            <div>
                <?php if ($pricingBreakdown['shipping_cost'] !== null): ?>
                    RM <?php echo app_escape(number_format($pricingBreakdown['shipping_cost'], 2)); ?>
                    <span class="text-muted small">(<?php echo app_escape($pricingBreakdown['weight_grams']); ?>g &times; RM<?php echo app_escape(number_format($pricingBreakdown['shipping_rate_per_gram'], 4)); ?>/g from <?php echo app_escape($pricingBreakdown['shipping_origin_country_name']); ?>)</span>
                <?php else: ?>
                    <span class="text-muted">Not configured (set Weight and Shipping Origin below)</span>
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

<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
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
    <button type="submit" class="btn btn-primary">Save</button>
</form>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
