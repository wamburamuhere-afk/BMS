<?php
/**
 * cron/process_registration_jobs.php
 * ---------------------------------------------------------------------------
 * Background worker — provisions one pending registration job per run.
 *
 * Uses SELECT ... FOR UPDATE in a transaction to atomically claim exactly one
 * 'pending' job, so two concurrent runs never provision the same tenant twice.
 *
 * Recommended schedule: every 1 minute via server cron.
 *   * * * * * php /path/to/bms/cron/process_registration_jobs.php >> /dev/null
 *
 * Also triggered opportunistically (best-effort) from api/mobile/register.php
 * on each job creation — so the first user typically does not need to wait a
 * full cron cycle.
 *
 * Safe to run as HTTP too (internal use), but cron is the reliable path.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';

@set_time_limit(300);
@ignore_user_abort(true);

$cpdo = getControlPdo();

// ── Atomically claim one pending job ─────────────────────────────────────────
// SELECT ... FOR UPDATE prevents two concurrent workers from picking the same
// row. The UPDATE sets status immediately inside the transaction so the row is
// invisible to the next worker before we commit.
try {
    $cpdo->beginTransaction();

    $findStmt = $cpdo->prepare("
        SELECT id FROM registration_jobs
        WHERE status = 'pending'
        ORDER BY created_at ASC
        LIMIT 1
        FOR UPDATE
    ");
    $findStmt->execute();
    $pendingId = $findStmt->fetchColumn();

    if (!$pendingId) {
        $cpdo->rollBack();
        if (php_sapi_name() === 'cli') echo "No pending registration jobs.\n";
        exit(0);
    }

    $cpdo->prepare("
        UPDATE registration_jobs SET status = 'provisioning', started_at = NOW() WHERE id = ?
    ")->execute([$pendingId]);

    $cpdo->commit();
} catch (Throwable $e) {
    $cpdo->rollBack();
    error_log('process_registration_jobs: claim failed: ' . $e->getMessage());
    exit(1);
}

// ── Fetch the full job row ────────────────────────────────────────────────────
$stmt = $cpdo->prepare("SELECT * FROM registration_jobs WHERE id = ? LIMIT 1");
$stmt->execute([$pendingId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    exit(0);
}

if (php_sapi_name() === 'cli') {
    echo "Processing job {$job['job_id']} — subdomain={$job['subdomain']} company={$job['company_name']}\n";
}

// ── Decrypt the owner's password ─────────────────────────────────────────────
$password = decryptTenantSecret((string)$job['owner_pass_enc']);
if ($password === null) {
    procRegFail($cpdo, $job['job_id'], 'Could not decrypt owner password — provisioning aborted.');
    exit(1);
}

// ── Provision the tenant ──────────────────────────────────────────────────────
try {
    $result = provisionTenant(
        $job['company_name'],
        $job['subdomain'],
        $job['owner_phone'],   // stored as users.username
        $password,
        [
            'status'           => 'active',
            'owner_first_name' => $job['owner_first_name'],
            'owner_last_name'  => $job['owner_last_name'],
            'owner_phone'      => $job['owner_phone'],
            'email'            => $job['owner_email'],
            'physical_address' => $job['phys_address'],
            'postal_address'   => $job['post_address'],
            'phone'            => $job['owner_phone'],
            'skip_welcome_email' => true,
        ]
    );

    if (!$result['ok']) {
        throw new RuntimeException($result['error'] ?? 'provisionTenant returned not-ok');
    }

    $tenantId = (int)$result['tenant_id'];

} catch (Throwable $e) {
    procRegFail($cpdo, $job['job_id'], $e->getMessage());
    exit(1);
}

// ── Issue a Bearer token into the new tenant DB ───────────────────────────────
$token    = null;
$loginUrl = $job['tenant_url'];

try {
    $tenantStmt = $cpdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $tenantStmt->execute([$tenantId]);
    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

    if ($tenant) {
        // Recompute the URL now that we have the confirmed subdomain
        $confirmedSub = $result['subdomain'] ?? $job['subdomain'];
        $base         = tenantBaseDomain() ?? '';
        $loginUrl     = 'https://' . $confirmedSub . '.' . $base . '/login';

        $dbPass = decryptTenantSecret((string)$tenant['db_password_encrypted']);
        if ($dbPass !== null) {
            $tpdo = new PDO(
                'mysql:host=' . $tenant['db_host'] . ';dbname=' . $tenant['db_name'] . ';charset=utf8mb4',
                $tenant['db_username'], $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $tpdo->exec("SET time_zone = '+03:00'");

            // Find the owner user (provisioner stores phone as username)
            $userStmt = $tpdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
            $userStmt->execute([$job['owner_phone']]);
            $userId = $userStmt->fetchColumn();

            if (!$userId) {
                $userId = $tpdo->query("SELECT user_id FROM users WHERE role_id = 1 LIMIT 1")->fetchColumn();
            }

            if ($userId) {
                $token = bin2hex(random_bytes(32));
                $tpdo->prepare("
                    INSERT INTO mobile_tokens (token, user_id, device_name, expires_at)
                    VALUES (?, ?, ?, NULL)
                ")->execute([$token, $userId, $job['device_name']]);
                $tpdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")
                     ->execute([$userId]);
            }
        }
    }
} catch (Throwable $e) {
    // Token issuance failed but tenant was provisioned — not fatal.
    // User can still log in via api/mobile/login.php.
    error_log('process_registration_jobs: token issuance failed for job ' . $job['job_id'] . ': ' . $e->getMessage());
}

// ── Mark job ready; clear the encrypted password ─────────────────────────────
$cpdo->prepare("
    UPDATE registration_jobs
       SET status = 'ready',
           tenant_id = ?,
           tenant_url = ?,
           token = ?,
           owner_pass_enc = '',
           completed_at = NOW()
     WHERE job_id = ?
")->execute([$tenantId, $loginUrl, $token, $job['job_id']]);

if (php_sapi_name() === 'cli') {
    echo "Done — tenant_id={$tenantId} token=" . ($token ? 'issued' : 'none (user can log in manually)') . "\n";
}
exit(0);

// ── Helper ────────────────────────────────────────────────────────────────────

function procRegFail(PDO $cpdo, string $jobId, string $reason): void
{
    error_log("process_registration_jobs: FAILED job {$jobId}: {$reason}");
    try {
        $cpdo->prepare("
            UPDATE registration_jobs
               SET status = 'failed', owner_pass_enc = '', error_message = ?, completed_at = NOW()
             WHERE job_id = ?
        ")->execute([substr($reason, 0, 1000), $jobId]);
    } catch (Throwable $e) {
        error_log('process_registration_jobs: could not update failed status: ' . $e->getMessage());
    }
    if (php_sapi_name() === 'cli') echo "FAILED: {$reason}\n";
}
