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

// Optional, multi-tenancy-only layer — single-tenant installs have no control
// database at all, so these are guarded by is_file() rather than assumed.
if (is_file(__DIR__ . '/../core/module_requests.php')) {
    require_once __DIR__ . '/../core/module_requests.php';
}
if (is_file(__DIR__ . '/../core/platform_settings.php')) {
    require_once __DIR__ . '/../core/platform_settings.php';
}

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

// Stale module-request reminder (tenant_module_control_plan.md, Phase C) —
// PLATFORM-wide, not this tenant's own concern, but this endpoint is exactly
// the "piggyback on any ordinary page load" mechanism the rest of this file
// already uses, and there is no per-tenant reason to gate it. Throttled via
// getPlatformSetting() (control DB), not get_setting() (this tenant's own
// system_settings) — a per-tenant throttle would fire this once per tenant
// per day, e-mailing superadmins duplicate reminders for the exact same
// stale requests.
// Help & Support (support_center_plan.md, Phase 6) — PLATFORM-wide like the module-request
// reminder below: overdue first-reply reminders to BJP and auto-closing resolved requests.
// Throttled to once per 10 minutes through the control DB, so many active companies do not
// each run it.
if (function_exists('getPlatformSetting') && is_file(__DIR__ . '/../core/support_notify.php')
    && (time() - (int)getPlatformSetting('support_jobs_last_ts', '0')) >= 600) {
    try {
        setPlatformSetting('support_jobs_last_ts', (string)time());
        require_once __DIR__ . '/../core/support_notify.php';
        supportRunSlaReminders();
        supportRunAutoClose();
        $ran[] = 'support_jobs';
    } catch (Throwable $e) {
        error_log('run_background_jobs support_jobs: ' . $e->getMessage());
    }
}

if (function_exists('getPlatformSetting') && function_exists('remindStalePendingRequests')
    && getPlatformSetting('module_request_reminder_last_run') !== date('Y-m-d')) {
    try {
        setPlatformSetting('module_request_reminder_last_run', date('Y-m-d'));
        remindStalePendingRequests();
        $ran[] = 'module_request_reminders';
    } catch (Throwable $e) {
        error_log('run_background_jobs/remindStalePendingRequests: ' . $e->getMessage());
    }
}

// BJP's own install only: newly registered companies → BJP customers
// (service_plans_plan.md Phase 4). One indexed SELECT when nothing is queued.
if (is_file(__DIR__ . '/../core/vendor_link.php')) {
    require_once __DIR__ . '/../core/vendor_context.php';
    if (isPlatformVendorContext()) {
        try {
            require_once __DIR__ . '/../core/vendor_link.php';
            if (vlDrainLinkQueue($pdo, 10)['processed'] > 0) $ran[] = 'vendor_customer_links';
        } catch (Throwable $e) {
            error_log('run_background_jobs/vlDrainLinkQueue: ' . $e->getMessage());
        }
    }
}

echo json_encode(['ok' => true, 'ran' => $ran]);
