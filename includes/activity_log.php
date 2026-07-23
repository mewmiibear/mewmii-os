<?php

/**
 * Reusable, module-agnostic activity log (see the `activity_logs` table in schema.sql) -
 * a foundation for future auditing, separate from the pre-existing (still-disabled)
 * audit_logs table/app_log_action() in includes/bootstrap.php, which this does not touch or
 * replace. Append-only: nothing in this app ever updates or deletes a row here.
 *
 * Currently called from: supplier order edits (supplier_order_apply_edit()), supplier order
 * payments (modules/supplier-orders/view.php), inventory adjustments
 * (modules/inventory/index.php), and product deletes (product_delete_if_unused()) - per the
 * Operations Stabilisation Improvements spec. Never throws - a logging failure must never
 * block the real action it's describing.
 */
function activity_log(PDO $pdo, string $module, string $action, ?int $recordId, string $description): void
{
    try {
        $pdo->prepare('
            INSERT INTO activity_logs (user_id, module, action, record_id, description)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$_SESSION['user_id'] ?? null, $module, $action, $recordId, $description]);
    } catch (Exception $exception) {
        // Best-effort only - never let activity logging break the caller's real operation.
    }
}
