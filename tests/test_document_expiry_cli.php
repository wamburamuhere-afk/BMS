<?php
/**
 * Document Expiry CLI Test Suite
 * Run: php tests/test_document_expiry_cli.php
 * Exit 0 = all pass (safe to push)
 * Exit 1 = failures found (push blocked)
 *
 * Static-analysis suite — needs no database, so it runs identically in the
 * local pre-push hook and in GitHub Actions CI.
 */

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $msg): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $msg\n"; }
function fail(string $msg): void  { global $failures; $failures++; echo "  \033[31m❌ $msg\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string {
    $path = "$root/$rel";
    return file_exists($path) ? file_get_contents($path) : '';
}
function want(string $hay, string $needle, string $okMsg, string $failMsg): void {
    str_contains($hay, $needle) ? pass($okMsg) : fail($failMsg);
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. Required feature files exist');
// ─────────────────────────────────────────────────────────────────────────────
$required = [
    'migrations/2026_05_21_document_expiry_tracking.php',
    'cron/check_document_expiry.php',
    'app/constant/document/document_library.php',
    'api/document/upload_document.php',
    'api/document/get_documents.php',
    'app/dashboard.php',
    'header.php',
];
foreach ($required as $f) {
    file_exists("$root/$f") ? pass($f) : fail("MISSING: $f");
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. PHP syntax — all feature files (+ files that must stay intact)');
// ─────────────────────────────────────────────────────────────────────────────
$syntaxFiles = array_merge($required, [
    'app/constant/communication/notification_center.php', // must remain valid (untouched)
    'app/constant/settings/user_roles.php',               // must remain valid (untouched)
]);
foreach ($syntaxFiles as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("Cannot lint — file missing: $f"); continue; }
    $out = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    if (str_contains((string)$out, 'Parse error') || str_contains((string)$out, 'Fatal error')) {
        fail("Syntax error in $f:\n     $out");
    } else {
        pass("Syntax OK: $f");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Migration integrity');
// ─────────────────────────────────────────────────────────────────────────────
$mig = readSrc($root, 'migrations/2026_05_21_document_expiry_tracking.php');
want($mig, 'issue_date',  'Migration adds documents.issue_date',  'Migration missing issue_date column');
want($mig, 'expire_date', 'Migration adds documents.expire_date', 'Migration missing expire_date column');
want($mig, 'document_id', 'Migration adds notifications.document_id', 'Migration missing notifications.document_id');
want($mig, 'CREATE TABLE IF NOT EXISTS document_expiry_reminders',
     'Migration creates document_expiry_reminders (idempotent)',
     'Migration missing CREATE TABLE IF NOT EXISTS document_expiry_reminders');
want($mig, "'document_expiry_alerts'", 'Migration inserts the document_expiry_alerts permission',
     'Migration missing document_expiry_alerts permission insert');
want($mig, 'SHOW COLUMNS', 'Migration guards ALTERs with SHOW COLUMNS (idempotent)',
     'Migration missing SHOW COLUMNS idempotency guards');
want($mig, 'exit(1)', 'Migration calls exit(1) on failure (deploy halts on error)',
     'Migration missing exit(1) on failure');
if (str_contains($mig, 'beginTransaction')) {
    fail('Migration wraps DDL in a transaction — this throws in MySQL');
} else {
    pass('Migration has no transaction wrapping DDL (correct)');
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. Upload form captures the dates');
// ─────────────────────────────────────────────────────────────────────────────
$lib = readSrc($root, 'app/constant/document/document_library.php');
want($lib, 'name="issue_date"',  'Upload modal has the Issue Date input',  'Upload modal missing name="issue_date"');
want($lib, 'name="expire_date"', 'Upload modal has the Expire Date input', 'Upload modal missing name="expire_date"');
want($lib, 'expireDate <= issueDate', 'Upload form validates expire > issue (client-side)',
     'Upload form missing client-side expire/issue date validation');

$up = readSrc($root, 'api/document/upload_document.php');
if (str_contains($up, 'issue_date') && str_contains($up, 'expire_date')) {
    pass('upload_document.php persists issue_date + expire_date');
} else {
    fail('upload_document.php does not persist issue_date / expire_date');
}
want($up, 'Expire Date must be later', 'upload_document.php validates expire > issue (server-side)',
     'upload_document.php missing server-side expire/issue validation');

// ─────────────────────────────────────────────────────────────────────────────
section('5. Notification engine correctness');
// ─────────────────────────────────────────────────────────────────────────────
$eng = readSrc($root, 'cron/check_document_expiry.php');
want($eng, 'function run_document_expiry_check', 'Engine defines run_document_expiry_check()',
     'Engine missing run_document_expiry_check() function');
want($eng, '30, 14, 7, 1', 'Engine uses the 30/14/7/1-day milestones',
     'Engine missing the [30,14,7,1] milestones');
want($eng, 'INSERT IGNORE INTO document_expiry_reminders',
     'Engine dedups milestones via INSERT IGNORE on document_expiry_reminders',
     'Engine missing INSERT IGNORE dedup — alerts could fire repeatedly');
want($eng, 'document_expiry_alerts', 'Engine resolves recipients via the RBAC permission',
     'Engine missing RBAC recipient resolution (document_expiry_alerts)');
want($eng, "'alert'", "Engine inserts notifications with type 'alert'",
     'Engine does not use the alert notification type');
want($eng, 'catch (Throwable', 'Engine is wrapped in catch (Throwable) — cannot break a page load',
     'Engine missing catch (Throwable) guard');
want($eng, 'doc_expiry_last_run', 'Engine self-throttles via doc_expiry_last_run',
     'Engine missing the doc_expiry_last_run throttle');

// ─────────────────────────────────────────────────────────────────────────────
section('6. Library display — column, badge, filter, stat');
// ─────────────────────────────────────────────────────────────────────────────
want($lib, 'function getExpiryBadge', 'Library has the getExpiryBadge() helper',
     'Library missing getExpiryBadge() helper');
want($lib, '<th>Expiry</th>', 'Library table has the Expiry column',
     'Library table missing the Expiry column header');
want($lib, 'expiryFilter', 'Library has the Expiry Status filter',
     'Library missing the expiryFilter dropdown');
want($lib, 'stat-expiring-soon', 'Library has the Expiring Soon stat card',
     'Library missing the stat-expiring-soon card');

$gd = readSrc($root, 'api/document/get_documents.php');
want($gd, 'expiry_status', 'get_documents.php accepts the expiry_status filter',
     'get_documents.php missing the expiry_status filter');
want($gd, 'expiring_soon', 'get_documents.php returns the expiring_soon stat',
     'get_documents.php missing the expiring_soon stat');

// ─────────────────────────────────────────────────────────────────────────────
section('7. Dashboard surfacing (additive — existing groups intact)');
// ─────────────────────────────────────────────────────────────────────────────
$dash = readSrc($root, 'app/dashboard.php');
want($dash, "'documents' =>", 'Dashboard has the new Document Expiry notification group',
     'Dashboard missing the documents notification group');
want($dash, 'doc_expiring', 'Dashboard handles the doc_expiring alert type',
     'Dashboard missing doc_expiring handling');
want($dash, 'document_id IS NOT NULL', 'Dashboard reads document-expiry notifications from the notifications table',
     'Dashboard missing the document-expiry notifications query');
// Additive check — original groups must still be present
foreach (["'invoices' =>", "'products' =>", "'approvals' =>", "'others' =>"] as $grp) {
    want($dash, $grp, "Dashboard still has the existing group: $grp",
         "Dashboard LOST an existing group ($grp) — change was not additive");
}

// ─────────────────────────────────────────────────────────────────────────────
section('8. header.php daily trigger');
// ─────────────────────────────────────────────────────────────────────────────
$hdr = readSrc($root, 'header.php');
// Must verify the real include statement, not just a mention in a comment.
$hdrHasThrottle = str_contains($hdr, "get_setting('doc_expiry_last_run')")
               || str_contains($hdr, 'get_setting("doc_expiry_last_run")');
$hdrHasInclude  = (bool) preg_match('~include(_once)?[^\n]*cron/check_document_expiry\.php~', $hdr);
if ($hdrHasThrottle && $hdrHasInclude) {
    pass('header.php runs the expiry engine once per day (throttled include)');
} else {
    fail('header.php missing the once-per-day expiry engine trigger — '
       . (!$hdrHasThrottle ? 'no doc_expiry_last_run throttle; ' : '')
       . (!$hdrHasInclude  ? 'no include of cron/check_document_expiry.php; ' : ''));
}

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m════════════════════════════════════════\033[0m\n";
if ($failures === 0) {
    echo "\033[32m✅ All $passes tests passed — safe to push.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $failures test(s) FAILED  |  $passes passed\033[0m\n";
    echo "\033[31mFix the errors above before pushing.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(1);
}
