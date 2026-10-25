<?php
/**
 * Organization-wide language mode — System Settings > General
 *   php tests/test_org_language_mode_cli.php
 *
 * Proves: default is per-user (orgLanguage() null); organization mode forces en/sw;
 * junk values fall back safely; the per-user controls are gated; settings are
 * rolled back afterwards. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src($p){ return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; exit($fail ? 1 : 0); });

section('A. orgLanguage() behaviour (fresh process per scenario; originals restored)');
// get_setting() caches for the whole request, so each scenario is read by a child process.
$probe = function () use ($root) {
    $code = 'require ' . var_export("$root/roots.php", true) . "; \$l = orgLanguage(); echo \$l === null ? 'none' : \$l;";
    return trim((string)shell_exec('php -r ' . escapeshellarg($code) . ' 2>NUL'));
};
$orig = [];
foreach (['language_mode', 'org_language'] as $k) {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    $orig[$k] = $v === false ? null : $v;
}
try {
    $pdo->exec("DELETE FROM system_settings WHERE setting_key IN ('language_mode','org_language')");
    ok($probe() === 'none', 'default (no setting) is per-user');
    save_setting('language_mode', 'user'); save_setting('org_language', 'sw');
    ok($probe() === 'none', 'per-user mode ignores org_language');
    save_setting('language_mode', 'organization');
    ok($probe() === 'sw', 'organization + sw forces sw');
    save_setting('org_language', 'en');
    ok($probe() === 'en', 'organization + en forces en');
    save_setting('org_language', 'fr');
    ok($probe() === 'en', 'unsupported org language falls back to en');
} finally {
    foreach ($orig as $k => $v) {
        if ($v === null) $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        else save_setting($k, $v);
    }
}
section('B. Gates are wired in');
$hdr = src("$root/header.php");
ok(substr_count($hdr, '$__bms_org_lang === null') >= 2, 'header: both globe toggles hidden in org mode');
ok(strpos($hdr, '$_SESSION[\'user_lang\'] = $__bms_org_lang') !== false, 'header: session language forced to org language');
ok(strpos(src("$root/api/set_language.php"), 'orgLanguage() !== null') !== false, 'set_language API rejects in org mode');
$my = src("$root/app/constant/settings/my_settings.php");
ok(strpos($my, 'if (orgLanguage() === null): ?>') !== false, 'My Settings hides the Language field in org mode');
ok(strpos($my, "if (orgLanguage() === null) {\n                \$_SESSION['user_lang']") !== false
   || preg_match('/if \(orgLanguage\(\) === null\) \{\s+\$_SESSION\[\'user_lang\'\]/', $my) === 1, 'My Settings save does not override session in org mode');
ok(strpos(src("$root/actions/login.php"), 'orgLanguage()') !== false, 'login applies org language');
ok(strpos(src("$root/core/mobile_auth.php"), 'orgLanguage') !== false, 'mobile login applies org language');
$ss = src("$root/app/constant/settings/system_settings.php");
ok(strpos($ss, "name=\"language_mode\"") !== false && strpos($ss, "name=\"org_language\"") !== false, 'System Settings has both selects');
ok(strpos($ss, "'language_mode' =>") !== false && strpos($ss, "'org_language'  =>") !== false, 'System Settings saves both (whitelisted)');

section('C. Swahili strings');
$sw = include "$root/lang/sw.php";
foreach (['Language mode','Per user','Per organization','Organization language'] as $k) ok(!empty($sw[$k]), "sw: $k");
