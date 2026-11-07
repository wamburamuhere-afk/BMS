<?php
/**
 * public_menu.php — the PUBLIC restaurant menu & ordering page
 * (restaurant_online_menu_plan.md). No login. Reached only as:
 *   GET  /m/<link>               the menu (shop link = pickup, table QR = that table)
 *   POST /m/<link>/order         place an order request (JSON in, JSON out)
 *   GET  /m/<link>/o/<ref>       that order's status page
 *   GET  /m/<link>/o/<ref>/status  its status as JSON (the page polls it)
 *
 * Security (see the plan's "Hard rules"):
 *  - never reads $_SESSION for identity, never calls includeHeader();
 *  - every failure to open a link renders the SAME generic page / JSON;
 *  - prices are built on the server from ids (core/restaurant_online.php);
 *  - an order is only a request: staff accept it before anything else happens;
 *  - strict CSP with a per-request nonce, no-referrer, noindex, no-store.
 */
require_once __DIR__ . '/roots.php';
require_once __DIR__ . '/core/restaurant_online.php';

global $pdo;

$route  = $GLOBALS['__ro_route'] ?? ['token' => '', 'action' => 'menu', 'ref' => ''];
$action = $route['action'];

// Language: an explicit, whitelisted ?lang= (no login here to remember one).
$lang = in_array($_GET['lang'] ?? '', SUPPORTED_LANGUAGES, true) ? $_GET['lang'] : 'en';
loadLanguage($lang);

$ctx = null;
try {
    $ctx = roResolveToken($pdo, (string)$route['token']);
} catch (Throwable $e) {
    error_log('public_menu resolve: ' . $e->getMessage());
    $ctx = null;
}

/** JSON reply and stop. */
$json = function (int $code, array $body): void {
    roSendSecurityHeaders(true);
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

// ─── POST /m/<link>/order ────────────────────────────────────────────────────
if ($action === 'order') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $json(405, ['success' => false, 'message' => t('Method not allowed')]);
    if (!$ctx) $json(404, ['success' => false, 'message' => t('This menu is not available.')]);
    // JSON only: a cross-site <form> cannot send this content type without a CORS
    // preflight, and this endpoint never answers one.
    $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ctype, 'application/json') !== 0) $json(415, ['success' => false, 'message' => t('Your order could not be read. Please try again.')]);
    $raw = (string)file_get_contents('php://input', false, null, 0, 65536);
    $in  = json_decode($raw, true);
    if (!is_array($in)) $json(400, ['success' => false, 'message' => t('Your order could not be read. Please try again.')]);

    if (!roCanOrder($ctx)) $json(403, ['success' => false, 'message' => t('Ordering from this menu is switched off. Please ask a waiter.')]);

    // Honeypot: a field real people never see. A bot that fills it gets a quiet refusal.
    if (trim((string)($in['website'] ?? '')) !== '') $json(400, ['success' => false, 'message' => t('Your order could not be read. Please try again.')]);
    $why = roCheckFormToken($pdo, $ctx['token'], $in['form_token'] ?? null);
    if ($why === 'old' || $why === 'bad') $json(409, ['success' => false, 'message' => t('This page is out of date. Please refresh the menu and try again.'), 'refresh' => true]);
    if ($why === 'fast') $json(429, ['success' => false, 'message' => t('Please wait a moment and try again.')]);

    $name  = roCleanText($in['name'] ?? '', RO_MAX_NAME);
    $phone = preg_replace('/[^0-9+]/', '', (string)($in['phone'] ?? '')) ?? '';
    $phone = substr($phone, 0, 20);
    $note  = roCleanText($in['note'] ?? '', RO_MAX_NOTE);
    if (!$ctx['table']) {
        // Pickup: staff need to know who to call.
        if (mb_strlen($name) < 2) $json(422, ['success' => false, 'message' => t('Please enter your name.')]);
        $digits = strlen(preg_replace('/\D/', '', $phone) ?? '');
        if ($digits < 7 || $digits > 15) $json(422, ['success' => false, 'message' => t('Please enter a valid phone number.')]);
    }

    try {
        $client = roClientHash($pdo);
        roCheckLimits($pdo, $ctx['warehouse']['warehouse_id'], $ctx['table']['table_id'] ?? null, $client);
        $built  = roBuildOrder($pdo, $ctx['warehouse']['warehouse_id'], $in['lines'] ?? null);
        $placed = roPlaceOrder($pdo, $ctx, $built, $name, $phone, $note, $client);
    } catch (RoUserError $e) {
        $json(422, ['success' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('public_menu order: ' . $e->getMessage());
        $json(500, ['success' => false, 'message' => t('Something went wrong. Please try again or ask a waiter.')]);
    }
    $json(200, [
        'success'    => true,
        'order_code' => $placed['order_code'],
        'status_url' => roStatusUrl($ctx['token'], $placed['public_ref']) . ($lang !== 'en' ? '?lang=' . $lang : ''),
    ]);
}

// ─── GET /m/<link>/o/<ref>/status ────────────────────────────────────────────
if ($action === 'status') {
    $order = $ctx ? roPublicOrder($pdo, $ctx['warehouse']['warehouse_id'], (string)$route['ref']) : null;
    if (!$order) $json(404, ['success' => false, 'message' => t('Order not found.')]);
    $json(200, ['success' => true, 'status' => $order['status'], 'reason' => $order['reason']]);
}

// ─── HTML pages ──────────────────────────────────────────────────────────────
$nonce = base64_encode(random_bytes(18));
roSendSecurityHeaders(false);
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce' https://cdn.jsdelivr.net; "
     . "font-src https://cdn.jsdelivr.net; img-src 'self' data: https:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$companyName = (string)get_setting('company_name', 'Restaurant');
$logoPath    = (string)get_setting('company_logo', '');
$logoUrl     = $logoPath !== '' ? getUrl($logoPath) : '';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$otherLang = $lang === 'sw' ? 'en' : 'sw';
$otherLangLabel = $lang === 'sw' ? 'English' : 'Kiswahili';
$selfPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$langUrl  = $selfPath . '?lang=' . $otherLang;

$order = null;
if ($ctx && $action === 'track') {
    $order = roPublicOrder($pdo, $ctx['warehouse']['warehouse_id'], (string)$route['ref']);
}
$notFound = !$ctx || ($action === 'track' && !$order);
if ($notFound) http_response_code(404);

$menu = ['items' => [], 'categories' => []];
if ($ctx && $action === 'menu') {
    try { $menu = roPublicMenu($pdo, $ctx['warehouse']['warehouse_id']); }
    catch (Throwable $ex) { error_log('public_menu menu: ' . $ex->getMessage()); }
}
$canOrder = $ctx && $action === 'menu' && roCanOrder($ctx);
$title = $ctx ? $ctx['warehouse']['warehouse_name'] : t('Menu');
// Page dressing — all from data BMS already holds (nothing typed in for the page itself).
$wh = $ctx['warehouse'] ?? null;
$popular = [];
$payNames = [];
if ($ctx && $action === 'menu') {
    foreach ($menu['items'] as &$it) $it['name'] = roDisplayName($it['name']);
    unset($it);
    foreach ($menu['categories'] as $k => $v) $menu['categories'][$k] = roDisplayName($v);
    $cand = [];
    foreach ($menu['items'] as $it) if (!$it['sold_out']) $cand[] = $it['id'];
    $popular = roPopularIds($pdo, $wh['warehouse_id'], $cand);
    if (!empty($wh['show_payment'])) $payNames = roPaymentMethodNames($pdo);
}
$branch   = ($wh && mb_strtolower(trim($wh['warehouse_name'])) !== mb_strtolower(trim($companyName))) ? $wh['warehouse_name'] : '';
$place    = $wh ? $wh['location'] : '';
$addrLine = $wh ? ($wh['address'] !== '' ? $wh['address'] : $wh['location']) : '';
$phone    = $wh ? $wh['phone'] : '';
$waNumber = roWhatsAppNumber($phone);
$telHref  = preg_replace('/[^0-9+]/', '', $phone);
// Share = the restaurant's own menu link, never a table QR (that would let anyone order to that table).
$shareUrl = ($wh && $wh['menu_token'] !== '') ? getUrl('m/' . $wh['menu_token']) : '';
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
:root{--ink:#111827;--muted:#6b7280;--line:#e5e7eb;--soft:#f3f4f6;--bg:#ffffff;--brand:#c2410c;--brand-dark:#7c2d12;--brand-soft:#fff1e8;--accent:#16a34a;--warn:#b45309;--danger:#dc2626;--r:16px;--wa:#16a34a}
*{-webkit-tap-highlight-color:transparent}
html{font-size:16px}
body{background:var(--bg);color:var(--ink);font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif;font-size:1rem;-webkit-font-smoothing:antialiased}
.wrap{max-width:1120px;margin:0 auto;padding:0 16px}
.topbar{position:sticky;top:0;z-index:30;background:rgba(255,255,255,.92);backdrop-filter:saturate(180%) blur(12px);border-bottom:1px solid var(--line)}
.brand{display:flex;align-items:center;gap:12px;padding:12px 0}
.brand .logo{width:44px;height:44px;border-radius:12px;object-fit:cover;background:var(--soft);flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--muted)}
.brand h1{font-size:1.05rem;font-weight:700;margin:0;line-height:1.2;letter-spacing:-.01em}
.brand small{color:var(--muted);font-size:.8rem}
.lang{margin-left:auto;font-size:.8rem;color:var(--muted);text-decoration:none;border:1px solid var(--line);border-radius:999px;padding:6px 12px;white-space:nowrap}
.lang:hover{color:var(--ink);border-color:#d1d5db}
.tb-btn.wa{color:var(--wa);border-color:#c6ecd4;background:#f0fbf4}
a.tb-btn{text-decoration:none}
#cartBtn{background:var(--brand);border-color:var(--brand);color:#fff}
#cartBtn .tb-badge{background:#fff;color:var(--brand);border-color:var(--brand)}
/* Banner: the restaurant's own logo, name and place on a warm colour */
.banner{position:relative;overflow:hidden;color:#fff;background:radial-gradient(120% 90% at 100% 0%,rgba(255,255,255,.18),transparent 55%),linear-gradient(135deg,var(--brand),var(--brand-dark))}
.banner::after{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.12) 1px,transparent 1.4px);background-size:16px 16px;-webkit-mask-image:linear-gradient(90deg,transparent,#000 70%);mask-image:linear-gradient(90deg,transparent,#000 70%);pointer-events:none}
.banner .wrap{position:relative;z-index:1;padding-top:22px;padding-bottom:20px}
.bn-row{display:flex;align-items:center;gap:14px}
.bn-logo{width:60px;height:60px;border-radius:16px;background:#fff;object-fit:cover;flex:0 0 auto;box-shadow:0 6px 18px rgba(0,0,0,.18);display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:800;color:var(--brand)}
.banner h2{font-size:1.45rem;font-weight:800;letter-spacing:-.02em;margin:0;line-height:1.15}
.bn-sub{margin:4px 0 0;font-size:.88rem;opacity:.92;display:flex;flex-wrap:wrap;gap:4px 12px}
.bn-sub i{margin-right:4px}
.bn-facts{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
@media(min-width:768px){.banner .wrap{padding-top:36px;padding-bottom:32px}.bn-logo{width:84px;height:84px;border-radius:20px;font-size:2.2rem}.banner h2{font-size:2rem}.bn-sub{font-size:.95rem}}
.pill{display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;border-radius:999px;padding:6px 12px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.24);color:#fff}
.pill.ok{background:#ecfdf5;border-color:#ecfdf5;color:#047857}
.banner .notice.info{background:rgba(255,255,255,.16);color:#fff;margin-top:14px}
.sec-h{display:flex;align-items:baseline;justify-content:space-between;margin:18px 0 6px}
.sec-h h3{font-size:1.1rem;font-weight:800;letter-spacing:-.01em;margin:0}
.pop-row{display:flex;gap:12px;overflow-x:auto;padding-bottom:6px;scrollbar-width:none}
.pop-row::-webkit-scrollbar{display:none}
.pop-row .card-x{flex:0 0 150px}
@media(min-width:768px){.pop-row .card-x{flex-basis:200px}}
.pop-row .ph{aspect-ratio:4/3}
.pop-row .card-x .pr{color:var(--brand);font-weight:800;margin-top:8px}
.rank{position:absolute;z-index:2;top:10px;left:10px;background:rgba(17,24,39,.78);color:#fff;border-radius:999px;font-size:.72rem;font-weight:700;padding:3px 8px}
/* Menu rows: name, description and price on the left, the photo on the right */
.dishes{display:grid;grid-template-columns:minmax(0,1fr)}
@media(min-width:768px){.dishes{grid-template-columns:repeat(2,minmax(0,1fr));column-gap:32px}}
.dish{display:grid;grid-template-columns:minmax(0,1fr) 108px;gap:14px;padding:14px 0;border-bottom:1px solid var(--line);cursor:pointer;min-width:0}
@media(min-width:768px){.dish{grid-template-columns:minmax(0,1fr) 128px}}
.dish .dn{font-size:1rem;font-weight:700;line-height:1.3}
.dish .dd{font-size:.84rem;color:var(--muted);margin:4px 0 8px;line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.dish .dp{font-weight:800;color:var(--brand);font-variant-numeric:tabular-nums}
.dish .ph{position:relative;aspect-ratio:1/1;border-radius:14px;overflow:hidden;background:var(--soft)}
.dish .ph img{width:100%;height:100%;object-fit:cover}
.dish .add{right:8px;bottom:8px;width:34px;height:34px}
.dish.out{cursor:default}
.dish.out .ph img{filter:grayscale(.85)}
.dish.out .ph::after{content:"";position:absolute;inset:0;background:rgba(255,255,255,.55)}
.dish .so{display:inline-block;font-size:.72rem;font-weight:700;color:#991b1b;background:#fef2f2;border-radius:999px;padding:2px 8px;margin-bottom:6px}
.stepper{position:absolute;z-index:3;right:8px;bottom:8px;height:34px;display:flex;align-items:center;gap:2px;border-radius:999px;background:var(--brand);color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.2);padding:0 3px}
.stepper button{border:0;background:transparent;color:#fff;width:28px;height:28px;border-radius:999px;font-size:1rem;display:flex;align-items:center;justify-content:center}
.stepper span{min-width:18px;text-align:center;font-weight:800;font-size:.85rem}
/* Footer */
.sfoot{background:#0f172a;color:#cbd5e1;font-size:.88rem;margin-top:32px}
.sfoot .wrap{padding-top:28px;padding-bottom:24px}
.sfoot.with-bar .wrap{padding-bottom:96px}
.sf-cols{display:grid;gap:22px}
@media(min-width:768px){.sf-cols{grid-template-columns:repeat(3,minmax(0,1fr));gap:32px}}
.sfoot h4{color:#fff;font-size:.78rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin:0 0 8px}
.sfoot p{margin:0 0 4px}
.sfoot ol{margin:0;padding-left:18px}.sfoot ol li{margin-bottom:4px}
.sf-btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.sf-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08);color:#fff;border-radius:999px;padding:7px 13px;font-size:.82rem;font-weight:600;text-decoration:none}
.sf-btn:hover{color:#fff;background:rgba(255,255,255,.14)}
.sf-btn.wa{background:var(--wa);border-color:var(--wa)}
.sf-pay{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:12px}
.sf-pay small{color:#94a3b8;margin-right:2px}
.sf-pay span{background:#fff;color:#0f172a;border-radius:7px;padding:3px 9px;font-weight:800;font-size:.75rem}
.sf-base{margin-top:22px;padding-top:14px;border-top:1px solid rgba(255,255,255,.1);display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;color:#94a3b8;font-size:.78rem}
.sf-base b{color:#fff}
.tools{position:sticky;top:69px;z-index:20;background:var(--bg);padding:12px 0 10px}
.search{position:relative}
.search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9ca3af}
.search input{width:100%;border:1px solid var(--line);background:var(--soft);border-radius:999px;padding:11px 14px 11px 40px;font-size:.95rem;outline:none}
.search input:focus{background:#fff;border-color:#9ca3af;box-shadow:0 0 0 4px rgba(17,24,39,.06)}
.chips{display:flex;gap:8px;overflow-x:auto;padding:12px 0 2px;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{flex:0 0 auto;border:1px solid var(--soft);background:var(--soft);color:#374151;border-radius:12px;padding:8px 14px;font-size:.85rem;font-weight:600;cursor:pointer;white-space:nowrap}
.chip.active{background:var(--brand);border-color:var(--brand);color:#fff}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 12px;padding:8px 0 120px}
@media(min-width:640px){.grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:24px 16px}}
@media(min-width:960px){.grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
.card-x{position:relative;cursor:pointer;min-width:0}
.card-x .ph{position:relative;aspect-ratio:1/1;border-radius:var(--r);overflow:hidden;background:var(--soft)}
.card-x .ph img{width:100%;height:100%;object-fit:cover;transition:transform .35s}
.card-x:hover .ph img{transform:scale(1.04)}
.ph .ini{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:2.4rem;font-weight:800;color:rgba(17,24,39,.35)}
.badge-x{position:absolute;z-index:2;top:10px;left:10px;background:#fff;border-radius:999px;font-size:.72rem;font-weight:600;padding:4px 9px;box-shadow:0 1px 2px rgba(0,0,0,.08)}
.add{position:absolute;z-index:3;right:10px;bottom:10px;width:38px;height:38px;border-radius:999px;border:0;background:var(--brand);color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.2);display:flex;align-items:center;justify-content:center;font-size:1.25rem}
.add .n{font-size:.85rem;font-weight:700}
.card-x.has .add,.dish.has .add{background:var(--brand-dark);color:#fff}
.card-x .nm{font-size:.95rem;font-weight:600;margin:10px 0 2px;line-height:1.3;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.card-x .ct{font-size:.8rem;color:var(--muted)}
.card-x .pr{font-size:.95rem;font-weight:700;margin-top:2px}
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
.grp{margin:14px 0 4px}
.grp .gh{display:flex;align-items:baseline;gap:8px;margin-bottom:6px}
.grp .gh b{font-size:.95rem}
.grp .gh span{font-size:.75rem;color:var(--muted)}
.grp .gh .req{color:var(--warn);font-weight:600}
.opt{display:flex;align-items:center;gap:12px;padding:12px 2px;border-bottom:1px solid var(--soft);cursor:pointer;font-size:.95rem}
.opt input{width:20px;height:20px;accent-color:var(--brand);flex:0 0 auto}
.opt .p{margin-left:auto;color:var(--muted);font-size:.85rem;white-space:nowrap}
.fld{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:.95rem;outline:none;background:#fff}
.fld:focus{border-color:#9ca3af;box-shadow:0 0 0 4px rgba(17,24,39,.06)}
label.lb{font-size:.85rem;font-weight:600;margin:14px 0 6px;display:block}
.qty{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:999px}
.qty button{border:0;background:none;width:40px;height:40px;font-size:1.1rem}
.qty span{min-width:28px;text-align:center;font-weight:700}
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
/* status page */
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
.notice.good{background:#ecfdf5;color:#065f46}
.backlink{display:inline-flex;align-items:center;gap:6px;color:var(--ink);font-weight:600;text-decoration:none;margin-top:16px}
.min-w-0{min-width:0}
.tb-actions{margin-left:auto;display:flex;align-items:center;gap:8px;flex:0 0 auto}
.tb-actions .lang{margin-left:0}
.tb-btn{position:relative;width:40px;height:40px;border-radius:999px;border:1px solid var(--line);background:#fff;color:var(--ink);display:inline-flex;align-items:center;justify-content:center;font-size:1.1rem;padding:0}
.tb-btn:hover{border-color:#d1d5db}
.tb-btn.bump{animation:tbBump .35s ease}
@keyframes tbBump{0%{transform:scale(1)}40%{transform:scale(1.18)}100%{transform:scale(1)}}
.tb-badge{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:var(--brand);color:#fff;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #fff}
#myOrdersCnt{background:var(--accent)}
.lang-dd{position:relative}
.lang-btn-x{background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px 12px;font-size:.8rem;font-weight:600;color:#555;cursor:pointer;display:flex;align-items:center;gap:5px;transition:border-color .15s,color .15s;box-shadow:0 1px 3px rgba(0,0,0,.07)}
.lang-btn-x:hover{border-color:#9ca3af;color:var(--ink)}
.lang-menu-x{position:absolute;right:0;top:40px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;min-width:150px;box-shadow:0 6px 20px rgba(0,0,0,.12);overflow:hidden;z-index:9999;display:none}
.lang-menu-x a{display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:.85rem;color:#333;text-decoration:none;transition:background .15s;white-space:nowrap}
.lang-menu-x a:hover{background:#f4f8ff;color:var(--brand)}
.lang-menu-x a.active{font-weight:700;color:var(--brand)}
.lang-chk{font-size:.7rem;margin-left:auto}
.ord-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid var(--soft);text-decoration:none;color:var(--ink)}
.ord-row:last-child{border-bottom:0}
.ord-row .oc{font-weight:800;font-size:1.05rem;letter-spacing:.01em}
.ord-row .ot{font-size:.8rem;color:var(--muted)}
.ord-row .os{margin-left:auto;font-size:.75rem;font-weight:600;border-radius:999px;padding:4px 10px;background:var(--soft);color:var(--ink);white-space:nowrap}
.ord-row .os.s-accepted{background:#e7f0ff;color:#084298}
.ord-row .os.s-ready{background:#ecfdf5;color:#047857}
.ord-row .os.s-rejected,.ord-row .os.s-cancelled{background:#fef2f2;color:#991b1b}
.ord-row .bi-chevron-right{color:#9ca3af}
.notice.info{background:#f3f4f6;color:#374151;margin-top:12px}
.pay{background:var(--soft);border-radius:16px;padding:14px 16px;margin-top:14px}
.pay-row{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--line)}
.pay-row:last-of-type{border-bottom:0}
.pay-row i{font-size:1.1rem;color:var(--muted);margin-top:2px}
.pay-l{font-weight:600}
.pay-n{font-weight:800;letter-spacing:.02em;user-select:all}
.pay-row small{display:block;color:var(--muted)}
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
      <?php if ($action === 'track' && $branch !== ''): ?><small><?= $e($branch) ?></small><?php endif; ?>
    </div>
    <div class="tb-actions">
      <?php if ($ctx && $waNumber !== ''): ?>
      <a class="tb-btn wa" href="https://wa.me/<?= $e($waNumber) ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp" title="WhatsApp"><i class="bi bi-whatsapp"></i></a>
      <?php endif; ?>
      <?php if ($ctx && $phone !== ''): ?>
      <a class="tb-btn" href="tel:<?= $e($telHref) ?>" aria-label="<?= $e(t('Call the restaurant')) ?>" title="<?= $e(t('Call the restaurant')) ?>"><i class="bi bi-telephone"></i></a>
      <?php endif; ?>
      <?php if (!$notFound): ?>
      <button type="button" class="tb-btn d-none" id="myOrdersBtn" aria-label="<?= $e(t('My orders')) ?>" title="<?= $e(t('My orders')) ?>"><i class="bi bi-receipt"></i><span class="tb-badge d-none" id="myOrdersCnt">0</span></button>
      <?php endif; ?>
      <?php if ($canOrder): ?>
      <button type="button" class="tb-btn" id="cartBtn" aria-label="<?= $e(t('Your order')) ?>" title="<?= $e(t('Your order')) ?>"><i class="bi bi-cart3"></i><span class="tb-badge d-none" id="cartCnt">0</span></button>
      <?php endif; ?>
      <div class="lang-dd">
        <button class="lang-btn-x" id="omLangBtn" type="button" aria-label="<?= $e(t('Language')) ?>" aria-haspopup="true" aria-expanded="false">
          <i class="bi bi-globe2"></i>
          <?= $e(strtoupper($lang)) ?>
          <i class="bi bi-chevron-down lang-chk" style="font-size:.6rem"></i>
        </button>
        <div class="lang-menu-x" id="omLangMenu" role="menu">
          <a href="<?= $e($selfPath . '?lang=en') ?>" class="<?= $lang === 'en' ? 'active' : '' ?>" rel="nofollow" role="menuitem">
            🇬🇧 English<?php if ($lang === 'en'): ?> <i class="bi bi-check2 lang-chk"></i><?php endif; ?>
          </a>
          <a href="<?= $e($selfPath . '?lang=sw') ?>" class="<?= $lang === 'sw' ? 'active' : '' ?>" rel="nofollow" role="menuitem">
            🇹🇿 Kiswahili<?php if ($lang === 'sw'): ?> <i class="bi bi-check2 lang-chk"></i><?php endif; ?>
          </a>
        </div>
      </div>
    </div>
  </div>
</header>

<?php if (!$notFound): ?>
<!-- My orders: the orders sent from THIS phone (kept in this browser only), each with its live status. -->
<div class="scrim" id="ordScrim"></div>
<section class="sheet" id="ordSheet" role="dialog" aria-modal="true" aria-labelledby="ordTitle">
  <div class="hd"><h3 id="ordTitle"><?= $e(t('My orders')) ?></h3><button type="button" class="x" id="ordClose" aria-label="<?= $e(t('Close')) ?>"><i class="bi bi-x-lg"></i></button></div>
  <div class="bd" id="ordList"></div>
</section>
<?php endif; ?>

<?php if ($notFound): ?>
<main class="wrap">
  <div class="empty">
    <i class="bi bi-qr-code"></i>
    <h2 class="h5 fw-bold text-dark"><?= $e($action === 'track' && $ctx ? t('Order not found.') : t('This menu is not available.')) ?></h2>
    <p><?= $e(t('Please ask a member of staff for help.')) ?></p>
  </div>
</main>

<?php elseif ($action === 'track'): ?>
<?php
  $st = $order['status'];
  $stepIdx = ['pending' => 0, 'accepted' => 1, 'ready' => 2, 'completed' => 3][$st] ?? -1;
  $menuUrl = getUrl('m/' . $ctx['token']) . ($lang !== 'en' ? '?lang=' . $lang : '');
?>
<main class="wrap">
  <div class="st-card">
    <div class="hint"><?= $e(t('Your order')) ?></div>
    <div class="st-code"><?= $e($order['code']) ?></div>
    <div class="hint"><?= $order['table'] !== null ? $e(sprintf(t('Table %s'), $order['table'])) : $e(t('Pickup order')) ?></div>

    <div id="stBad" class="notice bad mt-3<?= $st === 'rejected' || $st === 'cancelled' ? '' : ' d-none' ?>">
      <b><?= $e(t('The restaurant could not take this order.')) ?></b>
      <div id="stReason"><?= $e($order['reason']) ?></div>
    </div>
    <div id="stSteps" class="steps<?= $stepIdx < 0 ? ' d-none' : '' ?>">
      <?php foreach ([['bi-send', t('Sent')], ['bi-check2', t('Accepted')], ['bi-fire', t('Ready')]] as $i => [$ic, $lb]): ?>
        <div class="s<?= $stepIdx > $i || $stepIdx === 3 ? ' done' : ($stepIdx === $i ? ' now' : '') ?>" data-step="<?= $i ?>"><i class="bi <?= $ic ?>"></i><?= $e($lb) ?></div>
      <?php endforeach; ?>
    </div>
    <p id="stText" class="hint mb-2"></p>

    <?php foreach ($order['lines'] as $ln): ?>
      <div class="line">
        <div class="t"><b><?= (int)$ln['qty'] ?> × <?= $e($ln['name']) ?></b>
          <?php if ($ln['options']): ?><small><?= $e(implode(', ', $ln['options'])) ?></small><?php endif; ?>
          <?php if ($ln['note'] !== ''): ?><small>“<?= $e($ln['note']) ?>”</small><?php endif; ?>
        </div>
        <div class="lp" data-money="<?= $e($ln['price'] * $ln['qty']) ?>"></div>
      </div>
    <?php endforeach; ?>
    <div class="sum"><span><?= $e(t('Total')) ?></span><span data-money="<?= $e($order['subtotal']) ?>"></span></div>
    <p class="hint"><?= $e(t('You pay the waiter or the cashier. The final bill may change if staff adjust your order.')) ?></p>
    <?php
      // "Show our payment numbers" (Share menu): numbers from Payment Settings, the order code as reference.
      // The system never marks an order paid by itself — staff confirm the payment at the till.
      $payAccts = [];
      if (!empty($ctx['warehouse']['show_payment']) && in_array($st, ['pending', 'accepted', 'ready'], true)) {
          require_once __DIR__ . '/core/payment_accounts.php';
          try { $payAccts = paymentAccountsForBuyer($pdo); } catch (Throwable $ex) { $payAccts = []; }
      }
    ?>
    <?php if ($payAccts): ?>
    <div class="pay">
      <b><?= $e(t('You can also pay first')) ?></b>
      <?php foreach ($payAccts as $a): ?>
        <div class="pay-row"><i class="bi <?= $a['kind'] === 'bank' ? 'bi-bank' : 'bi-phone' ?>"></i>
          <div><span class="pay-l"><?= $e($a['label']) ?></span> <span class="pay-n"><?= $e($a['number']) ?></span>
            <?php if ($a['name'] !== ''): ?><small><?= $e($a['name']) ?></small><?php endif; ?></div></div>
      <?php endforeach; ?>
      <p class="hint mb-0 mt-2"><?= $e(sprintf(t('Use your order number %s as the reference. Staff confirm your payment before your order is closed.'), $order['code'])) ?></p>
    </div>
    <?php endif; ?>
    <a class="backlink" href="<?= $e($menuUrl) ?>"><i class="bi bi-arrow-left"></i><?= $e(t('Back to the menu')) ?></a>
  </div>
</main>

<?php else: ?>
<section class="banner">
  <div class="wrap">
    <div class="bn-row">
      <?php if ($logoUrl !== ''): ?>
        <img class="bn-logo" src="<?= $e($logoUrl) ?>" alt="">
      <?php else: ?>
        <div class="bn-logo"><?= $e(mb_strtoupper(mb_substr($companyName, 0, 1))) ?></div>
      <?php endif; ?>
      <div class="min-w-0">
        <h2><?= $e($companyName) ?></h2>
        <?php if ($branch !== '' || $place !== ''): ?>
        <p class="bn-sub">
          <?php if ($branch !== ''): ?><span><i class="bi bi-shop"></i><?= $e($branch) ?></span><?php endif; ?>
          <?php if ($place !== ''): ?><span><i class="bi bi-geo-alt"></i><?= $e($place) ?></span><?php endif; ?>
        </p>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($ctx['table'] || $canOrder): ?>
    <div class="bn-facts">
      <?php if ($ctx['table']): ?>
        <span class="pill<?= $canOrder ? ' ok' : '' ?>"><i class="bi bi-geo-alt"></i><?= $e(sprintf(t('Table %s'), $ctx['table']['table_number'])) ?><?= $canOrder ? ' · ' . $e(t('Order from your table')) : '' ?></span>
      <?php else: ?>
        <span class="pill"><i class="bi bi-bag"></i><?= $e(t('Order now, pick up when ready')) ?></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!$canOrder): ?>
      <div class="notice info"><i class="bi bi-info-circle me-1"></i><?= $e(t('Online ordering is not available right now. Please order with a waiter or at the counter.')) ?></div>
    <?php endif; ?>
  </div>
</section>
<main class="wrap">
  <div class="tools">
    <div class="search"><i class="bi bi-search"></i><input id="q" type="search" autocomplete="off" maxlength="60" placeholder="<?= $e(t('Search dishes and drinks…')) ?>" aria-label="<?= $e(t('Search')) ?>"></div>
    <div class="chips" id="chips" role="tablist"></div>
  </div>

  <section id="popWrap" class="d-none" aria-labelledby="popTitle">
    <div class="sec-h"><h3 id="popTitle"><?= $e(t('Most ordered')) ?></h3></div>
    <div class="pop-row" id="popRow"></div>
  </section>
  <div id="grid" aria-live="polite"></div>
  <div id="none" class="empty d-none"><i class="bi bi-search"></i><?= $e(t('Nothing matches your search.')) ?></div>
  <?php if (!$menu['items']): ?><div class="empty"><i class="bi bi-journal-x"></i><?= $e(t('The menu is empty right now.')) ?></div><?php endif; ?>
</main>

<footer class="sfoot<?= $canOrder ? ' with-bar' : '' ?>">
  <div class="wrap">
    <div class="sf-cols">
      <div>
        <h4><?= $e(t('Find us')) ?></h4>
        <p><?= $e($wh['warehouse_name']) ?></p>
        <?php if ($addrLine !== ''): ?><p><?= $e($addrLine) ?></p><?php endif; ?>
        <?php if ($shareUrl !== ''): ?><div class="sf-btns"><button type="button" class="sf-btn" id="shareBtn"><i class="bi bi-share"></i><span><?= $e(t('Share menu')) ?></span></button></div><?php endif; ?>
      </div>
      <div>
        <h4><?= $e(t('Need help?')) ?></h4>
        <?php if ($phone !== ''): ?>
          <p><?= $e($ctx['table'] ? sprintf(t('Ask your waiter, or call %s'), $phone) : sprintf(t('Phone: %s'), $phone)) ?></p>
          <div class="sf-btns">
            <?php if ($waNumber !== ''): ?><a class="sf-btn wa" href="https://wa.me/<?= $e($waNumber) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp"></i>WhatsApp</a><?php endif; ?>
            <a class="sf-btn" href="tel:<?= $e($telHref) ?>"><i class="bi bi-telephone"></i><?= $e(t('Call')) ?></a>
          </div>
        <?php else: ?>
          <p><?= $e(t('Please ask a member of staff for help.')) ?></p>
        <?php endif; ?>
      </div>
      <div>
        <h4><?= $e(t('How it works')) ?></h4>
        <?php if ($canOrder): ?>
        <ol>
          <li><?= $e(t('Choose your food and send the order.')) ?></li>
          <li><?= $e(t('Staff confirm it and the kitchen starts cooking.')) ?></li>
          <li><?= $e($ctx['table'] ? t('Pay your waiter or at the cashier.') : t('Pay when you collect your order.')) ?></li>
        </ol>
        <?php else: ?>
        <p><?= $e(t('Order with a waiter or at the counter. Pay your waiter or at the cashier.')) ?></p>
        <?php endif; ?>
        <?php if ($payNames): ?>
        <div class="sf-pay"><small><?= $e(t('Pay with')) ?></small><?php foreach ($payNames as $pn): ?><span><?= $e($pn) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="sf-base">
      <span><?= $e(t('Online menu by')) ?> <b><?= $e($companyName) ?></b></span>
      <span>Powered by BJP Technologies © <?= date('Y') ?>, All Rights Reserved.</span>
    </div>
  </div>
</footer>

<?php if ($canOrder): ?>
<div class="bar d-none" id="bar"><button type="button" id="barBtn"><span class="cnt" id="barCnt">0</span><span><?= $e(t('View order')) ?></span><span class="tot" id="barTot"></span></button></div>
<?php endif; ?>

<div class="scrim" id="scrim"></div>
<section class="sheet" id="dishSheet" role="dialog" aria-modal="true" aria-labelledby="dsName">
  <div class="hd"><h3 id="dsName"></h3><button type="button" class="x" data-close aria-label="<?= $e(t('Close')) ?>"><i class="bi bi-x-lg"></i></button></div>
  <div class="bd" id="dsBody"></div>
  <?php if ($canOrder): ?>
  <div class="ft"><div class="row-ft">
    <div class="qty"><button type="button" id="dsMinus" aria-label="−"><i class="bi bi-dash"></i></button><span id="dsQty">1</span><button type="button" id="dsPlus" aria-label="+"><i class="bi bi-plus"></i></button></div>
    <button type="button" class="primary" id="dsAdd"></button>
  </div><div class="err d-none" id="dsErr"></div></div>
  <?php endif; ?>
</section>

<?php if ($canOrder): ?>
<section class="sheet" id="cartSheet" role="dialog" aria-modal="true" aria-labelledby="csTitle">
  <div class="hd"><h3 id="csTitle"><?= $e(t('Your order')) ?></h3><button type="button" class="x" data-close aria-label="<?= $e(t('Close')) ?>"><i class="bi bi-x-lg"></i></button></div>
  <div class="bd">
    <div id="csLines"></div>
    <div class="sum"><span><?= $e(t('Total')) ?></span><span id="csTot"></span></div>
    <?php if (!$ctx['table']): ?>
      <label class="lb" for="csName"><?= $e(t('Your name')) ?></label>
      <input class="fld" id="csName" maxlength="<?= RO_MAX_NAME ?>" autocomplete="name">
      <label class="lb" for="csPhone"><?= $e(t('Phone number')) ?></label>
      <input class="fld" id="csPhone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel">
    <?php endif; ?>
    <label class="lb" for="csNote"><?= $e(t('Note for the kitchen (optional)')) ?></label>
    <input class="fld" id="csNote" maxlength="<?= RO_MAX_NOTE ?>" placeholder="<?= $e(t('e.g. less salt')) ?>">
    <div class="hp" aria-hidden="true"><label>Website <input id="csWebsite" tabindex="-1" autocomplete="off"></label></div>
    <p class="hint mt-3 mb-0"><?= $e($ctx['table'] ? t('Staff will confirm your order, then bring it to your table. You pay the waiter.') : t('Staff will confirm your order. Pay when you pick it up.')) ?></p>
    <div class="err d-none" id="csErr"></div>
  </div>
  <div class="ft"><button type="button" class="primary w-100" id="csSend"><?= $e(t('Send order')) ?></button></div>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if (!$notFound): ?>
<script nonce="<?= $e($nonce) ?>">
// My orders — remembered in THIS browser only (no account, nothing sent anywhere): code, time, status page.
(function () {
  'use strict';
  var KEY = 'bms_my_orders', DAY = 864e5;
  var L = <?= json_encode([
      'none' => t('No orders yet from this phone.'),
      'st'   => ['pending' => t('Waiting'), 'accepted' => t('Accepted'), 'ready' => t('Ready'), 'completed' => t('Done'), 'rejected' => t('Rejected'), 'cancelled' => t('Cancelled')],
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var OK_URL = /^\/(?:[A-Za-z0-9_.-]+\/)*m\/[a-f0-9]{32}\/o\/[a-f0-9]{32}(?:\?lang=(?:en|sw))?$/;
  function load() {
    var a = [];
    try { a = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { a = []; }
    if (!Array.isArray(a)) a = [];
    var now = Date.now();
    // Only this site's status pages, only the last 24 hours, at most 10.
    return a.filter(function (o) { return o && typeof o.url === 'string' && OK_URL.test(o.url) && now - (o.at || 0) < DAY; }).slice(0, 10);
  }
  function store(a) { try { localStorage.setItem(KEY, JSON.stringify(a.slice(0, 10))); } catch (e) {} }
  window.roRememberOrder = function (url, code) {
    if (typeof url !== 'string' || !OK_URL.test(url)) return;
    var path = url.split('?')[0], a = load().filter(function (o) { return o.url.split('?')[0] !== path; });
    a.unshift({ url: url, code: String(code || '').slice(0, 12), at: Date.now() });
    store(a); paint();
  };
  var btn = document.getElementById('myOrdersBtn'), cnt = document.getElementById('myOrdersCnt');
  var sheet = document.getElementById('ordSheet'), scrim = document.getElementById('ordScrim'), list = document.getElementById('ordList');
  function paint() {
    var a = load();
    if (!btn) return;
    btn.classList.toggle('d-none', a.length === 0);
    cnt.textContent = String(a.length); cnt.classList.toggle('d-none', a.length === 0);
  }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function open() {
    var a = load(); list.textContent = '';
    if (!a.length) list.appendChild(el('p', 'hint py-3', L.none));
    a.forEach(function (o) {
      var row = el('a', 'ord-row'); row.href = o.url;
      var t = el('div', ''); t.appendChild(el('div', 'oc', o.code || '—'));
      t.appendChild(el('div', 'ot', new Date(o.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })));
      var st = el('span', 'os', '…');
      row.appendChild(t); row.appendChild(st); row.appendChild(el('i', 'bi bi-chevron-right'));
      list.appendChild(row);
      fetch(o.url.split('?')[0] + '/status', { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.success) { st.textContent = L.st[d.status] || d.status; st.className = 'os s-' + String(d.status).replace(/[^a-z]/g, ''); }
        else st.textContent = '—';
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
  // Opening a status page (even from a shared link) remembers it, so it can be found again.
  window.roRememberOrder(<?= json_encode(roStatusUrl($ctx['token'], (string)$route['ref']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($order['code'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
<?php endif; ?>
  paint();
})();
</script>
<?php endif; ?>
<script nonce="<?= $e($nonce) ?>">
(function () {
  'use strict';
  var CUR = <?= json_encode(bmsCurrencySymbol(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  function money(v) {
    v = Number(v) || 0;
    var whole = Math.abs(v - Math.round(v)) < 0.005;
    return CUR + v.toLocaleString('en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 });
  }
  document.querySelectorAll('[data-money]').forEach(function (el) { el.textContent = money(el.getAttribute('data-money')); });

<?php if (!$notFound && $action === 'track'): ?>
  var TXT = <?= json_encode([
      'pending'   => t('Waiting for staff to confirm your order…'),
      'accepted'  => t('Your order is confirmed and is being prepared.'),
      'ready'     => $order['table'] !== null ? t('Your order is ready and on its way to your table.') : t('Your order is ready. Please collect it.'),
      'completed' => t('Enjoy your meal!'),
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var STATUS_URL = <?= json_encode(roStatusUrl($ctx['token'], $route['ref']) . '/status', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var IDX = { pending: 0, accepted: 1, ready: 2, completed: 3 };
  function paint(st, reason) {
    var bad = (st === 'rejected' || st === 'cancelled');
    document.getElementById('stBad').classList.toggle('d-none', !bad);
    document.getElementById('stSteps').classList.toggle('d-none', bad);
    if (bad) { document.getElementById('stReason').textContent = reason || ''; document.getElementById('stText').textContent = ''; return true; }
    var i = IDX[st]; if (i === undefined) return true;
    document.querySelectorAll('#stSteps .s').forEach(function (el) {
      var k = Number(el.getAttribute('data-step'));
      el.classList.toggle('done', k < i || i === 3);
      el.classList.toggle('now', k === i && i !== 3);
    });
    document.getElementById('stText').textContent = TXT[st] || '';
    return st === 'completed';
  }
  var done = paint(<?= json_encode($order['status']) ?>, <?= json_encode($order['reason'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);
  var fails = 0;
  function poll() {
    if (done || document.hidden) { if (!done) setTimeout(poll, 8000); return; }
    fetch(STATUS_URL, { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.success) { fails = 0; done = paint(d.status, d.reason); }
    }).catch(function () { fails++; }).then(function () { if (!done && fails < 20) setTimeout(poll, 8000); });
  }
  setTimeout(poll, 8000);
<?php elseif (!$notFound): ?>
  var ITEMS = <?= json_encode($menu['items'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var CATS  = <?= json_encode((object)$menu['categories'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var MODS  = <?= json_encode((object)array_map('array_values', array_map(fn($gs) => array_map(fn($g) => array_merge($g, ['options' => array_values($g['options'])]), $gs), roModifiersFor($pdo, array_column(array_filter($menu['items'], fn($i) => $i['has_options']), 'id')))), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var CAN_ORDER = <?= $canOrder ? 'true' : 'false' ?>;
  var POPULAR = <?= json_encode(array_values($popular)) ?>;
  var SHARE_URL = <?= json_encode($shareUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var IS_TABLE  = <?= $ctx['table'] ? 'true' : 'false' ?>;
  var ORDER_URL = <?= json_encode(getUrl('m/' . $ctx['token'] . '/order') . ($lang !== 'en' ? '?lang=' . $lang : ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var FORM_TOKEN = <?= json_encode($canOrder ? roFormToken($pdo, $ctx['token']) : '') ?>;
  var STORE_KEY = 'bms_menu_' + <?= json_encode(substr(hash('sha256', $ctx['token']), 0, 16)) ?>;
  var T = <?= json_encode([
      'all' => t('All'), 'soldOut' => t('Sold out'), 'add' => t('Add'), 'addFor' => t('Add · %s'),
      'choose' => t('Choose %d'), 'upTo' => t('Up to %d'), 'required' => t('Required'), 'optional' => t('Optional'),
      'pickRequired' => t('Please choose %s.'), 'tooMany' => t('You can choose up to %d for %s.'),
      'remove' => t('Remove'), 'sending' => t('Sending…'), 'send' => t('Send order'), 'offline' => t('No connection. Please check your internet and try again.'),
      'empty' => t('Your order is empty.'), 'name' => t('Please enter your name.'), 'phone' => t('Please enter a valid phone number.'),
      'other' => t('More'), 'copied' => t('Link copied'),
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

  var byId = {}; ITEMS.forEach(function (it) { byId[it.id] = it; });
  var state = { cat: 0, q: '' };
  var cart = [];
  try { var saved = JSON.parse(localStorage.getItem(STORE_KEY) || '[]'); if (Array.isArray(saved)) cart = saved.filter(function (l) { return byId[l.id] && !byId[l.id].sold_out; }); } catch (e) { cart = []; }
  function save() { try { localStorage.setItem(STORE_KEY, JSON.stringify(cart)); } catch (e) {} }

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function fmt(s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return String(s).replace(/%(\d\$)?[sd]/g, function () { return String(a[i++]); }); }
  function optMap(id) { var m = {}; (MODS[id] || []).forEach(function (g) { g.options.forEach(function (o) { m[o.id] = { g: g, o: o }; }); }); return m; }
  function linePrice(l) { var m = optMap(l.id), p = byId[l.id] ? byId[l.id].price : 0; (l.options || []).forEach(function (oid) { if (m[oid]) p += m[oid].o.price; }); return Math.max(0, p); }
  function qtyOf(id) { return cart.reduce(function (s, l) { return s + (l.id === id ? l.qty : 0); }, 0); }

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
  // Photo box with its label and its add / − n + control — shared by menu rows and the Most ordered cards.
  function photoBox(it, rank) {
    var ph = el('div', 'ph');
    if (it.image) { var img = el('img'); img.src = it.image; img.alt = ''; img.loading = 'lazy'; img.decoding = 'async'; img.addEventListener('error', function () { img.remove(); ph.appendChild(el('div', 'ini', it.name.charAt(0).toUpperCase())); }); ph.appendChild(img); }
    else { ph.appendChild(el('div', 'ini', it.name.charAt(0).toUpperCase())); }
    if (rank) ph.appendChild(el('span', 'rank', '#' + rank));
    if (CAN_ORDER && !it.sold_out) {
      var n = qtyOf(it.id);
      if (n && !it.has_options) {
        var sp = el('div', 'stepper');
        var mi = el('button'); mi.type = 'button'; mi.setAttribute('aria-label', T.remove + ' ' + it.name); mi.appendChild(el('i', 'bi ' + (n <= 1 ? 'bi-trash3' : 'bi-dash')));
        var pl = el('button'); pl.type = 'button'; pl.setAttribute('aria-label', T.add + ' ' + it.name); pl.appendChild(el('i', 'bi bi-plus'));
        mi.addEventListener('click', function (ev) { ev.stopPropagation(); lessOf(it.id); });
        pl.addEventListener('click', function (ev) { ev.stopPropagation(); addLine(it.id, 1, [], ''); });
        sp.appendChild(mi); sp.appendChild(el('span', '', String(n))); sp.appendChild(pl);
        sp.addEventListener('click', function (ev) { ev.stopPropagation(); });
        ph.appendChild(sp);
      } else {
        var a = el('button', 'add'); a.type = 'button'; a.setAttribute('aria-label', T.add + ' ' + it.name);
        if (n) a.appendChild(el('span', 'n', String(n))); else a.appendChild(el('i', 'bi bi-plus-lg'));
        a.addEventListener('click', function (ev) { ev.stopPropagation(); if (it.has_options) openDish(it); else addLine(it.id, 1, [], ''); });
        ph.appendChild(a);
      }
    }
    return ph;
  }
  function dishRow(it) {
    var r = el('div', 'dish' + (it.sold_out ? ' out' : '') + (qtyOf(it.id) ? ' has' : ''));
    var t = el('div', 'min-w-0');
    t.appendChild(el('div', 'dn', it.name));
    if (it.desc) t.appendChild(el('div', 'dd', it.desc)); else t.appendChild(el('div', 'dd', ''));
    if (it.sold_out) t.appendChild(el('span', 'so', T.soldOut));
    t.appendChild(el('div', 'dp', money(it.price)));
    r.appendChild(t); r.appendChild(photoBox(it, 0));
    if (!it.sold_out) r.addEventListener('click', function () { openDish(it); });
    return r;
  }
  var popWrap = document.getElementById('popWrap'), popRow = document.getElementById('popRow');
  function renderPopular() {
    if (!popWrap) return;
    var list = POPULAR.map(function (id) { return byId[id]; }).filter(function (it) { return it && !it.sold_out; });
    var on = list.length >= 3 && !state.cat && !state.q;
    popWrap.classList.toggle('d-none', !on);
    popRow.textContent = '';
    if (!on) return;
    list.forEach(function (it, i) {
      var c = el('div', 'card-x' + (qtyOf(it.id) ? ' has' : ''));
      c.appendChild(photoBox(it, i + 1));
      c.appendChild(el('div', 'pr', money(it.price)));
      c.appendChild(el('div', 'nm', it.name));
      c.addEventListener('click', function () { openDish(it); });
      popRow.appendChild(c);
    });
  }
  // The menu in sections, one per category (in the restaurant's own category order).
  function renderGrid() {
    grid.textContent = '';
    var q = state.q.toLowerCase(), shown = 0, groups = [], byCat = {};
    ITEMS.forEach(function (it) {
      if (state.cat && it.category_id !== state.cat) return;
      if (q && it.name.toLowerCase().indexOf(q) < 0 && String(CATS[it.category_id] || '').toLowerCase().indexOf(q) < 0 && String(it.desc || '').toLowerCase().indexOf(q) < 0) return;
      shown++;
      var k = String(it.category_id || 0);
      if (!byCat[k]) { byCat[k] = []; groups.push(k); }
      byCat[k].push(it);
    });
    groups.forEach(function (k) {
      var h = el('div', 'sec-h'); h.appendChild(el('h3', '', CATS[k] || T.other)); grid.appendChild(h);
      var box = el('div', 'dishes');
      byCat[k].forEach(function (it) { box.appendChild(dishRow(it)); });
      grid.appendChild(box);
    });
    document.getElementById('none').classList.toggle('d-none', shown > 0 || !ITEMS.length);
    renderPopular();
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

  var dish = { it: null, qty: 1, sel: {} };
  function openDish(it) {
    dish = { it: it, qty: 1, sel: {} };
    document.getElementById('dsName').textContent = it.name;
    var body = document.getElementById('dsBody'); body.textContent = '';
    if (it.image) { var img = el('img', 'big'); img.alt = ''; img.addEventListener('error', function () { img.remove(); }); img.src = it.image; body.appendChild(img); }
    var top = el('div', 'd-flex justify-content-between align-items-baseline');
    top.appendChild(el('div', 'hint', CATS[it.category_id] || ''));
    top.appendChild(el('div', 'fw-bold fs-5', money(it.price)));
    body.appendChild(top);
    if (it.desc) body.appendChild(el('p', 'mt-2 mb-0', it.desc));
    (MODS[it.id] || []).forEach(function (g) {
      var box = el('div', 'grp'), gh = el('div', 'gh');
      gh.appendChild(el('b', '', g.name));
      gh.appendChild(el('span', g.min > 0 ? 'req' : '', g.min > 0 ? T.required : T.optional));
      if (!g.single && g.max < 99) gh.appendChild(el('span', '', fmt(T.upTo, g.max)));
      box.appendChild(gh);
      g.options.forEach(function (o) {
        var lab = el('label', 'opt'), inp = el('input');
        inp.type = g.single ? 'radio' : 'checkbox'; inp.name = 'g' + g.id; inp.value = String(o.id);
        if (!CAN_ORDER) inp.disabled = true;
        inp.addEventListener('change', function () {
          if (g.single) g.options.forEach(function (x) { delete dish.sel[x.id]; });
          if (inp.checked) dish.sel[o.id] = true; else delete dish.sel[o.id];
          if (!g.single) { var n = g.options.filter(function (x) { return dish.sel[x.id]; }).length; if (n > g.max) { inp.checked = false; delete dish.sel[o.id]; dsErr(fmt(T.tooMany, g.max, g.name)); return; } }
          dsErr(''); paintAdd();
        });
        lab.appendChild(inp); lab.appendChild(el('span', '', o.name));
        if (o.price) lab.appendChild(el('span', 'p', (o.price > 0 ? '+' : '−') + money(Math.abs(o.price))));
        box.appendChild(lab);
      });
      body.appendChild(box);
    });
    if (CAN_ORDER) {
      body.appendChild(el('label', 'lb', <?= json_encode(t('Special request (optional)'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>));
      var note = el('input', 'fld'); note.id = 'dsNote'; note.maxLength = 120; body.appendChild(note);
      document.getElementById('dsQty').textContent = '1'; dsErr(''); paintAdd();
    }
    show(document.getElementById('dishSheet'));
  }
  function dsErr(m) { var e = document.getElementById('dsErr'); if (!e) return; e.textContent = m; e.classList.toggle('d-none', !m); }
  function paintAdd() {
    var b = document.getElementById('dsAdd'); if (!b || !dish.it) return;
    var l = { id: dish.it.id, options: Object.keys(dish.sel).map(Number) };
    b.textContent = fmt(T.addFor, money(linePrice(l) * dish.qty));
  }
  if (CAN_ORDER) {
    document.getElementById('dsMinus').addEventListener('click', function () { if (dish.qty > 1) dish.qty--; document.getElementById('dsQty').textContent = dish.qty; paintAdd(); });
    document.getElementById('dsPlus').addEventListener('click', function () { if (dish.qty < 20) dish.qty++; document.getElementById('dsQty').textContent = dish.qty; paintAdd(); });
    document.getElementById('dsAdd').addEventListener('click', function () {
      var it = dish.it, opts = Object.keys(dish.sel).map(Number);
      var bad = (MODS[it.id] || []).filter(function (g) { return g.options.filter(function (o) { return dish.sel[o.id]; }).length < g.min; })[0];
      if (bad) { dsErr(fmt(T.pickRequired, bad.name)); return; }
      var note = (document.getElementById('dsNote') || { value: '' }).value.trim().slice(0, 120);
      addLine(it.id, dish.qty, opts, note); hide();
    });
  }

  function addLine(id, qty, opts, note) {
    opts = (opts || []).slice().sort(function (a, b) { return a - b; });
    var same = cart.filter(function (l) { return l.id === id && (l.note || '') === note && JSON.stringify(l.options || []) === JSON.stringify(opts); })[0];
    if (same) same.qty = Math.min(20, same.qty + qty); else cart.push({ id: id, qty: qty, options: opts, note: note });
    if (cart.length > 30) cart.pop();
    save(); renderGrid(); paintBar();
  }
  // The row's − button (dishes without options only): one less of the plain line.
  function lessOf(id) {
    for (var i = cart.length - 1; i >= 0; i--) {
      var l = cart[i];
      if (l.id !== id || (l.options && l.options.length) || l.note) continue;
      if (l.qty > 1) l.qty--; else cart.splice(i, 1);
      break;
    }
    save(); renderGrid(); paintBar();
  }

  // ── Basket ──
  function paintBar() {
    var bar = document.getElementById('bar'); if (!bar) return;
    var n = cart.reduce(function (s, l) { return s + l.qty; }, 0);
    var tot = cart.reduce(function (s, l) { return s + linePrice(l) * l.qty; }, 0);
    bar.classList.toggle('d-none', n === 0);
    document.getElementById('barCnt').textContent = String(n);
    document.getElementById('barTot').textContent = money(tot);
    var cs = document.getElementById('csTot'); if (cs) cs.textContent = money(tot);
    // Bag icon in the top bar: always visible, so the basket is one tap away from anywhere on the page.
    var cc = document.getElementById('cartCnt'), cb = document.getElementById('cartBtn');
    if (cc && cb) {
      var before = Number(cc.textContent) || 0;
      cc.textContent = String(n); cc.classList.toggle('d-none', n === 0);
      if (n > before) { cb.classList.remove('bump'); void cb.offsetWidth; cb.classList.add('bump'); }
    }
  }
  function renderCart() {
    var box = document.getElementById('csLines'); box.textContent = '';
    if (!cart.length) { box.appendChild(el('p', 'hint py-3', T.empty)); }
    cart.forEach(function (l, idx) {
      var it = byId[l.id]; if (!it) return;
      var m = optMap(l.id), row = el('div', 'line'), t = el('div', 't');
      t.appendChild(el('b', '', it.name));
      var on = (l.options || []).map(function (o) { return m[o] ? m[o].o.name : ''; }).filter(Boolean);
      if (on.length) t.appendChild(el('small', '', on.join(', ')));
      if (l.note) t.appendChild(el('small', '', '“' + l.note + '”'));
      var q = el('div', 'qty mt-2');
      var minus = el('button'); minus.type = 'button'; minus.setAttribute('aria-label', l.qty === 1 ? T.remove : '−'); minus.appendChild(el('i', 'bi ' + (l.qty === 1 ? 'bi-trash3' : 'bi-dash')));
      var plus = el('button'); plus.type = 'button'; plus.setAttribute('aria-label', '+'); plus.appendChild(el('i', 'bi bi-plus'));
      minus.addEventListener('click', function () { if (l.qty > 1) l.qty--; else cart.splice(idx, 1); save(); renderCart(); renderGrid(); paintBar(); if (!cart.length) hide(); });
      plus.addEventListener('click', function () { if (l.qty < 20) l.qty++; save(); renderCart(); renderGrid(); paintBar(); });
      q.appendChild(minus); q.appendChild(el('span', '', String(l.qty))); q.appendChild(plus);
      t.appendChild(q);
      row.appendChild(t); row.appendChild(el('div', 'lp', money(linePrice(l) * l.qty)));
      box.appendChild(row);
    });
    paintBar();
  }
  function csErr(m) { var e = document.getElementById('csErr'); e.textContent = m; e.classList.toggle('d-none', !m); }
  if (CAN_ORDER) {
    function openCart() { csErr(''); renderCart(); show(document.getElementById('cartSheet')); }
    document.getElementById('barBtn').addEventListener('click', openCart);
    document.getElementById('cartBtn').addEventListener('click', openCart);
    var sending = false;
    document.getElementById('csSend').addEventListener('click', function () {
      if (sending) return;
      if (!cart.length) { csErr(T.empty); return; }
      var name = '', phone = '';
      if (!IS_TABLE) {
        name = document.getElementById('csName').value.trim(); phone = document.getElementById('csPhone').value.trim();
        if (name.length < 2) { csErr(T.name); return; }
        var d = phone.replace(/\D/g, ''); if (d.length < 7 || d.length > 15) { csErr(T.phone); return; }
      }
      sending = true; csErr('');
      var btn = document.getElementById('csSend'); btn.disabled = true; btn.textContent = T.sending;
      fetch(ORDER_URL, {
        method: 'POST', credentials: 'omit', cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          form_token: FORM_TOKEN, website: document.getElementById('csWebsite').value,
          name: name, phone: phone, note: document.getElementById('csNote').value.trim().slice(0, 200),
          lines: cart.map(function (l) { return { id: l.id, qty: l.qty, options: l.options || [], note: l.note || '' }; })
        })
      }).then(function (r) { return r.json().catch(function () { return { success: false, message: T.offline }; }); })
        .then(function (d) {
          if (d && d.success && typeof d.status_url === 'string' && d.status_url.charAt(0) === '/') {
            cart = []; save();
            if (window.roRememberOrder) window.roRememberOrder(d.status_url, d.order_code);
            window.location.assign(d.status_url); return;   // assign, not replace: Back returns to the menu
          }
          if (d && d.refresh) { setTimeout(function () { window.location.reload(); }, 1500); }
          csErr((d && d.message) || T.offline);
        })
        .catch(function () { csErr(T.offline); })
        .then(function () { sending = false; btn.disabled = false; btn.textContent = T.send; });
    });
  }

  // Share menu: the phone's share sheet when there is one, otherwise copy the link.
  var shareBtn = document.getElementById('shareBtn');
  if (shareBtn && SHARE_URL) shareBtn.addEventListener('click', function () {
    var url = new URL(SHARE_URL, window.location.href).href, label = shareBtn.querySelector('span');
    if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function () {}); return; }
    var done = function () { var old = label.textContent; label.textContent = T.copied; setTimeout(function () { label.textContent = old; }, 2000); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done).catch(function () {});
  });

  renderChips(); renderGrid(); paintBar();
<?php endif; ?>
})();
// Language dropdown toggle
(function(){
  var btn=document.getElementById('omLangBtn'),menu=document.getElementById('omLangMenu');
  if(!btn||!menu)return;
  btn.addEventListener('click',function(e){
    e.stopPropagation();
    var open=menu.style.display==='block';
    menu.style.display=open?'none':'block';
    btn.setAttribute('aria-expanded',open?'false':'true');
  });
  document.addEventListener('click',function(){menu.style.display='none';btn.setAttribute('aria-expanded','false');});
})();
</script>
</body>
</html>
