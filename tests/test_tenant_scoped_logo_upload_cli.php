<?php
/**
 * Tenant-scoped company logo uploads — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_tenant_scoped_logo_upload_cli.php
 *
 * Reported live: uploading a new company's logo appeared to change the logo
 * shown on other companies' sites. Root cause — app/constant/settings/
 * system_settings.php and app/constant/settings/company_profile.php both
 * hardcoded `uploads/system/logo/` (ROOT_DIR / __DIR__-based), a single
 * folder shared by every tenant since all tenants run from the same webroot
 * (core/tenant_bootstrap.php's own docblock: "ROOT_DIR is identical for all
 * of them"). company_profile.php was worse still — a FIXED filename
 * ('company_logo.<ext>', no timestamp or randomness), so any two tenants
 * that both saved a logo through it were GUARANTEED to overwrite each
 * other's file, not merely at risk of a rare same-second collision.
 *
 * Fix: both handlers now resolve their upload directory via
 * bmsUploadsDir('system/logo') / bmsUploadsRel('system/logo') — the exact
 * mechanism core/tenant_bootstrap.php was built for (added 2026-09-03
 * "after an incident", per its own docblock) — so each tenant's logo lands
 * under its own uploads/t{id}/system/logo/ subfolder, while the legacy
 * install (whose db_name matches this environment's DB_NAME) keeps the
 * original unprefixed path so its existing logo file stays valid.
 *
 * This is a narrow follow-up fix, not the full 52-handler initiative that
 * the same live audit found (see project memory / the conversation this
 * fix came from) — that is deliberately out of scope here.
 *
 * Verifies:
 *   1. Both files lint-clean.
 *   2. Neither file contains the old hardcoded ROOT_DIR/__DIR__ logo path
 *      any more; both call bmsUploadsDir('system/logo') and
 *      bmsUploadsRel('system/logo').
 *   3. Live filesystem: three different simulated tenant contexts (no
 *      tenant / tenant A / tenant B) each resolve to a DIFFERENT physical
 *      directory, a file written under one is invisible to the others'
 *      resolved path, and bmsUploadsRel() returns the matching relative
 *      path that would be stored in system_settings.company_logo.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/tenant_bootstrap.php";

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

$files = [
    'app/constant/settings/system_settings.php',
    'app/constant/settings/company_profile.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($files as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("$f missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$settingsSrc = file_get_contents("$root/app/constant/settings/system_settings.php");
$profileSrc  = file_get_contents("$root/app/constant/settings/company_profile.php");

$checks = [
    [$settingsSrc, "\$upload_dir = ROOT_DIR . '/uploads/system/logo/'", 'system_settings.php no longer hardcodes the shared logo path', true],
    [$settingsSrc, "bmsUploadsDir('system/logo')",        'system_settings.php resolves its upload dir via bmsUploadsDir()', false],
    [$settingsSrc, "bmsUploadsRel('system/logo') . \$file_name", 'system_settings.php stores the tenant-scoped relative path', false],

    [$profileSrc, "__DIR__ . '/../../../uploads/system/logo/'", 'company_profile.php no longer hardcodes the shared logo path', true],
    [$profileSrc, "bmsUploadsDir('system/logo')",        'company_profile.php resolves its upload dir via bmsUploadsDir()', false],
    [$profileSrc, "bmsUploadsRel('system/logo') . \$newFileName", 'company_profile.php stores the tenant-scoped relative path', false],
];
foreach ($checks as [$src, $needle, $label, $mustBeAbsent]) {
    $found = strpos($src, $needle) !== false;
    if ($mustBeAbsent) {
        !$found ? pass($label) : fail("$label — still present");
    } else {
        $found ? pass($label) : fail("$label — missing");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Live filesystem — three tenant contexts, three separate directories');
// ─────────────────────────────────────────────────────────────────────────
$origTenant = $GLOBALS['__bms_tenant'] ?? null;
$cleanupDirs = [];

try {
    // ── Context 1: no tenant (single-tenant / legacy install) ──
    $GLOBALS['__bms_tenant'] = null;
    $legacyDir = bmsUploadsDir('system/logo');
    $legacyRel = bmsUploadsRel('system/logo');
    (rtrim($legacyDir, '/\\') === rtrim((defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/uploads/system/logo', '/\\'))
        ? pass('no-tenant context resolves to the original, unprefixed uploads/system/logo/ (existing legacy logo stays valid)')
        : fail("no-tenant context resolved to an unexpected path: $legacyDir");
    ($legacyRel === 'uploads/system/logo/')
        ? pass("no-tenant bmsUploadsRel() returns the unprefixed relative path ($legacyRel)")
        : fail("no-tenant bmsUploadsRel() returned: $legacyRel");

    // ── Context 2 & 3: two different simulated real tenants ──
    $GLOBALS['__bms_tenant'] = ['id' => 900001, 'db_name' => 'bms_test_tenant_900001'];
    $dirA = bmsUploadsDir('system/logo');
    $relA = bmsUploadsRel('system/logo');
    $cleanupDirs[] = dirname(rtrim($dirA, '/\\'), 2); // .../uploads/t900001

    $GLOBALS['__bms_tenant'] = ['id' => 900002, 'db_name' => 'bms_test_tenant_900002'];
    $dirB = bmsUploadsDir('system/logo');
    $relB = bmsUploadsRel('system/logo');
    $cleanupDirs[] = dirname(rtrim($dirB, '/\\'), 2); // .../uploads/t900002

    (strpos($dirA, 't900001') !== false) ? pass("tenant 900001 gets its own subfolder ($dirA)") : fail("tenant 900001 dir wrong: $dirA");
    (strpos($dirB, 't900002') !== false) ? pass("tenant 900002 gets its own subfolder ($dirB)") : fail("tenant 900002 dir wrong: $dirB");
    ($dirA !== $dirB) ? pass('the two tenants resolve to DIFFERENT physical directories') : fail('both tenants resolved to the same directory — the bug is NOT fixed');
    ($dirA !== $legacyDir && $dirB !== $legacyDir) ? pass('neither tenant collides with the legacy/no-tenant directory') : fail('a tenant collided with the legacy directory');
    ($relA !== $relB && strpos($relA, 't900001/') !== false && strpos($relB, 't900002/') !== false)
        ? pass("bmsUploadsRel() returns distinct, correctly-prefixed relative paths ($relA vs $relB)")
        : fail("bmsUploadsRel() paths wrong: $relA vs $relB");

    // ── Prove actual file-content isolation, not just distinct string paths ──
    $filename = 'company_logo.png';
    file_put_contents($dirA . $filename, 'PRETEND-LOGO-BYTES-TENANT-A');
    file_put_contents($dirB . $filename, 'PRETEND-LOGO-BYTES-TENANT-B');

    $readA = file_get_contents($dirA . $filename);
    $readB = file_get_contents($dirB . $filename);
    ($readA === 'PRETEND-LOGO-BYTES-TENANT-A' && $readB === 'PRETEND-LOGO-BYTES-TENANT-B')
        ? pass('two tenants can use the IDENTICAL filename and each still reads back only their own bytes')
        : fail("cross-tenant content leak: A read '$readA', B read '$readB'");

    $legacyFile = $legacyDir . $filename;
    (!file_exists($legacyFile))
        ? pass('writing tenant A/B logos never touched the legacy/no-tenant directory at all')
        : fail('a tenant write leaked into the legacy directory');

} catch (Throwable $e) {
    fail('section 3 threw: ' . $e->getMessage());
} finally {
    // Cleanup: remove the two synthetic tenant upload trees entirely.
    foreach ($cleanupDirs as $d) {
        if ($d && is_dir($d) && strpos($d, 'uploads' . DIRECTORY_SEPARATOR . 't9000') !== false) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($d);
        }
    }
    // Remove the legacy-path test file only if OUR test wrote it (it wasn't
    // supposed to, per the assertion above — this is belt-and-braces).
    if (isset($legacyDir, $filename)) {
        $maybeLeak = $legacyDir . $filename;
        if (is_file($maybeLeak) && strpos((string)@file_get_contents($maybeLeak), 'PRETEND-LOGO-BYTES') === 0) {
            @unlink($maybeLeak);
        }
    }
    $GLOBALS['__bms_tenant'] = $origTenant;
    pass('synthetic tenant directories and test files cleaned up');
}

exit($failures === 0 ? 0 : 1);
