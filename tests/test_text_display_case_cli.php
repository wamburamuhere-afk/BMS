<?php
/**
 * Text Display Case — Phase 0 (foundation engine) — CLI regression suite
 *   php tests/test_text_display_case_cli.php
 *
 * Covers the global "Text Display Case" setting (2026-09-18 request,
 * visible for both Simple POS and normal tenants): a READ-ONLY display
 * formatting rule (core/text_display_case.php) — raw stored data is never
 * rewritten, so toggling the setting re-formats every existing record too,
 * losslessly, in either direction.
 *
 *   A. STATIC   — files lint clean; core/text_display_case.php is loaded
 *                 globally via roots.php (same pattern as core/terminology.php).
 *   B. ENGINE   — all 6 modes (as_typed/sentence/lower/upper/title/toggle)
 *                 transform correctly across a battery of real-world inputs:
 *                 multi-sentence text, mixed case, UTF-8 (Swahili names),
 *                 numbers/codes, single words, already-correct casing.
 *   C. SAFETY   — caseFormat() escapes HTML correctly in the RIGHT ORDER
 *                 (transform raw text first, then escape) so an ampersand
 *                 or a literal <script> in stored data can never produce
 *                 broken markup or an XSS hole via the case transform.
 *   D. DEFAULTS — null/empty/unset behave exactly like safe_output() would.
 *   E. SETTING  — system_settings.php's save handler whitelists the posted
 *                 value (rejects garbage), the select renders all 6 options
 *                 with the correct one marked selected, and a real save/read
 *                 round-trip through save_setting()/get_setting() works.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 80) . "`"); }
function eq($actual, $expected, string $label): void {
    ($actual === $expected) ? pass($label) : fail("$label — expected " . var_export($expected, true) . ", got " . var_export($actual, true));
}
// get_setting() caches the WHOLE system_settings table in a static var, once
// per PHP process — save_setting() writes to the DB but never invalidates
// that cache. So any save-then-read check must use two SEPARATE processes
// (this helper), never a save + get_setting() in the same script.
function _tdc_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'tdc_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach (['core/text_display_case.php', 'app/constant/settings/system_settings.php', 'roots.php'] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Loaded globally (same pattern as core/terminology.php)');
has(src($root, 'roots.php'), "require_once ROOT_DIR . '/core/text_display_case.php';", 'roots.php requires core/text_display_case.php unconditionally');
(function_exists('caseFormat')) ? pass('caseFormat() is available after requiring roots.php alone (no extra require needed)') : fail('caseFormat() not loaded — a page would need its own require_once');
(function_exists('applyCaseMode')) ? pass('applyCaseMode() available') : fail('applyCaseMode() not loaded');
(function_exists('textDisplayCaseMode')) ? pass('textDisplayCaseMode() available') : fail('textDisplayCaseMode() not loaded');

// ─────────────────────────────────────────────────────────────────────────
section('3. Engine — each mode, real-world battery');

eq(applyCaseMode('John Mwangi', 'as_typed'), 'John Mwangi', 'as_typed: unchanged');
eq(applyCaseMode('jOhN mWaNgI', 'as_typed'), 'jOhN mWaNgI', 'as_typed: unchanged even for weird input');

eq(applyCaseMode('JOHN MWANGI SUPPLIES', 'lower'), 'john mwangi supplies', 'lower: simple');
eq(applyCaseMode('Bidhaa Ya Kwanza', 'lower'), 'bidhaa ya kwanza', 'lower: Swahili name');

eq(applyCaseMode('john mwangi supplies', 'upper'), 'JOHN MWANGI SUPPLIES', 'upper: simple');
eq(applyCaseMode('bidhaa ya kwanza', 'upper'), 'BIDHAA YA KWANZA', 'upper: Swahili name');

eq(applyCaseMode('john mwangi supplies co.', 'title'), 'John Mwangi Supplies Co.', 'title: Capitalize Each Word');
eq(applyCaseMode('JOHN MWANGI', 'title'), 'John Mwangi', 'title: normalizes from all-caps too (lowercases first, then title-cases)');
eq(applyCaseMode('a b c', 'title'), 'A B C', 'title: single-letter words');

eq(applyCaseMode('the quick brown fox. it JUMPS! really?', 'sentence'), 'The quick brown fox. It jumps! Really?', 'sentence: real multi-sentence capitalization, not just ucfirst() of the whole string');
eq(applyCaseMode('john', 'sentence'), 'John', 'sentence: single word');
eq(applyCaseMode('JOHN MWANGI', 'sentence'), 'John mwangi', 'sentence: only first letter of first sentence stays capital, rest lowercase');

eq(applyCaseMode('Hello World', 'toggle'), 'hELLO wORLD', 'toggle: inverts every letter');
eq(applyCaseMode('AbC123xYz', 'toggle'), 'aBc123XyZ', 'toggle: numbers pass through untouched, letters invert');

// Numbers / codes — must never error or get mangled beyond the literal case rule
eq(applyCaseMode('BSX-SUP-0001', 'lower'), 'bsx-sup-0001', 'codes: lower works on a product/supplier code without error');
eq(applyCaseMode('BSX-SUP-0001', 'upper'), 'BSX-SUP-0001', 'codes: upper is a no-op when already upper');

// Empty string round-trips as empty (not the default) at the transform level —
// caseFormat() (section 4) is where the actual default substitution happens.
eq(applyCaseMode(''), '', 'applyCaseMode(""): empty string passes through, no crash');

// ─────────────────────────────────────────────────────────────────────────
section('4. caseFormat() — escaping order + defaults (the function pages will actually call)');

eq(caseFormat(null), 'N/A', 'null uses the default');
eq(caseFormat(''), 'N/A', 'empty string uses the default');
eq(caseFormat(null, '—'), '—', 'custom default is honored');
eq(caseFormat('John Mwangi'), 'John Mwangi', 'plain text passes through unescaped-looking (no special chars to escape)');

// The critical entity-corruption check: escaping must happen AFTER the case
// transform, or "Tom & Jerry" -> escaped "&amp;" -> uppercased would wrongly
// produce "TOM &AMP; JERRY" (an entity no browser recognizes) instead of the
// correct "TOM &amp; JERRY". Uses the explicit $mode override (not
// save_setting()+get_setting() in-process, which get_setting()'s per-process
// cache makes unreliable — see section 6 for a real save/read round-trip
// that correctly uses separate subprocesses instead).
eq(caseFormat('Tom & Jerry', 'N/A', 'upper'), 'TOM &amp; JERRY', 'ampersand: case-transform-then-escape order is correct (not the corrupting escape-then-transform order)');

// XSS safety must survive the case transform in every mode.
foreach (['as_typed', 'sentence', 'lower', 'upper', 'title', 'toggle'] as $mode) {
    $out = caseFormat('<script>alert(1)</script>', 'N/A', $mode);
    (strpos($out, '<script>') === false && strpos($out, '&lt;') !== false)
        ? pass("XSS safety holds under mode '$mode'")
        : fail("XSS safety BROKEN under mode '$mode': $out");
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Invalid/unset setting values fall back safely');

_tdc_run_php("require '$root/roots.php'; save_setting('text_display_case', 'literally_anything_else'); echo 'SET';");
$fallbackCheck = _tdc_run_php("require '$root/roots.php'; echo textDisplayCaseMode() . '|' . applyCaseMode('John Mwangi');");
eq($fallbackCheck, 'as_typed|John Mwangi', 'a garbage stored value falls back to as_typed cleanly (both textDisplayCaseMode() and applyCaseMode() with no explicit mode), never crashes or applies garbage');
_tdc_run_php("require '$root/roots.php'; save_setting('text_display_case', 'as_typed'); echo 'RESET';");

// ─────────────────────────────────────────────────────────────────────────
section('6. System Settings page — save handler + rendered select');

$settingsSrc = src($root, 'app/constant/settings/system_settings.php');
has($settingsSrc, "in_array(\$_POST['text_display_case'] ?? '', ['as_typed', 'sentence', 'lower', 'upper', 'title', 'toggle'], true)", 'save_general whitelists the posted value before saving');
has($settingsSrc, 'id="text_display_case" name="text_display_case"', 'the select field exists with the correct name');
foreach (['as_typed', 'sentence', 'lower', 'upper', 'title', 'toggle'] as $mode) {
    has($settingsSrc, "value=\"$mode\"", "option for mode '$mode' present in the select");
}
has($settingsSrc, 'id="text_case_preview_box"', 'live preview box present');
has($settingsSrc, 'function updateTextCasePreview()', 'JS preview function defined');

// Live render: confirm the CURRENT setting is marked selected in the actual output.
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
if (!$uid) {
    pass('no admin user fixture available — live render check skipped (n/a)');
} else {
    _tdc_run_php("require '$root/roots.php'; save_setting('text_display_case', 'title'); echo 'SET';");

    $rendered = _tdc_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        ob_start();
        include '$root/app/constant/settings/system_settings.php';
        echo ob_get_clean();
    ");
    (strpos($rendered, 'value="title" selected') !== false || preg_match('/value="title"[^>]*selected/', $rendered))
        ? pass('live render: "Capitalize Each Word" (title) is correctly marked selected when that is the saved setting')
        : fail('live render: the saved mode is not reflected as the selected option');

    // A real save-handler round-trip (simulating the actual POST) in its OWN
    // subprocess, then a SEPARATE subprocess to confirm the DB genuinely
    // reflects it — get_setting()'s per-process cache means checking inside
    // the same subprocess that just saved would read stale, pre-save data.
    _tdc_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_POST = ['save_general' => '1', 'company_name' => 'Test Co', 'company_type' => 'retail', 'currency' => 'TZS', 'timezone' => 'Africa/Nairobi', 'date_format' => 'Y-m-d', 'items_per_page' => '25', 'text_display_case' => 'sentence'];
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        ob_start();
        include '$root/app/constant/settings/system_settings.php';
        ob_end_clean();
        echo 'SAVED';
    ");
    $roundTrip = _tdc_run_php("require '$root/roots.php'; echo get_setting('text_display_case', 'MISSING');");
    (strpos($roundTrip, 'sentence') !== false)
        ? pass('real POST through save_general persists text_display_case=sentence, confirmed via a fresh get_setting() read in a separate process')
        : fail('save round-trip did not persist correctly: ' . $roundTrip);

    // A malicious/garbage posted value must be rejected, not saved verbatim —
    // again, save and verify in two separate subprocesses.
    _tdc_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_POST = ['save_general' => '1', 'company_name' => 'Test Co', 'company_type' => 'retail', 'currency' => 'TZS', 'timezone' => 'Africa/Nairobi', 'date_format' => 'Y-m-d', 'items_per_page' => '25', 'text_display_case' => '<script>DROP TABLE x</script>'];
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        ob_start();
        include '$root/app/constant/settings/system_settings.php';
        ob_end_clean();
        echo 'SAVED';
    ");
    $garbageAttempt = _tdc_run_php("require '$root/roots.php'; echo get_setting('text_display_case', 'MISSING');");
    (trim($garbageAttempt) === 'as_typed')
        ? pass('a garbage/malicious posted value is rejected and falls back to as_typed, never saved verbatim')
        : fail('garbage value was NOT rejected: ' . var_export($garbageAttempt, true));

    // Reset to the neutral default so this test run leaves no side effect
    // for other tests/sessions sharing this dev DB.
    _tdc_run_php("require '$root/roots.php'; save_setting('text_display_case', 'as_typed'); echo 'RESET';");
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Translation coverage (Swahili)');
require_once "$root/core/i18n.php";
loadLanguage('sw');
foreach (['Text Display Case', 'As Typed (Default)', 'Sentence case', 'lowercase', 'UPPERCASE', 'Capitalize Each Word', 'Toggle Case', 'Preview:'] as $key) {
    $sw = t($key);
    ($sw !== $key && $sw !== '') ? pass("'$key' has a real Swahili translation ('$sw')") : fail("'$key' falls back to raw English under sw locale");
}
loadLanguage('en');
