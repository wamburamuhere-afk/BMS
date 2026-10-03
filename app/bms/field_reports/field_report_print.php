<?php
// Customer Visits — printable daily/period report. GET: date_from, date_to (or date),
// user_id (admins only; others always get their own), lang (sw|en; default = the user's).
// One click from the visits page: no options dialog. Portrait/landscape is chosen ONLY in
// the browser's own print dialog (Layout) — the table re-arranges itself for it with
// @media (orientation: portrait) (card/trial/training → one "Given" column).
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/field_reports_report.php';

if (!isAuthenticated()) { header('Location: ' . getUrl('login')); exit; }
if (!canView('field_visits')) { http_response_code(403); die(t('Access Denied')); }

[$from, $to] = frRequestRange($_GET);
$userId = frScopeUserId($_GET['user_id'] ?? null);   // non-admin => always self
$me     = (int)$_SESSION['user_id'];
$lang   = frReportLang($_GET['lang'] ?? null);

$visits = frFetchVisits($pdo, $from, $to, $userId);
$day = ($from === $to && $userId !== null) ? frDayStatus($pdo, $userId, $from) : null;
logActivity($pdo, $me, 'Print field report', "Opened field report $from..$to (" . ($userId ?? 'all staff') . ", $lang)");

loadLanguage($lang);   // everything below is in the report's language
// Every column for BOTH orientations; CSS shows the right set (see the <style> below).
$columns = [];
foreach (frReportColumns($from !== $to, $userId === null, true) as $k => $label) {
    $columns[$k] = $label;
    if ($k === 'training') $columns['given'] = t('Given');
}
$rows    = frReportRows($visits, $columns);
$summary = frReportSummary(frStats($visits));
$subject = frReportSubject($pdo, $userId);

// Relative column widths per orientation, normalised to 100% of the columns shown in it.
// Yes/No columns must fit "Hapana"; the long-text columns give way.
$wLand = ['sno' => 3, 'date' => 7, 'time' => 5, 'staff' => 10, 'location' => 14, 'client' => 11, 'phone' => 10,
          'business' => 10, 'card' => 7, 'trial' => 8, 'training' => 8, 'interest' => 8, 'joined' => 8, 'follow_up' => 9, 'notes' => 12];
$wPort = array_merge(array_diff_key($wLand, ['card' => 1, 'trial' => 1, 'training' => 1]),
                     ['sno' => 4, 'joined' => 10, 'follow_up' => 10, 'client' => 12, 'given' => 11]);
$pct = function (array $weights) use ($columns): array {
    $w = array_intersect_key($weights, $columns);
    $sum = array_sum($w) ?: 1;
    return array_map(fn($v) => round($v * 100 / $sum, 2), $w);
};
$widthCss = function (array $p): string {
    return implode(' ', array_map(fn($k, $v) => ".k-$k{width:$v%}", array_keys($p), $p));
};
$center = ['sno', 'time', 'card', 'trial', 'training', 'joined'];

$comp = [
    'name'    => getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM'),
    'address' => getSetting('company_physical_address', getSetting('company_address', '')),
    'phone'   => getSetting('company_phone', ''),
    'email'   => getSetting('company_email', ''),
    'logo'    => getSetting('company_logo', ''),
];
$printedBy   = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? 'System');
$printedRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'User';
$printedAt   = frDateLabel(date('Y-m-d'), $lang) . ' ' . ($lang === 'sw' ? 'saa' : 'at') . ' ' . date('H:i');
$q = fn(array $over) => '?' . http_build_query(array_merge(
    ['date_from' => $from, 'date_to' => $to, 'user_id' => $userId ?? '', 'lang' => $lang], $over));
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e(t('CUSTOMER VISITS REPORT') . ' — ' . $subject . ' — ' . $from) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1a252f; line-height: 1.45; padding: 20px 20px 0; background: #fff; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 18px; padding: 10px; background: #f4f6f8; border-radius: 8px; }
        .toolbar a, .toolbar button { padding: 6px 14px; font-weight: 600; font-size: 13px; border: 1px solid #b6ccfe; border-radius: 6px; background: #fff; color: #0d6efd; text-decoration: none; cursor: pointer; }
        .toolbar .on { background: #0d6efd; color: #fff; border-color: #0d6efd; }
        .toolbar .primary { background: #0d6efd; color: #fff; border-color: #0d6efd; }
        .toolbar .grp { display: inline-flex; gap: 4px; align-items: center; }
        .toolbar .lbl { font-size: 12px; color: #495057; margin-right: 2px; }
        .toolbar .lang-alt { margin-left: auto; font-weight: 500; font-size: 12px; border-color: #dee2e6; color: #495057; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 3px solid #3498db; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .company h1 { color: #0d6efd; font-size: 20px; font-weight: 800; text-transform: uppercase; margin-bottom: 6px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .company .row { display: flex; gap: 12px; align-items: flex-start; }
        .company img { max-height: 54px; width: auto; object-fit: contain; }
        .company p { font-size: 11px; margin: 1px 0; }
        .title-box { text-align: right; background: #3498db; color: #fff; padding: 12px 18px; border-radius: 8px; min-width: 240px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .title-box h2 { font-size: 15px; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 6px; }
        .title-box p { font-size: 11.5px; margin: 2px 0; }
        .status { margin: 0 0 12px; font-size: 11.5px; padding: 6px 10px; border-radius: 6px; background: #e7f0ff; border: 1px solid #b6ccfe; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .summary { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; margin-bottom: 14px; }
        .summary div { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 6px; padding: 6px 8px; text-align: center; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .summary b { display: block; font-size: 16px; color: #0d6efd; }
        .summary span { font-size: 10px; color: #1a252f; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 14px; }
        thead { display: table-header-group; }   /* header repeats on every printed page */
        th { background: #34495e; color: #fff; font-weight: 600; font-size: 10px; padding: 7px 5px; text-align: left; vertical-align: bottom; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        td { padding: 5px; font-size: 11px; vertical-align: top; border-bottom: 1px solid #e4e8ec; }
        /* Wrap at spaces only — a word is broken only when it alone is wider than its column. */
        th, td { white-space: normal; word-break: normal; overflow-wrap: break-word; hyphens: none; }
        /* On a phone SCREEN the A4 table scrolls sideways instead of being squeezed
           letter-by-letter; printing always uses the A4 page width. */
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        @media screen and (max-width: 1000px) { .table-wrap table { min-width: 1000px; } .scroll-hint { display: block !important; } }
        @media screen and (max-width: 1000px) and (orientation: portrait) { .table-wrap table { min-width: 720px; } }
        .scroll-hint { display: none; font-size: 11px; color: #6c757d; margin: 0 0 6px; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        tbody tr:nth-child(even) td { background: #f9fafb; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .c { text-align: center; }
        .empty { text-align: center; padding: 18px; color: #6c757d; }
        /* Landscape (default): all columns. Portrait — the page chosen in the browser's print
           dialog, or a phone held upright — card/trial/training become one "Given" column. */
        <?= $widthCss($pct($wLand)) ?>
        .k-given { display: none; }
        @media (orientation: portrait) {
            .k-card, .k-trial, .k-training { display: none; }
            .k-given { display: table-cell; }
            <?= $widthCss($pct($wPort)) ?>
            .summary { grid-template-columns: repeat(4, 1fr); }
        }
        @media print and (orientation: portrait) {
            td { font-size: 9.5px; padding: 4px 3px; }
            th { font-size: 8.5px; padding: 6px 3px; letter-spacing: 0; }
        }
        @page { size: A4; margin: 10mm 8mm 14mm 8mm; }   /* orientation: the print dialog's Layout */
        @media print { .toolbar { display: none !important; } body { padding: 0; } }
        @media (max-width: 700px) { .header { flex-direction: column; } .title-box { text-align: left; width: 100%; } .summary { grid-template-columns: repeat(2, 1fr); } }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body>

<div class="toolbar">
    <button type="button" class="primary" onclick="window.print()">&#128424; <?= $e(t('Print / Save as PDF')) ?></button>
    <a href="<?= $e(getUrl('api/field_reports/export.php') . $q([])) ?>">&#11015; <?= $e(t('Download Excel')) ?></a>
    <?php /* Already in the user's language; this is only for a client who needs the other one. */ ?>
    <a class="lang-alt" href="<?= $e($q(['lang' => $lang === 'sw' ? 'en' : 'sw'])) ?>"><?= $lang === 'sw' ? 'English' : 'Kiswahili' ?></a>
    <?php /* Opened in a new tab → close it; opened any other way → back to the visits page. */ ?>
    <button type="button" onclick="if (window.opener) { window.close(); } else { location.href = '<?= $e(getUrl('field_reports')) ?>'; }"><?= $e(t('Close')) ?></button>
</div>

<div class="header">
    <div class="company">
        <h1><?= $e($comp['name']) ?></h1>
        <div class="row">
            <?php if (!empty($comp['logo'])): ?><img src="<?= $e(getUrl($comp['logo'])) ?>" alt=""><?php endif; ?>
            <div>
                <?php if ($comp['address'] !== ''): ?><p><?= $e($comp['address']) ?></p><?php endif; ?>
                <?php if ($comp['phone'] !== ''): ?><p><?= $e(t('Phone')) ?>: <?= $e($comp['phone']) ?></p><?php endif; ?>
                <?php if ($comp['email'] !== ''): ?><p><?= $e(t('Email')) ?>: <?= $e($comp['email']) ?></p><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="title-box">
        <h2><?= $e(t('CUSTOMER VISITS REPORT')) ?></h2>
        <p><strong><?= $e(t('Staff')) ?>:</strong> <?= $e($subject) ?></p>
        <p><strong><?= $e(t('Date')) ?>:</strong> <?= $e(frRangeLabel($from, $to, $lang)) ?></p>
    </div>
</div>

<?php if ($from === $to && $userId !== null): ?>
<div class="status">
    <?php if ($day): ?>
        &#10003; <?= $e(sprintf(t('Report submitted at %s'), date('H:i', strtotime($day['submitted_at'])))) ?>
        <?php if ((int)$day['changed_after_submit']): ?> — <?= $e(t('updated after it was submitted')) ?><?php endif; ?>
    <?php else: ?>
        <?= $e(t('This report has not been submitted yet.')) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="summary">
    <?php foreach ($summary as $label => $value): ?>
    <div><b><?= (int)$value ?></b><span><?= $e($label) ?></span></div>
    <?php endforeach; ?>
</div>

<p class="scroll-hint">&#8596; <?= $e(t('Swipe sideways to see every column. The printout fits the page.')) ?></p>
<div class="table-wrap">
<table>
    <thead><tr><?php foreach ($columns as $k => $label): ?><th class="k-<?= $k ?><?= in_array($k, $center, true) ? ' c' : '' ?>"><?= $e($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td class="empty" colspan="<?= count($columns) - 1 ?>"><?= $e(t('No visits recorded for this period.')) ?></td></tr>
    <?php else: foreach ($rows as $r): ?>
        <tr><?php foreach ($columns as $k => $label): ?><td class="k-<?= $k ?><?= in_array($k, $center, true) ? ' c' : '' ?>"><?= nl2br($e($r[$k])) ?></td><?php endforeach; ?></tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<div class="footer-spacer"></div>
<div class="print-footer">
    <?php if ($lang === 'sw'): ?>
    <p>Ripoti hii imechapishwa na <strong><?= $e(caseFormat($printedBy)) ?></strong> &mdash; <strong><?= $e(ucfirst($printedRole)) ?></strong> <?= $e($printedAt) ?></p>
    <?php else: ?>
    <p>This document was Printed by <strong><?= $e(caseFormat($printedBy)) ?></strong> &mdash; <strong><?= $e(ucfirst($printedRole)) ?></strong> on <?= $e($printedAt) ?></p>
    <?php endif; ?>
    <p class="brand">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</p>
</div>
</body>
</html>
