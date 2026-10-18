<?php
/**
 * Plan line columns migration is fast (2026-10-18 deploy-timeout fix)
 *   php tests/test_plan_line_columns_fast_cli.php
 *
 * A: static — one ALTER per table, INSTANT first; deploy.yml has the timeout + early reload.
 * B: on the local server — copies quotation_items / sales_order_items / invoice_items
 *    (without the plan columns) into a throwaway database, runs sptEnsurePlanLineColumns()
 *    twice, and checks all columns land, in one ALTER per table, and a re-run is a no-op.
 *    The throwaway database is dropped. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
if (is_file("$root/includes/config.php")) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
require_once "$root/core/service_price_terms.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

section('A. static');
$src = file_get_contents("$root/core/service_price_terms.php");
$fn  = substr($src, strpos($src, 'function sptEnsurePlanLineColumns'), 2500);
ok(strpos($fn, "implode(', ', \$missing)") !== false, 'missing columns are added in one ALTER per table');
ok(strpos($fn, 'ALGORITHM=INSTANT') !== false, 'tries ALGORITHM=INSTANT first');
$yml = file_get_contents("$root/.github/workflows/deploy.yml");
ok(preg_match('/^\s+command_timeout:\s*30m\s*$/m', $yml) === 1, 'deploy.yml raises the SSH command_timeout to 30m');
ok(strpos($yml, 'workflow_dispatch:') !== false, 'deploy can be re-run from the Actions tab');
ok(strpos($yml, 'php migrations/runner.php; sudo systemctl reload apache2') !== false, 'Apache reloads per host before tenant migrations');
ok(strpos($yml, 'script_stop: true') !== false, 'script_stop: true kept');

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo "\n(no local database — section B skipped)\n"; exit; }

section('B. real run on a throwaway database');
$src = $pdo->query('SELECT DATABASE()')->fetchColumn();
$tmp = 'spt_fastcol_test_' . getmypid();
$pdo->exec("CREATE DATABASE `$tmp`");
$GLOBALS['TEST_CLEANUPS'][] = function () use ($pdo, $tmp, $src) { $pdo->exec("DROP DATABASE IF EXISTS `$tmp`"); $pdo->exec("USE `$src`"); };
$planCols = ['plan_key', 'plan_name', 'plan_price_id', 'plan_addon_id', 'addon_quota', 'addon_units'];
foreach (array_keys(SPT_LINE_TABLES) as $t) {
    $pdo->exec("CREATE TABLE `$tmp`.`$t` LIKE `$src`.`$t`");
    foreach ($planCols as $c) {
        if ($pdo->query("SHOW COLUMNS FROM `$tmp`.`$t` LIKE " . $pdo->quote($c))->fetch()) $pdo->exec("ALTER TABLE `$tmp`.`$t` DROP COLUMN `$c`");
    }
}
$pdo->exec("USE `$tmp`");
ok(!sptPlanLineColumnsReady($pdo, 'invoice_items'), 'starts without the plan columns');

ob_start(); $t0 = microtime(true); sptEnsurePlanLineColumns($pdo); $secs = microtime(true) - $t0; $out = ob_get_clean();
$alters = substr_count($out, '  + ');
ok($alters === 3, "one ALTER per table (got $alters)");
foreach (array_keys(SPT_LINE_TABLES) as $t) {
    $have = 0;
    foreach ($planCols as $c) if ($pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch()) $have++;
    ok($have === 6, "$t has all 6 plan columns");
}
ok($secs < 5, sprintf('finished in %.2fs', $secs));

ob_start(); sptEnsurePlanLineColumns($pdo); $out2 = ob_get_clean();
ok($out2 === '', 're-run is a no-op');
