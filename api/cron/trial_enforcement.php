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
                "UPDATE tenants SET status='suspended', suspended_at=NOW()
                  WHERE id=? AND status='trial'"
            )->execute([$t['id']]);

            // Audit log (actor = NULL = system)
            logTenantAdminAction(
                (int)$t['id'],
                (string)$t['subdomain'],
                'auto_suspend_trial',
                'Trial expired ' . ($t['trial_ends_at'] ?? '?') . ' — batch enforcement'
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
                "UPDATE tenants SET status='suspended', suspended_at=NOW()
                  WHERE id=? AND status='active'"
            )->execute([$t['id']]);
            logTenantAdminAction(
                (int)$t['id'],
                (string)$t['subdomain'],
                'auto_suspend_subscription',
                'Subscription expired ' . ($t['subscription_ends_at'] ?? '?') . ' — batch enforcement'
            );
            $subSuspended++;
        } catch (Throwable $e) {
            error_log('trial_enforcement (subscription): tenant ' . $t['id'] . ' error: ' . $e->getMessage());
            $errors++;
        }
    }

    echo json_encode([
        'ok'                 => true,
        'trials_found'       => count($expired),
        'trials_suspended'   => $trialSuspended,
        'subs_found'         => count($expiredSubs),
        'subs_suspended'     => $subSuspended,
        'errors'             => $errors,
        'ran_at'             => date('c'),
    ]);

} catch (Throwable $e) {
    error_log('trial_enforcement.php fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
