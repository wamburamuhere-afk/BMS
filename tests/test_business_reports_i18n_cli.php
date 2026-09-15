<?php
/**
 * Business Reports — full i18n (language) coverage — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_business_reports_i18n_cli.php
 *
 * User request 2026-09-15: the 5 pages under Reports > Business Reports
 * (Sales, Purchase, PO vs Invoice, Inventory, Expense) had almost no
 * translation coverage — 7/6/1/5/6 t()/te() calls across 2,100+ lines.
 * Retrofit follows the exact pattern already proven by the POS module
 * (see test_pos_i18n_coverage_cli.php): t()/te() in PHP, a page-local
 * `const PT = {...}` object (json_encode(t(...))) for JS-side strings,
 * and tFormat({0}/{1}) placeholder templates instead of concatenating
 * translated fragments (po_invoice_report.php).
 *
 * Verifies:
 *   1. All 5 files lint-clean.
 *   2. COMPLETENESS GUARD: every literal string passed to t()/te() anywhere
 *      in these 5 files has a real, non-empty translation in lang/sw.php.
 *   3. Live: loadLanguage('sw') actually resolves representative keys from
 *      each of the 5 files to real Swahili, then loadLanguage('en') reverts
 *      with no state leakage.
 *   4. Regression guard for the fragment-concatenation bug class: the
 *      po_invoice_report.php composed sentences stay single templates with
 *      {0}/{1} placeholders, not concatenated translated fragments.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

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

$pageFiles = [
    'app/constant/reports/sales_report.php',
    'app/constant/reports/purchase_report.php',
    'app/bms/invoice/po_invoice_report.php',
    'app/constant/reports/inventory_report.php',
    'app/constant/reports/expense_report.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. All ' . count($pageFiles) . ' files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($pageFiles as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("$f missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
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
pass('scanned ' . count($allKeys) . ' distinct t()/te() keys across all ' . count($pageFiles) . ' files');

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
section('3. Live: switching to Swahili actually changes representative strings, then reverts cleanly');
// ─────────────────────────────────────────────────────────────────────────
loadLanguage('sw');
currentLanguage() === 'sw' ? pass("currentLanguage() reports 'sw' after loadLanguage('sw')") : fail('currentLanguage() did not report sw');

$liveChecks = [
    'Gross Revenue'           => 'Mapato Jumla',                   // sales_report.php
    'Spend Trend'             => 'Mwelekeo wa Matumizi',           // purchase_report.php
    'PO vs Invoice Report'    => 'Ripoti ya Oda dhidi ya Ankara',  // po_invoice_report.php
    'Stock Snapshot'          => 'Muhtasari wa Hisa',              // inventory_report.php
    'Expense Entries'         => 'Kumbukumbu za Matumizi',         // expense_report.php
];
foreach ($liveChecks as $en => $expectedSw) {
    $got = t($en);
    $got === $expectedSw
        ? pass("t('$en') => '$got' under Swahili")
        : fail("t('$en') under Swahili returned '$got', expected '$expectedSw'");
}

$unknownKey = 'This key genuinely does not exist in any catalog 2026-09-15';
t($unknownKey) === $unknownKey
    ? pass('an untranslated key safely falls back to itself, not an error or blank string')
    : fail('fallback behaviour for a missing key is broken');

loadLanguage('en');
currentLanguage() === 'en' ? pass("currentLanguage() correctly reverts to 'en'") : fail('language did not revert to en');
t('Gross Revenue') === 'Gross Revenue' ? pass("t('Gross Revenue') under English returns the key itself, unchanged") : fail('English fallback broken after switching languages');

// ─────────────────────────────────────────────────────────────────────────
section('4. Regression guard: composed sentences stay single templates, not concatenated fragments');
// ─────────────────────────────────────────────────────────────────────────
$poSrc = file_get_contents("$root/app/bms/invoice/po_invoice_report.php");
strpos($poSrc, 'function tFormat(') !== false
    ? pass('po_invoice_report.php still defines the numbered-placeholder tFormat() helper')
    : fail('tFormat() helper is missing from po_invoice_report.php');
strpos($poSrc, "t('Could not load the report (HTTP {0}). Check your connection and try again.')") !== false
    ? pass('the HTTP-status error message is still ONE coherent translatable sentence, not concatenated fragments')
    : fail('the HTTP-status error message regressed back to fragment concatenation');
strpos($poSrc, "t('{0} invoice(s) · {1}% billed')") !== false
    ? pass('the card-view invoice-count summary is still ONE template, not separate fragments')
    : fail('the card-view invoice-count summary regressed back to fragment concatenation');

exit($failures === 0 ? 0 : 1);
