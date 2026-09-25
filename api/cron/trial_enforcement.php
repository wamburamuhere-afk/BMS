<?php
/**
 * api/cron/trial_enforcement.php
 * -----------------------------------------------------------------------
 * Daily batch: grace-period lifecycle for expired trials and subscriptions.
 *
 * Four phases per run:
 *   Phase A — Trial expired, grace not started yet
 *             → set grace_until = trial_ends_at + 7 days, notify superadmin.
 *   Phase B — Trial in grace, grace window now closed
 *             → suspend (status = 'suspended', suspension_reason = 'trial_expired').
 *   Phase C — Subscription expired, grace not started yet
 *             → set grace_until = subscription_ends_at + 7 days, notify superadmin.
 *   Phase D — Subscription in grace, grace window now closed
 *             → suspend (status = 'suspended', suspension_reason = 'subscription_expired').
 *
 * Email digest: sent to all superadmins when any tenants are newly suspended today.
 *
 * Authentication: Bearer token matching CRON_SECRET env variable.
 * Schedule example (server cron):
 *   0 1 * * * curl -s -H "Authorization: Bearer $CRON_SECRET" \
 *              https://superadmin.bms.example.com/api/cron/trial_enforcement.php
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

define('GRACE_PERIOD_DAYS', 7);

try {
    $ctrl   = getControlPdo();
    $errors = 0;

    // ── Phase A: Trial expired, no grace period set yet ──────────────────
    $stmtA = $ctrl->prepare(
        "SELECT id, subdomain, company_name, trial_ends_at
           FROM tenants
          WHERE status = 'trial'
            AND trial_ends_at < NOW()
            AND grace_until IS NULL"
    );
    $stmtA->execute();
    $newTrialGrace = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    $trialGraceStarted = 0;
    foreach ($newTrialGrace as $t) {
        try {
            $graceDate = date('Y-m-d', strtotime($t['trial_ends_at'] . ' +' . GRACE_PERIOD_DAYS . ' days'));
            $ctrl->prepare(
                "UPDATE tenants SET grace_until = ? WHERE id = ? AND status = 'trial' AND grace_until IS NULL"
            )->execute([$graceDate, $t['id']]);
            logTenantAdminAction(
                (int)$t['id'], (string)$t['subdomain'],
                'grace_period_started',
                'Trial expired ' . $t['trial_ends_at'] . ' — grace period started, ends ' . $graceDate
            );
            insertSaNotification(
                (int)$t['id'], 'trial_grace_started',
                (string)($t['company_name'] ?? $t['subdomain']),
                (string)$t['subdomain']
            );
            $trialGraceStarted++;
        } catch (Throwable $e) {
            error_log('trial_enforcement Phase A tenant ' . $t['id'] . ': ' . $e->getMessage());
            $errors++;
        }
    }

    // ── Phase B: Trial grace window closed — suspend ─────────────────────
    $stmtB = $ctrl->prepare(
        "SELECT id, subdomain, company_name, trial_ends_at
           FROM tenants
          WHERE status = 'trial'
            AND grace_until IS NOT NULL
            AND grace_until < CURDATE()"
    );
    $stmtB->execute();
    $expiredTrials = $stmtB->fetchAll(PDO::FETCH_ASSOC);

    $trialSuspended = 0;
    foreach ($expiredTrials as $t) {
        try {
            $ctrl->prepare(
                "UPDATE tenants SET status='suspended', suspended_at=NOW(), suspension_reason='trial_expired'
                  WHERE id=? AND status='trial'"
            )->execute([$t['id']]);
            logTenantAdminAction(
                (int)$t['id'], (string)$t['subdomain'],
                'auto_suspend_trial',
                'Grace period ended — trial expired ' . ($t['trial_ends_at'] ?? '?') . ' — batch enforcement'
            );
            insertSaNotification(
                (int)$t['id'], 'trial_expired',
                (string)($t['company_name'] ?? $t['subdomain']),
                (string)$t['subdomain']
            );
            $trialSuspended++;
        } catch (Throwable $e) {
            error_log('trial_enforcement Phase B tenant ' . $t['id'] . ': ' . $e->getMessage());
            $errors++;
        }
    }

    // ── Phase C: Subscription expired, no grace period set yet ───────────
    $stmtC = $ctrl->prepare(
        "SELECT id, subdomain, company_name, subscription_ends_at
           FROM tenants
          WHERE status = 'active'
            AND subscription_ends_at IS NOT NULL
            AND subscription_ends_at < CURDATE()
            AND grace_until IS NULL"
    );
    $stmtC->execute();
    $newSubGrace = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    $subGraceStarted = 0;
    foreach ($newSubGrace as $t) {
        try {
            $graceDate = date('Y-m-d', strtotime($t['subscription_ends_at'] . ' +' . GRACE_PERIOD_DAYS . ' days'));
            $ctrl->prepare(
                "UPDATE tenants SET grace_until = ? WHERE id = ? AND status = 'active' AND grace_until IS NULL"
            )->execute([$graceDate, $t['id']]);
            logTenantAdminAction(
                (int)$t['id'], (string)$t['subdomain'],
                'grace_period_started',
                'Subscription expired ' . $t['subscription_ends_at'] . ' — grace period started, ends ' . $graceDate
            );
            insertSaNotification(
                (int)$t['id'], 'subscription_grace_started',
                (string)($t['company_name'] ?? $t['subdomain']),
                (string)$t['subdomain']
            );
            $subGraceStarted++;
        } catch (Throwable $e) {
            error_log('trial_enforcement Phase C tenant ' . $t['id'] . ': ' . $e->getMessage());
            $errors++;
        }
    }

    // ── Phase D: Subscription grace window closed — suspend ───────────────
    $stmtD = $ctrl->prepare(
        "SELECT id, subdomain, company_name, subscription_ends_at
           FROM tenants
          WHERE status = 'active'
            AND grace_until IS NOT NULL
            AND grace_until < CURDATE()"
    );
    $stmtD->execute();
    $expiredSubs = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    $subSuspended = 0;
    foreach ($expiredSubs as $t) {
        try {
            $ctrl->prepare(
                "UPDATE tenants SET status='suspended', suspended_at=NOW(), suspension_reason='subscription_expired'
                  WHERE id=? AND status='active'"
            )->execute([$t['id']]);
            logTenantAdminAction(
                (int)$t['id'], (string)$t['subdomain'],
                'auto_suspend_subscription',
                'Grace period ended — subscription expired ' . ($t['subscription_ends_at'] ?? '?') . ' — batch enforcement'
            );
            insertSaNotification(
                (int)$t['id'], 'subscription_expired',
                (string)($t['company_name'] ?? $t['subdomain']),
                (string)$t['subdomain']
            );
            $subSuspended++;
        } catch (Throwable $e) {
            error_log('trial_enforcement Phase D tenant ' . $t['id'] . ': ' . $e->getMessage());
            $errors++;
        }
    }

    // ── Email digest: only when tenants were actually suspended today ─────
    $totalSuspended = $trialSuspended + $subSuspended;
    if ($totalSuspended > 0) {
        try {
            $saEmails = $ctrl->query(
                "SELECT name, email FROM superadmins WHERE email IS NOT NULL AND email != '' AND email LIKE '%@%'"
            )->fetchAll(\PDO::FETCH_ASSOC);

            if ($saEmails) {
                $todayLabel = date('d M Y');
                $subject    = "BMS — {$totalSuspended} tenant" . ($totalSuspended > 1 ? 's' : '') . " suspended today ({$todayLabel})";

                $trialRows = '';
                foreach ($expiredTrials as $t) {
                    $trialRows .= '<tr><td style="padding:6px 10px">' . htmlspecialchars((string)$t['company_name'], ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 10px;color:#6c757d">' . htmlspecialchars((string)$t['subdomain'], ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 10px;color:#dc3545">Trial expired ' . htmlspecialchars((string)$t['trial_ends_at'], ENT_QUOTES) . ' (grace ended)</td></tr>';
                }
                $subRows = '';
                foreach ($expiredSubs as $t) {
                    $subRows .= '<tr><td style="padding:6px 10px">' . htmlspecialchars((string)$t['company_name'], ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 10px;color:#6c757d">' . htmlspecialchars((string)$t['subdomain'], ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 10px;color:#fd7e14">Subscription expired ' . htmlspecialchars((string)$t['subscription_ends_at'], ENT_QUOTES) . ' (grace ended)</td></tr>';
                }

                $tableStyle = 'width:100%;border-collapse:collapse;font-size:14px';
                $thStyle    = 'padding:8px 10px;background:#f8f9fa;text-align:left;font-weight:600;border-bottom:2px solid #dee2e6';

                $body = '
                <p>This is your daily BMS enforcement summary for <strong>' . $todayLabel . '</strong>.</p>
                <p>The following tenants completed their ' . GRACE_PERIOD_DAYS . '-day grace period and were automatically suspended.</p>
                <table style="' . $tableStyle . '">
                    <thead><tr>
                        <th style="' . $thStyle . '">Company</th>
                        <th style="' . $thStyle . '">Subdomain</th>
                        <th style="' . $thStyle . '">Reason</th>
                    </tr></thead>
                    <tbody>' . $trialRows . $subRows . '</tbody>
                </table>
                <p style="margin-top:16px">
                    Log in to reactivate after receiving payment:<br>
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
        'ok'                      => true,
        'trial_grace_started'     => $trialGraceStarted,
        'trial_suspended'         => $trialSuspended,
        'sub_grace_started'       => $subGraceStarted,
        'sub_suspended'           => $subSuspended,
        'errors'                  => $errors,
        'digest_sent'             => $totalSuspended > 0,
        'ran_at'                  => date('c'),
    ]);

} catch (Throwable $e) {
    error_log('trial_enforcement.php fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
