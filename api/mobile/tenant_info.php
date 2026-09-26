<?php
/**
 * api/mobile/tenant_info.php
 *
 * GET ?subdomain=<slug>
 *
 * No auth required. Returns company name, logo and contact info for a tenant
 * so the mobile app can display them on the login screen as soon as the user
 * finishes typing their subdomain — matching the web login page experience.
 *
 * Reachable from any origin; lookup is read-only and returns no sensitive data.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // 5-minute cache — name/logo rarely change

require_once __DIR__ . '/../../core/control_db.php';
require_once __DIR__ . '/../../core/tenant_crypto.php';
require_once __DIR__ . '/../../core/tenant_registration.php'; // tenantBaseDomain()

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$subdomain = strtolower(trim($_GET['subdomain'] ?? ''));

if ($subdomain === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,37}[a-z0-9]$|^[a-z0-9]{1,2}$/', $subdomain)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid subdomain (letters, digits, hyphens only).']);
    exit;
}

try {
    $cpdo = getControlPdo();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable.']);
    exit;
}

$stmt = $cpdo->prepare("SELECT * FROM tenants WHERE subdomain = ? AND status != 'deleted' LIMIT 1");
$stmt->execute([$subdomain]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No account found with that subdomain. Please check and try again.']);
    exit;
}

// Build the tenant base URL
$base      = tenantBaseDomain() ?? 'bms.bjptechnologies.co.tz';
$tenantUrl = 'https://' . $subdomain . '.' . $base;

// Defaults from the control DB tenant row (always available)
$companyName = $tenant['company_name'] ?? $subdomain;
$logoUrl     = '';
$currency    = 'TZS';
$address     = '';
$phone       = $tenant['owner_phone'] ?? '';

// Enrich from the tenant's live system_settings (best-effort)
try {
    $dbPass = decryptTenantSecret((string)($tenant['db_password_encrypted'] ?? ''));
    if ($dbPass !== null) {
        $tpdo = new PDO(
            'mysql:host=' . $tenant['db_host'] . ';dbname=' . $tenant['db_name'] . ';charset=utf8mb4',
            $tenant['db_username'], $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $tpdo->exec("SET time_zone = '+03:00'");

        $s = $tpdo->prepare("
            SELECT setting_key, setting_value FROM system_settings
            WHERE setting_key IN (?,?,?,?,?)
        ");
        $s->execute(['company_name', 'company_logo', 'company_currency', 'company_address', 'company_phone']);
        while ($row = $s->fetch(PDO::FETCH_ASSOC)) {
            switch ($row['setting_key']) {
                case 'company_name':
                    if ($row['setting_value'] !== '') $companyName = $row['setting_value'];
                    break;
                case 'company_logo':
                    $raw = trim($row['setting_value']);
                    if ($raw !== '') $logoUrl = $tenantUrl . '/' . ltrim($raw, '/');
                    break;
                case 'company_currency':
                    if ($row['setting_value'] !== '') $currency = $row['setting_value'];
                    break;
                case 'company_address': $address = $row['setting_value']; break;
                case 'company_phone':   $phone   = $row['setting_value']; break;
            }
        }
    }
} catch (Throwable $e) {
    // Fall through with control-DB defaults — never expose internal errors
}

echo json_encode([
    'success'      => true,
    'subdomain'    => $subdomain,
    'tenant_url'   => $tenantUrl,
    'company_name' => $companyName,
    'logo_url'     => $logoUrl,  // full URL or '' if no logo set
    'currency'     => $currency,
    'address'      => $address,
    'phone'        => $phone,
]);
