<?php
/**
 * tests/test_list_sql_input_cli.php
 *   php tests/test_list_sql_input_cli.php
 *
 * Request values that went into list SQL as raw text. Campaigns, Compliance and
 * Templates put `start` into LIMIT uncast; Campaigns, Leads and the Document
 * Library put the sort direction into ORDER BY uncast — a request could rewrite
 * the query. This proves:
 *
 *   1. each API casts `start` to a number and the direction to ASC / DESC
 *   2. against the database: harmless injections (`start=0,1 -- `,
 *      `dir=asc, (SELECT 1 FROM <missing table>)`) that break the query when
 *      they reach the SQL now leave the list whole, and normal asc / desc
 *      paging still works
 *
 * Each API call runs in its own PHP process (the endpoints exit / declare state).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

echo "\n\033[1m═══ List SQL input (start / sort direction) ═══\033[0m\n";

$startApis = ['api/get_campaigns.php', 'api/get_compliance.php', 'api/get_templates.php'];
$dirApis   = ['api/get_campaigns.php' => 'order_dir', 'api/get_leads.php' => 'order_dir',
              'api/document/get_documents.php' => 'orderDirection'];

// ── 1. source ──────────────────────────────────────────────────────────────
section('Values are cast before they reach SQL');
foreach ($startApis as $rel) {
    $src = (string)@file_get_contents("$root/$rel");
    preg_match('/\$start\s*=\s*max\(0,\s*\(int\)\(\$_GET\[\'start\'\]/', $src)
        ? pass("$rel — start cast to a number") : fail("$rel — start not cast");
}
foreach ($dirApis as $rel => $var) {
    $src = (string)@file_get_contents("$root/$rel");
    preg_match('/\$' . $var . '\s*=\s*strtolower\(\(string\)\(\$_GET\[\'order\'\]\[0\]\[\'dir\'\][^;]*\?\s*\'(ASC|DESC)\'\s*:\s*\'(ASC|DESC)\';/', $src)
        ? pass("$rel — direction limited to ASC / DESC") : fail("$rel — direction not whitelisted");
}

// ── 2. against the database ────────────────────────────────────────────────
section('Injections leave the list whole (database)');
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
parse_str(base64_decode($argv[2] ?? ''), $_GET);   // base64: Windows escapeshellarg() turns % into spaces
ob_start(); ob_start();
include $argv[1];
while (ob_get_level() > 1) ob_end_flush();
echo ob_get_clean();
PHP;
    $tmp = sys_get_temp_dir() . '/bms_sqlin_' . getmypid() . '.php';
    file_put_contents($tmp, $stub);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg("$root/$rel") . ' '
         . escapeshellarg(base64_encode(http_build_query($get))) . ' ' . (int)$uid . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $out = (string)shell_exec($cmd);
    @unlink($tmp);
    $p = strpos($out, '{');
    $j = $p === false ? null : json_decode(substr($out, $p), true);
    return is_array($j) ? $j : null;
}
function req(array $over = []): array
{
    return $over + ['draw' => 1, 'start' => 0, 'length' => 25, 'search' => ['value' => ''],
                    'order' => [['column' => 0, 'dir' => 'asc']], 'columns' => [['data' => '']]];
}
function rows(?array $j): ?int { return ($j !== null && isset($j['data']) && empty($j['error'])) ? count($j['data']) : null; }

foreach (array_unique(array_merge($startApis, array_keys($dirApis))) as $rel) {
    $normal = callApi($root, $rel, req(), $adminId);
    $n = rows($normal);
    if ($n === null) { fail("$rel — normal request returned an error"); continue; }
    if ($n < 2) { echo "  ($rel has fewer than 2 rows — injection checks skipped)\n"; continue; }
    pass("$rel — normal request: $n rows");

    if (in_array($rel, $startApis, true)) {
        $got = rows(callApi($root, $rel, req(['start' => '0,1 -- ']), $adminId));
        $got === $n ? pass("$rel — start=0,1-- ignored (still $n rows)")
                    : fail("$rel — start=0,1-- changed the query (" . var_export($got, true) . " rows)");
    }
    if (isset($dirApis[$rel])) {
        $got = rows(callApi($root, $rel, req(['order' => [['column' => 0, 'dir' => 'asc, (SELECT 1 FROM bms_no_such_table_x)']]]), $adminId));
        $got === $n ? pass("$rel — dir subquery ignored (still $n rows)")
                    : fail("$rel — dir injection changed the query (" . var_export($got, true) . " rows)");
        $desc = rows(callApi($root, $rel, req(['order' => [['column' => 0, 'dir' => 'desc']]]), $adminId));
        $desc === $n ? pass("$rel — dir=desc still works") : fail("$rel — dir=desc broke the list");
    }
    $p2 = rows(callApi($root, $rel, req(['start' => 1]), $adminId));
    $p2 === $n - 1 || ($n === 25 && $p2 !== null) ? pass("$rel — start=1 still pages")
                                                  : fail("$rel — start=1 returned " . var_export($p2, true) . " rows");
}
