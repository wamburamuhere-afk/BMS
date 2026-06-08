<?php
/**
 * AI Assistant — Phase 2 (Generate with AI) CLI test
 *   php tests/test_ai_generate_cli.php
 *
 * Verifies the generate endpoint + reusable widget without a live provider:
 *   - endpoint lints, is permission-gated, CSRF-checked, refuses when unconfigured
 *   - prompt builder picks the right "what to write" per field_type
 *   - the ✨ widget renders ONLY when AI is available (disabled → empty string)
 *   - the expenses form is wired with the widget + a target id
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/ai_service.php";
global $pdo;

$pass=0;$fail=0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src($root,$rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

section('1. Endpoint lint + gating');
foreach (['api/ai/generate.php','app/includes/ai_generate.php'] as $f){
    $out=[];$rc=0; exec('php -l '.escapeshellarg("$root/$f").' 2>&1',$out,$rc);
    ok($rc===0,"$f lint-clean");
}
$gen=src($root,'api/ai/generate.php');
ok(strpos($gen,"canView('ai_assistant')")!==false,'generate gated on ai_assistant permission');
ok(strpos($gen,'csrf_check()')!==false,'generate CSRF-checked');
ok(strpos($gen,'aiConfigured()')!==false,'generate refuses when AI unconfigured');
ok(strpos($gen,'do not invent specific figures')!==false,'prompt forbids fabricating figures/dates');
ok(strpos($gen,'aiComplete(')!==false,'generate calls aiComplete');

section('2. Field-type prompt mapping');
foreach (['invoice_description','quotation_notes','expense_description','sms','product_description'] as $ft){
    ok(strpos($gen,"'$ft'")!==false,"field_type '$ft' has a tailored prompt label");
}

section('3. Reusable widget visibility');
require_once "$root/app/includes/ai_generate.php";
ok(function_exists('aiButton') && function_exists('aiWidgetAvailable'),'aiButton/aiWidgetAvailable defined');
// AI is OFF by default → widget must render nothing
ok(aiWidgetAvailable()===false,'widget unavailable while AI disabled');
ok(aiButton('x','expense_description')==='','aiButton() returns empty string when AI disabled (host field unaffected)');
$partial=src($root,'app/includes/ai_generate.php');
ok(strpos($partial,'Swal.fire')!==false,'widget uses SweetAlert2');
ok(strpos($partial,'CSRF_TOKEN')!==false,'widget sends CSRF token');
ok(strpos($partial,'btn-outline-primary')!==false && strpos($partial,'bi-stars')!==false,'widget styled per ui-constants (blue, bi-*)');

section('4. Expenses form wired');
$exp=src($root,'app/constant/accounts/expenses.php');
ok(strpos($exp,'ai_generate.php')!==false,'expenses includes the AI widget partial');
ok(strpos($exp,"aiButton('expense_description_ai'")!==false,'expenses renders the ✨ button');
ok(strpos($exp,'id="expense_description_ai"')!==false,'expenses description has the target id');
// The textarea name/required is preserved (no regression to the form contract)
ok(preg_match('/id="expense_description_ai" name="description" rows="3" required/',$exp)===1,'description field keeps name="description" + required');

exit($fail===0?0:1);
