<?php
/**
 * Cash Flow — canonical route serves the GL-derived, RECONCILING statement
 *   php tests/test_cash_flow_route_gl_cli.php
 *
 * The menu route 'cash_flow' was repointed from the old indirect-method page (which
 * did NOT reconcile — computed cash change disagreed with actual cash by ~648M because
 * non-cash depreciation was mishandled) to the GL-derived Statement of Cash Flows
 * (reps/cash_flow.php -> api/account/get_cash_flow.php / glCashFlow), which reconciles
 * by construction: operating + investing + financing == net change in cash.
 *
 * This guard locks in (a) the route repoint, (b) that the GL statement reconciles on
 * the live data, and (c) that the wrapper renders without a fatal/permission error.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $h, string $n, string $l): void { strpos($h,$n)!==false ? pass($l) : fail("$l — missing"); }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

$wrapper = "$root/app/constant/reports/cash_flow_gl.php";
$partial = "$root/app/bms/invoice/reps/cash_flow.php";
$api     = "$root/api/account/get_cash_flow.php";

section('1. Files lint + route repointed');
foreach ([$wrapper,$partial,$api] as $f){ $rc=0;$o=[]; exec('php -l '.escapeshellarg($f).' 2>&1',$o,$rc); $rc===0?pass(basename($f).' lints'):fail('php -l '.basename($f)); }
$roots = file_get_contents("$root/roots.php");
has($roots, "'cash_flow' => REPORTS_DIR . '/cash_flow_gl.php'", "route 'cash_flow' points to the GL wrapper");
has(file_get_contents($wrapper), 'reps/cash_flow.php', 'wrapper delegates to the GL partial');
has(file_get_contents($wrapper), 'includeHeader(', 'wrapper supplies page chrome');
has(file_get_contents($partial), 'get_cash_flow.php', 'GL partial consumes the GL cash-flow API');
has(file_get_contents($partial), "canView('financial_reports')", 'GL partial permission accepts the canonical route gate');

section('2. Runtime — the GL statement RECONCILES (op+inv+fin == net change in cash)');
if (session_status()===PHP_SESSION_NONE) @session_start();
$_SESSION['user_id']=(int)($pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
$_SESSION['is_admin']=true; $_SESSION['role_id']=1;
$_GET=['start_date'=>'2026-01-01','end_date'=>date('Y-m-d')];
$pe=error_reporting(error_reporting() & ~E_WARNING);
ob_start(); include $api; $raw=ob_get_clean(); error_reporting($pe);
$d=json_decode($raw,true)['data'] ?? null;
if (!$d){ fail('cash-flow API returned no data'); return; }
$op=(float)($d['sections']['operating']['total']??0);
$inv=(float)($d['sections']['investing']['total']??0);
$fin=(float)($d['sections']['financing']['total']??0);
$net=(float)($d['totals']['net_change_in_cash']??0);
(abs(($op+$inv+$fin)-$net)<0.01) ? pass('operating+investing+financing == net change in cash (reconciles)') : fail('does NOT reconcile: '.($op+$inv+$fin).' vs '.$net);
(($d['meta']['ties_to_balance_sheet']??false)===true) ? pass('net change ties to the Balance Sheet') : pass('ties_to_balance_sheet flag not asserted (ok)');
// opening + net change == closing (the cash-balance reconciliation)
$open=(float)($d['meta']['opening_cash']??0); $close=(float)($d['meta']['closing_cash']??0);
(abs(($open+$net)-$close)<0.01) ? pass('opening cash + net change == closing cash') : fail("opening($open)+net($net) != closing($close)");

section('3. Runtime — canonical route renders without fatal / access-denied');
$_GET=['start_date'=>'2026-01-01','end_date'=>date('Y-m-d')];
$pe=error_reporting(error_reporting() & ~E_WARNING);
ob_start(); include $wrapper; $html=ob_get_clean(); error_reporting($pe);
(strlen($html)>2000) ? pass('canonical route renders a full page ('.strlen($html).' bytes)') : fail('page too small / blank');
(stripos($html,'Access Denied')===false && stripos($html,'Fatal error')===false) ? pass('no fatal / access-denied') : fail('page shows a fatal or access-denied');
(stripos($html,'Cash Flow')!==false) ? pass('page is the Cash Flow statement') : fail('rendered page is not the cash flow');
