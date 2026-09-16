<?php
/**
 * User dropdown / dashboard banner — role name translation — CLI test
 * ----------------------------------------------------------------------------
 *   php tests/test_user_role_translation_cli.php
 *
 * User report 2026-09-15 (screenshot): the user dropdown already speaks
 * Swahili ("Wasifu na Mipangilio Yangu", "Msaada", "Toka") except the role
 * badge ("ADMIN"), which stayed English — $user_role was rendered raw via
 * htmlspecialchars() with no t() wrapping at all, in both header.php's user
 * dropdown (two spots: the collapsed toggle and the panel header) and
 * app/dashboard.php's welcome banner badge.
 *
 * Verifies:
 *   1. All 3 render sites (header.php x2, dashboard.php x1) wrap $user_role
 *      in t().
 *   2. Both files lint-clean.
 *   3. Live: every role name actually present in this database's `roles`
 *      table resolves to a real Swahili translation under loadLanguage('sw'),
 *      then reverts to 'en' with no state leakage.
 *   4. A role name with no catalog entry (a tenant's own custom role) safely
 *      falls back to itself rather than an error or blank string.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

$passes = 0; $failures = 0;
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Both files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach (['header.php', 'app/dashboard.php'] as $f) {
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring — every render site wraps $user_role in t()');
// ─────────────────────────────────────────────────────────────────────────
$headerSrc = file_get_contents("$root/header.php");
$headerHits = substr_count($headerSrc, 'htmlspecialchars(t($user_role))');
$headerHits === 2
    ? pass("header.php wraps \$user_role in t() at both render sites (found $headerHits)")
    : fail("header.php wraps \$user_role in t() at $headerHits site(s), expected exactly 2");
strpos($headerSrc, 'htmlspecialchars($user_role)') === false
    ? pass('no untranslated $user_role render site remains in header.php')
    : fail('header.php still has a raw, untranslated $user_role render site');

$dashSrc = file_get_contents("$root/app/dashboard.php");
strpos($dashSrc, 'htmlspecialchars(t($user_role))') !== false
    ? pass("dashboard.php's welcome-banner badge wraps \$user_role in t()")
    : fail("dashboard.php's welcome-banner badge does not translate \$user_role");

// ─────────────────────────────────────────────────────────────────────────
section('3. Live — every real role name in this database translates under Swahili');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;
$sw = include "$root/lang/sw.php";
$roles = $pdo->query("SELECT DISTINCT role_name FROM roles WHERE role_name IS NOT NULL AND role_name != ''")->fetchAll(PDO::FETCH_COLUMN);
pass('found ' . count($roles) . ' distinct role name(s) in this database');

loadLanguage('sw');
currentLanguage() === 'sw' ? pass("currentLanguage() reports 'sw'") : fail('currentLanguage() did not report sw');

$untranslated = [];
foreach ($roles as $r) {
    if (!array_key_exists($r, $sw) || trim((string)$sw[$r]) === '') { $untranslated[] = $r; continue; }
    $got = t($r);
    if ($got === $r) { $untranslated[] = $r; }
}
empty($untranslated)
    ? pass('every real role name in this database has a non-empty Swahili translation')
    : fail('missing Swahili translation for: ' . implode(', ', $untranslated));

// A hypothetical custom role a tenant might create — must never error/blank.
$custom = 'Warehouse Supervisor 2026-09-15 XYZ';
t($custom) === $custom
    ? pass('an unlisted (custom tenant) role name safely falls back to itself')
    : fail('fallback behaviour for an unlisted role name is broken');

loadLanguage('en');
currentLanguage() === 'en' ? pass("currentLanguage() correctly reverts to 'en'") : fail('language did not revert to en');
if (!empty($roles)) {
    $first = $roles[0];
    t($first) === $first ? pass("t('$first') under English returns the name itself, unchanged") : fail('English fallback broken after switching languages');
}

exit($failures === 0 ? 0 : 1);
