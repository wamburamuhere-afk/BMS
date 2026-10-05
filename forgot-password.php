<?php
/**
 * forgot-password.php — the page login.php has been linking to since it was
 * written, and which did not exist until now (login.php:333 → a raw 404).
 *
 * Two states in one file, because they are two halves of one flow and splitting
 * them would mean two pages that must agree about the token:
 *
 *   no ?token=  → ask for the email address, send a link (or a username)
 *   ?token=…    → verify it, then let the admin choose a new password
 *
 * Deliberately a plain POST form, not AJAX. Everything else in this codebase
 * posts over jQuery, but this is the page someone reaches when they are ALREADY
 * locked out: if a CDN is blocked or a script fails, the page must still work.
 * It needs no JavaScript at all.
 *
 * Visual language is copied from login.php on purpose (same card, same CSS
 * variables, same Font Awesome icons, same EN/SW switcher) — a visitor walks
 * straight here from that page, and a different-looking page in the middle of a
 * password reset reads as a phishing site.
 *
 * The security rules live in core/account_recovery.php; this file only renders
 * what those functions return and never branches on whether an account exists.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/core/account_recovery.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Already signed in? Then nothing here applies — change the password in the
// profile page instead, where the current one is required.
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard');
    exit;
}

// ── Language: URL param → cookie → default 'en' (same order as login.php) ────
$pageLang = 'en';
if (!empty($_GET['lang']) && in_array($_GET['lang'], ['en', 'sw'], true)) {
    $pageLang = $_GET['lang'];
    setcookie('bms_lang', $pageLang, time() + 365 * 24 * 3600, '/');
} elseif (!empty($_COOKIE['bms_lang']) && in_array($_COOKIE['bms_lang'], ['en', 'sw'], true)) {
    $pageLang = $_COOKIE['bms_lang'];
}

$T = [
    'sw' => [
        'page_title'    => 'Umesahau Nenosiri',
        'heading'       => 'Umesahau nenosiri?',
        'lead'          => 'Andika anwani ya barua pepe ya akaunti yako ya msimamizi. Tutakutumia kiungo cha kuweka nenosiri jipya.',
        'email'         => 'Barua pepe',
        'email_ph'      => 'Andika barua pepe yako',
        'send_link'     => 'Nitumie kiungo',
        'send_username' => 'Nikumbushe jina langu la mtumiaji',
        'or'            => 'au',
        'back_login'    => 'Rudi kuingia',
        'sent_title'    => 'Angalia barua pepe yako',
        'sent_body'     => 'Kama kuna akaunti ya msimamizi yenye anwani hiyo, tumetuma maelekezo hapo sasa hivi. Angalia pia folda ya taka (spam).',
        'throttled'     => 'Umejaribu mara nyingi mfululizo. Tafadhali subiri dakika chache kisha ujaribu tena.',
        'reset_heading' => 'Weka nenosiri jipya',
        'reset_lead'    => 'Chagua nenosiri ambalo wewe tu ndiye unalijua.',
        'new_pw'        => 'Nenosiri jipya',
        'confirm_pw'    => 'Thibitisha nenosiri',
        'save_pw'       => 'Hifadhi nenosiri',
        'pw_hint'       => 'Angalau herufi 8, lenye herufi na namba.',
        'bad_token'     => 'Kiungo hiki hakitumiki tena. Linaweza kuwa limeisha muda, limekwisha tumika, au limebadilishwa na jipya. Tafadhali omba kiungo kipya.',
        'done_title'    => 'Nenosiri limebadilishwa',
        'done_body'     => 'Umetolewa nje kwenye vifaa vyote. Sasa ingia kwa nenosiri lako jipya.',
        'go_login'      => 'Ingia sasa',
        'csrf'          => 'Muda wa fomu umeisha. Tafadhali jaribu tena.',
        'no_email'      => 'Tafadhali andika barua pepe yako.',
        'admin_only'    => 'Njia hii ni kwa wasimamizi pekee. Kama wewe ni mfanyakazi, muulize msimamizi wa kampuni yako akubadilishie nenosiri.',
    ],
    'en' => [
        'page_title'    => 'Forgot Password',
        'heading'       => 'Forgot your password?',
        'lead'          => 'Enter the email address on your administrator account. We will send you a link to set a new password.',
        'email'         => 'Email address',
        'email_ph'      => 'Enter your email',
        'send_link'     => 'Send me a link',
        'send_username' => 'Remind me of my username',
        'or'            => 'or',
        'back_login'    => 'Back to sign in',
        'sent_title'    => 'Check your email',
        'sent_body'     => 'If an administrator account exists for that address, we have just sent instructions to it. Remember to check your spam folder.',
        'throttled'     => 'That is a lot of attempts in a row. Please wait a few minutes and try again.',
        'reset_heading' => 'Set a new password',
        'reset_lead'    => 'Choose a password only you know.',
        'new_pw'        => 'New password',
        'confirm_pw'    => 'Confirm password',
        'save_pw'       => 'Save password',
        'pw_hint'       => 'At least 8 characters, with a letter and a number.',
        'bad_token'     => 'This link is no longer valid. It may have expired, already been used, or been replaced by a newer one. Please request a new link.',
        'done_title'    => 'Password changed',
        'done_body'     => 'You have been signed out everywhere. Sign in now with your new password.',
        'go_login'      => 'Sign in now',
        'csrf'          => 'This form expired. Please try again.',
        'no_email'      => 'Please enter your email address.',
        'admin_only'    => 'This route is for administrators. If you are a staff member, ask your company administrator to set a new password for you.',
    ],
];
$tr = $T[$pageLang];

// ── Company branding, same source login.php uses ─────────────────────────────
$company_name = 'BMS';
$company_logo = null;
try {
    $s = $pdo->query("SELECT setting_key, setting_value FROM settings
                       WHERE setting_key IN ('company_name','company_logo')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($s['company_name'])) $company_name = $s['company_name'];
    if (!empty($s['company_logo'])) $company_logo = $s['company_logo'];
} catch (Throwable $e) { /* branding is cosmetic — never block recovery */ }

// ── State ────────────────────────────────────────────────────────────────────
$token   = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$notice  = null;      // ['type' => 'success'|'danger'|'info', 'title' => ?, 'text' => string]
$screen  = $token !== '' ? 'reset' : 'request';
$csrf    = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Own check rather than csrf_check(): that helper answers in JSON and
    // exits, which on a form page would show a locked-out admin a wall of
    // machine text instead of "please try again".
    $posted = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted)) {
        $notice = ['type' => 'danger', 'text' => $tr['csrf']];
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'set_password') {
            $r = recoveryCompleteReset(
                $pdo,
                $token,
                (string)($_POST['password'] ?? ''),
                (string)($_POST['password_confirm'] ?? '')
            );
            if ($r['ok']) {
                $screen = 'done';
                $notice = ['type' => 'success', 'title' => $tr['done_title'], 'text' => $tr['done_body']];
            } else {
                $screen = 'reset';
                $notice = ['type' => 'danger', 'text' => $r['error']];
            }
        } elseif ($action === 'request_reset' || $action === 'request_username') {
            $email = trim((string)($_POST['email'] ?? ''));
            if ($email === '') {
                $notice = ['type' => 'danger', 'text' => $tr['no_email']];
            } else {
                $res = $action === 'request_username'
                    ? recoveryRequestUsername($pdo, $email, $company_name)
                    : recoveryRequestReset($pdo, $email, $company_name);

                // One outcome for every case. The throttle is the only thing
                // that can say something different, and it is keyed on how many
                // attempts were made — never on whether an account was found.
                $notice = $res['throttled']
                    ? ['type' => 'danger', 'text' => $tr['throttled']]
                    : ['type' => 'success', 'title' => $tr['sent_title'], 'text' => $tr['sent_body']];
                if (!$res['throttled']) $screen = 'sent';
            }
        }
    }
}

// A token that is already spent or expired: say so before showing a form that
// could never work.
if ($screen === 'reset' && $_SERVER['REQUEST_METHOD'] !== 'POST' && recoveryVerifyToken($pdo, $token) === null) {
    $screen = 'request';
    $token  = '';
    $notice = ['type' => 'danger', 'text' => $tr['bad_token']];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- The token lives in this URL; never hand it to another site in a Referer header. -->
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($tr['page_title']) ?> — <?= htmlspecialchars($company_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
        }
        body { background-color: var(--light-bg); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .login-container {
            max-width: 450px; margin: 5% auto; padding: 2rem;
            background: #fff; border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,.1);
        }
        .logo-container { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #eee; }
        .company-logo { width: 70px; height: 70px; object-fit: contain; margin-bottom: .5rem; border-radius: 8px; }
        .logo-placeholder {
            width: 70px; height: 70px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 12px; display: inline-flex; align-items: center;
            justify-content: center; margin-bottom: .5rem;
            box-shadow: 0 4px 12px rgba(52,152,219,.3);
        }
        .logo-placeholder i { font-size: 2rem; color: #fff; }
        .company-name { font-size: 1.1rem; font-weight: 700; color: var(--dark-text); margin-bottom: .2rem; letter-spacing: .3px; }
        .form-control { padding: 12px 15px; border-radius: 5px; border: 1px solid #ddd; }
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 .25rem rgba(52,152,219,.25); }
        .input-group-text { background-color: #e9ecef; border-right: none; }
        .input-group .form-control { border-left: none; }
        .btn-login {
            background-color: var(--primary-color); border: none; padding: 12px;
            font-weight: 600; letter-spacing: .5px; color: #fff; transition: all .3s;
        }
        .btn-login:hover { background-color: var(--secondary-color); color: #fff; transform: translateY(-2px); }
        .lang-switcher { position: absolute; top: 14px; right: 14px; }
        .lang-switcher a {
            font-size: .78rem; color: #666; text-decoration: none;
            padding: 4px 8px; border-radius: 6px;
        }
        .lang-switcher a.active { font-weight: 700; color: var(--primary-color); background: #f4f8ff; }
        .divider { display: flex; align-items: center; gap: 12px; color: #aaa; font-size: .8rem; margin: 1rem 0; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e5e5e5; }
        @media (max-width: 575.98px) {
            .login-container { margin: 2% auto !important; padding: 1.25rem !important; }
            .company-logo, .logo-placeholder { width: 54px !important; height: 54px !important; }
            .logo-placeholder i { font-size: 1.5rem !important; }
            .company-name { font-size: .95rem !important; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="login-container position-relative">

        <div class="lang-switcher">
            <a href="?lang=en<?= $token !== '' ? '&token=' . urlencode($token) : '' ?>" class="<?= $pageLang === 'en' ? 'active' : '' ?>">EN</a>
            <a href="?lang=sw<?= $token !== '' ? '&token=' . urlencode($token) : '' ?>" class="<?= $pageLang === 'sw' ? 'active' : '' ?>">SW</a>
        </div>

        <div class="logo-container">
            <?php if ($company_logo): ?>
                <img src="<?= htmlspecialchars($company_logo) ?>" alt="<?= htmlspecialchars($company_name) ?>" class="company-logo">
            <?php else: ?>
                <div class="logo-placeholder"><i class="fas fa-building"></i></div>
            <?php endif; ?>
            <h5 class="company-name"><?= htmlspecialchars($company_name) ?></h5>
        </div>

        <?php if ($notice): ?>
            <div class="alert alert-<?= htmlspecialchars($notice['type']) ?>" role="alert">
                <?php if (!empty($notice['title'])): ?>
                    <div class="fw-bold mb-1">
                        <i class="fas fa-<?= $notice['type'] === 'success' ? 'envelope-circle-check' : 'circle-exclamation' ?> me-1"></i>
                        <?= htmlspecialchars($notice['title']) ?>
                    </div>
                <?php endif; ?>
                <div style="font-size:.9rem;"><?= htmlspecialchars($notice['text']) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($screen === 'request'): ?>

            <h6 class="fw-bold mb-1"><?= htmlspecialchars($tr['heading']) ?></h6>
            <p class="text-muted" style="font-size:.86rem;"><?= htmlspecialchars($tr['lead']) ?></p>

            <form method="post" action="">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <div class="mb-3">
                    <label for="email" class="form-label"><?= htmlspecialchars($tr['email']) ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" required autofocus
                               maxlength="191" placeholder="<?= htmlspecialchars($tr['email_ph']) ?>"
                               value="<?= htmlspecialchars((string)($_POST['email'] ?? '')) ?>">
                    </div>
                </div>
                <button type="submit" name="action" value="request_reset" class="btn btn-login w-100 mb-2">
                    <i class="fas fa-paper-plane me-1"></i> <?= htmlspecialchars($tr['send_link']) ?>
                </button>
                <div class="divider"><?= htmlspecialchars($tr['or']) ?></div>
                <button type="submit" name="action" value="request_username" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-id-badge me-1"></i> <?= htmlspecialchars($tr['send_username']) ?>
                </button>
            </form>

            <p class="text-muted mt-3 mb-0" style="font-size:.78rem;">
                <i class="fas fa-circle-info me-1"></i><?= htmlspecialchars($tr['admin_only']) ?>
            </p>

        <?php elseif ($screen === 'reset'): ?>

            <h6 class="fw-bold mb-1"><?= htmlspecialchars($tr['reset_heading']) ?></h6>
            <p class="text-muted" style="font-size:.86rem;"><?= htmlspecialchars($tr['reset_lead']) ?></p>

            <form method="post" action="">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="set_password">
                <div class="mb-3">
                    <label for="password" class="form-label"><?= htmlspecialchars($tr['new_pw']) ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               required autofocus autocomplete="new-password" minlength="8">
                    </div>
                    <div class="form-text"><?= htmlspecialchars($tr['pw_hint']) ?></div>
                </div>
                <div class="mb-3">
                    <label for="password_confirm" class="form-label"><?= htmlspecialchars($tr['confirm_pw']) ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                               required autocomplete="new-password" minlength="8">
                    </div>
                </div>
                <button type="submit" class="btn btn-login w-100">
                    <i class="fas fa-check me-1"></i> <?= htmlspecialchars($tr['save_pw']) ?>
                </button>
            </form>

        <?php else: /* sent | done */ ?>

            <div class="text-center">
                <a href="login" class="btn btn-login w-100">
                    <i class="fas fa-right-to-bracket me-1"></i>
                    <?= htmlspecialchars($screen === 'done' ? $tr['go_login'] : $tr['back_login']) ?>
                </a>
            </div>

        <?php endif; ?>

        <?php if ($screen === 'request' || $screen === 'reset'): ?>
            <div class="text-center mt-3">
                <a href="login" style="font-size:.85rem;color:var(--primary-color);text-decoration:none;">
                    <i class="fas fa-arrow-left me-1"></i><?= htmlspecialchars($tr['back_login']) ?>
                </a>
            </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
