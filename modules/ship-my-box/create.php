<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/product_variations.php';
require_once __DIR__ . '/../../includes/ship_my_box.php';
app_require_permission('ship-my-box.manage');

$appTitle = 'New Ship Request';
$error = '';
$pdo = app_db();

$customerId = (int) ($_GET['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $postedQuantities = $_POST['quantity'] ?? [];

    if ($error === '' && $customerId < 1) {
        $error = 'Select a customer.';
    }

    $lines = [];
    if ($error === '' && is_array($postedQuantities)) {
        foreach ($postedQuantities as $storageId => $qty) {
            $storageId = (int) $storageId;
            $qty = trim((string) $qty);

            if ($storageId < 1 || $qty === '' || $qty === '0') {
                continue;
            }

            if (!ctype_digit($qty) || (int) $qty < 1) {
                $error = 'Quantities must be whole numbers of at least 1.';
                break;
            }

            $lines[$storageId] = (int) $qty;
        }
    }

    if ($error === '' && $lines === []) {
        $error = 'Select at least one item and quantity to ship.';
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            $customerCheck = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE id = ?');
            $customerCheck->execute([$customerId]);
            if ((int) $customerCheck->fetchColumn() === 0) {
                throw new RuntimeException('Selected customer does not exist.');
            }

            $requestNumber = ship_request_generate_number();
            $requestStmt = $pdo->prepare("INSERT INTO ship_requests (request_number, customer_id, status) VALUES (?, ?, 'pending')");
            $requestStmt->execute([$requestNumber, $customerId]);
            $shipRequestId = (int) $pdo->lastInsertId();

            $storageStmt = $pdo->prepare("SELECT * FROM customer_storage WHERE id = ? AND customer_id = ? AND status = 'stored' FOR UPDATE");
            $itemStmt = $pdo->prepare('INSERT INTO ship_request_items (ship_request_id, customer_storage_id, order_id, order_item_id, quantity) VALUES (?, ?, ?, ?, ?)');
            $orderItemStmt = $pdo->prepare('SELECT order_id FROM mewmii_order_items WHERE id = ?');

            foreach ($lines as $storageId => $qty) {
                $storageStmt->execute([$storageId, $customerId]);
                $storageRow = $storageStmt->fetch(PDO::FETCH_ASSOC);

                if (!$storageRow) {
                    throw new RuntimeException('Storage record #' . $storageId . ' is not available for this customer.');
                }

                // Nets out whatever's already committed to another OPEN (not yet shipped)
                // ship request on this same lot - shipment_create() only catches this later,
                // when this ship request is actually processed (see
                // ship_request_storage_lot_available() in includes/ship_my_box.php) - checking
                // it here instead gives an immediate, specific error at creation time.
                $availableToShip = ship_request_storage_lot_available($pdo, $storageId);
                if ($qty > $availableToShip) {
                    throw new RuntimeException('Requested quantity (' . $qty . ') for storage record #' . $storageId . ' exceeds what is still available (' . $availableToShip . ') - some of it is already committed to another pending ship request.');
                }

                // order_id/order_item_id are denormalized from the storage lot's own
                // order_item_id (which may itself be NULL for manually-added storage) purely
                // for query/reporting convenience - customer_storage_id remains the one
                // authoritative link (see includes/ship_my_box.php).
                $orderItemId = $storageRow['order_item_id'] !== null ? (int) $storageRow['order_item_id'] : null;
                $orderId = null;
                if ($orderItemId !== null) {
                    $orderItemStmt->execute([$orderItemId]);
                    $foundOrderId = $orderItemStmt->fetchColumn();
                    $orderId = $foundOrderId !== false ? (int) $foundOrderId : null;
                }

                $itemStmt->execute([$shipRequestId, $storageId, $orderId, $orderItemId, $qty]);
            }

            $pdo->commit();

            app_redirect('/modules/ship-my-box/view.php?id=' . $shipRequestId . '&created=1');
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            $pdo->rollBack();
            $error = 'Failed to create ship request.';
        }
    }
}

// Pins the ?customer_id= customer so the Customer Storage shortcuts always resolve. See
// app_customer_options().
$allCustomers = app_customer_options($pdo, $customerId > 0 ? $customerId : null);

$storedItems = [];
$selectedCustomer = null;

if ($customerId > 0) {
    $customerStmt = $pdo->prepare('SELECT id, name, email FROM customers WHERE id = ? LIMIT 1');
    $customerStmt->execute([$customerId]);
    $selectedCustomer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedCustomer) {
        $storedStmt = $pdo->prepare("
            SELECT cs.id, cs.quantity, cs.arrival_date, cs.variation_id,
                   COALESCE(pv.sku, p.sku) AS sku, p.name AS product_name
            FROM customer_storage cs
            INNER JOIN products p ON p.id = cs.product_id
            LEFT JOIN product_variations pv ON pv.id = cs.variation_id
            WHERE cs.customer_id = ? AND cs.status = 'stored' AND cs.quantity > 0
            ORDER BY cs.created_at DESC
        ");
        $storedStmt->execute([$customerId]);
        $storedItems = $storedStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($storedItems as &$storedItem) {
            $storedItem['variation_label'] = $storedItem['variation_id'] !== null ? variation_build_label($pdo, (int) $storedItem['variation_id']) : '';
            // How much of this lot is actually still free to commit - the SAME function the POST
            // handler above already validates against, so the form can never offer a quantity the
            // server would reject. The lot's raw quantity is not that number: another still-open
            // ship request may already have a claim on part of it.
            $storedItem['available'] = ship_request_storage_lot_available($pdo, (int) $storedItem['id']);
        }
        unset($storedItem);
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">New Ship Request</h2>
        <p class="text-muted mb-0">Select a customer, then choose which stored items to ship.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/ship-my-box/index.php">Back to Ship My Box</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Customer</h5>
    <form method="get" class="d-flex gap-2">
        <select class="form-select" name="customer_id" required>
            <option value="">Select a customer&hellip;</option>
            <?php foreach ($allCustomers as $customer): ?>
                <option value="<?php echo (int) $customer['id']; ?>" <?php echo $customerId === (int) $customer['id'] ? 'selected' : ''; ?>>
                    <?php echo app_escape($customer['name']); ?><?php if (!empty($customer['email'])): ?> (<?php echo app_escape($customer['email']); ?>)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-primary" type="submit">Load Stored Items</button>
    </form>
</div>

<?php if ($selectedCustomer): ?>
    <div class="card p-4">
        <h5 class="mb-3">Stored Items for <?php echo app_escape($selectedCustomer['name']); ?></h5>

        <?php if ($storedItems === []): ?>
            <p class="text-muted mb-0">This customer has no items currently in storage.</p>
        <?php else: ?>
            <?php
            // Shipping a customer's whole box is the normal case and a partial box the exception,
            // so quantities are pre-filled to each lot's available amount instead of 0 -
            // previously an operator had to type a number into every row before the form could do
            // anything. Same form default already used by modules/shipments/create.php.
            //
            // This changes only the FORM DEFAULT and its ceiling. The POST handler above still
            // re-checks every line against ship_request_storage_lot_available() before creating
            // anything, so nothing can be over-committed.
            $prefillTotal = 0;
            foreach ($storedItems as $item) {
                $prefillTotal += (int) $item['available'];
            }
            ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="customer_id" value="<?php echo (int) $selectedCustomer['id']; ?>">

                <?php if ($prefillTotal > 0): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted small">
                            <strong id="ship-request-selected-total"><?php echo (int) $prefillTotal; ?></strong> of <?php echo (int) $prefillTotal; ?> available unit<?php echo $prefillTotal === 1 ? '' : 's'; ?> selected
                        </span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="ship-request-select-all-qty">Ship All Available</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="ship-request-clear-qty">Clear</button>
                        </div>
                    </div>
                <?php endif; ?>

                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product</th>
                            <th>Arrival</th>
                            <th>Stored Qty</th>
                            <th>Available To Ship</th>
                            <th>Qty to Ship</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($storedItems as $item): ?>
                            <?php $available = (int) $item['available']; ?>
                            <tr>
                                <td><?php echo app_escape($item['sku']); ?></td>
                                <td>
                                    <?php echo app_escape($item['product_name']); ?>
                                    <?php if (!empty($item['variation_label'])): ?>
                                        <div class="text-muted small"><?php echo app_escape($item['variation_label']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo app_escape($item['arrival_date'] ?? '-'); ?></td>
                                <td><?php echo app_escape((string) $item['quantity']); ?></td>
                                <td>
                                    <?php echo $available; ?>
                                    <?php if ($available < (int) $item['quantity']): ?>
                                        <div class="text-muted small">
                                            <?php echo (int) $item['quantity'] - $available; ?> committed to a pending ship request
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="number" class="form-control ship-request-qty" style="max-width: 120px;"
                                           name="quantity[<?php echo (int) $item['id']; ?>]"
                                           min="0" max="<?php echo $available; ?>" value="<?php echo $available; ?>"
                                           data-available="<?php echo $available; ?>"
                                           <?php echo $available < 1 ? 'disabled' : ''; ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <button class="btn btn-primary mt-3" type="submit">Create Ship Request</button>
            </form>

            <script>
            // Picking helpers for the pre-filled quantities above. Progressive enhancement only -
            // the quantities are already correct server-side, so with JS off the form still submits
            // a full box; these buttons just make adjusting a partial one fast. Same mechanism as
            // modules/shipments/create.php.
            (function () {
                var inputs = document.querySelectorAll('.ship-request-qty');
                if (!inputs.length) { return; }
                var totalEl = document.getElementById('ship-request-selected-total');
                var allBtn = document.getElementById('ship-request-select-all-qty');
                var clearBtn = document.getElementById('ship-request-clear-qty');

                function sync() {
                    if (!totalEl) { return; }
                    var total = 0;
                    for (var i = 0; i < inputs.length; i++) {
                        var v = parseInt(inputs[i].value, 10);
                        if (!isNaN(v) && v > 0) { total += v; }
                    }
                    totalEl.textContent = String(total);
                }

                function setAll(useAvailable) {
                    for (var i = 0; i < inputs.length; i++) {
                        if (inputs[i].disabled) { continue; }
                        inputs[i].value = useAvailable ? (inputs[i].getAttribute('data-available') || '0') : '0';
                    }
                    sync();
                }

                if (allBtn) { allBtn.addEventListener('click', function () { setAll(true); }); }
                if (clearBtn) { clearBtn.addEventListener('click', function () { setAll(false); }); }
                for (var i = 0; i < inputs.length; i++) { inputs[i].addEventListener('input', sync); }
                sync();
            })();
            </script>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
