<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/currency_rates.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('supplier-orders.manage');

$appTitle = 'New Supplier Order';
$error = '';
$pdo = app_db();

// Phase 6B (Supplier Order currency) - dropdown offered on the form; 'OTHER' reveals a free-
// text code (currency_other below) for anything not in this short list.
const SUPPLIER_ORDER_CURRENCY_OPTIONS = ['MYR', 'JPY', 'CNY', 'USD', 'EUR', 'GBP'];

$form = [
    'supplier_id' => '',
    'purchase_number' => 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
    // When the purchase actually happened. Defaults to today; back-dating is allowed so
    // historical purchases can be recorded during migration. Deliberately NOT the same thing as
    // received_date / inventory movement timestamps - see the field's note in the form below.
    'order_date' => date('Y-m-d'),
    'notes' => '',
    'shipping_fee' => '0.00',
    'payment_status' => 'unpaid',
    'currency' => SYSTEM_SELLING_CURRENCY,
    'currency_other' => '',
    'exchange_rate' => '',
];
$existingItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));
    $form['purchase_number'] = trim((string) ($_POST['purchase_number'] ?? ''));
    $form['order_date'] = trim((string) ($_POST['order_date'] ?? ''));
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));
    $form['shipping_fee'] = trim((string) ($_POST['shipping_fee'] ?? ''));
    $form['payment_status'] = in_array($_POST['payment_status'] ?? '', SUPPLIER_ORDER_PAYMENT_STATUSES, true) ? $_POST['payment_status'] : 'unpaid';
    $form['currency'] = trim((string) ($_POST['currency'] ?? SYSTEM_SELLING_CURRENCY));
    $form['currency_other'] = trim((string) ($_POST['currency_other'] ?? ''));
    $form['exchange_rate'] = trim((string) ($_POST['exchange_rate'] ?? ''));

    // Phase 6B (Supplier Order currency) - 'OTHER' + a free-text code collapses into the one
    // stored currency value everything below (and the whole rest of the codebase) treats the
    // same way; nothing downstream ever sees the literal string 'OTHER'. Currency + line-item
    // validation is shared with edit.php - see supplier_order_validate_form().
    $formResult = supplier_order_validate_form($pdo, $form, $_POST['unit_key'] ?? [], $_POST['quantity'] ?? [], $_POST['supplier_price'] ?? [], $error);
    $error = $formResult['error'];
    $currency = $formResult['currency'];
    $exchangeRate = $formResult['exchange_rate'];
    $validItems = $formResult['valid_items'];
    $existingItems = $formResult['existing_items'];

    if ($error === '' && ($form['supplier_id'] === '' || (int) $form['supplier_id'] < 1)) {
        $error = 'Select a supplier.';
    }

    $supplierId = (int) $form['supplier_id'];
    if ($error === '') {
        $supplierCheck = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id = ?');
        $supplierCheck->execute([$supplierId]);
        if ((int) $supplierCheck->fetchColumn() === 0) {
            $error = 'Selected supplier does not exist.';
        }
    }

    if ($error === '') {
        if ($form['order_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['order_date'])) {
            $error = 'Enter a valid order date.';
        } elseif ($form['purchase_number'] === '' || strlen($form['purchase_number']) > 100) {
            $error = 'Purchase number is required and must be 100 characters or fewer.';
        }
    }

    if ($error === '') {
        $poCheck = $pdo->prepare('SELECT COUNT(*) FROM supplier_orders WHERE purchase_number = ?');
        $poCheck->execute([$form['purchase_number']]);
        if ((int) $poCheck->fetchColumn() > 0) {
            $error = 'Purchase number already exists.';
        }
    }

    if ($error === '' && $validItems === []) {
        $error = 'Add at least one product with a quantity.';
    }

    $shippingFee = 0.00;
    if ($error === '') {
        if ($form['shipping_fee'] !== '' && (!is_numeric($form['shipping_fee']) || (float) $form['shipping_fee'] < 0)) {
            $error = 'Shipping fee must be a valid non-negative number.';
        } else {
            $shippingFee = $form['shipping_fee'] !== '' ? round((float) $form['shipping_fee'], 2) : 0.00;
        }
    }

    if ($error === '') {
        $estimatedCost = 0.00;
        $foreignTotal = 0.00;
        foreach ($validItems as $line) {
            $estimatedCost += $line['quantity'] * $line['supplier_price'];
            $foreignTotal += $line['quantity'] * $line['unit_cost_foreign'];
        }

        $pdo->beginTransaction();

        try {
            $orderStmt = $pdo->prepare("
                INSERT INTO supplier_orders (supplier_id, purchase_number, status, payment_status, estimated_cost, shipping_fee, currency, exchange_rate, foreign_total, order_date, notes)
                VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $orderStmt->execute([$supplierId, $form['purchase_number'], $form['payment_status'], round($estimatedCost, 2), $shippingFee, $currency, $exchangeRate, round($foreignTotal, 2), $form['order_date'], $form['notes'] !== '' ? $form['notes'] : null]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO supplier_order_items (supplier_order_id, product_id, variation_id, total_quantity, supplier_price, unit_cost_foreign, unit_cost_myr, subtotal)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');

            foreach ($validItems as $line) {
                $subtotal = round($line['quantity'] * $line['supplier_price'], 2);
                $itemStmt->execute([$orderId, $line['product_id'], $line['variation_id'], $line['quantity'], $line['supplier_price'], $line['unit_cost_foreign'], $line['supplier_price'], $subtotal]);
                $itemId = (int) $pdo->lastInsertId();

                supplier_order_mark_incoming($pdo, $line['product_id'], $itemId, $line['quantity'], $line['variation_id']);
            }

            activity_log($pdo, 'supplier_orders', 'create', $orderId, 'Created supplier order ' . $form['purchase_number'] . ' (' . count($validItems) . ' item(s), ' . $currency . ' ' . number_format($foreignTotal, 2) . ')');

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            inventory_discard_pending_woocommerce_resync();

            error_log('[supplier-orders/create] ' . get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());

            // The UNIQUE constraint on supplier_orders.purchase_number (see database/schema.sql
            // and database/migrate_supplier_order_purchase_number_unique.php) is the
            // authoritative guard against two concurrent submissions creating duplicate orders -
            // the SELECT COUNT() check above is only a fast-path UX hint and can race under real
            // concurrency (two requests both reading "0 exists" before either inserts). MySQL
            // error 1062 here means that race was actually hit: another request already created
            // this exact order, so this one must not be reported as a generic failure that would
            // invite the admin to retry and create a real duplicate.
            $isDuplicatePurchaseNumber = $exception instanceof PDOException
                && (int) ($exception->errorInfo[1] ?? 0) === 1062;

            if ($isDuplicatePurchaseNumber) {
                $error = 'Supplier order already created. Please refresh the page.';
            } else {
                $configPath = dirname(__DIR__, 2) . '/config.php';
                $appConfig = is_file($configPath) ? require $configPath : [];
                $debugEnabled = !empty($appConfig['app']['debug']);

                $error = 'Failed to create supplier order.' . ($debugEnabled ? ' Debug: ' . $exception->getMessage() : '');
            }
        }

        // The order is already durably committed at this point - a failure in the WooCommerce
        // resync (best-effort, and not expected to ever throw - see
        // inventory_flush_woocommerce_resync()'s own doc comment) must never turn a real
        // success into a false "Failed to create supplier order" that could prompt the admin
        // to resubmit and create a genuine duplicate order.
        if ($error === '') {
            try {
                inventory_flush_woocommerce_resync($pdo);
            } catch (Throwable $exception) {
                error_log('[supplier-orders/create] WooCommerce resync failed after commit for order #' . $orderId . ': ' . $exception->getMessage());
            }

            app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&created=1');
        }
    }
}

$suppliersStmt = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200');
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$pickerSuppliers = $suppliers;
$pickerCategories = catalog_list_categories_tree($pdo);
$pickerProducts = supplier_order_picker_products($pdo);

// Exchange rate suggestion only (see includes/currency_rates.php) - reuses the same centrally
// managed 'supplier' rate already used for product cost conversion. Purely a pre-fill hint for
// supplier-order-form.js; the admin's actual invoiced rate (typed or already saved) always wins
// and this never overwrites it - see the JS side's "only fill if the field is still empty" guard.
$supplierRateSuggestions = currency_rates_lookup_batch($pdo, 'supplier', SUPPLIER_ORDER_CURRENCY_OPTIONS);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">New Supplier Order</h1>
        <p class="text-muted mb-0">Create a purchase order and mark the ordered stock as incoming.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/index.php">Back to Supplier Orders</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label">Supplier</label>
                <select class="form-select" name="supplier_id" required>
                    <option value="">Select a supplier&hellip;</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>>
                            <?php echo app_escape($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Purchase Number</label>
                <input type="text" class="form-control" name="purchase_number" value="<?php echo app_escape($form['purchase_number']); ?>" maxlength="100" required>
            </div>

            <div class="col-md-3">
                <label class="form-label">Order Date</label>
                <input type="date" class="form-control" name="order_date" value="<?php echo app_escape($form['order_date']); ?>" required>
                <div class="form-text">When the purchase was placed. Back-date it to record a past order. Stock still enters inventory on the day you actually receive it.</div>
            </div>

            <div class="col-md-3">
                <label class="form-label">Supplier Currency</label>
                <select class="form-select" name="currency" id="supplier-order-currency">
                    <?php foreach (SUPPLIER_ORDER_CURRENCY_OPTIONS as $currencyOption): ?>
                        <option value="<?php echo app_escape($currencyOption); ?>" <?php echo $form['currency'] === $currencyOption ? 'selected' : ''; ?>><?php echo app_escape($currencyOption); ?></option>
                    <?php endforeach; ?>
                    <option value="OTHER" <?php echo !in_array($form['currency'], SUPPLIER_ORDER_CURRENCY_OPTIONS, true) ? 'selected' : ''; ?>>Other</option>
                </select>
                <input type="text" class="form-control mt-2<?php echo in_array($form['currency'], SUPPLIER_ORDER_CURRENCY_OPTIONS, true) ? ' d-none' : ''; ?>" id="supplier-order-currency-other" name="currency_other" maxlength="10" placeholder="e.g. KRW" value="<?php echo app_escape($form['currency_other']); ?>">
            </div>
            <div class="col-md-3 d-none" id="supplier-order-exchange-rate-wrapper">
                <label class="form-label" id="supplier-order-exchange-rate-label">Exchange Rate</label>
                <input type="number" step="0.000001" min="0" class="form-control" id="supplier-order-exchange-rate" name="exchange_rate" value="<?php echo app_escape($form['exchange_rate']); ?>">
                <div class="form-text">e.g. 1 JPY = 0.03 MYR</div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Shipping Fee (RM)</label>
                <input type="number" step="0.01" min="0" class="form-control" id="supplier-order-shipping-fee" name="shipping_fee" value="<?php echo app_escape($form['shipping_fee']); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Payment Status</label>
                <select class="form-select" name="payment_status">
                    <?php foreach (SUPPLIER_ORDER_PAYMENT_STATUSES as $statusValue): ?>
                        <option value="<?php echo app_escape($statusValue); ?>" <?php echo $form['payment_status'] === $statusValue ? 'selected' : ''; ?>>
                            <?php echo app_escape(supplier_order_payment_status_label($statusValue)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"><?php echo app_escape($form['notes']); ?></textarea>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Products</h5>
            <button type="button" class="btn btn-primary btn-sm" id="add-product-btn">+ Add Product</button>
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="supplier-order-items-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU / Variation</th>
                        <th>MOQ</th>
                        <th>Quantity</th>
                        <th id="supplier-order-unit-cost-header">Unit Cost (RM)</th>
                        <th id="supplier-order-subtotal-header">Subtotal (RM)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr class="d-none" id="supplier-order-foreign-subtotal-row">
                        <td colspan="5" class="text-end text-muted">Product Subtotal (<span id="supplier-order-foreign-subtotal-currency"></span>)</td>
                        <td class="text-muted" id="supplier-order-foreign-subtotal">0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Product Subtotal (RM)</td>
                        <td class="fw-semibold" id="supplier-order-product-subtotal">0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Total Purchase Amount</td>
                        <td class="fw-semibold" id="supplier-order-total">0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <button class="btn btn-primary mt-3" type="submit">Create Supplier Order</button>
    </form>
</div>

<?php require __DIR__ . '/_item_picker_modal.php'; ?>

<script id="supplier-order-form-data" type="application/json">
    <?php echo json_encode([
        'products' => $pickerProducts,
        'existingItems' => $existingItems,
        'supplierRateSuggestions' => $supplierRateSuggestions,
    ]); ?>
</script>
<?php
$supplierOrderFormJsPath = __DIR__ . '/../../assets/js/supplier-order-form.js';
$supplierOrderFormJsVersion = is_file($supplierOrderFormJsPath) ? filemtime($supplierOrderFormJsPath) : time();
?>
<script src="/assets/js/supplier-order-form.js?v=<?php echo (int) $supplierOrderFormJsVersion; ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>