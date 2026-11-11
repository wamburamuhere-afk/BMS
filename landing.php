<?php
/**
 * landing.php — the public website on the platform's own address (Option B "Showcase", 2026-11-11).
 *
 * Pages: /  /smart-pos  /smart-bms  /pricing  /faq  /contact  (English; ?lang=sw Kiswahili).
 * Only on the platform root (bms.… / demo.…): a company's own address and the Superadmin address get
 * a 404 here, and index.php keeps sending a company's "/" to its login.
 *
 * Nothing is typed in by hand that the system already holds:
 *   products, plans, prices, terms, limits, plan items ......... listPublicOffers() (Superadmin › Plans)
 *   free-trial length, whether sign-up is open ................ tenantDefaultTrialDays(), selfRegistrationClosedReason()
 *   phone, WhatsApp, email, hours ............................ Superadmin › Support settings
 *   legal name, address, main website ........................ Superadmin › Platform settings › Branding
 *   product wording .......................................... core/product_copy.php (shared with register.php)
 * The login page and the sign-up page are unchanged; this page only links to them.
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
$signupUrl = '/register' . ($sw ? '?lang=sw' : '');
$canTrial  = $data['signup_open'];

// ── SEO for this page ────────────────────────────────────────────────────────
$meta = landingMeta($path, $sw, $data, $site);
header('Content-Type: text/html; charset=utf-8');
if (!$site['indexable']) header('X-Robots-Tag: noindex, follow');
$jsonLd = landingJsonLd($path, $sw, $data, $site, $abs);
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
<link rel="icon" type="image/png" href="/assets/images/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php foreach ($jsonLd as $ld): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach; ?>
<style>
:root{--ink:#0f172a;--muted:#475569;--line:#e2e8f0;--soft:#f8fafc;--blue:#1d4ed8;--blue-d:#1e3a8a;--tint:#eff6ff;--navy:#0f172a}
*{box-sizing:border-box}
body{margin:0;background:#fff;color:var(--ink);font-family:'Plus Jakarta Sans',system-ui,-apple-system,'Segoe UI',sans-serif;line-height:1.55}
a{color:var(--blue)}a:hover{color:var(--blue-d)}
img{max-width:100%}
.wrap{max-width:1240px;margin:0 auto;padding:0 24px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 22px;border-radius:999px;font:inherit;font-weight:800;font-size:16px;text-decoration:none;cursor:pointer;border:0}
.btn-blue{background:var(--blue);color:#fff}.btn-blue:hover{background:var(--blue-d);color:#fff}
.btn-dark{background:var(--navy);color:#fff}.btn-dark:hover{background:#1e293b;color:#fff}
.btn-line{background:#fff;color:var(--ink);border:1px solid #cbd5e1}.btn-line:hover{border-color:var(--blue);color:var(--blue)}
.kicker{margin:0;font-size:13px;font-weight:800;letter-spacing:1.5px;color:var(--blue);text-transform:uppercase}
h1,h2,h3{letter-spacing:-.5px;margin:0}
.lead{font-size:18px;color:var(--muted)}
.sec{padding:72px 0}.sec-soft{background:var(--soft);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.grid{display:flex;flex-wrap:wrap;gap:16px}
.card{border:1px solid var(--line);border-radius:20px;background:#fff;padding:26px}
.tick{display:flex;gap:10px;align-items:flex-start}.tick svg{flex:0 0 auto;margin-top:4px}
/* header */
.hdr{position:sticky;top:0;z-index:30;background:rgba(255,255,255,.97);border-bottom:1px solid var(--line)}
.hdr-in{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:12px 24px;max-width:1240px;margin:0 auto}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--ink);font-weight:800;font-size:19px;flex:1 1 0}
.brand-mark{width:34px;height:34px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center}
.pillnav{display:flex;gap:2px;background:#f1f5f9;border-radius:999px;padding:4px}
.pillnav a{min-height:40px;padding:0 16px;border-radius:999px;display:flex;align-items:center;font-size:14px;font-weight:700;color:#334155;text-decoration:none;white-space:nowrap}
.pillnav a[aria-current=page]{background:#fff;color:var(--blue);box-shadow:0 1px 3px rgba(15,23,42,.12)}
.hdr-act{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex:1 1 0}
.lang{display:flex;border:1px solid #cbd5e1;border-radius:999px;padding:3px}
.lang a{min-width:40px;min-height:34px;display:flex;align-items:center;justify-content:center;border-radius:999px;font-size:13px;font-weight:800;color:#334155;text-decoration:none}
.lang a[aria-current=true]{background:var(--navy);color:#fff}
.linkbtn{background:none;border:0;font:inherit;font-weight:700;color:var(--ink);cursor:pointer;min-height:44px;padding:0 10px}
.mnav{display:none;width:100%;gap:6px;overflow-x:auto;padding-bottom:2px;scrollbar-width:none}
.mnav::-webkit-scrollbar{display:none}
.mnav a{flex:0 0 auto;min-height:40px;padding:0 14px;border-radius:999px;border:1px solid var(--line);display:flex;align-items:center;font-size:14px;font-weight:700;color:#334155;text-decoration:none}
.mnav a[aria-current=page]{background:var(--tint);border-color:var(--blue);color:var(--blue)}
/* hero */
.hero{text-align:center;padding:72px 0 0}
.hero h1{font-size:clamp(34px,5.4vw,60px);line-height:1.05;font-weight:800;letter-spacing:-1.8px;max-width:880px;margin:0 auto}
.badge{display:inline-block;margin:0 0 18px;padding:6px 14px;border-radius:999px;background:var(--tint);color:#1e40af;font-size:13px;font-weight:700}
.points{display:flex;gap:18px;justify-content:center;flex-wrap:wrap;margin-top:18px;font-size:14px;font-weight:600;color:var(--muted)}
.screen{margin:48px auto 0;max-width:1080px;border:1px solid var(--line);border-bottom:0;border-radius:18px 18px 0 0;overflow:hidden;text-align:left;box-shadow:0 -8px 40px rgba(15,23,42,.06)}
.screen-bar{display:flex;gap:6px;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--soft)}
.screen-bar span{width:10px;height:10px;border-radius:50%;background:#cbd5e1}
.screen-body{display:flex}
.screen-side{flex:0 0 200px;padding:18px;border-right:1px solid var(--line);display:flex;flex-direction:column;gap:4px;font-size:14px;color:var(--muted)}
.screen-side span{padding:9px 12px;border-radius:8px}.screen-side span:first-child{background:var(--tint);color:var(--blue);font-weight:700}
.screen-main{flex:1 1 auto;min-width:0;padding:22px;display:flex;flex-direction:column;gap:14px}
.kpi{flex:1 1 160px;border:1px solid var(--line);border-radius:12px;padding:14px}
.kpi p{margin:0}.kpi .v{margin-top:6px;font-size:22px;font-weight:800}
.bars{display:flex;align-items:flex-end;gap:8px;height:110px;border:1px solid var(--line);border-radius:12px;padding:14px}
.bars i{flex:1;border-radius:4px;background:#dbeafe}
.ex{font-size:11px;font-weight:700;color:var(--muted);background:#f1f5f9;padding:3px 9px;border-radius:999px}
/* segments / tabs */
.seg-row{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:28px}
.seg{min-width:190px;min-height:70px;padding:12px 20px;border-radius:16px;border:1px solid #cbd5e1;background:#fff;font:inherit;color:var(--ink);text-align:left;cursor:pointer}
.seg[aria-selected=true]{border:2px solid var(--blue);box-shadow:0 0 0 4px #dbeafe}
.seg strong{display:block;font-size:18px}.seg span{display:block;font-size:13px;color:var(--muted)}
.panel[hidden]{display:none}
.feat{flex:1 1 230px;padding:14px 16px;border-radius:12px;background:var(--soft);font-size:15px;font-weight:600}
/* pricing */
.toggle{display:inline-flex;background:#f1f5f9;border-radius:999px;padding:4px;flex-wrap:wrap}
.toggle button{min-height:44px;padding:0 18px;border:0;border-radius:999px;background:transparent;font:inherit;font-weight:800;color:var(--muted);cursor:pointer}
.toggle button[aria-pressed=true]{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(15,23,42,.12)}
.plans{display:flex;flex-wrap:wrap;border:1px solid var(--line);border-radius:24px;overflow:hidden}
.plan{flex:1 1 280px;padding:30px;display:flex;flex-direction:column;background:#fff;border-right:1px solid var(--line)}
.plan:last-child{border-right:0}.plan.pop{background:var(--tint)}
.plan .price{margin:16px 0 0;font-size:32px;font-weight:800}
.plan .per{font-size:14px;color:var(--muted)}
.price-t[hidden]{display:none}
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
.cats > a[aria-current=true]{background:var(--navy);color:#fff}
/* contact */
.split{display:flex;flex-wrap:wrap;border:1px solid var(--line);border-radius:26px;overflow:hidden}
.split-l{flex:1 1 360px;background:var(--navy);color:#fff;padding:40px}
.split-r{flex:1 1 440px;min-width:0;padding:40px}
.ch{display:flex;gap:14px;align-items:center;margin-top:18px;color:#fff;text-decoration:none}
.ch span.i{width:44px;height:44px;border-radius:50%;background:#1e293b;color:#93c5fd;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;flex:0 0 auto}
label.f{display:block;margin-top:14px;font-size:14px;font-weight:700}
.in{width:100%;min-height:48px;margin-top:6px;padding:0 16px;border:1px solid #94a3b8;border-radius:999px;font:inherit;background:#fff}
textarea.in{min-height:110px;padding:12px 16px;border-radius:16px;resize:vertical}
/* footer */
.ftr{background:var(--navy);color:#cbd5e1}
.ftr a{color:#cbd5e1}
/* dialog */
.dlg{position:fixed;inset:0;z-index:60;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px}
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
@media (max-width:760px){
  .pillnav{display:none}.mnav{display:flex}.screen-side{display:none}
  .split-l,.split-r{padding:28px}.plan{border-right:0;border-bottom:1px solid var(--line)}
  .sec{padding:52px 0}.hero{padding-top:48px}
  .brand,.hdr-act{flex:1 1 auto}
}
</style>
</head>
<body>
<header class="hdr">
  <div class="hdr-in">
    <a class="brand" href="<?= $h($url('')) ?>" aria-label="Smart BMS — <?= $h($T['home']) ?>">
      <span class="brand-mark"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19V9"/><path d="M10 19V5"/><path d="M16 19v-7"/><path d="M22 19H2"/></svg></span>
      Smart BMS
    </a>
    <nav class="pillnav" aria-label="<?= $h($T['menu']) ?>">
      <?php foreach ($pages as $p => [$en, $s]): ?>
      <a href="<?= $h($url($p)) ?>"<?= $p === $path ? ' aria-current="page"' : '' ?>><?= $h($sw ? $s : $en) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="hdr-act">
      <div class="lang" role="group" aria-label="Language / Lugha">
        <a href="<?= $h($url($path, 'en')) ?>" hreflang="en"<?= !$sw ? ' aria-current="true"' : '' ?>>EN</a>
        <a href="<?= $h($url($path, 'sw')) ?>" hreflang="sw"<?= $sw ? ' aria-current="true"' : '' ?>>SW</a>
      </div>
      <button type="button" class="linkbtn" data-open-login><?= $h($T['login']) ?></button>
      <?php if ($canTrial): ?>
      <a class="btn btn-dark" style="min-height:44px;padding:0 18px;font-size:15px" href="<?= $h($signupUrl) ?>"><?= $h($T['trial']) ?></a>
      <?php else: ?>
      <a class="btn btn-dark" style="min-height:44px;padding:0 18px;font-size:15px" href="<?= $h($url('contact')) ?>"><?= $h($T['contact']) ?></a>
      <?php endif; ?>
    </div>
    <nav class="mnav" aria-label="<?= $h($T['menu']) ?>">
      <?php foreach ($pages as $p => [$en, $s]): ?>
      <a href="<?= $h($url($p)) ?>"<?= $p === $path ? ' aria-current="page"' : '' ?>><?= $h($sw ? $s : $en) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</header>

<main id="main">
<?php if ($path === ''): /* ═════════════ HOME ═════════════ */ ?>
  <section class="hero">
    <div class="wrap">
      <p class="badge"><?= $h($T['badge']) ?></p>
      <h1><?= $h($T['hero_title']) ?></h1>
      <p class="lead" style="max-width:640px;margin:20px auto 0"><?= $h($T['hero_sub']) ?></p>
      <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:30px">
        <?php if ($canTrial): ?><a class="btn btn-blue" style="min-height:54px;padding:0 28px" href="<?= $h($signupUrl) ?>"><?= $h($T['trial_long']) ?></a><?php endif; ?>
        <a class="btn btn-line" style="min-height:54px;padding:0 26px" href="<?= $h($url('smart-pos')) ?>"><?= $h($T['see_how']) ?></a>
      </div>
      <div class="points"><?php foreach ($T['points'] as $pt): ?><span>✓ <?= $h($pt) ?></span><?php endforeach; ?></div>
      <div class="screen" aria-label="<?= $h($T['example']) ?>">
        <div class="screen-bar" aria-hidden="true"><span></span><span></span><span></span></div>
        <div class="screen-body">
          <div class="screen-side" aria-hidden="true"><?php foreach ($T['app_menu'] as $m): ?><span><?= $h($m) ?></span><?php endforeach; ?></div>
          <div class="screen-main">
            <div style="display:flex;justify-content:space-between;align-items:center"><strong style="font-size:18px"><?= $h($T['today']) ?></strong><span class="ex"><?= $h($T['example']) ?></span></div>
            <div class="grid" style="gap:12px">
              <div class="kpi"><p class="lead" style="font-size:12px"><?= $h($T['k_sales']) ?></p><p class="v">TZS 1,365,000</p></div>
              <div class="kpi"><p class="lead" style="font-size:12px"><?= $h($T['k_profit']) ?></p><p class="v">TZS 412,500</p></div>
              <div class="kpi"><p class="lead" style="font-size:12px"><?= $h($T['k_credit']) ?></p><p class="v">TZS 86,000</p></div>
            </div>
            <div class="bars" aria-hidden="true"><i style="height:35%"></i><i style="height:50%"></i><i style="height:42%"></i><i style="height:64%"></i><i style="height:58%;background:#bfdbfe"></i><i style="height:76%;background:#93c5fd"></i><i style="height:100%;background:var(--blue)"></i></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="sec sec-soft">
    <div class="wrap">
      <h2 style="text-align:center;font-size:clamp(28px,3.6vw,42px);font-weight:800"><?= $h($T['built_title']) ?></h2>
      <div class="seg-row" role="tablist" aria-label="<?= $h($T['built_title']) ?>">
        <?php foreach ($data['segments'] as $i => $g): ?>
        <button type="button" class="seg" role="tab" id="segt-<?= $h($g['key']) ?>" aria-controls="segp-<?= $h($g['key']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-tab="seg">
          <strong><?= $h($g['name']) ?></strong><span><?= $h($g['product']) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php foreach ($data['segments'] as $i => $g): ?>
      <div class="panel card" role="tabpanel" id="segp-<?= $h($g['key']) ?>" aria-labelledby="segt-<?= $h($g['key']) ?>" style="margin-top:24px"<?= $i === 0 ? '' : ' hidden' ?>>
        <div class="grid" style="gap:28px">
          <div style="flex:1 1 320px">
            <h3 style="font-size:26px;font-weight:800"><?= $h($g['head']) ?></h3>
            <p class="lead" style="font-size:16px"><?= $h($g['sub']) ?></p>
            <a class="btn btn-blue" style="margin-top:8px" href="<?= $h($url($g['page'])) ?>"><?= $h($T['learn_more']) ?> — <?= $h($g['product']) ?></a>
          </div>
          <div class="grid" style="flex:2 1 460px;gap:10px">
            <?php foreach ($g['feats'] as $f): ?><span class="feat">✓ <?= $h($f) ?></span><?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="sec">
    <div class="wrap">
      <div class="grid" style="gap:28px">
        <?php foreach ($T['values'] as $i => $v): ?>
        <div style="flex:1 1 280px">
          <span style="width:48px;height:48px;border-radius:14px;background:var(--tint);color:var(--blue);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800"><?= $i + 1 ?></span>
          <h3 style="margin-top:16px;font-size:20px;font-weight:800"><?= $h($v[0]) ?></h3>
          <p class="lead" style="font-size:15px"><?= $h($v[1]) ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:56px;text-align:center;border-top:1px solid var(--line);padding-top:48px">
        <h2 style="font-size:clamp(26px,3.2vw,36px);font-weight:800"><?= $h($T['band_title']) ?></h2>
        <p class="lead"><?= $h($canTrial ? $T['band_sub'] : $T['band_sub_closed']) ?></p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:18px">
          <?php if ($canTrial): ?><a class="btn btn-blue" href="<?= $h($signupUrl) ?>"><?= $h($T['trial_long']) ?></a><?php endif; ?>
          <a class="btn btn-line" href="<?= $h($url('pricing')) ?>"><?= $h($T['see_prices']) ?></a>
        </div>
      </div>
    </div>
  </section>

<?php elseif ($path === 'smart-pos' || $path === 'smart-bms'): /* ═════════════ PRODUCT ═════════════ */
  $pk = $path === 'smart-pos' ? 'bms_shop' : 'bms_full';
  $pc = bmsProductCopy($sw)[$pk];
  $offer = $data['products_by_key'][$pk] ?? null;
  $pname = $offer['name'] ?? ($pk === 'bms_shop' ? 'Smart POS' : 'Smart BMS');
?>
  <section class="sec sec-soft" style="padding-top:56px">
    <div class="wrap">
      <p class="lead" style="font-size:14px;margin:0"><a href="<?= $h($url('')) ?>"><?= $h($T['home']) ?></a> / <?= $h($pname) ?></p>
      <p class="kicker" style="margin-top:18px"><?= $h($pc['tag']) ?></p>
      <h1 style="margin-top:8px;font-size:clamp(32px,4.4vw,52px);font-weight:800;letter-spacing:-1.2px"><?= $h($pname) ?> — <?= $h($pc['head']) ?></h1>
      <p class="lead" style="max-width:720px"><?= $h($pc['sub']) ?></p>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:22px">
        <?php if ($canTrial && $offer): ?><a class="btn btn-blue" href="<?= $h('/register?product=' . rawurlencode($pk) . ($sw ? '&lang=sw' : '')) ?>"><?= $h($T['start_with']) ?> <?= $h($pname) ?></a><?php endif; ?>
        <a class="btn btn-line" href="<?= $h($url($offer ? 'pricing' : 'contact')) ?>"><?= $h($offer ? $T['see_prices'] : $T['ask_price']) ?></a>
      </div>
      <?php if ($pk === 'bms_full'): ?><p class="lead" style="font-size:14px;margin-top:14px"><?= $h($T['bms_approval']) ?></p><?php endif; ?>
    </div>
  </section>
  <section class="sec">
    <div class="wrap">
      <h2 style="font-size:28px;font-weight:800"><?= $h($T['what_you_get']) ?></h2>
      <div class="grid" style="margin-top:22px">
        <?php foreach ($pc['benefits'] as $b): ?>
        <div class="card tick" style="flex:1 1 300px;padding:20px"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg><span style="font-weight:600"><?= $h($b) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php if ($pk === 'bms_shop'): ?>
      <div class="card" style="margin-top:32px;display:flex;flex-wrap:wrap;gap:24px;align-items:center">
        <div style="flex:1 1 340px">
          <p class="kicker" style="color:#c2410c"><?= $h($T['rest_kicker']) ?></p>
          <h3 style="margin-top:6px;font-size:24px;font-weight:800"><?= $h($T['rest_title']) ?></h3>
          <p class="lead" style="font-size:16px"><?= $h($T['rest_sub']) ?></p>
        </div>
        <div style="flex:1 1 380px;display:flex;flex-wrap:wrap;gap:8px">
          <?php foreach ($T['rest_list'] as $r): ?><span style="padding:9px 14px;border-radius:999px;background:#fff7ed;color:#9a3412;font-size:14px;font-weight:700"><?= $h($r) ?></span><?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <h2 style="margin-top:48px;font-size:28px;font-weight:800"><?= $h($T['first_steps']) ?></h2>
      <ol class="grid" style="list-style:none;padding:0;margin:22px 0 0">
        <?php foreach ($pc['first'] as $i => $s): ?>
        <li class="card" style="flex:1 1 240px;padding:20px"><span style="width:36px;height:36px;border-radius:50%;background:var(--navy);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800"><?= $i + 1 ?></span><p style="margin:12px 0 0;font-weight:700"><?= $h($s) ?></p></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

<?php elseif ($path === 'pricing'): /* ═════════════ PRICING ═════════════ */ ?>
  <section class="sec" style="padding-bottom:24px;text-align:center">
    <div class="wrap">
      <h1 style="font-size:clamp(30px,4vw,46px);font-weight:800;letter-spacing:-1px"><?= $h($T['price_title']) ?></h1>
      <p class="lead"><?= $h($T['price_sub']) ?></p>
    </div>
  </section>
  <section style="padding-bottom:72px">
    <div class="wrap">
    <?php if (!$data['products']): ?>
      <div class="card" style="text-align:center">
        <h2 style="font-size:22px;font-weight:800"><?= $h($T['no_prices']) ?></h2>
        <p class="lead"><?= $h($T['no_prices_sub']) ?></p>
        <a class="btn btn-blue" href="<?= $h($url('contact')) ?>"><?= $h($T['contact']) ?></a>
      </div>
    <?php else: ?>
      <?php if (count($data['products']) > 1): ?>
      <div class="seg-row" role="tablist" aria-label="<?= $h($T['products']) ?>" style="margin-top:0;margin-bottom:26px">
        <?php foreach ($data['products'] as $i => $pr): ?>
        <button type="button" class="seg" role="tab" id="prt-<?= $h($pr['key']) ?>" aria-controls="prp-<?= $h($pr['key']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-tab="prod">
          <strong><?= $h($pr['name']) ?></strong><span><?= $h($pr['copy']['desc']) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php foreach ($data['products'] as $i => $pr): ?>
      <div class="panel" role="tabpanel" id="prp-<?= $h($pr['key']) ?>" aria-labelledby="prt-<?= $h($pr['key']) ?>"<?= $i === 0 ? '' : ' hidden' ?>>
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
          <div class="plan<?= $pl['popular'] ? ' pop' : '' ?>">
            <?php if ($pl['popular']): ?><p class="kicker"><?= $h($T['popular']) ?></p><?php endif; ?>
            <h2 style="margin-top:6px;font-size:24px;font-weight:800"><?= $h($pl['name']) ?></h2>
            <?php if ($pl['tagline'] !== ''): ?><p class="lead" style="font-size:14px;margin:4px 0 0"><?= $h($pl['tagline']) ?></p><?php endif; ?>
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
            <a class="btn <?= $pl['popular'] ? 'btn-blue' : 'btn-line' ?>" style="margin-top:18px" href="<?= $h('/register?product=' . rawurlencode($pr['key']) . '&plan=' . rawurlencode($pl['key']) . ($sw ? '&lang=sw' : '')) ?>"><?= $h($T['trial']) ?></a>
            <?php endif; ?>
            <div style="margin-top:20px;display:flex;flex-direction:column;gap:8px;font-size:15px">
              <?php foreach ($pl['feats'] as $f): ?><span>✓ <?= $h($f) ?></span><?php endforeach; ?>
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
      <div class="split">
        <div class="split-l">
          <h1 style="font-size:clamp(28px,3.6vw,40px);font-weight:800"><?= $h($T['contact_title']) ?></h1>
          <p style="color:#cbd5e1;margin:10px 0 0"><?= $h($T['contact_sub']) ?></p>
          <?php if ($site['whatsapp'] !== ''): ?><a class="ch" href="https://wa.me/<?= $h(ltrim($tel($site['whatsapp']), '+')) ?>" target="_blank" rel="noopener"><span class="i">WA</span><span><strong style="display:block">WhatsApp</strong><span style="color:#cbd5e1"><?= $h($site['whatsapp']) ?></span></span></a><?php endif; ?>
          <?php if ($site['phone'] !== ''): ?><a class="ch" href="tel:<?= $h($tel($site['phone'])) ?>"><span class="i">Tel</span><span><strong style="display:block"><?= $h($T['phone']) ?></strong><span style="color:#cbd5e1"><?= $h($site['phone']) ?></span></span></a><?php endif; ?>
          <?php if ($site['email'] !== ''): ?><a class="ch" href="mailto:<?= $h($site['email']) ?>"><span class="i">@</span><span><strong style="display:block">Email</strong><span style="color:#cbd5e1"><?= $h($site['email']) ?></span></span></a><?php endif; ?>
          <?php if ($site['hours'] !== ''): ?><div class="ch"><span class="i"><?= $h($T['hrs']) ?></span><span><strong style="display:block"><?= $h($T['hours']) ?></strong><span style="color:#cbd5e1"><?= $h($site['hours']) ?></span></span></div><?php endif; ?>
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
          <?php if ($canTrial): ?><a class="btn btn-blue" style="margin-top:14px" href="<?= $h($signupUrl) ?>"><?= $h($T['trial_long']) ?></a><?php endif; ?>
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
  <div class="wrap" style="padding:40px 24px;display:flex;flex-wrap:wrap;gap:28px;justify-content:space-between;align-items:center">
    <div><strong style="font-size:18px;color:#fff">Smart BMS</strong><p style="margin:6px 0 0;font-size:14px;max-width:360px"><?= $h($T['foot_about']) ?></p></div>
    <nav style="display:flex;gap:4px;flex-wrap:wrap" aria-label="<?= $h($T['menu']) ?>">
      <?php foreach ($pages as $p => [$en, $s]): ?><a href="<?= $h($url($p)) ?>" style="min-height:44px;padding:0 10px;display:flex;align-items:center;font-size:14px;text-decoration:none"><?= $h($sw ? $s : $en) ?></a><?php endforeach; ?>
    </nav>
  </div>
  <div style="border-top:1px solid #1e293b;padding:16px 24px;display:flex;flex-wrap:wrap;gap:14px;justify-content:center;font-size:13px">
    <span>© <?= date('Y') ?> <?= $h($site['legal_name']) ?></span>
    <a href="/privacy"><?= $h($T['privacy']) ?></a>
    <a href="/login"><?= $h($T['staff_login']) ?></a>
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
  var L = <?= json_encode(['notFound' => $T['co_not_found'], 'bad' => $T['co_bad'], 'busy' => $T['co_busy'], 'go' => $T['co_go'], 'find' => $T['co_find'], 'need' => $T['m_need']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  // Tabs (segments on Home, products on Pricing): every panel is in the page; buttons only switch which one shows.
  document.querySelectorAll('[role=tablist]').forEach(function (list) {
    var tabs = list.querySelectorAll('[role=tab]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); var p = document.getElementById(t.getAttribute('aria-controls')); if (p) p.hidden = t !== tab; });
      });
    });
  });
  // Price terms: show the chosen term's price on every plan of that product.
  document.querySelectorAll('[data-term]').forEach(function (b) {
    b.addEventListener('click', function () {
      var panel = b.closest('.panel'), term = b.getAttribute('data-term');
      panel.querySelectorAll('[data-term]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      panel.querySelectorAll('[data-term-price]').forEach(function (p) { p.hidden = p.getAttribute('data-term-price') !== term; });
    });
  });
  // Log in dialog.
  var dlg = document.getElementById('loginDlg');
  function openLogin() { dlg.hidden = false; var i = document.getElementById('co-sub'); if (i) i.focus(); }
  function closeLogin() { dlg.hidden = true; }
  document.querySelectorAll('[data-open-login]').forEach(function (b) { b.addEventListener('click', openLogin); });
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
  // Contact: the message opens WhatsApp (or email) with the text ready — nothing is stored on this site.
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
