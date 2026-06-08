<?php
/**
 * POS Dashboard — Phase 4 guard
 *   php tests/test_pos_dashboard_cli.php
 *
 *   A. STATIC — API + page exist, lint clean, permission-gated, view-logged.
 *   B. LIVE — invoking get_dashboard.php in-process, each tile reconciles to a
 *      direct SQL aggregate (today net, month net, low-stock count, trend length,
 *      recent cap).
 *
 * Read-only. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a,$b){ return abs((float)$a-(float)$b) < 0.01; }
function src($p){ return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    section('A. Contract');
    $api  = "$root/api/pos/get_dashboard.php";
    $page = "$root/app/bms/pos/pos_dashboard.php";
    foreach (['get_dashboard.php'=>$api, 'pos_dashboard.php'=>$page] as $n=>$f) {
        $o=[];$rc=0; exec('php -l '.escapeshellarg($f).' 2>&1',$o,$rc); ok($rc===0, "$n lint-clean");
    }
    $a = src($api); $p = src($page);
    ok(strpos($a, "canView('pos')") !== false, 'API gated on canView(pos)');
    ok(strpos($a, "scopeFilterSqlNullable('project', 'ps')") !== false, 'API project-scoped');
    ok(strpos($p, "autoEnforcePermission('pos')") !== false, 'page permission-gated');
    ok(strpos($p, 'Viewed POS Dashboard') !== false, 'page writes a view-activity log (security baseline)');
    ok(strpos($p, 'cdn.jsdelivr.net/npm/chart.js') !== false && strpos($p, 'new Chart(') !== false, 'page renders a Chart.js trend chart');

    section('B. Live tile reconciliation (in-process)');
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch()) {
        ok(true, 'pos_sales absent — live reconciliation skipped');
        exit($fail === 0 ? 0 : 1);
    }

    $uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
    $_SESSION['user_id'] = $uid; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true;
    $_GET = [];

    ob_start();
    include $api;
    $json = ob_get_clean();
    $d = json_decode($json, true);
    ok($d && !empty($d['success']), 'dashboard API returns successfully in-process');
    if ($d && !empty($d['success'])) {
        $data = $d['data'];
        $today = date('Y-m-d'); $mfrom = date('Y-m-01');
        $rec = "ps.sale_status IN ('completed','partially_refunded','refunded') AND ps.is_return_sale=0 AND ps.invoice_id IS NULL";
        $ret = "ps.is_return_sale=1 AND ps.sale_status NOT IN ('voided','cancelled') AND ps.invoice_id IS NULL";
        $netExpr = "COALESCE(SUM(CASE WHEN $rec THEN ps.grand_total-ps.tax_amount WHEN $ret THEN -(ps.grand_total-ps.tax_amount) ELSE 0 END),0)";

        $todayNet = (float)$pdo->query("SELECT $netExpr FROM pos_sales ps WHERE DATE(ps.sale_date)='$today'")->fetchColumn();
        $monthNet = (float)$pdo->query("SELECT $netExpr FROM pos_sales ps WHERE DATE(ps.sale_date) BETWEEN '$mfrom' AND '$today'")->fetchColumn();
        $lowCnt   = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE COALESCE(is_service,0)=0 AND current_stock <= min_stock_level")->fetchColumn();

        ok(approx($data['today']['net'], $todayNet), sprintf('today net == SQL (%.2f)', $todayNet));
        ok(approx($data['month']['net'], $monthNet), sprintf('month net == SQL (%.2f)', $monthNet));
        ok($data['low_stock_count'] === $lowCnt, "low-stock count == SQL ($lowCnt)");
        ok(is_array($data['trend']) && count($data['trend']) === 14, 'trend returns 14 days');
        ok(isset($data['today']['aov']) && ($data['today']['count'] == 0 || approx($data['today']['aov'], round($data['today']['net'] / $data['today']['count'], 2))), 'AOV == net / count');
        ok(is_array($data['recent']) && count($data['recent']) <= 8, 'recent sales capped at 8');
        ok(is_array($data['top_products']) && count($data['top_products']) <= 5, 'top products capped at 5');
    }

} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage());
}
exit($fail === 0 ? 0 : 1);
