<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/order_resolution.php';

/**
 * Customer Order Resolution System - the public, tokenized customer-facing page (Part 3: "Use
 * tokenized customer pages hosted by Mewmii OS first" - MewmiiBear.com's own codebase isn't
 * available in this repository, see the architecture decision this phase started from).
 *
 * Deliberately does NOT call app_require_login()/app_require_permission() - there is no
 * customer login system in Mewmii OS at all (confirmed by audit). The token IS the
 * authentication: resolution_find_by_token() is the only way this page ever learns which
 * resolution_requests row it's looking at, and every action below re-derives the resolution
 * from that same token on every request (GET and POST) rather than trusting a resolution_id/
 * item_id posted directly - this is what "customer can only access their own orders" and
 * "token only accesses one resolution request" mean in practice. CSRF protection still applies
 * normally (app_csrf_token()/app_require_csrf() read the session, which exists for anonymous
 * visitors too - no special-casing needed).
 *
 * Reuses includes/header.php/footer.php for the page shell exactly like login.php does (both
 * already branch on app_is_logged_in() internally, degrading to a sidebar-less shell for an
 * anonymous visitor) - no separate customer-facing layout was built.
 */

$pdo = app_db();
$rawToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$resolution = $rawToken !== '' ? resolution_find_by_token($pdo, $rawToken) : null;

$appTitle = 'Order Resolution';
$error = '';
$success = '';

if ($resolution === null) {
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card p-4">
                <h2 class="mb-3">Link not valid</h2>
                <p class="text-muted">This resolution link is invalid or has expired. Please contact us for a new link.</p>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$resolutionId = (int) $resolution['id'];

$orderStmt = $pdo->prepare('SELECT id, order_number, customer_id FROM mewmii_orders WHERE id = ?');
$orderStmt->execute([(int) $resolution['order_id']]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    $itemId = (int) ($_POST['item_id'] ?? 0);

    // Ownership: the posted item_id must belong to THIS token's resolution - never trusted on
    // its own. This is re-checked on every single POST, not cached from the GET render.
    $itemBelongsToResolution = false;
    foreach (resolution_list_items($pdo, $resolutionId) as $ownedItem) {
        if ((int) $ownedItem['id'] === $itemId) {
            $itemBelongsToResolution = true;
            break;
        }
    }
    if ($error === '' && !$itemBelongsToResolution) {
        $error = 'Invalid item.';
    }

    if ($error === '' && isset($_POST['choose_replacement'])) {
        try {
            resolution_customer_choose_replacement($pdo, $itemId, (int) ($_POST['variation_id'] ?? 0));
            $success = 'Your choice has been recorded.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($error === '' && isset($_POST['choose_store_credit'])) {
        try {
            resolution_customer_choose_store_credit($pdo, $itemId);
            $success = 'Store credit has been added to your wallet.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($error === '' && isset($_POST['choose_refund'])) {
        try {
            resolution_customer_choose_refund($pdo, $itemId);
            $success = 'Your refund request has been submitted.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($error === '' && isset($_POST['choose_difference_credit'])) {
        try {
            resolution_customer_choose_difference_credit($pdo, $itemId);
            $success = 'The price difference has been added to your wallet as store credit.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($error === '' && isset($_POST['choose_difference_refund'])) {
        try {
            resolution_customer_choose_difference_refund($pdo, $itemId);
            $success = 'Your refund request for the price difference has been submitted.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($error === '' && isset($_POST['upload_receipt'])) {
        try {
            resolution_upload_receipt($pdo, $itemId, $_FILES['receipt'] ?? []);
            $success = 'Receipt uploaded - we will review it shortly.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }
}

$items = resolution_list_items($pdo, $resolutionId);
$walletBalance = $order !== false && $order['customer_id'] !== null ? wallet_balance($pdo, (int) $order['customer_id']) : null;

require_once __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card p-4 mb-4">
            <h2 class="mb-1">Order Resolution</h2>
            <p class="text-muted mb-0">Order <?php echo app_escape($order['order_number'] ?? ('#' . (int) $resolution['order_id'])); ?></p>
            <?php if (!empty($resolution['reason'])): ?>
                <p class="text-muted small mb-0">Reason: <?php echo app_escape($resolution['reason']); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?php echo app_escape($success); ?></div>
        <?php endif; ?>

        <?php if ($resolution['status'] === 'resolved'): ?>
            <div class="alert alert-info">All items in this resolution request have been resolved. Thank you!</div>
        <?php else: ?>
            <p class="text-muted">Your ordered item is currently unavailable. Please choose an option below.</p>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
            <?php
            $orderItemStmt = $pdo->prepare('SELECT product_id FROM mewmii_order_items WHERE id = ?');
            $orderItemStmt->execute([(int) $item['order_item_id']]);
            $productId = (int) $orderItemStmt->fetchColumn();
            $replacementOptions = $item['status'] === 'pending'
                ? resolution_available_replacement_variations($pdo, $productId, $item['original_variation_id'] !== null ? (int) $item['original_variation_id'] : null)
                : [];
            ?>
            <div class="card p-4 mb-3">
                <h5 class="mb-1"><?php echo app_escape($item['product_name']); ?></h5>
                <?php if (!empty($item['order_item_variation_label'])): ?>
                    <p class="text-muted mb-1">Original variation: <?php echo app_escape($item['order_item_variation_label']); ?></p>
                <?php endif; ?>
                <p class="mb-3">Price: RM <?php echo number_format((float) $item['original_price'], 2); ?></p>

                <?php if ($item['status'] === 'pending'): ?>
                    <?php if ($replacementOptions !== []): ?>
                        <h6>Option A: Replace with another variation</h6>
                        <form method="post" class="mb-3">
                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                            <input type="hidden" name="token" value="<?php echo app_escape($rawToken); ?>">
                            <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                            <input type="hidden" name="choose_replacement" value="1">
                            <div class="list-group mb-2">
                                <?php foreach ($replacementOptions as $option): ?>
                                    <label class="list-group-item">
                                        <input type="radio" name="variation_id" value="<?php echo (int) $option['id']; ?>" class="form-check-input me-2" required>
                                        <?php echo app_escape($option['label']); ?> - RM <?php echo number_format($option['price'], 2); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Choose this variation</button>
                        </form>
                    <?php endif; ?>

                    <h6>Option B: Receive store credit</h6>
                    <form method="post" class="mb-3">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="token" value="<?php echo app_escape($rawToken); ?>">
                        <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                        <input type="hidden" name="choose_store_credit" value="1">
                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirm('Add RM<?php echo number_format((float) $item['original_price'], 2); ?> to your store credit wallet?');">Get RM<?php echo number_format((float) $item['original_price'], 2); ?> store credit</button>
                    </form>

                    <h6>Option C: Request refund</h6>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="token" value="<?php echo app_escape($rawToken); ?>">
                        <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                        <input type="hidden" name="choose_refund" value="1">
                        <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Request a refund of RM<?php echo number_format((float) $item['original_price'], 2); ?>?');">Request refund</button>
                    </form>

                <?php elseif ($item['status'] === 'awaiting_difference_choice'): ?>
                    <p>Your replacement is cheaper by RM <?php echo number_format(abs((float) $item['price_difference']), 2); ?>. How would you like to receive the difference?</p>
                    <div class="d-flex gap-2">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                            <input type="hidden" name="token" value="<?php echo app_escape($rawToken); ?>">
                            <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                            <input type="hidden" name="choose_difference_credit" value="1">
                            <button type="submit" class="btn btn-outline-primary btn-sm">Store credit</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                            <input type="hidden" name="token" value="<?php echo app_escape($rawToken); ?>">
                            <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                            <input type="hidden" name="choose_difference_refund" value="1">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Refund</button>
                        </form>
                    </div>

                <?php elseif ($item['status'] === 'awaiting_payment'): ?>
                    <div class="alert alert-warning">Additional payment required: RM <?php echo number_format((float) $item['price_difference'], 2); ?></div>
                    <p class="text-muted small">Please make a bank transfer for the amount above, then upload your payment receipt.</p>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                        <input type="hidden" name="token" value="<?php echo app_escape($rawToken); ?>">
                        <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                        <input type="hidden" name="upload_receipt" value="1">
                        <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf" class="form-control form-control-sm mb-2" required>
                        <button type="submit" class="btn btn-primary btn-sm">Upload Payment Receipt</button>
                    </form>

                <?php elseif ($item['status'] === 'awaiting_payment_verification'): ?>
                    <div class="alert alert-info mb-0">
                        Your payment receipt has been uploaded and is awaiting verification.
                        <?php if ($item['latest_receipt'] !== null): ?>
                            <a href="/resolution_receipt.php?token=<?php echo app_escape($rawToken); ?>&receipt_id=<?php echo (int) $item['latest_receipt']['id']; ?>" target="_blank" rel="noopener">View my receipt</a>
                        <?php endif; ?>
                    </div>

                <?php elseif ($item['status'] === 'refund_pending'): ?>
                    <div class="alert alert-info mb-0">Your refund request is being processed<?php echo $item['refund'] !== null ? ' (status: ' . app_escape(ucfirst($item['refund']['status'])) . ')' : ''; ?>.</div>

                <?php elseif ($item['status'] === 'resolved'): ?>
                    <div class="alert alert-success mb-0">Resolved<?php echo $item['chosen_action'] !== null ? ' - ' . app_escape(ucfirst(str_replace('_', ' ', $item['chosen_action']))) : ''; ?>.</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($walletBalance !== null): ?>
            <div class="card p-4">
                <h6 class="mb-1">Your store credit wallet</h6>
                <div class="fs-5">RM <?php echo number_format($walletBalance, 2); ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
