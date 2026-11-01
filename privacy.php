<?php
/**
 * privacy.php — public Privacy Policy, reachable without signing in at /privacy.
 *
 * Google requires a privacy policy link before the BMS Backups OAuth app can be
 * published (backup_plan.md §4). No session needed. The only data read is the
 * serving company's own Company Profile (name, email, phone) from system_settings,
 * so each tenant's page shows its own contact; blanks and the install placeholders
 * fall back to BJP Technologies' details. Nothing else comes from the database.
 */
header('Content-Type: text/html; charset=utf-8');

$bjpName  = 'BJP Technologies Co. Ltd';
$bjpEmail = 'bjptechnologieslimited@gmail.com';
$coName = $bjpName; $coEmail = $bjpEmail; $coPhone = '';
try {
    require_once __DIR__ . '/includes/config.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings
                             WHERE setting_key IN ('company_name','company_email','company_phone')")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
        $n = trim((string)($rows['company_name'] ?? ''));
        $e = trim((string)($rows['company_email'] ?? ''));
        $t = trim((string)($rows['company_phone'] ?? ''));
        if ($n !== '' && $n !== 'My Company') { $coName = $n; }
        if ($e !== '' && $e !== 'info@example.com' && filter_var($e, FILTER_VALIDATE_EMAIL)) { $coEmail = $e; }
        $coPhone = $t;
    }
} catch (Throwable $e) { /* fall back to BJP details */ }
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Policy | Business Management System</title>
<style>
    body { margin: 0; background: #fff; color: #212529; font: 16px/1.6 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    main { max-width: 760px; margin: 0 auto; padding: 32px 16px 48px; }
    h1 { color: #0a3d8f; font-size: 1.8rem; margin: 0 0 4px; }
    h2 { color: #0a3d8f; font-size: 1.15rem; margin: 28px 0 8px; }
    .updated { color: #6c757d; font-size: .9rem; margin: 0 0 24px; }
    a { color: #0d6efd; }
</style>
</head>
<body>
<main>
    <h1>Privacy Policy</h1>
    <p class="updated">Last updated: 1 November 2026</p>

    <p><?= $h($coName) ?> ("we") uses the Business Management System ("BMS"), business software provided by BJP Technologies Co. Ltd, Tanzania ("BJP"), to run its operations. This policy explains how information is handled in BMS.</p>

    <h2>Information we handle</h2>
    <p>Each company using BMS enters and controls its own business records, such as customers, suppliers, products, sales, staff and accounting records, and the files it uploads. We also hold account details of the people who use the system, such as names, email addresses and phone numbers, along with sign-in and activity records used for security.</p>

    <h2>How we use it</h2>
    <p>We use this information to provide and secure BMS, support our users, send messages related to the service, and protect each company's data through backups.</p>

    <h2>Separation between companies</h2>
    <p>Each company's data is kept in its own database, and its uploaded files and backups are kept in its own folders.</p>

    <h2>Backups and Google Drive</h2>
    <p>BMS backs up company data automatically. An encrypted copy of the nightly backups is stored by BJP, the BMS provider, in a Google Drive account owned by BJP, so the data can be recovered if the server is lost. The data is encrypted on our server before upload.</p>
    <p>The BMS Backups application uses Google's <code>drive.file</code> permission, which limits it to files the application itself creates. It uses this access only to store, verify, retrieve and remove these backup copies. It does not access other files in the Google Drive account, and it does not ask for access to the Google accounts of BMS users.</p>
    <p>The use of information received from Google APIs adheres to the <a href="https://developers.google.com/terms/api-services-user-data-policy">Google API Services User Data Policy</a>, including the Limited Use requirements.</p>

    <h2>Sharing</h2>
    <p>We do not sell personal information. We share information only with providers we use to run the service, such as hosting, email and Google Drive for encrypted backups, with features a company chooses to use, or where the law requires it.</p>

    <h2>Retention</h2>
    <p>Company data is kept while the company uses BMS. Automatic backup copies are removed on a schedule, with monthly copies kept for about 12 months.</p>

    <h2>Your choices</h2>
    <p>If your details are held in a company's BMS records, contact that company first, because it controls those records. You can also contact us at the details below.</p>

    <h2>Contact</h2>
    <p><?= $h($coName) ?><br>
    Email: <a href="mailto:<?= $h($coEmail) ?>"><?= $h($coEmail) ?></a><?php if ($coPhone !== ''): ?><br>
    Phone: <?= $h($coPhone) ?><?php endif; ?></p>
    <?php if ($coName !== $bjpName): ?>
    <p>BMS provider: <?= $h($bjpName) ?>, Tanzania &mdash; <a href="mailto:<?= $h($bjpEmail) ?>"><?= $h($bjpEmail) ?></a></p>
    <?php endif; ?>
</main>
</body>
</html>
