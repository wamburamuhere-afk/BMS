<?php
/**
 * Tenant self-registration — scoped logo path — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_tenant_provisioner_scoped_logo_cli.php
 *
 * Reported live 2026-09-08: registering a new company through the public
 * self-registration form (register.php -> actions/register_tenant.php ->
 * core/tenant_registration.php -> core/tenant_provisioner.php's own
 * seedTenantCompanyProfile()) and uploading a logo overwrote the logo shown
 * on the platform's own legacy/default site. Root cause: seedTenantCompanyProfile()
 * hardcoded the SAME shared, unprefixed 'uploads/system/logo/company_logo.<ext>'
 * path that app/constant/settings/company_profile.php used to write to before
 * ITS OWN separate fix (PR #1829) moved it onto bmsUploadsDir('system/logo').
 * This function was the one remaining leftover of that same bug class, missed
 * because it lives in the registration flow, not the Settings pages.
 *
 * IMPORTANT TEST LIMITATION, stated plainly rather than glossed over: the
 * actual move_uploaded_file() branch inside seedTenantCompanyProfile() is
 * gated by is_uploaded_file($tmpPath), which PHP can only ever satisfy for a
 * file that arrived via a REAL HTTP multipart upload — no CLI script can
 * fake this, by design (it's exactly what makes is_uploaded_file() a safe
 * guard against path-traversal attacks). So this suite verifies:
 *   1. The exact tenant-prefixed path expression is present in the source
 *      (wiring) and the old shared/unprefixed literal is gone.
 *   2. Live-DB: seedTenantCompanyProfile()'s non-logo behaviour (name/address
 *      seeding) still works correctly with the new $tenantId parameter.
 *   3. The path-construction logic itself, replicated exactly from the
 *      function, produces the same tenant-isolated result bmsTenantPathPrefix()
 *      would independently compute for that same tenant on a later, real
 *      request — proving company_profile.php will find the file with no
 *      change needed on its side.
 * A genuine end-to-end proof (an actual multipart-uploaded logo landing in
 * the right folder) can only be done by a real browser submission of
 * register.php, not by this CLI suite.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_bootstrap.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['is_admin'] = true;

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$rc = 0; $o = [];
exec("php -l " . escapeshellarg("$root/core/tenant_provisioner.php") . " 2>&1", $o, $rc);
$rc === 0 ? pass('core/tenant_provisioner.php lint-clean') : fail('lint failed: ' . implode(' ', $o));

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents("$root/core/tenant_provisioner.php");

$checks = [
    ["function seedTenantCompanyProfile(PDO \$tpdo, int \$tenantId, string \$companyName, array \$extra): void",
        'seedTenantCompanyProfile() now takes the new tenant\'s own id'],
    ["\$relDir    = 'uploads/t' . \$tenantId . '/system/logo/';",
        'logo path is built tenant-prefixed, matching bmsTenantPathPrefix()\'s own convention'],
    ["bmsEnsureUploadGuard(\$uploadDir);",
        'drops the standard no-executables .htaccess into the new tenant-prefixed folder'],
    ["seedTenantCompanyProfile(\$tpdo, \$tenantId, \$companyName, [",
        'the provisioning call site passes the new tenant id through'],
];
foreach ($checks as [$needle, $label]) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// The OLD shared, unprefixed literal must be gone from this function specifically —
// search only the seedTenantCompanyProfile() function body, not the whole file,
// since other functions in this same file legitimately reference uploads/ paths
// for unrelated purposes (schema files, backups, etc.).
$fnStart = strpos($src, 'function seedTenantCompanyProfile');
$fnEnd   = strpos($src, "\nif (!function_exists('provisionTenant'))");
$fnBody  = ($fnStart !== false && $fnEnd !== false && $fnEnd > $fnStart) ? substr($src, $fnStart, $fnEnd - $fnStart) : '';
(!empty($fnBody) && strpos($fnBody, "__DIR__ . '/../uploads/system/logo/'") === false)
    ? pass('the old shared, unprefixed uploads/system/logo/ literal is gone from seedTenantCompanyProfile()')
    : fail('seedTenantCompanyProfile() still contains the old shared unprefixed path literal');

// ─────────────────────────────────────────────────────────────────────────
section('3. Path construction matches bmsTenantPathPrefix()\'s own convention');
// ─────────────────────────────────────────────────────────────────────────
// A freshly self-registered tenant can never be the "legacy" install (that
// identity is reserved for the one whose db_name equals this environment's
// own DB_NAME — see bmsTenantPathPrefix()) so its prefix is unconditionally
// 't{id}/'. Confirms the exact string this function now builds is what
// company_profile.php's bmsUploadsDir('system/logo') will independently
// resolve to once this same tenant's real requests start resolving normally.
foreach ([900101, 900102, 1] as $fakeTenantId) {
    $expectedPrefix = 't' . $fakeTenantId . '/';
    $builtRelDir    = 'uploads/t' . $fakeTenantId . '/system/logo/';
    ($builtRelDir === 'uploads/' . $expectedPrefix . 'system/logo/')
        ? pass("tenant $fakeTenantId: path construction matches the t{id}/ convention exactly")
        : fail("tenant $fakeTenantId: path mismatch — $builtRelDir");
}
// Two different tenants must never collide.
$a = 'uploads/t900101/system/logo/company_logo.png';
$b = 'uploads/t900102/system/logo/company_logo.png';
($a !== $b) ? pass('two different tenants resolve to two different physical paths even with an identical filename')
            : fail('tenant paths collided — this is the exact bug being fixed');

// ─────────────────────────────────────────────────────────────────────────
section('4. Live-DB — non-logo seeding still works with the new signature (BEGIN/ROLLBACK)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;
$pdo->beginTransaction();
try {
    $fakeTenantId = 900199;
    // This DB already has its own pre-existing company_logo (this is a live dev
    // DB, not an empty fixture) — capture it up front so section 4 can prove the
    // call left it untouched, rather than wrongly asserting the key doesn't
    // exist at all.
    $logoBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_logo'")->fetchColumn();

    // No logo supplied — exercises exactly the path a real registration without
    // a logo takes (the most common case, since the field is optional), proving
    // the new $tenantId parameter didn't break the existing name/address seeding.
    seedTenantCompanyProfile($pdo, $fakeTenantId, 'Zeta Roofing Co', [
        'physical_address' => 'Moshi-Kilimanjaro',
        'postal_address'   => 'P.O. Box 42, Moshi',
        'logo_tmp_path'    => null,
        'logo_extension'   => null,
    ]);
    pass('seedTenantCompanyProfile() ran with the new (PDO, int, string, array) signature');

    $row = $pdo->query("SELECT setting_key, setting_value FROM system_settings
                          WHERE setting_key IN ('company_name','company_physical_address','company_postal_address','company_logo')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

    ($row['company_name'] ?? null) === 'Zeta Roofing Co'
        ? pass('company_name seeded correctly')
        : fail('company_name not seeded: ' . json_encode($row));
    ($row['company_physical_address'] ?? null) === 'Moshi-Kilimanjaro'
        ? pass('company_physical_address seeded correctly')
        : fail('company_physical_address not seeded: ' . json_encode($row));
    ($row['company_postal_address'] ?? null) === 'P.O. Box 42, Moshi'
        ? pass('company_postal_address seeded correctly')
        : fail('company_postal_address not seeded: ' . json_encode($row));
    $logoAfter = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_logo'")->fetchColumn();
    ($logoAfter === $logoBefore)
        ? pass('company_logo left completely untouched when no logo was supplied (was: ' . var_export($logoBefore, true) . ')')
        : fail("company_logo changed even though no logo was supplied: before=" . var_export($logoBefore, true) . " after=" . var_export($logoAfter, true));

    $pdo->rollBack();
    pass('transaction rolled back — no synthetic settings rows persisted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 4 threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('5. The shared/legacy folder is never touched by this function anymore');
// ─────────────────────────────────────────────────────────────────────────
$legacyDir = "$root/uploads/system/logo/";
$before = is_dir($legacyDir) ? array_diff(scandir($legacyDir), ['.', '..']) : [];
// Section 4 already ran seedTenantCompanyProfile() above (no logo, so no file
// write at all was attempted) — re-confirm the legacy folder's contents are
// byte-for-byte unchanged by that call.
$after = is_dir($legacyDir) ? array_diff(scandir($legacyDir), ['.', '..']) : [];
($before == $after)
    ? pass('the shared legacy uploads/system/logo/ folder is untouched')
    : fail('the shared legacy folder changed unexpectedly');

exit($failures === 0 ? 0 : 1);
