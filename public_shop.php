<?php
/**
 * public_shop.php — the PUBLIC retail online shop of one Smart POS shop (retail_online_shop_plan.md).
 * No login. Reached only as:
 *   GET  /s/<link>                   the shop (products, Available / Sold out — never a number)
 *   POST /s/<link>/order             send an order request (JSON in, JSON out)
 *   GET  /s/<link>/o/<ref>           that order's page
 *   GET  /s/<link>/o/<ref>/status    its status as JSON (the page polls it)
 *   POST /s/<link>/o/<ref>/cancel    the buyer cancels it while it is still waiting (JSON)
 *
 * Security (same rules as the restaurant online menu):
 *  - never reads $_SESSION for identity, never calls includeHeader();
 *  - every failure to open a link renders the SAME generic page / JSON;
 *  - prices and stock are checked on the server from ids (core/retail_online.php);
 *  - an order is only a request: staff accept it and take the payment at the till;
 *  - strict CSP with a per-request nonce, no-referrer, noindex, no-store; JSON-only POSTs.
 */
require_once __DIR__ . '/roots.php';
require_once __DIR__ . '/core/retail_online.php';

global $pdo;

$route  = $GLOBALS['__rs_route'] ?? ['token' => '', 'action' => 'shop', 'ref' => ''];
$action = $route['action'];

$lang = in_array($_GET['lang'] ?? '', SUPPORTED_LANGUAGES, true) ? $_GET['lang'] : 'en';
loadLanguage($lang);

$ctx = null;
try {
    $ctx = rsResolveToken($pdo, (string)$route['token']);
} catch (Throwable $e) {
    error_log('public_shop resolve: ' . $e->getMessage());
    $ctx = null;
}
$shop = $ctx['warehouse'] ?? null;

$json = function (int $code, array $body): void {
    roSendSecurityHeaders(true);
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};
/** JSON body of a POST, or a refusal (a cross-site <form> cannot send application/json without a preflight). */
$readJson = function () use ($json): array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $json(405, ['success' => false, 'message' => t('Method not allowed')]);
    $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ctype, 'application/json') !== 0) $json(415, ['success' => false, 'message' => t('Your order could not be read. Please try again.')]);
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
    if (!is_array($in)) $json(400, ['success' => false, 'message' => t('Your order could not be read. Please try again.')]);
    return $in;
};

// ─── POST /s/<link>/order ────────────────────────────────────────────────────
if ($action === 'order') {
    if (!$ctx) { $readJson(); $json(404, ['success' => false, 'message' => t('This shop is not available.')]); }
    $in = $readJson();
    if (!$shop['ordering']) $json(403, ['success' => false, 'message' => t('Ordering online is switched off. Please call the shop.')]);
    if (trim((string)($in['website'] ?? '')) !== '') $json(400, ['success' => false, 'message' => t('Your order could not be read. Please try again.')]);
    $why = roCheckFormToken($pdo, $ctx['token'], $in['form_token'] ?? null);
    if ($why === 'old' || $why === 'bad') $json(409, ['success' => false, 'message' => t('This page is out of date. Please refresh and try again.'), 'refresh' => true]);
    if ($why === 'fast') $json(429, ['success' => false, 'message' => t('Please wait a moment and try again.')]);

    $name  = roCleanText($in['name'] ?? '', RO_MAX_NAME);
    $phone = substr(preg_replace('/[^0-9+]/', '', (string)($in['phone'] ?? '')) ?? '', 0, 20);
    $note  = roCleanText($in['note'] ?? '', RO_MAX_NOTE);
    $fulfil = ($in['fulfil'] ?? '') === 'delivery' ? 'delivery' : 'pickup';
    $address = roCleanText($in['address'] ?? '', RS_MAX_ADDRESS);
    $priceType = ($in['price_type'] ?? '') === 'wholesale' ? 'wholesale' : 'retail';
    if (mb_strlen($name) < 2) $json(422, ['success' => false, 'message' => t('Please enter your name.')]);
    $digits = strlen(preg_replace('/\D/', '', $phone) ?? '');
    if ($digits < 7 || $digits > 15) $json(422, ['success' => false, 'message' => t('Please enter a valid phone number.')]);
    if ($fulfil === 'delivery' && !$shop['delivery']) $json(422, ['success' => false, 'message' => t('This shop does not deliver. Please choose pickup.')]);
    if ($fulfil === 'pickup' && !$shop['pickup'])     $json(422, ['success' => false, 'message' => t('This shop only delivers. Please choose delivery.')]);
    if ($fulfil === 'delivery' && mb_strlen($address) < 3) $json(422, ['success' => false, 'message' => t('Please enter the delivery address or area.')]);

    try {
        $client = roClientHash($pdo);
        rsCheckLimits($pdo, $shop['warehouse_id'], $client);
        $built  = rsBuildOrder($pdo, $shop, $in['lines'] ?? null, $priceType);
        $placed = rsPlaceOrder($pdo, $ctx, $built, $name, $phone, $note, $fulfil, $address, $shop['wholesale'] ? $priceType : 'retail', $client);
    } catch (RoUserError $e) {
        $json(422, ['success' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('public_shop order: ' . $e->getMessage());
        $json(500, ['success' => false, 'message' => t('Something went wrong. Please try again or call the shop.')]);
    }
    $json(200, [
        'success'    => true,
        'order_code' => $placed['order_code'],
        'status_url' => rsStatusUrl($ctx['token'], $placed['public_ref']) . ($lang !== 'en' ? '?lang=' . $lang : ''),
    ]);
}

// ─── GET /s/<link>/o/<ref>/status ────────────────────────────────────────────
if ($action === 'status') {
    $order = $ctx ? rsPublicOrder($pdo, $shop['warehouse_id'], (string)$route['ref']) : null;
    if (!$order) $json(404, ['success' => false, 'message' => t('Order not found.')]);
    $json(200, ['success' => true, 'status' => $order['status'], 'reason' => $order['reason'], 'cancelled_by' => $order['cancelled_by']]);
}

// ─── POST /s/<link>/o/<ref>/cancel ───────────────────────────────────────────
if ($action === 'cancel') {
    $readJson();
    if (!$ctx || !rsPublicOrder($pdo, $shop['warehouse_id'], (string)$route['ref'])) $json(404, ['success' => false, 'message' => t('Order not found.')]);
    if (!rsCustomerCancel($pdo, $shop['warehouse_id'], (string)$route['ref'])) {
        $json(409, ['success' => false, 'message' => t('The shop has already handled this order. Please call the shop to change it.')]);
    }
    $json(200, ['success' => true, 'message' => t('Your order was cancelled.')]);
}

// ─── HTML pages ──────────────────────────────────────────────────────────────
$nonce = base64_encode(random_bytes(18));
roSendSecurityHeaders(false);
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce' https://cdn.jsdelivr.net; "
     . "font-src https://cdn.jsdelivr.net; img-src 'self' data: https:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$companyName = (string)get_setting('company_name', 'Shop');
$logoPath    = (string)get_setting('company_logo', '');
$logoUrl     = $logoPath !== '' ? getUrl($logoPath) : '';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$selfPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$shopUrl  = $ctx ? getUrl('s/' . $ctx['token']) . ($lang !== 'en' ? '?lang=' . $lang : '') : '';

$order = null;
if ($ctx && $action === 'track') $order = rsPublicOrder($pdo, $shop['warehouse_id'], (string)$route['ref']);
$notFound = !$ctx || ($action === 'track' && !$order);
if ($notFound) http_response_code(404);

$catalog = ['items' => [], 'categories' => [], 'has_wholesale' => false];
if ($ctx && $action === 'shop') {
    try { $catalog = rsPublicCatalog($pdo, $shop); }
    catch (Throwable $ex) { error_log('public_shop catalog: ' . $ex->getMessage()); }
}
$canOrder = $ctx && $action === 'shop' && $shop['ordering'];
$payAccts = [];
if ($ctx && $action === 'track' && $order && $shop['show_payment'] && in_array($order['status'], ['pending', 'accepted', 'ready'], true)) {
    require_once __DIR__ . '/core/payment_accounts.php';
    try { $payAccts = paymentAccountsForBuyer($pdo); } catch (Throwable $ex) { $payAccts = []; }
}
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
$title = $ctx ? $shop['warehouse_name'] : t('Shop');
$jsonOpts = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#ffffff">
<link rel="icon" type="image/png" href="<?= getUrl('assets/images/favicon.png') ?>">
<link rel="apple-touch-icon" href="<?= getUrl('assets/images/favicon.png') ?>">
<title><?= $e($title) ?> · <?= $e($companyName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" crossorigin="anonymous">
<style nonce="<?= $e($nonce) ?>">
:root{--ink:#111827;--muted:#6b7280;--line:#e5e7eb;--soft:#f3f4f6;--bg:#ffffff;--brand:#111827;--accent:#16a34a;--warn:#b45309;--danger:#dc2626;--r:16px}
*{-webkit-tap-highlight-color:transparent}
html{font-size:16px}
body{background:var(--bg);color:var(--ink);font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif;font-size:1rem;-webkit-font-smoothing:antialiased}
.wrap{max-width:1120px;margin:0 auto;padding:0 16px}
.topbar{position:sticky;top:0;z-index:30;background:rgba(255,255,255,.92);backdrop-filter:saturate(180%) blur(12px);border-bottom:1px solid var(--line)}
.brand{display:flex;align-items:center;gap:12px;padding:12px 0}
.brand .logo{width:44px;height:44px;border-radius:12px;object-fit:cover;background:var(--soft);flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--muted)}
.brand h1{font-size:1.05rem;font-weight:700;margin:0;line-height:1.2;letter-spacing:-.01em}
.brand small{color:var(--muted);font-size:.8rem}
.min-w-0{min-width:0}
.tb-actions{margin-left:auto;display:flex;align-items:center;gap:8px;flex:0 0 auto}
.tb-btn{position:relative;width:40px;height:40px;border-radius:999px;border:1px solid var(--line);background:#fff;color:var(--ink);display:inline-flex;align-items:center;justify-content:center;font-size:1.1rem;padding:0;text-decoration:none}
.tb-btn:hover{border-color:#d1d5db;color:var(--ink)}
.tb-btn.bump{animation:tbBump .35s ease}
@keyframes tbBump{0%{transform:scale(1)}40%{transform:scale(1.18)}100%{transform:scale(1)}}
.tb-badge{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:var(--brand);color:#fff;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #fff}
#myOrdersCnt{background:var(--accent)}
.lang-dd{position:relative}
.lang-btn-x{background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px 12px;font-size:.8rem;font-weight:600;color:#555;cursor:pointer;display:flex;align-items:center;gap:5px;box-shadow:0 1px 3px rgba(0,0,0,.07)}
.lang-menu-x{position:absolute;right:0;top:40px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;min-width:150px;box-shadow:0 6px 20px rgba(0,0,0,.12);overflow:hidden;z-index:9999;display:none}
.lang-menu-x a{display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:.85rem;color:#333;text-decoration:none;white-space:nowrap}
.lang-menu-x a:hover{background:#f4f8ff}
.lang-menu-x a.active{font-weight:700}
.hero{padding:20px 0 8px}
.hero h2{font-size:1.6rem;font-weight:800;letter-spacing:-.02em;margin:0 0 6px}
.pill{display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;border-radius:999px;padding:6px 12px;background:var(--soft);color:var(--ink);margin:0 6px 6px 0}
.pill.ok{background:#ecfdf5;color:#047857}
.tools{position:sticky;top:69px;z-index:20;background:var(--bg);padding:12px 0 10px}
.search{position:relative}
.search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9ca3af}
.search input{width:100%;border:1px solid var(--line);background:var(--soft);border-radius:999px;padding:11px 14px 11px 40px;font-size:.95rem;outline:none}
.search input:focus{background:#fff;border-color:#9ca3af;box-shadow:0 0 0 4px rgba(17,24,39,.06)}
.chips{display:flex;gap:8px;overflow-x:auto;padding:12px 0 2px;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{flex:0 0 auto;border:1px solid var(--line);background:#fff;color:var(--ink);border-radius:999px;padding:8px 14px;font-size:.85rem;font-weight:500;cursor:pointer;white-space:nowrap}
.chip.active{background:var(--brand);border-color:var(--brand);color:#fff}
.ptoggle{display:inline-flex;background:var(--soft);border-radius:999px;padding:4px;margin-top:10px}
.ptoggle button{border:0;background:transparent;border-radius:999px;padding:7px 16px;font-size:.85rem;font-weight:700;color:var(--muted)}
.ptoggle button.on{background:var(--brand);color:#fff}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 12px;padding:8px 0 120px}
@media(min-width:640px){.grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:24px 16px}}
@media(min-width:960px){.grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
.card-x{position:relative;cursor:pointer;min-width:0}
.card-x .ph{position:relative;aspect-ratio:1/1;border-radius:var(--r);overflow:hidden;background:var(--soft)}
.card-x .ph img{width:100%;height:100%;object-fit:cover;transition:transform .35s}
.card-x:hover .ph img{transform:scale(1.04)}
.ph .ini{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:2.4rem;font-weight:800;color:rgba(17,24,39,.35)}
.badge-x{position:absolute;top:10px;left:10px;background:#fff;border-radius:999px;font-size:.72rem;font-weight:600;padding:4px 9px;box-shadow:0 1px 2px rgba(0,0,0,.08)}
.badge-x.av{color:#047857}
.add{position:absolute;right:10px;bottom:10px;width:38px;height:38px;border-radius:999px;border:0;background:#fff;color:var(--ink);box-shadow:0 4px 12px rgba(0,0,0,.15);display:flex;align-items:center;justify-content:center;font-size:1.25rem}
.add .n{font-size:.8rem;font-weight:700}
.card-x.has .add{background:var(--brand);color:#fff}
.card-x .nm{font-size:.95rem;font-weight:600;margin:10px 0 2px;line-height:1.3;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.card-x .ct{font-size:.8rem;color:var(--muted)}
.card-x .pr{font-size:.95rem;font-weight:700;margin-top:2px}
.card-x .pr small{font-weight:500;color:var(--muted)}
.card-x.out{cursor:default}
.card-x.out .ph::after{content:"";position:absolute;inset:0;background:rgba(255,255,255,.55)}
.card-x.out .badge-x{z-index:2;background:var(--ink);color:#fff}
.empty{text-align:center;color:var(--muted);padding:60px 16px}
.empty i{font-size:2.2rem;display:block;margin-bottom:8px}
.bar{position:fixed;left:0;right:0;bottom:0;z-index:40;padding:12px 16px calc(12px + env(safe-area-inset-bottom));pointer-events:none}
.bar button{pointer-events:auto;max-width:560px;margin:0 auto;width:100%;display:flex;align-items:center;gap:12px;border:0;border-radius:999px;background:var(--brand);color:#fff;padding:14px 20px;font-weight:600;font-size:1rem;box-shadow:0 10px 30px rgba(17,24,39,.3)}
.bar .cnt{background:#fff;color:var(--ink);border-radius:999px;min-width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;padding:0 8px}
.bar .tot{margin-left:auto}
.scrim{position:fixed;inset:0;background:rgba(17,24,39,.45);z-index:50;opacity:0;pointer-events:none;transition:opacity .2s}
.scrim.on{opacity:1;pointer-events:auto}
.sheet{position:fixed;left:0;right:0;bottom:0;z-index:60;background:#fff;border-radius:24px 24px 0 0;max-height:92vh;display:flex;flex-direction:column;transform:translateY(calc(100% + 48px));visibility:hidden;transition:transform .25s ease,visibility 0s linear .25s;max-width:640px;margin:0 auto}
.sheet.on{transform:none;visibility:visible;transition:transform .25s ease,visibility 0s}
@media(min-width:700px){.sheet{bottom:24px;border-radius:24px;max-height:86vh}}
.sheet .hd{display:flex;align-items:center;gap:8px;padding:16px 20px 8px}
.sheet .hd h3{font-size:1.1rem;font-weight:700;margin:0;flex:1;min-width:0}
.sheet .x{border:0;background:var(--soft);width:36px;height:36px;border-radius:999px;font-size:1.1rem}
.sheet .bd{overflow-y:auto;padding:4px 20px 16px}
.sheet .ft{padding:12px 20px calc(16px + env(safe-area-inset-bottom));border-top:1px solid var(--line)}
.sheet .big{width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:var(--r);background:var(--soft);margin-bottom:12px}
.fld{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:.95rem;outline:none;background:#fff}
.fld:focus{border-color:#9ca3af;box-shadow:0 0 0 4px rgba(17,24,39,.06)}
label.lb{font-size:.85rem;font-weight:600;margin:14px 0 6px;display:block}
.qty{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:999px}
.qty button{border:0;background:none;width:40px;height:40px;font-size:1.1rem}
.qty input{width:64px;text-align:center;font-weight:700;border:0;outline:none;background:transparent;font-size:1rem}
.qty span{min-width:34px;text-align:center;font-weight:700}
.primary{border:0;border-radius:999px;background:var(--brand);color:#fff;font-weight:600;padding:13px 20px;font-size:1rem;flex:1}
.primary:disabled{opacity:.5}
.row-ft{display:flex;gap:12px;align-items:center}
.line{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--soft)}
.line .t{flex:1;min-width:0}
.line .t b{font-weight:600;font-size:.95rem;display:block}
.line .t small{color:var(--muted);display:block}
.line .lp{font-weight:600;white-space:nowrap}
.sum{display:flex;justify-content:space-between;font-weight:700;font-size:1.05rem;padding:14px 0 4px}
.hint{font-size:.8rem;color:var(--muted)}
.err{background:#fef2f2;color:#991b1b;border-radius:12px;padding:10px 12px;font-size:.9rem;margin-top:12px}
.hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
.ways{display:flex;gap:8px}
.ways label{flex:1;border:1px solid var(--line);border-radius:12px;padding:12px;display:flex;gap:10px;align-items:center;cursor:pointer;font-weight:600;font-size:.9rem}
.ways input{accent-color:var(--brand);width:18px;height:18px}
.ways label.on{border-color:var(--brand);box-shadow:0 0 0 3px rgba(17,24,39,.08)}
.st-card{max-width:560px;margin:24px auto 60px;border:1px solid var(--line);border-radius:24px;padding:24px}
.st-code{font-size:2.2rem;font-weight:800;letter-spacing:.02em}
.steps{display:flex;gap:8px;margin:20px 0}
.steps .s{flex:1;text-align:center;font-size:.78rem;color:var(--muted)}
.steps .s i{display:flex;align-items:center;justify-content:center;width:40px;height:40px;margin:0 auto 6px;border-radius:999px;background:var(--soft);font-size:1.1rem}
.steps .s.done{color:var(--ink);font-weight:600}
.steps .s.done i{background:var(--accent);color:#fff}
.steps .s.now i{background:var(--brand);color:#fff;box-shadow:0 0 0 6px rgba(17,24,39,.08)}
.notice{border-radius:16px;padding:14px 16px;font-size:.95rem}
.notice.bad{background:#fef2f2;color:#991b1b}
.notice.info{background:#f3f4f6;color:#374151;margin-top:12px}
.backlink{display:inline-flex;align-items:center;gap:6px;color:var(--ink);font-weight:600;text-decoration:none;margin-top:16px}
.ghost{border:1px solid var(--line);background:#fff;border-radius:999px;padding:10px 16px;font-weight:600;font-size:.9rem;color:var(--ink);text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.ghost.danger{color:var(--danger);border-color:#fecaca}
.pay{background:var(--soft);border-radius:16px;padding:14px 16px;margin-top:14px}
.pay-row{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--line)}
.pay-row:last-of-type{border-bottom:0}
.pay-row i{font-size:1.1rem;color:var(--muted);margin-top:2px}
.pay-l{font-weight:600}
.pay-n{font-weight:800;letter-spacing:.02em;user-select:all}
.pay-row small{display:block;color:var(--muted)}
.ord-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid var(--soft);text-decoration:none;color:var(--ink)}
.ord-row:last-child{border-bottom:0}
.ord-row .oc{font-weight:800;font-size:1.05rem}
.ord-row .ot{font-size:.8rem;color:var(--muted)}
.ord-row .os{margin-left:auto;font-size:.75rem;font-weight:600;border-radius:999px;padding:4px 10px;background:var(--soft);white-space:nowrap}
.ord-row .os.s-accepted{background:#e7f0ff;color:#084298}
.ord-row .os.s-ready,.ord-row .os.s-completed{background:#ecfdf5;color:#047857}
.ord-row .os.s-rejected,.ord-row .os.s-cancelled{background:#fef2f2;color:#991b1b}
.ord-row .bi-chevron-right{color:#9ca3af}
.foot{color:var(--muted);font-size:.75rem;text-align:center;padding:24px 0 100px}
</style>
</head>
<body>
<header class="topbar">
  <div class="wrap brand">
    <?php if ($logoUrl !== ''): ?>
      <img class="logo" src="<?= $e($logoUrl) ?>" alt="">
    <?php else: ?>
      <div class="logo"><?= $e(mb_strtoupper(mb_substr($companyName, 0, 1))) ?></div>
    <?php endif; ?>
    <div class="min-w-0">
      <h1><?= $e($companyName) ?></h1>
      <?php if ($ctx): ?><small><?= $e($shop['warehouse_name']) ?></small><?php endif; ?>
    </div>
    <div class="tb-actions">
      <?php if ($ctx && $shop['phone'] !== ''): ?>
      <a class="tb-btn" href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $shop['phone'])) ?>" aria-label="<?= $e(t('Call the shop')) ?>" title="<?= $e(t('Call the shop')) ?>"><i class="bi bi-telephone"></i></a>
      <?php endif; ?>
      <?php if (!$notFound): ?>
      <button type="button" class="tb-btn d-none" id="myOrdersBtn" aria-label="<?= $e(t('My orders')) ?>" title="<?= $e(t('My orders')) ?>"><i class="bi bi-receipt"></i><span class="tb-badge d-none" id="myOrdersCnt">0</span></button>
      <?php endif; ?>
      <?php if ($canOrder): ?>
      <button type="button" class="tb-btn" id="cartBtn" aria-label="<?= $e(t('Your order')) ?>" title="<?= $e(t('Your order')) ?>"><i class="bi bi-bag"></i><span class="tb-badge d-none" id="cartCnt">0</span></button>
      <?php endif; ?>
      <div class="lang-dd">
        <button class="lang-btn-x" id="osLangBtn" type="button" aria-label="<?= $e(t('Language')) ?>" aria-haspopup="true" aria-expanded="false"><i class="bi bi-globe2"></i><?= $e(strtoupper($lang)) ?></button>
        <div class="lang-menu-x" id="osLangMenu" role="menu">
          <a href="<?= $e($selfPath . '?lang=en') ?>" class="<?= $lang === 'en' ? 'active' : '' ?>" rel="nofollow" role="menuitem">English</a>
          <a href="<?= $e($selfPath . '?lang=sw') ?>" class="<?= $lang === 'sw' ? 'active' : '' ?>" rel="nofollow" role="menuitem">Kiswahili</a>
        </div>
      </div>
    </div>
  </div>
</header>

<?php if (!$notFound): ?>
<div class="scrim" id="ordScrim"></div>
<section class="sheet" id="ordSheet" role="dialog" aria-modal="true" aria-labelledby="ordTitle">
  <div class="hd"><h3 id="ordTitle"><?= $e(t('My orders')) ?></h3><button type="button" class="x" id="ordClose" aria-label="<?= $e(t('Close')) ?>"><i class="bi bi-x-lg"></i></button></div>
  <div class="bd" id="ordList"></div>
</section>
<?php endif; ?>

<?php if ($notFound): ?>
<main class="wrap">
  <div class="empty">
    <i class="bi bi-shop"></i>
    <h2 class="h5 fw-bold text-dark"><?= $e($action === 'track' && $ctx ? t('Order not found.') : t('This shop is not available.')) ?></h2>
    <p><?= $e(t('The link may have changed. Please ask the shop for its new link.')) ?></p>
  </div>
</main>

<?php elseif ($action === 'track'): ?>
<?php
  $st = $order['status'];
  $isDel = $order['type'] === 'delivery';
  $stepIdx = ['pending' => 0, 'accepted' => 1, 'ready' => 2, 'completed' => 3][$st] ?? -1;
  $steps = [['bi-send', t('Sent')], ['bi-check2', t('Accepted')], [$isDel ? 'bi-truck' : 'bi-bag-check', $isDel ? t('On the way') : t('Ready')], ['bi-check2-all', $isDel ? t('Delivered') : t('Collected')]];
?>
<main class="wrap">
  <div class="st-card">
    <div class="hint"><?= $e(t('Your order')) ?></div>
    <div class="st-code"><?= $e($order['code']) ?></div>
    <div class="hint"><?= $e($isDel ? t('Delivery order') : t('Pickup order')) ?><?= $order['price_type'] === 'wholesale' ? ' · ' . $e(t('Wholesale prices')) : '' ?></div>

    <div id="stBad" class="notice bad mt-3<?= in_array($st, ['rejected', 'cancelled'], true) ? '' : ' d-none' ?>">
      <b id="stBadTitle"><?= $e($order['cancelled_by'] === 'customer' ? t('You cancelled this order.') : t('The shop could not take this order.')) ?></b>
      <div id="stReason"><?= $e($order['cancelled_by'] === 'customer' ? '' : $order['reason']) ?></div>
    </div>
    <div id="stSteps" class="steps<?= $stepIdx < 0 ? ' d-none' : '' ?>">
      <?php foreach ($steps as $i => [$ic, $lb]): ?>
        <div class="s<?= $stepIdx > $i || $stepIdx === 3 ? ' done' : ($stepIdx === $i ? ' now' : '') ?>" data-step="<?= $i ?>"><i class="bi <?= $ic ?>"></i><?= $e($lb) ?></div>
      <?php endforeach; ?>
    </div>
    <p id="stText" class="hint mb-2"></p>

    <?php foreach ($order['lines'] as $ln): ?>
      <div class="line">
        <div class="t"><b><?= $e($fmtQty($ln['qty'])) ?> × <?= $e($ln['name']) ?></b></div>
        <div class="lp" data-money="<?= $e($ln['price'] * $ln['qty']) ?>"></div>
      </div>
    <?php endforeach; ?>
    <div class="sum"><span><?= $e(t('Total')) ?></span><span data-money="<?= $e($order['subtotal']) ?>"></span></div>
    <p class="hint"><?= $e($isDel ? t('You pay on delivery. The shop will call you to agree the delivery fee.') : t('You pay when you collect your order at the shop.')) ?></p>

    <?php if ($payAccts): ?>
    <div class="pay">
      <b><?= $e(t('You can also pay first')) ?></b>
      <?php foreach ($payAccts as $a): ?>
        <div class="pay-row"><i class="bi <?= $a['kind'] === 'bank' ? 'bi-bank' : 'bi-phone' ?>"></i>
          <div><span class="pay-l"><?= $e($a['label']) ?></span> <span class="pay-n"><?= $e($a['number']) ?></span>
            <?php if ($a['name'] !== ''): ?><small><?= $e($a['name']) ?></small><?php endif; ?></div></div>
      <?php endforeach; ?>
      <p class="hint mb-0 mt-2"><?= $e(sprintf(t('Use your order number %s as the reference. The shop confirms your payment before your order is closed.'), $order['code'])) ?></p>
    </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 mt-3">
      <?php if ($shop['phone'] !== ''): ?><a class="ghost" href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $shop['phone'])) ?>"><i class="bi bi-telephone"></i><?= $e(t('Call the shop')) ?></a><?php endif; ?>
      <button type="button" class="ghost danger<?= $st === 'pending' ? '' : ' d-none' ?>" id="stCancel"><i class="bi bi-x-circle"></i><?= $e(t('Cancel my order')) ?></button>
    </div>
    <div class="err d-none" id="stErr"></div>
    <a class="backlink" href="<?= $e($shopUrl) ?>"><i class="bi bi-arrow-left"></i><?= $e(t('Back to the shop')) ?></a>
  </div>
</main>

<?php else: ?>
<main class="wrap">
  <section class="hero">
    <h2><?= $e(t('Our products')) ?></h2>
    <?php if ($canOrder): ?>
      <?php if ($shop['pickup']): ?><span class="pill ok"><i class="bi bi-bag"></i><?= $e(t('Order now, pick up at the shop')) ?></span><?php endif; ?>
      <?php if ($shop['delivery']): ?><span class="pill ok"><i class="bi bi-truck"></i><?= $e(t('Delivery available')) ?></span><?php endif; ?>
    <?php else: ?>
      <div class="notice info"><i class="bi bi-info-circle me-1"></i><?= $e(t('Online ordering is not available right now. Please call or visit the shop.')) ?></div>
    <?php endif; ?>
    <?php if ($catalog['has_wholesale']): ?>
      <div><div class="ptoggle" role="group" aria-label="<?= $e(t('Prices')) ?>">
        <button type="button" class="on" data-pt="retail"><?= $e(t('Retail')) ?></button>
        <button type="button" data-pt="wholesale"><?= $e(t('Wholesale')) ?></button>
      </div></div>
    <?php endif; ?>
  </section>

  <div class="tools">
    <div class="search"><i class="bi bi-search"></i><input id="q" type="search" autocomplete="off" maxlength="60" placeholder="<?= $e(t('Search products…')) ?>" aria-label="<?= $e(t('Search')) ?>"></div>
    <div class="chips" id="chips" role="tablist"></div>
  </div>

  <div id="grid" class="grid" aria-live="polite"></div>
  <div id="none" class="empty d-none"><i class="bi bi-search"></i><?= $e(t('Nothing matches your search.')) ?></div>
  <?php if (!$catalog['items']): ?><div class="empty"><i class="bi bi-box-seam"></i><?= $e(t('No products are online right now.')) ?></div><?php endif; ?>
  <div class="foot"><?= $e(t('Prices are set by the shop. Nothing is charged online — you pay the shop.')) ?></div>
</main>

<?php if ($canOrder): ?>
<div class="bar d-none" id="bar"><button type="button" id="barBtn"><span class="cnt" id="barCnt">0</span><span><?= $e(t('View order')) ?></span><span class="tot" id="barTot"></span></button></div>
<?php endif; ?>

<div class="scrim" id="scrim"></div>
<section class="sheet" id="itemSheet" role="dialog" aria-modal="true" aria-labelledby="isName">
  <div class="hd"><h3 id="isName"></h3><button type="button" class="x" data-close aria-label="<?= $e(t('Close')) ?>"><i class="bi bi-x-lg"></i></button></div>
  <div class="bd" id="isBody"></div>
  <?php if ($canOrder): ?>
  <div class="ft"><div class="row-ft">
    <div class="qty"><button type="button" id="isMinus" aria-label="−"><i class="bi bi-dash"></i></button><input id="isQty" inputmode="decimal" value="1" aria-label="<?= $e(t('Quantity')) ?>"><button type="button" id="isPlus" aria-label="+"><i class="bi bi-plus"></i></button></div>
    <button type="button" class="primary" id="isAdd"></button>
  </div><div class="err d-none" id="isErr"></div></div>
  <?php endif; ?>
</section>

<?php if ($canOrder): ?>
<section class="sheet" id="cartSheet" role="dialog" aria-modal="true" aria-labelledby="csTitle">
  <div class="hd"><h3 id="csTitle"><?= $e(t('Your order')) ?></h3><button type="button" class="x" data-close aria-label="<?= $e(t('Close')) ?>"><i class="bi bi-x-lg"></i></button></div>
  <div class="bd">
    <div id="csLines"></div>
    <div class="sum"><span><?= $e(t('Total')) ?></span><span id="csTot"></span></div>
    <div class="hint d-none" id="csWs"><?= $e(t('Wholesale prices — the shop confirms them when accepting your order.')) ?></div>
    <label class="lb" for="csName"><?= $e(t('Your name')) ?></label>
    <input class="fld" id="csName" maxlength="<?= RO_MAX_NAME ?>" autocomplete="name">
    <label class="lb" for="csPhone"><?= $e(t('Phone number')) ?></label>
    <input class="fld" id="csPhone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel">
    <?php if ($shop['pickup'] && $shop['delivery']): ?>
    <label class="lb"><?= $e(t('How do you want it?')) ?></label>
    <div class="ways" role="radiogroup">
      <label class="on"><input type="radio" name="fulfil" value="pickup" checked><?= $e(t('Pickup at the shop')) ?></label>
      <label><input type="radio" name="fulfil" value="delivery"><?= $e(t('Delivery')) ?></label>
    </div>
    <?php else: ?>
    <input type="hidden" name="fulfil" value="<?= $shop['delivery'] ? 'delivery' : 'pickup' ?>">
    <p class="hint mt-3 mb-0"><i class="bi <?= $shop['delivery'] ? 'bi-truck' : 'bi-bag' ?> me-1"></i><?= $e($shop['delivery'] ? t('This shop delivers. Give your address below.') : t('Pick up your order at the shop.')) ?></p>
    <?php endif; ?>
    <div id="csAddrWrap" class="<?= $shop['delivery'] && !$shop['pickup'] ? '' : 'd-none' ?>">
      <label class="lb" for="csAddr"><?= $e(t('Delivery address or area')) ?></label>
      <input class="fld" id="csAddr" maxlength="<?= RS_MAX_ADDRESS ?>" autocomplete="street-address" placeholder="<?= $e(t('e.g. Sinza Mori, near the market')) ?>">
    </div>
    <label class="lb" for="csNote"><?= $e(t('Note for the shop (optional)')) ?></label>
    <input class="fld" id="csNote" maxlength="<?= RO_MAX_NOTE ?>">
    <div class="hp" aria-hidden="true"><label>Website <input id="csWebsite" tabindex="-1" autocomplete="off"></label></div>
    <p class="hint mt-3 mb-0" id="csHow"></p>
    <div class="err d-none" id="csErr"></div>
  </div>
  <div class="ft"><button type="button" class="primary w-100" id="csSend"><?= $e(t('Send order')) ?></button></div>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if (!$notFound): ?>
<script nonce="<?= $e($nonce) ?>">
// My orders — remembered in THIS browser only (no account, nothing sent anywhere).
(function () {
  'use strict';
  var KEY = 'bms_my_shop_orders', WEEK = 7 * 864e5;
  var L = <?= json_encode(['none' => t('No orders yet from this phone.'),
      'st' => ['pending' => t('Waiting'), 'accepted' => t('Accepted'), 'ready' => t('Ready'), 'completed' => t('Done'), 'rejected' => t('Rejected'), 'cancelled' => t('Cancelled')]], $jsonOpts) ?>;
  var OK_URL = /^\/(?:[A-Za-z0-9_.-]+\/)*s\/[a-f0-9]{32}\/o\/[a-f0-9]{32}(?:\?lang=(?:en|sw))?$/;
  function load() {
    var a = []; try { a = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { a = []; }
    if (!Array.isArray(a)) a = [];
    var now = Date.now();
    return a.filter(function (o) { return o && typeof o.url === 'string' && OK_URL.test(o.url) && now - (o.at || 0) < WEEK; }).slice(0, 10);
  }
  function store(a) { try { localStorage.setItem(KEY, JSON.stringify(a.slice(0, 10))); } catch (e) {} }
  window.rsRememberOrder = function (url, code) {
    if (typeof url !== 'string' || !OK_URL.test(url)) return;
    var path = url.split('?')[0], a = load().filter(function (o) { return o.url.split('?')[0] !== path; });
    a.unshift({ url: url, code: String(code || '').slice(0, 12), at: Date.now() });
    store(a); paint();
  };
  var btn = document.getElementById('myOrdersBtn'), cnt = document.getElementById('myOrdersCnt');
  var sheet = document.getElementById('ordSheet'), scrim = document.getElementById('ordScrim'), list = document.getElementById('ordList');
  function paint() { var a = load(); if (!btn) return; btn.classList.toggle('d-none', a.length === 0); cnt.textContent = String(a.length); cnt.classList.toggle('d-none', a.length === 0); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function open() {
    var a = load(); list.textContent = '';
    if (!a.length) list.appendChild(el('p', 'hint py-3', L.none));
    a.forEach(function (o) {
      var row = el('a', 'ord-row'); row.href = o.url;
      var t = el('div', ''); t.appendChild(el('div', 'oc', o.code || '—'));
      t.appendChild(el('div', 'ot', new Date(o.at).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })));
      var st = el('span', 'os', '…');
      row.appendChild(t); row.appendChild(st); row.appendChild(el('i', 'bi bi-chevron-right'));
      list.appendChild(row);
      fetch(o.url.split('?')[0] + '/status', { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.success) { st.textContent = L.st[d.status] || d.status; st.className = 'os s-' + String(d.status).replace(/[^a-z]/g, ''); } else st.textContent = '—';
      }).catch(function () { st.textContent = '—'; });
    });
    sheet.classList.add('on'); scrim.classList.add('on'); document.body.style.overflow = 'hidden';
  }
  function close() { sheet.classList.remove('on'); scrim.classList.remove('on'); document.body.style.overflow = ''; }
  if (btn) btn.addEventListener('click', open);
  if (scrim) scrim.addEventListener('click', close);
  var cl = document.getElementById('ordClose'); if (cl) cl.addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
<?php if ($action === 'track' && $order): ?>
  window.rsRememberOrder(<?= json_encode(rsStatusUrl($ctx['token'], (string)$route['ref']), $jsonOpts) ?>, <?= json_encode($order['code'], $jsonOpts) ?>);
<?php endif; ?>
  paint();
})();
</script>
<?php endif; ?>
<script nonce="<?= $e($nonce) ?>">
(function () {
  'use strict';
  var CUR = <?= json_encode(bmsCurrencySymbol(), $jsonOpts) ?>;
  function money(v) {
    v = Number(v) || 0;
    var whole = Math.abs(v - Math.round(v)) < 0.005;
    return CUR + v.toLocaleString('en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 });
  }
  function qtyText(q) { return String(Math.round(Number(q) * 1000) / 1000); }
  document.querySelectorAll('[data-money]').forEach(function (el) { el.textContent = money(el.getAttribute('data-money')); });

<?php if (!$notFound && $action === 'track'): ?>
  var DEL = <?= $order['type'] === 'delivery' ? 'true' : 'false' ?>;
  var TXT = <?= json_encode([
      'pending'   => t('Waiting for the shop to confirm your order…'),
      'accepted'  => t('The shop confirmed your order and is getting it ready.'),
      'ready'     => $order['type'] === 'delivery' ? t('Your order is on the way.') : t('Your order is ready. Please collect it at the shop.'),
      'completed' => t('Thank you for shopping with us!'),
  ], $jsonOpts) ?>;
  var M = <?= json_encode(['youCancelled' => t('You cancelled this order.'), 'shopCould' => t('The shop could not take this order.'),
      'cancelQ' => t('Cancel this order?'), 'tapAgain' => t('Tap again to confirm.'), 'offline' => t('No connection. Please check your internet and try again.')], $jsonOpts) ?>;
  var BASE = <?= json_encode(rsStatusUrl($ctx['token'], $route['ref']), $jsonOpts) ?>;
  var IDX = { pending: 0, accepted: 1, ready: 2, completed: 3 };
  function paint(st, reason, by) {
    var bad = (st === 'rejected' || st === 'cancelled');
    document.getElementById('stBad').classList.toggle('d-none', !bad);
    document.getElementById('stSteps').classList.toggle('d-none', bad);
    document.getElementById('stCancel').classList.toggle('d-none', st !== 'pending');
    if (bad) {
      document.getElementById('stBadTitle').textContent = by === 'customer' ? M.youCancelled : M.shopCould;
      document.getElementById('stReason').textContent = by === 'customer' ? '' : (reason || '');
      document.getElementById('stText').textContent = ''; return true;
    }
    var i = IDX[st]; if (i === undefined) return true;
    document.querySelectorAll('#stSteps .s').forEach(function (el) {
      var k = Number(el.getAttribute('data-step'));
      el.classList.toggle('done', k < i || i === 3);
      el.classList.toggle('now', k === i && i !== 3);
    });
    document.getElementById('stText').textContent = TXT[st] || '';
    return st === 'completed';
  }
  var done = paint(<?= json_encode($order['status']) ?>, <?= json_encode($order['reason'], $jsonOpts) ?>, <?= json_encode($order['cancelled_by']) ?>);
  var fails = 0;
  function poll() {
    if (done || document.hidden) { if (!done) setTimeout(poll, 8000); return; }
    fetch(BASE + '/status', { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.success) { fails = 0; done = paint(d.status, d.reason, d.cancelled_by); }
    }).catch(function () { fails++; }).then(function () { if (!done && fails < 20) setTimeout(poll, 8000); });
  }
  setTimeout(poll, 8000);
  var armed = false, cancelLabel = document.getElementById('stCancel').innerHTML;
  document.getElementById('stCancel').addEventListener('click', function () {
    var b = this;
    // Two taps: the first asks, the second cancels (no browser pop-up).
    if (!armed) { armed = true; b.textContent = M.cancelQ + ' ' + M.tapAgain; setTimeout(function () { if (armed) { armed = false; b.innerHTML = cancelLabel; } }, 5000); return; }
    armed = false; b.innerHTML = cancelLabel; b.disabled = true;
    var err = document.getElementById('stErr');
    fetch(BASE + '/cancel', { method: 'POST', credentials: 'omit', cache: 'no-store', headers: { 'Content-Type': 'application/json' }, body: '{}' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.success) { done = paint('cancelled', '', 'customer'); err.classList.add('d-none'); }
        else { err.textContent = (d && d.message) || M.offline; err.classList.remove('d-none'); poll(); }
      }).catch(function () { err.textContent = M.offline; err.classList.remove('d-none'); })
      .then(function () { b.disabled = false; });
  });
<?php elseif (!$notFound): ?>
  var ITEMS = <?= json_encode($catalog['items'], $jsonOpts) ?>;
  var CATS  = <?= json_encode((object)$catalog['categories'], $jsonOpts) ?>;
  var CAN_ORDER = <?= $canOrder ? 'true' : 'false' ?>;
  var HAS_WS = <?= $catalog['has_wholesale'] ? 'true' : 'false' ?>;
  var PICKUP = <?= $shop['pickup'] ? 'true' : 'false' ?>, DELIVERY = <?= $shop['delivery'] ? 'true' : 'false' ?>;
  var ORDER_URL = <?= json_encode(getUrl('s/' . $ctx['token'] . '/order') . ($lang !== 'en' ? '?lang=' . $lang : ''), $jsonOpts) ?>;
  var FORM_TOKEN = <?= json_encode($canOrder ? roFormToken($pdo, $ctx['token']) : '') ?>;
  var STORE_KEY = 'bms_shop_' + <?= json_encode(substr(hash('sha256', $ctx['token']), 0, 16)) ?>;
  var T = <?= json_encode([
      'all' => t('All'), 'soldOut' => t('Sold out'), 'available' => t('Available'), 'add' => t('Add'), 'addFor' => t('Add · %s'),
      'remove' => t('Remove'), 'sending' => t('Sending…'), 'send' => t('Send order'), 'offline' => t('No connection. Please check your internet and try again.'),
      'empty' => t('Your order is empty.'), 'name' => t('Please enter your name.'), 'phone' => t('Please enter a valid phone number.'),
      'addr' => t('Please enter the delivery address or area.'), 'whole' => t('%s is sold in whole numbers.'), 'qty' => t('Enter a quantity.'),
      'per' => t('per %s'), 'howPick' => t('The shop confirms your order. You pay when you collect it.'),
      'howDel' => t('The shop confirms your order and calls you about the delivery fee. You pay on delivery.'), 'ws' => t('Wholesale'),
  ], $jsonOpts) ?>;

  var byId = {}; ITEMS.forEach(function (it) { byId[it.id] = it; });
  var state = { cat: 0, q: '', pt: 'retail' };
  var cart = [];
  try {
    var saved = JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
    if (saved && Array.isArray(saved.lines)) cart = saved.lines.filter(function (l) { return byId[l.id] && !byId[l.id].sold_out && Number(l.qty) > 0; });
    if (HAS_WS && saved && saved.pt === 'wholesale') state.pt = 'wholesale';
  } catch (e) { cart = []; }
  function save() { try { localStorage.setItem(STORE_KEY, JSON.stringify({ lines: cart, pt: state.pt })); } catch (e) {} }

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function fmt(s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return String(s).replace(/%(\d\$)?[sd]/g, function () { return String(a[i++]); }); }
  function priceOf(it) { return state.pt === 'wholesale' && it.wholesale ? it.wholesale : it.price; }
  function qtyOf(id) { return cart.reduce(function (s, l) { return s + (l.id === id ? Number(l.qty) : 0); }, 0); }
  function step(it) { return it.decimal ? 0.5 : 1; }
  function cleanQty(it, v) {
    v = Math.round(Number(String(v).replace(',', '.')) * 1000) / 1000;
    if (!isFinite(v) || v <= 0) return 0;
    if (!it.decimal) v = Math.round(v);
    return Math.min(999, v);
  }

  // ── Price toggle ──
  document.querySelectorAll('[data-pt]').forEach(function (b) {
    b.classList.toggle('on', b.getAttribute('data-pt') === state.pt);
    b.addEventListener('click', function () {
      state.pt = b.getAttribute('data-pt');
      document.querySelectorAll('[data-pt]').forEach(function (x) { x.classList.toggle('on', x === b); });
      save(); renderGrid(); paintBar();
    });
  });

  // ── Chips + grid ──
  var chips = document.getElementById('chips');
  function chip(id, label) {
    var b = el('button', 'chip' + (state.cat === id ? ' active' : ''), label); b.type = 'button'; b.setAttribute('role', 'tab');
    b.addEventListener('click', function () { state.cat = id; renderChips(); renderGrid(); });
    chips.appendChild(b);
  }
  function renderChips() {
    chips.textContent = '';
    if (!Object.keys(CATS).length) { chips.classList.add('d-none'); return; }
    chip(0, T.all);
    Object.keys(CATS).forEach(function (k) { if (ITEMS.some(function (i) { return i.category_id === Number(k); })) chip(Number(k), CATS[k]); });
  }
  var grid = document.getElementById('grid');
  function renderGrid() {
    grid.textContent = '';
    var q = state.q.toLowerCase(), shown = 0;
    ITEMS.forEach(function (it) {
      if (state.cat && it.category_id !== state.cat) return;
      if (q && it.name.toLowerCase().indexOf(q) < 0 && String(CATS[it.category_id] || '').toLowerCase().indexOf(q) < 0) return;
      shown++;
      var c = el('div', 'card-x' + (it.sold_out ? ' out' : '') + (qtyOf(it.id) ? ' has' : ''));
      var ph = el('div', 'ph');
      if (it.image) { var img = el('img'); img.src = it.image; img.alt = ''; img.loading = 'lazy'; img.decoding = 'async'; img.addEventListener('error', function () { img.remove(); ph.appendChild(el('div', 'ini', it.name.charAt(0).toUpperCase())); }); ph.appendChild(img); }
      else { ph.appendChild(el('div', 'ini', it.name.charAt(0).toUpperCase())); }
      ph.appendChild(el('span', 'badge-x' + (it.sold_out ? '' : ' av'), it.sold_out ? T.soldOut : T.available));
      if (CAN_ORDER && !it.sold_out) {
        var a = el('button', 'add'); a.type = 'button'; a.setAttribute('aria-label', T.add + ' ' + it.name);
        var n = qtyOf(it.id);
        if (n) a.appendChild(el('span', 'n', qtyText(n))); else a.appendChild(el('i', 'bi bi-plus-lg'));
        a.addEventListener('click', function (ev) { ev.stopPropagation(); if (it.decimal) openItem(it); else addLine(it.id, 1); });
        ph.appendChild(a);
      }
      c.appendChild(ph);
      c.appendChild(el('div', 'nm', it.name));
      if (CATS[it.category_id]) c.appendChild(el('div', 'ct', CATS[it.category_id]));
      var pr = el('div', 'pr', money(priceOf(it)));
      if (it.decimal && it.unit) pr.appendChild(el('small', '', ' ' + fmt(T.per, it.unit)));
      c.appendChild(pr);
      if (!it.sold_out) c.addEventListener('click', function () { openItem(it); });
      grid.appendChild(c);
    });
    document.getElementById('none').classList.toggle('d-none', shown > 0 || !ITEMS.length);
  }
  var qIn = document.getElementById('q');
  qIn.addEventListener('input', function () { state.q = qIn.value.trim(); renderGrid(); });

  // ── Sheets ──
  var scrim = document.getElementById('scrim'), openSheet = null;
  function show(s) { if (openSheet) openSheet.classList.remove('on'); openSheet = s; s.classList.add('on'); scrim.classList.add('on'); document.body.style.overflow = 'hidden'; }
  function hide() { if (openSheet) openSheet.classList.remove('on'); openSheet = null; scrim.classList.remove('on'); document.body.style.overflow = ''; }
  scrim.addEventListener('click', hide);
  document.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', hide); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });

  var cur = null;
  function openItem(it) {
    cur = it;
    document.getElementById('isName').textContent = it.name;
    var body = document.getElementById('isBody'); body.textContent = '';
    if (it.image) { var img = el('img', 'big'); img.src = it.image; img.alt = ''; body.appendChild(img); }
    var top = el('div', 'd-flex justify-content-between align-items-baseline');
    top.appendChild(el('div', 'hint', CATS[it.category_id] || ''));
    var p = el('div', 'fw-bold fs-5', money(priceOf(it))); if (it.decimal && it.unit) p.appendChild(el('small', 'hint', ' ' + fmt(T.per, it.unit)));
    top.appendChild(p); body.appendChild(top);
    if (it.wholesale) body.appendChild(el('div', 'hint mt-1', T.ws + ': ' + money(it.wholesale)));
    body.appendChild(el('div', 'hint mt-2', it.sold_out ? T.soldOut : T.available));
    if (CAN_ORDER) { document.getElementById('isQty').value = it.decimal ? '1' : '1'; isErr(''); paintAdd(); }
    show(document.getElementById('itemSheet'));
  }
  function isErr(m) { var e = document.getElementById('isErr'); if (!e) return; e.textContent = m; e.classList.toggle('d-none', !m); }
  function paintAdd() {
    var b = document.getElementById('isAdd'); if (!b || !cur) return;
    var q = cleanQty(cur, document.getElementById('isQty').value);
    b.textContent = fmt(T.addFor, money(priceOf(cur) * q));
  }
  if (CAN_ORDER) {
    var qtyIn = document.getElementById('isQty');
    qtyIn.addEventListener('input', function () { isErr(''); paintAdd(); });
    document.getElementById('isMinus').addEventListener('click', function () { var q = cleanQty(cur, qtyIn.value) - step(cur); qtyIn.value = qtyText(Math.max(step(cur), q)); paintAdd(); });
    document.getElementById('isPlus').addEventListener('click', function () { var q = cleanQty(cur, qtyIn.value) + step(cur); qtyIn.value = qtyText(Math.min(999, q)); paintAdd(); });
    document.getElementById('isAdd').addEventListener('click', function () {
      var raw = Number(String(qtyIn.value).replace(',', '.'));
      if (!cur.decimal && Math.abs(raw - Math.round(raw)) > 1e-7) { isErr(fmt(T.whole, cur.name)); return; }
      var q = cleanQty(cur, qtyIn.value);
      if (!q) { isErr(T.qty); return; }
      addLine(cur.id, q); hide();
    });
  }

  function addLine(id, qty) {
    var same = cart.filter(function (l) { return l.id === id; })[0];
    if (same) same.qty = Math.min(999, Math.round((Number(same.qty) + qty) * 1000) / 1000); else cart.push({ id: id, qty: qty });
    if (cart.length > 30) cart.pop();
    save(); renderGrid(); paintBar();
  }

  // ── Basket ──
  function total() { return cart.reduce(function (s, l) { return s + priceOf(byId[l.id]) * Number(l.qty); }, 0); }
  function paintBar() {
    var bar = document.getElementById('bar'); if (!bar) return;
    var n = cart.length, tot = total();
    bar.classList.toggle('d-none', n === 0);
    document.getElementById('barCnt').textContent = String(n);
    document.getElementById('barTot').textContent = money(tot);
    var cs = document.getElementById('csTot'); if (cs) cs.textContent = money(tot);
    var ws = document.getElementById('csWs'); if (ws) ws.classList.toggle('d-none', state.pt !== 'wholesale');
    var cc = document.getElementById('cartCnt'), cb = document.getElementById('cartBtn');
    if (cc && cb) {
      var before = Number(cc.textContent) || 0;
      cc.textContent = String(n); cc.classList.toggle('d-none', n === 0);
      if (n > before) { cb.classList.remove('bump'); void cb.offsetWidth; cb.classList.add('bump'); }
    }
  }
  function renderCart() {
    var box = document.getElementById('csLines'); box.textContent = '';
    if (!cart.length) box.appendChild(el('p', 'hint py-3', T.empty));
    cart.forEach(function (l, idx) {
      var it = byId[l.id]; if (!it) return;
      var row = el('div', 'line'), t = el('div', 't');
      t.appendChild(el('b', '', it.name));
      t.appendChild(el('small', '', money(priceOf(it)) + (it.decimal && it.unit ? ' ' + fmt(T.per, it.unit) : '')));
      var q = el('div', 'qty mt-2');
      var minus = el('button'); minus.type = 'button'; minus.setAttribute('aria-label', Number(l.qty) <= step(it) ? T.remove : '−');
      minus.appendChild(el('i', 'bi ' + (Number(l.qty) <= step(it) ? 'bi-trash3' : 'bi-dash')));
      var plus = el('button'); plus.type = 'button'; plus.setAttribute('aria-label', '+'); plus.appendChild(el('i', 'bi bi-plus'));
      minus.addEventListener('click', function () { if (Number(l.qty) > step(it)) l.qty = Math.round((Number(l.qty) - step(it)) * 1000) / 1000; else cart.splice(idx, 1); save(); renderCart(); renderGrid(); if (!cart.length) hide(); });
      plus.addEventListener('click', function () { l.qty = Math.min(999, Math.round((Number(l.qty) + step(it)) * 1000) / 1000); save(); renderCart(); renderGrid(); });
      q.appendChild(minus); q.appendChild(el('span', '', qtyText(l.qty) + (it.decimal && it.unit ? ' ' + it.unit : ''))); q.appendChild(plus);
      t.appendChild(q);
      row.appendChild(t); row.appendChild(el('div', 'lp', money(priceOf(it) * Number(l.qty))));
      box.appendChild(row);
    });
    paintBar(); paintHow();
  }
  function fulfil() { var r = document.querySelector('input[name="fulfil"]:checked') || document.querySelector('input[name="fulfil"]'); return r ? r.value : 'pickup'; }
  function paintHow() {
    var f = fulfil();
    var w = document.getElementById('csAddrWrap'); if (w) w.classList.toggle('d-none', f !== 'delivery');
    var h = document.getElementById('csHow'); if (h) h.textContent = f === 'delivery' ? T.howDel : T.howPick;
    document.querySelectorAll('.ways label').forEach(function (lb) { lb.classList.toggle('on', lb.querySelector('input').checked); });
  }
  function csErr(m) { var e = document.getElementById('csErr'); e.textContent = m; e.classList.toggle('d-none', !m); }
  if (CAN_ORDER) {
    document.querySelectorAll('input[name="fulfil"]').forEach(function (r) { r.addEventListener('change', paintHow); });
    function openCart() { csErr(''); renderCart(); show(document.getElementById('cartSheet')); }
    document.getElementById('barBtn').addEventListener('click', openCart);
    document.getElementById('cartBtn').addEventListener('click', openCart);
    var sending = false;
    document.getElementById('csSend').addEventListener('click', function () {
      if (sending) return;
      if (!cart.length) { csErr(T.empty); return; }
      var name = document.getElementById('csName').value.trim(), phone = document.getElementById('csPhone').value.trim();
      if (name.length < 2) { csErr(T.name); return; }
      var d = phone.replace(/\D/g, ''); if (d.length < 7 || d.length > 15) { csErr(T.phone); return; }
      var f = fulfil(), addr = (document.getElementById('csAddr') || { value: '' }).value.trim();
      if (f === 'delivery' && addr.length < 3) { csErr(T.addr); return; }
      sending = true; csErr('');
      var btn = document.getElementById('csSend'); btn.disabled = true; btn.textContent = T.sending;
      fetch(ORDER_URL, {
        method: 'POST', credentials: 'omit', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          form_token: FORM_TOKEN, website: document.getElementById('csWebsite').value, name: name, phone: phone,
          note: document.getElementById('csNote').value.trim().slice(0, 200), fulfil: f, address: addr.slice(0, 200), price_type: state.pt,
          lines: cart.map(function (l) { return { id: l.id, qty: Number(l.qty) }; })
        })
      }).then(function (r) { return r.json().catch(function () { return { success: false, message: T.offline }; }); })
        .then(function (d) {
          if (d && d.success && typeof d.status_url === 'string' && d.status_url.charAt(0) === '/') {
            cart = []; save();
            if (window.rsRememberOrder) window.rsRememberOrder(d.status_url, d.order_code);
            window.location.assign(d.status_url); return;
          }
          if (d && d.refresh) setTimeout(function () { window.location.reload(); }, 1500);
          csErr((d && d.message) || T.offline);
        })
        .catch(function () { csErr(T.offline); })
        .then(function () { sending = false; btn.disabled = false; btn.textContent = T.send; });
    });
  }

  renderChips(); renderGrid(); paintBar();
<?php endif; ?>
})();
(function(){
  var btn=document.getElementById('osLangBtn'),menu=document.getElementById('osLangMenu');
  if(!btn||!menu)return;
  btn.addEventListener('click',function(e){ e.stopPropagation(); var open=menu.style.display==='block'; menu.style.display=open?'none':'block'; btn.setAttribute('aria-expanded',open?'false':'true'); });
  document.addEventListener('click',function(){menu.style.display='none';btn.setAttribute('aria-expanded','false');});
})();
</script>
</body>
</html>
