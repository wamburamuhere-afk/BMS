<?php
/**
 * core/restaurant_menu_photo.php — a menu item's photo (2026-11-03).
 *
 * The POS tiles and the public online menu already show products.image_url; the Menu page
 * now lets the owner add / change / remove it. Same rules as the Products page
 * (api/create_product.php): JPG, PNG, WebP or GIF, at most 2 MB, within the company's storage
 * limit. Stored under this company's own uploads folder (bmsUploadsDir / bmsUploadsRel), so two
 * companies never share a file. The file name and extension come from the server, never from
 * the uploaded name.
 */

if (!function_exists('restaurantMoveUploadedPhoto')) {
    /**
     * The only two calls that touch PHP's upload machinery, kept in one place: is this a real
     * upload, and move it. A CLI test (which cannot make a real upload) defines its own copy of
     * this function before loading the file; web requests always use this one.
     */
    function restaurantMoveUploadedPhoto(string $tmp, string $dest): bool
    {
        return is_uploaded_file($tmp) && move_uploaded_file($tmp, $dest);
    }
}

if (!function_exists('restaurantStoreMenuPhoto')) {
    /**
     * Save an uploaded photo ($_FILES entry) and return its web path (uploads/…/products/x.jpg).
     * Returns null when no file was sent. Throws Exception with a plain message when it is refused.
     */
    function restaurantStoreMenuPhoto(PDO $pdo, ?array $file): ?string
    {
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception(t('The photo is too large. The maximum is 2 MB.'));
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_file((string)$file['tmp_name'])) {
            throw new Exception(t('The photo could not be uploaded. Please try again.'));
        }
        $size = (int)$file['size'];
        if ($size > 2 * 1024 * 1024) {
            throw new Exception(sprintf(t('The photo is too large (%s MB). The maximum is 2 MB.'), round($size / 1048576, 1)));
        }
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = (string)mime_content_type($file['tmp_name']);
        if (!isset($types[$mime]) || @getimagesize($file['tmp_name']) === false) {
            throw new Exception(t('Choose a photo (JPG, PNG or WebP).'));
        }
        if (function_exists('assertUploadWithinQuota')) assertUploadWithinQuota($pdo, $size);

        $name = 'menu_' . bin2hex(random_bytes(8)) . '.' . $types[$mime];
        $dir  = bmsUploadsDir('products');
        if (!restaurantMoveUploadedPhoto((string)$file['tmp_name'], $dir . $name)) {
            throw new Exception(t('The photo could not be uploaded. Please try again.'));
        }
        return bmsUploadsRel('products') . $name;
    }
}

if (!function_exists('restaurantDeleteMenuPhoto')) {
    /** Remove an old photo file — only a file inside an uploads/…/products/ folder, never anything else. */
    function restaurantDeleteMenuPhoto(?string $rel): void
    {
        $rel = str_replace('\\', '/', trim((string)$rel));
        if ($rel === '' || strpos($rel, '..') !== false || !preg_match('#^uploads/(?:[A-Za-z0-9_-]+/)?products/[A-Za-z0-9._-]+$#', $rel)) return;
        $abs = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/' . $rel;
        if (is_file($abs)) @unlink($abs);
    }
}
