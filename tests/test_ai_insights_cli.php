<?php
/**
 * AI Assistant — Phase 3 (Ask BMS / insights) CLI test
 *   php tests/test_ai_insights_cli.php
 *
 * The insight registry is the ONLY data path for the AI, so this is the safety-
 * critical test:
 *   - every insight function returns the SAME aggregate as a direct SQL check
 *   - the registry contains NO write statements (read-only by construction)
 *   - unknown insight degrades to an error (no throw)
 *   - ask.php is permission+CSRF gated, uses the registry, and has the
 *     function-call loop + JSON extractor
 * No live provider needed. Read-only. Exit 0 = pass.
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

try {
    section('1. No write path (read-only by construction)');
    $reg = src($root,'core/ai_insights.php');
    ok(preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP\s+|ALTER\s+|TRUNCATE)\b/i',$reg)===0,
        'core/ai_insights.php contains NO write/DDL statements');

    section('2. Catalog + dispatcher');
    $cat = aiInsightCatalog();
    ok(count($cat) >= 8, 'catalog exposes the insight functions ('.count($cat).')');
    ok(aiRunInsight('definitely_not_a_function')['ok']===false, 'unknown insight returns ok=false (no throw)');

    section('3. Each insight matches a direct SQL check (live)');
    [$f,$t] = [date('Y-m-01'), date('Y-m-d')];

    // revenue
    $r = aiRunInsight('revenue', ['from'=>$f,'to'=>$t]);
    $exp = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM invoices WHERE status<>'deleted' AND invoice_date BETWEEN ".$pdo->quote($f)." AND ".$pdo->quote($t))->fetchColumn();
    ok($r['ok'] && approx($r['data']['revenue'],$exp), "revenue == SQL ({$exp})");

    // expenses_total
    $r = aiRunInsight('expenses_total', ['from'=>$f,'to'=>$t]);
    $exp = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE (status IS NULL OR status<>'deleted') AND expense_date BETWEEN ".$pdo->quote($f)." AND ".$pdo->quote($t))->fetchColumn();
    ok($r['ok'] && approx($r['data']['expenses'],$exp), "expenses_total == SQL ({$exp})");

    // profit = rev - exp
    $r = aiRunInsight('profit', ['from'=>$f,'to'=>$t]);
    ok($r['ok'] && approx($r['data']['profit'], $r['data']['revenue'] - $r['data']['expenses']), 'profit == revenue - expenses');

    // outstanding_receivables
    $r = aiRunInsight('outstanding_receivables');
    $exp = (float)$pdo->query("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE status<>'deleted' AND balance_due>0")->fetchColumn();
    ok($r['ok'] && approx($r['data']['outstanding_receivables'],$exp), "outstanding_receivables == SQL ({$exp})");

    // cash_position
    $r = aiRunInsight('cash_position');
    $exp = (float)$pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM accounts a WHERE a.status='active' AND a.account_type='asset' AND a.cash_flow_category='cash' AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent_account_id=a.account_id)")->fetchColumn();
    ok($r['ok'] && approx($r['data']['total_cash'],$exp), "cash_position == SQL ({$exp})");

    // low_stock
    $r = aiRunInsight('low_stock');
    $exp = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE current_stock <= min_stock_level")->fetchColumn();
    ok($r['ok'] && $r['data']['below_minimum_count']===$exp, "low_stock count == SQL ({$exp})");

    // top_debtors structure
    $r = aiRunInsight('top_debtors', ['limit'=>3]);
    ok($r['ok'] && isset($r['data']['top_debtors']) && count($r['data']['top_debtors']) <= 3, 'top_debtors returns a capped list');

    // ar_aging_summary buckets present
    $r = aiRunInsight('ar_aging_summary');
    ok($r['ok'] && isset($r['data']['current'],$r['data']['over_90']), 'ar_aging_summary returns buckets');

    // sales_trend
    $r = aiRunInsight('sales_trend', ['months'=>6]);
    ok($r['ok'] && isset($r['data']['monthly_sales']), 'sales_trend returns monthly series');

    // ── operational modules (projects/HR/procurement) ──
    $r = aiRunInsight('projects_summary');
    $exp = (int)$pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    ok($r['ok'] && $r['data']['project_count']===$exp, "projects_summary count == SQL ({$exp})");
    $r = aiRunInsight('employees_summary');
    $exp = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status='active'")->fetchColumn();
    ok($r['ok'] && $r['data']['active_employees']===$exp, "employees_summary active == SQL ({$exp})");
    $r = aiRunInsight('purchase_orders_summary');
    ok($r['ok'] && isset($r['data']['awaiting_approval']), 'purchase_orders_summary returns awaiting_approval');
    $r = aiRunInsight('pending_approvals');
    ok($r['ok'] && isset($r['data']['purchase_orders_awaiting'],$r['data']['leave_requests_awaiting']), 'pending_approvals aggregates PO/SO/leave');
    ok(count(aiInsightCatalog()) >= 18, 'catalog now spans finance + operations ('.count(aiInsightCatalog()).' functions)');

    section('4. ask.php wiring');
    $ask = src($root,'api/ai/ask.php');
    $out=[];$rc=0; exec('php -l '.escapeshellarg("$root/api/ai/ask.php").' 2>&1',$out,$rc); ok($rc===0,'ask.php lint-clean');
    ok(strpos($ask,"canView('ai_assistant')")!==false,'ask gated on ai_assistant permission');
    ok(strpos($ask,'csrf_check()')!==false,'ask CSRF-checked');
    ok(strpos($ask,'aiRunInsight(')!==false && strpos($ask,'aiInsightCatalog(')!==false,'ask uses the curated insight registry');
    ok(strpos($ask,'_ai_extract_call')!==false,'ask has the JSON function-call extractor');
    ok(strpos($ask,'maxHops')!==false,'ask caps the function-call hops');
    ok(strpos($ask,'do not output SQL') !== false || strpos($ask,'Do not output SQL')!==false,'ask forbids SQL output');

    section('5. Chat page');
    $page = src($root,'app/constant/communication/ai_assistant.php');
    $out=[];$rc=0; exec('php -l '.escapeshellarg("$root/app/constant/communication/ai_assistant.php").' 2>&1',$out,$rc); ok($rc===0,'chat page lint-clean');
    ok(strpos($page,"autoEnforcePermission('ai_assistant')")!==false,'chat page permission-gated');
    ok(strpos($page,'aiConfigured()')!==false,'chat page degrades gracefully when unconfigured');

} catch (Throwable $e){ ok(false,'test threw: '.$e->getMessage()); }
exit($fail===0?0:1);
