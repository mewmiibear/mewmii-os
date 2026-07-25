<?php

/**
 * Saved Views (Sprint 10 - Operator Experience): named, shared filter presets for the
 * Products/Orders/Supplier Orders/Shipments/Customers list pages. Shared/global by design -
 * every operator sees and can use/delete every saved view, not just their own; created_by is
 * kept purely to show "Saved by X" and never restricts access.
 */

const SAVED_VIEWS_MODULES = [
    'products' => 'products.view',
    'orders' => 'orders.view',
    'supplier-orders' => 'supplier-orders.view',
    'shipments' => 'shipments.view',
    'customers' => 'customers.view',
];

function saved_view_list(PDO $pdo, string $module): array
{
    $stmt = $pdo->prepare('
        SELECT sv.id, sv.name, sv.query_string, sv.created_at, u.name AS created_by_name
        FROM saved_views sv
        LEFT JOIN users u ON u.id = sv.created_by
        WHERE sv.module = ?
        ORDER BY sv.name ASC
    ');
    $stmt->execute([$module]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saved_view_create(PDO $pdo, string $module, string $name, string $queryString, ?int $userId): int
{
    $stmt = $pdo->prepare('INSERT INTO saved_views (module, name, query_string, created_by) VALUES (?, ?, ?, ?)');
    $stmt->execute([$module, $name, $queryString, $userId]);

    return (int) $pdo->lastInsertId();
}

function saved_view_delete(PDO $pdo, int $id, string $module): void
{
    // Scoped by module as well as id - a delete request for one module's view can never
    // remove a row belonging to a different module, even if the id itself is guessed/reused.
    $stmt = $pdo->prepare('DELETE FROM saved_views WHERE id = ? AND module = ?');
    $stmt->execute([$id, $module]);
}
