<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/currency_rates.php';
require_once __DIR__ . '/../../includes/supplier_orders.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/catalog.php';
app_require_permission('supplier-orders.manage');

$appTitle = 'Edit Supplier Order';
$error = '';
$pdo = app_db();

$orderId = (int) ($_GET['id'] ?? 0);

if ($orderId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$orderStmt = $pdo->prepare('SELECT * FROM supplier_orders WHERE id = ? LIMIT 1');
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Supplier order not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Completed locks products/quantities/cost entirely (notes only) - every other status
// stays editable, since receiving-history safety is enforced per-line inside
// supplier_order_apply_edit() (a line with received quantity can't be removed or reduced
// below what's already received), not by blocking the whole order.
$isCompleted = $order['status'] === 'completed';
$showSentWarning = !$isCompleted && $order['status'] !== 'draft';

// P7a - once any stock has been received, supplier/currency/exchange rate are locked, and any
// line that has received stock has its unit cost locked. Enforced server-side in
// supplier_order_apply_edit(); mirrored here so the fields render disabled with an explanation
// rather than letting a submission fail after the user has typed. Everything else - notes,
// payment status, shipping fee, adding lines, increasing quantities, editing lines with nothing
// received - stays editable exactly as before.
$hasReceivingHistory = supplier_order_has_receiving_history($pdo, $orderId);
$lockSourcingFields = $hasReceivingHistory && !$isCompleted;

$itemsStmt = $pdo->prepare('
    SELECT soi.id, soi.product_id, soi.variation_id, soi.total_quantity, soi.supplier_price, soi.unit_cost_foreign,
           COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name, p.moq AS parent_moq
    FROM supplier_order_items soi
    INNER JOIN products p ON p.id = soi.product_id
    LEFT JOIN product_variations pv ON pv.id = soi.variation_id
    WHERE soi.supplier_order_id = ?
    ORDER BY soi.id ASC
');
$itemsStmt->execute([$orderId]);
$existingDbItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$existingItems = array_map(static function (array $item) use ($pdo): array {
    $variationLabel = $item['variation_id'] !== null ? variation_build_label($pdo, (int) $item['variation_id']) : '';

    return [
        'unit_key' => $item['product_id'] . ':' . (int) ($item['variation_id'] ?? 0),
        'label' => $item['product_name'] . ($variationLabel !== '' ? (' - ' . $variationLabel) : ''),
        'sku' => $item['sku'],
        // MOQ belongs only to the parent product - variations always inherit it.
        'moq' => $item['parent_moq'] !== null ? (int) $item['parent_moq'] : null,
        'quantity' => (string) (int) $item['total_quantity'],
        // Phase 6B (Supplier Order currency) - the "Unit Cost" input represents the order's
        // own currency, so it must be pre-filled from unit_cost_foreign (falling back to
        // supplier_price for a plain MYR line/pre-6B row, where the two are identical anyway).
        'supplier_price' => (string) ($item['unit_cost_foreign'] ?? $item['supplier_price']),
        'received_quantity' => supplier_order_item_received_quantity($pdo, (int) $item['id']),
    ];
}, $existingDbItems);

// Phase 6B (Supplier Order currency) - same short list create.php offers; 'OTHER' reveals a
// free-text code (currency_other below) for anything not in this list.
const SUPPLIER_ORDER_CURRENCY_OPTIONS = ['MYR', 'JPY', 'CNY', 'USD', 'EUR', 'GBP'];

$orderCurrency = (string) ($order['currency'] ?? SYSTEM_SELLING_CURRENCY);
$form = [
    'supplier_id' => (string) $order['supplier_id'],
    'purchase_number' => $order['purchase_number'],
    'notes' => (string) ($order['notes'] ?? ''),
    'shipping_fee' => (string) $order['shipping_fee'],
    'payment_status' => (string) $order['payment_status'],
    'order_date' => (string) ($order['order_date'] ?? ''),
    'currency' => in_array($orderCurrency, SUPPLIER_ORDER_CURRENCY_OPTIONS, true) ? $orderCurrency : 'OTHER',
    'currency_other' => in_array($orderCurrency, SUPPLIER_ORDER_CURRENCY_OPTIONS, true) ? '' : $orderCurrency,
    'exchange_rate' => $order['exchange_rate'] !== null ? (string) $order['exchange_rate'] : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));
    $form['shipping_fee'] = trim((string) ($_POST['shipping_fee'] ?? ''));
    $form['payment_status'] = in_array($_POST['payment_status'] ?? '', SUPPLIER_ORDER_PAYMENT_STATUSES, true) ? $_POST['payment_status'] : $form['payment_status'];
    $form['order_date'] = trim((string) ($_POST['order_date'] ?? $form['order_date']));

    // Shipping fee is purely a Supplier Order-level expense, never tied to
    // products/receiving history - it stays editable even once the order is Completed
    // (unlike products/quantities/unit cost, which lock for receiving-history safety).
    $shippingFee = 0.00;
    if ($error === '') {
        if ($form['shipping_fee'] !== '' && (!is_numeric($form['shipping_fee']) || (float) $form['shipping_fee'] < 0)) {
            $error = 'Shipping fee must be a valid non-negative number.';
        } else {
            $shippingFee = $form['shipping_fee'] !== '' ? round((float) $form['shipping_fee'], 2) : 0.00;
        }
    }

    if ($isCompleted) {
        // Completed: notes + shipping fee + payment status only, nothing else can be posted
        // from this form.
        if ($error === '') {
            $oldShippingFee = (float) $order['shipping_fee'];
            if (abs($oldShippingFee - $shippingFee) > 0.001) {
                supplier_order_log_event($pdo, $orderId, 'Shipping fee changed RM' . number_format($oldShippingFee, 2) . ' -> RM' . number_format($shippingFee, 2));
            }
            if ($form['payment_status'] !== $order['payment_status']) {
                supplier_order_log_event($pdo, $orderId, 'Payment status changed ' . supplier_order_payment_status_label((string) $order['payment_status']) . ' -> ' . supplier_order_payment_status_label($form['payment_status']));
            }

            $pdo->prepare('UPDATE supplier_orders SET notes = ?, shipping_fee = ?, payment_status = ? WHERE id = ?')
                ->execute([$form['notes'] !== '' ? $form['notes'] : null, $shippingFee, $form['payment_status'], $orderId]);
            activity_log($pdo, 'supplier_orders', 'edit', $orderId, 'Edited supplier order ' . $order['purchase_number']);

            app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
        }
    } else {
        $form['supplier_id'] = trim((string) ($_POST['supplier_id'] ?? ''));
        $form['currency'] = trim((string) ($_POST['currency'] ?? SYSTEM_SELLING_CURRENCY));
        $form['currency_other'] = trim((string) ($_POST['currency_other'] ?? ''));
        $form['exchange_rate'] = trim((string) ($_POST['exchange_rate'] ?? ''));

        // Phase 6B (Supplier Order currency) - same 'OTHER' + free-text collapse as create.php.
        // Currency + line-item validation is shared with create.php - see
        // supplier_order_validate_form().
        $formResult = supplier_order_validate_form($pdo, $form, $_POST['unit_key'] ?? [], $_POST['quantity'] ?? [], $_POST['supplier_price'] ?? [], $error);
        $error = $formResult['error'];
        $currency = $formResult['currency'];
        $exchangeRate = $formResult['exchange_rate'];
        $validItems = $formResult['valid_items'];
        $existingItems = $formResult['existing_items'];

        // P7a - supplier_order_validate_form() is shared with create.php and so doesn't carry
        // received quantities. Re-attach them by unit_key before re-rendering, otherwise a
        // validation error would silently unlock the cost inputs on already-received lines.
        // (The server-side guard in supplier_order_apply_edit() still holds either way; this
        // keeps the UI honest rather than being the protection itself.)
        foreach ($existingItems as $index => $existingItem) {
            $originalKey = $existingItem['unit_key'];
            $existingItems[$index]['received_quantity'] = 0;
            foreach ($existingDbItems as $dbItem) {
                if ($dbItem['product_id'] . ':' . (int) ($dbItem['variation_id'] ?? 0) === $originalKey) {
                    $existingItems[$index]['received_quantity'] = supplier_order_item_received_quantity($pdo, (int) $dbItem['id']);
                    break;
                }
            }
        }

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

        if ($error === '' && $validItems === []) {
            $error = 'Add at least one product with a quantity.';
        }

        if ($error === '') {
            $pdo->beginTransaction();

            try {
                supplier_order_apply_edit($pdo, $orderId, $supplierId, $form['notes'], $validItems, $shippingFee, $form['payment_status'], $currency, $exchangeRate, $form['order_date'] !== '' ? $form['order_date'] : null);

                $pdo->commit();
            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                inventory_discard_pending_woocommerce_resync();
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                inventory_discard_pending_woocommerce_resync();

                error_log('[supplier-orders/edit] ' . get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());

                $configPath = dirname(__DIR__, 2) . '/config.php';
                $appConfig = is_file($configPath) ? require $configPath : [];
                $debugEnabled = !empty($appConfig['app']['debug']);

                $error = 'Failed to update supplier order.' . ($debugEnabled ? ' Debug: ' . $exception->getMessage() : '');
            }

            // Same "already committed, must not report failure" protection as
            // modules/supplier-orders/create.php - see its own comment for why.
            if ($error === '') {
                try {
                    inventory_flush_woocommerce_resync($pdo);
                } catch (Throwable $exception) {
                    error_log('[supplier-orders/edit] WooCommerce resync failed after commit for order #' . $orderId . ': ' . $exception->getMessage());
                }

                app_redirect('/modules/supplier-orders/view.php?id=' . $orderId . '&updated=1');
            }
        }
    }
}

$suppliersStmt = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC LIMIT 200');
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$pickerSuppliers = $suppliers;
$pickerCategories = catalog_list_categories_tree($pdo);
$pickerProducts = supplier_order_picker_products($pdo);

// Exchange rate suggestion only - see modules/supplier-orders/create.php's identical comment.
// Never overwrites this order's already-saved exchange_rate (see the JS side's guard); only
// applies if the admin changes the currency and the field is empty.
$supplierRateSuggestions = currency_rates_lookup_batch($pdo, 'supplier', SUPPLIER_ORDER_CURRENCY_OPTIONS);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="mb-1">Edit Supplier Order <?php echo app_escape($order['purchase_number']); ?></h1>
        <p class="page-description"><?php echo supplier_order_status_badge($order['status']); ?></p>
    </div>
    <div class="action-bar">
        <a class="btn btn-outline-secondary btn-sm" href="/modules/supplier-orders/view.php?id=<?php echo (int) $orderId; ?>">Back to Order</a>
    </div>
</div>

<?php if ($showSentWarning): ?>
    <div class="alert alert-warning">This supplier order has already been sent. Changes may require supplier confirmation.</div>
<?php endif; ?>
<?php if ($isCompleted): ?>
    <div class="alert alert-info">This order is Completed - products, quantities, and cost are locked. Only notes can be edited.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<?php if ($isCompleted): ?>
    <div class="card p-4">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Shipping Fee (RM)</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="shipping_fee" value="<?php echo app_escape($form['shipping_fee']); ?>">
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
                    <textarea class="form-control" name="notes" rows="3"><?php echo app_escape($form['notes']); ?></textarea>
                </div>
            </div>
            <button class="btn btn-primary mt-3" type="submit">Save Changes</button>
        </form>
    </div>
<?php else: ?>
    <div class="card p-4">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Supplier</label>
                    <select class="form-select" <?php echo $lockSourcingFields ? 'disabled' : 'name="supplier_id" required'; ?>>
                        <option value="">Select a supplier&hellip;</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>>
                                <?php echo app_escape($supplier['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($lockSourcingFields): ?>
                        <?php /* disabled inputs do not submit - carry the unchanged value explicitly */ ?>
                        <input type="hidden" name="supplier_id" value="<?php echo app_escape($form['supplier_id']); ?>">
                        <div class="form-text">Locked &mdash; stock has already been received against this order.</div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Purchase Number</label>
                    <input type="text" class="form-control" value="<?php echo app_escape($form['purchase_number']); ?>" readonly disabled>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Order Date</label>
                    <input type="date" class="form-control" value="<?php echo app_escape($form['order_date']); ?>"
                           <?php echo $lockSourcingFields ? 'disabled' : 'name="order_date"'; ?>>
                    <?php if ($lockSourcingFields): ?>
                        <?php /* disabled inputs do not submit - carry the unchanged value explicitly */ ?>
                        <input type="hidden" name="order_date" value="<?php echo app_escape($form['order_date']); ?>">
                        <div class="form-text">Locked &mdash; it decides which purchase line supplies landed cost, and stock has already been received.</div>
                    <?php else: ?>
                        <div class="form-text">When the purchase was placed, not when stock arrived. Receiving dates and inventory movements are unaffected.</div>
                    <?php endif; ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Supplier Currency</label>
                    <select class="form-select" id="supplier-order-currency" <?php echo $lockSourcingFields ? 'disabled' : 'name="currency"'; ?>>
                        <?php foreach (SUPPLIER_ORDER_CURRENCY_OPTIONS as $currencyOption): ?>
                            <option value="<?php echo app_escape($currencyOption); ?>" <?php echo $form['currency'] === $currencyOption ? 'selected' : ''; ?>><?php echo app_escape($currencyOption); ?></option>
                        <?php endforeach; ?>
                        <option value="OTHER" <?php echo !in_array($form['currency'], SUPPLIER_ORDER_CURRENCY_OPTIONS, true) ? 'selected' : ''; ?>>Other</option>
                    </select>
                    <?php if ($lockSourcingFields): ?>
                        <?php /* disabled inputs do not submit - carry the unchanged values explicitly */ ?>
                        <input type="hidden" name="currency" value="<?php echo app_escape($form['currency']); ?>">
                        <input type="hidden" name="currency_other" value="<?php echo app_escape($form['currency_other']); ?>">
                        <div class="form-text">Locked &mdash; changing it would rewrite the recorded cost of stock already received.</div>
                    <?php else: ?>
                        <input type="text" class="form-control mt-2<?php echo in_array($form['currency'], SUPPLIER_ORDER_CURRENCY_OPTIONS, true) ? ' d-none' : ''; ?>" id="supplier-order-currency-other" name="currency_other" maxlength="10" placeholder="e.g. KRW" value="<?php echo app_escape($form['currency_other']); ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-3<?php echo $form['currency'] === SYSTEM_SELLING_CURRENCY ? ' d-none' : ''; ?>" id="supplier-order-exchange-rate-wrapper">
                    <label class="form-label" id="supplier-order-exchange-rate-label">Exchange Rate</label>
                    <input type="number" step="0.000001" min="0" class="form-control" id="supplier-order-exchange-rate" value="<?php echo app_escape($form['exchange_rate']); ?>" <?php echo $lockSourcingFields ? 'disabled' : 'name="exchange_rate"'; ?>>
                    <?php if ($lockSourcingFields): ?>
                        <input type="hidden" name="exchange_rate" value="<?php echo app_escape($form['exchange_rate']); ?>">
                        <div class="form-text">Locked &mdash; stock has already been received at this rate.</div>
                    <?php else: ?>
                        <div class="form-text">e.g. 1 JPY = 0.03 <?php echo app_escape(SYSTEM_SELLING_CURRENCY); ?></div>
                    <?php endif; ?>
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
            <p class="text-muted small">A line that already has received quantity can be increased but not removed or reduced below what's already been received.</p>
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

            <button class="btn btn-primary mt-3" type="submit">Save Changes</button>
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
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>