<?php
/**
 * change-password.php — the one page a user flagged `must_change_password`
 * can reach until they have chosen a password of their own.
 *
 * WHY THIS EXISTS. createTenantAsOperator() lets a platform operator type the
 * first password for a new company's administrator. That is convenient at
 * onboarding and unacceptable afterwards: until the owner changes it, somebody
 * outside the company knows how to sign in as its administrator. Marking the
 * account at provisioning and refusing to serve any other page until it is
 * cleared turns "they really should change it" into "they cannot avoid it".
 *
 * The CURRENT password is still required. The person arriving here was given it
 * by the operator, so they have it; asking for it means a borrowed or forgotten
 * session on a shared machine cannot be used to seize the account.
 *
 * Plain POST form, no JavaScript — same reasoning as forgot-password.php.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/core/account_recovery.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$st = $pdo->prepare("SELECT username, password, email, first_name, last_name,
                            COALESCE(must_change_password, 0) AS must_change_password
                       FROM users WHERE user_id = ? LIMIT 1");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    header('Location: logout');
    exit;
}

// Nothing to force — this page is not a second profile page.
if ((int)$me['must_change_password'] !== 1) {
    header('Location: dashboard');
    exit;
}

$pageLang = (!empty($_COOKIE['bms_lang']) && in_array($_COOKIE['bms_lang'], ['en', 'sw'], true))
    ? $_COOKIE['bms_lang'] : 'en';
if (!empty($_GET['lang']) && in_array($_GET['lang'], ['en', 'sw'], true)) $pageLang = $_GET['lang'];

$T = [
    'sw' => [
        'title'    => 'Badilisha Nenosiri',
        'heading'  => 'Weka nenosiri lako mwenyewe',
        'lead'     => 'Akaunti yako ilitengenezwa na mtoa huduma, na nenosiri la sasa analijua. Kabla ya kuendelea, weka nenosiri ambalo wewe tu ndiye unalijua.',
        'current'  => 'Nenosiri la sasa',
        'new'      => 'Nenosiri jipya',
        'confirm'  => 'Thibitisha nenosiri jipya',
        'hint'     => 'Angalau herufi 8, lenye herufi na namba.',
        'save'     => 'Hifadhi na uendelee',
        'logout'   => 'Toka',
        'wrong'    => 'Nenosiri la sasa si sahihi.',
        'same'     => 'Nenosiri jipya lazima litofautiane na la sasa.',
        'csrf'     => 'Muda wa fomu umeisha. Tafadhali jaribu tena.',
    ],
    'en' => [
        'title'    => 'Change Password',
        'heading'  => 'Set a password of your own',
        'lead'     => 'Your account was created by your provider, who knows the current password. Before you continue, choose one only you know.',
        'current'  => 'Current password',
        'new'      => 'New password',
        'confirm'  => 'Confirm new password',
        'hint'     => 'At least 8 characters, with a letter and a number.',
        'save'     => 'Save and continue',
        'logout'   => 'Sign out',
        'wrong'    => 'The current password is not correct.',
        'same'     => 'The new password must be different from the current one.',
        'csrf'     => 'This form expired. Please try again.',
    ],
];
$tr    = $T[$pageLang];
$error = null;
$csrf  = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
        $error = $tr['csrf'];
    } else {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');

        if (!password_verify($current, (string)$me['password'])) {
            $error = $tr['wrong'];
        } elseif (password_verify($new, (string)$me['password'])) {
            $error = $tr['same'];
        } elseif ($err = recoveryPasswordError($new, $confirm)) {
            $error = $err;
        } else {
            $pdo->prepare("
                UPDATE users
                   SET password = ?, password_changed_at = ?, must_change_password = 0
                 WHERE user_id = ?
            ")->execute([password_hash($new, PASSWORD_DEFAULT), recoveryNow(), $userId]);

            // The operator's copy of the old password is now worthless; say so
            // to the account holder, same as any other password change.
            recoveryNotifyPasswordChanged($pdo, $userId);

            if (function_exists('logActivity')) {
                @logActivity($pdo, $userId, 'Set own password (first sign-in after provisioning)');
            }

            header('Location: dashboard');
            exit;
        }
    }
}

$company_name = 'BMS';
try {
    $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'company_name'")->fetchColumn();
    if ($v) $company_name = $v;
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($tr['title']) ?> — <?= htmlspecialchars($company_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #3498db; --secondary-color: #2980b9; --dark-text: #2c3e50; }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .login-container { max-width: 460px; margin: 5% auto; padding: 2rem; background: #fff;
                           border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,.1); }
        .logo-placeholder { width: 64px; height: 64px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px rgba(52,152,219,.3); }
        .logo-placeholder i { font-size: 1.8rem; color: #fff; }
        .form-control { padding: 12px 15px; border-radius: 5px; border: 1px solid #ddd; }
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 .25rem rgba(52,152,219,.25); }
        .input-group-text { background-color: #e9ecef; border-right: none; }
        .input-group .form-control { border-left: none; }
        .btn-login { background-color: var(--primary-color); border: none; padding: 12px;
                     font-weight: 600; color: #fff; }
        .btn-login:hover { background-color: var(--secondary-color); color: #fff; }
        @media (max-width: 575.98px) { .login-container { margin: 2% auto !important; padding: 1.25rem !important; } }
    </style>
</head>
<body>
<div class="container">
    <div class="login-container">
        <div class="text-center mb-3">
            <div class="logo-placeholder"><i class="fas fa-key"></i></div>
            <h5 class="fw-bold mt-2 mb-0" style="color:var(--dark-text);"><?= htmlspecialchars($tr['heading']) ?></h5>
        </div>

        <p class="text-muted" style="font-size:.86rem;"><?= htmlspecialchars($tr['lead']) ?></p>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="font-size:.9rem;">
                <i class="fas fa-circle-exclamation me-1"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div class="mb-3">
                <label for="current_password" class="form-label"><?= htmlspecialchars($tr['current']) ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-unlock"></i></span>
                    <input type="password" class="form-control" id="current_password" name="current_password"
                           required autofocus autocomplete="current-password">
                </div>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label"><?= htmlspecialchars($tr['new']) ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password"
                           required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-text"><?= htmlspecialchars($tr['hint']) ?></div>
            </div>
            <div class="mb-3">
                <label for="password_confirm" class="form-label"><?= htmlspecialchars($tr['confirm']) ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                           required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <button type="submit" class="btn btn-login w-100">
                <i class="fas fa-check me-1"></i> <?= htmlspecialchars($tr['save']) ?>
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="logout" style="font-size:.85rem;color:#6c757d;text-decoration:none;">
                <?= htmlspecialchars($tr['logout']) ?>
            </a>
        </div>
    </div>
</div>
</body>
</html>
