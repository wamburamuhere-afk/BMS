<?php
/**
 * Mobile API upgrade — Phase 9: "Ask Smart POS" in the Flutter app — CLI regression
 *   php tests/test_mobile_ai_cli.php
 *
 *   - api/mobile/ai_ask.php runs the web endpoint api/ai/ask.php for a Bearer
 *     caller (same module/permission gate, key, cap, rate limit, language);
 *   - me.php → ai: allowed, ready, limit_reached, name — the app shows the chat
 *     only when ready, exactly when the web shows its floating chat.
 *
 * The provider is never called: the key used here points at a local address,
 * which the AI service's own SSRF guard refuses — so no request leaves this
 * machine. Real HTTP with Bearer tokens on a throwaway company.
 */

$root = dirname(__DIR__);
require_once "$root/tests/helpers/mobile_http.inc.php";
require_once "$root/core/tenant_admin.php";
require_once "$root/core/crypto.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function check(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    mhStopAll();   // before exit(1), which would skip mhStart's own cleanup
    if ($fail > 0) exit(1);
});

section('1. Static');
foreach (['api/mobile/ai_ask.php', 'api/mobile/me.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "lint $f");
}
check(str_contains(file_get_contents("$root/api/mobile/ai_ask.php"), "require __DIR__ . '/../ai/ask.php'"), 'the app endpoint runs the web endpoint');

$m = mhStart('artai');
if (!$m) { echo "  \033[33m⚠ multi-tenancy is off here — skipping live checks\033[0m\n"; exit; }
$tok = mhLogin($m);
$tp  = mhTenantPdo($m);
$set = function (string $k, string $v) use ($tp) {
    $tp->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")->execute([$k, $v]);
};
$ai  = fn(string $t) => mhCall($m, 'GET', '/api/mobile/me.php', [], $t)[1]['ai'] ?? null;
$ask = fn(string $t, string $q = 'How much did I sell today?') => mhCall($m, 'POST', '/api/mobile/ai_ask.php', ['question' => $q], $t);

section('2. Module on, no key');
$a = $ai($tok);
check(($a['allowed'] ?? null) === true && ($a['ready'] ?? null) === false && !empty($a['name']), 'me.php: allowed, not ready (no key) — app hides the chat');
[$code, $j] = $ask($tok);
check($code === 422 && str_contains((string)($j['message'] ?? ''), 'not configured'), "ask → 422 \"AI is not configured.\" (got $code)");

section('3. A key is set (local address — refused by the SSRF guard, nothing leaves)');
$set('ai_enabled', '1');
$set('ai_provider', 'openai');
$set('ai_model', 'gpt-4o-mini');
$set('ai_base_url', 'http://127.0.0.1:9/v1');
$set('ai_api_key_enc', encryptSecret('sk-test-not-real'));
$a = $ai($tok);
check(($a['ready'] ?? null) === true && ($a['limit_reached'] ?? null) === false, 'me.php: ready — the app shows the chat');
[$code, $j] = $ask($tok, '');
check($code === 422 && str_contains((string)($j['message'] ?? ''), 'type a question'), 'empty question → 422');
[$code, $j] = $ask($tok);
check($code >= 400 && $code < 600 && empty($j['success']) && ($j['message'] ?? '') !== '', "provider refused → clean JSON error, no crash ($code: " . ($j['message'] ?? '') . ')');

section('4. Monthly cap reached');
$set('ai_monthly_cost_cap', '0.01');
try {
    $tp->exec("INSERT INTO ai_usage_log (user_id, feature, provider, model, est_cost, created_at) VALUES (1, 'ask', 'openai', 'gpt-4o-mini', 5, NOW())");
    $a = $ai($tok);
    check(($a['limit_reached'] ?? null) === true, 'me.php: limit_reached = true');
} catch (Throwable $e) {
    echo "  \033[33m⚠ ai_usage_log not writable here (" . $e->getMessage() . ") — cap check skipped\033[0m\n";
}

section('5. Module switched off for the company');
$r = setTenantFeatures($m['tid'], ['ai_assistant' => false]);
$a = $ai($tok);
check(!empty($r['ok']) && ($a['allowed'] ?? null) === false && ($a['ready'] ?? null) === false, 'me.php: not allowed, not ready');
[$code] = $ask($tok);
check($code === 403, "ask → 403 (got $code)");
[$code] = mhCall($m, 'POST', '/api/mobile/ai_ask.php', ['question' => 'hi']);
check($code === 401, 'no token → 401');
