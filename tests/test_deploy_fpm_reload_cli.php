<?php
/**
 * Deploy reloads PHP-FPM after Apache (2026-11-08 server upgrade)
 *   php tests/test_deploy_fpm_reload_cli.php
 *
 * Since the BMS vhosts run PHP 8.5 through php8.5-fpm, an Apache reload alone
 * no longer clears BMS's opcode cache. deploy.yml must reload every active
 * php*-fpm service right after each Apache reload, and that step may never
 * abort the deploy.
 *
 * A: static — deploy.yml wiring.
 * B: runtime — the real reload_fpm line from deploy.yml is run in bash with
 *    systemctl/sudo stubbed: reloads each active FPM, warns (exit 0) on a
 *    failed reload, does nothing when no FPM runs. Skipped if no bash.
 * Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

section('A. deploy.yml wiring');
$yml = file_get_contents("$root/.github/workflows/deploy.yml");

preg_match('/^[ \t]*(reload_fpm\(\) \{.*\})[ \t]*\r?$/m', $yml, $def);
$fnLine = $def[1] ?? '';
ok($fnLine !== '', 'reload_fpm() is defined in the deploy script');
ok(str_contains($fnLine, "--state=active") && str_contains($fnLine, "'php*-fpm.service'"), 'it targets only ACTIVE php*-fpm services (any PHP version)');
ok(str_contains($fnLine, 'sudo systemctl reload "$s"'), 'it uses reload (graceful), not restart');
ok((bool)preg_match('/sudo systemctl reload "\$s" \|\| echo/', $fnLine), 'a failed reload only warns — it can never abort the deploy');
ok(strpos($yml, 'reload_fpm() {') < strpos($yml, 'for h in $HOSTS; do'), 'defined before the host loop uses it');

preg_match('/for h in \$HOSTS; do (.*); done[ \t]*\r?$/m', $yml, $loop);
$body = $loop[1] ?? '';
ok(str_contains($body, 'sudo systemctl reload apache2 || echo "⚠️  Apache reload failed on $h"; reload_fpm;'), 'per host: FPM reloads right after the early Apache reload');
ok(strpos($body, 'reload_fpm') < strpos($body, 'tenant_migration_runner.php'), 'per host: before the slow tenant migrations');
ok((bool)preg_match('/sudo systemctl reload apache2 \|\| echo "[^"]*stale opcache[^"]*"\r?\n[ \t]*reload_fpm\r?\n/', $yml), 'final: FPM reloads right after the final Apache reload');
ok(str_contains($yml, 'php migrations/runner.php; sudo systemctl reload apache2'), 'existing Apache reload kept unchanged');
ok(str_contains($yml, 'script_stop: true'), 'script_stop: true kept');

section('B. reload_fpm runs correctly (stubbed systemctl/sudo)');
$bash = getenv('BMS_TEST_BASH') ?: null;
if (!$bash) {
    foreach (['bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $cand) {
        $probe = @shell_exec(escapeshellarg($cand) . ' -c "echo ok" 2>&1');
        if (is_string($probe) && trim($probe) === 'ok') { $bash = $cand; break; }
    }
}
if (!$bash || $fnLine === '') { echo "  (no bash found — section B skipped; set BMS_TEST_BASH)\n"; exit; }

/** Run reload_fpm with stubbed systemctl/sudo; returns [stdout, exitCode]. */
function runReload(string $bash, string $fnLine, string $units, int $reloadExit): array
{
    $script = "set -e\n"
        . "systemctl() { if [ \"\$1\" = list-units ]; then printf '%b' " . escapeshellarg($units) . "; else echo \"CALL \$*\"; return $reloadExit; fi; }\n"
        . "sudo() { \"\$@\"; }\n"
        . $fnLine . "\n"
        . "reload_fpm\n"
        . "echo AFTER\n";
    $p = proc_open([$bash, '-s'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $script); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [str_replace("\r", '', $out), proc_close($p)];
}

$two = 'php8.5-fpm.service loaded active running The PHP 8.5 FastCGI Process Manager\nphp8.3-fpm.service loaded active running The PHP 8.3 FastCGI Process Manager\n';
[$out, $code] = runReload($bash, $fnLine, $two, 0);
ok(str_contains($out, 'CALL reload php8.5-fpm.service') && str_contains($out, 'CALL reload php8.3-fpm.service'), 'reloads every active FPM service');
ok($code === 0 && str_contains($out, 'AFTER') && !str_contains($out, '⚠️'), 'success: no warning, script continues');

[$out, $code] = runReload($bash, $fnLine, $two, 1);
ok(str_contains($out, 'php8.5-fpm.service reload failed') && str_contains($out, 'sudo systemctl reload php8.5-fpm.service'), 'failed reload prints a warning with the fix command');
ok($code === 0 && str_contains($out, 'AFTER') && str_contains($out, 'CALL reload php8.3-fpm.service'), 'failed reload does not abort (set -e) and the next service is still reloaded');

[$out, $code] = runReload($bash, $fnLine, '', 0);
ok($code === 0 && !str_contains($out, 'CALL') && str_contains($out, 'AFTER'), 'no FPM running (e.g. demo-only host): does nothing, no error');
