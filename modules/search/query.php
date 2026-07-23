<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/search.php';
app_require_login();

/**
 * Global search endpoint (Phase 2 - backend only, no header UI wired up yet). Returns a bare
 * HTML fragment (a handful of result rows, or a single status message) meant to be dropped
 * into a future autocomplete dropdown via fetch() - not a full page (no header/footer include)
 * and not JSON (this app is server-rendered PHP throughout; nothing else here returns JSON
 * except the dedicated modules/*/ajax/*.php endpoints, which this deliberately is not).
 *
 * This file IS the permission boundary for global search: includes/search.php performs no
 * permission checks itself and trusts $allowedTypes completely, so every entity type below is
 * only added to that list after its own app_has_permission() check passes.
 */

$pdo = app_db();
$term = trim((string) ($_GET['term'] ?? ''));
$minLength = 2;

if (mb_strlen($term) < $minLength) {
    echo '<div class="search-result-message text-muted small px-3 py-2">Type at least ' . $minLength . ' characters to search.</div>';
    exit;
}

// Permission -> entity type mapping, exactly as specified. Nothing here is hardcoded into
// $allowedTypes directly - each group is only added once its own permission check passes.
$permissionToTypes = [
    'products.view' => ['products', 'variations'],
    'orders.view' => ['orders'],
    'supplier-orders.view' => ['supplier_orders'],
    'suppliers.view' => ['suppliers'],
    'shipments.view' => ['shipments'],
];

$allowedTypes = [];
foreach ($permissionToTypes as $permission => $types) {
    if (app_has_permission($permission)) {
        $allowedTypes = array_merge($allowedTypes, $types);
    }
}

if ($allowedTypes === []) {
    echo '<div class="search-result-message text-muted small px-3 py-2">No searchable modules available for your permissions.</div>';
    exit;
}

$results = global_search($pdo, $term, $allowedTypes);

if ($results === []) {
    echo '<div class="search-result-message text-muted small px-3 py-2">No results for &quot;' . app_escape($term) . '&quot;.</div>';
    exit;
}

$entityTypeLabels = [
    'products' => 'Product',
    'variations' => 'Variation',
    'orders' => 'Order',
    'supplier_orders' => 'Supplier Order',
    'suppliers' => 'Supplier',
    'shipments' => 'Shipment',
];

foreach ($results as $result) {
    $typeLabel = $entityTypeLabels[$result['entity_type']] ?? ucfirst(str_replace('_', ' ', (string) $result['entity_type']));
    ?>
    <a class="search-result-item d-block text-decoration-none text-body px-3 py-2 border-bottom" href="<?php echo app_escape($result['url']); ?>">
        <div class="d-flex justify-content-between align-items-center gap-2">
            <span class="fw-semibold"><?php echo app_escape($result['label']); ?></span>
            <span class="badge bg-light text-dark border"><?php echo app_escape($typeLabel); ?></span>
        </div>
        <?php if (!empty($result['subtitle'])): ?>
            <div class="text-muted small"><?php echo app_escape($result['subtitle']); ?></div>
        <?php endif; ?>
    </a>
    <?php
}
