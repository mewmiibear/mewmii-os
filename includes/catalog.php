<?php

/**
 * Brand/Category/Collection/Tag/Attribute/Template helpers for the catalog overhaul.
 * Brand, Category and Collection are presented in the product form as plain text
 * fields (get-or-create by name) rather than separate CRUD screens, since the product
 * form is the only place they're picked from today. Character/Color/Size are NOT
 * taxonomies - they live entirely in product_attributes/product_attribute_values.
 */

/**
 * "2026-09" -> "September 2026" for display - products.estimated_release_month is stored
 * as a plain YYYY-MM string (no day component to fabricate), so formatting always happens
 * here at render time rather than being baked into storage. Returns null for anything
 * empty or not in YYYY-MM shape, so callers can just skip rendering the line entirely.
 */
function catalog_format_release_month(?string $value): ?string
{
    if ($value === null || !preg_match('/^\d{4}-\d{2}$/', $value)) {
        return null;
    }

    $timestamp = strtotime($value . '-01');

    return $timestamp !== false ? date('F Y', $timestamp) : null;
}

/**
 * Colored-dot status indicator (products.status: draft/active/hidden/archived) - a plain
 * text/emoji string, not markup, since it's dropped straight into table cells and
 * <option> labels alike. Purely a display label - never affects the underlying status
 * value or query filters.
 */
function catalog_status_dot(string $status): string
{
    $map = [
        'active' => '🟢 Active',
        'draft' => '⚪ Draft',
        'hidden' => '🔴 Hidden',
        'archived' => '⚫ Archived',
    ];

    return $map[$status] ?? ('⚪ ' . ucfirst($status));
}

/**
 * Which lifecycle stage a product is currently in, driven by the same rules as
 * catalog_product_is_orderable()/catalog_product_effective_price() (includes/product_variations.php)
 * rather than re-deriving the Early Bird/Preorder/waiting-release state machine a second
 * time. Returns a stable key ('early_bird'|'preorder'|'ready_stock'|'waiting_release'|'closed')
 * for callers that need to branch on stage, separate from catalog_lifecycle_badge()'s display
 * string. Expects a row with at least status, product_type, preorder_closing_date,
 * preorder_reopened_at, availability_override (e.g. a `products` row or a
 * catalog_sellable_units() entry).
 */
function catalog_product_lifecycle_stage(array $product): string
{
    if (($product['status'] ?? '') !== 'active') {
        return 'closed';
    }

    // A manual "Out of Stock" override always shows as fully closed, regardless of what
    // stage the closing-date/reopen state machine below would otherwise compute - see
    // catalog_product_availability_status(). It never affects that state machine itself,
    // only what's displayed on top of it.
    if (($product['availability_override'] ?? 'auto') === 'out_of_stock') {
        return 'closed';
    }

    $productType = $product['product_type'] ?? 'ready_stock';
    if ($productType === 'ready_stock') {
        return 'ready_stock';
    }

    $closingDate = $product['preorder_closing_date'] ?? null;
    $reopened = !empty($product['preorder_reopened_at']);
    $hasClosed = !empty($closingDate) && strtotime((string) $closingDate) < strtotime('today');

    if ($hasClosed && !$reopened) {
        return 'waiting_release';
    }

    if ($reopened) {
        return 'preorder';
    }

    return $productType === 'early_bird' ? 'early_bird' : 'preorder';
}

/**
 * Colored lifecycle badge (HTML) for the stage computed by catalog_product_lifecycle_stage().
 * Inline styles rather than Bootstrap badge color classes since orange/purple aren't part
 * of the default Bootstrap 5.3 palette used elsewhere in this app.
 */
function catalog_lifecycle_badge(array $product): string
{
    $styles = [
        'early_bird' => ['emoji' => '🟧', 'label' => 'Early Bird', 'bg' => '#fd7e14'],
        'preorder' => ['emoji' => '🟪', 'label' => 'Preorder', 'bg' => '#6f42c1'],
        'ready_stock' => ['emoji' => '🟩', 'label' => 'Ready Stock', 'bg' => '#198754'],
        'waiting_release' => ['emoji' => '⚪', 'label' => 'Waiting Release', 'bg' => '#adb5bd'],
        'closed' => ['emoji' => '🔴', 'label' => 'Closed', 'bg' => '#dc3545'],
    ];

    $stage = catalog_product_lifecycle_stage($product);
    $style = $styles[$stage] ?? $styles['closed'];

    return '<span class="badge" style="background-color:' . $style['bg'] . ';color:#fff;">'
        . $style['emoji'] . ' ' . $style['label'] . '</span>';
}

/**
 * A variation's effective Minimum Order Quantity for the Supplier Order product picker
 * (see supplier_order_picker_products()): the variation's own moq if it has one set,
 * otherwise the parent product's moq, otherwise null (no MOQ at all - not expected in
 * practice today since products.moq is NOT NULL DEFAULT 1, but product_variations.moq is
 * nullable by design so most variations simply inherit the parent's value).
 */
function catalog_variation_effective_moq(?int $variationMoq, ?int $parentMoq): ?int
{
    if ($variationMoq !== null) {
        return $variationMoq;
    }

    return $parentMoq;
}

function catalog_slugify(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');

    return $slug !== '' ? $slug : 'item';
}

function catalog_unique_slug(PDO $pdo, string $table, string $baseSlug): string
{
    $slug = $baseSlug;
    $suffix = 2;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE slug = ?");

    while (true) {
        $stmt->execute([$slug]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }
}

// --- Brand / Category / Collection: get-or-create by name -----------------------------

function catalog_get_or_create_brand(PDO $pdo, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM brands WHERE name = ?');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $slug = catalog_unique_slug($pdo, 'brands', catalog_slugify($name));
    $pdo->prepare('INSERT INTO brands (name, slug) VALUES (?, ?)')->execute([$name, $slug]);

    return (int) $pdo->lastInsertId();
}

function catalog_get_or_create_category(PDO $pdo, string $name, ?int $parentId = null): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $slug = catalog_unique_slug($pdo, 'categories', catalog_slugify($name));
    $pdo->prepare('INSERT INTO categories (name, slug, parent_id) VALUES (?, ?, ?)')->execute([$name, $slug, $parentId]);

    return (int) $pdo->lastInsertId();
}

/**
 * Every category as a flat list ordered depth-first (parents immediately followed by
 * their children), each with a `depth` (0 = top-level) so a <select> can render indented
 * options reflecting the existing categories.parent_id hierarchy.
 */
function catalog_list_categories_tree(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name, parent_id FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

    $byParent = [];
    foreach ($rows as $row) {
        $parentKey = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        $byParent[$parentKey][] = $row;
    }

    $ordered = [];
    $walk = static function (int $parentKey, int $depth) use (&$walk, &$byParent, &$ordered): void {
        foreach ($byParent[$parentKey] ?? [] as $row) {
            $ordered[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'depth' => $depth,
            ];
            $walk((int) $row['id'], $depth + 1);
        }
    };
    $walk(0, 0);

    return $ordered;
}

function catalog_get_or_create_collection(PDO $pdo, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM collections WHERE name = ?');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $slug = catalog_unique_slug($pdo, 'collections', catalog_slugify($name));
    $pdo->prepare('INSERT INTO collections (name, slug) VALUES (?, ?)')->execute([$name, $slug]);

    return (int) $pdo->lastInsertId();
}

function catalog_list_brands(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM brands ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function catalog_list_collections(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM collections ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function catalog_brand_product_names(PDO $pdo, int $brandId): array
{
    $stmt = $pdo->prepare('SELECT name FROM products WHERE brand_id = ? ORDER BY name ASC');
    $stmt->execute([$brandId]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function catalog_sync_product_category(PDO $pdo, int $productId, ?int $categoryId): void
{
    $pdo->prepare('DELETE FROM product_category_relationships WHERE product_id = ?')->execute([$productId]);
    if ($categoryId !== null) {
        $pdo->prepare('INSERT INTO product_category_relationships (product_id, category_id) VALUES (?, ?)')
            ->execute([$productId, $categoryId]);
    }
}

function catalog_sync_product_collection(PDO $pdo, int $productId, ?int $collectionId): void
{
    $pdo->prepare('DELETE FROM product_collection_relationships WHERE product_id = ?')->execute([$productId]);
    if ($collectionId !== null) {
        $pdo->prepare('INSERT INTO product_collection_relationships (product_id, collection_id) VALUES (?, ?)')
            ->execute([$productId, $collectionId]);
    }
}

function catalog_get_product_category_id(PDO $pdo, int $productId): ?int
{
    $stmt = $pdo->prepare('SELECT category_id FROM product_category_relationships WHERE product_id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function catalog_get_product_collection_id(PDO $pdo, int $productId): ?int
{
    $stmt = $pdo->prepare('SELECT collection_id FROM product_collection_relationships WHERE product_id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function catalog_product_category_name(PDO $pdo, int $productId): ?string
{
    $stmt = $pdo->prepare('
        SELECT c.name FROM categories c
        INNER JOIN product_category_relationships r ON r.category_id = c.id
        WHERE r.product_id = ?
        LIMIT 1
    ');
    $stmt->execute([$productId]);
    $name = $stmt->fetchColumn();

    return $name !== false ? (string) $name : null;
}

function catalog_product_collection_name(PDO $pdo, int $productId): ?string
{
    $stmt = $pdo->prepare('
        SELECT c.name FROM collections c
        INNER JOIN product_collection_relationships r ON r.collection_id = c.id
        WHERE r.product_id = ?
        LIMIT 1
    ');
    $stmt->execute([$productId]);
    $name = $stmt->fetchColumn();

    return $name !== false ? (string) $name : null;
}

// --- Tags: admin-managed list, picked on the product form via checkboxes (no free text) -

function catalog_list_tags(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM product_tags ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function catalog_get_or_create_tag(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare('SELECT id FROM product_tags WHERE name = ?');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $pdo->prepare('INSERT INTO product_tags (name) VALUES (?)')->execute([$name]);

    return (int) $pdo->lastInsertId();
}

function catalog_tag_product_count(PDO $pdo, int $tagId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_tag_relationships WHERE tag_id = ?');
    $stmt->execute([$tagId]);

    return (int) $stmt->fetchColumn();
}

function catalog_get_product_tag_ids(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('SELECT tag_id FROM product_tag_relationships WHERE product_id = ?');
    $stmt->execute([$productId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Replaces a product's tag selections with exactly the given tag ids - picked from the
 * admin-managed tag list (modules/tags/index.php), never typed freehand on the product form.
 */
function catalog_sync_product_tag_ids(PDO $pdo, int $productId, array $tagIds): void
{
    $tagIds = array_values(array_unique(array_map('intval', $tagIds)));

    $pdo->prepare('DELETE FROM product_tag_relationships WHERE product_id = ?')->execute([$productId]);

    $insertStmt = $pdo->prepare('INSERT IGNORE INTO product_tag_relationships (product_id, tag_id) VALUES (?, ?)');
    foreach ($tagIds as $tagId) {
        if ($tagId > 0) {
            $insertStmt->execute([$productId, $tagId]);
        }
    }
}

// --- Attributes: Character, Color, Size, ... (global, reusable across products) -------

function catalog_list_attributes(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM product_attributes ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function catalog_list_attribute_values(PDO $pdo, int $attributeId): array
{
    $stmt = $pdo->prepare('SELECT id, value, code FROM product_attribute_values WHERE attribute_id = ? ORDER BY sort_order ASC, value ASC');
    $stmt->execute([$attributeId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function catalog_get_or_create_attribute(PDO $pdo, string $name): int
{
    $name = trim($name);
    $stmt = $pdo->prepare('SELECT id FROM product_attributes WHERE name = ?');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $slug = catalog_unique_slug($pdo, 'product_attributes', catalog_slugify($name));
    $pdo->prepare('INSERT INTO product_attributes (name, slug) VALUES (?, ?)')->execute([$name, $slug]);

    return (int) $pdo->lastInsertId();
}

/**
 * $code is the short (usually 2-3 char) inventory prefix used for variation SKU
 * generation instead of the full value name (e.g. "CN" for "Cinnamoroll") - see
 * catalog_attribute_value_sku_code(). Only applied when actually creating a new value;
 * an existing value's code is never silently overwritten by a repeat "get" call, since the
 * value (and its code) is shared/global across every product that uses this attribute.
 */
function catalog_get_or_create_attribute_value(PDO $pdo, int $attributeId, string $value, ?string $code = null): int
{
    $value = trim($value);
    $stmt = $pdo->prepare('SELECT id FROM product_attribute_values WHERE attribute_id = ? AND value = ?');
    $stmt->execute([$attributeId, $value]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $code = $code !== null ? trim($code) : '';
    $slug = catalog_unique_slug($pdo, 'product_attribute_values', catalog_slugify($value));
    $stmt = $pdo->prepare('INSERT INTO product_attribute_values (attribute_id, value, code, slug) VALUES (?, ?, ?, ?)');
    $stmt->execute([$attributeId, $value, $code !== '' ? $code : null, $slug]);

    return (int) $pdo->lastInsertId();
}

// --- Which attributes/values apply to a given product ---------------------------------

function catalog_get_product_attribute_assignments(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('
        SELECT paa.id AS assignment_id, paa.attribute_id, paa.is_variation_attribute, pa.name AS attribute_name
        FROM product_attribute_assignments paa
        INNER JOIN product_attributes pa ON pa.id = paa.attribute_id
        WHERE paa.product_id = ?
        ORDER BY paa.sort_order ASC, pa.name ASC
    ');
    $stmt->execute([$productId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function catalog_get_assignment_value_ids(PDO $pdo, int $assignmentId): array
{
    $stmt = $pdo->prepare('SELECT attribute_value_id FROM product_attribute_assignment_values WHERE assignment_id = ?');
    $stmt->execute([$assignmentId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Replace a product's attribute/value selections in one go.
 * $selections is an array of ['attribute_id' => int, 'is_variation_attribute' => bool, 'value_ids' => int[]].
 * Attributes not present in $selections are removed (with their value selections, via FK cascade).
 * Caller is responsible for the surrounding transaction.
 */
function catalog_set_product_attributes(PDO $pdo, int $productId, array $selections): void
{
    $existingStmt = $pdo->prepare('SELECT id, attribute_id FROM product_attribute_assignments WHERE product_id = ?');
    $existingStmt->execute([$productId]);

    $existingByAttribute = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingByAttribute[(int) $row['attribute_id']] = (int) $row['id'];
    }

    $keepAttributeIds = [];

    foreach ($selections as $selection) {
        $attributeId = (int) $selection['attribute_id'];
        $isVariationAttribute = !empty($selection['is_variation_attribute']) ? 1 : 0;
        $valueIds = array_unique(array_map('intval', $selection['value_ids'] ?? []));
        $keepAttributeIds[] = $attributeId;

        if (isset($existingByAttribute[$attributeId])) {
            $assignmentId = $existingByAttribute[$attributeId];
            $pdo->prepare('UPDATE product_attribute_assignments SET is_variation_attribute = ? WHERE id = ?')
                ->execute([$isVariationAttribute, $assignmentId]);
        } else {
            $pdo->prepare('INSERT INTO product_attribute_assignments (product_id, attribute_id, is_variation_attribute) VALUES (?, ?, ?)')
                ->execute([$productId, $attributeId, $isVariationAttribute]);
            $assignmentId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM product_attribute_assignment_values WHERE assignment_id = ?')->execute([$assignmentId]);
        $insertValueStmt = $pdo->prepare('INSERT INTO product_attribute_assignment_values (assignment_id, attribute_value_id) VALUES (?, ?)');
        foreach ($valueIds as $valueId) {
            $insertValueStmt->execute([$assignmentId, $valueId]);
        }
    }

    foreach ($existingByAttribute as $attributeId => $assignmentId) {
        if (!in_array($attributeId, $keepAttributeIds, true)) {
            $pdo->prepare('DELETE FROM product_attribute_assignments WHERE id = ?')->execute([$assignmentId]);
        }
    }
}

// --- Variation templates: reusable attribute/value presets -----------------------------

function catalog_list_templates(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, description FROM variation_templates ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Copies a template's attribute/value selections into the product's own assignment
 * tables. This is a one-time copy, never a live link - editing the template later has
 * no effect on products that already applied it. source_template_id is kept purely as
 * an audit trail of where the assignment came from.
 */
function catalog_apply_template(PDO $pdo, int $productId, int $templateId): void
{
    $attrStmt = $pdo->prepare('SELECT id, attribute_id, is_variation_attribute FROM variation_template_attributes WHERE template_id = ?');
    $attrStmt->execute([$templateId]);
    $templateAttributes = $attrStmt->fetchAll(PDO::FETCH_ASSOC);

    $valueStmt = $pdo->prepare('SELECT attribute_value_id FROM variation_template_attribute_values WHERE template_attribute_id = ?');
    $existingStmt = $pdo->prepare('SELECT id FROM product_attribute_assignments WHERE product_id = ? AND attribute_id = ?');
    $insertValueStmt = $pdo->prepare('INSERT INTO product_attribute_assignment_values (assignment_id, attribute_value_id) VALUES (?, ?)');

    foreach ($templateAttributes as $templateAttribute) {
        $attributeId = (int) $templateAttribute['attribute_id'];
        $isVariationAttribute = (int) $templateAttribute['is_variation_attribute'];

        $valueStmt->execute([(int) $templateAttribute['id']]);
        $valueIds = array_map('intval', $valueStmt->fetchAll(PDO::FETCH_COLUMN));

        $existingStmt->execute([$productId, $attributeId]);
        $assignmentId = $existingStmt->fetchColumn();

        if ($assignmentId !== false) {
            $assignmentId = (int) $assignmentId;
            $pdo->prepare('UPDATE product_attribute_assignments SET is_variation_attribute = ?, source_template_id = ? WHERE id = ?')
                ->execute([$isVariationAttribute, $templateId, $assignmentId]);
        } else {
            $pdo->prepare('INSERT INTO product_attribute_assignments (product_id, attribute_id, is_variation_attribute, source_template_id) VALUES (?, ?, ?, ?)')
                ->execute([$productId, $attributeId, $isVariationAttribute, $templateId]);
            $assignmentId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM product_attribute_assignment_values WHERE assignment_id = ?')->execute([$assignmentId]);
        foreach ($valueIds as $valueId) {
            $insertValueStmt->execute([$assignmentId, $valueId]);
        }
    }
}

// --- Development cleanup: relationship-based delete eligibility, no test-flag column ----

/**
 * Every table that can hold real business history against a product_id, and the message
 * shown when any of them do. Deliberately checked in application code even where a
 * foreign key would also block the delete (mewmii_order_items/supplier_order_items/
 * customer_storage have no ON DELETE action, so MySQL defaults to RESTRICT there) -
 * inventory_transactions.product_id is ON DELETE CASCADE, so the database alone would
 * silently destroy ledger history on a product delete instead of stopping it. This check
 * is what actually protects that table; the others just get a friendlier message than a
 * raw FK error.
 */
function catalog_product_history_tables(): array
{
    return [
        'mewmii_order_items' => 'customer orders',
        'supplier_order_items' => 'supplier orders',
        'inventory_transactions' => 'inventory transactions',
        'customer_storage' => 'customer storage records',
    ];
}

/**
 * Hard-deletes a product, but only if it has never appeared in any real business record -
 * checked against every table in catalog_product_history_tables(). Otherwise throws with
 * the admin-facing message so the caller can show it instead of a raw SQL/FK error. If
 * clear, deleting the product cascades (ON DELETE CASCADE) to its variations, images,
 * attribute assignments, tag/category/collection links, and mewmii_inventory row -
 * nothing product-specific is left behind.
 */
function product_delete_if_unused(PDO $pdo, int $productId): void
{
    foreach (array_keys(catalog_product_history_tables()) as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE product_id = ?");
        $stmt->execute([$productId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('This product has transaction history and cannot be deleted.');
        }
    }

    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
}

/**
 * Every product with zero rows in any history table - the "safe to delete" list for the
 * Data Cleanup tool (modules/settings/maintenance.php). A single NOT EXISTS query rather
 * than looping product_delete_if_unused() over every product, for the same reason
 * modules/products/index.php's quick=low_stock filter avoids re-querying per row where it
 * can - this just scales better once there are hundreds of products. The actual delete
 * action still re-validates via product_delete_if_unused() itself, so this list is never
 * treated as authoritative on its own (a record could theoretically gain history between
 * listing and deleting).
 */
function catalog_list_deletable_products(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT p.id, p.sku, p.name, p.status, p.created_at
        FROM products p
        WHERE NOT EXISTS (SELECT 1 FROM mewmii_order_items x WHERE x.product_id = p.id)
          AND NOT EXISTS (SELECT 1 FROM supplier_order_items x WHERE x.product_id = p.id)
          AND NOT EXISTS (SELECT 1 FROM inventory_transactions x WHERE x.product_id = p.id)
          AND NOT EXISTS (SELECT 1 FROM customer_storage x WHERE x.product_id = p.id)
        ORDER BY p.created_at DESC
        LIMIT 500
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * "Deactivate": the safe, always-available alternative to deleting a product that DOES
 * have history - reuses the existing status column (no new schema) rather than a
 * dedicated is_active flag, since 'archived' already means exactly this everywhere else
 * status is checked (catalog_product_lifecycle_stage(), filters, etc). Never touches any
 * history table.
 */
function product_deactivate(PDO $pdo, int $productId): void
{
    $pdo->prepare("UPDATE products SET status = 'archived' WHERE id = ?")->execute([$productId]);
}
