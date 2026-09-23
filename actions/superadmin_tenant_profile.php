<?php
/**
 * actions/superadmin_tenant_profile.php — on-demand company profile for one tenant.
 *
 * Reads company_phone, company_email, company_address, company_website,
 * company_tin, company_vrn from the tenant's own system_settings table.
 *
 * Same guard discipline and architectural rationale as
 * superadmin_tenant_usage.php — see that file's docblock. The deliberate,
 * narrow exception to "the superadmin panel never opens a tenant's own
 * database" kept as its own endpoint so the cross-boundary read is easy to
 * find, audit, and remove. Read-only. POST only — not cacheable.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (currentSuperadmin() === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();

$tenantId = (int)($_POST['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

try {
    $st = getControlPdo()->prepare(
        "SELECT db_host, db_name, db_username, db_password_encrypted, status FROM tenants WHERE id = ? LIMIT 1"
    );
    $st->execute([$tenantId]);
    $t = $st->fetch();

    if (!$t || $t['status'] === 'deleted') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tenant not found or has been deleted.']);
        exit;
    }

    $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
    if ($pw === null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not connect to the tenant database.']);
        exit;
    }

    $tPdo = new PDO(
        'mysql:host=' . $t['db_host'] . ';dbname=' . $t['db_name'] . ';charset=utf8mb4',
        $t['db_username'], $pw,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );

    $keys = ['company_phone', 'company_email', 'company_address', 'company_website', 'company_tin', 'company_vrn'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $st2 = $tPdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $st2->execute($keys);

    $s = [];
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s[$row['setting_key']] = $row['setting_value'];
    }

    echo json_encode([
        'success' => true,
        'phone'   => $s['company_phone']   ?? '',
        'email'   => $s['company_email']   ?? '',
        'address' => $s['company_address'] ?? '',
        'website' => $s['company_website'] ?? '',
        'tin'     => $s['company_tin']     ?? '',
        'vrn'     => $s['company_vrn']     ?? '',
    ]);
} catch (Throwable $e) {
    error_log('superadmin_tenant_profile(' . $tenantId . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not read company profile for this tenant.']);
}
