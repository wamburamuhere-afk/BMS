<?php
/**
 * api/mobile/register_status.php
 *
 * GET ?job_id=<hex64>
 *
 * Poll this every 5 seconds after POST api/mobile/register.php.
 * No auth required — the job_id is a 64-char random token, unfeasible to guess.
 *
 * Responses:
 *   202  { status:"provisioning", username, subdomain, tenant_url, message }
 *   200  { status:"ready", token, username, subdomain, tenant_url, user{}, company{} }
 *   200  { success:false, status:"failed", message }
 *   404  job not found
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/control_db.php';
require_once __DIR__ . '/../../core/tenant_crypto.php';

$jobId = trim($_GET['job_id'] ?? '');
if ($jobId === '' || !preg_match('/^[0-9a-f]{64}$/', $jobId)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid job_id.']);
    exit;
}

$cpdo = getControlPdo();
$stmt = $cpdo->prepare("SELECT * FROM registration_jobs WHERE job_id = ? LIMIT 1");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Registration job not found.']);
    exit;
}

// Login name (owner_username, else the phone), the chosen plan, and whether the
// company waits for a superadmin's Smart BMS approval — same as the web signup.
$loginName = trim((string)($job['owner_username'] ?? '')) !== '' ? trim((string)$job['owner_username']) : (string)$job['owner_phone'];
$planInfo  = null;
if (!empty($job['plan_key'])) {
    try {
        require_once __DIR__ . '/../../core/plans.php';
        $pl = getPlanByKey((string)$job['plan_key']);
        $planInfo = ['plan_key' => (string)$job['plan_key'], 'term_key' => (string)($job['plan_term'] ?? ''),
                     'name' => $pl ? (string)$pl['name'] : null, 'product_key' => $pl ? (string)($pl['product_key'] ?? '') : null];
    } catch (Throwable $e) { /* plan catalogue unavailable — leave null */ }
}
$pendingApproval = ($planInfo['product_key'] ?? '') === 'bms_full';
$job['owner_phone'] = $loginName;   // the "username" every response below returns

switch ($job['status']) {

    case 'pending':
    case 'provisioning':
        http_response_code(202);
        echo json_encode([
            'success'    => true,
            'status'     => 'provisioning',
            'message'    => 'Your BMS account is being set up. Please wait about 1 minute.',
            'username'   => $job['owner_phone'],
            'subdomain'  => $job['subdomain'],
            'tenant_url' => $job['tenant_url'],
            'plan'       => $planInfo,
            'pending_approval' => $pendingApproval,
        ]);
        break;

    case 'failed':
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'status'  => 'failed',
            'message' => 'Account setup failed. Please try registering again or contact support.',
        ]);
        break;

    case 'ready':
        http_response_code(200);
        $payload = regStatusBuildReadyPayload($cpdo, $job) + ['plan' => $planInfo, 'pending_approval' => $pendingApproval];
        if ($pendingApproval) {
            $payload['message'] = 'Your Smart BMS account is built and is waiting for approval. We will email you when it is approved.';
        }
        echo json_encode($payload);
        break;

    default:
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unknown job status.']);
}
exit;

// ── Helper ────────────────────────────────────────────────────────────────────

function regStatusBuildReadyPayload(PDO $cpdo, array $job): array
{
    $base = [
        'success'    => true,
        'status'     => 'ready',
        'token'      => $job['token'],
        'expires_at' => null,
        'username'   => $job['owner_phone'],
        'subdomain'  => $job['subdomain'],
        'tenant_url' => $job['tenant_url'],
        'note'       => trim((string)($job['owner_username'] ?? '')) !== '' ? 'Use your username to log in.' : 'Use your phone number as your username.',
        'message'    => $job['token']
            ? 'Your BMS account is ready! You are now logged in.'
            : 'Your account is ready. Please log in with your phone number and password.',
    ];

    if (!$job['token'] || !$job['tenant_id']) {
        return $base;
    }

    try {
        $tenantStmt = $cpdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $tenantStmt->execute([$job['tenant_id']]);
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) return $base;

        $dbPass = decryptTenantSecret((string)$tenant['db_password_encrypted']);
        if ($dbPass === null) return $base;

        $tpdo = new PDO(
            'mysql:host=' . $tenant['db_host'] . ';dbname=' . $tenant['db_name'] . ';charset=utf8mb4',
            $tenant['db_username'], $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $tpdo->exec("SET time_zone = '+03:00'");

        $userStmt = $tpdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $userStmt->execute([$job['owner_phone']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return $base;

        $settingStmt = $tpdo->prepare("
            SELECT setting_key, setting_value FROM system_settings
            WHERE setting_key IN (?,?,?,?,?,?)
        ");
        $settingStmt->execute(['company_name','company_logo','company_currency','company_address','company_phone','currency']);
        $settings = [];
        while ($row = $settingStmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        // Same rule as core/currency.php bmsCompanyCurrency(): the web's `currency` key decides.
        $__c = strtoupper(trim((string)($settings['currency'] ?? '')));
        $settings['company_currency'] = preg_match('/^[A-Z]{3}$/', $__c) ? $__c : 'TZS';

        $base['user'] = [
            'id'         => (int)$user['user_id'],
            'name'       => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'first_name' => $user['first_name'] ?? '',
            'last_name'  => $user['last_name']  ?? '',
            'role'       => $user['user_role']  ?? 'admin',
            'role_id'    => (int)($user['role_id'] ?? 1),
            'phone'      => $user['username']   ?? $job['owner_phone'],
            'email'      => $user['email']      ?? '',
            'currency'   => $settings['company_currency'] ?? 'TZS',
        ];
        $base['company'] = [
            'name'     => $settings['company_name']     ?? $job['company_name'],
            'logo_url' => $settings['company_logo']     ?? '',
            'currency' => $settings['company_currency'] ?? 'TZS',
            'address'  => $settings['company_address']  ?? '',
            'phone'    => $settings['company_phone']    ?? $job['owner_phone'],
        ];
    } catch (Throwable $e) {
        error_log('register_status.php buildReadyPayload: ' . $e->getMessage());
    }

    return $base;
}
