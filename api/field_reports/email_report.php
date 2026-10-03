<?php
// POST to (one or more addresses, comma/space separated), message (optional), date_from,
// date_to, user_id (admins only), lang, pdf (base64 of the PDF built in the browser).
// Emails the Customer Visits report with the PDF attached. The subject and the summary
// in the body are rebuilt here from the database — only the attachment comes from the
// browser, and it must really be a PDF.
require_once __DIR__ . '/_common.php';
require_once ROOT_DIR . '/core/field_reports_report.php';
require_once ROOT_DIR . '/core/mailer.php';
frRequirePost();

$me = (int)$_SESSION['user_id'];
$maxPerHour = 20;
$maxBytes   = 5 * 1024 * 1024;

// Recipients: at most 5 valid addresses.
$to = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['to'] ?? ''))))));
if (!$to) frJson(['success' => false, 'message' => t('Enter an email address.'), 'errors' => ['to' => 1]], 422);
if (count($to) > 5) frJson(['success' => false, 'message' => t('Send to at most 5 email addresses at a time.'), 'errors' => ['to' => 1]], 422);
foreach ($to as $addr) {
    if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || mb_strlen($addr) > 190) {
        frJson(['success' => false, 'message' => sprintf(t('"%s" is not a valid email address.'), $addr), 'errors' => ['to' => 1]], 422);
    }
}
$note = trim((string)($_POST['message'] ?? ''));
if (mb_strlen($note) > 1000) frJson(['success' => false, 'message' => t('The message is too long.'), 'errors' => ['message' => 1]], 422);

// The attachment: base64 PDF, size-limited, checked by its bytes.
$pdf = base64_decode((string)($_POST['pdf'] ?? ''), true);
if ($pdf === false || $pdf === '' || strlen($pdf) > $maxBytes || strncmp($pdf, '%PDF-', 5) !== 0) {
    frJson(['success' => false, 'message' => t('The report file could not be prepared. Please try again.')], 422);
}

// No more than $maxPerHour report emails per user per hour.
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND action = 'Email field report' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $c->execute([$me]);
    if ((int)$c->fetchColumn() >= $maxPerHour) {
        frJson(['success' => false, 'message' => t('Too many emails sent in the last hour. Please try again later.')], 429);
    }
} catch (PDOException $e) { /* no activity log table — no limit to apply */ }

[$from, $to_] = frRequestRange($_POST);
$userId = frScopeUserId($_POST['user_id'] ?? null);   // non-admin => always self
$lang = frReportLang($_POST['lang'] ?? null);

try {
    $visits  = frFetchVisits($pdo, $from, $to_, $userId);
    $subject = frReportSubject($pdo, $userId);
    $s = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
    $s->execute([$me]);
    $replyTo = trim((string)$s->fetchColumn());
} catch (PDOException $e) {
    error_log('field_reports/email_report: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
$userLang = $_SESSION['user_lang'] ?? 'en';

loadLanguage($lang);   // the email speaks the report's language
$company = getSetting('company_name', 'BMS');
$range   = frRangeLabel($from, $to_, $lang);
$sender  = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? '');
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$rowsHtml = '';
foreach (frReportSummary(frStats($visits)) as $label => $value) {
    $rowsHtml .= '<tr><td style="padding:6px 10px;border:1px solid #dee2e6;">' . $e($label) . '</td>'
               . '<td style="padding:6px 10px;border:1px solid #dee2e6;text-align:right;font-weight:bold;">' . (int)$value . '</td></tr>';
}
$body = ($note !== '' ? '<p>' . nl2br($e($note)) . '</p>' : '')
      . '<p><strong>' . $e(t('Staff')) . ':</strong> ' . $e($subject) . '<br>'
      . '<strong>' . $e(t('Date')) . ':</strong> ' . $e($range) . '</p>'
      . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin:6px 0 14px;">' . $rowsHtml . '</table>'
      . '<p>' . $e(t('The full report is attached as a PDF.')) . '</p>'
      . '<p style="color:#6c757d;">' . $e(sprintf(t('Sent by %s'), $sender)) . '</p>';
$mailSubject = t('CUSTOMER VISITS REPORT') . ' — ' . $subject . ' — ' . $range;

// Attach under a readable name; the temporary copy is removed whatever happens.
$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $subject), '_') ?: 'staff';
$name = ($lang === 'sw' ? 'Ripoti_ya_Ziara_' : 'Customer_Visits_Report_') . $slug . '_' . $from . ($from !== $to_ ? '_to_' . $to_ : '') . '.pdf';
$dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_fr_' . bin2hex(random_bytes(8));
$path = $dir . DIRECTORY_SEPARATOR . $name;
$sent = false;
try {
    if (@mkdir($dir, 0700) && file_put_contents($path, $pdf) !== false) {
        $opts = ['attachments' => [$path]];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $opts['reply_to'] = $replyTo;
        $sent = sendEmail($to, $mailSubject, $body, $opts);
    }
} finally {
    if (is_file($path)) @unlink($path);
    if (is_dir($dir)) @rmdir($dir);
}

loadLanguage($userLang);   // the reply speaks the user's language
if (!$sent) {
    error_log('field_reports/email_report: send failed — ' . mailer_last_error());
    frJson(['success' => false, 'message' => t('The email could not be sent. Check the email settings or try again.')], 502);
}
logActivity($pdo, $me, 'Email field report', "Emailed field report $from..$to_ ($subject) to " . implode(', ', $to));
frJson(['success' => true, 'message' => sprintf(t('Report sent to %s.'), implode(', ', $to))]);
