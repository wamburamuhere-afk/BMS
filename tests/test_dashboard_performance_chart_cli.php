<?php
/**
 * Performance Overview chart — "Daily" period addition
 *   php tests/test_dashboard_performance_chart_cli.php
 *
 * Request: "add also filtering of daily... make sure is working." The chart's
 * period dropdown only offered Weekly/Monthly/Quarterly/Yearly; api/get_performance_data.php
 * had no 'daily' branch at all (fell through to the 'yearly' else-case). Added
 * a real daily branch (last 30 days, grouped by calendar day) plus the
 * corresponding <option> in app/dashboard.php.
 *
 * Live reconciliation: calls the real endpoint (in-process, same as every
 * other API test in this suite) and cross-checks its 'daily' response
 * against a direct SQL query over the same ledger tables, so this proves
 * the actual numbers are right — not just that the endpoint returns 200.
 *
 * Exit 0 = all checks pass.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/project_scope.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

$adminUid = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = $adminUid;
$_SESSION['is_admin'] = true;
$_SESSION['role_id'] = 1;

function _call_performance_api(string $root, string $period): array {
    global $pdo;
    $_GET['period'] = $period;
    ob_start();
    require "$root/api/get_performance_data.php";
    $out = ob_get_clean();
    $json = json_decode($out, true);
    return is_array($json) ? $json : ['success' => false, 'raw' => $out];
}

try {
    // ── A. Static — the dropdown + backend both know about 'daily' ──────────
    section('A. Static — dashboard.php offers Daily, get_performance_data.php handles it');
    $dash = file_get_contents("$root/app/dashboard.php");
    ok(strpos($dash, "<option value=\"daily\">") !== false, "dashboard.php's #chartPeriod dropdown has a Daily option");

    $api = file_get_contents("$root/api/get_performance_data.php");
    ok(strpos($api, "\$period === 'daily'") !== false, "get_performance_data.php has a dedicated 'daily' branch");

    // ── B. Live — 'daily' returns valid, correctly-shaped data ───────────────
    section("B. Live — period=daily");
    $resp = _call_performance_api($root, 'daily');
    ok($resp['success'] === true, "response success=true" . (isset($resp['raw']) ? ' (raw: ' . substr($resp['raw'], 0, 200) . ')' : ''));
    ok(isset($resp['data']) && is_array($resp['data']), "response carries a 'data' array");

    if (!empty($resp['data'])) {
        $last = end($resp['data']);
        foreach (['period', 'revenue', 'expense', 'net_profit', 'collected', 'cash_out', 'net_cash'] as $key) {
            ok(array_key_exists($key, $last), "each daily data point carries '$key'");
        }
        ok((bool)preg_match('/^[A-Z][a-z]{2} \d{1,2}$/', $last['period']), "daily label is 'Mon D' style (got '{$last['period']}')");

        // The last emitted day must be TODAY or earlier — never a future date.
        // Compare as plain 'Y-m-d' strings (date-only) to avoid any
        // time-of-day mismatch between the two DateTime defaults.
        $lastDateOnly = date('Y-m-d', strtotime($last['period'] . ' ' . date('Y')));
        ok($lastDateOnly <= date('Y-m-d'), "the most recent daily point ($lastDateOnly) is not in the future");
    }

    // ── C. Live reconciliation — TODAY's figure matches a direct ledger query ──
    section('C. Live reconciliation — daily figure for TODAY matches direct SQL');
    $scope = scopeFilterSqlNullable('project', 'je');
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN at.category IN ('revenue','other_income')
                     THEN (CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END)
                     ELSE 0 END) AS revenue,
            SUM(CASE WHEN at.category IN ('cogs','expense','finance_cost')
                     THEN (CASE WHEN jei.type='debit'  THEN jei.amount ELSE -jei.amount END)
                     ELSE 0 END) AS expense
        FROM journal_entry_items jei
        JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
        JOIN accounts        a  ON a.account_id = jei.account_id
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        WHERE je.entry_date = CURDATE()
          {$scope}
    ");
    $stmt->execute();
    $direct = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['revenue' => 0, 'expense' => 0];
    $directRevenue = round((float)($direct['revenue'] ?? 0), 2);
    $directExpense = round((float)($direct['expense'] ?? 0), 2);

    // Periods are emitted in ascending date order and can never exceed today,
    // so the last element the API returns for 'daily' is always today's row.
    $apiToday = end($resp['data']);
    ok(abs(($apiToday['revenue'] ?? -9999999) - $directRevenue) < 0.01,
        "API's today revenue ({$apiToday['revenue']}) matches direct SQL ($directRevenue)");
    ok(abs(($apiToday['expense'] ?? -9999999) - $directExpense) < 0.01,
        "API's today expense ({$apiToday['expense']}) matches direct SQL ($directExpense)");

    // ── D. Regression — the other 4 periods still work ───────────────────────
    section('D. Regression — Weekly/Monthly/Quarterly/Yearly still return valid data');
    foreach (['weekly', 'monthly', 'quarterly', 'yearly'] as $period) {
        $r = _call_performance_api($root, $period);
        ok($r['success'] === true, "period=$period still returns success=true");
    }

} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

exit($fail === 0 ? 0 : 1);
