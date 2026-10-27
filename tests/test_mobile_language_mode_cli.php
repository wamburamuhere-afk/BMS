<?php
/**
 * Mobile API upgrade — Phase 6: the app follows the company's language mode — CLI regression
 *   php tests/test_mobile_language_mode_cli.php
 *
 * An admin can set one language for everyone (Settings → General → Language
 * Mode = organization). The web already followed it; the app showed and saved
 * each user's own choice. Now:
 *   - login.php and me.php return the EFFECTIVE language + language_mode +
 *     language_locked;
 *   - profile/language refuses (409) in organization mode, like api/set_language.php;
 *   - server messages already followed it (mobileBearerAuth) — checked to agree.
 *
 * Real HTTP with Bearer tokens on a throwaway company.
 */

$root = dirname(__DIR__);
require_once "$root/tests/helpers/mobile_http.inc.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function check(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

section('1. Static');
foreach (['api/mobile/login.php', 'api/mobile/me.php', 'api/mobile/profile/language.php', 'lang/sw.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "lint $f");
}

$m = mhStart('artlang');
if (!$m) { echo "  \033[33m⚠ multi-tenancy is off here — skipping live checks\033[0m\n"; exit; }
$tp = mhTenantPdo($m);
$set = function (string $k, string $v) use ($tp) {
    $tp->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")->execute([$k, $v]);
};

section('2. Per-user mode (default)');
$tok = mhLogin($m);
[$code, $j] = mhCall($m, 'POST', '/api/mobile/profile/language.php', ['language' => 'sw'], $tok);
check($code === 200 && ($j['language'] ?? '') === 'sw', 'the user switches to Swahili');
[, $me] = mhCall($m, 'GET', '/api/mobile/me.php', [], $tok);
check(($me['user']['language'] ?? '') === 'sw' && ($me['user']['language_mode'] ?? '') === 'user' && ($me['user']['language_locked'] ?? null) === false,
    'me.php: sw, mode user, not locked');

section('3. Organization mode: English for everyone');
$set('language_mode', 'organization');
$set('org_language', 'en');
[$code, $login] = mhCall($m, 'POST', '/api/mobile/login.php', ['username' => 'mhowner', 'password' => 'Password1', 'device_name' => 'T']);
check(($login['user']['language'] ?? '') === 'en' && ($login['user']['language_locked'] ?? null) === true, 'login: en (the company\'s), locked — not the user\'s sw');
$tok2 = $login['token'] ?? '';
[, $me] = mhCall($m, 'GET', '/api/mobile/me.php', [], $tok2);
check(($me['user']['language'] ?? '') === 'en' && ($me['user']['language_mode'] ?? '') === 'organization', 'me.php: en, mode organization');
[$code, $j] = mhCall($m, 'POST', '/api/mobile/profile/language.php', ['language' => 'sw'], $tok2);
check($code === 409 && ($j['language_locked'] ?? null) === true && ($j['language'] ?? '') === 'en', "changing it is refused: 409 (got $code)");
$saved = $tp->query("SELECT setting_value FROM system_settings WHERE setting_key LIKE 'user_language_%' LIMIT 1")->fetchColumn();
check($saved === 'sw', 'the user\'s own choice is kept for when the mode goes back');
[$code, $j] = mhCall($m, 'POST', '/api/mobile/stock/adjust.php', [], $tok2);
check(is_array($j) && !preg_match('/[ÀÄ]|haku|tafadhali|imeshindwa/i', (string)($j['message'] ?? '')), 'server messages in English too: "' . ($j['message'] ?? '') . '"');

section('4. Organization mode: Swahili for everyone');
$set('org_language', 'sw');
[$code, $j] = mhCall($m, 'POST', '/api/mobile/profile/language.php', ['language' => 'en'], $tok2);
check($code === 409 && str_contains((string)($j['message'] ?? ''), 'Lugha imewekwa'), 'the refusal is in Swahili: "' . ($j['message'] ?? '') . '"');
[, $me] = mhCall($m, 'GET', '/api/mobile/me.php', [], $tok2);
check(($me['user']['language'] ?? '') === 'sw', 'me.php: sw');

section('5. Back to per-user');
$set('language_mode', 'user');
[$code, $j] = mhCall($m, 'POST', '/api/mobile/profile/language.php', ['language' => 'en'], $tok2);
[, $me] = mhCall($m, 'GET', '/api/mobile/me.php', [], $tok2);
check($code === 200 && ($me['user']['language'] ?? '') === 'en' && ($me['user']['language_locked'] ?? null) === false, 'switching works again; unlocked');
