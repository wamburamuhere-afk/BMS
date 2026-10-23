<?php
/**
 * continue-smart-pos.php — the link emailed to an owner whose Smart BMS request
 * was declined. The GET only shows a confirmation (mail scanners prefetch links);
 * the switch itself is a CSRF-protected POST. Root domain only, like register.php.
 */
require_once __DIR__ . '/core/tenant_registration.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

$sw = (($_COOKIE['bms_lang'] ?? '') === 'sw');
$tx = $sw ? [
    'title'   => 'Endelea na Smart POS',
    'lead'    => 'Ombi lako la Smart BMS halikuidhinishwa, lakini unaweza kuanza sasa hivi na Smart POS — kuuza, stock, mauzo ya mkopo, matumizi na ripoti. Username na password yako ni zile zile.',
    'button'  => 'Endelea na Smart POS',
    'ready'   => 'Akaunti yako ya Smart POS iko tayari',
    'trial'   => 'Majaribio yako ya bure yanaanza leo.',
    'user'    => 'Username',
    'signin'  => 'Ingia sasa',
    'invalid' => 'Kiungo hiki si sahihi.',
] : [
    'title'   => 'Continue with Smart POS',
    'lead'    => 'Your Smart BMS request was not approved, but you can start right now with Smart POS — selling, stock, credit sales, expenses and reports. Your username and password stay the same.',
    'button'  => 'Continue with Smart POS',
    'ready'   => 'Your Smart POS account is ready',
    'trial'   => 'Your free trial starts today.',
    'user'    => 'Username',
    'signin'  => 'Sign in now',
    'invalid' => 'This link is not valid.',
];

$id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$done  = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $done = continueDeclinedAsPos($id, $token);
    if (!$done['ok']) { $error = $done['error']; $done = null; }
} elseif ($id <= 0 || $token === '') {
    $error = $tx['invalid'];
}
?>
<!DOCTYPE html>
<html lang="<?= $sw ? 'sw' : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($tx['title']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f8ff; font-family: 'Plus Jakarta Sans', system-ui, sans-serif; color: #0f172a; }
    .box { max-width: 520px; margin: 8vh auto; background: #fff; border: 1px solid #e5eaf3; border-radius: 18px;
           box-shadow: 0 10px 34px rgba(15,23,42,.07); padding: 36px; }
    .btn-blue { background: #1d4ed8; color: #fff; font-weight: 700; padding: 13px; border-radius: 10px; }
    .btn-blue:hover { background: #1e40af; color: #fff; }
</style>
</head>
<body>
<div class="box mx-3 mx-sm-auto">
<?php if ($done): ?>
    <h1 class="h4 fw-bold mb-2"><?= htmlspecialchars($tx['ready']) ?></h1>
    <p class="text-secondary mb-3"><?= htmlspecialchars($done['company']) ?> · <?= htmlspecialchars($tx['trial']) ?></p>
    <p class="mb-4"><?= htmlspecialchars($tx['user']) ?>: <strong><?= htmlspecialchars($done['username']) ?></strong></p>
    <a class="btn btn-blue w-100" href="<?= htmlspecialchars($done['login_url'] . '?u=' . rawurlencode($done['username'])) ?>"><?= htmlspecialchars($tx['signin']) ?> →</a>
<?php elseif ($error): ?>
    <h1 class="h4 fw-bold mb-2"><?= htmlspecialchars($tx['title']) ?></h1>
    <p class="text-secondary mb-0"><?= htmlspecialchars($error) ?></p>
<?php else: ?>
    <h1 class="h4 fw-bold mb-2"><?= htmlspecialchars($tx['title']) ?></h1>
    <p class="text-secondary mb-4"><?= htmlspecialchars($tx['lead']) ?></p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
        <button type="submit" class="btn btn-blue w-100"><?= htmlspecialchars($tx['button']) ?></button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
