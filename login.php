<?php
// pages/login.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard');
    exit;
}

// ── Language detection: URL param → cookie → session → default 'en' ──────────
$pageLang = 'en';
if (!empty($_GET['lang']) && in_array($_GET['lang'], ['en', 'sw'], true)) {
    $pageLang = $_GET['lang'];
    setcookie('bms_lang', $pageLang, time() + 365 * 24 * 3600, '/');
} elseif (!empty($_COOKIE['bms_lang']) && in_array($_COOKIE['bms_lang'], ['en', 'sw'], true)) {
    $pageLang = $_COOKIE['bms_lang'];
}

$tr = $pageLang === 'sw' ? [
    'page_title'        => 'Ingia | Mfumo wa Usimamizi wa Biashara',
    'sign_in_prompt'    => 'Tafadhali ingia kuendelea',
    'username'          => 'Jina la Mtumiaji',
    'username_ph'       => 'Andika jina lako la mtumiaji',
    'password'          => 'Nenosiri',
    'password_ph'       => 'Andika nenosiri lako',
    'remember_me'       => 'Nikumbuke',
    'forgot_pw'         => 'Umesahau nenosiri?',
    'login_btn'         => 'Ingia',
    'logging_in'        => 'Ingia...',
    'new_company'       => 'Kampuni mpya?',
    'register_here'     => 'Jisajili hapa',
    'privacy'           => 'Sera ya Faragha',
    'terms'             => 'Masharti ya Huduma',
    'help'              => 'Msaada',
    'login_failed'      => 'Imeshindwa Kuingia',
    'invalid_creds'     => 'Jina la mtumiaji au nenosiri si sahihi.',
    'server_error'      => 'Hitilafu ya seva. Tafadhali jaribu tena.',
    'modal_back'        => 'Rudi Nyuma',
    'modal_continue'    => 'Endelea Kujisajili',
    'lang_label'        => 'Lugha',
    'modal_tagline'     => 'Simamia Duka Lako kwa Urahisi na Ufanisi',
    'modal_desc'        => '<strong>Simple POS</strong> ni mfumo wetu wa kisasa wa kusimamia mauzo ya duka lako. Fuatilia bidhaa, mauzo, na hali ya duka lako lote mahali pamoja — haraka, rahisi, na salama. Jiunga leo na maelfu ya wafanyabiashara wanaotumia mfumo huu kukua kila siku!',
    'modal_trial_title' => 'Siku 14 Bure — Bila Malipo!',
    'modal_trial_body'  => 'Unapojisajili, utakuwa na siku <strong>14 za majaribio bure</strong>. Baada ya hapo, chagua mpango unaokufaa ili kuendelea kutumia mfumo.',
    'modal_plans'       => 'MIPANGO YA BEI',
    'plan_1m'           => 'Mwezi 1',
    'plan_3m'           => 'Miezi 3',
    'plan_6m'           => 'Miezi 6',
    'plan_1y'           => 'Mwaka 1',
    'per_month'         => '/mwezi',
    'save_17'           => 'Akiba 17%',
    'save_25'           => 'Akiba 25%',
    'save_29'           => 'Akiba 29%',
    'popular'           => 'MAARUFU',
    'best'              => 'BORA ZAIDI',
    'note_label'        => 'Kumbuka:',
    'note_body'         => 'Mipango yote hapo juu ni kwa <strong>duka moja (1)</strong> na <strong>watumiaji wawili (2)</strong> tu. Kwa maduka zaidi au watumiaji zaidi, wasiliana nasi:',
    'contact_us'        => 'wasiliana nasi',
] : [
    'page_title'        => 'Login | Business Management System',
    'sign_in_prompt'    => 'Please sign in to continue',
    'username'          => 'Username',
    'username_ph'       => 'Enter your username',
    'password'          => 'Password',
    'password_ph'       => 'Enter your password',
    'remember_me'       => 'Remember me',
    'forgot_pw'         => 'Forgot password?',
    'login_btn'         => 'Login',
    'logging_in'        => 'Logging in...',
    'new_company'       => 'New company?',
    'register_here'     => 'Register here',
    'privacy'           => 'Privacy Policy',
    'terms'             => 'Terms of Service',
    'help'              => 'Help Center',
    'login_failed'      => 'Login Failed',
    'invalid_creds'     => 'Invalid username or password.',
    'server_error'      => 'Unable to connect to the server. Please try again.',
    'modal_back'        => 'Go Back',
    'modal_continue'    => 'Continue to Register',
    'lang_label'        => 'Language',
    'modal_tagline'     => 'Manage Your Shop with Ease and Efficiency',
    'modal_desc'        => '<strong>Simple POS</strong> is our modern system for managing your shop\'s sales. Track products, sales, and your shop\'s status all in one place — fast, easy, and secure. Join thousands of business owners growing with this system every day!',
    'modal_trial_title' => '14 Days Free — No Payment Required!',
    'modal_trial_body'  => 'When you register, you get <strong>14 free trial days</strong>. After that, choose the plan that suits you to keep using the system.',
    'modal_plans'       => 'PRICING PLANS',
    'plan_1m'           => '1 Month',
    'plan_3m'           => '3 Months',
    'plan_6m'           => '6 Months',
    'plan_1y'           => '1 Year',
    'per_month'         => '/month',
    'save_17'           => 'Save 17%',
    'save_25'           => 'Save 25%',
    'save_29'           => 'Save 29%',
    'popular'           => 'POPULAR',
    'best'              => 'BEST VALUE',
    'note_label'        => 'Note:',
    'note_body'         => 'All plans above are for <strong>one shop (1)</strong> and <strong>two users (2)</strong> only. For more shops or more users, contact us:',
    'contact_us'        => 'contact us',
];

// Get company branding and contact info from settings
$company_logo  = get_setting('company_logo', '');
$company_name  = get_setting('company_name', 'Business Management System');
$company_email = get_setting('company_email', '');
$company_phone = get_setting('company_phone', '');

// ── Multi-tenancy: register URL + subdomain detection ────────────────────────
$registerUrl  = 'register.php';
$isSubdomain  = false;
$tenantResolverFile = __DIR__ . '/core/tenant_resolver.php';
if (is_file($tenantResolverFile)) {
    require_once $tenantResolverFile;
    if (function_exists('resolveTenantFromRequest')) {
        $__r = resolveTenantFromRequest();
        if (($__r['status'] ?? '') === 'found') {
            $isSubdomain = true;
            $base = function_exists('tenantBaseDomain') ? tenantBaseDomain() : null;
            if ($base) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $registerUrl = $scheme . '://' . $base . '/register.php';
            }
        }
    }
}

// Build proper logo URL
if ($company_logo && strpos($company_logo, 'http') !== 0) {
    $company_logo = '/' . ltrim($company_logo, '/');
}

// Append lang param to register URL
$registerUrlWithLang = $registerUrl . (str_contains($registerUrl, '?') ? '&' : '?') . 'lang=' . urlencode($pageLang);
?>
<!DOCTYPE html>
<html lang="<?= $pageLang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($tr['page_title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --accent-color: #e74c3c;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
        }
        body { background-color: var(--light-bg); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .login-container {
            max-width: 450px; margin: 5% auto; padding: 2rem;
            background: white; border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,.1);
        }
        .logo-container {
            text-align: center; margin-bottom: 2rem;
            padding-bottom: 1rem; border-bottom: 1px solid #eee;
        }
        .company-logo { width: 70px; height: 70px; object-fit: contain; margin-bottom: .5rem; border-radius: 8px; }
        .logo-placeholder {
            width: 70px; height: 70px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 12px; display: inline-flex; align-items: center;
            justify-content: center; margin-bottom: .5rem;
            box-shadow: 0 4px 12px rgba(52,152,219,.3);
        }
        .logo-placeholder i { font-size: 2rem; color: white; }
        .company-name { font-size: 1.1rem; font-weight: 700; color: var(--dark-text); margin-bottom: .2rem; letter-spacing: .3px; }
        .form-control { padding: 12px 15px; border-radius: 5px; border: 1px solid #ddd; }
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 .25rem rgba(52,152,219,.25); }
        .btn-login { background-color: var(--primary-color); border: none; padding: 12px; font-weight: 600; letter-spacing: .5px; transition: all .3s; }
        .btn-login:hover { background-color: var(--secondary-color); transform: translateY(-2px); }
        .input-group-text { background-color: #e9ecef; border-right: none; }
        .input-group .form-control { border-left: none; }
        .divider { display: flex; align-items: center; margin: 1.5rem 0; }
        .divider::before, .divider::after { content: ""; flex: 1; border-bottom: 1px solid #dee2e6; }
        .divider-text { padding: 0 10px; color: #6c757d; font-size: .875rem; }
        .footer-links { text-align: center; margin-top: 1.5rem; font-size: .9rem; }
        .footer-links a { color: var(--dark-text); text-decoration: none; margin: 0 10px; }
        .footer-links a:hover { color: var(--primary-color); }

        /* Language switcher */
        .lang-switcher { position: absolute; top: 12px; right: 14px; }
        .lang-btn {
            background: white; border: 1px solid #dee2e6; border-radius: 20px;
            padding: 4px 12px; font-size: .8rem; font-weight: 600; color: #555;
            cursor: pointer; display: flex; align-items: center; gap: 5px;
            transition: all .15s; box-shadow: 0 1px 4px rgba(0,0,0,.07);
        }
        .lang-btn:hover { border-color: var(--primary-color); color: var(--primary-color); }
        .lang-menu {
            position: absolute; right: 0; top: 34px; background: white;
            border: 1px solid #e0e0e0; border-radius: 10px; min-width: 150px;
            box-shadow: 0 6px 20px rgba(0,0,0,.12); overflow: hidden; z-index: 9999;
            display: none;
        }
        .lang-menu a {
            display: flex; align-items: center; gap: 8px; padding: 9px 14px;
            font-size: .85rem; color: #333; text-decoration: none; transition: background .15s;
        }
        .lang-menu a:hover { background: #f4f8ff; color: var(--primary-color); }
        .lang-menu a.active { font-weight: 700; color: var(--primary-color); }

        /* Pricing modal */
        .pricing-card { transition: all .2s; }
        .pricing-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,.10); }

        /* ── Mobile: login card ── */
        @media (max-width: 575.98px) {
            .login-container { margin: 2% auto !important; padding: 1.25rem !important; }
            .logo-container { margin-bottom: 1.2rem !important; }
            .company-logo, .logo-placeholder { width: 54px !important; height: 54px !important; }
            .logo-placeholder i { font-size: 1.5rem !important; }
            .company-name { font-size: .95rem !important; }
        }

        /* ── Mobile: pricing modal ── */
        @media (max-width: 575.98px) {
            /* Tighter modal margins so more content is visible */
            #pricingModal .modal-dialog { margin: .3rem !important; }
            #pricingModal .modal-content { border-radius: 12px !important; }

            /* Header: logo + name in a compact horizontal row */
            #pricingModal .modal-header { padding: .7rem .85rem 0 !important; }
            #pricingModal .pm-header-inner { flex-direction: row !important; align-items: center !important; gap: .6rem; text-align: left !important; }
            #pricingModal .pm-logo { width: 42px !important; height: 42px !important; margin-bottom: 0 !important; border-radius: 8px !important; flex-shrink: 0; }
            #pricingModal .pm-logo-placeholder { width: 42px !important; height: 42px !important; min-width: 42px; margin-bottom: 0 !important; border-radius: 10px !important; flex-shrink: 0; }
            #pricingModal .pm-logo-placeholder i { font-size: 1.2rem !important; }
            #pricingModal .pm-name-group { text-align: left !important; }
            #pricingModal .pm-company-name { font-size: .8rem !important; line-height: 1.25 !important; }
            #pricingModal .pm-pos-badge { font-size: .6rem !important; margin-top: .12rem !important; display: inline-block; }

            /* Body: tighter padding */
            #pricingModal .modal-body { padding: .55rem .85rem .4rem !important; }

            /* Welcome description: compact */
            #pricingModal .pm-welcome { margin-bottom: .5rem !important; padding-left: 0 !important; padding-right: 0 !important; }
            #pricingModal .pm-welcome h6 { font-size: .8rem !important; margin-bottom: .15rem !important; }
            #pricingModal .pm-welcome p { font-size: .74rem !important; line-height: 1.45 !important; }

            /* Free-trial banner: compact */
            #pricingModal .pm-trial { padding: .5rem .8rem !important; margin-bottom: .55rem !important; }
            #pricingModal .pm-trial-title { font-size: .86rem !important; margin-bottom: .12rem !important; }
            #pricingModal .pm-trial-body { font-size: .73rem !important; }

            /* Plans section heading */
            #pricingModal .pm-plans-label { font-size: .73rem !important; margin-bottom: .4rem !important; }
            #pricingModal .pm-cards-row { --bs-gutter-x: .45rem; --bs-gutter-y: .55rem; margin-bottom: .5rem !important; }

            /* Pricing cards: smaller everything */
            #pricingModal .pricing-card { padding: .4rem .28rem !important; }
            #pricingModal .pm-card-icon { font-size: 1.1rem !important; }
            #pricingModal .pm-card-icon-wrap { margin-bottom: .2rem !important; }
            #pricingModal .pm-card-name { font-size: .68rem !important; }
            #pricingModal .pm-card-price-wrap { margin-top: .2rem !important; margin-bottom: .2rem !important; }
            #pricingModal .pm-price-num { font-size: .97rem !important; font-weight: 800 !important; }
            #pricingModal .pm-price-cur { font-size: .58rem !important; }
            #pricingModal .pm-price-per { font-size: .58rem !important; }
            #pricingModal .pm-save-badge { font-size: .52rem !important; padding: .12em .4em !important; }

            /* Footer buttons: smaller */
            #pricingModal .modal-footer { padding: .4rem .85rem .75rem !important; }
            #pricingModal .modal-footer .btn { padding: .4rem .55rem !important; font-size: .78rem !important; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="login-container position-relative">

        <!-- Language switcher -->
        <div class="lang-switcher">
            <button class="lang-btn" onclick="toggleLang()" id="langBtn">
                <i class="fas fa-globe"></i>
                <?= $pageLang === 'sw' ? 'SW' : 'EN' ?>
                <i class="fas fa-chevron-down" style="font-size:.65rem;"></i>
            </button>
            <div class="lang-menu" id="langMenu">
                <a href="?lang=en" class="<?= $pageLang === 'en' ? 'active' : '' ?>">
                    <span>🇬🇧</span> English
                    <?= $pageLang === 'en' ? '<i class="fas fa-check ms-auto" style="font-size:.7rem;color:#3498db;"></i>' : '' ?>
                </a>
                <a href="?lang=sw" class="<?= $pageLang === 'sw' ? 'active' : '' ?>">
                    <span>🇹🇿</span> Kiswahili
                    <?= $pageLang === 'sw' ? '<i class="fas fa-check ms-auto" style="font-size:.7rem;color:#3498db;"></i>' : '' ?>
                </a>
            </div>
        </div>

        <div class="logo-container">
            <?php if ($company_logo): ?>
                <img src="<?= htmlspecialchars($company_logo) ?>" alt="<?= htmlspecialchars($company_name) ?>" class="company-logo">
            <?php else: ?>
                <div class="logo-placeholder">
                    <i class="fas fa-building"></i>
                </div>
            <?php endif; ?>
            <h5 class="company-name"><?= htmlspecialchars($company_name) ?></h5>
            <p class="text-muted mb-0" style="font-size:.85rem;"><?= htmlspecialchars($tr['sign_in_prompt']) ?></p>
        </div>

        <form id="loginForm">
            <div class="mb-3">
                <label for="username" class="form-label"><?= htmlspecialchars($tr['username']) ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                    <input type="text" class="form-control" id="username" name="username"
                           placeholder="<?= htmlspecialchars($tr['username_ph']) ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label"><?= htmlspecialchars($tr['password']) ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password"
                           placeholder="<?= htmlspecialchars($tr['password_ph']) ?>" required>
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="rememberMe">
                <label class="form-check-label" for="rememberMe"><?= htmlspecialchars($tr['remember_me']) ?></label>
                <a href="forgot-password.php" class="float-end"><?= htmlspecialchars($tr['forgot_pw']) ?></a>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-login"><?= htmlspecialchars($tr['login_btn']) ?></button>

            <?php if (!$isSubdomain): ?>
            <div class="divider"><span class="divider-text">OR</span></div>

            <p class="text-center mb-0"><?= htmlspecialchars($tr['new_company']) ?>
                <a href="#" data-bs-toggle="modal" data-bs-target="#pricingModal"
                   style="color:var(--primary-color);"><?= htmlspecialchars($tr['register_here']) ?></a>
            </p>
            <?php endif; ?>
        </form>

        <div class="footer-links">
            <a href="#"><?= htmlspecialchars($tr['privacy']) ?></a>
            <a href="#"><?= htmlspecialchars($tr['terms']) ?></a>
            <a href="#"><?= htmlspecialchars($tr['help']) ?></a>
        </div>
    </div>
</div>

<?php if (!$isSubdomain): ?>
<!-- Pricing / Offer Modal -->
<div class="modal fade" id="pricingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden;">

      <div class="modal-header border-0 pb-0 pt-4 px-4">
        <div class="d-flex flex-column align-items-center w-100 text-center pm-header-inner">
          <?php if ($company_logo): ?>
            <img src="<?= htmlspecialchars($company_logo) ?>" alt="<?= htmlspecialchars($company_name) ?>"
                 class="pm-logo" style="width:64px;height:64px;object-fit:contain;border-radius:10px;margin-bottom:.75rem;">
          <?php else: ?>
            <div class="pm-logo-placeholder" style="width:64px;height:64px;background:linear-gradient(135deg,#3498db,#2980b9);border-radius:14px;display:flex;align-items:center;justify-content:center;margin-bottom:.75rem;box-shadow:0 4px 14px rgba(52,152,219,.35);">
              <i class="fas fa-building text-white fs-3"></i>
            </div>
          <?php endif; ?>
          <div class="pm-name-group">
            <h5 class="fw-bold mb-0 pm-company-name" style="color:#2c3e50;"><?= htmlspecialchars($company_name) ?></h5>
            <span class="badge rounded-pill mt-1 pm-pos-badge" style="background:#e8f4fd;color:#2980b9;font-size:.75rem;font-weight:600;">
              <i class="fas fa-store me-1"></i>Simple POS
            </span>
          </div>
        </div>
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body px-4 pt-3 pb-2">

        <!-- Welcome description -->
        <div class="text-center mb-4 px-2 pm-welcome">
          <h6 class="fw-bold mb-2" style="color:#2c3e50;font-size:1rem;">
            🛒 <?= htmlspecialchars($tr['modal_tagline']) ?>
          </h6>
          <p class="text-muted mb-0" style="font-size:.88rem;line-height:1.65;">
            <?= $tr['modal_desc'] ?>
          </p>
        </div>

        <!-- Free-trial banner -->
        <div class="text-center rounded-3 py-3 px-3 mb-4 pm-trial"
             style="background:linear-gradient(135deg,#1abc9c,#16a085);color:#fff;">
          <div class="fw-bold pm-trial-title mb-1"><i class="fas fa-gift me-2"></i><?= htmlspecialchars($tr['modal_trial_title']) ?></div>
          <div class="pm-trial-body" style="font-size:.9rem;opacity:.92;"><?= $tr['modal_trial_body'] ?></div>
        </div>

        <!-- Pricing cards -->
        <h6 class="text-center fw-semibold mb-3 pm-plans-label" style="color:#555;letter-spacing:.3px;"><?= htmlspecialchars($tr['modal_plans']) ?></h6>
        <div class="row g-3 mb-3 pm-cards-row">

          <div class="col-6 col-md-3">
            <div class="pricing-card text-center p-3 h-100 rounded-3 border" style="border-color:#dee2e6!important;">
              <div class="mb-2 pm-card-icon-wrap"><i class="fas fa-calendar-day pm-card-icon" style="font-size:1.6rem;color:#3498db;"></i></div>
              <div class="fw-bold pm-card-name" style="color:#2c3e50;"><?= htmlspecialchars($tr['plan_1m']) ?></div>
              <div class="my-2 pm-card-price-wrap">
                <span class="pm-price-num" style="font-size:1.4rem;font-weight:800;color:#2c3e50;">10,000</span>
                <span class="pm-price-cur" style="font-size:.75rem;color:#888;"> TZS</span>
              </div>
              <div class="pm-price-per" style="font-size:.75rem;color:#888;"><?= htmlspecialchars($tr['per_month']) ?></div>
            </div>
          </div>

          <div class="col-6 col-md-3">
            <div class="pricing-card text-center p-3 h-100 rounded-3 border" style="border-color:#dee2e6!important;">
              <div class="mb-2 pm-card-icon-wrap"><i class="fas fa-calendar-week pm-card-icon" style="font-size:1.6rem;color:#9b59b6;"></i></div>
              <div class="fw-bold pm-card-name" style="color:#2c3e50;"><?= htmlspecialchars($tr['plan_3m']) ?></div>
              <div class="my-2 pm-card-price-wrap">
                <span class="pm-price-num" style="font-size:1.4rem;font-weight:800;color:#2c3e50;">25,000</span>
                <span class="pm-price-cur" style="font-size:.75rem;color:#888;"> TZS</span>
              </div>
              <div class="badge rounded-pill pm-save-badge" style="background:#f0e6ff;color:#9b59b6;font-size:.7rem;"><?= htmlspecialchars($tr['save_17']) ?></div>
            </div>
          </div>

          <div class="col-6 col-md-3">
            <div class="pricing-card text-center p-3 h-100 rounded-3 border position-relative" style="border-color:#1abc9c!important;">
              <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill"
                    style="background:#1abc9c;font-size:.65rem;white-space:nowrap;"><?= htmlspecialchars($tr['popular']) ?></span>
              <div class="mb-2 mt-1 pm-card-icon-wrap"><i class="fas fa-calendar-alt pm-card-icon" style="font-size:1.6rem;color:#1abc9c;"></i></div>
              <div class="fw-bold pm-card-name" style="color:#2c3e50;"><?= htmlspecialchars($tr['plan_6m']) ?></div>
              <div class="my-2 pm-card-price-wrap">
                <span class="pm-price-num" style="font-size:1.4rem;font-weight:800;color:#2c3e50;">45,000</span>
                <span class="pm-price-cur" style="font-size:.75rem;color:#888;"> TZS</span>
              </div>
              <div class="badge rounded-pill pm-save-badge" style="background:#e6faf5;color:#1abc9c;font-size:.7rem;"><?= htmlspecialchars($tr['save_25']) ?></div>
            </div>
          </div>

          <div class="col-6 col-md-3">
            <div class="pricing-card text-center p-3 h-100 rounded-3 border position-relative" style="border-color:#f39c12!important;">
              <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill"
                    style="background:#f39c12;font-size:.65rem;white-space:nowrap;"><?= htmlspecialchars($tr['best']) ?></span>
              <div class="mb-2 mt-1 pm-card-icon-wrap"><i class="fas fa-crown pm-card-icon" style="font-size:1.6rem;color:#f39c12;"></i></div>
              <div class="fw-bold pm-card-name" style="color:#2c3e50;"><?= htmlspecialchars($tr['plan_1y']) ?></div>
              <div class="my-2 pm-card-price-wrap">
                <span class="pm-price-num" style="font-size:1.4rem;font-weight:800;color:#2c3e50;">85,000</span>
                <span class="pm-price-cur" style="font-size:.75rem;color:#888;"> TZS</span>
              </div>
              <div class="badge rounded-pill pm-save-badge" style="background:#fff8e6;color:#f39c12;font-size:.7rem;"><?= htmlspecialchars($tr['save_29']) ?></div>
            </div>
          </div>

        </div>

        <!-- Limitation note -->
        <div class="rounded-3 px-3 py-2 mb-1 d-flex align-items-start gap-2"
             style="background:#fff8e6;border:1px solid #ffe5a0;">
          <i class="fas fa-info-circle mt-1" style="color:#f39c12;flex-shrink:0;"></i>
          <small style="color:#7a5c00;line-height:1.6;">
            <strong><?= htmlspecialchars($tr['note_label']) ?></strong> <?= $tr['note_body'] ?>
            <?php if ($company_email || $company_phone): ?>
              <span class="d-block mt-1">
                <?php if ($company_email): ?>
                  <i class="fas fa-envelope me-1"></i>
                  <a href="mailto:<?= htmlspecialchars($company_email) ?>"
                     style="color:#7a5c00;font-weight:600;"><?= htmlspecialchars($company_email) ?></a>
                  <?php if ($company_phone): ?>&nbsp;&nbsp;<?php endif; ?>
                <?php endif; ?>
                <?php if ($company_phone): ?>
                  <i class="fas fa-phone me-1"></i>
                  <a href="tel:<?= htmlspecialchars($company_phone) ?>"
                     style="color:#7a5c00;font-weight:600;"><?= htmlspecialchars($company_phone) ?></a>
                <?php endif; ?>
              </span>
            <?php endif; ?>
          </small>
        </div>

      </div>

      <div class="modal-footer border-0 px-4 pb-4 pt-2 d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">
          <?= htmlspecialchars($tr['modal_back']) ?>
        </button>
        <a href="<?= htmlspecialchars($registerUrlWithLang) ?>"
           class="btn flex-fill fw-semibold text-white"
           style="background:linear-gradient(135deg,#3498db,#2980b9);">
          <i class="fas fa-arrow-right me-1"></i> <?= htmlspecialchars($tr['modal_continue']) ?>
        </a>
      </div>

    </div>
  </div>
</div>
<?php endif; // !$isSubdomain — modal ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const originalSwalFire = Swal.fire.bind(Swal);
Swal.fire = function(...args) {
    if (args.length === 1 && typeof args[0] === 'object') {
        const options = { ...args[0] };
        if (!options.confirmButtonColor) options.confirmButtonColor = '#28a745';
        if (!options.confirmButtonText) options.confirmButtonText = 'OK';
        return originalSwalFire(options);
    }
    if (typeof args[0] === 'string') {
        const options = { title: args[0] };
        if (args[1]) options.text = args[1];
        if (args[2]) options.icon = args[2];
        options.confirmButtonColor = '#28a745';
        options.confirmButtonText = 'OK';
        return originalSwalFire(options);
    }
    return originalSwalFire(...args);
};

function toggleLang() {
    const menu = document.getElementById('langMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.lang-switcher')) {
        document.getElementById('langMenu').style.display = 'none';
    }
});

$(document).ready(function() {
    $('#togglePassword').click(function() {
        const password = $('#password');
        const type = password.attr('type') === 'password' ? 'text' : 'password';
        password.attr('type', type);
        $(this).find('i').toggleClass('fa-eye fa-eye-slash');
    });

    $('#loginForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('.btn-login');
        $btn.html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= addslashes($tr['logging_in']) ?>');
        $btn.prop('disabled', true);

        $.ajax({
            url: 'actions/login.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    if (navigator.sendBeacon) {
                        navigator.sendBeacon('<?= getUrl('api/finalize_login') ?>');
                    }
                    window.location.href = 'dashboard';
                } else {
                    Swal.fire('<?= addslashes($tr['login_failed']) ?>', response.message || '<?= addslashes($tr['invalid_creds']) ?>', 'error');
                    $btn.html('<?= addslashes($tr['login_btn']) ?>');
                    $btn.prop('disabled', false);
                }
            },
            error: function() {
                Swal.fire('Error', '<?= addslashes($tr['server_error']) ?>', 'error');
                $btn.html('<?= addslashes($tr['login_btn']) ?>');
                $btn.prop('disabled', false);
            }
        });
    });
});
</script>
</body>
</html>
