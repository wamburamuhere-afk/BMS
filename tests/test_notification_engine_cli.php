<?php
/**
 * Smart Notification Engine — CLI test
 * ------------------------------------
 *   php tests/test_notification_engine_cli.php
 *
 * Verifies files+lint, schema (tables/columns), seeded event catalog, core
 * helpers, the resolve→dispatch→in-app→outbox→log pipeline, idempotency,
 * project-scope + per-user mute filtering, admin-rule narrowing (incl. the
 * "a rule cannot reach someone without access" safety), the mailer fail-silent
 * path, the AI-digest fallback, and that every wired source file actually emits
 * its event. Self-cleaning + idempotent (safe in the pre-push hook). Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/notify.php";
require_once "$root/core/mailer.php";   // worker loads this on demand in prod; load explicitly for the test
global $pdo;

$failures = 0; $passes = 0;

// Sentinels declared early so the shutdown cleanup can always reach them,
// even if an assertion fatals mid-run (the pre-push hook re-runs this file).
$SENT = 'NENG_TEST';
$SENT_ENT = 970000;
register_shutdown_function(function () use (&$pdo) {
    try {
        $pdo->exec("DELETE FROM notifications      WHERE title='NENG_TEST overdue' OR event_key='this.event.unknown'");
        $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE '%NENG_TEST%' OR dedupe_key='NENG_TEST_DK'");
        $pdo->exec("DELETE FROM notification_log    WHERE entity_id=970000");
        $pdo->exec("DELETE FROM notification_outbox WHERE entity_id=970000 OR dedupe_key='NENG_TEST_DK'");
    } catch (Throwable $e) { /* best effort */ }
});
function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 50) . "`"); }

register_shutdown_function(function () {
    global $passes, $failures; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$files = [
    'core/mailer.php', 'core/notify.php',
    'cron/process_notifications.php', 'cron/run_notification_checks.php', 'cron/send_notification_digests.php',
    'api/notifications/rules_api.php', 'app/constant/settings/notification_rules.php',
    'migrations/2026_06_28_notification_engine_foundation.php',
    'migrations/2026_06_28_notification_outbox.php',
    'migrations/2026_06_28_notification_rules.php',
    'includes/PHPMailer/PHPMailer.php', 'includes/PHPMailer/SMTP.php', 'includes/PHPMailer/Exception.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' | ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Schema — tables + columns');
foreach (['notification_events','notification_dedupe','notification_log','notification_outbox','notification_rules'] as $t) {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); pass("table $t exists"); }
    catch (Throwable $e) { fail("table $t missing"); }
}
foreach (['event_key','category'] as $c) {
    $ok = $pdo->query("SHOW COLUMNS FROM notifications LIKE " . $pdo->quote($c))->fetch();
    $ok ? pass("notifications.$c exists") : fail("notifications.$c missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Event catalog seeded');
$wiredEvents = [
    'invoice.overdue','invoice.needs_review','po.needs_approval','grn.pending',
    'quotation.expiring','purchase_return.pending','sales_return.pending',
    'debit_note.pending','credit_note.pending','voucher.needs_approval',
    'expense.needs_review','tender.deadline',
];
$catalog = $pdo->query("SELECT event_key FROM notification_events")->fetchAll(PDO::FETCH_COLUMN);
foreach ($wiredEvents as $ek) {
    in_array($ek, $catalog, true) ? pass("catalog has $ek") : fail("catalog missing $ek");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Core helpers callable');
foreach (['usersWithPermission','createNotification','notifClaimDedupe','notifLog',
          'resolveRecipients','previewRecipients','dispatchEvent','enqueueEmail',
          'processNotificationOutbox','notifUserMuted','aiSummarizeNotifications',
          'sendNotificationDigests','sendEmail','mailer_last_error'] as $fn) {
    function_exists($fn) ? pass("$fn() defined") : fail("$fn() missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('5. RBAC resolution');
$recips = usersWithPermission($pdo, 'invoices', 'view');
count($recips) >= 1 ? pass('usersWithPermission(invoices,view) >= 1') : fail('no recipients resolved');
$first = $recips ? reset($recips) : [];
(is_array($first) && array_key_exists('is_admin', $first)) ? pass('recipient carries is_admin flag') : fail('is_admin flag absent');

// ─────────────────────────────────────────────────────────────────────────
section('6. Dispatch pipeline + idempotency');
$ctx = ['entity_type'=>'test','entity_id'=>$SENT_ENT,'dedupe_suffix'=>$SENT,
        'title'=>"$SENT overdue",'message'=>'m','action_url'=>'invoice_view?id=1'];
$r1 = dispatchEvent($pdo, 'invoice.overdue', $ctx);
(!empty($r1['dispatched']) && $r1['created'] >= 1) ? pass("dispatch created {$r1['created']} in-app") : fail('dispatch created nothing');
$logCnt = (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE entity_id=$SENT_ENT AND status='created'")->fetchColumn();
$logCnt === (int)$r1['created'] ? pass('audit log rows written') : fail("log mismatch ($logCnt vs {$r1['created']})");
$r2 = dispatchEvent($pdo, 'invoice.overdue', $ctx);
($r2['created'] === 0 && $r2['skipped'] === $r2['recipients']) ? pass('2nd dispatch idempotent (deduped)') : fail('not idempotent: '.json_encode($r2));
$ru = dispatchEvent($pdo, 'this.event.unknown', $ctx);
($ru['dispatched'] === false && $ru['reason'] === 'unknown_event') ? pass('unknown event safe-skips') : fail('unknown event not handled');

// ─────────────────────────────────────────────────────────────────────────
section('7. enqueueEmail dedupe');
$d1 = enqueueEmail($pdo, ['to_email'=>'x@x.com','subject'=>'s','body'=>'b','dedupe_key'=>$SENT.'_DK','entity_id'=>$SENT_ENT]);
$d2 = enqueueEmail($pdo, ['to_email'=>'x@x.com','subject'=>'s','body'=>'b','dedupe_key'=>$SENT.'_DK','entity_id'=>$SENT_ENT]);
($d1 === true && $d2 === false) ? pass('enqueueEmail dedupes (1st true / 2nd false)') : fail('enqueue dedupe broken');

// ─────────────────────────────────────────────────────────────────────────
section('8. Per-user mute logic');
notifUserMuted('{"notifications_enabled":false}','x','y') === true ? pass('mute: global off') : fail('mute global');
notifUserMuted('{"muted_events":["invoice.overdue"]}','invoice.overdue','F') === true ? pass('mute: by event') : fail('mute event');
notifUserMuted('{"muted_categories":["Finance"]}','invoice.overdue','Finance') === true ? pass('mute: by category') : fail('mute category');
notifUserMuted('{"foo":"bar"}','invoice.overdue','Finance') === false ? pass('mute: unrelated prefs ignored') : fail('mute false-positive');
notifUserMuted(null,'a','b') === false ? pass('mute: null prefs safe') : fail('mute null');

// ─────────────────────────────────────────────────────────────────────────
section('9. Admin-rule narrowing + no-access safety');
$ev = $pdo->query("SELECT * FROM notification_events WHERE event_key='invoice.overdue'")->fetch(PDO::FETCH_ASSOC);
$all = usersWithPermission($pdo, 'invoices', 'view'); $n = count($all); $uid = array_key_first($all);
$rPerm = resolveRecipients($pdo, $ev, [], [['target_type'=>'permission','target_id'=>null,'channel_inapp'=>1,'channel_email'=>0]]);
count($rPerm) === $n ? pass('permission rule -> all with access') : fail('permission rule wrong count');
$rUser = resolveRecipients($pdo, $ev, [], [['target_type'=>'user','target_id'=>$uid,'channel_inapp'=>1,'channel_email'=>1]]);
(count($rUser) === 1 && isset($rUser[$uid]) && !empty($rUser[$uid]['channels']['email'])) ? pass('user rule -> exactly that user, channels applied') : fail('user rule wrong');
$rNo = previewRecipients($pdo, 'invoice.overdue', [['target_type'=>'user','target_id'=>9999999,'channel_inapp'=>1]]);
count($rNo) === 0 ? pass('SAFETY: rule to no-access user -> nobody') : fail('SECURITY: rule reached a no-access user');

// ─────────────────────────────────────────────────────────────────────────
section('10. Mailer fail-silent + digest fallback');
$saveHost = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='smtp_username'")->fetchColumn();
// Only assert fail-silent when SMTP truly unconfigured; otherwise just assert it returns a bool.
$res = sendEmail('nobody@example.com', 'x', '<p>x</p>');
is_bool($res) ? pass('sendEmail returns a boolean (never throws)') : fail('sendEmail did not return bool');
if ($res === false) { (mailer_last_error() !== '') ? pass('mailer_last_error populated on failure') : fail('no error captured'); }
else { pass('SMTP configured — sendEmail succeeded'); }
$digest = aiSummarizeNotifications([['title'=>'A','message'=>'1','priority'=>'high'],['title'=>'B','message'=>'2','priority'=>'low']]);
(strpos($digest,'A') !== false && strpos($digest,'B') !== false) ? pass('digest summary includes items (AI or fallback)') : fail('digest summary empty');

// ─────────────────────────────────────────────────────────────────────────
section('11. Every wired source file actually emits its event');
$emitMap = [
    'api/account/save_purchase_order.php'    => "'po.needs_approval'",
    'api/account/save_invoice.php'           => "'invoice.needs_review'",
    'api/purchase/create_debit_note.php'     => "'debit_note.pending'",
    'api/sales/create_credit_note.php'       => "'credit_note.pending'",
    'api/sales/create_return.php'            => "'sales_return.pending'",
    'api/create_purchase_return.php'         => "'purchase_return.pending'",
    'api/account/add_expense.php'            => "'expense.needs_review'",
    'api/create_grn.php'                     => "'grn.pending'",
    'api/account/save_voucher.php'           => "'voucher.needs_approval'",
    'cron/run_notification_checks.php'       => "'quotation.expiring'",
];
foreach ($emitMap as $file => $needle) {
    $code = file_exists("$root/$file") ? file_get_contents("$root/$file") : '';
    (strpos($code, 'dispatchEvent') !== false && strpos($code, $needle) !== false)
        ? pass("$file emits $needle")
        : fail("$file does NOT emit $needle");
}
$checks = file_get_contents("$root/cron/run_notification_checks.php");
has($checks, "'tender.deadline'", 'scheduler emits tender.deadline');
has($checks, "'invoice.overdue'", 'scheduler emits invoice.overdue');

// ─────────────────────────────────────────────────────────────────────────
section('Cleanup (remove all test artifacts)');
try {
    $pdo->exec("DELETE FROM notifications      WHERE title='$SENT overdue' OR event_key='this.event.unknown'");
    $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE '%$SENT%' OR dedupe_key='{$SENT}_DK'");
    $pdo->exec("DELETE FROM notification_log    WHERE entity_id=$SENT_ENT");
    $pdo->exec("DELETE FROM notification_outbox WHERE entity_id=$SENT_ENT OR dedupe_key='{$SENT}_DK'");
    pass('test artifacts removed');
} catch (Throwable $e) {
    fail('cleanup error: ' . $e->getMessage());
}
