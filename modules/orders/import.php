<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/csv_import.php';
require_once __DIR__ . '/../../includes/order_import.php';
require_once __DIR__ . '/../../includes/product_variations.php';
app_require_permission('orders.manage');

/**
 * CSV import for historical Customer Orders + Order Items in one file (one row = one
 * line item; order-level columns repeat on every row that shares the same order_number -
 * a common single-file convention that avoids needing two correlated uploads). Same
 * all-or-nothing shape as the other import tools: every row across every group is
 * validated before order_import_create() is called for any of them.
 */

$appTitle = 'Import Historical Orders';
$error = '';
$rowErrors = [];
$importedOrders = 0;

$orderStatusOptions = array_merge(ORDER_STATUS_WORKFLOW, ['cancelled']);
$paymentStatusOptions = ['pending', 'paid', 'refunded', 'failed'];
$shippingStatusOptions = ['pending', 'packed', 'shipped', 'delivered'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK)) {
        $error = 'Choose a CSV file to upload.';
    }

    if ($error === '') {
        $pdo = app_db();

        try {
            $parsed = csv_import_read_rows($_FILES['csv_file']['tmp_name']);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        if ($error === '') {
            $rows = $parsed['rows'];
            if ($rows === []) {
                $error = 'The CSV file has no data rows.';
            }
        }

        if ($error === '') {
            $sellableUnits = catalog_sellable_units($pdo);
            $unitsBySku = [];
            foreach ($sellableUnits as $unit) {
                $unitsBySku[strtoupper($unit['sku'])] = $unit;
            }

            $existingOrderNumbersStmt = $pdo->query('SELECT UPPER(order_number) FROM mewmii_orders');
            $existingOrderNumbers = array_flip($existingOrderNumbersStmt->fetchAll(PDO::FETCH_COLUMN));

            // Group rows by order_number, preserving first-seen order for a stable
            // "Row N" reference back to the uploaded file.
            $groups = [];
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $orderNumber = trim((string) ($row['order_number'] ?? ''));
                if ($orderNumber === '') {
                    $rowErrors[] = "Row {$rowNum}: order_number is required.";
                    continue;
                }
                $groups[$orderNumber]['rows'][] = ['row_num' => $rowNum, 'data' => $row];
            }

            foreach ($groups as $orderNumber => $group) {
                if (isset($existingOrderNumbers[strtoupper($orderNumber)])) {
                    $rowErrors[] = "Order {$orderNumber}: this order number already exists.";
                    continue;
                }

                $first = $group['rows'][0]['data'];
                $customerEmail = trim((string) ($first['customer_email'] ?? ''));
                $customerName = trim((string) ($first['customer_name'] ?? ''));
                $customerId = null;

                if ($customerEmail !== '') {
                    $custStmt = $pdo->prepare('SELECT id FROM customers WHERE LOWER(email) = LOWER(?) LIMIT 1');
                    $custStmt->execute([$customerEmail]);
                    $customerId = $custStmt->fetchColumn() ?: null;
                }
                if ($customerId === null && $customerName !== '') {
                    $custStmt = $pdo->prepare('SELECT id FROM customers WHERE LOWER(name) = LOWER(?) LIMIT 1');
                    $custStmt->execute([$customerName]);
                    $customerId = $custStmt->fetchColumn() ?: null;
                }
                if ($customerId === null) {
                    $rowErrors[] = "Order {$orderNumber}: no matching customer found for customer_email/customer_name (\"{$customerEmail}{$customerName}\") - import the customer first.";
                }

                $orderDate = trim((string) ($first['order_date'] ?? ''));
                if ($orderDate === '' || !DateTime::createFromFormat('Y-m-d', $orderDate)) {
                    $rowErrors[] = "Order {$orderNumber}: order_date is required and must be YYYY-MM-DD.";
                }

                $orderStatus = trim((string) ($first['order_status'] ?? ''));
                if (!in_array($orderStatus, $orderStatusOptions, true)) {
                    $rowErrors[] = "Order {$orderNumber}: order_status must be one of: " . implode(', ', $orderStatusOptions) . '.';
                }
                $paymentStatus = trim((string) ($first['payment_status'] ?? ''));
                if (!in_array($paymentStatus, $paymentStatusOptions, true)) {
                    $rowErrors[] = "Order {$orderNumber}: payment_status must be one of: " . implode(', ', $paymentStatusOptions) . '.';
                }
                $shippingStatus = trim((string) ($first['shipping_status'] ?? ''));
                if (!in_array($shippingStatus, $shippingStatusOptions, true)) {
                    $rowErrors[] = "Order {$orderNumber}: shipping_status must be one of: " . implode(', ', $shippingStatusOptions) . '.';
                }

                $items = [];
                foreach ($group['rows'] as $entry) {
                    $rowNum = $entry['row_num'];
                    $row = $entry['data'];
                    $sku = trim((string) ($row['sku'] ?? ''));
                    $quantity = (int) ($row['quantity'] ?? 0);
                    $unitPrice = trim((string) ($row['unit_price'] ?? ''));

                    if ($sku === '' || !isset($unitsBySku[strtoupper($sku)])) {
                        $rowErrors[] = "Row {$rowNum}: sku \"{$sku}\" was not found among sellable products/variations.";
                        continue;
                    }
                    if ($quantity < 1) {
                        $rowErrors[] = "Row {$rowNum}: quantity must be at least 1.";
                        continue;
                    }
                    if ($unitPrice === '' || !is_numeric($unitPrice) || (float) $unitPrice < 0) {
                        $rowErrors[] = "Row {$rowNum}: unit_price must be a valid non-negative number.";
                        continue;
                    }

                    $unit = $unitsBySku[strtoupper($sku)];
                    $items[] = [
                        'product_id' => $unit['product_id'],
                        'variation_id' => $unit['variation_id'],
                        'quantity' => $quantity,
                        'selling_price' => (float) $unitPrice,
                        'discount' => (float) ($row['item_discount'] ?? 0),
                        'cost_snapshot' => $unit['cost_price'],
                    ];
                }

                $groups[$orderNumber]['prepared'] = [
                    'data' => [
                        'order_number' => $orderNumber,
                        'customer_id' => $customerId,
                        'order_date' => $orderDate,
                        'payment_date' => trim((string) ($first['payment_date'] ?? '')),
                        'fulfillment_date' => trim((string) ($first['fulfillment_date'] ?? '')),
                        'order_status' => $orderStatus,
                        'payment_status' => $paymentStatus,
                        'shipping_status' => $shippingStatus,
                        'shipping_fee' => (float) ($first['shipping_fee'] ?? 0),
                        'discount' => (float) ($first['order_discount'] ?? 0),
                        'customer_note' => trim((string) ($first['customer_note'] ?? '')),
                        'internal_note' => trim((string) ($first['internal_note'] ?? '')),
                    ],
                    'items' => $items,
                ];
            }

            if ($rowErrors === [] && $groups !== []) {
                $pdo->beginTransaction();

                try {
                    foreach ($groups as $group) {
                        order_import_create($pdo, $group['prepared']['data'], $group['prepared']['items']);
                        $importedOrders++;
                    }

                    $pdo->commit();
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $error = 'Import failed: ' . $exception->getMessage();
                    $importedOrders = 0;
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Import failed.';
                    $importedOrders = 0;
                }
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Import Historical Orders</h2>
        <p class="text-muted mb-0">CSV only, one row per order item - rows sharing the same order_number become one order. Never reserves/ships/releases inventory.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/orders/index.php">Back to Orders</a>
</div>

<?php if ($importedOrders > 0): ?>
    <div class="alert alert-success"><?php echo $importedOrders; ?> historical order(s) imported successfully.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if ($rowErrors !== []): ?>
    <div class="alert alert-danger">
        <p class="mb-2">The file was <strong>not</strong> imported - fix these rows and re-upload:</p>
        <ul class="mb-0">
            <?php foreach ($rowErrors as $rowError): ?>
                <li><?php echo app_escape($rowError); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card p-4">
    <h5 class="mb-3">Upload CSV</h5>
    <p class="text-muted small mb-2">One row per order item. Columns (header row required):</p>
    <ul class="text-muted small">
        <li><code>order_number</code>, <code>customer_email</code> or <code>customer_name</code> (customer must already exist), <code>order_date</code> (YYYY-MM-DD)</li>
        <li><code>payment_date</code>, <code>fulfillment_date</code> (optional, YYYY-MM-DD)</li>
        <li><code>order_status</code> (<?php echo app_escape(implode('/', $orderStatusOptions)); ?>), <code>payment_status</code> (<?php echo app_escape(implode('/', $paymentStatusOptions)); ?>), <code>shipping_status</code> (<?php echo app_escape(implode('/', $shippingStatusOptions)); ?>)</li>
        <li><code>shipping_fee</code>, <code>order_discount</code>, <code>customer_note</code>, <code>internal_note</code> (order-level - only the first row per order_number is used)</li>
        <li><code>sku</code> (product or variation SKU), <code>quantity</code>, <code>unit_price</code>, <code>item_discount</code> (per line item)</li>
    </ul>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <div class="mb-3">
            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" class="btn btn-primary">Validate &amp; Import</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
