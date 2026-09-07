<?php
/**
 * api/run_background_jobs.php
 * ----------------------------------------------------------------------------
 * Out-of-band home for the "opportunistic" housekeeping jobs that used to run
 * INLINE inside header.php on a random authenticated page load: draining the
 * notification email outbox (up to 50 real SMTP sends, each up to a 20s connect
 * timeout) and five once-a-day checks (document expiry, leave accrual, HR
 * expiry, smart notifications, AI digest).
 *
 * Running that inline meant whichever user's ordinary page load happened to
 * cross the throttle window absorbed the full synchronous cost — a page that
 * should take 200ms could take minutes. That is the "login/pages sometimes
 * just freeze" symptom: nothing to do with the page itself, purely a timing
 * coincidence of which request crossed the window.
 *
 * header.php now fires this endpoint asynchronously (navigator.sendBeacon) on
 * every authenticated page load where something might be due, and moves on
 * immediately — it never waits for a response. This file re-verifies every
 * condition itself (the same checks that used to live in header.php), so it is
 * cheap and harmless to call even when nothing is actually due, and safe to
 * call as often as the browser likes.
 *
 * Not a substitute for a real OS-level scheduled task under heavy load (see
 * the note in cron/process_notifications.php), but it is what keeps this work
 * from ever blocking a real user's page again on BMS's current hosting.
 */

require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

// Same implicit trust boundary as before: this only ever ran for a signed-in
// user's page load, never for anonymous traffic.
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

// sendBeacon may fire as the tab is closing/navigating away — keep running
// server-side to completion regardless, so an email send never gets cut
// halfway through, and lift the default execution time cap for the same reason.
ignore_user_abort(true);
set_time_limit(0);

$ran = [];

if (function_exists('get_setting') && get_setting('doc_expiry_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/../cron/check_document_expiry.php';
    $ran[] = 'doc_expiry';
}

if (function_exists('get_setting') && get_setting('leave_accrual_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/../cron/run_leave_accrual.php';
    $ran[] = 'leave_accrual';
}

if (function_exists('get_setting') && get_setting('hr_expiry_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/../cron/check_hr_expiry.php';
    $ran[] = 'hr_expiry';
}

if (function_exists('get_setting') && get_setting('notif_checks_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/../cron/run_notification_checks.php';
    $ran[] = 'notif_checks';
}

if (function_exists('get_setting') && (time() - (int) get_setting('notif_outbox_last_ts', '0')) >= 120) {
    if (function_exists('save_setting')) save_setting('notif_outbox_last_ts', (string) time());
    @include_once __DIR__ . '/../cron/process_notifications.php';
    $ran[] = 'notif_outbox';
}

if (function_exists('get_setting') && get_setting('notif_digest_enabled', '0') === '1'
    && get_setting('notif_digest_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/../cron/send_notification_digests.php';
    $ran[] = 'notif_digest';
}

echo json_encode(['ok' => true, 'ran' => $ran]);
