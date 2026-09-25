<?php
/**
 * api/cron/trial_enforcement.php
 * -----------------------------------------------------------------------
 * Daily batch: suspend every tenant whose trial has expired but still has
 * status = 'trial'. Complements the at-request gate in tenant_bootstrap.php
 * (which fires when the tenant user actually logs in). This ensures tenants
 * that have been inactive since expiry are still marked suspended and will
 * not slip through if the bootstrap gate is later bypassed.
 *
 * Authentication: Bearer token matching CRON_SECRET env variable.
 * Schedule example (server cron):
 *   0 1 * * * curl -s -H "Authorization: Bearer $CRON_SECRET" \
 *              https://superadmin.bms.example.com/api/cron/trial_enforcement.php
 *
 * Also callable by the GitHub Actions deploy workflow for on-demand runs.
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/control_db.php';
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_notifications.php';
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

try {
    $ctrl = getControlPdo();

    // Fetch expired trials
    $stmt = $ctrl->prepare(
        "SELECT id, subdomain, owner_email, company_name, trial_ends_at
           FROM tenants
          WHERE status = 'trial' AND trial_ends_at < NOW()"
    );
    $stmt->execute();
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $suspended = 0;
    $errors    = 0;

    foreach ($expired as $t) {
        try {
            $ctrl->prepare(
                "UPDATE tenants SET status='suspended', suspended_at=NOW(), suspension_reason='trial_expired'
                  WHERE id=? AND status='trial'"
            )->execute([$t['id']]);
            logTenantAdminAction(
                (int)$t['id'],
                (string)$t['subdomain'],
                'auto_suspend_trial',
                'Trial expired ' . ($t['trial_ends_at'] ?? '?') . ' — batch enforcement'
            );
            insertSaNotification(
                (int)$t['id'], 'trial_expired',
                (string)($t['company_name'] ?? $t['subdomain']),
                (string)$t['subdomain']
            );
            $suspended++;
        } catch (Throwable $e) {
            error_log('trial_enforcement: tenant ' . $t['id'] . ' error: ' . $e->getMessage());
            $errors++;
        }
    }

    $trialSuspended = $suspended;

    // Also suspend active tenants whose subscription has expired
    $stmt2 = $ctrl->prepare(
        "SELECT id, subdomain, owner_email, company_name, subscription_ends_at
           FROM tenants
          WHERE status = 'active'
            AND subscription_ends_at IS NOT NULL
            AND subscription_ends_at < CURDATE()"
    );
    $stmt2->execute();
    $expiredSubs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $subSuspended = 0;
    foreach ($expiredSubs as $t) {
        try {
            $ctrl->prepare(
                "UPDATE tenants SET status='suspended', suspended_at=NOW(), suspension_reason='subscription_expired'
                  WHERE id=? AND status='active'"
            )->execute([$t['id']]);
            logTenantAdminAction(
                (int)$t['id'],
                (string)$t['subdomain'],
                'auto_suspend_subscription',
                'Subscription expired ' . ($t['subscription_ends_at'] ?? '?') . ' — batch enforcement'
            );
            insertSaNotification(
                (int)$t['id'], 'subscription_expired',
                (string)($t['company_name'] ?? $t['subdomain']),
                (string)$t['subdomain']
            );
            $subSuspended++;
        } catch (Throwable $e) {
            error_log('trial_enforcement (subscription): tenant ' . $t['id'] . ' error: ' . $e->getMessage());
            $errors++;
        }
    }

    // Email digest to all superadmins when anything expired today
    $totalSuspended = $trialSuspended + $subSuspended;
    if ($totalSuspended > 0) {
        try {
            $saEmails = $ctrl->query(
                "SELECT name, email FROM superadmins WHERE email IS NOT NULL AND email != '' AND email LIKE '%@%'"
            )->fetchAll(\PDO::FETCH_ASSOC);

            if ($saEmails) {
                $todayLabel = date('d M Y');
                $subject    = "BMS — {$totalSuspended} tenant" . ($totalSuspended > 1 ? 's' : '') . " expired today ({$todayLabel})";

                $trialRows = '';
                foreach ($expired as $t) {
                    $trialRows .= '<tr><td style="padding:6px 10px">' . htmlspecialchars((string)$t['company_name'], ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 10px;color:#6c757d">' . htmlspecialchars((string)$t['subdomain'], ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 10px;color:#dc3545">Trial expired ' . htmlspecialchars((string)$t['trial_ends_at'], ENT_QUOTES) . '</td></tr>';
                }
                $subRows = '';
                foreach ($expiredSubs as $t) {
                    $subRows .= '<tr><td style="padding:6px 10px">' . htmlspecialchars((string)$t['company_name'], ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 10px;color:#6c757d">' . htmlspecialchars((string)$t['subdomain'], ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 10px;color:#fd7e14">Subscription expired ' . htmlspecialchars((string)$t['subscription_ends_at'], ENT_QUOTES) . '</td></tr>';
                }

                $tableStyle = 'width:100%;border-collapse:collapse;font-size:14px';
                $thStyle    = 'padding:8px 10px;background:#f8f9fa;text-align:left;font-weight:600;border-bottom:2px solid #dee2e6';

                $body = '
                <p>This is your daily BMS enforcement summary for <strong>' . $todayLabel . '</strong>.</p>
                <table style="' . $tableStyle . '">
                    <thead><tr>
                        <th style="' . $thStyle . '">Company</th>
                        <th style="' . $thStyle . '">Subdomain</th>
                        <th style="' . $thStyle . '">Reason</th>
                    </tr></thead>
                    <tbody>' . $trialRows . $subRows . '</tbody>
                </table>
                <p style="margin-top:16px">
                    These tenants are now suspended. Log in to extend a trial, record a payment, or take action:<br>
                    <a href="https://superadmin.bms.bjptechnologies.co.tz/tenants">
                        superadmin.bms.bjptechnologies.co.tz/tenants
                    </a>
                </p>';

                foreach ($saEmails as $sa) {
                    sendEmail(
                        $sa['email'],
                        $subject,
                        '<p>Hi ' . htmlspecialchars((string)$sa['name'], ENT_QUOTES) . ',</p>' . $body,
                        ['wrap_brand' => 'BJP Technologies / BMS']
                    );
                }
            }
        } catch (Throwable $e) {
            error_log('trial_enforcement email digest: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'ok'                 => true,
        'trials_found'       => count($expired),
        'trials_suspended'   => $trialSuspended,
        'subs_found'         => count($expiredSubs),
        'subs_suspended'     => $subSuspended,
        'errors'             => $errors,
        'digest_sent'        => $totalSuspended > 0,
        'ran_at'             => date('c'),
    ]);

} catch (Throwable $e) {
    error_log('trial_enforcement.php fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
