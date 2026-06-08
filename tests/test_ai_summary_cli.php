<?php
/**
 * AI Assistant — Phase 4 (monthly summary) CLI test
 *   php tests/test_ai_summary_cli.php
 *
 * Verifies the monthly summary endpoint + dashboard surfacing without a live
 * provider: endpoint lints + gated + gathers KPIs from the curated registry;
 * the KPI figures it would feed the model match direct SQL; the dashboard card
 * is guarded by aiConfigured(). Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/ai_insights.php";
global $pdo;

$pass=0;$fail=0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a,$b){ return abs((float)$a-(float)$b) < 0.01; }
function src($root,$rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

section('1. Endpoint lint + gating + KPI gathering');
$out=[];$rc=0; exec('php -l '.escapeshellarg("$root/api/ai/monthly_summary.php").' 2>&1',$out,$rc); ok($rc===0,'monthly_summary.php lint-clean');
$s = src($root,'api/ai/monthly_summary.php');
ok(strpos($s,"canView('ai_assistant')")!==false,'gated on ai_assistant permission');
ok(strpos($s,'csrf_check()')!==false,'CSRF-checked');
ok(strpos($s,'aiConfigured()')!==false,'refuses when AI unconfigured');
foreach (['revenue','expenses_total','profit','outstanding_receivables','cash_position','top_customers','low_stock'] as $fn){
    ok(strpos($s,"aiRunInsight('$fn'")!==false,"gathers KPI via aiRunInsight('$fn')");
}
ok(strpos($s,'Do NOT invent figures')!==false,'instructs the model not to invent figures');

section('2. The KPI figures match direct SQL (live)');
$f=date('Y-m-01'); $t=date('Y-m-d');
$rev = aiRunInsight('revenue',['period'=>'this_month'])['data']['revenue'] ?? null;
$expSql=(float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM invoices WHERE status<>'deleted' AND invoice_date BETWEEN ".$pdo->quote($f)." AND ".$pdo->quote($t))->fetchColumn();
ok($rev!==null && approx($rev,$expSql),"this-month revenue KPI == SQL ({$expSql})");
$exp = aiRunInsight('expenses_total',['period'=>'this_month'])['data']['expenses'] ?? null;
$expSql2=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE (status IS NULL OR status<>'deleted') AND expense_date BETWEEN ".$pdo->quote($f)." AND ".$pdo->quote($t))->fetchColumn();
ok($exp!==null && approx($exp,$expSql2),"this-month expenses KPI == SQL ({$expSql2})");

section('3. Dashboard surfacing removed (per request)');
// The "This month, in words" AI summary card was permanently removed from the main
// dashboard. The endpoint stays (covered above) for the AI Assistant, but the
// dashboard must no longer render the card or its button.
$dash = src($root,'app/dashboard.php');
ok(strpos($dash,'This month, in words')===false,'dashboard no longer shows the AI summary card');
ok(strpos($dash,'aiSummaryBtn')===false,'dashboard no longer has the Generate button');
ok(strpos($dash,'api/ai/monthly_summary.php')===false,'dashboard no longer calls the summary endpoint');

exit($fail===0?0:1);
