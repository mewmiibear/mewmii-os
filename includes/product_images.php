<?php

require_once __DIR__ . '/image_upload.php';

// --- Reading images back out -----------------------------------------------------------

function product_image_get_main(PDO $pdo, int $productId): ?array
{
    $stmt = $pdo->prepare("
        SELECT * FROM product_images
        WHERE product_id = ? AND variation_id IS NULL AND image_type = 'main'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function product_image_list_gallery(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("
        SELECT * FROM product_images
        WHERE product_id = ? AND variation_id IS NULL AND image_type = 'gallery'
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$productId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function product_image_get_variation(PDO $pdo, int $variationId): ?array
{
    $stmt = $pdo->prepare("
        SELECT * FROM product_images
        WHERE variation_id = ? AND image_type = 'variation'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$variationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * The image path actually shown for a variation: its own image if it has one, otherwise
 * the parent product's main image, otherwise null (no image at all).
 */
function variation_effective_image(PDO $pdo, int $productId, int $variationId): ?string
{
    $own = product_image_get_variation($pdo, $variationId);
    if ($own !== null) {
        return $own['image_path'];
    }

    $main = product_image_get_main($pdo, $productId);

    return $main !== null ? $main['image_path'] : null;
}

// --- Main image ---------------------------------------------------------------------------

/**
 * Replaces the product's main image with a newly uploaded file. The old main image's
 * file and row are only removed after the new one is safely stored.
 */
function product_image_set_main(PDO $pdo, int $productId, array $uploadedFile): void
{
    $imagePath = image_upload_process($uploadedFile, 'products');
    $old = product_image_get_main($pdo, $productId);

    $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, NULL, 'main', ?, 0)")
        ->execute([$productId, $imagePath]);

    if ($old !== null) {
        $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$old['id']]);
        image_upload_delete($old['image_path']);
    }
}

function product_image_remove_main(PDO $pdo, int $productId): void
{
    $old = product_image_get_main($pdo, $productId);
    if ($old === null) {
        return;
    }

    $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$old['id']]);
    image_upload_delete($old['image_path']);
}

// --- Gallery images ------------------------------------------------------------------------

/**
 * Appends one or more newly uploaded files to the product's gallery, after any images
 * already there. $uploadedFiles is a list of normalized single-file arrays (see
 * image_upload_normalize_multi()).
 */
function product_image_add_gallery(PDO $pdo, int $productId, array $uploadedFiles): void
{
    if ($uploadedFiles === []) {
        return;
    }

    $maxOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM product_images WHERE product_id = ? AND variation_id IS NULL AND image_type = 'gallery'");
    $maxOrderStmt->execute([$productId]);
    $nextOrder = (int) $maxOrderStmt->fetchColumn() + 1;

    $insertStmt = $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, NULL, 'gallery', ?, ?)");

    foreach ($uploadedFiles as $file) {
        $imagePath = image_upload_process($file, 'products');
        $insertStmt->execute([$productId, $imagePath, $nextOrder]);
        $nextOrder++;
    }
}

/**
 * Applies gallery deletions and sort-order edits in one pass.
 * $sortOrders is [imageId => newSortOrder]; $deleteIds is a list of image ids to remove
 * (their files are deleted from disk too). Both are scoped to this product's gallery
 * images only, so a forged image id belonging to another product/type is a no-op.
 */
function product_image_update_gallery(PDO $pdo, int $productId, array $sortOrders, array $deleteIds): void
{
    $deleteIds = array_values(array_unique(array_map('intval', $deleteIds)));

    if ($deleteIds !== []) {
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, image_path FROM product_images
            WHERE product_id = ? AND variation_id IS NULL AND image_type = 'gallery' AND id IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$productId], $deleteIds));
        $toDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $deleteStmt = $pdo->prepare('DELETE FROM product_images WHERE id = ?');
        foreach ($toDelete as $row) {
            $deleteStmt->execute([$row['id']]);
            image_upload_delete($row['image_path']);
        }
    }

    $updateStmt = $pdo->prepare("UPDATE product_images SET sort_order = ? WHERE id = ? AND product_id = ? AND variation_id IS NULL AND image_type = 'gallery'");
    foreach ($sortOrders as $imageId => $sortOrder) {
        $imageId = (int) $imageId;
        if (in_array($imageId, $deleteIds, true)) {
            continue;
        }
        $updateStmt->execute([(int) $sortOrder, $imageId, $productId]);
    }
}

// --- Variation image -----------------------------------------------------------------------

/**
 * Replaces one variation's own image with a newly uploaded file (deleting the old one,
 * if any, only after the new one is safely stored).
 */
function variation_image_set(PDO $pdo, int $productId, int $variationId, array $uploadedFile): void
{
    $imagePath = image_upload_process($uploadedFile, 'variations');
    $old = product_image_get_variation($pdo, $variationId);

    $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, ?, 'variation', ?, 0)")
        ->execute([$productId, $variationId, $imagePath]);

    if ($old !== null) {
        $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$old['id']]);
        image_upload_delete($old['image_path']);
    }
}

/**
 * Assigns one uploaded image to every selected variation at once (e.g. "give all the
 * Pink variations the pink product photo"). The file is processed exactly once; each
 * additional variation gets its own physically-copied file (image_upload_duplicate()),
 * never a shared path, for the same reason variation images are never shared by
 * reference elsewhere - one row's later removal must not delete another's picture out
 * from under it.
 */
function variation_bulk_set_image(PDO $pdo, int $productId, array $variationIds, array $uploadedFile): void
{
    $variationIds = array_values(array_unique(array_map('intval', $variationIds)));
    if ($variationIds === []) {
        return;
    }

    $firstPath = image_upload_process($uploadedFile, 'variations');

    foreach ($variationIds as $index => $variationId) {
        $imagePath = $index === 0 ? $firstPath : image_upload_duplicate($firstPath, 'variations');
        $old = product_image_get_variation($pdo, $variationId);

        $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, ?, 'variation', ?, 0)")
            ->execute([$productId, $variationId, $imagePath]);

        if ($old !== null) {
            $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$old['id']]);
            image_upload_delete($old['image_path']);
        }
    }
}

/**
 * Removes a variation's own image (it then falls back to the parent's main image).
 */
function variation_image_remove(PDO $pdo, int $variationId): void
{
    $old = product_image_get_variation($pdo, $variationId);
    if ($old === null) {
        return;
    }

    $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$old['id']]);
    image_upload_delete($old['image_path']);
}

// --- Variation gallery: close-up/angle/packaging shots, separate from the single "Main
// Image" above (image_type = 'variation') - same image_type = 'gallery' the product-level
// gallery already uses, just scoped to variation_id instead of NULL. ---------------------

function variation_image_list_gallery(PDO $pdo, int $variationId): array
{
    $stmt = $pdo->prepare("
        SELECT * FROM product_images
        WHERE variation_id = ? AND image_type = 'gallery'
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$variationId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Appends one or more newly uploaded files to one variation's gallery, after any images
 * already there. $uploadedFiles is a list of normalized single-file arrays (see
 * image_upload_normalize_multi()).
 */
function variation_image_add_gallery(PDO $pdo, int $productId, int $variationId, array $uploadedFiles): void
{
    if ($uploadedFiles === []) {
        return;
    }

    $maxOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM product_images WHERE variation_id = ? AND image_type = 'gallery'");
    $maxOrderStmt->execute([$variationId]);
    $nextOrder = (int) $maxOrderStmt->fetchColumn() + 1;

    $insertStmt = $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, ?, 'gallery', ?, ?)");

    foreach ($uploadedFiles as $file) {
        $imagePath = image_upload_process($file, 'variations');
        $insertStmt->execute([$productId, $variationId, $imagePath, $nextOrder]);
        $nextOrder++;
    }
}

/**
 * Applies gallery deletions and sort-order edits in one pass, scoped to one variation's
 * gallery images only - mirrors product_image_update_gallery() exactly, just keyed by
 * variation_id instead of product_id (variation_id IS NULL).
 */
function variation_image_update_gallery(PDO $pdo, int $variationId, array $sortOrders, array $deleteIds): void
{
    $deleteIds = array_values(array_unique(array_map('intval', $deleteIds)));

    if ($deleteIds !== []) {
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, image_path FROM product_images
            WHERE variation_id = ? AND image_type = 'gallery' AND id IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$variationId], $deleteIds));
        $toDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $deleteStmt = $pdo->prepare('DELETE FROM product_images WHERE id = ?');
        foreach ($toDelete as $row) {
            $deleteStmt->execute([$row['id']]);
            image_upload_delete($row['image_path']);
        }
    }

    $updateStmt = $pdo->prepare("UPDATE product_images SET sort_order = ? WHERE id = ? AND variation_id = ? AND image_type = 'gallery'");
    foreach ($sortOrders as $imageId => $sortOrder) {
        $imageId = (int) $imageId;
        if (in_array($imageId, $deleteIds, true)) {
            continue;
        }
        $updateStmt->execute([(int) $sortOrder, $imageId, $variationId]);
    }
}
