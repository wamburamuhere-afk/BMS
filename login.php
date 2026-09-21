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
    'page_title'     => 'Ingia | Mfumo wa Usimamizi wa Biashara',
    'sign_in_prompt' => 'Tafadhali ingia kuendelea',
    'username'       => 'Jina la Mtumiaji',
    'username_ph'    => 'Andika jina lako la mtumiaji',
    'password'       => 'Nenosiri',
    'password_ph'    => 'Andika nenosiri lako',
    'remember_me'    => 'Nikumbuke',
    'forgot_pw'      => 'Umesahau nenosiri?',
    'login_btn'      => 'Ingia',
    'logging_in'     => 'Ingia...',
    'new_company'    => 'Kampuni mpya?',
    'register_here'  => 'Jisajili hapa',
    'privacy'        => 'Sera ya Faragha',
    'terms'          => 'Masharti ya Huduma',
    'help'           => 'Msaada',
    'login_failed'   => 'Imeshindwa Kuingia',
    'invalid_creds'  => 'Jina la mtumiaji au nenosiri si sahihi.',
    'server_error'   => 'Hitilafu ya seva. Tafadhali jaribu tena.',
    'modal_back'     => 'Rudi Nyuma',
    'modal_continue' => 'Endelea Kujisajili',
    'lang_label'     => 'Lugha',
] : [
    'page_title'     => 'Login | Business Management System',
    'sign_in_prompt' => 'Please sign in to continue',
    'username'       => 'Username',
    'username_ph'    => 'Enter your username',
    'password'       => 'Password',
    'password_ph'    => 'Enter your password',
    'remember_me'    => 'Remember me',
    'forgot_pw'      => 'Forgot password?',
    'login_btn'      => 'Login',
    'logging_in'     => 'Logging in...',
    'new_company'    => 'New company?',
    'register_here'  => 'Register here',
    'privacy'        => 'Privacy Policy',
    'terms'          => 'Terms of Service',
    'help'           => 'Help Center',
    'login_failed'   => 'Login Failed',
    'invalid_creds'  => 'Invalid username or password.',
    'server_error'   => 'Unable to connect to the server. Please try again.',
    'modal_back'     => 'Go Back',
    'modal_continue' => 'Continue to Register',
    'lang_label'     => 'Language',
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
        <div class="d-flex flex-column align-items-center w-100 text-center">
          <?php if ($company_logo): ?>
            <img src="<?= htmlspecialchars($company_logo) ?>" alt="<?= htmlspecialchars($company_name) ?>"
                 style="width:64px;height:64px;object-fit:contain;border-radius:10px;margin-bottom:.75rem;">
          <?php else: ?>
            <div style="width:64px;height:64px;background:linear-gradient(135deg,#3498db,#2980b9);border-radius:14px;display:flex;align-items:center;justify-content:center;margin-bottom:.75rem;box-shadow:0 4px 14px rgba(52,152,219,.35);">
              <i class="fas fa-building text-white fs-3"></i>
            </div>
          <?php endif; ?>
          <h5 class="fw-bold mb-0" style="color:#2c3e50;"><?= htmlspecialchars($company_name) ?></h5>
          <span class="badge rounded-pill mt-1" style="background:#e8f4fd;color:#2980b9;font-size:.75rem;font-weight:600;">
            <i class="fas fa-store me-1"></i>Simple POS
          </span>
        </div>
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body px-4 pt-3 pb-2">

        <!-- Welcome description -->
        <div class="text-center mb-4 px-2">
          <h6 class="fw-bold mb-2" style="color:#2c3e50;font-size:1rem;">
            🛒 Simamia Duka Lako kwa Urahisi na Ufanisi
          </h6>
          <p class="text-muted mb-0" style="font-size:.88rem;line-height:1.65;">
            <strong>BMS Simple POS</strong> ni mfumo wetu wa kisasa wa kusimamia mauzo ya duka lako.
            Fuatilia bidhaa, mauzo, na hali ya duka lako lote mahali pamoja — haraka, rahisi, na salama.
            Jiunga leo na maelfu ya wafanyabiashara wanaotumia mfumo huu kukua kila siku!
          </p>
        </div>

        <!-- Free-trial banner -->
        <div class="text-center rounded-3 py-3 px-3 mb-4"
             style="background:linear-gradient(135deg,#1abc9c,#16a085);color:#fff;">
          <div class="fw-bold fs-5 mb-1"><i class="fas fa-gift me-2"></i>Siku 14 Bure — Bila Malipo!</div>
          <div style="font-size:.9rem;opacity:.92;">
            Unapojisajili, utakuwa na siku <strong>14 za majaribio bure</strong>.
            Baada ya hapo, chagua mpango unaokufaa ili kuendelea kutumia mfumo.
          </div>
        </div>

        <!-- Pricing cards -->
        <h6 class="text-center fw-semibold mb-3" style="color:#555;letter-spacing:.3px;">MIPANGO YA BEI</h6>
        <div class="row g-3 mb-3">

          <div class="col-6 col-md-3">
            <div class="pricing-card text-center p-3 h-100 rounded-3 border" style="border-color:#dee2e6!important;">
              <div class="mb-2"><i class="fas fa-calendar-day" style="font-size:1.6rem;color:#3498db;"></i></div>
              <div class="fw-bold" style="color:#2c3e50;">Mwezi 1</div>
              <div class="my-2">
                <span style="font-size:1.4rem;font-weight:800;color:#2c3e50;">10,000</span>
                <span style="font-size:.75rem;color:#888;"> TZS</span>
              </div>
              <div style="font-size:.75rem;color:#888;">/mwezi</div>
            </div>
          </div>

          <div class="col-6 col-md-3">
            <div class="pricing-card text-center p-3 h-100 rounded-3 border" style="border-color:#dee2e6!important;">
              <div class="mb-2"><i class="fas fa-calendar-week" style="font-size:1.6rem;color:#9b59b6;"></i></div>
              <div class="fw-bold" style="color:#2c3e50;">Miezi 3</div>
              <div class="my-2">
                <span style="font-size:1.4rem;font-weight:800;color:#2c3e50;">25,000</span>
                <span style="font-size:.75rem;color:#888;"> TZS</span>
              </div>
              <div class="badge rounded-pill" style="background:#f0e6ff;color:#9b59b6;font-size:.7rem;">Akiba 17%</div>
            </div>
          </div>

          <div class="col-6 col-md-3">
            <div class="pricing-card text-center p-3 h-100 rounded-3 border position-relative" style="border-color:#1abc9c!important;">
              <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill"
                    style="background:#1abc9c;font-size:.65rem;white-space:nowrap;">MAARUFU</span>
              <div class="mb-2 mt-1"><i class="fas fa-calendar-alt" style="font-size:1.6rem;color:#1abc9c;"></i></div>
              <div class="fw-bold" style="color:#2c3e50;">Miezi 6</div>
              <div class="my-2">
                <span style="font-size:1.4rem;font-weight:800;color:#2c3e50;">45,000</span>
                <span style="font-size:.75rem;color:#888;"> TZS</span>
              </div>
              <div class="badge rounded-pill" style="background:#e6faf5;color:#1abc9c;font-size:.7rem;">Akiba 25%</div>
            </div>
          </div>

          <div class="col-6 col-md-3">
            <div class="pricing-card text-center p-3 h-100 rounded-3 border position-relative" style="border-color:#f39c12!important;">
              <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill"
                    style="background:#f39c12;font-size:.65rem;white-space:nowrap;">BORA ZAIDI</span>
              <div class="mb-2 mt-1"><i class="fas fa-crown" style="font-size:1.6rem;color:#f39c12;"></i></div>
              <div class="fw-bold" style="color:#2c3e50;">Mwaka 1</div>
              <div class="my-2">
                <span style="font-size:1.4rem;font-weight:800;color:#2c3e50;">85,000</span>
                <span style="font-size:.75rem;color:#888;"> TZS</span>
              </div>
              <div class="badge rounded-pill" style="background:#fff8e6;color:#f39c12;font-size:.7rem;">Akiba 29%</div>
            </div>
          </div>

        </div>

        <!-- Limitation note -->
        <div class="rounded-3 px-3 py-2 mb-1 d-flex align-items-start gap-2"
             style="background:#fff8e6;border:1px solid #ffe5a0;">
          <i class="fas fa-info-circle mt-1" style="color:#f39c12;flex-shrink:0;"></i>
          <small style="color:#7a5c00;line-height:1.6;">
            <strong>Kumbuka:</strong> Mipango yote hapo juu ni kwa <strong>duka moja (1)</strong> na
            <strong>watumiaji wawili (2)</strong> tu. Kwa maduka zaidi au watumiaji zaidi, wasiliana nasi:
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
