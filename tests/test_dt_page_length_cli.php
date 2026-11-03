<?php
/**
 * tests/test_dt_page_length_cli.php
 *   php tests/test_dt_page_length_cli.php
 *
 * Page size "All" on the server-side DataTables lists (dt_page_length() in
 * helpers.php). DataTables sends length=-1 for "All"; the lists built
 * LIMIT 0, -1 (invalid SQL) and answered HTTP 500 — the "Ajax error" warning
 * (tn/7), or an empty table where the error was caught. This proves:
 *
 *   1. dt_page_length() — missing → the list's default, a normal size is kept,
 *      -1 / 0 / junk → every row
 *   2. every list API reads its page size through it
 *   3. against the database: each API answers "All" without an error and
 *      returns every row (rows = recordsFiltered), and 25 still pages
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
function same($got, $want, string $label): void {
    $got === $want ? pass($label) : fail("$label — got `" . var_export($got, true) . "`");
}

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

echo "\n\033[1m═══ DataTables page size \"All\" ═══\033[0m\n";

// ── 1. dt_page_length() ────────────────────────────────────────────────────
section('dt_page_length()');
same(dt_page_length(null), 10, 'missing → default 10');
same(dt_page_length('', 25), 25, 'empty → the list\'s own default');
same(dt_page_length('25'), 25, 'normal size kept');
same(dt_page_length(100), 100, 'integer size kept');
same(dt_page_length('-1') >= 1000000, true, '"All" (-1) → every row');
same(dt_page_length(-1) >= 1000000, true, '"All" as integer → every row');
same(dt_page_length('0') >= 1000000, true, '0 → every row (never LIMIT x, 0)');
same(dt_page_length('abc') >= 1000000, true, 'junk → every row, never reaches SQL as text');

// ── 2. every list API uses it ─────────────────────────────────────────────
section('List APIs read the page size through dt_page_length()');
$apis = [
    'ajax/get_users.php', 'api/account/get_expenses.php', 'api/account/get_invoices.php',
    'api/account/get_sales_orders.php', 'api/account/get_chart_of_accounts.php',
    'api/document/get_compliance_documents.php', 'api/document/get_documents.php',
    'api/get_campaigns.php', 'api/get_compliance.php', 'api/get_customers_paged.php',
    'api/get_delivery_notes_list.php', 'api/get_documents.php', 'api/get_email_templates.php',
    'api/get_employees.php', 'api/get_grns.php', 'api/get_leads.php', 'api/get_notifications.php',
    'api/get_pending_signatures.php', 'api/get_purchase_returns.php', 'api/get_signature_history.php',
    'api/get_templates.php', 'api/get_user_signatures.php', 'api/operations/get_assets.php',
    'api/operations/get_maintenance_logs.php', 'api/operations/get_projects.php',
];
foreach ($apis as $rel) {
    $src = (string)@file_get_contents("$root/$rel");
    preg_match('/\$length\s*=\s*dt_page_length\(\s*\$_GET\[\'length\'\]/', $src)
        ? pass("$rel — uses dt_page_length()") : fail("$rel — page size not read through dt_page_length()");
}

// ── 3. against the database ────────────────────────────────────────────────
section('Each API answers "All" with every row (database)');
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
    $tmp = sys_get_temp_dir() . '/bms_dtlen_' . getmypid() . '.php';
    file_put_contents($tmp, $stub);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg("$root/$rel") . ' '
         . escapeshellarg(base64_encode(http_build_query($get))) . ' ' . (int)$uid . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $out = (string)shell_exec($cmd);
    @unlink($tmp);
    $p = strpos($out, '{');
    $j = $p === false ? null : json_decode(substr($out, $p), true);
    return is_array($j) ? $j : null;
}

foreach ($apis as $rel) {
    $req = ['draw' => 1, 'start' => 0, 'search' => ['value' => ''],
            'order' => [['column' => 0, 'dir' => 'desc']], 'columns' => [['data' => '']]];
    $all  = callApi($root, $rel, $req + ['length' => -1], $adminId);
    $page = callApi($root, $rel, $req + ['length' => 25], $adminId);
    if ($all === null || !isset($all['data']) || !empty($all['error']) || ($all['success'] ?? true) === false) {
        fail("$rel — \"All\" returned an error or invalid JSON");
        continue;
    }
    $filtered = (int)($all['recordsFiltered'] ?? $all['recordsTotal'] ?? count($all['data']));
    count($all['data']) === $filtered
        ? pass("$rel — \"All\" returns every row ($filtered)")
        : fail("$rel — \"All\" returned " . count($all['data']) . " of $filtered rows");
    if ($page !== null && isset($page['data'])) {
        count($page['data']) === min(25, $filtered)
            ? pass("$rel — 25 per page still pages")
            : fail("$rel — 25 per page returned " . count($page['data']) . " rows");
    } else {
        fail("$rel — 25 per page returned an error or invalid JSON");
    }
}
