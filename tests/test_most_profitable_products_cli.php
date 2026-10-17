<?php
/**
 * Most Profitable Products (customer_subscription_plan.md Phase 7)
 *   php tests/test_most_profitable_products_cli.php
 *
 * A: wiring (CI).  B: a real invoice of known goods + services; cost, profit, margin, the
 * profit sort and the ledger reconciliation checked through the real report API.
 * Data is 'MPP-TEST' and removed (incl. its ledger entries). Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$hasConfig = is_file("$root/includes/config.php");
if ($hasConfig) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

section('A. wiring');
$api = file_get_contents("$root/api/account/get_product_analysis_report.php");
$page = file_get_contents("$root/app/constant/reports/product_analysis.php");
$hdr = file_get_contents("$root/header.php");
ok(strpos($api, "'sort'") !== false && strpos($api, "'cost_basis'") !== false && strpos($api, 'glProfitLoss(') !== false, 'API: cost, profit, sort and ledger reconciliation');
ok(strpos($api, 'scopeFilterSqlNullable') !== false && strpos($api, "userCan('project'") !== false, 'API keeps project/warehouse scope (§23)');
ok(strpos($page, 'id="f-sort"') !== false && strpos($page, "view'] ?? '') === 'profit'") !== false && strpos($page, 'id="profitNote"') !== false, 'page: sort by profit, profit columns, reconciliation note');
ok(substr_count($hdr, "?view=profit") === 2, 'Reports menu (full and Simple POS) links Most Profitable Products');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B ──────────────────────────────────────────────────────────────────────
global $pdo;
$uid  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid  = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$cust = (int)$pdo->query("SELECT customer_id FROM customers WHERE status = 'active' ORDER BY customer_id LIMIT 1")->fetchColumn();
if (!$uid || !$wid || !$cust) { echo "SKIP B: needs admin, warehouse, customer\n"; exit; }

$cleanup = function () use ($pdo) {
    $inv = implode(',', array_map('intval', $pdo->query("SELECT invoice_id FROM invoices WHERE notes = 'MPP-TEST'")->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                 WHERE je.entity_type IN ('invoice','invoice_cogs','invoice_void') AND je.entity_id IN ($inv)");
    $pdo->exec("DELETE FROM journal_entries WHERE entity_type IN ('invoice','invoice_cogs','invoice_void') AND entity_id IN ($inv)");
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ($inv)");
    try { $pdo->exec("DELETE FROM workflow_signatures WHERE entity_type = 'invoice' AND entity_id IN ($inv)"); } catch (Throwable $e) {}
    $pdo->exec("DELETE FROM invoices WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM products WHERE product_name LIKE 'MPP-TEST%'");
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

$mk = function (string $name, int $isService, float $sell, float $cost) use ($pdo, $wid, $uid): int {
    $pdo->prepare("INSERT INTO products (product_name, is_service, track_inventory, selling_price, cost_price, status, warehouse_id, unit, created_by)
                   VALUES (?, ?, 0, ?, ?, 'active', ?, 'pcs', ?)")->execute([$name, $isService, $sell, $cost, $wid, $uid]);
    return (int)$pdo->lastInsertId();
};
$gA = $mk('MPP-TEST Goods A', 0, 1000, 600);
$gB = $mk('MPP-TEST Goods B', 0, 5000, 4500);
$sv = $mk('MPP-TEST Install', 1, 20000, 5000);
$sv0 = $mk('MPP-TEST Advice', 1, 8000, 0);

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'mpp_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function req(string $file, array $data, string $method): array {
    global $root, $uid;
    $out = runPhp("\$_SERVER['REQUEST_METHOD'] = '$method'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_role'] = 'Admin';
        \$_POST = \$_GET = \$_REQUEST = " . var_export($data, true) . "; \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);");
    $j = json_decode($out, true);
    if (!is_array($j)) { $p = strrpos($out, "\n{"); $j = $p !== false ? json_decode(substr($out, $p + 1), true) : null; }
    return is_array($j) ? $j : ['success' => false, 'message' => $out];
}
$today = date('Y-m-d');
$report = fn(string $sort = 'revenue') => req('api/account/get_product_analysis_report.php',
    ['date_from' => $today, 'date_to' => $today, 'warehouse_id' => (string)$wid, 'sort' => $sort], 'GET');

$before = $report();
ok(!empty($before['success']), 'report loads' . (empty($before['success']) ? ' — ' . ($before['message'] ?? '') : ''));
$diffBefore = $before['ledger']['difference'] ?? null;

$line = fn(int $pid, string $n, float $q, float $p) => ['product_id' => $pid, 'product_name' => $n, 'quantity' => $q, 'unit' => 'pcs',
    'unit_price' => $p, 'tax_rate' => 0, 'discount_percent' => 0, 'description' => ''];
$r = req('api/account/save_invoice.php', ['customer_id' => $cust, 'invoice_date' => $today, 'due_date' => $today, 'currency' => 'TZS',
    'warehouse_id' => $wid, 'notes' => 'MPP-TEST', 'items' => [
        $line($gA, 'MPP-TEST Goods A', 10, 1000), $line($gB, 'MPP-TEST Goods B', 2, 5000),
        $line($sv, 'MPP-TEST Install', 1, 20000), $line($sv0, 'MPP-TEST Advice', 1, 8000)]], 'POST');
ok(!empty($r['success']), 'invoice saved (posts revenue + cost of goods to the ledger)' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));

section('B1. cost, profit, margin per row');
$after = $report('profit');
$rows = [];
foreach ($after['rows'] ?? [] as $row) $rows[(int)$row['product_id']] = $row;
$chk = function (int $id, float $cost, float $profit, ?float $margin, string $basis, string $label) use ($rows) {
    $r = $rows[$id] ?? null;
    ok($r && abs((float)$r['cost'] - $cost) < 0.01 && abs((float)$r['profit'] - $profit) < 0.01
       && ($margin === null ? $r['margin'] === null : abs((float)$r['margin'] - $margin) < 0.05) && $r['cost_basis'] === $basis,
       "$label: cost " . number_format($cost) . ", profit " . number_format($profit) . ", margin $margin%, basis $basis"
       . ($r ? '' : ' (row missing)'));
};
$chk($gA, 6000, 4000, 40.0, 'ledger', 'Goods A (10 × 1,000, cost 600)');
$chk($gB, 9000, 1000, 10.0, 'ledger', 'Goods B (2 × 5,000, cost 4,500)');
$chk($sv, 5000, 15000, 75.0, 'estimate', 'Service with materials (estimated cost)');
$chk($sv0, 0, 8000, 100.0, 'none', 'Service with no cost');

section('B2. sorted by profit');
$order = array_values(array_filter(array_map(fn($r) => (int)$r['product_id'], $after['rows'] ?? []), fn($id) => in_array($id, [$gA, $gB, $sv, $sv0], true)));
ok($order === [$sv, $sv0, $gA, $gB], 'Install 15,000 → Advice 8,000 → Goods A 4,000 → Goods B 1,000');
$byRev = array_values(array_filter(array_map(fn($r) => (int)$r['product_id'], $report('revenue')['rows'] ?? []), fn($id) => in_array($id, [$gA, $gB, $sv, $sv0], true)));
ok($byRev === [$sv, $gA, $gB, $sv0], 'sorted by revenue it stays the old order (20,000 → 10,000 → 10,000 → 8,000)');
ok(!empty($after['charts']['top_profit']) && isset($after['summary']['total_profit'], $after['summary']['margin']), 'summary + top-by-profit chart data');

section('B3. matches the ledger');
$diffAfter = $after['ledger']['difference'] ?? null;
ok($diffBefore !== null && $diffAfter !== null && abs($diffAfter - $diffBefore) < 0.01,
   'adding the invoice moved report goods cost and ledger COGS by the same amount (15,000) — difference unchanged');
ok(abs(($after['ledger']['report_goods_cost'] ?? 0) - ($before['ledger']['report_goods_cost'] ?? 0) - 15000) < 0.01, 'goods cost counted once (services excluded from the ledger comparison)');

section('B4. page');
$html = runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
    \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
    \$_GET = ['view' => 'profit']; ob_start(); include '$root/app/constant/reports/product_analysis.php'; echo ob_get_clean();");
ok(strpos($html, 'Most Profitable Products') !== false && preg_match('/<option value="profit" selected>/', $html) && strpos($html, 'Fatal error') === false,
   '?view=profit opens as Most Profitable Products, sorted by profit');
