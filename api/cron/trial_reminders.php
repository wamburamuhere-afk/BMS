<?php
/**
 * api/cron/trial_reminders.php
 * -----------------------------------------------------------------------
 * Daily batch: send trial-expiry reminder emails at the 7-day, 3-day, and
 * 0-day (expiry day) milestones.
 *
 * Deduplication: each reminder is logged to tenant_admin_log with a
 * distinguishing action name. The job skips tenants where that action was
 * already logged today so it is safe to run multiple times a day.
 *
 * Authentication: Bearer token matching CRON_SECRET env variable.
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/control_db.php';
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/mailer.php';

header('Content-Type: application/json');

// --- Bearer-token gate -------------------------------------------------
$expectedSecret = trim((string)getenv('CRON_SECRET'));
if ($expectedSecret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'CRON_SECRET not configured']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '' && function_exists('getallheaders')) {
    $hdrs       = getallheaders();
    $authHeader = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
}
if (!hash_equals('Bearer ' . $expectedSecret, trim($authHeader))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// --- Core work --------------------------------------------------------
ignore_user_abort(true);
set_time_limit(120);

/**
 * Check whether a reminder of the given action was already sent today for
 * a tenant (prevents double-send on repeated runs).
 */
function reminderAlreadySentToday(\PDO $ctrl, int $tenantId, string $action): bool
{
    $row = $ctrl->prepare(
        "SELECT 1 FROM tenant_admin_log
          WHERE tenant_id = ? AND action = ? AND DATE(created_at) = CURDATE()
          LIMIT 1"
    );
    $row->execute([$tenantId, $action]);
    return (bool)$row->fetchColumn();
}

/**
 * Send one reminder email and log it.
 */
function sendTrialReminder(\PDO $ctrl, array $t, string $action, int $daysLeft): void
{
    $name    = trim(($t['owner_first_name'] ?? '') . ' ' . ($t['owner_last_name'] ?? ''));
    $greet   = $name !== '' ? $name : $t['owner_email'];
    $company = $t['company_name'] ?? 'your account';

    if ($daysLeft > 0) {
        $subject = "Your BMS trial ends in {$daysLeft} day" . ($daysLeft > 1 ? 's' : '');
        $urgency = $daysLeft <= 3 ? "⚠️ " : "";
        $body    = "
            <p>Hi {$greet},</p>
            <p>{$urgency}Your free trial for <strong>{$company}</strong> ends in
            <strong>{$daysLeft} day" . ($daysLeft > 1 ? 's' : '') . "</strong>.</p>
            <p>To keep access to all your data and features, please contact us to
            upgrade your account before the trial period ends.</p>
            <p>If you have any questions, reply to this email or reach us at
            <a href='mailto:support@bjptechnologies.co.tz'>support@bjptechnologies.co.tz</a>.</p>
            <p>Thank you for trying BMS!</p>";
    } else {
        $subject = "Your BMS trial has ended — your account is now paused";
        $body    = "
            <p>Hi {$greet},</p>
            <p>Your free trial for <strong>{$company}</strong> has ended today and your
            account has been paused.</p>
            <p>Your data is safe. To restore access, please contact us to activate your
            subscription.</p>
            <p>Reply to this email or reach us at
            <a href='mailto:support@bjptechnologies.co.tz'>support@bjptechnologies.co.tz</a>.</p>";
    }

    $sent = false;
    if (!empty($t['owner_email'])) {
        $sent = sendEmail(
            $t['owner_email'],
            $subject,
            $body,
            ['wrap_brand' => 'BJP Technologies / BMS']
        );
    }

    logTenantAdminAction(
        (int)$t['id'],
        (string)$t['subdomain'],
        $action,
        "Trial reminder sent ({$daysLeft}d) — email " . ($sent ? 'delivered' : 'failed')
    );
}

// --- Run reminders ----------------------------------------------------
try {
    $ctrl = getControlPdo();

    // Milestones: [ days_ahead => action_key ]
    $milestones = [
        7 => 'trial_reminder_7d',
        3 => 'trial_reminder_3d',
        0 => 'trial_reminder_0d',
    ];

    $sent   = 0;
    $skip   = 0;
    $errors = 0;

    foreach ($milestones as $daysAhead => $action) {
        if ($daysAhead > 0) {
            // Tenants expiring exactly N days from now (within 24h window)
            $stmt = $ctrl->prepare(
                "SELECT id, subdomain, owner_email, owner_first_name, owner_last_name,
                        company_name, trial_ends_at, unsubscribed_at
                   FROM tenants
                  WHERE status = 'trial'
                    AND trial_ends_at >= NOW()
                    AND trial_ends_at <  DATE_ADD(NOW(), INTERVAL :ahead DAY)
                    AND trial_ends_at >= DATE_ADD(NOW(), INTERVAL :ahead_minus_1 DAY)"
            );
            $stmt->execute([':ahead' => $daysAhead, ':ahead_minus_1' => $daysAhead - 1]);
        } else {
            // Expiry-day: already in the past (just expired today)
            $stmt = $ctrl->prepare(
                "SELECT id, subdomain, owner_email, owner_first_name, owner_last_name,
                        company_name, trial_ends_at, unsubscribed_at
                   FROM tenants
                  WHERE status IN ('trial','suspended')
                    AND DATE(trial_ends_at) = CURDATE()"
            );
            $stmt->execute();
        }

        $tenants = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($tenants as $t) {
            // Skip unsubscribed
            if (!empty($t['unsubscribed_at'])) {
                $skip++;
                continue;
            }
            // Skip if already sent today
            if (reminderAlreadySentToday($ctrl, (int)$t['id'], $action)) {
                $skip++;
                continue;
            }
            try {
                sendTrialReminder($ctrl, $t, $action, $daysAhead);
                $sent++;
            } catch (Throwable $e) {
                error_log('trial_reminders: tenant ' . $t['id'] . ' error: ' . $e->getMessage());
                $errors++;
            }
        }
    }

    echo json_encode([
        'ok'     => true,
        'sent'   => $sent,
        'skip'   => $skip,
        'errors' => $errors,
        'ran_at' => date('c'),
    ]);

} catch (Throwable $e) {
    error_log('trial_reminders.php fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
