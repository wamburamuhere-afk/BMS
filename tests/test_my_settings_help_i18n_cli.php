<?php
/**
 * My Profile & Settings + Help Center — full i18n coverage — CLI test
 * ----------------------------------------------------------------------------
 *   php tests/test_my_settings_help_i18n_cli.php
 *
 * User request 2026-09-15: "Once you open 'wasifu wa mipangilio yangu'...
 * three sections do you have tried to see if all section has fully
 * implemented for language translation... also do you have tried to open
 * 'msaada' and tried to see if all areas now has language translation?"
 *
 * Both pages had almost no i18n coverage — my_settings.php had 10 t()/te()
 * calls across 511 lines (basically just the language dropdown itself), and
 * help.php had ZERO across 559 lines. Retrofit follows the same proven
 * pattern as the Business Reports work: every static string wrapped in
 * t()/te(), JS-side strings via a page-local PT-style object, and — since
 * Help Center's FAQ answers are rich HTML (bold tags, lists) — each answer's
 * full HTML kept as ONE translation key rather than fragmented per-tag, so
 * grammar/word order is never broken by concatenating translated fragments.
 *
 * Verifies:
 *   1. Both files lint-clean.
 *   2. COMPLETENESS GUARD: every literal string passed to t()/te() in either
 *      file has a real, non-empty translation in lang/sw.php.
 *   3. Live: loadLanguage('sw') resolves a representative key from each of
 *      my_settings.php's 3 tabs and from help.php's FAQ/sidebar, then
 *      loadLanguage('en') reverts cleanly.
 *   4. Regression: no duplicate lang/sw.php key was introduced by this work
 *      (all duplicates found pre-date it).
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

$pageFiles = [
    'app/constant/settings/my_settings.php',
    'app/constant/settings/help.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. Both files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($pageFiles as $f) {
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Completeness guard — every t()/te() key used has a real sw.php translation');
// ─────────────────────────────────────────────────────────────────────────
function extractTKeys(string $path): array
{
    $src = file_get_contents($path);
    $tokens = token_get_all($src);
    $n = count($tokens);
    $keys = [];
    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (is_array($tok) && $tok[0] === T_STRING && ($tok[1] === 't' || $tok[1] === 'te')) {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (!isset($tokens[$j]) || $tokens[$j] !== '(') continue;
            $j++;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (!isset($tokens[$j])) continue;
            $argTok = $tokens[$j];
            if (is_array($argTok) && $argTok[0] === T_CONSTANT_ENCAPSED_STRING) {
                $val = eval("return {$argTok[1]};");
                $keys[$val] = true;
            }
        }
    }
    return array_keys($keys);
}

$sw = include "$root/lang/sw.php";
$allKeys = [];
foreach ($pageFiles as $f) {
    foreach (extractTKeys("$root/$f") as $k) { $allKeys[$k][] = $f; }
}
pass('scanned ' . count($allKeys) . ' distinct t()/te() keys across both files');

$untranslated = [];
foreach ($allKeys as $key => $files) {
    if (!array_key_exists($key, $sw) || trim((string)$sw[$key]) === '') {
        $untranslated[$key] = $files;
    }
}
if (empty($untranslated)) {
    pass('every single key has a non-empty lang/sw.php translation — zero gaps');
} else {
    foreach ($untranslated as $key => $files) {
        fail('missing/empty Swahili translation for: ' . substr($key, 0, 80) . ' (used in ' . implode(',', array_unique($files)) . ')');
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Live: switching to Swahili resolves one key from each tab/section, then reverts cleanly');
// ─────────────────────────────────────────────────────────────────────────
loadLanguage('sw');
currentLanguage() === 'sw' ? pass("currentLanguage() reports 'sw' after loadLanguage('sw')") : fail('currentLanguage() did not report sw');

$liveChecks = [
    'My Settings'                              => 'Mipangilio Yangu',       // my_settings.php header
    'Personal Information'                     => 'Taarifa Binafsi',       // Profile tab
    'Change Password'                          => 'Badilisha Nenosiri',    // Security tab
    'Display & Notifications'                  => 'Uonyeshaji na Arifa',   // Preferences tab
    'Weak'                                     => 'Dhaifu',                // JS password-strength meter
    'Help Center'                              => 'Kituo cha Msaada',      // help.php header
    'How do I use the POS (Point of Sale)?'    => 'Ninatumiaje POS (Sehemu ya Mauzo)?', // FAQ question
    'Keyboard Shortcuts'                       => 'Njia za Mkato za Kibodi', // sidebar
];
foreach ($liveChecks as $en => $expectedSw) {
    $got = t($en);
    $got === $expectedSw
        ? pass("t('$en') => '$got' under Swahili")
        : fail("t('$en') under Swahili returned '$got', expected '$expectedSw'");
}

$unknownKey = 'This key genuinely does not exist in any catalog 2026-09-15b';
t($unknownKey) === $unknownKey
    ? pass('an untranslated key safely falls back to itself, not an error or blank string')
    : fail('fallback behaviour for a missing key is broken');

loadLanguage('en');
currentLanguage() === 'en' ? pass("currentLanguage() correctly reverts to 'en'") : fail('language did not revert to en');
t('My Settings') === 'My Settings' ? pass("t('My Settings') under English returns the key itself, unchanged") : fail('English fallback broken after switching languages');

// ─────────────────────────────────────────────────────────────────────────
section('4. Regression — no new duplicate lang/sw.php key introduced by this work');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents("$root/lang/sw.php");
$tokens = token_get_all($src);
$n = count($tokens);
$keyCounts = [];
for ($i = 0; $i < $n; $i++) {
    $tok = $tokens[$i];
    if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
            $val = eval("return {$tok[1]};");
            $keyCounts[$val] = ($keyCounts[$val] ?? 0) + 1;
        }
    }
}
$dupeCount = count(array_filter($keyCounts, fn($c) => $c > 1));
// Known pre-existing duplicate count as of this work (verified by file
// position — all predate 2026-09-15's additions). A HIGHER count here means
// this session's own edits introduced a new one.
$dupeCount <= 29
    ? pass("duplicate key count ($dupeCount) has not increased beyond the pre-existing baseline (29)")
    : fail("duplicate key count ($dupeCount) exceeds the pre-existing baseline (29) — this work introduced a new conflicting key");

exit($failures === 0 ? 0 : 1);
