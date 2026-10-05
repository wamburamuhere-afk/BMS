<?php
/**
 * app/superadmin/forgot.php — the platform operator's own password recovery.
 *
 * Standalone for the same reason login.php is: roots.php loads tenant
 * permissions, i18n and check_auth, none of which apply when no tenant is
 * involved.
 *
 * Plain POST form, no AJAX. login.php next door posts over jQuery, but this is
 * the page an operator reaches when they are already locked out of the panel
 * that administers every company on the platform. A blocked CDN must not be
 * the reason they stay locked out.
 *
 * Two states, as on the tenant side: no ?token= asks for the address; with one,
 * it offers the new password. Everything it enforces lives in
 * core/superadmin_recovery.php.
 */
require_once __DIR__ . '/../../core/superadmin_recovery.php';
require_once __DIR__ . '/../../helpers.php';

assertSuperadminHost();
superadminSessionReady();

if (isSuperadminLoggedIn()) {
    header('Location: ' . saUrl('profile'));   // already in: change it there
    exit;
}

$token  = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$screen = $token !== '' ? 'reset' : 'request';
$notice = null;
$csrf   = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Own check rather than csrf_check(), which answers in JSON and exits —
    // on a form page that would be a wall of machine text.
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
        $notice = ['type' => 'danger', 'text' => 'This form expired. Please try again.'];
    } elseif (($_POST['action'] ?? '') === 'set_password') {
        $r = saRecoveryComplete($token, (string)($_POST['password'] ?? ''), (string)($_POST['password_confirm'] ?? ''));
        if ($r['ok']) {
            $screen = 'done';
            $notice = ['type' => 'success', 'title' => 'Password changed',
                       'text' => 'Any lockout on the account has been lifted. Sign in with your new password.'];
        } else {
            $notice = ['type' => 'danger', 'text' => $r['error']];
        }
    } elseif (($_POST['action'] ?? '') === 'request') {
        $email  = trim((string)($_POST['email'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $res    = saRecoveryRequest($email, $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? ''));

        // One outcome whatever the address — the panel's login already refuses
        // to say whether an operator account exists, and this must not undo it.
        if ($res['throttled']) {
            $notice = ['type' => 'danger',
                       'text' => 'That is a lot of attempts in a row. Please wait a few minutes and try again.'];
        } else {
            $screen = 'sent';
            $notice = ['type' => 'success', 'title' => 'Check your email',
                       'text' => 'If an operator account exists for that address, instructions are on their way to it.'];
        }
    }
}

// A spent or expired link: say so rather than show a form that cannot work.
if ($screen === 'reset' && $_SERVER['REQUEST_METHOD'] !== 'POST' && saRecoveryVerify($token) === null) {
    $screen = 'request';
    $token  = '';
    $notice = ['type' => 'danger',
               'text' => 'This link is no longer valid. It may have expired, already been used, '
                       . 'or been replaced by a newer one. Please request a new one.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex,nofollow">
<title>Reset Operator Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background-color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .login-container {
        max-width: 450px; margin: 5% auto; padding: 2rem; background: #fff;
        border: 1px solid #b6ccfe; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,.08);
    }
    .brand { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #eee; }
    .brand i { font-size: 2.5rem; }
    .scope-note { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; font-size: .875rem; }
</style>
</head>
<body>
<div class="login-container">
    <div class="brand">
        <i class="bi bi-shield-lock-fill text-primary"></i>
        <h4 class="mt-2 mb-0">Platform Administration</h4>
        <small class="text-muted">Operator password recovery</small>
    </div>

    <?php if ($notice): ?>
        <div class="alert alert-<?= safe_output($notice['type'], 'info') ?>" role="alert">
            <?php if (!empty($notice['title'])): ?>
                <div class="fw-bold mb-1">
                    <i class="bi bi-<?= $notice['type'] === 'success' ? 'envelope-check' : 'exclamation-triangle-fill' ?> me-1"></i>
                    <?= safe_output($notice['title'], '') ?>
                </div>
            <?php endif; ?>
            <div style="font-size:.9rem;"><?= safe_output($notice['text'], '') ?></div>
        </div>
    <?php endif; ?>

    <?php if ($screen === 'request'): ?>

        <div class="scope-note p-3 mb-3">
            <i class="bi bi-info-circle text-primary me-1"></i>
            For <strong>platform operators</strong> only. If you are looking for your
            company's own account, use the Forgot password link on your company's
            sign-in page instead.
        </div>

        <form method="post" action="">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="request">
            <div class="mb-3">
                <label for="email" class="form-label">Operator email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope text-primary"></i></span>
                    <input type="email" class="form-control" id="email" name="email" required autofocus
                           maxlength="191" value="<?= safe_output($_POST['email'] ?? '', '') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-send me-1"></i> Send reset link
            </button>
        </form>

    <?php elseif ($screen === 'reset'): ?>

        <p class="text-muted" style="font-size:.9rem;">
            Choose a new password. Using this link also lifts any sign-in lockout on the account.
        </p>

        <form method="post" action="">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="token" value="<?= safe_output($token, '') ?>">
            <input type="hidden" name="action" value="set_password">
            <div class="mb-3">
                <label for="password" class="form-label">New password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-key text-primary"></i></span>
                    <input type="password" class="form-control" id="password" name="password"
                           required autofocus minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="mb-3">
                <label for="password_confirm" class="form-label">Confirm new password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-key text-primary"></i></span>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                           required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-check-circle me-1"></i> Save password
            </button>
        </form>

    <?php else: ?>

        <a href="<?= saUrl('login') ?>" class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-1"></i> Back to sign in
        </a>

    <?php endif; ?>

    <?php if ($screen !== 'done'): ?>
        <div class="text-center mt-3">
            <a href="<?= saUrl('login') ?>" class="text-decoration-none" style="font-size:.85rem;">
                <i class="bi bi-arrow-left me-1"></i>Back to sign in
            </a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
