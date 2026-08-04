<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/shipments.php';
require_once __DIR__ . '/../../includes/orders.php';
app_require_permission('shipments.view');

$appTitle = 'Shipment Detail';
$error = '';

$shipmentId = (int) ($_GET['id'] ?? 0);

if ($shipmentId < 1) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Shipment not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo = app_db();
$shipment = shipment_get($pdo, $shipmentId);

if (!$shipment) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-danger">Shipment not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$canManage = app_has_permission('shipments.manage');
// Separate from $canManage above: the order links in the items table below go to
// modules/orders/view.php, which requires orders.view - the destination controls
// permission, not this page's own shipments.view/manage gate.
$canViewOrders = app_has_permission('orders.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && !$canManage) {
        http_response_code(403);
        $error = 'You do not have permission to manage shipments.';
    }

    if ($error === '') {
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'mark_packed') {
                $pdo->beginTransaction();
                shipment_mark_packed($pdo, $shipmentId);
                $pdo->commit();
            } elseif ($action === 'mark_shipped') {
                $carrier = trim((string) ($_POST['carrier'] ?? ''));
                $trackingNumber = trim((string) ($_POST['tracking_number'] ?? ''));
                if ($carrier === '' || $trackingNumber === '') {
                    throw new RuntimeException('Carrier and tracking number are required.');
                }
                $shippedDateInput = trim((string) ($_POST['shipped_date'] ?? ''));
                $shippedAt = $shippedDateInput !== '' ? $shippedDateInput . ' ' . date('H:i:s') : null;

                $pdo->beginTransaction();
                shipment_mark_shipped($pdo, $shipmentId, $carrier, $trackingNumber, $shippedAt);
                $pdo->commit();
                inventory_flush_woocommerce_resync($pdo);
            } elseif ($action === 'mark_delivered') {
                $pdo->beginTransaction();
                shipment_mark_delivered($pdo, $shipmentId);
                $pdo->commit();
            } elseif ($action === 'cancel') {
                $pdo->beginTransaction();
                shipment_cancel($pdo, $shipmentId);
                $pdo->commit();
            } elseif ($action === 'update_tracking') {
                $carrier = trim((string) ($_POST['carrier'] ?? ''));
                $trackingNumber = trim((string) ($_POST['tracking_number'] ?? ''));

                $pdo->beginTransaction();
                shipment_update_tracking($pdo, $shipmentId, $carrier, $trackingNumber);
                $pdo->commit();
            } else {
                $error = 'Unknown action.';
            }

            if ($error === '') {
                app_redirect('/modules/shipments/view.php?id=' . $shipmentId . '&updated=1');
            }
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            inventory_discard_pending_woocommerce_resync();
            $error = $exception->getMessage();
        } catch (Exception $exception) {
            $pdo->rollBack();
            inventory_discard_pending_woocommerce_resync();
            $error = 'Failed to update shipment.';
        }
    }

    if ($error !== '') {
        $shipment = shipment_get($pdo, $shipmentId);
    }
}

$items = shipment_list_items($pdo, $shipmentId);
$events = shipment_list_events($pdo, $shipmentId);

// Display default for the Confirm Shipped form's carrier field only - the same default
// modules/shipments/index.php's inline dispatch already used. Fetched after the POST handler
// above so a shipment dispatched on this very request is itself the most recent one. The "edit
// tracking info" form further down deliberately does NOT use this: it shows this shipment's own
// stored carrier.
$lastCarrier = shipment_last_used_carrier($pdo);

// UI/UX Phase 5C: package/order summary counts - purely derived from $items already fetched
// above, no new query.
$packageItemCount = count($items);
$packageQty = 0;
$packageOrderIds = [];
foreach ($items as $item) {
    $packageQty += (int) $item['quantity'];
    if (!empty($item['order_id'])) {
        $packageOrderIds[(int) $item['order_id']] = true;
    }
}
$packageOrderCount = count($packageOrderIds);

// UI/UX Phase 5C: small icon per timeline event type - purely presentational, event_type
// values/data are read exactly as stored, nothing is renamed or reinterpreted.
$shipmentEventIcons = [
    'shipment_created' => 'bi-plus-circle',
    'packed' => 'bi-box-seam',
    'shipped' => 'bi-truck',
    'delivered' => 'bi-check-circle',
    'cancelled' => 'bi-x-circle',
    'carrier_changed' => 'bi-pencil',
    'tracking_updated' => 'bi-pencil',
];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2 class="mb-1">
            Shipment <?php echo app_escape($shipment['shipment_number']); ?>
            <?php echo shipment_status_badge($shipment['shipping_status']); ?>
        </h2>
        <p class="page-description"><?php echo app_escape($shipment['customer_name']); ?> &middot; <?php echo app_escape($shipment['customer_email'] ?? '-'); ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/shipments/index.php">Back to Shipments</a>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Shipment created.</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Shipment updated.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <h5 class="mb-3"><i class="bi bi-info-circle"></i> Shipment Info</h5>
            <table class="table table-borderless mb-0">
                <tr><th>Source</th><td><?php echo app_escape(ucfirst(str_replace('_', ' ', $shipment['source_type']))); ?></td></tr>
                <tr><th>Orders</th><td><?php echo (int) $packageOrderCount; ?></td></tr>
                <tr><th>Items</th><td><?php echo (int) $packageItemCount; ?> line<?php echo $packageItemCount === 1 ? '' : 's'; ?> &middot; <?php echo (int) $packageQty; ?> unit<?php echo $packageQty === 1 ? '' : 's'; ?></td></tr>
                <tr><th>Shipped Date</th><td><?php echo $shipment['shipped_at'] !== null ? app_escape(date('j F Y', strtotime($shipment['shipped_at']))) : '&mdash;'; ?></td></tr>
                <tr><th>Created</th><td><?php echo app_escape($shipment['created_at']); ?></td></tr>
            </table>
        </div>

        <div class="card p-4 mb-4">
            <h5 class="mb-3"><i class="bi bi-geo-alt"></i> Tracking</h5>
            <?php if ($shipment['tracking_number'] !== null): ?>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold fs-5"><?php echo app_escape($shipment['tracking_number']); ?></div>
                        <div class="text-muted"><?php echo $shipment['carrier'] !== null ? app_escape($shipment['carrier']) : 'Carrier not set'; ?></div>
                    </div>
                    <?php echo shipment_status_badge($shipment['shipping_status']); ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No tracking number assigned yet<?php echo $canManage ? ' - use "Confirm Shipped" to add one.' : '.'; ?></p>
            <?php endif; ?>
        </div>

        <div class="card p-4">
            <h5 class="mb-3"><i class="bi bi-list-check"></i> Items</h5>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Order</th>
                        <th>Qty</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo app_escape($item['sku']); ?></td>
                            <td>
                                <?php echo app_escape($item['product_name']); ?>
                                <?php if (!empty($item['variation_label'])): ?>
                                    <div class="text-muted small"><?php echo app_escape($item['variation_label']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['order_number']) && $canViewOrders): ?>
                                    <a href="/modules/orders/view.php?id=<?php echo (int) $item['order_id']; ?>"><?php echo app_escape(order_display_number_compact($item['order_number'])); ?></a>
                                <?php elseif (!empty($item['order_number'])): ?>
                                    <?php echo app_escape(order_display_number_compact($item['order_number'])); ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int) $item['quantity']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($items === []): ?>
                        <tr><td colspan="4" class="text-muted">No items on this shipment.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($canManage): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3"><i class="bi bi-lightning-charge"></i> Actions</h5>

                <?php if ($shipment['shipping_status'] === 'pending'): ?>
                    <form method="post" class="mb-2 d-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="mark_packed">
                        <button type="submit" class="btn btn-primary btn-sm">Mark Packed</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($shipment['shipping_status'], ['pending', 'packed'], true)): ?>
                    <form method="post" class="mb-3 border rounded p-3">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="mark_shipped">
                        <div class="mb-2">
                            <label class="form-label">Carrier</label>
                            <?php /* Defaults to the last courier actually dispatched with - see
                                     shipment_last_used_carrier(). Still required, so an empty
                                     default (nothing shipped yet) forces the operator to enter one. */ ?>
                            <input type="text" class="form-control form-control-sm" name="carrier" value="<?php echo app_escape($lastCarrier); ?>" placeholder="e.g. Ninja Van, J&amp;T Express, Pos Laju" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Tracking Number</label>
                            <input type="text" class="form-control form-control-sm" name="tracking_number" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Shipped Date</label>
                            <input type="date" class="form-control form-control-sm" name="shipped_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">Confirm Shipped</button>
                    </form>

                    <form method="post" class="d-grid" onsubmit="return confirm('Cancel this shipment? Its items become available for a future shipment again.');">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Cancel Shipment</button>
                    </form>
                <?php endif; ?>

                <?php if ($shipment['shipping_status'] === 'shipped'): ?>
                    <form method="post" class="mb-2 d-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="mark_delivered">
                        <button type="submit" class="btn btn-success btn-sm">Mark Delivered</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($shipment['shipping_status'], ['shipped', 'delivered'], true)): ?>
                    <form method="post" class="mt-3 border-top pt-3">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_tracking">
                        <label class="form-label small text-muted">Edit tracking info</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <input type="text" class="form-control form-control-sm" style="max-width: 160px;" name="carrier" value="<?php echo app_escape($shipment['carrier'] ?? ''); ?>" placeholder="Carrier">
                            <input type="text" class="form-control form-control-sm" style="max-width: 160px;" name="tracking_number" value="<?php echo app_escape($shipment['tracking_number'] ?? ''); ?>" placeholder="Tracking number">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Save</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($shipment['shipping_status'] === 'cancelled'): ?>
                    <span class="badge bg-secondary">Cancelled</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card p-4">
            <h5 class="mb-3"><i class="bi bi-clock-history"></i> Shipment Timeline</h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($events as $event): ?>
                    <li class="mb-3 d-flex gap-2">
                        <i class="bi <?php echo $shipmentEventIcons[$event['event_type']] ?? 'bi-dot'; ?> text-muted mt-1"></i>
                        <div>
                            <div class="fw-semibold"><?php echo app_escape(ucfirst(str_replace('_', ' ', $event['event_type']))); ?></div>
                            <?php if (!empty($event['notes'])): ?>
                                <div><?php echo app_escape($event['notes']); ?></div>
                            <?php endif; ?>
                            <div class="text-muted small">
                                <?php echo app_escape($event['created_at']); ?>
                                <?php if (!empty($event['user_name'])): ?>
                                    &middot; <?php echo app_escape($event['user_name']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if ($events === []): ?>
                    <li class="text-muted">No events recorded yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
