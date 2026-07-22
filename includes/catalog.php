<?php

/**
 * Brand/Category/Collection/Tag/Attribute/Template helpers for the catalog overhaul.
 * Brand, Category and Collection are presented in the product form as plain text
 * fields (get-or-create by name) rather than separate CRUD screens, since the product
 * form is the only place they're picked from today. Character/Color/Size are NOT
 * taxonomies - they live entirely in product_attributes/product_attribute_values.
 */

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
    $stmt = $pdo->prepare('SELECT id, value FROM product_attribute_values WHERE attribute_id = ? ORDER BY sort_order ASC, value ASC');
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

function catalog_get_or_create_attribute_value(PDO $pdo, int $attributeId, string $value): int
{
    $value = trim($value);
    $stmt = $pdo->prepare('SELECT id FROM product_attribute_values WHERE attribute_id = ? AND value = ?');
    $stmt->execute([$attributeId, $value]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $slug = catalog_unique_slug($pdo, 'product_attribute_values', catalog_slugify($value));
    $stmt = $pdo->prepare('INSERT INTO product_attribute_values (attribute_id, value, slug) VALUES (?, ?, ?)');
    $stmt->execute([$attributeId, $value, $slug]);

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
