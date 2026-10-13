<?php
// POST pdf (base64 of the PDF built in the browser), date_from, date_to, user_id (admins
// only), lang. For WhatsApp on a device that cannot attach a file: keeps the PDF behind a
// random link that expires, and returns that link — the message carries only the link.
//
// Same token model as the shop-catalog and e-signature links: the raw token is returned in
// this response only; just its SHA-256 is stored. The PDF is kept in the database, never in
// a public folder, and field_report_pdf.php serves it until it expires.
require_once __DIR__ . '/_common.php';
require_once ROOT_DIR . '/core/field_reports_report.php';
frRequirePost();

$me = (int)$_SESSION['user_id'];
$maxPerHour = 20;
$maxBytes   = 5 * 1024 * 1024;
$validDays  = 7;

$pdf = base64_decode((string)($_POST['pdf'] ?? ''), true);
if ($pdf === false || $pdf === '' || strlen($pdf) > $maxBytes || strncmp($pdf, '%PDF-', 5) !== 0) {
    frJson(['success' => false, 'message' => t('The report file could not be prepared. Please try again.')], 422);
}

try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM field_report_shares WHERE created_by = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $c->execute([$me]);
    if ((int)$c->fetchColumn() >= $maxPerHour) {
        frJson(['success' => false, 'message' => t('Too many reports shared in the last hour. Please try again later.')], 429);
    }

    [$from, $to] = frRequestRange($_POST);
    $userId = frScopeUserId($_POST['user_id'] ?? null);   // non-admin => always self
    $lang = frReportLang($_POST['lang'] ?? null);
    $filename = frReportFilename(frReportSubject($pdo, $userId), $lang, $from, $to);

    // Expired copies are of no use to anyone — remove them as new ones are made.
    $pdo->exec("DELETE FROM field_report_shares WHERE expires_at < NOW()");

    $token = bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO field_report_shares (token_hash, filename, pdf, created_by, created_at, expires_at)
                   VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL $validDays DAY))")
        ->execute([hash('sha256', $token), $filename, $pdf, $me]);
} catch (PDOException $e) {
    error_log('field_reports/share_link: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}

logActivity($pdo, $me, 'Share field report link', "Shared field report $from..$to as a link ($filename)");
frJson(['success' => true, 'url' => buildUrl('report-pdf') . '?t=' . $token, 'days' => $validDays, 'filename' => $filename]);
