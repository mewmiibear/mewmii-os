<?php

/**
 * Upload pipeline for product/variation images: validate, resize large images down,
 * convert to WebP, save under a generated filename. Only the final WebP ever gets
 * written to a permanent path - the PHP-managed upload tmp file is never persisted or
 * copied anywhere; it is read directly and discarded (PHP removes it automatically at
 * the end of the request).
 */

const IMAGE_UPLOAD_MAX_BYTES = 8 * 1024 * 1024; // 8 MB
const IMAGE_UPLOAD_MAX_DIMENSION = 2000; // px, longest side; smaller images are left alone
const IMAGE_UPLOAD_WEBP_QUALITY = 82;
const IMAGE_UPLOAD_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

function image_upload_base_dir(): string
{
    return dirname(__DIR__) . '/uploads';
}

/**
 * Validates a single $_FILES-style entry. Throws RuntimeException with a user-facing
 * message on any problem. Never trusts the client-reported MIME type or file extension -
 * getimagesize() parses the actual file header, so a renamed non-image file is rejected.
 * Returns the getimagesize() info array (used to read the real MIME type).
 */
function image_upload_validate(array $file): array
{
    if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('No file was uploaded.');
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (error code ' . $file['error'] . ').');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload.');
    }

    if ((int) $file['size'] > IMAGE_UPLOAD_MAX_BYTES) {
        throw new RuntimeException('Image is too large (max ' . (int) (IMAGE_UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB).');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('File is not a valid image.');
    }

    $mime = $info['mime'] ?? '';
    if (!in_array($mime, IMAGE_UPLOAD_ALLOWED_MIME, true)) {
        throw new RuntimeException('Unsupported image type. Use JPEG, PNG, GIF, or WebP.');
    }

    return $info;
}

/**
 * @return resource|\GdImage
 */
function image_upload_load_gd(string $tmpPath, string $mime)
{
    switch ($mime) {
        case 'image/jpeg':
            return imagecreatefromjpeg($tmpPath);
        case 'image/png':
            return imagecreatefrompng($tmpPath);
        case 'image/gif':
            return imagecreatefromgif($tmpPath);
        case 'image/webp':
            return imagecreatefromwebp($tmpPath);
        default:
            throw new RuntimeException('Unsupported image type.');
    }
}

function image_upload_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * Blocks script execution inside the uploads directory - defense in depth. We only ever
 * write our own generated .webp files here, never an uploaded file's original bytes or
 * extension, but this stops anything from being executed there regardless.
 */
function image_upload_ensure_htaccess(string $baseDir): void
{
    $htaccessPath = $baseDir . '/.htaccess';
    if (!is_file($htaccessPath)) {
        file_put_contents($htaccessPath, "php_flag engine off\nAddType text/plain .php .php3 .php4 .php5 .phtml .pht\n");
    }
}

/**
 * Validates, resizes (only if larger than IMAGE_UPLOAD_MAX_DIMENSION), compresses, and
 * converts an uploaded image to WebP, saving it under uploads/$subDir with a generated
 * collision-safe filename. Returns the path relative to the app root (e.g.
 * "uploads/products/ab12cd34ef56.webp") to store in the database.
 */
function image_upload_process(array $file, string $subDir): string
{
    if (!function_exists('imagewebp')) {
        throw new RuntimeException('The PHP GD extension (with WebP support) is required for image uploads.');
    }

    $info = image_upload_validate($file);
    $mime = (string) ($info['mime'] ?? '');

    return image_upload_encode_webp($file['tmp_name'], $mime, $subDir);
}

/**
 * Shared resize + WebP-encode + save pipeline behind image_upload_process() (a validated
 * $_FILES upload) and image_upload_process_from_path() below (a file already on local disk,
 * e.g. downloaded from a remote URL) - identical output either way, just a different
 * source-file validation step in front of it.
 */
function image_upload_encode_webp(string $sourcePath, string $mime, string $subDir): string
{
    $baseDir = image_upload_base_dir();
    image_upload_ensure_dir($baseDir);
    image_upload_ensure_htaccess($baseDir);

    $subDir = trim($subDir, '/');
    $targetDir = $baseDir . '/' . $subDir;
    image_upload_ensure_dir($targetDir);

    $image = image_upload_load_gd($sourcePath, $mime);
    if ($image === false) {
        throw new RuntimeException('Failed to read the image.');
    }

    // Preserve transparency (palette PNGs/GIFs) through the resize step.
    imagepalettetotruecolor($image);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    $width = imagesx($image);
    $height = imagesy($image);
    $longestSide = max($width, $height);

    if ($longestSide > IMAGE_UPLOAD_MAX_DIMENSION) {
        $scale = IMAGE_UPLOAD_MAX_DIMENSION / $longestSide;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        imagedestroy($image);
        $image = $resized;
    }

    $filename = bin2hex(random_bytes(12)) . '.webp';
    $fullPath = $targetDir . '/' . $filename;

    $saved = imagewebp($image, $fullPath, IMAGE_UPLOAD_WEBP_QUALITY);
    imagedestroy($image);

    if (!$saved) {
        throw new RuntimeException('Failed to save the processed image.');
    }

    return 'uploads/' . $subDir . '/' . $filename;
}

/**
 * Same validate-resize-WebP pipeline as image_upload_process(), for a file that's already
 * sitting on local disk (e.g. downloaded from a remote URL by cli/wc_product_import.php)
 * rather than a live $_FILES upload - image_upload_validate()'s is_uploaded_file() check
 * would always fail for a file PHP didn't itself receive as an HTTP upload, so this does its
 * own equivalent validation (exists, size cap, real image header/MIME via getimagesize())
 * instead. Does not delete $sourcePath afterward - the caller owns that file's lifecycle,
 * same as image_upload_duplicate() leaving its source alone.
 */
function image_upload_process_from_path(string $sourcePath, string $subDir): string
{
    if (!function_exists('imagewebp')) {
        throw new RuntimeException('The PHP GD extension (with WebP support) is required for image uploads.');
    }

    if (!is_file($sourcePath)) {
        throw new RuntimeException('Source image file not found.');
    }

    if (filesize($sourcePath) > IMAGE_UPLOAD_MAX_BYTES) {
        throw new RuntimeException('Image is too large (max ' . (int) (IMAGE_UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB).');
    }

    $info = @getimagesize($sourcePath);
    if ($info === false) {
        throw new RuntimeException('File is not a valid image.');
    }

    $mime = (string) ($info['mime'] ?? '');
    if (!in_array($mime, IMAGE_UPLOAD_ALLOWED_MIME, true)) {
        throw new RuntimeException('Unsupported image type. Use JPEG, PNG, GIF, or WebP.');
    }

    return image_upload_encode_webp($sourcePath, $mime, $subDir);
}

/**
 * Physically copies an already-processed image file to a fresh generated filename under
 * uploads/$subDir, returning the new relative path. Used whenever a second database row
 * needs to reference "the same picture" (bulk-assigning one image to several variations,
 * duplicating a product) - rows must never share one file path, since
 * image_upload_delete() unconditionally removes the file when any single row referencing
 * it is deleted, which would silently break every other row still pointing at it.
 */
function image_upload_duplicate(string $sourceRelativePath, string $subDir): string
{
    $sourceFullPath = dirname(__DIR__) . '/' . ltrim($sourceRelativePath, '/');
    if (!is_file($sourceFullPath)) {
        throw new RuntimeException('Source image file not found.');
    }

    $baseDir = image_upload_base_dir();
    $subDir = trim($subDir, '/');
    $targetDir = $baseDir . '/' . $subDir;
    image_upload_ensure_dir($targetDir);

    $extension = strtolower((string) pathinfo($sourceFullPath, PATHINFO_EXTENSION));
    $filename = bin2hex(random_bytes(12)) . ($extension !== '' ? ('.' . $extension) : '');
    $fullPath = $targetDir . '/' . $filename;

    if (!copy($sourceFullPath, $fullPath)) {
        throw new RuntimeException('Failed to duplicate the image file.');
    }

    return 'uploads/' . $subDir . '/' . $filename;
}

/**
 * Bugfix pass - turns a stored relative image path ("uploads/products/x.webp", the exact
 * format image_upload_process()/image_upload_encode_webp() above always return) into an
 * absolute URL WooCommerce can fetch over the internet. Previously nothing did this: the
 * raw relative path was sent to WooCommerce as-is, and WooCommerce's own "add a scheme if
 * one looks missing" normalization turned "uploads/products/x.webp" into
 * "http://uploads/products/x.webp" - treating "uploads" as if it were the hostname -
 * before rejecting it as an invalid URL.
 *
 * - Already-absolute input (http://, https://, or protocol-relative //host/...) is
 *   returned completely unchanged - never re-prefixed, so this is safe to call on a value
 *   that might already be a full URL from somewhere else.
 * - Otherwise it's joined onto app_base_url() (includes/bootstrap.php) - the local
 *   "display" convention of a bare leading "/" (see modules/products/_form.php's
 *   <img src="/...">) is untouched; this is additive, only for the WooCommerce payload.
 * - Returns '' if the path is blank OR if no base URL could be determined at all (see
 *   app_base_url()'s own docblock) - callers must treat an empty result as "omit this
 *   image", never fall back to sending the bare relative path again.
 */
function image_upload_public_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^(?:https?:)?//#i', $path) === 1) {
        return $path;
    }

    $base = app_base_url();
    if ($base === '') {
        return '';
    }

    return $base . '/' . ltrim($path, '/');
}

/**
 * Deletes a previously stored image file from disk, given its stored relative path.
 * Silently no-ops if the file is already gone - callers should still remove the
 * corresponding product_images row regardless of whether the file existed.
 */
function image_upload_delete(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }

    $fullPath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/**
 * Reshapes PHP's array-style $_FILES structure (e.g. $_FILES['gallery_images'] or
 * $_FILES['variation_image'] where name/type/tmp_name/error/size are each parallel
 * arrays, possibly keyed by variation id) into a flat map of normal single-file arrays,
 * skipping any slot where no file was actually chosen.
 */
function image_upload_normalize_multi(array $filesEntry): array
{
    if (!isset($filesEntry['name']) || !is_array($filesEntry['name'])) {
        return [];
    }

    $normalized = [];
    foreach ($filesEntry['name'] as $key => $name) {
        $error = (int) ($filesEntry['error'][$key] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $normalized[$key] = [
            'name' => $filesEntry['name'][$key],
            'type' => $filesEntry['type'][$key],
            'tmp_name' => $filesEntry['tmp_name'][$key],
            'error' => $filesEntry['error'][$key],
            'size' => $filesEntry['size'][$key],
        ];
    }

    return $normalized;
}
