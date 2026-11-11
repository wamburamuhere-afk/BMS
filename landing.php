<?php
/**
 * landing.php — the public website on the platform's own address (Option B "Showcase", 2026-11-11;
 * redesign the same day: Products menu, separate Retail / Restaurant pages, one language at a time).
 *
 * Pages: /  /smart-pos  /smart-pos/retail  /smart-pos/restaurant  /smart-bms  /pricing  /faq  /contact
 * (English; ?lang=sw Kiswahili). Only on the platform root (bms.… / demo.…): a company's own address
 * and the Superadmin address get a 404 here, and index.php keeps sending a company's "/" to its login.
 *
 * Nothing is typed in by hand that the system already holds:
 *   products, plans, prices, terms, limits, plan items ......... listPublicOffers() (Superadmin › Plans)
 *   free-trial length, whether sign-up is open ................ tenantDefaultTrialDays(), selfRegistrationClosedReason()
 *   phone, WhatsApp, email, hours ............................ Superadmin › Support settings
 *   legal name, address, main website ........................ Superadmin › Platform settings › Branding
 *   product wording .......................................... core/product_copy.php (shared with register.php)
 * The login page is unchanged; this page only links to it. Sign-up links carry ?product= (and
 * ?business=restaurant from the Restaurant page, ?plan= from Pricing — register.php then opens at step 3).
 */
require_once __DIR__ . '/core/tenant_registration.php';   // listPublicOffers, tenantDefaultTrialDays, selfRegistrationClosedReason, tenantBaseDomain
require_once __DIR__ . '/core/product_copy.php';
require_once __DIR__ . '/core/public_site.php';
require_once __DIR__ . '/core/landing_content.php';

if (!publicSiteIsPlatformRoot()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

$path = trim((string)strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'), '/');
$pages = publicSitePages();
if (!array_key_exists($path, $pages)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

// Language: ?lang= → cookie → English (same rule as login.php / register.php).
$lang = 'en';
if (in_array($_GET['lang'] ?? '', ['en', 'sw'], true)) {
    $lang = $_GET['lang'];
    setcookie('bms_lang', $lang, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
} elseif (in_array($_COOKIE['bms_lang'] ?? '', ['en', 'sw'], true)) {
    $lang = $_COOKIE['bms_lang'];
}
$sw = $lang === 'sw';

$site    = publicSiteInfo();
$data    = landingData($sw);
$T       = landingCopy($sw, $data['trial_days']);
$h       = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$url     = fn(string $p, ?string $l = null) => '/' . $p . (($l ?? $lang) === 'sw' ? '?lang=sw' : '');
$abs     = fn(string $p, string $l) => $site['base'] . '/' . $p . ($l === 'sw' ? '?lang=sw' : '');
$tel     = fn(string $n) => preg_replace('/[^0-9+]/', '', $n);
$canTrial = $data['signup_open'];
// Sign-up link: /register?product=…&business=…&lang=sw (only what is set).
$reg = fn(array $q = []) => '/register' . (($q = array_filter($q + ($sw ? ['lang' => 'sw'] : []), fn($v) => $v !== '' && $v !== null)) ? '?' . http_build_query($q) : '');
$posOffer = $data['products_by_key']['bms_shop'] ?? null;
$bmsOffer = $data['products_by_key']['bms_full'] ?? null;
$fmtN = fn($n) => number_format((float)$n);

// Line icons (24×24, stroke = currentColor).
$ICONS = [
    'bolt' => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
    'box' => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    'scale' => '<path d="M12 3v18"/><path d="M5 7h14"/><path d="M5 7l-3 7a4 4 0 0 0 6 0L5 7z"/><path d="M19 7l-3 7a4 4 0 0 0 6 0l-3-7z"/><path d="M8 21h8"/>',
    'credit' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/>',
    'chart' => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
    'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.5 12h12L22 7H6"/>',
    'table' => '<path d="M3 10h18"/><path d="M5 10v9"/><path d="M19 10v9"/><path d="M8 6h8"/><path d="M12 6v4"/>',
    'screen' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
    'book' => '<path d="M4 4h10a4 4 0 0 1 4 4v12H8a4 4 0 0 1-4-4V4z"/><path d="M8 8h6"/><path d="M8 12h6"/>',
    'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3z"/><path d="M20 14v7h-6"/>',
    'truck' => '<path d="M1 6h13v10H1z"/><path d="M14 9h4l4 4v3h-8z"/><circle cx="5.5" cy="18.5" r="1.8"/><circle cx="17.5" cy="18.5" r="1.8"/>',
    'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h10l1-14"/>',
    'ledger' => '<path d="M4 3h16v18H4z"/><path d="M4 9h16"/><path d="M10 9v12"/>',
    'invoice' => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2z"/><path d="M9 7h6"/><path d="M9 11h6"/><path d="M9 15h4"/>',
    'bank' => '<path d="M3 10l9-6 9 6"/><path d="M5 10v8"/><path d="M10 10v8"/><path d="M14 10v8"/><path d="M19 10v8"/><path d="M3 21h18"/>',
    'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c.8-3.5 3.5-5.5 7-5.5s6.2 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14.8c2 .7 3.4 2.5 4 5.2"/>',
    'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'store' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/>',
    'fork' => '<path d="M7 3v8"/><path d="M5 3v4a2 2 0 0 0 4 0V3"/><path d="M7 11v10"/><path d="M17 3c-2 2-2 6 0 8v10"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
    'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    'db' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    'check' => '<path d="M20 6L9 17l-5-5"/>',
    'flame' => '<path d="M12 2c1 4 6 6 6 12a6 6 0 0 1-12 0c0-3 2-5 3-7 1 2 2 3 3 3 0-3-1-5 0-8z"/>',
    'plate' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/>',
    'chev' => '<path d="m6 9 6 6 6-6"/>',
    'globe' => '<circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5c2.6 2.8 3.9 6 3.9 9.5s-1.3 6.7-3.9 9.5c-2.6-2.8-3.9-6-3.9-9.5s1.3-6.7 3.9-9.5z"/>',
    'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'arrow' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
];
$icon = fn(string $n, int $s = 20, string $cls = '') => '<svg class="i ' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($ICONS[$n] ?? '') . '</svg>';
$brandMark = '<span class="brand-mark"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19V9"/><path d="M10 19V5"/><path d="M16 19v-7"/><path d="M22 19H2"/></svg></span>';

// ── SEO for this page ────────────────────────────────────────────────────────
$meta = landingMeta($path, $sw, $data, $site);
header('Content-Type: text/html; charset=utf-8');
if (!$site['indexable']) header('X-Robots-Tag: noindex, follow');
$jsonLd = landingJsonLd($path, $sw, $data, $site, $abs);
$inPos = in_array($path, ['smart-pos', 'smart-pos/retail', 'smart-pos/restaurant', 'smart-bms'], true);
?><!DOCTYPE html>
<html lang="<?= $sw ? 'sw' : 'en' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($meta['title']) ?></title>
<meta name="description" content="<?= $h($meta['description']) ?>">
<meta name="robots" content="<?= $site['indexable'] ? 'index, follow' : 'noindex, follow' ?>">
<link rel="canonical" href="<?= $h($abs($path, $lang)) ?>">
<link rel="alternate" hreflang="en" href="<?= $h($abs($path, 'en')) ?>">
<link rel="alternate" hreflang="sw" href="<?= $h($abs($path, 'sw')) ?>">
<link rel="alternate" hreflang="x-default" href="<?= $h($abs($path, 'en')) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Smart BMS">
<meta property="og:title" content="<?= $h($meta['title']) ?>">
<meta property="og:description" content="<?= $h($meta['description']) ?>">
<meta property="og:url" content="<?= $h($abs($path, $lang)) ?>">
<meta property="og:locale" content="<?= $sw ? 'sw_TZ' : 'en_TZ' ?>">
<meta name="twitter:card" content="summary">
<meta name="theme-color" content="#ffffff">
<link rel="icon" type="image/png" href="/assets/images/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php foreach ($jsonLd as $ld): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach; ?>
<style>
:root{--ink:#0f172a;--muted:#475569;--line:#e2e8f0;--soft:#f8fafc;--blue:#1d4ed8;--blue-d:#1e3a8a;--sky:#60a5fa;--tint:#eff6ff;--navy:#0b1220;--ok:#15803d;--warm:#c2410c;--warm-soft:#fff7ed;--indigo:#3730a3}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;background:#fff;color:var(--ink);font-family:'Plus Jakarta Sans',system-ui,-apple-system,'Segoe UI',sans-serif;line-height:1.55;-webkit-font-smoothing:antialiased}
a{color:var(--blue)}a:hover{color:var(--blue-d)}
img{max-width:100%}
:focus-visible{outline:3px solid var(--sky);outline-offset:2px}
.i{flex:0 0 auto;display:block}
.wrap{max-width:1180px;margin:0 auto;padding:0 24px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 22px;border-radius:999px;font:inherit;font-weight:800;font-size:15px;text-decoration:none;cursor:pointer;border:0;transition:transform .15s,background .15s,box-shadow .15s,border-color .15s,color .15s}
.btn-blue{background:var(--blue);color:#fff;box-shadow:0 10px 24px rgba(29,78,216,.28)}.btn-blue:hover{background:var(--blue-d);color:#fff;transform:translateY(-1px)}
.btn-warm{background:var(--warm);color:#fff;box-shadow:0 10px 24px rgba(194,65,12,.25)}.btn-warm:hover{background:#9a3412;color:#fff;transform:translateY(-1px)}
.btn-dark{background:var(--ink);color:#fff}.btn-dark:hover{background:#1e293b;color:#fff}
.btn-line{background:#fff;color:var(--ink);border:1px solid #cbd5e1}.btn-line:hover{border-color:var(--blue);color:var(--blue)}
.kick{margin:0;font-size:12.5px;font-weight:800;letter-spacing:1.5px;color:var(--blue);text-transform:uppercase}
h1,h2,h3{letter-spacing:-.5px;margin:0;text-wrap:balance}
.lead{font-size:18px;color:var(--muted);margin:12px 0 0;max-width:62ch}
.sec{padding:76px 0}.sec-soft{background:var(--soft);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
h2.big{font-size:clamp(26px,3.6vw,40px);font-weight:800;letter-spacing:-1px}
.grid{display:flex;flex-wrap:wrap;gap:16px}
.card{border:1px solid var(--line);border-radius:20px;background:#fff;padding:26px}
.ex{font-size:11px;font-weight:700;color:var(--muted);background:#f1f5f9;padding:3px 9px;border-radius:999px}

/* ── header ── */
.hdr{position:sticky;top:0;z-index:40;background:rgba(255,255,255,.96);-webkit-backdrop-filter:saturate(1.4) blur(10px);backdrop-filter:saturate(1.4) blur(10px);border-bottom:1px solid var(--line)}
.hdr-in{position:relative;display:flex;align-items:center;gap:18px;padding:12px 24px;max-width:1180px;margin:0 auto}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--ink);font-weight:800;font-size:19px;flex:0 0 auto}
.brand:hover{color:var(--ink)}
.brand-mark{width:34px;height:34px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.nav{display:flex;gap:2px;align-items:center}
.nav > a,.nav > button{min-height:42px;padding:0 15px;border-radius:999px;display:inline-flex;align-items:center;gap:6px;font:inherit;font-size:15px;font-weight:700;color:#334155;text-decoration:none;background:none;border:0;cursor:pointer;white-space:nowrap}
.nav > a:hover,.nav > button:hover,.nav > button[aria-expanded=true]{background:#f1f5f9;color:var(--blue)}
.nav > a[aria-current=page],.nav > button.cur{color:var(--blue)}
.caret{transition:transform .2s}
[aria-expanded=true] > .caret{transform:rotate(180deg)}
.hdr-act{display:flex;gap:10px;align-items:center;margin-left:auto}
.linkbtn{background:none;border:0;font:inherit;font-size:15px;font-weight:700;color:var(--ink);cursor:pointer;min-height:44px;padding:0 10px}
.linkbtn:hover{color:var(--blue)}
.burger{display:none;width:46px;height:46px;border-radius:14px;border:1px solid #cbd5e1;background:#fff;cursor:pointer;align-items:center;justify-content:center;color:var(--ink)}
/* language: one at a time, the same switcher as Register */
.lang{position:relative}
.lang-btn{display:inline-flex;align-items:center;gap:6px;min-height:42px;padding:0 12px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;font:inherit;font-size:14px;font-weight:800;color:var(--ink);cursor:pointer}
.lang-btn:hover{border-color:var(--blue)}
.pop{position:absolute;top:calc(100% + 8px);background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 22px 50px rgba(15,23,42,.16);padding:6px;z-index:60;animation:drop .18s ease-out}
.pop[hidden]{display:none}
@keyframes drop{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.lang .pop{right:0;min-width:190px}
.lang .pop a{display:flex;align-items:center;gap:10px;min-height:44px;padding:0 12px;border-radius:10px;text-decoration:none;color:var(--ink);font-weight:600;font-size:15px}
.lang .pop a:hover{background:var(--tint)}
.lang .pop a[aria-current=true]{color:var(--blue);font-weight:800}
.lang .pop a .tick{margin-left:auto}
/* products panel */
.mega{left:24px;right:24px;max-width:780px;padding:16px;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:14px}
.mcol{border-radius:14px;padding:14px;background:var(--soft);display:flex;flex-direction:column;gap:8px}
.mcol h4{margin:0;font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted)}
.mcol p{margin:0 0 2px;font-size:13.5px;color:var(--muted)}
.mitem{display:grid;grid-template-columns:42px minmax(0,1fr) auto;gap:12px;align-items:center;text-decoration:none;border:1px solid transparent;background:#fff;border-radius:12px;padding:10px 12px;color:var(--ink);transition:border-color .15s,transform .15s}
.mitem:hover{border-color:#bfdbfe;transform:translateX(3px);color:var(--ink)}
.mitem[aria-current=page]{border-color:var(--blue)}
.mitem strong{display:block;font-size:15px}.mitem small{display:block;font-size:13px;color:var(--muted)}
.mitem > .i:last-child{color:var(--blue)}
.ico{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center}
.ico.b{background:#dbeafe;color:var(--blue)}.ico.w{background:#ffedd5;color:var(--warm)}.ico.n{background:#e0e7ff;color:var(--indigo)}
/* phone menu */
.sheet{position:absolute;left:0;right:0;top:100%;background:#fff;border-bottom:1px solid var(--line);box-shadow:0 26px 40px rgba(15,23,42,.18);padding:14px 16px 20px;display:flex;flex-direction:column;gap:10px;max-height:calc(100vh - 70px);overflow-y:auto;animation:drop .2s ease-out}
.sheet[hidden]{display:none}
.sheet h4{margin:4px 0 0;font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted)}
.sheet .mitem{background:var(--soft)}
.sheet .plain{display:flex;justify-content:space-between;align-items:center;min-height:52px;border-top:1px solid var(--line);text-decoration:none;color:var(--ink);font-weight:700;font-size:16px}
.sheet .btns{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px}

/* ── home hero ── */
.hero{position:relative;overflow:hidden;padding:76px 0 0;text-align:center;isolation:isolate}
.glow{position:absolute;inset:-22% -10% auto;height:560px;z-index:-1;background:radial-gradient(40% 50% at 30% 30%,rgba(96,165,250,.34),transparent 70%),radial-gradient(35% 45% at 72% 25%,rgba(29,78,216,.20),transparent 70%);animation:drift 14s ease-in-out infinite alternate}
.glow.w{background:radial-gradient(40% 50% at 30% 30%,rgba(251,146,60,.28),transparent 70%),radial-gradient(35% 45% at 72% 25%,rgba(194,65,12,.14),transparent 70%)}
.glow.n{background:radial-gradient(40% 50% at 30% 30%,rgba(129,140,248,.28),transparent 70%),radial-gradient(35% 45% at 72% 25%,rgba(29,78,216,.16),transparent 70%)}
.gridbg{position:absolute;inset:0;z-index:-1;background-image:linear-gradient(rgba(15,23,42,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(15,23,42,.05) 1px,transparent 1px);background-size:44px 44px;-webkit-mask-image:radial-gradient(60% 55% at 50% 18%,#000,transparent);mask-image:radial-gradient(60% 55% at 50% 18%,#000,transparent)}
@keyframes drift{to{transform:translate3d(4%,6%,0) scale(1.08)}}
.pill{display:inline-flex;align-items:center;gap:8px;padding:6px 14px 6px 6px;border-radius:999px;background:#fff;border:1px solid #dbeafe;font-size:13.5px;font-weight:700;color:#1e40af;box-shadow:0 4px 14px rgba(29,78,216,.10);text-decoration:none}
.pill b{background:var(--blue);color:#fff;border-radius:999px;padding:2px 10px;font-size:11.5px}
.hero h1{margin:20px auto 0;font-size:clamp(34px,6vw,68px);line-height:1.04;letter-spacing:-2px;font-weight:800;max-width:940px}
.rot{display:inline-grid;vertical-align:bottom;overflow:hidden;height:1.12em;text-align:left;transition:width .45s cubic-bezier(.2,.8,.2,1)}
.rot > span{grid-area:1/1;justify-self:start;white-space:nowrap;background:linear-gradient(90deg,var(--blue),#3b82f6 60%,#0ea5e9);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:var(--blue);transform:translateY(105%);opacity:0;transition:transform .55s cubic-bezier(.2,.8,.2,1),opacity .4s}
.rot > span.on{transform:none;opacity:1}
.rot > span.out{transform:translateY(-105%);opacity:0}
.hero .lead{margin:20px auto 0;text-align:center}
.cta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:30px}
.cta .btn{min-height:54px;padding:0 28px;font-size:16px}
.trust{display:flex;gap:8px 20px;justify-content:center;flex-wrap:wrap;margin-top:18px;font-size:14px;font-weight:600;color:var(--muted)}
.trust span{display:inline-flex;gap:6px;align-items:center}.trust .i{color:var(--ok)}
.up{animation:up .7s cubic-bezier(.2,.8,.2,1) both}
.d1{animation-delay:.05s}.d2{animation-delay:.15s}.d3{animation-delay:.25s}.d4{animation-delay:.35s}.d5{animation-delay:.5s}
@keyframes up{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
.stage{position:relative;max-width:980px;margin:52px auto 0;padding:0 8px}
.app{position:relative;border:1px solid var(--line);border-bottom:0;border-radius:20px 20px 0 0;background:#fff;box-shadow:0 -12px 60px rgba(15,23,42,.10);overflow:hidden;text-align:left;display:flex}
.app-side{flex:0 0 180px;padding:18px;border-right:1px solid var(--line);display:flex;flex-direction:column;gap:3px;font-size:14px;color:var(--muted)}
.app-side span{padding:9px 12px;border-radius:9px}.app-side span:first-child{background:var(--tint);color:var(--blue);font-weight:700}
.app-main{flex:1 1 auto;min-width:0;padding:20px;display:flex;flex-direction:column;gap:14px}
.kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.kpi{border:1px solid var(--line);border-radius:14px;padding:14px;min-width:0}
.kpi p{margin:0;font-size:12.5px;color:var(--muted)}
.kpi .v{margin-top:4px;font-size:clamp(15px,2vw,22px);font-weight:800;font-variant-numeric:tabular-nums;color:var(--ink);white-space:nowrap}
.kpi .tr{font-size:12px;font-weight:700;color:var(--ok)}
.bars{height:140px;border:1px solid var(--line);border-radius:14px;padding:14px 14px 10px;display:flex;align-items:flex-end;gap:10px}
.bars i{flex:1;border-radius:6px 6px 2px 2px;background:linear-gradient(#93c5fd,#dbeafe);transform-origin:bottom;animation:grow .9s cubic-bezier(.2,.8,.2,1) both}
.bars i:last-child{background:linear-gradient(var(--blue),#60a5fa)}
@keyframes grow{from{transform:scaleY(0)}to{transform:scaleY(1)}}
.toast{position:absolute;right:-14px;top:-30px;width:250px;background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 20px 44px rgba(15,23,42,.16);padding:12px 14px;display:flex;gap:10px;align-items:center;font-size:13.5px;transition:opacity .4s,transform .4s;z-index:2;text-align:left}
.toast.hide{opacity:0;transform:translateY(-8px)}
.toast .dot{width:36px;height:36px;border-radius:11px;background:#dcfce7;color:var(--ok);display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.toast span{min-width:0}
.receipt{position:absolute;left:-18px;bottom:26px;width:168px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 14px;font-size:11.5px;box-shadow:0 20px 44px rgba(15,23,42,.14);animation:bob 5s ease-in-out infinite;z-index:2;text-align:left}
.receipt hr{border:0;border-top:1px dashed #cbd5e1;margin:7px 0}
.receipt div{display:flex;justify-content:space-between;gap:8px;font-variant-numeric:tabular-nums}
@keyframes bob{50%{transform:translateY(-8px)}}
/* choose cards */
.choose{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-top:30px}
.ccard{text-decoration:none;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:22px;padding:24px;display:flex;flex-direction:column;gap:10px;transition:transform .2s,box-shadow .2s,border-color .2s}
.ccard:hover{transform:translateY(-4px);box-shadow:0 22px 44px rgba(15,23,42,.10);border-color:#bfdbfe;color:var(--ink)}
.ccard h3{font-size:21px;letter-spacing:-.4px}
.ccard p{margin:0;color:var(--muted);font-size:15px}
.ccard ul{margin:4px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:7px;font-size:14.5px;font-weight:600}
.ccard li{display:flex;gap:8px;align-items:flex-start}.ccard li .i{color:var(--blue);margin-top:2px}
.ccard .more{margin-top:auto;padding-top:10px;font-weight:800;color:var(--blue);display:flex;gap:6px;align-items:center}
.ccard .more .i{transition:transform .2s}.ccard:hover .more .i{transform:translateX(4px)}
.ccard.w li .i,.ccard.w .more{color:var(--warm)}.ccard.w:hover{border-color:#fed7aa}
.ccard.n li .i,.ccard.n .more{color:var(--indigo)}.ccard.n:hover{border-color:#c7d2fe}
.prodtag{font-size:11.5px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.vals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:28px}
.vals h3{margin-top:14px;font-size:19px}.vals p{margin:6px 0 0;color:var(--muted);font-size:15px}
.fi{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;background:#dbeafe;color:var(--blue);flex:0 0 auto}
.t-w .fi{background:#ffedd5;color:var(--warm)}.t-n .fi{background:#e0e7ff;color:var(--indigo)}
.band{margin-top:60px;text-align:center;border-top:1px solid var(--line);padding-top:52px}
/* ── product pages ── */
.phero{position:relative;overflow:hidden;isolation:isolate;padding:56px 0 52px}
.phero .glow{height:420px}
.crumb{font-size:14px;color:var(--muted);margin:0}
.crumb a{text-decoration:none}
.phero h1{margin-top:12px;font-size:clamp(32px,4.6vw,54px);letter-spacing:-1.5px;line-height:1.06;font-weight:800;max-width:760px}
.split{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:40px;align-items:center}
.phero .cta{justify-content:flex-start}
.note{margin:14px 0 0;font-size:14px;color:var(--muted)}
.feats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:28px}
.feat{border:1px solid var(--line);border-radius:18px;padding:18px;background:#fff;display:flex;gap:14px;align-items:flex-start;transition:transform .2s,box-shadow .2s}
.feat:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(15,23,42,.08)}
.feat strong{display:block;font-size:16px}.feat span{display:block;font-size:14px;color:var(--muted);margin-top:3px}
.steps1{list-style:none;padding:0;margin:26px 0 0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.steps1 li{border:1px solid var(--line);border-radius:18px;padding:18px;background:#fff;font-weight:700}
.steps1 b{width:34px;height:34px;border-radius:50%;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;margin-bottom:10px}
.signup{margin-top:32px;border-radius:22px;background:var(--navy);color:#e2e8f0;padding:28px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:22px;align-items:center}
.signup.w{background:#1c1008}
.signup h3{color:#fff;font-size:23px}
.signup ol{margin:14px 0 0;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:8px}
.signup li{font-size:14px;background:rgba(255,255,255,.08);border-radius:999px;padding:7px 13px}
.signup li b{color:var(--sky);margin-right:4px}.signup.w li b{color:#fdba74}
/* till */
.till{border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:0 22px 54px rgba(15,23,42,.10);padding:18px;display:flex;flex-direction:column;gap:8px;font-size:14px}
.till-rows{display:flex;flex-direction:column;gap:8px;min-height:180px}
.till .row{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:11px;background:var(--soft);font-variant-numeric:tabular-nums;animation:slidein .5s ease-out both}
.till .row[hidden]{display:none}
.till .row span span{color:var(--muted)}
@keyframes slidein{from{opacity:0;transform:translateX(16px)}to{opacity:1;transform:none}}
.till .tot{display:flex;justify-content:space-between;font-weight:800;font-size:18px;padding:12px;border-top:1px dashed #cbd5e1;font-variant-numeric:tabular-nums}
/* restaurant flow */
.flow{border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:0 22px 54px rgba(15,23,42,.10);padding:22px}
.flow h4{margin:0 0 16px;font-size:14.5px;color:var(--muted)}
.track{position:relative;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:4px}
.track::before{content:"";position:absolute;left:10%;right:10%;top:23px;height:3px;background:#fde2cf;border-radius:3px}
.runner{position:absolute;top:17px;left:10%;width:15px;height:15px;border-radius:50%;background:var(--warm);box-shadow:0 0 0 6px rgba(194,65,12,.15);animation:run 7s cubic-bezier(.6,0,.4,1) infinite;z-index:2}
@keyframes run{0%,6%{left:10%}20%,26%{left:30%}40%,46%{left:50%}60%,66%{left:70%}80%,100%{left:calc(90% - 15px)}}
.stop{text-align:center;font-size:13px;font-weight:700;display:flex;flex-direction:column;align-items:center;gap:6px;min-width:0}
.stop .c{width:48px;height:48px;border-radius:50%;background:var(--warm-soft);border:2px solid #fed7aa;color:var(--warm);display:flex;align-items:center;justify-content:center;position:relative;z-index:1}
.stop small{display:block;font-weight:500;color:var(--muted);font-size:11.5px}
.tickets{margin-top:18px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.tk{border:1px dashed #fdba74;border-radius:12px;padding:10px 12px;font-size:13px;background:#fffbf7}
.tk b{display:flex;justify-content:space-between}
.tk em{display:inline-block;margin-top:6px;font-style:normal;font-size:11.5px;font-weight:800;padding:2px 9px;border-radius:999px;background:#ffedd5;color:var(--warm)}
.tk em.ok{background:#dcfce7;color:var(--ok)}
/* ledger */
.ledger{border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:0 22px 54px rgba(15,23,42,.10);overflow:hidden}
.ledger header{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line);font-weight:800}
.bal{font-size:12.5px;font-weight:800;color:var(--ok);background:#dcfce7;border-radius:999px;padding:3px 11px;white-space:nowrap}
.tbw{overflow-x:auto}
.tb{width:100%;min-width:340px;border-collapse:collapse;font-size:13.5px;font-variant-numeric:tabular-nums}
.tb th,.tb td{padding:10px 16px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}
.tb th:first-child,.tb td:first-child{text-align:left;white-space:normal}
.tb thead th{background:var(--navy);color:#fff;font-size:12.5px}
.tb tfoot td{font-weight:800;background:var(--soft)}
.tb .code{color:var(--muted)}
.stmts{display:flex;flex-wrap:wrap;gap:8px;padding:14px 18px 6px}
.stmts span{font-size:12.5px;font-weight:700;border:1px solid #c7d2fe;color:var(--indigo);background:#eef2ff;border-radius:999px;padding:5px 11px}
.tbnote{margin:0;padding:6px 18px 14px;font-size:12px;color:var(--muted)}
/* pos overview */
.duo{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:30px}
/* ── pricing ── */
.seg-row{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:28px}
.seg{min-width:190px;min-height:70px;padding:12px 20px;border-radius:16px;border:1px solid #cbd5e1;background:#fff;font:inherit;color:var(--ink);text-align:left;cursor:pointer}
.seg[aria-selected=true]{border:2px solid var(--blue);box-shadow:0 0 0 4px #dbeafe}
.seg strong{display:block;font-size:18px}.seg span{display:block;font-size:13px;color:var(--muted)}
.panel[hidden]{display:none}
.toggle{display:inline-flex;background:#f1f5f9;border-radius:999px;padding:4px;flex-wrap:wrap}
.toggle button{min-height:44px;padding:0 18px;border:0;border-radius:999px;background:transparent;font:inherit;font-weight:800;color:var(--muted);cursor:pointer}
.toggle button[aria-pressed=true]{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(15,23,42,.12)}
.plans{display:flex;flex-wrap:wrap;border:1px solid var(--line);border-radius:24px;overflow:hidden}
.plan{flex:1 1 280px;padding:30px;display:flex;flex-direction:column;background:#fff;border-right:1px solid var(--line)}
.plan:last-child{border-right:0}.plan.popular{background:var(--tint)}
.plan .price{margin:16px 0 0;font-size:32px;font-weight:800}
.plan .per{font-size:14px;color:var(--muted);margin:0}
.price-t[hidden]{display:none}
.samenote{display:flex;gap:10px;align-items:center;justify-content:center;margin:0 0 22px;font-size:14.5px;color:var(--muted)}
.samenote .i{color:var(--blue)}
table.cmp{width:100%;min-width:560px;border-collapse:collapse;font-size:15px}
.cmp th,.cmp td{padding:13px 16px;border-top:1px solid var(--line);text-align:center}
.cmp thead th{background:var(--navy);color:#fff;border-top:0}
.cmp th[scope=row]{text-align:left;font-weight:600}
/* faq */
details.q{border-bottom:1px solid var(--line)}
details.q summary{list-style:none;cursor:pointer;min-height:60px;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px 0;font-size:18px;font-weight:700}
details.q summary::-webkit-details-marker{display:none}
details.q summary::after{content:'+';width:32px;height:32px;border-radius:50%;background:#f1f5f9;color:var(--blue);display:flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:20px}
details.q[open] summary::after{content:'\2212'}
details.q p{margin:0 0 18px;color:var(--muted);font-size:16px}
.cats{display:flex;flex-direction:column;gap:6px}
.cats > a{min-height:46px;padding:0 16px;border-radius:12px;display:flex;align-items:center;font-weight:700;color:#334155;text-decoration:none}
.cats > a[aria-current=true]{background:var(--ink);color:#fff}
/* contact */
.split2{display:flex;flex-wrap:wrap;border:1px solid var(--line);border-radius:26px;overflow:hidden}
.split-l{flex:1 1 360px;background:var(--navy);color:#fff;padding:40px}
.split-r{flex:1 1 440px;min-width:0;padding:40px}
.ch{display:flex;gap:14px;align-items:center;margin-top:18px;color:#fff;text-decoration:none}
.ch:hover{color:#fff}
.ch span.ci{width:44px;height:44px;border-radius:50%;background:#1e293b;color:#93c5fd;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;flex:0 0 auto}
label.f{display:block;margin-top:14px;font-size:14px;font-weight:700}
.in{width:100%;min-height:48px;margin-top:6px;padding:0 16px;border:1px solid #94a3b8;border-radius:999px;font:inherit;background:#fff}
textarea.in{min-height:110px;padding:12px 16px;border-radius:16px;resize:vertical}
/* footer */
.ftr{background:var(--navy);color:#94a3b8;font-size:14.5px}
.ftr-in{max-width:1180px;margin:0 auto;padding:52px 24px 34px;display:grid;grid-template-columns:minmax(0,1.6fr) repeat(3,minmax(0,1fr));gap:32px}
.ftr h2{font-size:13px;letter-spacing:1px;text-transform:uppercase;color:#fff;margin:0 0 12px}
.ftr .fb{display:flex;gap:10px;align-items:center;color:#fff;font-weight:800;font-size:19px;text-decoration:none}
.ftr .fb:hover{color:#fff}
.ftr p{margin:12px 0 0;line-height:1.65;max-width:42ch}
.ftr ul{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:9px}
.ftr a{color:#cbd5e1;text-decoration:none}.ftr a:hover{color:#fff}
.ftr-bot{border-top:1px solid #1e293b}
.ftr-bot div{max-width:1180px;margin:0 auto;padding:16px 24px;display:flex;flex-wrap:wrap;gap:10px 18px;justify-content:space-between;font-size:13.5px}
.ftr-bot a{text-decoration:underline;text-underline-offset:3px}
/* log in window */
.dlg{position:fixed;inset:0;z-index:80;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px}
.dlg[hidden]{display:none}
.dlg-box{width:100%;max-width:440px;background:#fff;border-radius:22px;padding:28px}
.x{width:44px;height:44px;border:0;background:#f1f5f9;border-radius:50%;font-size:20px;cursor:pointer}
.addr{display:flex;border:1px solid #94a3b8;border-radius:999px;overflow:hidden;margin-top:6px}
.addr input{flex:1;min-width:0;min-height:50px;border:0;padding:0 16px;font:inherit}
.addr span{display:flex;align-items:center;padding:0 14px;background:#f1f5f9;color:var(--muted);font-size:14px;white-space:nowrap}
.found{display:none;margin-top:14px;padding:14px;border-radius:14px;background:var(--tint);gap:12px;align-items:center}
.found.on{display:flex}
.found img{width:44px;height:44px;border-radius:10px;object-fit:contain;background:#fff}
.err{color:#b91c1c;font-size:14px;font-weight:600;margin-top:8px;min-height:20px}

@media (max-width:980px){
  .feats{grid-template-columns:repeat(2,minmax(0,1fr))}
  .steps1{grid-template-columns:repeat(2,minmax(0,1fr))}
  .ftr-in{grid-template-columns:repeat(3,minmax(0,1fr))}.ftr-in > div:first-child{grid-column:1/-1}
}
@media (max-width:900px){
  .nav,.hdr-act .linkbtn,.hdr-act .trialbtn{display:none}
  .burger{display:inline-flex}
  .hdr-in{padding:10px 16px;gap:10px}
  .split,.choose,.vals,.duo{grid-template-columns:minmax(0,1fr)}
  .signup{grid-template-columns:minmax(0,1fr)}
}
@media (max-width:640px){
  .wrap{padding:0 16px}
  .sec{padding:52px 0}
  .hero{padding-top:40px}
  .hero h1{letter-spacing:-1.2px}
  .lead{font-size:16px}
  .cta .btn,.signup .btn{width:100%}
  .app-side,.receipt{display:none}
  .toast{right:6px;top:-28px;width:min(230px,80%)}
  .kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.kpis .kpi:last-child{display:none}
  .stage{margin-top:40px}
  .feats,.steps1,.tickets{grid-template-columns:minmax(0,1fr)}
  .phero{padding:32px 0 36px}
  .stop{font-size:11px}.stop .c{width:40px;height:40px}.track::before{top:19px}.runner{top:13px}.stop small{font-size:10.5px}
  .split-l,.split-r{padding:28px}.plan{border-right:0;border-bottom:1px solid var(--line)}
  .ftr-in{grid-template-columns:repeat(2,minmax(0,1fr));padding:40px 16px 26px;gap:26px}
  .ftr-bot div{padding:14px 16px}
  .brand{font-size:17px}
}
@media (prefers-reduced-motion:reduce){
  *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;scroll-behavior:auto!important}
  .runner{display:none}
}
</style>
</head>
<body>
<header class="hdr">
  <div class="hdr-in">
    <a class="brand" href="<?= $h($url('')) ?>" aria-label="Smart BMS — <?= $h($T['home']) ?>"><?= $brandMark ?>Smart BMS</a>
    <nav class="nav" aria-label="<?= $h($T['menu']) ?>">
      <button type="button" id="prodBtn" class="<?= $inPos ? 'cur' : '' ?>" aria-expanded="false" aria-controls="mega"><?= $h($T['products']) ?> <?= $icon('chev', 14, 'caret') ?></button>
      <a href="<?= $h($url('pricing')) ?>"<?= $path === 'pricing' ? ' aria-current="page"' : '' ?>><?= $h($T['pricing']) ?></a>
      <a href="<?= $h($url('faq')) ?>"<?= $path === 'faq' ? ' aria-current="page"' : '' ?>><?= $h($T['faq']) ?></a>
      <a href="<?= $h($url('contact')) ?>"<?= $path === 'contact' ? ' aria-current="page"' : '' ?>><?= $h($T['contact']) ?></a>
    </nav>
    <div class="hdr-act">
      <div class="lang">
        <button type="button" class="lang-btn" id="langBtn" aria-expanded="false" aria-controls="langMenu" aria-label="<?= $h($T['language']) ?>: <?= $sw ? 'Kiswahili' : 'English' ?>"><?= $icon('globe', 17) ?><?= $sw ? 'SW' : 'EN' ?><?= $icon('chev', 13, 'caret') ?></button>
        <div class="pop" id="langMenu" hidden>
          <a href="<?= $h($url($path, 'en')) ?>" hreflang="en" lang="en"<?= !$sw ? ' aria-current="true"' : '' ?>>English<?= !$sw ? $icon('check', 16, 'tick') : '' ?></a>
          <a href="<?= $h($url($path, 'sw')) ?>" hreflang="sw" lang="sw"<?= $sw ? ' aria-current="true"' : '' ?>>Kiswahili<?= $sw ? $icon('check', 16, 'tick') : '' ?></a>
        </div>
      </div>
      <button type="button" class="linkbtn" data-open-login><?= $h($T['login']) ?></button>
      <?php if ($canTrial): ?>
      <a class="btn btn-dark trialbtn" style="min-height:44px;padding:0 18px" href="<?= $h($reg()) ?>"><?= $h($T['trial']) ?></a>
      <?php else: ?>
      <a class="btn btn-dark trialbtn" style="min-height:44px;padding:0 18px" href="<?= $h($url('contact')) ?>"><?= $h($T['contact']) ?></a>
      <?php endif; ?>
      <button type="button" class="burger" id="burger" aria-expanded="false" aria-controls="sheet" aria-label="<?= $h($T['menu_open']) ?>"><?= $icon('menu', 22) ?></button>
    </div>

    <?php // Products panel (computer) and the phone menu share the same three links. ?>
    <?php $prodLinks = function (bool $phone) use ($h, $url, $T, $icon, $path) { ob_start(); ?>
      <a class="mitem" href="<?= $h($url('smart-pos/retail')) ?>"<?= $path === 'smart-pos/retail' ? ' aria-current="page"' : '' ?>><span class="ico b"><?= $icon('store', 21) ?></span><span><strong><?= $phone ? 'Smart POS · ' : '' ?><?= $h($T['m_ret']) ?></strong><small><?= $h($T['m_ret_s']) ?></small></span><?= $icon('arrow', 18) ?></a>
      <a class="mitem" href="<?= $h($url('smart-pos/restaurant')) ?>"<?= $path === 'smart-pos/restaurant' ? ' aria-current="page"' : '' ?>><span class="ico w"><?= $icon('fork', 21) ?></span><span><strong><?= $phone ? 'Smart POS · ' : '' ?><?= $h($T['m_res']) ?></strong><small><?= $h($T['m_res_s']) ?></small></span><?= $icon('arrow', 18) ?></a>
    <?php return ob_get_clean(); };
    $bmsLink = '<a class="mitem" href="' . $h($url('smart-bms')) . '"' . ($path === 'smart-bms' ? ' aria-current="page"' : '') . '><span class="ico n">' . $icon('ledger', 21) . '</span><span><strong>Smart BMS</strong><small>' . $h($T['m_bms_s']) . '</small></span>' . $icon('arrow', 18) . '</a>'; ?>
    <div class="pop mega" id="mega" hidden>
      <div class="mcol"><h4>Smart BMS</h4><p><?= $h($T['m_bms_d']) ?></p><?= $bmsLink ?></div>
      <div class="mcol"><h4><a href="<?= $h($url('smart-pos')) ?>" style="color:inherit;text-decoration:none">Smart POS</a></h4><p><?= $h($T['m_pos_d']) ?></p><?= $prodLinks(false) ?></div>
    </div>
    <div class="sheet" id="sheet" hidden>
      <h4><?= $h($T['products']) ?></h4>
      <?= $prodLinks(true) ?><?= $bmsLink ?>
      <div>
        <a class="plain" href="<?= $h($url('pricing')) ?>"><?= $h($T['pricing']) ?><?= $icon('arrow', 18) ?></a>
        <a class="plain" href="<?= $h($url('faq')) ?>"><?= $h($T['faq']) ?><?= $icon('arrow', 18) ?></a>
        <a class="plain" href="<?= $h($url('contact')) ?>"><?= $h($T['contact']) ?><?= $icon('arrow', 18) ?></a>
      </div>
      <div class="btns">
        <button type="button" class="btn btn-line" data-open-login><?= $h($T['login']) ?></button>
        <a class="btn btn-blue" href="<?= $h($canTrial ? $reg() : $url('contact')) ?>"><?= $h($canTrial ? $T['trial'] : $T['contact']) ?></a>
      </div>
    </div>
  </div>
</header>

<main id="main">
<?php if ($path === ''): /* ═════════════ HOME ═════════════ */ ?>
  <section class="hero">
    <div class="glow" aria-hidden="true"></div><div class="gridbg" aria-hidden="true"></div>
    <div class="wrap">
      <a class="pill up d1" href="<?= $h($url('smart-pos/restaurant')) ?>"><b><?= $h($T['pill_new']) ?></b><?= $h($T['pill']) ?></a>
      <h1 class="up d2"><?= $h($T['h_a']) ?> <span class="rot" id="rot"><?php foreach ($T['h_words'] as $i => $w): ?><span<?= $i === 0 ? ' class="on"' : ' aria-hidden="true"' ?>><?= $h($w) ?></span><?php endforeach; ?></span><br><?= $h($T['h_b']) ?></h1>
      <p class="lead up d3"><?= $h($T['hero_sub']) ?></p>
      <div class="cta up d4">
        <?php if ($canTrial): ?><a class="btn btn-blue" href="<?= $h($reg()) ?>"><?= $h($T['trial_long']) ?></a><?php endif; ?>
        <button type="button" class="btn btn-line" id="seeProducts"><?= $h($T['see_products']) ?></button>
      </div>
      <div class="trust up d5"><?php foreach ($T['points'] as $pt): ?><span><?= $icon('check', 16) ?><?= $h($pt) ?></span><?php endforeach; ?></div>
      <div class="stage up d5">
        <div class="toast" id="toast" role="status"><span class="dot"><?= $icon('check', 18) ?></span><span><strong id="toastT"><?= $h($T['feed'][0][0]) ?></strong><br><span id="toastV" style="color:var(--muted)"><?= $h($T['feed'][0][1]) ?></span></span></div>
        <div class="receipt" aria-hidden="true"><strong><?= $h($T['rc_title']) ?></strong><hr><?php foreach ($T['rc_items'] as $ri): ?><div><span><?= $h($ri[0]) ?></span><span><?= $h($ri[1]) ?></span></div><?php endforeach; ?><hr><div><b><?= $h($T['rc_total']) ?></b><b>7,500</b></div></div>
        <div class="app" aria-label="<?= $h($T['example']) ?>">
          <div class="app-side" aria-hidden="true"><?php foreach ($T['app_menu'] as $m): ?><span><?= $h($m) ?></span><?php endforeach; ?></div>
          <div class="app-main">
            <div style="display:flex;gap:10px;align-items:center"><strong style="font-size:18px"><?= $h($T['today']) ?></strong><span class="ex"><?= $h($T['example']) ?></span></div>
            <div class="kpis">
              <div class="kpi"><p><?= $h($T['k_sales']) ?></p><p class="v">TZS <span class="count" data-to="1365000">1,365,000</span></p><span class="tr"><?= $h($T['k_up']) ?></span></div>
              <div class="kpi"><p><?= $h($T['k_profit']) ?></p><p class="v">TZS <span class="count" data-to="412500">412,500</span></p><span class="tr"><?= $h($T['k_up2']) ?></span></div>
              <div class="kpi"><p><?= $h($T['k_credit']) ?></p><p class="v">TZS <span class="count" data-to="86000">86,000</span></p><span class="tr" style="color:var(--muted)"><?= $h($T['k_who']) ?></span></div>
            </div>
            <div class="bars" aria-hidden="true"><?php foreach ([35, 50, 42, 64, 58, 76, 100] as $k => $bh): ?><i style="height:<?= $bh ?>%;animation-delay:<?= number_format(.6 + $k * .08, 2) ?>s"></i><?php endforeach; ?></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="sec sec-soft">
    <div class="wrap">
      <p class="kick"><?= $h($T['choose_k']) ?></p>
      <h2 class="big" style="margin-top:8px"><?= $h($T['choose_h']) ?></h2>
      <div class="choose">
        <?php foreach (['retail' => ['smart-pos/retail', 'Smart POS', '', 'store'], 'restaurant' => ['smart-pos/restaurant', 'Smart POS', 'w', 'fork'], 'company' => ['smart-bms', 'Smart BMS', 'n', 'ledger']] as $ck => [$cp, $cprod, $ccls, $cico]): $c = $T['cards'][$ck]; ?>
        <a class="ccard <?= $ccls ?> t-<?= $ccls ?>" href="<?= $h($url($cp)) ?>">
          <span style="display:flex;justify-content:space-between;align-items:center"><span class="fi"><?= $icon($cico, 22) ?></span><span class="prodtag"><?= $h($cprod) ?></span></span>
          <h3><?= $h($c[0]) ?></h3>
          <p><?= $h($c[1]) ?></p>
          <ul><?php foreach ($c[2] as $li): ?><li><?= $icon('check', 16) ?><?= $h($li) ?></li><?php endforeach; ?></ul>
          <span class="more"><?= $h($c[3]) ?> <?= $icon('arrow', 18) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="sec">
    <div class="wrap">
      <div class="vals">
        <?php foreach ($T['values'] as $i => $v): ?>
        <div><span class="fi"><?= $icon(['user', 'lock', 'db'][$i] ?? 'check', 22) ?></span><h3><?= $h($v[0]) ?></h3><p><?= $h($v[1]) ?></p></div>
        <?php endforeach; ?>
      </div>
      <div class="band">
        <h2 class="big"><?= $h($T['band_title']) ?></h2>
        <p class="lead" style="margin-left:auto;margin-right:auto"><?= $h($canTrial ? $T['band_sub'] : $T['band_sub_closed']) ?></p>
        <div class="cta">
          <?php if ($canTrial): ?><a class="btn btn-blue" href="<?= $h($reg()) ?>"><?= $h($T['trial_long']) ?></a><?php else: ?><a class="btn btn-blue" href="<?= $h($url('contact')) ?>"><?= $h($T['contact']) ?></a><?php endif; ?>
          <a class="btn btn-line" href="<?= $h($url('pricing')) ?>"><?= $h($T['see_prices']) ?></a>
        </div>
      </div>
    </div>
  </section>

<?php elseif ($path === 'smart-pos'): /* ═════════════ SMART POS OVERVIEW ═════════════ */ ?>
  <section class="phero">
    <div class="glow" aria-hidden="true"></div>
    <div class="wrap">
      <p class="crumb"><a href="<?= $h($url('')) ?>"><?= $h($T['home']) ?></a> / Smart POS</p>
      <p class="kick" style="margin-top:16px"><?= $h($T['pos_hub_k']) ?></p>
      <h1><?= $h($T['pos_hub_h']) ?></h1>
      <p class="lead"><?= $h($T['pos_hub_sub']) ?></p>
      <div class="duo">
        <?php foreach (['retail' => ['smart-pos/retail', '', 'store'], 'restaurant' => ['smart-pos/restaurant', 'w', 'fork']] as $ck => [$cp, $ccls, $cico]): $c = $T['cards'][$ck]; ?>
        <a class="ccard <?= $ccls ?> t-<?= $ccls ?>" href="<?= $h($url($cp)) ?>">
          <span class="fi"><?= $icon($cico, 22) ?></span>
          <h3><?= $h($c[0]) ?></h3><p><?= $h($c[1]) ?></p>
          <ul><?php foreach ($c[2] as $li): ?><li><?= $icon('check', 16) ?><?= $h($li) ?></li><?php endforeach; ?></ul>
          <span class="more"><?= $h($c[3]) ?> <?= $icon('arrow', 18) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <p class="note"><?= $h($T['pos_same']) ?> <a href="<?= $h($url($posOffer ? 'pricing' : 'contact')) ?>"><?= $h($posOffer ? $T['see_prices'] : $T['ask_price']) ?></a></p>
    </div>
  </section>

<?php elseif (in_array($path, ['smart-pos/retail', 'smart-pos/restaurant', 'smart-bms'], true)): /* ═════════════ PRODUCT PAGES ═════════════ */
  $kind  = $path === 'smart-bms' ? 'bms' : ($path === 'smart-pos/restaurant' ? 'res' : 'ret');
  $pc    = $kind === 'bms' ? bmsProductCopy($sw)['bms_full'] : ($kind === 'res' ? bmsRestaurantCopy($sw) : bmsProductCopy($sw)['bms_shop']);
  $offer = $kind === 'bms' ? $bmsOffer : $posOffer;
  $pname = $offer['name'] ?? ($kind === 'bms' ? 'Smart BMS' : 'Smart POS');
  $tone  = $kind === 'res' ? 'w' : ($kind === 'bms' ? 'n' : '');
  $btn   = $kind === 'res' ? 'btn-warm' : 'btn-blue';
  $signupHref = $reg(['product' => $kind === 'bms' ? 'bms_full' : 'bms_shop', 'business' => $kind === 'res' ? 'restaurant' : '']);
  $feats = $T[$kind . '_feats'];
?>
  <section class="phero t-<?= $tone ?>">
    <div class="glow <?= $tone ?>" aria-hidden="true"></div>
    <div class="wrap split">
      <div>
        <p class="crumb"><a href="<?= $h($url('')) ?>"><?= $h($T['home']) ?></a> / <?php if ($kind !== 'bms'): ?><a href="<?= $h($url('smart-pos')) ?>">Smart POS</a> / <?= $h($kind === 'res' ? $T['m_res'] : $T['m_ret']) ?><?php else: ?>Smart BMS<?php endif; ?></p>
        <p class="kick" style="margin-top:16px;<?= $kind === 'res' ? 'color:var(--warm)' : ($kind === 'bms' ? 'color:var(--indigo)' : '') ?>"><?= $h($T[$kind . '_k']) ?></p>
        <h1><?= $h($kind === 'bms' ? $T['bms_h'] : $pc['head']) ?></h1>
        <p class="lead"><?= $h($kind === 'bms' ? $T['bms_sub'] : $pc['sub']) ?></p>
        <div class="cta">
          <?php if ($canTrial && $offer): ?><a class="btn <?= $btn ?>" href="<?= $h($signupHref) ?>"><?= $h($kind === 'bms' ? $T['trial'] . ' — Smart BMS' : $T['trial']) ?></a><?php endif; ?>
          <a class="btn btn-line" href="<?= $h($url($offer ? 'pricing' : 'contact')) ?>"><?= $h($offer ? $T['see_prices'] : $T['ask_price']) ?></a>
        </div>
        <?php if ($kind === 'bms'): ?><p class="note"><?= $h($T['bms_approval']) ?></p><?php endif; ?>
      </div>
      <?php if ($kind === 'ret'): $sum = array_sum(array_column($T['till_items'], 2)); ?>
      <div class="till" aria-label="<?= $h($T['example']) ?>">
        <div style="display:flex;justify-content:space-between;align-items:center"><strong style="font-size:16px"><?= $h($T['till_title']) ?></strong><span class="ex"><?= $h($T['example']) ?></span></div>
        <div class="till-rows" id="tillRows"><?php foreach ($T['till_items'] as $it): ?><div class="row" data-amt="<?= (int)$it[2] ?>"><span><?= $h($it[0]) ?> <span><?= $h($it[1]) ?></span></span><span><?= $fmtN($it[2]) ?></span></div><?php endforeach; ?></div>
        <div class="tot"><span><?= $h($T['till_total']) ?></span><span>TZS <span id="tillTot"><?= $fmtN($sum) ?></span></span></div>
      </div>
      <?php elseif ($kind === 'res'): $fi = ['user', 'credit', 'flame', 'plate', 'invoice']; ?>
      <div class="flow" aria-label="<?= $h($T['flow_h']) ?>">
        <h4><?= $h($T['flow_h']) ?></h4>
        <div class="track">
          <span class="runner" aria-hidden="true"></span>
          <?php foreach ($T['flow'] as $k => $st): ?><div class="stop"><span class="c"><?= $icon($fi[$k], 20) ?></span><?= $h($st[0]) ?><small><?= $h($st[1]) ?></small></div><?php endforeach; ?>
        </div>
        <div class="tickets"><?php foreach ($T['tickets'] as $k => $tk): ?><div class="tk"><b><span><?= $h($tk[0]) ?></span><span><?= $h($tk[1]) ?></span></b><?= $h($tk[2]) ?><br><em class="<?= $k === 1 ? 'ok' : '' ?>"><?= $h($tk[3]) ?></em></div><?php endforeach; ?></div>
        <p class="note" style="margin-bottom:0"><?= $h($T['flow_note']) ?></p>
      </div>
      <?php else: $dr = array_sum(array_column($T['tb_rows'], 2)); $cr = array_sum(array_column($T['tb_rows'], 3)); ?>
      <div class="ledger" aria-label="<?= $h($T['tb_title']) ?> — <?= $h($T['example']) ?>">
        <header><span><?= $h($T['tb_title']) ?></span><span class="bal"><?= $h($T['tb_bal']) ?></span></header>
        <div class="tbw"><table class="tb">
          <thead><tr><?php foreach ($T['tb_cols'] as $c): ?><th scope="col"><?= $h($c) ?></th><?php endforeach; ?></tr></thead>
          <tbody><?php foreach ($T['tb_rows'] as $r): ?><tr><td><span class="code"><?= $h($r[0]) ?></span> — <?= $h($r[1]) ?></td><td><?= $r[2] ? $fmtN($r[2]) : '' ?></td><td><?= $r[3] ? $fmtN($r[3]) : '' ?></td></tr><?php endforeach; ?></tbody>
          <tfoot><tr><td><?= $h($T['tb_total']) ?></td><td><span class="count" data-to="<?= (int)$dr ?>"><?= $fmtN($dr) ?></span></td><td><span class="count" data-to="<?= (int)$cr ?>"><?= $fmtN($cr) ?></span></td></tr></tfoot>
        </table></div>
        <div class="stmts"><?php foreach ($T['stmts'] as $s): ?><span><?= $h($s) ?></span><?php endforeach; ?></div>
        <p class="tbnote"><?= $h($T['tb_note']) ?></p>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="sec sec-soft t-<?= $tone ?>">
    <div class="wrap">
      <h2 class="big"><?= $h($T[$kind . '_feats_h']) ?></h2>
      <div class="feats">
        <?php foreach ($feats as $f): ?><div class="feat"><span class="fi"><?= $icon($f[0], 21) ?></span><div><strong><?= $h($f[1]) ?></strong><span><?= $h($f[2]) ?></span></div></div><?php endforeach; ?>
      </div>
      <h2 class="big" style="margin-top:56px;font-size:clamp(22px,2.8vw,30px)"><?= $h($T['first_steps']) ?></h2>
      <ol class="steps1">
        <?php foreach ($pc['first'] as $i => $s): ?><li><b><?= $i + 1 ?></b><?= $h($s) ?></li><?php endforeach; ?>
      </ol>
      <?php if ($canTrial && $offer): ?>
      <div class="signup <?= $tone ?>">
        <div>
          <h3><?= $h($T['signup_h']) ?></h3>
          <ol><?php foreach ($T[$kind . '_steps'] as $i => $s): ?><li><b><?= $i + 1 ?></b><?= $h($s) ?></li><?php endforeach; ?></ol>
        </div>
        <a class="btn <?= $btn ?>" href="<?= $h($signupHref) ?>"><?= $h($T['trial']) ?></a>
      </div>
      <?php endif; ?>
    </div>
  </section>

<?php elseif ($path === 'pricing'): /* ═════════════ PRICING ═════════════ */ ?>
  <section class="sec" style="padding-bottom:24px;text-align:center">
    <div class="wrap">
      <h1 style="font-size:clamp(30px,4vw,46px);font-weight:800;letter-spacing:-1px"><?= $h($T['price_title']) ?></h1>
      <p class="lead" style="margin-left:auto;margin-right:auto"><?= $h($T['price_sub']) ?></p>
    </div>
  </section>
  <section style="padding-bottom:76px">
    <div class="wrap">
    <?php if (!$data['products']): ?>
      <div class="card" style="text-align:center">
        <h2 style="font-size:22px;font-weight:800"><?= $h($T['no_prices']) ?></h2>
        <p class="lead" style="margin-left:auto;margin-right:auto"><?= $h($T['no_prices_sub']) ?></p>
        <a class="btn btn-blue" style="margin-top:14px" href="<?= $h($url('contact')) ?>"><?= $h($T['contact']) ?></a>
      </div>
    <?php else: ?>
      <?php if (count($data['products']) > 1): ?>
      <div class="seg-row" role="tablist" aria-label="<?= $h($T['products']) ?>" style="margin-top:0;margin-bottom:26px">
        <?php foreach ($data['products'] as $i => $pr): ?>
        <button type="button" class="seg" role="tab" id="prt-<?= $h($pr['key']) ?>" aria-controls="prp-<?= $h($pr['key']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
          <strong><?= $h($pr['name']) ?></strong><span><?= $h($pr['copy']['desc']) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php foreach ($data['products'] as $i => $pr): ?>
      <div class="panel" role="tabpanel" id="prp-<?= $h($pr['key']) ?>" aria-labelledby="prt-<?= $h($pr['key']) ?>"<?= $i === 0 ? '' : ' hidden' ?>>
        <?php if ($pr['key'] === 'bms_shop'): ?><p class="samenote"><?= $icon('store', 18) ?><?= $h($T['pos_same']) ?></p><?php endif; ?>
        <?php if (count($pr['terms']) > 1): ?>
        <div style="text-align:center;margin-bottom:22px">
          <div class="toggle" role="group" aria-label="<?= $h($T['billing']) ?>">
            <?php foreach ($pr['terms'] as $tk => $tm): ?>
            <button type="button" aria-pressed="<?= $tk === $pr['default_term'] ? 'true' : 'false' ?>" data-term="<?= $h($tk) ?>"><?= $h($tm['label']) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="plans">
          <?php foreach ($pr['plans'] as $pl): ?>
          <div class="plan<?= $pl['popular'] ? ' popular' : '' ?>">
            <?php if ($pl['popular']): ?><p class="kick"><?= $h($T['popular']) ?></p><?php endif; ?>
            <h2 style="margin-top:6px;font-size:24px;font-weight:800"><?= $h($pl['name']) ?></h2>
            <?php if ($pl['tagline'] !== ''): ?><p class="per" style="margin-top:4px"><?= $h($pl['tagline']) ?></p><?php endif; ?>
            <?php foreach ($pr['terms'] as $tk => $tm): $px = $pl['prices'][$tk] ?? null; ?>
            <div class="price-t" data-term-price="<?= $h($tk) ?>"<?= $tk === $pr['default_term'] ? '' : ' hidden' ?>>
              <?php if ($px): ?>
              <p class="price">TZS <?= number_format($px['price']) ?></p>
              <p class="per"><?= $h($tm['label']) ?><?= $px['months'] > 1 ? ' · ≈ TZS ' . number_format($px['per_month']) . ' ' . $h($T['per_month']) : '' ?></p>
              <?php else: ?>
              <p class="price" style="font-size:20px;color:var(--muted)"><?= $h($T['not_term']) ?></p>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <p class="per" style="margin-top:2px"><?= $h($T['after_trial']) ?></p>
            <?php if ($canTrial): ?>
            <a class="btn <?= $pl['popular'] ? 'btn-blue' : 'btn-line' ?>" style="margin-top:18px" data-plan-link href="<?= $h($reg(['product' => $pr['key'], 'plan' => $pl['key'], 'term' => $pr['default_term']])) ?>"><?= $h($T['trial']) ?></a>
            <?php endif; ?>
            <div style="margin-top:20px;display:flex;flex-direction:column;gap:8px;font-size:15px">
              <?php foreach ($pl['feats'] as $f): ?><span style="display:flex;gap:8px;align-items:flex-start"><span style="color:var(--blue);margin-top:3px"><?= $icon('check', 16) ?></span><?= $h($f) ?></span><?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($pr['plans']) > 1 && $pr['compare']): ?>
        <h2 style="margin:40px 0 14px;font-size:24px;font-weight:800;text-align:center"><?= $h($T['compare']) ?> — <?= $h($pr['name']) ?></h2>
        <div style="overflow-x:auto;border:1px solid var(--line);border-radius:16px">
          <table class="cmp">
            <thead><tr><th scope="col" style="text-align:left"><?= $h($T['feature']) ?></th><?php foreach ($pr['plans'] as $pl): ?><th scope="col"><?= $h($pl['name']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
              <?php foreach ($pr['compare'] as $row): ?>
              <tr><th scope="row"><?= $h($row['label']) ?></th><?php foreach ($row['cells'] as $cell): ?><td style="color:<?= $cell === '✓' ? '#15803d' : ($cell === '—' ? '#94a3b8' : 'inherit') ?>"><?= $h($cell) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div class="grid" style="margin-top:44px">
        <?php foreach ($data['faqs']['billing'] as $q): ?>
        <div class="card" style="flex:1 1 300px;padding:22px"><strong style="font-size:17px"><?= $h($q[0]) ?></strong><p class="lead" style="font-size:15px;margin:8px 0 0"><?= $h($q[1]) ?></p></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    </div>
  </section>

<?php elseif ($path === 'faq'): /* ═════════════ FAQ ═════════════ */
  $cat = array_key_exists($_GET['topic'] ?? '', $data['faq_cats']) ? $_GET['topic'] : 'general';
?>
  <section class="sec">
    <div class="wrap">
      <h1 style="font-size:clamp(30px,4vw,46px);font-weight:800;letter-spacing:-1px"><?= $h($T['faq_title']) ?></h1>
      <p class="lead"><?= $h($T['faq_sub']) ?></p>
      <div class="grid" style="gap:32px;margin-top:32px;align-items:flex-start">
        <nav class="cats" style="flex:1 1 240px" aria-label="<?= $h($T['topics']) ?>">
          <?php foreach ($data['faq_cats'] as $k => $name): ?>
          <a href="<?= $h('/faq?topic=' . $k . ($sw ? '&lang=sw' : '')) ?>"<?= $k === $cat ? ' aria-current="true"' : '' ?>><?= $h($name) ?></a>
          <?php endforeach; ?>
          <div class="card" style="margin-top:16px;padding:18px;background:var(--tint);border:0">
            <strong><?= $h($T['still_q']) ?></strong>
            <p class="lead" style="font-size:14px;margin:4px 0 12px"><?= $h($T['still_q_sub']) ?></p>
            <a class="btn btn-blue" style="min-height:44px" href="<?= $h($url('contact')) ?>"><?= $h($T['contact']) ?></a>
          </div>
        </nav>
        <div style="flex:3 1 520px;min-width:0">
          <?php foreach ($data['faqs'][$cat] as $i => $q): ?>
          <details class="q"<?= $i === 0 ? ' open' : '' ?>><summary><?= $h($q[0]) ?></summary><p><?= $h($q[1]) ?></p></details>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

<?php elseif ($path === 'contact'): /* ═════════════ CONTACT ═════════════ */
  $hasAny = $site['whatsapp'] !== '' || $site['phone'] !== '' || $site['email'] !== '';
?>
  <section class="sec">
    <div class="wrap">
      <div class="split2">
        <div class="split-l">
          <h1 style="font-size:clamp(28px,3.6vw,40px);font-weight:800"><?= $h($T['contact_title']) ?></h1>
          <p style="color:#cbd5e1;margin:10px 0 0"><?= $h($T['contact_sub']) ?></p>
          <?php if ($site['whatsapp'] !== ''): ?><a class="ch" href="https://wa.me/<?= $h(ltrim($tel($site['whatsapp']), '+')) ?>" target="_blank" rel="noopener"><span class="ci">WA</span><span><strong style="display:block">WhatsApp</strong><span style="color:#cbd5e1"><?= $h($site['whatsapp']) ?></span></span></a><?php endif; ?>
          <?php if ($site['phone'] !== ''): ?><a class="ch" href="tel:<?= $h($tel($site['phone'])) ?>"><span class="ci">Tel</span><span><strong style="display:block"><?= $h($T['phone']) ?></strong><span style="color:#cbd5e1"><?= $h($site['phone']) ?></span></span></a><?php endif; ?>
          <?php if ($site['email'] !== ''): ?><a class="ch" href="mailto:<?= $h($site['email']) ?>"><span class="ci">@</span><span><strong style="display:block"><?= $h($T['email']) ?></strong><span style="color:#cbd5e1"><?= $h($site['email']) ?></span></span></a><?php endif; ?>
          <?php if ($site['hours'] !== ''): ?><div class="ch"><span class="ci"><?= $h($T['hrs']) ?></span><span><strong style="display:block"><?= $h($T['hours']) ?></strong><span style="color:#cbd5e1"><?= $h($site['hours']) ?></span></span></div><?php endif; ?>
          <p style="margin:28px 0 0;font-size:14px;color:#94a3b8"><?= $h($site['legal_name']) ?><?= $site['address'] !== '' ? ' · ' . $h($site['address']) : '' ?></p>
        </div>
        <div class="split-r">
          <?php if ($site['whatsapp'] !== '' || $site['email'] !== ''): ?>
          <h2 style="font-size:22px;font-weight:800"><?= $h($T['form_title']) ?></h2>
          <p class="lead" style="font-size:15px;margin:6px 0 0"><?= $h($site['whatsapp'] !== '' ? $T['form_sub_wa'] : $T['form_sub_mail']) ?></p>
          <form id="msgForm" data-wa="<?= $h(ltrim($tel($site['whatsapp']), '+')) ?>" data-mail="<?= $h($site['email']) ?>" novalidate>
            <label class="f" for="m-name"><?= $h($T['f_name']) ?></label><input class="in" id="m-name" autocomplete="name" required>
            <label class="f" for="m-biz"><?= $h($T['f_biz']) ?></label>
            <select class="in" id="m-biz"><?php foreach ($T['biz_opts'] as $o): ?><option><?= $h($o) ?></option><?php endforeach; ?></select>
            <label class="f" for="m-msg"><?= $h($T['f_msg']) ?></label><textarea class="in" id="m-msg" required></textarea>
            <p class="err" id="m-err" role="alert"></p>
            <button class="btn btn-blue" type="submit"><?= $h($site['whatsapp'] !== '' ? $T['send_wa'] : $T['send_mail']) ?> →</button>
          </form>
          <?php elseif (!$hasAny): ?>
          <h2 style="font-size:22px;font-weight:800"><?= $h($T['no_contacts']) ?></h2>
          <?php if ($canTrial): ?><a class="btn btn-blue" style="margin-top:14px" href="<?= $h($reg()) ?>"><?= $h($T['trial_long']) ?></a><?php endif; ?>
          <?php else: ?>
          <h2 style="font-size:22px;font-weight:800"><?= $h($T['call_us']) ?></h2>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>
</main>

<footer class="ftr">
  <div class="ftr-in">
    <div>
      <a class="fb" href="<?= $h($url('')) ?>"><?= $brandMark ?>Smart BMS</a>
      <p><?= $h($T['foot_about']) ?></p>
    </div>
    <div>
      <h2><?= $h($T['f_products']) ?></h2>
      <ul>
        <li><a href="<?= $h($url('smart-pos/retail')) ?>">Smart POS · <?= $h($T['m_ret']) ?></a></li>
        <li><a href="<?= $h($url('smart-pos/restaurant')) ?>">Smart POS · <?= $h($T['m_res']) ?></a></li>
        <li><a href="<?= $h($url('smart-bms')) ?>">Smart BMS · <?= $h($T['f_acct']) ?></a></li>
      </ul>
    </div>
    <div>
      <h2><?= $h($T['f_help']) ?></h2>
      <ul>
        <li><a href="<?= $h($url('pricing')) ?>"><?= $h($T['pricing']) ?></a></li>
        <li><a href="<?= $h($url('faq')) ?>"><?= $h($T['faq']) ?></a></li>
        <li><a href="<?= $h($url('contact')) ?>"><?= $h($T['contact']) ?></a></li>
      </ul>
    </div>
    <div>
      <h2><?= $h($T['f_talk']) ?></h2>
      <ul>
        <?php if ($site['whatsapp'] !== ''): ?><li><a href="https://wa.me/<?= $h(ltrim($tel($site['whatsapp']), '+')) ?>" target="_blank" rel="noopener">WhatsApp · <?= $h($site['whatsapp']) ?></a></li><?php endif; ?>
        <?php if ($site['phone'] !== ''): ?><li><a href="tel:<?= $h($tel($site['phone'])) ?>"><?= $h($T['phone']) ?> · <?= $h($site['phone']) ?></a></li><?php endif; ?>
        <?php if ($site['email'] !== ''): ?><li><a href="mailto:<?= $h($site['email']) ?>"><?= $h($site['email']) ?></a></li><?php endif; ?>
        <?php if ($site['whatsapp'] === '' && $site['phone'] === '' && $site['email'] === ''): ?><li><a href="<?= $h($url('contact')) ?>"><?= $h($T['contact']) ?></a></li><?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="ftr-bot">
    <div>
      <span>© <?= date('Y') ?> <?= $h($site['legal_name']) ?><?= $site['address'] !== '' ? ' · ' . $h($site['address']) : '' ?></span>
      <a href="/privacy"><?= $h($T['privacy']) ?></a>
    </div>
  </div>
</footer>

<!-- Log in: find the company by its web address, then its own login page (the login page itself is unchanged). -->
<div class="dlg" id="loginDlg" hidden>
  <div class="dlg-box" role="dialog" aria-modal="true" aria-labelledby="loginTitle">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <h2 id="loginTitle" style="font-size:22px;font-weight:800"><?= $h($T['login']) ?></h2>
      <button type="button" class="x" data-close-login aria-label="<?= $h($T['close']) ?>">×</button>
    </div>
    <?php if ($data['base_domain'] !== ''): ?>
    <form id="findCo" novalidate>
      <label class="f" for="co-sub"><?= $h($T['co_addr']) ?></label>
      <div class="addr"><input id="co-sub" autocomplete="off" autocapitalize="none" spellcheck="false" inputmode="url" placeholder="<?= $h($T['co_ph']) ?>"><span>.<?= $h($data['base_domain']) ?></span></div>
      <p class="err" id="co-err" role="alert"></p>
      <div class="found" id="co-found"><img id="co-logo" alt="" hidden><span style="flex:1"><strong id="co-name" style="display:block"></strong><span style="font-size:13px;color:var(--muted)" id="co-url"></span></span></div>
      <button class="btn btn-blue" type="submit" id="co-go" style="width:100%;margin-top:14px"><?= $h($T['co_find']) ?></button>
    </form>
    <p class="lead" style="font-size:14px;margin:16px 0 0;text-align:center"><?= $h($T['co_hint']) ?></p>
    <?php endif; ?>
    <p style="margin:16px 0 0;padding-top:14px;border-top:1px solid var(--line);text-align:center;font-size:14px"><a href="/login"><?= $h($T['staff_login']) ?></a></p>
  </div>
</div>

<script>
(function () {
  var L = <?= json_encode(['notFound' => $T['co_not_found'], 'bad' => $T['co_bad'], 'busy' => $T['co_busy'], 'go' => $T['co_go'], 'find' => $T['co_find'], 'need' => $T['m_need'], 'feed' => $T['feed']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };

  // ── Menus: Products (computer), phone menu, language ──
  var pairs = [['prodBtn', 'mega'], ['burger', 'sheet'], ['langBtn', 'langMenu']].map(function (p) { return [document.getElementById(p[0]), document.getElementById(p[1])]; })
    .filter(function (p) { return p[0] && p[1]; });
  function closeAll(except) { pairs.forEach(function (p) { if (p[1] !== except) { p[1].hidden = true; p[0].setAttribute('aria-expanded', 'false'); } }); }
  function openMenu(p) { closeAll(p[1]); p[1].hidden = false; p[0].setAttribute('aria-expanded', 'true'); }
  pairs.forEach(function (p) {
    p[0].addEventListener('click', function (e) { e.stopPropagation(); if (p[1].hidden) openMenu(p); else closeAll(); });
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('.pop, .sheet')) closeAll(); });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = pairs.filter(function (p) { return !p[1].hidden; })[0];
    closeAll(); if (open) open[0].focus();
  });
  var see = document.getElementById('seeProducts');
  if (see) see.addEventListener('click', function (e) {
    e.stopPropagation();
    var phone = window.getComputedStyle(document.getElementById('burger')).display !== 'none';
    var p = pairs.filter(function (x) { return x[1].id === (phone ? 'sheet' : 'mega'); })[0];
    window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
    if (p) { openMenu(p); var first = p[1].querySelector('a'); if (first) setTimeout(function () { first.focus({ preventScroll: true }); }, 50); }
  });

  // ── Home: the headline word turns, the "new sale" card changes ──
  var rot = document.getElementById('rot');
  if (rot && !reduce) {
    var words = rot.children, wi = 0;
    var fit = function (el) { rot.style.width = el.offsetWidth + 'px'; };
    fit(words[0]);
    setInterval(function () {
      var cur = words[wi]; wi = (wi + 1) % words.length; var nx = words[wi];
      cur.classList.remove('on'); cur.classList.add('out'); cur.setAttribute('aria-hidden', 'true');
      nx.classList.remove('out'); nx.classList.add('on'); nx.removeAttribute('aria-hidden');
      fit(nx);
      setTimeout(function () { cur.classList.remove('out'); }, 600);
    }, 2600);
    window.addEventListener('resize', function () { fit(words[wi]); });
  }
  var toast = document.getElementById('toast');
  if (toast && !reduce && L.feed.length > 1) {
    var fi = 0;
    setInterval(function () {
      toast.classList.add('hide');
      setTimeout(function () { fi = (fi + 1) % L.feed.length; document.getElementById('toastT').textContent = L.feed[fi][0]; document.getElementById('toastV').textContent = L.feed[fi][1]; toast.classList.remove('hide'); }, 450);
    }, 3200);
  }
  // Numbers count up from 0 (the page already shows the final figure without JavaScript).
  if (!reduce) document.querySelectorAll('.count').forEach(function (el) {
    var to = +el.getAttribute('data-to'), t0 = null;
    el.textContent = '0';
    function step(t) { if (!t0) t0 = t; var p = Math.min(1, (t - t0) / 1400); el.textContent = fmt(to * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); }
    setTimeout(function () { requestAnimationFrame(step); }, 500);
  });
  // Retail: the example sale rings up line by line.
  var till = document.getElementById('tillRows');
  if (till && !reduce) {
    var rows = Array.prototype.slice.call(till.children), tot = document.getElementById('tillTot'), sum = 0;
    rows.forEach(function (r) { r.hidden = true; }); tot.textContent = '0';
    rows.forEach(function (r, i) { setTimeout(function () { r.hidden = false; sum += +r.getAttribute('data-amt'); tot.textContent = fmt(sum); }, 500 + i * 650); });
  }

  // ── Pricing: product tabs and price terms ──
  document.querySelectorAll('[role=tablist]').forEach(function (list) {
    var tabs = list.querySelectorAll('[role=tab]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); var p = document.getElementById(t.getAttribute('aria-controls')); if (p) p.hidden = t !== tab; });
      });
    });
  });
  document.querySelectorAll('[data-term]').forEach(function (b) {
    b.addEventListener('click', function () {
      var panel = b.closest('.panel'), term = b.getAttribute('data-term');
      panel.querySelectorAll('[data-term]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      panel.querySelectorAll('[data-term-price]').forEach(function (p) { p.hidden = p.getAttribute('data-term-price') !== term; });
      // the sign-up link carries the term shown, so the plan card on sign-up matches
      panel.querySelectorAll('[data-plan-link]').forEach(function (a) { var u = new URL(a.href, location.href); u.searchParams.set('term', term); a.href = u.pathname + u.search; });
    });
  });

  // ── Log in window ──
  var dlg = document.getElementById('loginDlg');
  function openLogin() { closeAll(); dlg.hidden = false; var i = document.getElementById('co-sub'); if (i) i.focus(); }
  function closeLogin() { dlg.hidden = true; }
  document.querySelectorAll('[data-open-login]').forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); openLogin(); }); });
  document.querySelectorAll('[data-close-login]').forEach(function (b) { b.addEventListener('click', closeLogin); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) closeLogin(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !dlg.hidden) closeLogin(); });
  var f = document.getElementById('findCo'), found = null;
  if (f) {
    var inp = document.getElementById('co-sub'), err = document.getElementById('co-err'), go = document.getElementById('co-go'), box = document.getElementById('co-found');
    inp.addEventListener('input', function () { found = null; box.classList.remove('on'); go.textContent = L.find; err.textContent = ''; });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (found) { window.location.href = found; return; }
      var v = inp.value.trim().toLowerCase().replace(/\..*$/, '');
      if (!/^[a-z0-9][a-z0-9-]{0,37}[a-z0-9]$|^[a-z0-9]{1,2}$/.test(v)) { err.textContent = L.bad; return; }
      go.disabled = true; err.textContent = '';
      fetch('/api/mobile/tenant_info.php?subdomain=' + encodeURIComponent(v), { credentials: 'omit' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.success) { err.textContent = (d && d.message) ? L.notFound : L.busy; return; }
          found = d.tenant_url.replace(/\/+$/, '') + '/login';
          document.getElementById('co-name').textContent = d.company_name;
          document.getElementById('co-url').textContent = d.tenant_url.replace(/^https?:\/\//, '');
          var img = document.getElementById('co-logo'); if (d.logo_url) { img.src = d.logo_url; img.hidden = false; } else { img.hidden = true; }
          box.classList.add('on'); go.textContent = L.go;
        })
        .catch(function () { err.textContent = L.busy; })
        .then(function () { go.disabled = false; });
    });
  }
  // ── Contact: the message opens WhatsApp (or email) with the text ready — nothing is stored on this site ──
  var m = document.getElementById('msgForm');
  if (m) m.addEventListener('submit', function (e) {
    e.preventDefault();
    var name = document.getElementById('m-name').value.trim(), msg = document.getElementById('m-msg').value.trim(), biz = document.getElementById('m-biz').value;
    if (!name || !msg) { document.getElementById('m-err').textContent = L.need; return; }
    var text = name + ' (' + biz + '):\n' + msg;
    var wa = m.getAttribute('data-wa'), mail = m.getAttribute('data-mail');
    if (wa) window.open('https://wa.me/' + wa + '?text=' + encodeURIComponent(text), '_blank', 'noopener');
    else window.location.href = 'mailto:' + mail + '?subject=' + encodeURIComponent('Smart BMS — ' + name) + '&body=' + encodeURIComponent(text);
  });
})();
</script>
</body>
</html>
