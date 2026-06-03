<?php
/**
 * Consolidated Expenses report — CLI test.
 *   php tests/test_consolidated_expenses_cli.php
 *
 * Seeds outflows of several types, renders the report page, and asserts they all
 * appear with their source account + correct total. Cleans up.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;
$pass=0; $fail=0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }

$ids = [];
try {
    ok(is_file("$root/app/constant/reports/consolidated_expenses.php"), "report page exists");
    $lint = shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg("$root/app/constant/reports/consolidated_expenses.php").' 2>&1');
    ok(strpos($lint,'No syntax errors')!==false, "report page lints clean");

    $ap = defaultPayableAccountId($pdo);
    $bank = (int)cashBankAccounts($pdo)[0]['account_id'];
    // Use an isolated date so no other ledger rows interfere with the total.
    $d = '2099-01-15';
    $ids[] = postOutflow($pdo,'supplier_payment',$bank,$ap,500000,$d,'__CE1','Supplier pay');
    $ids[] = postOutflow($pdo,'sc_payment',$bank,$ap,300000,$d,'__CE2','SC pay');
    $ids[] = postOutflow($pdo,'received_invoice_payment',$bank,$ap,286000,$d,'__CE3','Inv pay');

    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id']=4; $_SESSION['is_admin']=true; $_SESSION['role_id']=1;
    $_SERVER['REQUEST_METHOD']='GET'; $_GET=['from'=>$d,'to'=>$d];
    ob_start(); include "$root/app/constant/reports/consolidated_expenses.php"; $h=ob_get_clean();

    ok(strpos($h,'Consolidated Expenses')!==false, "renders the report");
    ok(strpos($h,'>Supplier Payment<')!==false && strpos($h,'>Sub-Contractor Payment<')!==false, "shows multiple outflow types");
    ok(strpos($h,'By Type')!==false && strpos($h,'By Source Account')!==false, "type + source breakdown panels present");
    ok(strpos($h,'1,086,000')!==false, "total reflects the seeded outflows (1,086,000)");
    ok(stripos($h,'Fatal error')===false && stripos($h,'Parse error')===false, "no PHP errors");
} catch (Throwable $e) {
    ok(false, "exception: ".$e->getMessage());
}
foreach ($ids as $t) reverseOutflow($pdo, $t);

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail===0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
