<?php
/**
 * POS module — full i18n (language) coverage — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_i18n_coverage_cli.php
 *
 * User request 2026-09-08: switching the logged-in user's language
 * preference (Settings > My Settings > Preferences > Language) must change
 * everything in the POS module and everything related to it — not just a
 * few pages. Uses the existing app-wide i18n system (core/i18n.php: t()/te(),
 * lang/en.php empty-by-design, lang/sw.php holding Swahili), the exact same
 * mechanism already live elsewhere (e.g. app/bms/product/products.php) —
 * no new mechanism was invented for POS.
 *
 * Scope: the 9 POS page files (Workspace, modals, terminal JS, Dashboard,
 * Z-Report, Shift History, Customer Display, POS Settings, Price Groups —
 * added Phase 14) plus the 24 genuinely POS-functional API files under
 * api/pos/ (the other files in that folder are HR/Payroll — departments,
 * designations, salary components, holidays — co-located there by
 * historical accident, not part of "POS" and deliberately out of scope;
 * search_customers.php is also excluded here — it has zero t()/te() calls).
 *
 * Verifies:
 *   1. All 33 files lint-clean.
 *   2. Every one of the 24 API files loads the caller's saved language
 *      preference (they never included header.php, so t() would otherwise
 *      always default to English regardless of the user's setting).
 *   3. COMPLETENESS GUARD: every literal string passed to t()/te() anywhere
 *      in these 33 files has a real, non-empty translation in lang/sw.php.
 *      This is the regression guard that matters most — it fails loudly if
 *      a future POS change adds a new t()-wrapped string and forgets to
 *      translate it, rather than silently shipping an English word in the
 *      middle of a Swahili screen.
 *   4. Live: loadLanguage('sw') actually resolves representative keys from
 *      BOTH a page file and an API file to real Swahili, then loadLanguage('en')
 *      correctly reverts with no state leakage.
 *   5. Regression guard for the specific "translated sentence fragments
 *      concatenated back together" bug class found and fixed during this
 *      work (breaks grammar in any language that reorders words relative to
 *      English) — confirms the two fixed spots stay fixed.
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
    'app/bms/pos/pos.php', 'app/bms/pos/pos_modals_new.php', 'app/bms/pos/pos_scripts_new.php',
    'app/bms/pos/pos_dashboard.php', 'app/bms/pos/zreport.php', 'app/bms/pos/shift_history.php',
    'app/bms/pos/customer_display.php', 'app/constant/settings/pos_config_settings.php',
    // Phase 14 (pos_upgrade_plan.md §8) — selling price tiers.
    'app/bms/pos/price_groups.php',
];
$apiFiles = [
    'api/pos/close_shift.php', 'api/pos/create_return.php', 'api/pos/delete_held_sale.php',
    'api/pos/email_receipt.php', 'api/pos/generate_receipt_number.php', 'api/pos/get_dashboard.php',
    'api/pos/get_held_sales.php', 'api/pos/get_registers.php', 'api/pos/get_sale_items.php',
    'api/pos/get_sales.php', 'api/pos/hold_sale.php', 'api/pos/open_shift.php', 'api/pos/process_sale.php',
    'api/pos/receive_payment.php', 'api/pos/save_register.php', 'api/pos/simple_products.php',
    'api/pos/test_products.php', 'api/pos/toggle_register_status.php', 'api/pos/void_sale.php',
    // Phase 14 (pos_upgrade_plan.md §8) — selling price tiers.
    'api/pos/get_price_groups.php', 'api/pos/save_price_group.php', 'api/pos/toggle_price_group_status.php',
    'api/pos/get_price_group_products.php', 'api/pos/save_price_group_product_price.php',
    // Phase 15 (pos_upgrade_plan.md §8) — unit conversion at the register.
    'api/pos/get_product_units.php',
    // Phase 21 (pos_upgrade_plan.md §8) — network (IP) thermal printer.
    'api/pos/print_receipt.php', 'api/pos/test_network_printer.php',
    // Phase 26 (pos_upgrade_plan.md §9) — serial/IMEI-level stock tracking.
    'api/pos/get_available_serials.php',
    // Phase 29 (pos_upgrade_plan.md §9) — POS Dashboard Intelligence.
    'api/pos/save_sales_target.php',
    // Phase 30 (pos_upgrade_plan.md §9) — Restaurant Module backend.
    'api/restaurant/get_floors.php', 'api/restaurant/save_floor.php',
    'api/restaurant/get_tables.php', 'api/restaurant/save_table.php', 'api/restaurant/update_table_status.php',
    'api/restaurant/get_kitchen_stations.php', 'api/restaurant/save_kitchen_station.php',
    'api/restaurant/send_to_kitchen.php', 'api/restaurant/get_kitchen_tickets.php', 'api/restaurant/update_ticket_status.php',
    'api/restaurant/get_modifier_groups.php', 'api/restaurant/save_modifier_group.php', 'api/restaurant/save_modifier_option.php',
    'api/restaurant/get_product_modifier_groups.php', 'api/restaurant/save_product_modifier_links.php',
    'api/restaurant/get_reservations.php', 'api/restaurant/save_reservation.php', 'api/restaurant/update_reservation_status.php',
];
// Core helper files that call t()/te() directly (achievement-band labels
// etc.) but aren't a page or an API endpoint themselves, so section 2's
// "loads the caller's language preference" check doesn't apply to them —
// still scanned for lint + translation completeness like everything else.
$coreFiles = [
    // Phase 29 (pos_upgrade_plan.md §9) — POS Dashboard Intelligence.
    'core/pos_dashboard_metrics.php',
    // Phase 30 (pos_upgrade_plan.md §9) — Restaurant Module backend.
    'core/pos_nav.php',
];
$allFiles = array_merge($pageFiles, $apiFiles, $coreFiles);

// ─────────────────────────────────────────────────────────────────────────
section('1. All ' . count($allFiles) . ' files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($allFiles as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("$f missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Every POS API file loads the caller\'s saved language preference');
// ─────────────────────────────────────────────────────────────────────────
foreach ($apiFiles as $f) {
    $src = file_get_contents("$root/$f");
    strpos($src, "loadLanguage(\$_SESSION['user_lang'])") !== false
        ? pass("$f loads the user's saved language")
        : fail("$f never calls loadLanguage() — its t()-wrapped messages would always be English regardless of the user's preference");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Completeness guard — every t()/te() key used has a real sw.php translation');
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
foreach ($allFiles as $f) {
    foreach (extractTKeys("$root/$f") as $k) { $allKeys[$k][] = $f; }
}
pass('scanned ' . count($allKeys) . ' distinct t()/te() keys across all ' . count($allFiles) . ' files');

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
section('4. Live: switching to Swahili actually changes representative strings, then reverts cleanly');
// ─────────────────────────────────────────────────────────────────────────
loadLanguage('sw');
currentLanguage() === 'sw' ? pass("currentLanguage() reports 'sw' after loadLanguage('sw')") : fail('currentLanguage() did not report sw');

$liveChecks = [
    'Start Shift'            => 'Anza Zamu',       // page file (pos_modals_new.php)
    'Force Close'            => 'Funga kwa Nguvu', // page file (shift_history.php)
    'Register not found.'    => 'Rejista haikupatikana.', // API file (toggle_register_status.php)
    'Unauthorized'           => 'Huna ruhusa',      // API file (shared across most)
    'in use by %s since %s'  => 'inatumika na %s tangu %s', // JS-embedded, placeholder-based
];
foreach ($liveChecks as $en => $expectedSw) {
    $got = t($en);
    $got === $expectedSw
        ? pass("t('$en') => '$got' under Swahili")
        : fail("t('$en') under Swahili returned '$got', expected '$expectedSw'");
}

$unknownKey = 'This key genuinely does not exist in any catalog 2026-09-08';
t($unknownKey) === $unknownKey
    ? pass('an untranslated key safely falls back to itself, not an error or blank string')
    : fail('fallback behaviour for a missing key is broken');

loadLanguage('en');
currentLanguage() === 'en' ? pass("currentLanguage() correctly reverts to 'en'") : fail('language did not revert to en');
t('Start Shift') === 'Start Shift' ? pass("t('Start Shift') under English returns the key itself, unchanged") : fail('English fallback broken after switching languages');

// ─────────────────────────────────────────────────────────────────────────
section('5. Regression guard: fixed sentence-fragment-concatenation spots stay fixed');
// ─────────────────────────────────────────────────────────────────────────
// Found live during this work: translating "This closes" / "on" / "opened by"
// as SEPARATE keys and concatenating them at runtime breaks grammar in any
// language whose word order differs from English. Fixed with single-template
// keys carrying {0}/{1}/{2} (or %s) placeholders instead. This guards against
// a future edit silently reintroducing the same anti-pattern.
$shiftHistorySrc = file_get_contents("$root/app/bms/pos/shift_history.php");
strpos($shiftHistorySrc, 'function tFormat(') !== false
    ? pass('shift_history.php still defines the numbered-placeholder tFormat() helper')
    : fail('tFormat() helper is missing from shift_history.php');
strpos($shiftHistorySrc, "t('This closes <b>{0}</b> on <b>{1}</b>, opened by <b>{2}</b>") !== false
    ? pass('the force-close confirmation message is still ONE coherent translatable sentence, not concatenated fragments')
    : fail('the force-close confirmation message no longer uses the single-sentence {0}/{1}/{2} template');

$scriptsSrc = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
strpos($scriptsSrc, "t('in use by %s since %s')") !== false
    ? pass("pos_scripts_new.php's busy-register label is still ONE template, not separate 'in use by' + 'since' fragments")
    : fail("pos_scripts_new.php's busy-register label regressed back to fragment concatenation");

exit($failures === 0 ? 0 : 1);
