<?php
/**
 * tests/test_dt_header_sort_cli.php
 *   php tests/test_dt_header_sort_cli.php
 *
 * Header sorting on the server-side DataTables lists (core/dt_order.php).
 * Before this, ten list APIs ignored the clicked column: every header looked
 * sortable but only S/No changed the order. This proves:
 *
 *   1. dtOrderSql() — S/No gives the normal order / its reverse, a whitelisted
 *      column sorts with a tie-breaker, anything else (unknown names, injection
 *      attempts, bad directions) falls back to safe SQL
 *   2. every list API uses it (no leftover S/No-only ordering)
 *   3. against the database: each API answers every whitelisted column in both
 *      directions without an SQL error, and the rows come back in that order
 *      (where the column is a plain field of the returned row)
 *
 * Each API call runs in its own PHP process (the endpoints exit / declare state).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/dt_order.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function same($got, $want, string $label): void {
    $got === $want ? pass($label) : fail("$label — got `" . var_export($got, true) . "`");
}

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

echo "\n\033[1m═══ DataTables header sorting ═══\033[0m\n";

// ── 1. dtOrderSql() ────────────────────────────────────────────────────────
section('dtOrderSql()');
$map = ['name' => 'c.name', 'total' => 'total'];
$req = function (int $col, string $dir, array $cols) {
    $c = [];
    foreach ($cols as $i => $d) $c[$i] = is_array($d) ? $d : ['data' => $d];
    return ['order' => [['column' => $col, 'dir' => $dir]], 'columns' => $c];
};
$cols = ['', 'name', 'total', '', ['data' => '', 'name' => 'total']];
same(dtOrderSql($req(0, 'asc', $cols), $map, 'N', 'R', 'c.id'), 'N', 'S/No ascending → normal order');
same(dtOrderSql($req(0, 'desc', $cols), $map, 'N', 'R', 'c.id'), 'R', 'S/No descending → reversed order');
same(dtOrderSql($req(1, 'asc', $cols), $map, 'N', 'R', 'c.id'), 'c.name ASC, c.id ASC', 'whitelisted column ascending, with tie-breaker');
same(dtOrderSql($req(2, 'desc', $cols), $map, 'N', 'R', 'c.id'), 'total DESC, c.id DESC', 'whitelisted column descending, with tie-breaker');
same(dtOrderSql($req(4, 'asc', $cols), $map, 'N', 'R', 'c.id'), 'total ASC, c.id ASC', 'render-only column sorts by its columns[i][name]');
same(dtOrderSql($req(3, 'asc', $cols), $map, 'N', 'R', 'c.id'), 'N', 'column with no data/name → normal order');
same(dtOrderSql($req(1, 'asc', ['', 'name; DROP TABLE x']), $map, 'N', 'R', 'c.id'), 'N', 'unknown data name never reaches SQL');
same(dtOrderSql($req(1, 'desc; DROP', $cols), $map, 'N', 'R', 'c.id'), 'c.name ASC, c.id ASC', 'bad direction → ASC');
same(dtOrderSql($req(1, 'asc', [0 => '', 1 => ['data' => ['x' => 1]]]), $map, 'N', 'R', 'c.id'), 'N', 'array data value ignored');
same(dtOrderSql([], $map, 'N', 'R', 'c.id'), 'N', 'no order in request → normal order');
same(dtOrderSql($req(1, 'desc', ['', '']), $map, 'N', 'R', 'c.id', 1), 'R', 'S/No in column 1 (projects) honoured');
same(dtOrderSql($req(0, 'desc', ['name']), $map, 'N', 'R', 'c.id', 1), 'c.name DESC, c.id DESC', 'column 0 is a data column when S/No is column 1');

// ── 2. every list API uses it ──────────────────────────────────────────────
section('List APIs use dtOrderSql()');
$apis = [
    'api/get_customers_paged.php',
    'api/account/get_invoices.php',
    'api/account/get_sales_orders.php',
    'api/operations/get_assets.php',
    'api/operations/get_maintenance_logs.php',
    'ajax/get_users.php',
    'api/operations/get_projects.php',
    'api/account/get_journals.php',
    'api/get_delivery_notes_list.php',
    'api/get_grns.php',
];
$whitelists = [];
foreach ($apis as $rel) {
    $src = (string)@file_get_contents("$root/$rel");
    if ($src === '') { fail("$rel — missing"); continue; }
    (strpos($src, "core/dt_order.php") !== false && strpos($src, 'dtOrderSql(') !== false)
        ? pass("$rel — loads core/dt_order.php and calls dtOrderSql()") : fail("$rel — not using dtOrderSql()");
    preg_match('/\$sn_(rev|d|dir)\b/', $src) ? fail("$rel — leftover S/No-only ordering (\$sn_*)") : pass("$rel — no leftover S/No-only ordering");
    strpos($src, 'ORDER BY $order_sql') !== false ? pass("$rel — ORDER BY uses the computed clause") : fail("$rel — ORDER BY does not use \$order_sql");
    if (preg_match('/dtOrderSql\(\$_GET,\s*\[(.*?)\],/s', $src, $m) && preg_match_all("/'([a-z_]+)'\s*=>/", $m[1], $k)) {
        $whitelists[$rel] = $k[1];
    } else {
        fail("$rel — could not read its sort whitelist");
    }
}
foreach (['api/operations/get_maintenance_logs.php' => "canView('assets')", 'api/operations/get_projects.php' => "canView('projects')",
          'api/get_delivery_notes_list.php' => "canView('dn')"] as $rel => $gate) {
    $src = (string)@file_get_contents("$root/$rel");
    (strpos($src, 'isAuthenticated()') !== false && strpos($src, $gate) !== false)
        ? pass("$rel — login + $gate enforced") : fail("$rel — missing login/permission gate");
}

// ── 3. against the database ────────────────────────────────────────────────
section('Each API sorts every whitelisted column (database)');
if (!($pdo instanceof PDO)) { echo "  (no database — skipped)\n"; exit; }
$adminId = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
if ($adminId <= 0) { echo "  (no admin user — skipped)\n"; exit; }

function callApi(string $root, string $rel, array $get, int $uid): ?array
{
    $stub = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = (int)$argv[3]; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/bms/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
parse_str($argv[2] ?? '', $_GET);
ob_start(); ob_start();
include $argv[1];
while (ob_get_level() > 1) ob_end_flush();
echo ob_get_clean();
PHP;
    $tmp = sys_get_temp_dir() . '/bms_dtsort_' . getmypid() . '.php';
    file_put_contents($tmp, $stub);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg("$root/$rel") . ' '
         . escapeshellarg(http_build_query($get)) . ' ' . (int)$uid . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $out = (string)shell_exec($cmd);
    @unlink($tmp);
    $j = json_decode(substr($out, (int)strpos($out, '{')), true);
    return is_array($j) ? $j : null;
}
function cmpVal($a, $b): int
{
    $a = ($a === null || $a === '') ? null : $a; $b = ($b === null || $b === '') ? null : $b;
    if ($a === $b) return 0;
    if ($a === null) return -1;
    if ($b === null) return 1;
    if (is_numeric($a) && is_numeric($b)) return $a <=> $b;
    return strcasecmp((string)$a, (string)$b) <=> 0;
}

foreach ($whitelists as $rel => $keys) {
    $snCol = $rel === 'api/operations/get_projects.php' ? 1 : 0;
    foreach ($keys as $key) {
        $col = $snCol + 1;                       // any non-S/No index; the key decides
        $columns = array_fill(0, $col + 1, ['data' => '']);
        $columns[$col] = ['data' => $key];
        $runs = [];
        foreach (['asc', 'desc'] as $dir) {
            $runs[$dir] = callApi($root, $rel, ['draw' => 1, 'start' => 0, 'length' => 100,
                'order' => [['column' => $col, 'dir' => $dir]], 'columns' => $columns], $adminId);
        }
        $okJson = $runs['asc'] !== null && $runs['desc'] !== null
               && isset($runs['asc']['data'], $runs['desc']['data']) && empty($runs['asc']['error']) && empty($runs['desc']['error']);
        if (!$okJson) { fail("$rel — `$key` returned an error or invalid JSON"); continue; }
        $sorted = true; $checked = false;
        foreach (['asc' => 1, 'desc' => -1] as $dir => $sign) {
            $vals = array_map(fn($r) => is_array($r) && array_key_exists($key, $r) ? $r[$key] : '__absent__', $runs[$dir]['data']);
            if (in_array('__absent__', $vals, true)) continue;   // computed in SQL under another name
            $checked = true;
            for ($i = 1; $i < count($vals); $i++) {
                if ($sign * cmpVal($vals[$i - 1], $vals[$i]) > 0) { $sorted = false; break; }
            }
        }
        $sorted ? pass("$rel — `$key` asc/desc" . ($checked ? ' sorted' : ' answered')) : fail("$rel — `$key` rows not in order");
    }
}
