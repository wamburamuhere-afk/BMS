<?php
/**
 * tests/helpers/restaurant_inproc.php — shared in-process harness for the restaurant tests
 * (restaurant_plan.md). Real endpoints run inside the caller's outer transaction
 * (SavepointPDO) with php://input stubbed; defines ok()/section()/call()/raw()/val()/worker(),
 * an admin session, and a stocked product fixture ($W shop, $DRINK, $DPRICE).
 */
$root = dirname(__DIR__, 2);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/feature_registry.php";
if (session_status() === PHP_SESSION_NONE) session_start();

$failures = 0; $passes = 0;
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});
function ok(bool $c, string $m): void { global $passes, $failures; if ($c) { $passes++; echo "  \033[32m✅\033[0m $m\n"; } else { $failures++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

class SavepointPDO extends PDO
{
    private int $depth = 0;
    public function startOuter(): void { parent::beginTransaction(); $this->depth = 1; }
    public function endOuter(): void { if ($this->depth > 0) { parent::rollBack(); $this->depth = 0; } }
    public function beginTransaction(): bool { $this->depth++; $this->exec("SAVEPOINT sp{$this->depth}"); return true; }
    public function commit(): bool { $this->exec("RELEASE SAVEPOINT sp{$this->depth}"); $this->depth--; return true; }
    public function rollBack(): bool { $this->exec("ROLLBACK TO SAVEPOINT sp{$this->depth}"); $this->depth--; return true; }
    public function inTransaction(): bool { return $this->depth > 1; }
}
class InputStub
{
    public static string $body = '';
    public $context;
    private int $pos = 0;
    public function stream_open($path, $mode, $options, &$opened) { $this->pos = 0; return true; }
    public function stream_read($n) { $r = substr(self::$body, $this->pos, $n); $this->pos += strlen($r); return $r; }
    public function stream_eof() { return $this->pos >= strlen(self::$body); }
    public function stream_stat() { return []; }
}
$pdo = new SavepointPDO('mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET time_zone = '+03:00'");
$GLOBALS['pdo'] = $pdo;

function raw(string $file, string $method, array $data = [], bool $json = false): string {
    $_SERVER['REQUEST_METHOD'] = $method; $_POST = []; $_GET = [];
    if ($method === 'GET') $_GET = $data;
    elseif ($json) InputStub::$body = json_encode($data);
    else { $_POST = $data + ['_csrf' => 'test-csrf']; InputStub::$body = ''; }
    stream_wrapper_unregister('php'); stream_wrapper_register('php', 'InputStub');
    ob_start();
    try { (function () use ($file) { global $pdo; include $GLOBALS['root'] . '/' . $file; })(); }
    catch (Throwable $e) { echo json_encode(['success' => false, 'message' => 'EXC: ' . $e->getMessage()]); }
    finally { $out = ob_get_clean(); stream_wrapper_restore('php'); }
    return $out;
}
function call(string $file, string $method, array $data = [], bool $json = false): array {
    $out = raw($file, $method, $data, $json);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($out, 0, 300)];
}
function val(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function worker(array $cfg): string {
    global $root;
    $f = tempnam(sys_get_temp_dir(), 'rp3'); file_put_contents($f, json_encode($cfg));
    $out = (string)shell_exec('php ' . escapeshellarg("$root/tests/test_restaurant_phase1_cli.php") . ' worker ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return $out;
}

$admin = (int)val("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1");
$_SESSION['user_id'] = $admin; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['role_id'] = 1;
$_SESSION['is_admin'] = true; $_SESSION['csrf_token'] = 'test-csrf'; $_SESSION['user_lang'] = 'en';
$GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true];
$sess = ['user_id' => $admin, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en', 'csrf_token' => 'test-csrf'];
$ON = ['pos' => true, 'restaurant_pos' => true]; $OFF = ['pos' => true, 'restaurant_pos' => false];

$drink = $pdo->query("SELECT ps.product_id, ps.warehouse_id, p.selling_price FROM product_stocks ps JOIN products p ON p.product_id = ps.product_id
               WHERE ps.stock_quantity >= 10 AND p.is_service = 0 AND COALESCE(p.is_combo,0) = 0 AND p.selling_price > 0 AND p.status = 'active'
                 AND p.selling_price >= p.min_selling_price
                 AND NOT EXISTS (SELECT 1 FROM product_serials sn WHERE sn.product_id = p.product_id)
            ORDER BY ps.stock_quantity DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$drink) { ok(false, 'fixture: a stocked product to sell'); exit; }
$W = (int)$drink['warehouse_id']; $DRINK = (int)$drink['product_id']; $DPRICE = (float)$drink['selling_price'];
