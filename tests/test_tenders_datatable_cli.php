<?php
/**
 * Tenders DataTable conversion CLI test.
 *   php tests/test_tenders_datatable_cli.php
 *
 * Verifies:
 *   - api/get_tenders.php: limit=-1 returns every matching row, no LIMIT/OFFSET
 *   - api/get_tenders.php: default behavior (no limit param) is unchanged
 *     (still paginates, defaults to 10 per page) — nothing else in the
 *     codebase calls this endpoint, but the default path is kept intact anyway
 *   - tenders.php renders with no PHP errors and carries the new DataTable +
 *     Copy/CSV/Print/PDF toolbar markup, while all pre-existing workflow JS
 *     (staff assignment, exportPDF, generateActions) is still present
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[3]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'GET';
    $_GET = $cfg['get'] ?? [];
    $target = $argv[2];
    require "$root/$target";
    exit;
}

require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

function runWorker(string $target, array $session, array $get = []): string {
    global $root;
    $cfg = ['session' => $session, 'method' => 'GET', 'get' => $get];
    $f = tempnam(sys_get_temp_dir(), 'tdt'); file_put_contents($f, json_encode($cfg));
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($target) . ' ' . escapeshellarg($f));
    @unlink($f);
    return $o;
}

try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM tenders")->fetchColumn();
    $SESSION = ['user_id' => $admin_uid, 'is_admin' => true, 'role_id' => 1, 'first_name' => 'Admin', 'last_name' => 'Admin', 'user_role' => 'Admin'];

    section('1. api/get_tenders.php — limit=-1 fetches everything');
    $out = runWorker('api/get_tenders.php', $SESSION, ['limit' => -1]);
    $s = strpos($out, '{'); $res = $s === false ? null : json_decode(substr($out, $s), true);
    ok($res !== null && !empty($res['success']), 'endpoint responds successfully with limit=-1');
    ok($res !== null && count($res['data'] ?? []) === $total, "returns all $total tenders, not just one page");
    ok($res !== null && (int)($res['pagination']['pages'] ?? 0) === 1, "pagination.pages=1 when fetching everything");

    section('2. api/get_tenders.php — default behavior unchanged');
    $out2 = runWorker('api/get_tenders.php', $SESSION, []);
    $s2 = strpos($out2, '{'); $res2 = $s2 === false ? null : json_decode(substr($out2, $s2), true);
    $expectedCount = min(10, $total);
    ok($res2 !== null && !empty($res2['success']), 'endpoint responds successfully with no params');
    ok($res2 !== null && count($res2['data'] ?? []) === $expectedCount, "still defaults to 10 per page (got " . ($res2 ? count($res2['data'] ?? []) : 'n/a') . ", expected $expectedCount)");
    ok($res2 !== null && (int)($res2['pagination']['limit'] ?? 0) === 10, 'pagination.limit defaults to 10, unchanged');

    section('3. tenders.php renders clean with the new DataTable + toolbar');
    $render = runWorker('app/bms/tenders/tenders.php', $SESSION);
    foreach (['Fatal error','Parse error','Uncaught','SQLSTATE'] as $needle) {
        ok(stripos($render, $needle) === false, "no '$needle' in rendered output");
    }
    foreach ([
        "tenderTable').DataTable" => 'DataTable initialized on #tenderTable',
        'function initTendersTable' => 'initTendersTable() present',
        'function reloadTenders' => 'reloadTenders() present',
        'function copyTendersTable' => 'Copy button handler present',
        'function exportTendersCSV' => 'CSV button handler present',
        'function exportPDF' => 'pre-existing PDF export preserved',
        'function generateActions' => 'pre-existing workflow-action generator preserved',
        "staff_select_input').html(html).select2" => 'pre-existing staff-assignment Select2 flow preserved',
    ] as $needle => $label) {
        ok(strpos($render, $needle) !== false, $label);
    }

    $lintOut=[]; $rc=0; exec('php -l ' . escapeshellarg("$root/app/bms/tenders/tenders.php") . ' 2>&1', $lintOut, $rc);
    ok($rc===0, 'tenders.php php -l clean');
    $lintOut=[]; $rc=0; exec('php -l ' . escapeshellarg("$root/api/get_tenders.php") . ' 2>&1', $lintOut, $rc);
    ok($rc===0, 'api/get_tenders.php php -l clean');

} catch (Throwable $e) {
    ok(false, 'test threw: ' . $e->getMessage());
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
