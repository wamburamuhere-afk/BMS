<?php
/**
 * shop_catalog.php — the OLD public shop catalog link (shop_catalog?token=…), kept only so links
 * already posted keep working (retail_online_shop_plan.md). The catalog itself is now the online
 * shop at /s/<link> (public_shop.php).
 *
 * The old raw token is still matched only by its SHA-256 hash. When that shop's online shop is on,
 * the visitor is sent on to it; every other case (unknown token, shop off, Smart POS off) shows the
 * same generic page, so nothing here tells anyone which tokens ever existed.
 */
require_once __DIR__ . '/roots.php';
require_once __DIR__ . '/core/retail_online.php';

$token = trim((string)($_GET['token'] ?? ''));
$lang  = in_array($_GET['lang'] ?? '', SUPPORTED_LANGUAGES, true) ? $_GET['lang'] : 'en';
loadLanguage($lang);

$target = null;
try {
    if ($token !== '' && strlen($token) <= 128 && rsModuleOnForPublic($pdo)) {
        $st = $pdo->prepare("SELECT online_shop_token FROM warehouses
                              WHERE public_catalog_token_hash = ? AND status = 'active' AND online_shop_enabled = 1
                                AND online_shop_token IS NOT NULL AND COALESCE(pos_mode, 'retail') IN ('retail','hybrid') LIMIT 1");
        $st->execute([hash('sha256', $token)]);
        $new = (string)$st->fetchColumn();
        if ($new !== '' && roTokenLooksValid($new)) $target = getUrl('s/' . $new) . ($lang !== 'en' ? '?lang=' . $lang : '');
    }
} catch (Throwable $e) {
    error_log('shop_catalog redirect: ' . $e->getMessage());
}

roSendSecurityHeaders(false);
if ($target !== null) {
    header('Location: ' . $target, true, 301);
    exit;
}
http_response_code(404);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<link rel="icon" type="image/png" href="<?= getUrl('assets/images/favicon.png') ?>">
<title><?= $e(t('Shop not available')) ?></title>
<style>
body{font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif;background:#fff;color:#111827;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px;text-align:center}
.box{max-width:420px} h1{font-size:1.2rem;margin:0 0 8px} p{color:#6b7280;margin:0}
</style>
</head>
<body>
<div class="box">
  <h1><?= $e(t('This shop is not available.')) ?></h1>
  <p><?= $e(t('The link may have changed. Please ask the shop for its new link.')) ?></p>
</div>
</body>
</html>
