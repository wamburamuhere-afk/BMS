<?php
/**
 * Expenses list "Ajax error" — missing payee columns — CLI regression suite
 *   php tests/test_expenses_payee_columns_migration_cli.php
 *
 * demo.bjptechnologies.co.tz/expenses showed "DataTables warning: table
 * id=expensesTable - Ajax error". api/account/get_expenses.php selects
 * e.paid_to_type / paid_to_id / payee_manual_name, but paid_to_type and
 * paid_to_id were only ever created by a hand-run legacy script, so a database
 * without them makes the list query fail (500). The fix is
 * migrations/tenant/2026_10_28_expenses_payee_columns.php.
 *
 *   A. STATIC   — migration + endpoint lint clean.
 *   B. BROKEN   — a scratch DB whose expenses table lacks the four columns
 *                 reproduces the failure (the endpoint's payee SELECT errors).
 *   C. MIGRATE  — the real migration, run through tenant_migration_bootstrap
 *                 exactly as the runner does, adds the columns; a second run
 *                 is a no-op; the same SELECT now succeeds.
 *   D. LEGACY   — a table with the legacy ENUM('supplier','staff') gets widened
 *                 to allow sub_contractor/other, and the manual columns added.
 *   E. RUNTIME  — the real api/get_expenses.php returns valid DataTables JSON.
 *
 * The scratch DB and temporary MySQL user are always dropped at the end.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$scratchDb = 'bms_exp_payee_test_' . bin2hex(random_bytes(3));
$tmpUser   = 'exp_payee_' . bin2hex(random_bytes(3));
$tmpPass   = bin2hex(random_bytes(12));
$madeUser  = false;

register_shutdown_function(function () use ($pdo, $scratchDb, $tmpUser, &$madeUser) {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    try { $pdo->exec("DROP DATABASE IF EXISTS `$scratchDb`"); } catch (Throwable $e) {}
    if ($madeUser) { try { $pdo->exec("DROP USER IF EXISTS '$tmpUser'@'localhost'"); } catch (Throwable $e) {} }
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$migFile = "$root/migrations/tenant/2026_10_28_expenses_payee_columns.php";
$payeeCols = ['paid_to_type', 'paid_to_id', 'payee_manual_role', 'payee_manual_name'];
$payeeSelect = "SELECT e.paid_to_type, e.paid_to_id, e.payee_manual_role, e.payee_manual_name FROM `%s`.expenses e LIMIT 1";

// ─────────────────────────────────────────────────────────────────────────
section('A. Files lint clean');
foreach (['migrations/tenant/2026_10_28_expenses_payee_columns.php', 'api/account/get_expenses.php'] as $f) {
    $rc = 0; $out = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// Credentials the migration subprocess connects with. Windows cannot pass an
// empty env var (it deletes it), so with an empty local password a temporary
// password-protected user scoped to the scratch DB is used instead.
$pdo->exec("CREATE DATABASE `$scratchDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$migUser = DB_USERNAME; $migPass = DB_PASSWORD;
if (DB_PASSWORD === '') {
    try {
        $pdo->exec("CREATE USER '$tmpUser'@'localhost' IDENTIFIED BY '$tmpPass'");
        $madeUser = true;
        $pdo->exec("GRANT ALL PRIVILEGES ON `$scratchDb`.* TO '$tmpUser'@'localhost'");
        $migUser = $tmpUser; $migPass = $tmpPass;
    } catch (PDOException $e) {
        fail('could not create a temporary MySQL user for the migration subprocess: ' . $e->getMessage());
        exit;
    }
}

function _run_migration(string $file, string $db, string $user, string $passw): string {
    $env = array_merge(array_filter($_SERVER, 'is_string'), [
        'TENANT_MIGRATION_DB_HOST' => DB_SERVER,
        'TENANT_MIGRATION_DB_NAME' => $db,
        'TENANT_MIGRATION_DB_USER' => $user,
        'TENANT_MIGRATION_DB_PASS' => $passw,
    ]);
    $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) return '';
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    return $out;
}
function _cols(PDO $pdo, string $db): array {
    $rows = $pdo->query("SHOW COLUMNS FROM `$db`.expenses")->fetchAll(PDO::FETCH_ASSOC);
    return array_column($rows, 'Type', 'Field');
}
function _select_ok(PDO $pdo, string $sql): bool {
    try { $pdo->query($sql)->fetchAll(); return true; } catch (PDOException $e) { return false; }
}

// ─────────────────────────────────────────────────────────────────────────
section('B. Database without the payee columns reproduces the failure');
$pdo->exec("CREATE TABLE `$scratchDb`.expenses LIKE `" . DB_NAME . "`.expenses");
$have = _cols($pdo, $scratchDb);
$drop = array_values(array_filter($payeeCols, fn($c) => isset($have[$c])));
if ($drop) {
    $pdo->exec("ALTER TABLE `$scratchDb`.expenses " . implode(', ', array_map(fn($c) => "DROP COLUMN `$c`", $drop)));
}
$have = _cols($pdo, $scratchDb);
count(array_intersect($payeeCols, array_keys($have))) === 0 ? pass('scratch expenses table has none of the 4 payee columns') : fail('scratch table still has payee columns');
!_select_ok($pdo, sprintf($payeeSelect, $scratchDb)) ? pass('the list query\'s payee SELECT fails there (the demo bug)') : fail('payee SELECT unexpectedly succeeded on a table without the columns');

// ─────────────────────────────────────────────────────────────────────────
section('C. Migration adds the columns and is idempotent');
$run1 = _run_migration($migFile, $scratchDb, $migUser, $migPass);
strpos($run1, 'Migration complete.') !== false ? pass('first run completes') : fail("first run did not complete:\n$run1");
$have = _cols($pdo, $scratchDb);
foreach ($payeeCols as $c) isset($have[$c]) ? pass("expenses.$c added") : fail("expenses.$c still missing");
$t = $have['paid_to_type'] ?? '';
(stripos($t, "'sub_contractor'") !== false && stripos($t, "'other'") !== false) ? pass("paid_to_type = $t") : fail("paid_to_type has wrong type: $t");

$run2 = _run_migration($migFile, $scratchDb, $migUser, $migPass);
(strpos($run2, 'Migration complete.') !== false && substr_count($run2, 'already exists') === 4 && strpos($run2, '+ ') === false)
    ? pass('second run is a no-op (all 4 "already exists", nothing altered)') : fail("second run not idempotent:\n$run2");
_select_ok($pdo, sprintf($payeeSelect, $scratchDb)) ? pass('the payee SELECT now succeeds') : fail('payee SELECT still fails after migration');

// ─────────────────────────────────────────────────────────────────────────
section('D. Legacy ENUM(\'supplier\',\'staff\') is widened');
$pdo->exec("ALTER TABLE `$scratchDb`.expenses DROP COLUMN payee_manual_role, DROP COLUMN payee_manual_name,
            MODIFY COLUMN paid_to_type ENUM('supplier','staff') NULL DEFAULT NULL");
$pdo->exec("INSERT INTO `$scratchDb`.expenses (expense_date, amount, paid_to_type, paid_to_id) VALUES (CURDATE(), 10, 'staff', 7)");
$run3 = _run_migration($migFile, $scratchDb, $migUser, $migPass);
strpos($run3, 'Migration complete.') !== false ? pass('migration completes on a legacy-shaped table') : fail("legacy run did not complete:\n$run3");
$have = _cols($pdo, $scratchDb);
$t = $have['paid_to_type'] ?? '';
(stripos($t, "'sub_contractor'") !== false && stripos($t, "'other'") !== false) ? pass('paid_to_type widened to allow sub_contractor + other') : fail("paid_to_type not widened: $t");
(isset($have['payee_manual_role']) && isset($have['payee_manual_name'])) ? pass('manual payee columns added') : fail('manual payee columns missing');
$row = $pdo->query("SELECT paid_to_type, paid_to_id FROM `$scratchDb`.expenses LIMIT 1")->fetch(PDO::FETCH_ASSOC);
($row && $row['paid_to_type'] === 'staff' && (int)$row['paid_to_id'] === 7) ? pass('existing payee data preserved through the widen') : fail('existing payee data changed: ' . json_encode($row));

// ─────────────────────────────────────────────────────────────────────────
section('E. Real api/get_expenses.php returns valid DataTables JSON');
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$code = '$_SERVER["HTTP_HOST"]="localhost"; $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/api/get_expenses.php";'
      . '@session_start(); $_SESSION["user_id"]=' . $uid . '; $_SESSION["role_id"]=1;'
      . '$_GET=["draw"=>3,"start"=>0,"length"=>10,"order"=>[["column"=>1,"dir"=>"desc"]],"search"=>["value"=>""]];'
      . 'chdir(' . var_export("$root/api", true) . '); require ' . var_export("$root/api/get_expenses.php", true) . ';';
$tmp = tempnam(sys_get_temp_dir(), 'exp_list_');
file_put_contents($tmp, "<?php\n" . $code);
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);
$json = json_decode(trim($out), true);
(is_array($json) && ($json['draw'] ?? null) === 3 && isset($json['data']) && !isset($json['error']))
    ? pass('valid JSON, draw echoed, ' . count($json['data']) . ' row(s), no error')
    : fail('endpoint did not return valid DataTables JSON: ' . substr($out, 0, 300));
