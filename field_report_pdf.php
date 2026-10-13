<?php
/**
 * field_report_pdf.php — public, read-only: serves one Customer Visits report PDF that a
 * Sales Officer shared on WhatsApp from a device that cannot attach files
 * (api/field_reports/share_link.php). Reached only through that link.
 *
 * Same model as shop_catalog.php / sign_document.php: no session needed, the link's token
 * is the credential, only its SHA-256 is stored, and an unknown, wrong or expired link all
 * get the same message, so nothing confirms whether a token ever existed. No writes.
 * Deliberately does not call includeHeader() (that forces a login).
 */
require_once __DIR__ . '/roots.php';

$lang = in_array($_GET['lang'] ?? '', SUPPORTED_LANGUAGES, true) ? $_GET['lang'] : 'en';
loadLanguage($lang);

$token = trim((string)($_GET['t'] ?? ''));
$row = null;
if (preg_match('/^[a-f0-9]{48}$/', $token)) {
    try {
        $s = $pdo->prepare("SELECT filename, pdf FROM field_report_shares WHERE token_hash = ? AND expires_at >= NOW() LIMIT 1");
        $s->execute([hash('sha256', $token)]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $row = null;   // table not there yet on this tenant
    }
}

if ($row) {
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $row['filename']);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($row['pdf']));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo $row['pdf'];
    exit;
}

http_response_code(404);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= te('Report not available') ?></title>
    <style>
        body { margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fff; color: #212529; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; }
        .box { max-width: 420px; text-align: center; border: 1px solid #b6ccfe; background: #e7f0ff; border-radius: 12px; padding: 24px; }
        h1 { color: #0d6efd; font-size: 20px; margin: 0 0 8px; }
        p { margin: 0; font-size: 15px; }
    </style>
</head>
<body>
    <div class="box">
        <h1><?= te('Report not available') ?></h1>
        <p><?= te('This link has expired or is not valid. Ask the sender to share the report again.') ?></p>
    </div>
</body>
</html>
