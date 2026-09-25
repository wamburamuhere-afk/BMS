<?php
/**
 * register.php — public company self-registration.
 *
 * Standalone by design: it never boots roots.php, because there is no tenant and
 * no signed-in user to load. Reachable only on the platform's root domain — a
 * tenant's own subdomain returns 404, since a visitor there is that company's
 * customer, not a prospect.
 */
require_once __DIR__ . '/core/tenant_registration.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Language detection (mirrors login.php)
$pageLang = 'en';
if (!empty($_GET['lang']) && in_array($_GET['lang'], ['en', 'sw'], true)) {
    $pageLang = $_GET['lang'];
    setcookie('bms_lang', $pageLang, time() + 365 * 24 * 3600, '/');
} elseif (!empty($_COOKIE['bms_lang']) && in_array($_COOKIE['bms_lang'], ['en', 'sw'], true)) {
    $pageLang = $_COOKIE['bms_lang'];
}

$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

$closed  = selfRegistrationClosedReason();
$baseDom = tenantBaseDomain();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageLang === 'sw' ? 'Fungua Akaunti ya Kampuni' : 'Create your company account' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .signup-card {
        max-width: 560px; margin: 4% auto; padding: 2rem;
        border: 1px solid #b6ccfe; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,.08);
    }
    .brand { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #eee; }
    .brand i { font-size: 2.5rem; }
    /* Honeypot: hidden from people, visible to naive bots. Not type=hidden,
       which bots skip — it must look like a real field in the DOM. */
    .hp-field { position: absolute; left: -9999px; top: -9999px; height: 0; overflow: hidden; }
    .subdomain-hint { font-size: .875rem; min-height: 1.25rem; }
    .progress-note { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; }
    .lang-switcher { position: absolute; top: 12px; right: 14px; }
    .lang-btn {
        background: white; border: 1px solid #dee2e6; border-radius: 20px;
        padding: 4px 12px; font-size: .8rem; font-weight: 600; color: #555;
        cursor: pointer; display: flex; align-items: center; gap: 5px;
        transition: all .15s; box-shadow: 0 1px 4px rgba(0,0,0,.07);
    }
    .lang-btn:hover { border-color: #3498db; color: #3498db; }
    .lang-menu {
        position: absolute; right: 0; top: 34px; background: white;
        border: 1px solid #e0e0e0; border-radius: 10px; min-width: 150px;
        box-shadow: 0 6px 20px rgba(0,0,0,.12); overflow: hidden; z-index: 9999; display: none;
    }
    .lang-menu a {
        display: flex; align-items: center; gap: 8px; padding: 9px 14px;
        font-size: .85rem; color: #333; text-decoration: none; transition: background .15s;
    }
    .lang-menu a:hover { background: #f4f8ff; color: #3498db; }
    .lang-menu a.active { font-weight: 700; color: #3498db; }
</style>
</head>
<body>
<div class="signup-card position-relative">

    <!-- Language switcher -->
    <div class="lang-switcher">
        <button class="lang-btn" onclick="toggleLang()">
            <i class="bi bi-globe"></i>
            <?= $pageLang === 'sw' ? 'SW' : 'EN' ?>
            <i class="bi bi-chevron-down" style="font-size:.6rem;"></i>
        </button>
        <div class="lang-menu" id="langMenu">
            <a href="?lang=en" class="<?= $pageLang === 'en' ? 'active' : '' ?>">
                <span>🇬🇧</span> English
                <?= $pageLang === 'en' ? '<i class="bi bi-check ms-auto" style="font-size:.7rem;color:#3498db;"></i>' : '' ?>
            </a>
            <a href="?lang=sw" class="<?= $pageLang === 'sw' ? 'active' : '' ?>">
                <span>🇹🇿</span> Kiswahili
                <?= $pageLang === 'sw' ? '<i class="bi bi-check ms-auto" style="font-size:.7rem;color:#3498db;"></i>' : '' ?>
            </a>
        </div>
    </div>

    <div class="brand">
        <i class="bi bi-building-add text-primary"></i>
        <h4 class="mt-2 mb-0">Create your company account</h4>
        <small class="text-muted">Your own private system, ready in a moment</small>
    </div>

<?php if ($closed): ?>
    <div class="alert alert-secondary text-center mb-0">
        <i class="bi bi-info-circle me-1"></i><?= safe_output($closed, '') ?>
    </div>
<?php else: ?>

    <div id="formError" class="alert alert-danger d-none" role="alert"></div>

    <form id="registerForm" autocomplete="off" novalidate enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <!-- Honeypot. Left blank by humans; bots fill it and are refused. -->
        <div class="hp-field" aria-hidden="true">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="mb-3">
            <label for="company_name" class="form-label">Company name</label>
            <input type="text" class="form-control" id="company_name" name="company_name" maxlength="191" required autofocus>
            <div class="form-text">This fills in your Company Profile automatically — you won't need to retype it.</div>
        </div>

        <div class="mb-3">
            <label class="form-label">Company logo <span class="text-muted">(optional)</span></label>
            <div class="d-flex align-items-center gap-3">
                <div class="bg-light rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 64px; height: 64px; overflow: hidden; border: 2px dashed #dee2e6;">
                    <img id="logoPreview" src="" alt="" class="d-none img-fluid" style="max-height: 100%; width: auto;">
                    <i id="logoPlaceholder" class="bi bi-image text-muted fs-4"></i>
                </div>
                <input type="file" class="form-control" id="company_logo" name="company_logo" accept="image/png,image/jpeg,image/gif">
            </div>
            <div class="form-text">PNG, JPG or GIF. Max 2MB. You can also add this later.</div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label for="company_physical_address" class="form-label">Physical address <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control" id="company_physical_address" name="company_physical_address" maxlength="255" placeholder="e.g. Moshi-Kilimanjaro">
            </div>
            <div class="col-md-6">
                <label for="company_postal_address" class="form-label">Postal address <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control" id="company_postal_address" name="company_postal_address" maxlength="255" placeholder="e.g. P.O. Box 123, Machame">
            </div>
        </div>

        <div class="mb-1">
            <label for="subdomain" class="form-label">Choose your web address</label>
            <div class="input-group">
                <input type="text" class="form-control" id="subdomain" name="subdomain"
                       maxlength="32" pattern="[a-z0-9-]+" placeholder="yourcompany" required>
                <span class="input-group-text">.<?= safe_output($baseDom ?? 'example.com', '') ?></span>
            </div>
        </div>
        <div class="subdomain-hint text-muted mb-3" id="subHint">
            Lowercase letters, numbers and hyphens. 3–32 characters.
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6">
                <label for="owner_first_name" class="form-label">First name</label>
                <input type="text" class="form-control" id="owner_first_name" name="owner_first_name" maxlength="100">
            </div>
            <div class="col-6">
                <label for="owner_last_name" class="form-label">Last name</label>
                <input type="text" class="form-control" id="owner_last_name" name="owner_last_name" maxlength="100">
            </div>
        </div>

        <div class="mb-3">
            <label for="owner_phone" class="form-label">Phone number</label>
            <input type="tel" class="form-control" id="owner_phone" name="owner_phone" maxlength="20" placeholder="e.g. 0712345678" required>
            <div class="form-text">You will sign in with this number.</div>
        </div>

        <div class="mb-3">
            <label for="owner_email" class="form-label">Email address <span class="text-muted">(optional)</span></label>
            <input type="email" class="form-control" id="owner_email" name="owner_email" maxlength="191" placeholder="e.g. info@yourcompany.com">
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label for="owner_password" class="form-label">Password</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="owner_password" name="owner_password" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('owner_password',this)" tabindex="-1" title="Show/hide password">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="form-text">At least 8 characters, with a letter and a number.</div>
            </div>
            <div class="col-md-6">
                <label for="owner_password_confirm" class="form-label">Confirm password</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="owner_password_confirm" name="owner_password_confirm" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('owner_password_confirm',this)" tabindex="-1" title="Show/hide password">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-12 col-md-4">
                <label for="country" class="form-label">Country <span class="text-danger">*</span></label>
                <select class="form-select" id="country" name="country" required>
                    <option value="">Select country…</option>
                    <option value="Tanzania">Tanzania</option>
                    <option value="Kenya">Kenya</option>
                    <option value="Uganda">Uganda</option>
                    <option value="Rwanda">Rwanda</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label for="industry" class="form-label">Industry</label>
                <select class="form-select" id="industry" name="industry">
                    <option value="">Select industry…</option>
                    <option value="retail">Retail / Shop</option>
                    <option value="restaurant">Restaurant / Café</option>
                    <option value="services">Services / Consulting</option>
                    <option value="manufacturing">Manufacturing</option>
                    <option value="healthcare">Healthcare</option>
                    <option value="transport">Transport / Logistics</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label for="company_size" class="form-label">Team size</label>
                <select class="form-select" id="company_size" name="company_size">
                    <option value="">Select size…</option>
                    <option value="1-5">1–5 staff</option>
                    <option value="6-20">6–20 staff</option>
                    <option value="21-100">21–100 staff</option>
                    <option value="100+">100+ staff</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100" id="btnSubmit">
            <i class="bi bi-check-circle me-1"></i> Create my account
        </button>
    </form>

    <div id="settingUp" class="progress-note p-4 text-center d-none">
        <div class="spinner-border text-primary mb-2" role="status"></div>
        <div class="fw-bold">Setting up your account…</div>
        <small class="text-muted">Creating your private database. This takes a few seconds — please don't close this page.</small>
    </div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Keep the field itself canonical — the server lowercases anyway, but showing
// the user what will actually be registered avoids a surprising correction.
$('#subdomain').on('input', function () {
    const clean = $(this).val().toLowerCase().replace(/[^a-z0-9-]/g, '');
    if (clean !== $(this).val()) $(this).val(clean);
    scheduleCheck();
});

let checkTimer = null;
function scheduleCheck() {
    clearTimeout(checkTimer);
    checkTimer = setTimeout(checkSubdomain, 350);   // debounce keystrokes
}

function checkSubdomain() {
    const sub = $('#subdomain').val();
    const $hint = $('#subHint');
    if (!sub) {
        $hint.removeClass('text-danger text-primary').addClass('text-muted')
             .text('Lowercase letters, numbers and hyphens. 3–32 characters.');
        return;
    }
    $.getJSON('/ajax/check_subdomain_availability.php', { subdomain: sub })
        .done(function (res) {
            $hint.removeClass('text-muted text-danger text-primary');
            if (res.available) {
                $hint.addClass('text-primary').html('<i class="bi bi-check-circle me-1"></i>' + res.message);
            } else {
                $hint.addClass('text-danger').html('<i class="bi bi-x-circle me-1"></i>' + (res.message || 'Not available.'));
            }
        })
        .fail(function () {
            $hint.removeClass('text-primary').addClass('text-muted').text('Could not check availability.');
        });
}

// Logo preview: purely cosmetic, the file itself travels with the form.
$('#company_logo').on('change', function () {
    const file = this.files && this.files[0];
    const $img = $('#logoPreview');
    const $ph  = $('#logoPlaceholder');
    if (!file) { $img.addClass('d-none').attr('src', ''); $ph.removeClass('d-none'); return; }
    const reader = new FileReader();
    reader.onload = function (e) { $img.attr('src', e.target.result).removeClass('d-none'); $ph.addClass('d-none'); };
    reader.readAsDataURL(file);
});

$('#registerForm').on('submit', function (e) {
    e.preventDefault();
    $('#formError').addClass('d-none').text('');

    if ($('#owner_password').val() !== $('#owner_password_confirm').val()) {
        $('#formError').removeClass('d-none').text('The two passwords do not match.');
        return;
    }

    const formEl = this;
    $('#registerForm').addClass('d-none');
    $('#settingUp').removeClass('d-none');

    $.ajax({
        url: '/actions/register_tenant.php',
        method: 'POST',
        dataType: 'json',
        data: new FormData(formEl),
        contentType: false,
        processData: false,
        timeout: 120000,                 // provisioning builds ~300 tables
        success: function (res) {
            if (res && res.success) {
                Swal.fire({
                    icon: 'success',
                    title: '<?= $pageLang === "sw" ? "Akaunti yako iko tayari! 🎉" : "Your account is ready! 🎉" ?>',
                    html: '<?= $pageLang === "sw"
                        ? "<strong>Majaribio ya bure yanaanza leo!</strong><br><small>Una siku 14 za kutumia mfumo bila malipo yoyote.</small>"
                        : "<strong>Your free trial starts today!</strong><br><small>You have 14 days to use the system completely free.</small>" ?>',
                    timer: 3000,
                    showConfirmButton: false
                });
                setTimeout(function () { window.location.href = res.login_url; }, 2200);
            } else {
                showFormError((res && res.message) || 'Registration failed.');
            }
        },
        error: function (xhr) {
            let msg = 'Registration failed. Please try again.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
            showFormError(msg);
        }
    });
});

function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const showing = inp.type === 'text';
    inp.type = showing ? 'password' : 'text';
    btn.querySelector('i').className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
}

function toggleLang() {
    const menu = document.getElementById('langMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.lang-switcher')) {
        const m = document.getElementById('langMenu');
        if (m) m.style.display = 'none';
    }
});

function showFormError(msg) {
    $('#settingUp').addClass('d-none');
    $('#registerForm').removeClass('d-none');
    $('#formError').removeClass('d-none').text(msg);
    $('html, body').animate({ scrollTop: 0 }, 200);
}
</script>
</body>
</html>
