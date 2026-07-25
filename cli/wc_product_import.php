#!/usr/bin/env php
<?php

/**
 * CLI-only WooCommerce -> Mewmii OS catalog import (initial product/variation sync).
 *
 * Usage:
 *   php cli/wc_product_import.php             Import for real.
 *   php cli/wc_product_import.php --dry-run    Hit the WooCommerce API and log what would
 *                                               happen, but make no database writes at all.
 *
 * Same CLI-only protection as cli/wc_order_sync.php: the PHP_SAPI check below stops this
 * from ever reaching bootstrap.php/the database under a web request, and cli/.htaccess
 * denies web access to this whole directory at the server level - either alone would be
 * enough, both together mean a misconfiguration in one doesn't leave the route open.
 *
 * Idempotent by design: products are matched by woocommerce_product_id first, falling back
 * to SKU, and updated in place rather than duplicated - safe to re-run. An update only ever
 * touches the columns this import owns (name/sku/pricing/status/catalog_type/
 * woocommerce_product_id); product_cost, MOQ, brand/category/supplier, description, and
 * every other staff-authored field are never overwritten by a re-run. Main/gallery/variation
 * images and Opening Stock are likewise only ever set once (see the "if missing" image
 * helpers and inventory_import_opening_stock()'s own history check below) - re-running this
 * script never re-downloads an image staff may have replaced, or double-counts stock.
 *
 * Inventory Ledger Compliance: stock is never written directly to mewmii_inventory. Every
 * unit's opening stock goes through inventory_import_opening_stock() (includes/inventory.php),
 * the same function modules/inventory/import_opening_stock.php's CSV importer uses - it logs
 * an inventory_transactions row (transaction_type = 'opening_stock') and updates
 * mewmii_inventory.available_quantity in the same call, and refuses outright if that unit
 * already has ANY transaction history, which is exactly what keeps re-runs from double-
 * counting stock that's already been through normal use.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/wc_client.php';

// --- CLI logging -------------------------------------------------------------------------

function wc_product_import_cli_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . ' UTC] [INFO] ' . $message . PHP_EOL);
}

function wc_product_import_cli_warn(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . ' UTC] [WARN] ' . $message . PHP_EOL);
}

function wc_product_import_cli_error(string $message): void
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] [ERROR] ' . $message . PHP_EOL);
}

// --- Small mapping/parsing helpers --------------------------------------------------------

function wc_import_map_product_status(string $wcStatus): string
{
    switch ($wcStatus) {
        case 'publish':
            return 'active';
        case 'private':
            return 'hidden';
        default: // draft, pending, trash, future, ...
            return 'draft';
    }
}

function wc_import_map_variation_status(string $wcStatus): string
{
    return $wcStatus === 'publish' ? 'active' : 'draft';
}

function wc_import_price_or_null($value): ?float
{
    $value = trim((string) ($value ?? ''));

    return $value === '' ? null : (float) $value;
}

function wc_import_price_or_zero($value): float
{
    $value = trim((string) ($value ?? ''));

    return $value === '' ? 0.0 : (float) $value;
}

/**
 * Pages through a WooCommerce list endpoint (per_page=100) until an empty page comes back,
 * merging every page into one array. wc_client_get() (includes/wc_client.php) already
 * handles auth, transient-failure retry, and error decoding - this only adds the paging
 * loop on top, shared by both the /products and /products/<id>/variations endpoints.
 */
function wc_import_fetch_all(string $endpoint, callable $onPage): array
{
    $all = [];
    $page = 1;

    while (true) {
        $items = wc_client_get($endpoint, ['per_page' => 100, 'page' => $page]);
        if (!is_array($items) || $items === []) {
            break;
        }

        $all = array_merge($all, $items);
        $onPage($page, count($items));
        $page++;
    }

    return $all;
}

// --- Product / variation upsert -----------------------------------------------------------

/**
 * @return array{id: int, created: bool}
 */
function wc_import_upsert_product(PDO $pdo, array $wcProduct, bool $dryRun): array
{
    $sku = trim((string) ($wcProduct['sku'] ?? ''));
    if ($sku === '') {
        throw new RuntimeException('No SKU (products.sku is required and unique in Mewmii OS).');
    }

    $wcId = (int) ($wcProduct['id'] ?? 0);

    $existing = null;
    if ($wcId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE woocommerce_product_id = ? LIMIT 1');
        $stmt->execute([$wcId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $existing = (int) $id;
        }
    }
    if ($existing === null) {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $existing = (int) $id;
        }
    }

    if ($dryRun) {
        return ['id' => $existing ?? 0, 'created' => $existing === null];
    }

    $name = trim((string) ($wcProduct['name'] ?? ''));
    $catalogType = ($wcProduct['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple';
    $status = wc_import_map_product_status((string) ($wcProduct['status'] ?? 'draft'));
    $sellingPrice = wc_import_price_or_zero($wcProduct['regular_price'] ?? ($wcProduct['price'] ?? 0));
    $salePrice = wc_import_price_or_null($wcProduct['sale_price'] ?? null);

    if ($existing !== null) {
        if ($catalogType === 'simple') {
            // Never downgrade an existing variable product back to simple while it still has
            // variations - mirrors the same protection modules/products/_form.php's Product
            // Type radio enforces in the UI (disabled once variations exist).
            $variationCountStmt = $pdo->prepare('SELECT COUNT(*) FROM product_variations WHERE product_id = ?');
            $variationCountStmt->execute([$existing]);
            if ((int) $variationCountStmt->fetchColumn() > 0) {
                $catalogType = 'variable';
            }
        }

        // Deliberately narrow: only the fields this import owns are ever touched on update.
        // product_cost, MOQ, brand/category/supplier, description, availability_override,
        // and every other staff-authored field are left completely alone, so re-running this
        // import can never clobber a manual edit made inside Mewmii OS itself.
        $pdo->prepare('
            UPDATE products
            SET name = ?, sku = ?, catalog_type = ?, selling_price = ?, sale_enabled = ?, sale_price = ?,
                status = ?, woocommerce_product_id = ?, published_to_woocommerce = 1, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $name, $sku, $catalogType, $sellingPrice, $salePrice !== null ? 1 : 0, $salePrice,
            $status, $wcId > 0 ? $wcId : null, $existing,
        ]);

        return ['id' => $existing, 'created' => false];
    }

    // Only used on first insert - a re-run never overwrites a description staff may have
    // since rewritten (see the docblock above). WooCommerce descriptions are HTML;
    // short_description here is a plain customer-facing summary elsewhere in this app (see
    // modules/products/_form.php), so tags are stripped to keep first-import content usable.
    $description = trim((string) ($wcProduct['description'] ?? ''));
    $shortDescription = trim(strip_tags((string) ($wcProduct['short_description'] ?? '')));

    $stmt = $pdo->prepare('
        INSERT INTO products
            (woocommerce_product_id, sku, name, short_description, description, catalog_type,
             selling_price, sale_enabled, sale_price, status, published_to_woocommerce)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ');
    $stmt->execute([
        $wcId > 0 ? $wcId : null,
        $sku,
        $name,
        $shortDescription !== '' ? mb_substr($shortDescription, 0, 500) : null,
        $description !== '' ? $description : null,
        $catalogType,
        $sellingPrice,
        $salePrice !== null ? 1 : 0,
        $salePrice,
        $status,
    ]);

    return ['id' => (int) $pdo->lastInsertId(), 'created' => true];
}

/**
 * @return array{id: int, created: bool}
 */
function wc_import_upsert_variation(PDO $pdo, int $localProductId, array $wcVariation): array
{
    $sku = trim((string) ($wcVariation['sku'] ?? ''));
    if ($sku === '') {
        throw new RuntimeException('No SKU.');
    }

    $wcVariationId = (int) ($wcVariation['id'] ?? 0);
    $status = wc_import_map_variation_status((string) ($wcVariation['status'] ?? 'draft'));
    // WooCommerce always carries its own price per variation - there is no "inherit parent
    // price" concept on that side (see wc_client_sync_variable_product_from_mewmii()'s
    // docblock for the same fact in the opposite sync direction) - so an imported variation's
    // price is always its own (price_mode='custom'), never price_mode='inherit'.
    $customPrice = wc_import_price_or_null($wcVariation['regular_price'] ?? null);
    $weightRaw = trim((string) ($wcVariation['weight'] ?? ''));
    $weight = ($weightRaw !== '' && is_numeric($weightRaw)) ? round((float) $weightRaw, 3) : null;

    $existing = null;
    if ($wcVariationId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM product_variations WHERE woocommerce_variation_id = ? LIMIT 1');
        $stmt->execute([$wcVariationId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $existing = (int) $id;
        }
    }
    if ($existing === null) {
        $stmt = $pdo->prepare('SELECT id FROM product_variations WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $existing = (int) $id;
        }
    }

    if ($existing !== null) {
        $pdo->prepare('
            UPDATE product_variations
            SET sku = ?, weight = ?, price_mode = ?, custom_price = ?, status = ?, woocommerce_variation_id = ?,
                is_system_generated = 0, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $sku, $weight, $customPrice !== null ? 'custom' : 'inherit', $customPrice, $status,
            $wcVariationId > 0 ? $wcVariationId : null, $existing,
        ]);

        return ['id' => $existing, 'created' => false];
    }

    $stmt = $pdo->prepare('
        INSERT INTO product_variations
            (product_id, sku, weight, price_mode, custom_price, status, is_system_generated, woocommerce_variation_id)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?)
    ');
    $stmt->execute([
        $localProductId, $sku, $weight, $customPrice !== null ? 'custom' : 'inherit', $customPrice, $status,
        $wcVariationId > 0 ? $wcVariationId : null,
    ]);

    $variationId = (int) $pdo->lastInsertId();

    // Every variation needs its own mewmii_inventory row before any ledger entry can
    // reference it (see inventory_get_or_create_row()) - variation_generate_combinations()
    // creates this at generation time; a WooCommerce-sourced variation has no such generation
    // step, so it's created explicitly here instead. INSERT IGNORE: harmless no-op if a
    // previous run already created it (e.g. a partially-failed retry).
    $pdo->prepare('INSERT IGNORE INTO mewmii_inventory (product_id, variation_id) VALUES (?, ?)')
        ->execute([$localProductId, $variationId]);

    return ['id' => $variationId, 'created' => true];
}

/**
 * Maps a WooCommerce variation's attributes (e.g. [{name: "Character", option: "Hello
 * Kitty"}]) onto this app's relational model: product_attributes/product_attribute_values
 * (global, get-or-create by name/value - same helpers the "+ Add Attribute"/"+ Add Value"
 * modals use), product_attribute_assignments/product_attribute_assignment_values (which
 * attributes/values are available on the PARENT product), and finally
 * product_variation_attribute_values (this exact variation's combination). Deliberately
 * additive only - never removes an existing assignment/link - so re-running this import can
 * never delete a relationship that might still be in use.
 */
function wc_import_sync_variation_attributes(PDO $pdo, int $localProductId, int $localVariationId, array $wcAttributes): void
{
    foreach ($wcAttributes as $wcAttribute) {
        if (!is_array($wcAttribute)) {
            continue;
        }

        $attributeName = trim((string) ($wcAttribute['name'] ?? ''));
        $optionValue = trim((string) ($wcAttribute['option'] ?? ''));
        if ($attributeName === '' || $optionValue === '') {
            continue;
        }

        $attributeId = catalog_get_or_create_attribute($pdo, $attributeName);
        $valueId = catalog_get_or_create_attribute_value($pdo, $attributeId, $optionValue);

        $assignmentStmt = $pdo->prepare('SELECT id FROM product_attribute_assignments WHERE product_id = ? AND attribute_id = ?');
        $assignmentStmt->execute([$localProductId, $attributeId]);
        $assignmentId = $assignmentStmt->fetchColumn();

        if ($assignmentId === false) {
            $pdo->prepare('INSERT INTO product_attribute_assignments (product_id, attribute_id, is_variation_attribute) VALUES (?, ?, 1)')
                ->execute([$localProductId, $attributeId]);
            $assignmentId = (int) $pdo->lastInsertId();
        } else {
            $assignmentId = (int) $assignmentId;
        }

        $pdo->prepare('INSERT IGNORE INTO product_attribute_assignment_values (assignment_id, attribute_value_id) VALUES (?, ?)')
            ->execute([$assignmentId, $valueId]);

        $pdo->prepare('
            INSERT INTO product_variation_attribute_values (variation_id, attribute_id, attribute_value_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE attribute_value_id = VALUES(attribute_value_id)
        ')->execute([$localVariationId, $attributeId, $valueId]);
    }
}

// --- Images ---------------------------------------------------------------------------------

function wc_import_download_to_tmp(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('download failed: ' . $error);
    }
    if ($statusCode < 200 || $statusCode >= 300 || $body === false || $body === '') {
        throw new RuntimeException('download failed: HTTP ' . $statusCode);
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'wcimg_');
    if ($tmpPath === false || file_put_contents($tmpPath, $body) === false) {
        throw new RuntimeException('could not write to a local temp file.');
    }

    return $tmpPath;
}

/**
 * Main/gallery/variation images are only ever set the FIRST time a unit has none - a
 * re-import never re-downloads or replaces an image staff may have since changed inside
 * Mewmii OS. product_images has no column identifying which WooCommerce image a row came
 * from, so there's no way to detect "this is the same picture, just re-synced" versus "staff
 * uploaded a different one" - treating any existing image as authoritative and untouchable is
 * the safe direction to err in, matching this whole script's "never clobber a manual edit"
 * rule for every other field.
 */
function wc_import_set_main_image_if_missing(PDO $pdo, int $productId, string $imageUrl): bool
{
    if (product_image_get_main($pdo, $productId) !== null) {
        return false;
    }

    $tmpPath = wc_import_download_to_tmp($imageUrl);
    try {
        $relativePath = image_upload_process_from_path($tmpPath, 'products');
    } finally {
        @unlink($tmpPath);
    }

    $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, NULL, 'main', ?, 0)")
        ->execute([$productId, $relativePath]);

    return true;
}

function wc_import_add_gallery_images_if_empty(PDO $pdo, int $productId, array $imageUrls): int
{
    if ($imageUrls === [] || product_image_list_gallery($pdo, $productId) !== []) {
        return 0;
    }

    $added = 0;
    $insertStmt = $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, NULL, 'gallery', ?, ?)");

    foreach ($imageUrls as $sortOrder => $imageUrl) {
        $tmpPath = wc_import_download_to_tmp($imageUrl);
        try {
            $relativePath = image_upload_process_from_path($tmpPath, 'products');
        } finally {
            @unlink($tmpPath);
        }

        $insertStmt->execute([$productId, $relativePath, $sortOrder]);
        $added++;
    }

    return $added;
}

function wc_import_set_variation_image_if_missing(PDO $pdo, int $productId, int $variationId, string $imageUrl): bool
{
    if (product_image_get_variation($pdo, $variationId) !== null) {
        return false;
    }

    $tmpPath = wc_import_download_to_tmp($imageUrl);
    try {
        $relativePath = image_upload_process_from_path($tmpPath, 'variations');
    } finally {
        @unlink($tmpPath);
    }

    $pdo->prepare("INSERT INTO product_images (product_id, variation_id, image_type, image_path, sort_order) VALUES (?, ?, 'variation', ?, 0)")
        ->execute([$productId, $variationId, $relativePath]);

    return true;
}

// --- Main run ------------------------------------------------------------------------------

$dryRun = in_array('--dry-run', $argv, true);

wc_product_import_cli_log('WooCommerce product import starting' . ($dryRun ? ' (DRY RUN - no database writes will be made)' : '') . '.');

if (!wc_client_is_configured()) {
    wc_product_import_cli_error('WooCommerce API is not configured (check config.php woocommerce.url/consumer_key/consumer_secret).');
    exit(1);
}

$pdo = app_db();

try {
    $wcProducts = wc_import_fetch_all('products', function (int $page, int $count): void {
        wc_product_import_cli_log('Fetched products page ' . $page . ' (' . $count . ' item(s)).');
    });
} catch (Throwable $e) {
    wc_product_import_cli_error('Failed to fetch products from WooCommerce: ' . $e->getMessage());
    exit(1);
}

$total = count($wcProducts);
wc_product_import_cli_log($total . ' product(s) found in WooCommerce.');

$stats = [
    'products_created' => 0, 'products_updated' => 0, 'products_skipped' => 0, 'products_failed' => 0,
    'variations_created' => 0, 'variations_updated' => 0,
    'opening_stock_set' => 0, 'opening_stock_skipped' => 0,
    'images_downloaded' => 0, 'images_failed' => 0,
];

foreach ($wcProducts as $index => $wcProduct) {
    $position = $index + 1;
    $name = (string) ($wcProduct['name'] ?? '(unnamed)');
    $sku = trim((string) ($wcProduct['sku'] ?? ''));

    wc_product_import_cli_log("Processing product {$position}/{$total}: {$name}" . ($sku !== '' ? " ({$sku})" : ' (no SKU)'));

    if ($sku === '') {
        wc_product_import_cli_warn('  Skipped - no SKU (WooCommerce id ' . (int) ($wcProduct['id'] ?? 0) . ').');
        $stats['products_skipped']++;
        continue;
    }

    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    try {
        $productResult = wc_import_upsert_product($pdo, $wcProduct, $dryRun);
        $localProductId = $productResult['id'];
        $stats[$productResult['created'] ? 'products_created' : 'products_updated']++;

        if ($dryRun) {
            wc_product_import_cli_log('  Would ' . ($productResult['created'] ? 'create' : 'update') . ' this product.');
        }

        if (!$dryRun && $localProductId > 0) {
            $images = $wcProduct['images'] ?? [];
            if (is_array($images) && $images !== []) {
                $mainImage = array_shift($images);
                $mainUrl = is_array($mainImage) ? trim((string) ($mainImage['src'] ?? '')) : '';

                if ($mainUrl !== '') {
                    try {
                        if (wc_import_set_main_image_if_missing($pdo, $localProductId, $mainUrl)) {
                            $stats['images_downloaded']++;
                        }
                    } catch (Throwable $e) {
                        $stats['images_failed']++;
                        wc_product_import_cli_warn("  Main image {$e->getMessage()}");
                    }
                }

                $galleryUrls = [];
                foreach ($images as $galleryImage) {
                    if (is_array($galleryImage) && !empty($galleryImage['src'])) {
                        $galleryUrls[] = (string) $galleryImage['src'];
                    }
                }

                if ($galleryUrls !== []) {
                    try {
                        $stats['images_downloaded'] += wc_import_add_gallery_images_if_empty($pdo, $localProductId, $galleryUrls);
                    } catch (Throwable $e) {
                        $stats['images_failed']++;
                        wc_product_import_cli_warn("  Gallery image {$e->getMessage()}");
                    }
                }
            }

            $isVariableProduct = ($wcProduct['type'] ?? 'simple') === 'variable';
            if (!$isVariableProduct) {
                $stockManaged = !empty($wcProduct['manage_stock']);
                $stockQuantity = (int) ($wcProduct['stock_quantity'] ?? 0);

                if ($stockManaged && $stockQuantity > 0) {
                    try {
                        inventory_import_opening_stock($pdo, $localProductId, null, $stockQuantity, 'Imported from WooCommerce initial sync');
                        $stats['opening_stock_set']++;
                    } catch (RuntimeException $e) {
                        // Already has ledger history (typically a re-run) - expected, not an error.
                        $stats['opening_stock_skipped']++;
                    }
                }
            }
        }

        if (($wcProduct['type'] ?? 'simple') === 'variable' && (int) ($wcProduct['id'] ?? 0) > 0) {
            $wcVariations = wc_import_fetch_all('products/' . (int) $wcProduct['id'] . '/variations', function (int $page, int $count) use ($sku): void {
                wc_product_import_cli_log("  Fetched variations page {$page} ({$count} item(s)) for {$sku}.");
            });

            wc_product_import_cli_log('  ' . count($wcVariations) . " variation(s) found for {$sku}.");

            foreach ($wcVariations as $wcVariation) {
                $variationSku = trim((string) ($wcVariation['sku'] ?? ''));
                if ($variationSku === '') {
                    wc_product_import_cli_warn('  Skipped variation - no SKU (WooCommerce variation id ' . (int) ($wcVariation['id'] ?? 0) . ').');
                    continue;
                }

                if ($dryRun) {
                    wc_product_import_cli_log("  Would upsert variation {$variationSku}.");
                    continue;
                }

                $variationResult = wc_import_upsert_variation($pdo, $localProductId, $wcVariation);
                $localVariationId = $variationResult['id'];
                $stats[$variationResult['created'] ? 'variations_created' : 'variations_updated']++;

                $wcAttributes = $wcVariation['attributes'] ?? [];
                if (is_array($wcAttributes) && $wcAttributes !== []) {
                    wc_import_sync_variation_attributes($pdo, $localProductId, $localVariationId, $wcAttributes);
                }

                $variationImage = $wcVariation['image'] ?? null;
                $variationImageUrl = is_array($variationImage) ? trim((string) ($variationImage['src'] ?? '')) : '';
                if ($variationImageUrl !== '') {
                    try {
                        if (wc_import_set_variation_image_if_missing($pdo, $localProductId, $localVariationId, $variationImageUrl)) {
                            $stats['images_downloaded']++;
                        }
                    } catch (Throwable $e) {
                        $stats['images_failed']++;
                        wc_product_import_cli_warn("  Variation image ({$variationSku}) {$e->getMessage()}");
                    }
                }

                $variationStockManaged = !empty($wcVariation['manage_stock']);
                $variationStockQuantity = (int) ($wcVariation['stock_quantity'] ?? 0);
                if ($variationStockManaged && $variationStockQuantity > 0) {
                    try {
                        inventory_import_opening_stock($pdo, $localProductId, $localVariationId, $variationStockQuantity, 'Imported from WooCommerce initial sync');
                        $stats['opening_stock_set']++;
                    } catch (RuntimeException $e) {
                        $stats['opening_stock_skipped']++;
                    }
                }
            }
        }

        if (!$dryRun) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $stats['products_failed']++;
        wc_product_import_cli_error("Failed to import product {$sku}: " . $e->getMessage());
    }
}

if ($stats['products_failed'] > 0) {
    // Per-product failures are tolerated by design, same convention as
    // cli/wc_order_sync.php's per-order failures: logged clearly, counted, and the run
    // continues - one bad product (e.g. a SKU collision) must never abort every other
    // product still waiting to be imported.
    wc_product_import_cli_log('WARNING: ' . $stats['products_failed'] . ' product(s) failed to import individually - see errors above.');
}

wc_product_import_cli_log(sprintf(
    'Import finished%s - products: %d created, %d updated, %d skipped, %d failed | variations: %d created, %d updated | opening stock: %d set, %d already tracked | images: %d downloaded, %d failed',
    $dryRun ? ' (DRY RUN)' : '',
    $stats['products_created'], $stats['products_updated'], $stats['products_skipped'], $stats['products_failed'],
    $stats['variations_created'], $stats['variations_updated'],
    $stats['opening_stock_set'], $stats['opening_stock_skipped'],
    $stats['images_downloaded'], $stats['images_failed']
));

exit(0);
