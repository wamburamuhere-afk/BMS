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
    'help_title'        => 'Unahitaji msaada wa kuingia?',
    'help_staff'        => 'Wafanyakazi: mwombe msimamizi wa kampuni yako akuwekee nenosiri jipya.',
    'help_admin'        => 'Msimamizi: tumia "Umesahau nenosiri?" hapo juu.',
    'help_contact'      => 'Bado umekwama? Wasiliana na msaada:',
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
    'modal_desc'        => '<strong>Smart POS</strong> ni mfumo wetu wa kisasa wa kusimamia mauzo ya duka lako. Fuatilia bidhaa, mauzo, na hali ya duka lako lote mahali pamoja — haraka, rahisi, na salama. Jiunga leo na maelfu ya wafanyabiashara wanaotumia mfumo huu kukua kila siku!',
    'modal_trial_title' => 'Siku 14 Bure — Bila Malipo!',
    'modal_trial_body'  => 'Unapojisajili, utakuwa na siku <strong>14 za majaribio bure</strong>. Baada ya hapo, chagua mpango unaokufaa ili kuendelea kutumia mfumo.',
    'modal_plans'       => 'MIPANGO YA BEI',
    'pm_soon_title'     => 'Bei zinaandaliwa',
    'pm_soon_body'      => 'Mipango yetu ya bei inasasishwa. Unaweza kujisajili sasa na kuanza majaribio ya bure — tutakujulisha bei pindi zikiwa tayari.',
    'pm_modules'        => 'Moduli %d',
    'note_label'        => 'Kumbuka:',
    'note_body'         => 'Kwa bei, maduka zaidi au watumiaji zaidi, wasiliana nasi:',
    'contact_us'        => 'wasiliana nasi',
    'pm_choose'         => 'CHAGUA MPANGO — MAJARIBIO YAKO YA BURE YANAANZA NAO',
    'pm_popular'        => 'MAARUFU ZAIDI',
    'pm_per_month'      => '≈ %s TZS /mwezi',
    'pm_not_term'       => 'Haupo kwa muda huu',
    'pm_shop_1'         => 'Duka 1',
    'pm_shops'          => 'Maduka %d',
    'pm_shops_unl'      => 'Maduka bila kikomo',
    'pm_user_1'         => 'Mtumiaji 1',
    'pm_users'          => 'Watumiaji %d',
    'pm_users_unl'      => 'Watumiaji bila kikomo',
    'pm_note'           => 'Unahitaji maduka au watumiaji zaidi? Wasiliana nasi:',
] : [
    'page_title'        => 'Login | Business Management System',
    'sign_in_prompt'    => 'Please sign in to continue',
    'username'          => 'Username',
    'username_ph'       => 'Enter your username',
    'password'          => 'Password',
    'password_ph'       => 'Enter your password',
    'remember_me'       => 'Remember me',
    'forgot_pw'         => 'Forgot password?',
    'help_title'        => 'Need help signing in?',
    'help_staff'        => 'Staff: ask your company admin to set a new password for you.',
    'help_admin'        => 'Admins: use "Forgot password?" above.',
    'help_contact'      => 'Still stuck? Contact support:',
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
    'modal_desc'        => '<strong>Smart POS</strong> is our modern system for managing your shop\'s sales. Track products, sales, and your shop\'s status all in one place — fast, easy, and secure. Join thousands of business owners growing with this system every day!',
    'modal_trial_title' => '14 Days Free — No Payment Required!',
    'modal_trial_body'  => 'When you register, you get <strong>14 free trial days</strong>. After that, choose the plan that suits you to keep using the system.',
    'modal_plans'       => 'PRICING PLANS',
    'pm_soon_title'     => 'Pricing is being prepared',
    'pm_soon_body'      => 'Our plans are being updated. You can register now and start your free trial — we will share pricing as soon as it is ready.',
    'pm_modules'        => '%d modules',
    'note_label'        => 'Note:',
    'note_body'         => 'For pricing, more shops or more users, contact us:',
    'contact_us'        => 'contact us',
    'pm_choose'         => 'CHOOSE A PLAN — YOUR FREE TRIAL STARTS ON IT',
    'pm_popular'        => 'MOST POPULAR',
    'pm_per_month'      => '≈ %s TZS /month',
    'pm_not_term'       => 'Not offered for this length',
    'pm_shop_1'         => '1 shop',
    'pm_shops'          => '%d shops',
    'pm_shops_unl'      => 'Unlimited shops',
    'pm_user_1'         => '1 user',
    'pm_users'          => '%d users',
    'pm_users_unl'      => 'Unlimited users',
    'pm_note'           => 'Need more shops or users? Contact us:',
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

    <link rel="manifest" href="<?= getUrl('manifest.json') ?>">
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Smart BMS">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAALQAAAC0CAMAAAAKE/YAAAAALVBMVEX+/v7U1NiMj7dcX5k9QYY1OoEpLXqwsczV1uQVGm0cIXGdn8KoqK1+ga6/wNfXo1VqAAAFCUlEQVR42u2c23akIBBFG1FETOb/P3da++Id6hSW4ArnJZNMQu2QCrSy4+NRUlJSUlJSUlJSUlLyJ6MqXTfGNLWuWpsahpRWm24Wp/vURGHkeiR1L2A3/sNUqam8UfUXeJrq5/umTU12nOqJ2O3k+UF9IYZd5Pvu/ifrA+YRu7HhCrN32MTq5zB7i0J9iDxSOxVdgTDJP95sELzMI7WNrEDITyArBO1nHqiNjaoQ1xvvLD69CjEP1DqmwhkTvZwIFSJ+Uff8CgLQNQW6W7Z1uALa1RYasg03x7ZBUkMTJ3o51YmhWzJzV2UDranQXddkA23o0J3KBJq03m37Iy00YWPZWz/SQgd38P2mTgtdI9DTopcaGkgu0MjiMVs+CrRse3SWUSE1tMsEGlryzLdEWuhbbi7kF3k5beOW3h2uazOBRl6aTi2dGpp4tbW63koMTd5eFtfjqaGB9UOyp8EhgZ28TQo9v4dB7uoZNVaBlDY45OLTkV3xTS1wWyzY1KufHXIl0LIqUKKwEa2BqcEK8XO9HZFBDVYgYlu1n93fENvgfY1VEAmwnXf5nHf1buesyLnMqa12x5jZUj9stbr6Mr/9wVFdRtSv83wzNIozuvr3GK8S8qcesjjCPLq2yY56kUJNytCjtTF1lHPi7et3hdOslo1zwp2bQ2p1VoVvpblz0r2dE6Yq47vT8LFaXt9XlNWy55yMg/Lm4oj6VKvldOek7YgvqfhWi/YNWnuck+WbwT2BqT1WizcB58TsjBl2ThBqh7+iDjonhuWc0O/77Vgt4d4IOifN6ktCl3wWpjYYtaBzgnQI9Nso6pwg1MiWQDyQMDznBLmfTW8QjnMichceaRCOcyIjiNCnmuWcQNBkb2FhtXgD3JerOT3NtVq8kXdOeBV8+UcfkHlYxbRafOEdCyI93fMOHk9q6VlTX3DE6wvknBgeNBDaotewhpTXFrzB9A0ONDQttOWDB42sHgLQ2JAcaIH2kJe/mBV84S1IiZc8YHPpeJsLVKEmQSOnr9Mmi72ePn1H5DknF1gtgaYmZ2q4C6wWb3r6NPzyoHlWiz/yzgl9AyPfZuI4J5jVQnxxCt35kHdOqEfSwP08oOeYzgmpAniLibxnTdTnWy2OfFUL/vgmagmrBbzZa520c0LwQ+AzDIVTn2q1PP+LcSBFN2Wmvj5STnCrxXHPRTVwrnNuhecHG64OJO+c7Ffoov5ke+OceGaeR72u8Dpf1dxpfg9arS4Zje5Ppd6rEIc8RlW6Gb9/Z/TvQLZ7K5jd16sK0Sfjs+l4rgsB5ySGelNBIsU5SUz96RDVa103zWi1pAYNU3db58Sc+JslRz2ttp83XKvlaupV0zT5zDZwdHfpk1Q+GVbQ7SqKGAW1BcaPWK9JzgmZmme1oJNLuRCJok72JBWEmme1nNQb7zxg6nyepIJQ5/MkFcA5YVot1FzgnKSFZjoniaF5zkliaHmrRQCafmfY3fxJKon/Qp938FiepIJD3/KhJLeEvuVDSW4JLW+1iPT0DR9pJG+1CEDLK5MC0EznJC30XZ+kQsZmWi0C0Lk8SeUC5yTxTFPXD+kLW6EnqajU0LHOCVaBFHnnBKxACeqcUKwWFVWBEtQ5CfshbVyF+Lnejui3WnadE5knqWDOicGdE6yCRKyEcyIfCedEPiLOyQXYIs6JfFSlzeuY2dRZnYyHIu+clJSUlJSUlJSUlJT8nfwHEXIZVeHdeokAAAAASUVORK5CYII=">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('<?= getUrl('sw.js') ?>').catch(function () {});
            });
        }
    </script>
    <style>
        /* Installed desktop app without a title bar: invisible strip so the window can still be dragged. */
        @media (display-mode: window-controls-overlay) {
            body::before {
                content: ""; position: fixed; z-index: 2000;
                top: 0; left: env(titlebar-area-x, 0); width: env(titlebar-area-width, 100%); height: env(titlebar-area-height, 32px);
                app-region: drag; -webkit-app-region: drag;
            }
        }
    </style>
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


        /* ── Mobile: login card ── */
        @media (max-width: 575.98px) {
            .login-container { margin: 2% auto !important; padding: 1.25rem !important; }
            .logo-container { margin-bottom: 1.2rem !important; }
            .company-logo, .logo-placeholder { width: 54px !important; height: 54px !important; }
            .logo-placeholder i { font-size: 1.5rem !important; }
            .company-name { font-size: .95rem !important; }
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
                    <input type="text" class="form-control" id="username" name="username" autocomplete="username"
                           value="<?= htmlspecialchars(substr((string)($_GET['u'] ?? ''), 0, 50)) ?>"
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
                <!-- Clean-URL form, and the page now actually exists. This
                     link pointed at forgot-password.php for a long time while
                     no such file was ever written: every tenant who clicked it
                     got a bare 404. Carries the chosen language through. -->
                <a href="forgot-password?lang=<?= urlencode($pageLang) ?>" class="float-end"><?= htmlspecialchars($tr['forgot_pw']) ?></a>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-login"><?= htmlspecialchars($tr['login_btn']) ?></button>

            <?php if (!$isSubdomain): ?>
            <div class="divider"><span class="divider-text">OR</span></div>

            <p class="text-center mb-0"><?= htmlspecialchars($tr['new_company']) ?>
                <a href="<?= htmlspecialchars($registerUrlWithLang) ?>"
                   style="color:var(--primary-color);"><?= htmlspecialchars($tr['register_here']) ?></a>
            </p>
            <?php endif; ?>
        </form>

        <?php
        // "Need help signing in?" — text and contacts from Superadmin › Support › Settings (control DB).
        $__sup = [];
        if (is_file(__DIR__ . '/core/support.php')) {
            try { require_once __DIR__ . '/core/support.php'; $__sup = supportSettings(); } catch (Throwable $e) { $__sup = []; }
        }
        $__supTel = static fn(string $n): string => preg_replace('/[^0-9+]/', '', $n);
        ?>
        <div class="text-center mt-3">
            <a class="small" data-bs-toggle="collapse" href="#loginHelp" role="button" aria-expanded="false" aria-controls="loginHelp"><?= htmlspecialchars($tr['help_title']) ?></a>
        </div>
        <div class="collapse mt-2" id="loginHelp">
            <div class="border rounded p-3 small" style="background:#e7f0ff;border-color:#b6ccfe!important">
                <div class="mb-1"><?= htmlspecialchars($tr['help_staff']) ?></div>
                <div class="mb-2"><?= htmlspecialchars($tr['help_admin']) ?></div>
                <?php if (trim((string)($__sup['support_login_help'] ?? '')) !== ''): ?>
                <div class="mb-2" style="white-space:pre-wrap"><?= htmlspecialchars((string)$__sup['support_login_help']) ?></div>
                <?php endif; ?>
                <?php if (($__sup['support_email'] ?? '') !== '' || ($__sup['support_phone'] ?? '') !== '' || ($__sup['support_whatsapp'] ?? '') !== ''): ?>
                <div class="fw-semibold mb-1"><?= htmlspecialchars($tr['help_contact']) ?></div>
                <?php if (($__sup['support_email'] ?? '') !== ''): ?><div><i class="fas fa-envelope me-1"></i><a href="mailto:<?= htmlspecialchars($__sup['support_email']) ?>"><?= htmlspecialchars($__sup['support_email']) ?></a></div><?php endif; ?>
                <?php if (($__sup['support_phone'] ?? '') !== ''): ?><div><i class="fas fa-phone me-1"></i><a href="tel:<?= htmlspecialchars($__supTel($__sup['support_phone'])) ?>"><?= htmlspecialchars($__sup['support_phone']) ?></a></div><?php endif; ?>
                <?php if (($__sup['support_whatsapp'] ?? '') !== ''): ?><div><i class="fab fa-whatsapp me-1"></i><a href="https://wa.me/<?= htmlspecialchars(ltrim($__supTel($__sup['support_whatsapp']), '+')) ?>" target="_blank" rel="noopener">WhatsApp</a></div><?php endif; ?>
                <?php if (($__sup['support_hours'] ?? '') !== ''): ?><div class="text-muted mt-1"><?= htmlspecialchars($__sup['support_hours']) ?></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer-links">
            <a href="#"><?= htmlspecialchars($tr['privacy']) ?></a>
            <a href="#"><?= htmlspecialchars($tr['terms']) ?></a>
            <a data-bs-toggle="collapse" href="#loginHelp" role="button" aria-controls="loginHelp"><?= htmlspecialchars($tr['help']) ?></a>
        </div>
    </div>
</div>


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
