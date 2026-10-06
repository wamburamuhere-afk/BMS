<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
// Shared by api/mobile/products/create.php + update.php — the optional product
// fields the web product form (api/create_product.php / update_product.php) accepts.
if (!defined('BMS_MOBILE_PRODUCT_FIELDS')) {
    define('BMS_MOBILE_PRODUCT_FIELDS', 1);

    /**
     * Columns to write for the optional fields present in $body.
     * Throws InvalidArgumentException (→ 422) on bad input.
     * @return array<string,mixed>
     */
    function mobileProductOptionalFields(PDO $pdo, array $body): array
    {
        $out = [];
        $num = function (string $k, float $min = 0, ?float $max = null) use ($body, &$out): void {
            if (!array_key_exists($k, $body) || $body[$k] === '' || $body[$k] === null) return;
            if (!is_numeric($body[$k])) throw new InvalidArgumentException("$k must be a number");
            $v = (float)$body[$k];
            if ($v < $min || ($max !== null && $v > $max)) throw new InvalidArgumentException("$k is out of range");
            $out[$k] = $v;
        };
        $num('wholesale_price');
        $num('discount_rate', 0, 100);
        $num('min_selling_price');
        $num('min_stock_level');
        $num('max_stock_level');
        $num('weight');
        if (array_key_exists('expiry_days', $body) && $body['expiry_days'] !== '') $out['expiry_days'] = max(0, (int)$body['expiry_days']);

        foreach (['manufacturer', 'model'] as $k) {
            if (array_key_exists($k, $body)) $out[$k] = trim((string)$body[$k]) !== '' ? trim((string)$body[$k]) : null;
        }
        if (array_key_exists('is_taxable', $body)) $out['is_taxable'] = !empty($body['is_taxable']) && $body['is_taxable'] !== 'false' ? 1 : 0;

        if (array_key_exists('brand_id', $body)) {
            $bid = (int)$body['brand_id'];
            if ($bid > 0) {
                $st = $pdo->prepare("SELECT 1 FROM brands WHERE brand_id = ?");
                $st->execute([$bid]);
                if (!$st->fetchColumn()) throw new InvalidArgumentException('brand_id not found');
            }
            $out['brand_id'] = $bid > 0 ? $bid : null;
        }
        if (array_key_exists('category_id', $body)) {
            $cid = (int)$body['category_id'];
            if ($cid > 0) {
                $st = $pdo->prepare("SELECT 1 FROM categories WHERE category_id = ?");
                $st->execute([$cid]);
                if (!$st->fetchColumn()) throw new InvalidArgumentException('category_id not found');
            }
            $out['category_id'] = $cid > 0 ? $cid : null;
        }
        if (array_key_exists('tax_id', $body)) {
            $tid = (int)$body['tax_id'];
            $out['tax_id'] = null; $out['tax_rate'] = 0;
            if ($tid > 0) {
                $st = $pdo->prepare("SELECT rate_percentage FROM tax_rates WHERE rate_id = ?");
                $st->execute([$tid]);
                $rate = $st->fetchColumn();
                if ($rate === false) throw new InvalidArgumentException('tax_id not found');
                $out['tax_id'] = $tid; $out['tax_rate'] = (float)$rate;
            }
        }
        if (isset($out['min_stock_level'], $out['max_stock_level']) && $out['max_stock_level'] > 0 && $out['max_stock_level'] < $out['min_stock_level']) {
            throw new InvalidArgumentException('max_stock_level cannot be below min_stock_level');
        }
        return $out;
    }

    /**
     * Optional multipart product_image upload (security.md §19). Returns the
     * stored web-relative path, or null when no file was sent.
     */
    function mobileProductImageUpload(PDO $pdo): ?string
    {
        if (empty($_FILES['product_image']) || ($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        $f = $_FILES['product_image'];
        if ($f['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Image upload failed');
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) throw new InvalidArgumentException('Image must be JPG, PNG, GIF or WebP');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) throw new InvalidArgumentException('File content is not an allowed image type');
        if ($f['size'] > 2 * 1024 * 1024) throw new InvalidArgumentException('Image exceeds 2MB');
        if (function_exists('assertUploadWithinQuota')) assertUploadWithinQuota($pdo, (int)$f['size']);

        $dir = __DIR__ . '/../../../uploads/products/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . $name)) throw new RuntimeException('Could not store image');
        $rel = 'uploads/products/' . $name;
        if (function_exists('registerFileInLibrary')) {
            try { registerFileInLibrary($pdo, $rel, $f['name'], $f['size'], 'Product image', 'product,image', $_SESSION['user_id'] ?? null); } catch (Throwable $e) {}
        }
        return $rel;
    }
}

if (!function_exists('mobileShopQuantities')) {
    /**
     * Per-shop quantities from the request, as [warehouse_id => quantity].
     * Accepts a list of {warehouse_id, quantity}, a {warehouse_id: quantity}
     * map, or either as a JSON string (multipart requests carrying an image).
     * Returns null when the key is absent.
     *
     * @throws InvalidArgumentException
     */
    function mobileShopQuantities(array $body, string $key): ?array
    {
        if (!array_key_exists($key, $body)) return null;
        $raw = $body[$key];
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : json_decode($raw, true);
            if (!is_array($raw)) throw new InvalidArgumentException("$key must be a list of {warehouse_id, quantity}");
        }
        if (!is_array($raw)) throw new InvalidArgumentException("$key must be a list of {warehouse_id, quantity}");

        $out = [];
        foreach ($raw as $k => $v) {
            if (is_array($v)) {
                $wid = $v['warehouse_id'] ?? null;
                $qty = $v['quantity'] ?? null;
            } else {
                $wid = $k;
                $qty = $v;
            }
            if (!is_numeric($wid) || (int)$wid <= 0) throw new InvalidArgumentException("$key: every entry needs a valid warehouse_id");
            if (!is_numeric($qty) || (float)$qty < 0) throw new InvalidArgumentException("$key: quantity must be a non-negative number");
            $wid = (int)$wid;
            if (isset($out[$wid])) throw new InvalidArgumentException("$key: warehouse_id $wid is listed more than once");
            $out[$wid] = (float)$qty;
        }
        return $out;
    }
}
