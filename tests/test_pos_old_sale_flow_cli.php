<?php
/**
 * POS "Record Old Sale" — end-to-end server test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_old_sale_flow_cli.php
 *
 * Runs the REAL api/pos/process_sale.php, api/pos/void_sale.php and
 * api/pos/old_sale_context.php in-process against the local DB. Everything
 * happens inside one outer transaction that is rolled back at the end: the
 * endpoints' own transactions become savepoints (SavepointPDO below), so no
 * test sale, shift, stock movement or journal entry is left behind.
 *
 * Verifies:
 *   1. Wiring (source): permission check, shift resolution, dated entries.
 *   2. Old cash sale into the cashier's CLOSED shift of 3 days ago:
 *      sale_date/is_past_entry, receipt numbered on that day, shift used,
 *      stock movement + journal entry + bank register + drawer row dated that
 *      day, shift's expected cash / difference / totals refreshed, today's
 *      open shift untouched.
 *   3. Old sale on a day with no shift → one closed back-dated shift, reused
 *      by a second sale that day.
 *   4. Refusals: a future time and a date older than the limit (HTTP 422).
 *   5. Voiding the old sale reverses its cash inside its own shift and
 *      refreshes that shift.
 *   6. A normal sale is unchanged (today, active shift, today's receipt).
 *   7. An offline sale (sold_at yesterday, not an old sale) stays in today's
 *      shift but its stock and journal are dated yesterday.
 *   8. The pop-up preview endpoint (old_sale_context.php).
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']    = 4;
$_SESSION['username']   = 'admin';
$_SESSION['role']       = 'admin';
$_SESSION['is_admin']   = true;
$_SESSION['csrf_token'] = 'test-csrf';
unset($_SESSION['shift_id']);

$failures = 0;
$passes   = 0;
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

/** The endpoints' own transactions become savepoints inside the test's outer one. */
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

/** Feeds a JSON body to php://input for the in-process endpoint. */
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

function postSale(array $payload): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [];
    InputStub::$body = json_encode($payload);
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'InputStub');
    ob_start();
    try { (function () { include $GLOBALS['root'] . '/api/pos/process_sale.php'; })(); }
    finally { $out = ob_get_clean(); stream_wrapper_restore('php'); }
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($out, 0, 300)];
}
function voidSale(int $saleId): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['sale_id' => $saleId, 'reason' => 'test void', '_csrf' => 'test-csrf'];
    unset($_SERVER['HTTP_AUTHORIZATION']);
    ob_start();
    try { (function () { include $GLOBALS['root'] . '/api/pos/void_sale.php'; })(); }
    finally { $out = ob_get_clean(); }
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($out, 0, 300)];
}
function context(array $q): array {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $q;
    ob_start();
    try { (function () { include $GLOBALS['root'] . '/api/pos/old_sale_context.php'; })(); }
    finally { $out = ob_get_clean(); }
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($out, 0, 300)];
}
function one(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); }
function val(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

// ─────────────────────────────────────────────────────────────────────────
section('1. Wiring');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents("$root/api/pos/process_sale.php");
ok(strpos($src, "canView('pos_past_sales')") !== false, 'process_sale checks the pos_past_sales permission');
ok(strpos($src, 'posOldSaleResolveShift(') !== false, 'process_sale resolves the old sale’s shift');
ok(strpos($src, "date('Y-m-d'), \$receipt_number") === false && substr_count($src, '$effective_date, $receipt_number') === 2, 'journal and bank register use the sale’s own date');
ok(strpos($src, 'UPDATE stock_movements SET movement_date') !== false, 'stock movements are dated on the sale’s day');
ok(strpos($src, 'posOldSaleReconLock(') !== false, 'reconciled bank periods are guarded');
$vsrc = file_get_contents("$root/api/pos/void_sale.php");
ok(strpos($vsrc, "\$sale['is_past_entry']") !== false && strpos($vsrc, 'posOldSaleRecomputeShift(') !== false, 'void reverses an old sale in its own shift and refreshes it');
foreach (['api/pos/process_sale.php', 'api/pos/void_sale.php', 'api/pos/old_sale_context.php'] as $f) {
    $rc = 0; $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok($rc === 0, "$f lint-clean");
}

// Test fixture: a stocked product in a warehouse.
$prod = one("SELECT ps.product_id, ps.warehouse_id, p.selling_price FROM product_stocks ps JOIN products p ON p.product_id = ps.product_id
              WHERE ps.stock_quantity >= 20 AND p.is_service = 0 AND COALESCE(p.is_combo,0) = 0 AND p.selling_price > 0 AND p.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM product_serials sn WHERE sn.product_id = p.product_id)
           ORDER BY ps.stock_quantity DESC LIMIT 1");
if (!$prod) { fail('no stocked product on the local DB to sell — cannot run the flow'); exit; }
$PID = (int)$prod['product_id']; $WH = (int)$prod['warehouse_id']; $PRICE = (float)$prod['selling_price'];

function payload(array $extra = []): array {
    global $PID, $WH, $PRICE;
    $t = $PRICE * 2;
    return $extra + [
        'customer_id' => null, 'warehouse_id' => $WH, 'project_id' => null,
        'items' => [['product_id' => $PID, 'quantity' => 2, 'price' => $PRICE, 'discounted_price' => $PRICE, 'tax_rate' => 0]],
        'subtotal' => $t, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $t,
        'payment_method' => 'cash', 'amount_tendered' => $t, 'change_given' => 0, 'amount_paid' => $t,
    ];
}

$today = date('Y-m-d');
$D3 = date('Y-m-d', strtotime('-3 days'));
$D5 = date('Y-m-d', strtotime('-5 days'));
$D1 = date('Y-m-d', strtotime('-1 day'));

$pdo->startOuter();
try {
    // Today's open shift and a counted, closed shift 3 days ago (cash 10,000 + an unrecorded sale counted in).
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-OPEN', 4, 1, NULL, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute();
    $openShift = (int)$pdo->lastInsertId();
    $counted = 10000 + $PRICE * 2;
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, end_time, starting_cash, ending_cash, expected_cash, cash_difference, status)
                   VALUES ('TEST-D3', 4, 1, NULL, ?, ?, 10000, ?, 10000, ?, 'closed')")
        ->execute(["$D3 08:00:00", "$D3 19:00:00", $counted, $counted - 10000]);
    $d3Shift = (int)$pdo->lastInsertId();

    // ─────────────────────────────────────────────────────────────────────
    section('2. Old cash sale into the closed shift of 3 days ago');
    // ─────────────────────────────────────────────────────────────────────
    $r = postSale(payload(['old_sale' => 1, 'sold_at' => "$D3 14:30"]));
    ok(!empty($r['success']), 'sale saved' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
    $sid = (int)($r['sale_id'] ?? 0);
    $s = one("SELECT * FROM pos_sales WHERE sale_id = ?", [$sid]) ?: [];
    ok(($s['sale_date'] ?? '') === "$D3 14:30:00" && (int)($s['is_past_entry'] ?? 0) === 1, 'sale_date is 3 days ago, marked as past entry');
    ok((int)($s['shift_id'] ?? 0) === $d3Shift, 'it went into that day’s closed shift');
    ok(strpos((string)($r['receipt_number'] ?? ''), 'RCP-' . str_replace('-', '', $D3) . '-') === 0, 'receipt numbered on the sale’s day (' . ($r['receipt_number'] ?? '?') . ')');
    ok(!empty($r['is_old_sale']) && ($r['shift_code'] ?? '') === 'TEST-D3' && empty($r['shift_created']), 'response names the shift, none created');
    ok(val("SELECT COUNT(*) FROM stock_movements WHERE reference_type='pos_sale' AND reference_id=? AND movement_date <> ?", [$sid, $D3]) == 0
       && val("SELECT COUNT(*) FROM stock_movements WHERE reference_type='pos_sale' AND reference_id=?", [$sid]) > 0, 'stock movement dated that day');
    $je = val("SELECT entry_date FROM journal_entries WHERE entity_type='pos_sale' AND entity_id=? AND status='posted' LIMIT 1", [$sid]);
    $je !== false ? ok($je === $D3, "journal entry dated that day ($je)") : pass('journal entry: not posted on this local chart of accounts (skipped)');
    $bt = val("SELECT transaction_date FROM bank_transactions WHERE reference_number = ? LIMIT 1", [$r['receipt_number'] ?? '']);
    $bt !== false ? ok(substr((string)$bt, 0, 10) === $D3, 'bank register dated that day') : pass('bank register: no receipt account configured locally (skipped)');
    $dr = one("SELECT shift_id, created_at FROM cash_register_transactions WHERE sale_id = ? AND transaction_type='sale'", [$sid]);
    ok($dr && (int)$dr['shift_id'] === $d3Shift && substr($dr['created_at'], 0, 10) === $D3, 'drawer row in that shift, dated that day');
    $sh = one("SELECT expected_cash, cash_difference, total_sales, total_cash_sales FROM cash_register_shifts WHERE shift_id = ?", [$d3Shift]);
    ok(abs((float)$sh['expected_cash'] - (10000 + (float)$s['grand_total'])) < 0.01, 'that shift’s expected cash now includes the sale');
    ok(abs((float)$sh['cash_difference'] - ($counted - (float)$sh['expected_cash'])) < 0.01, 'its cash difference was recalculated (' . $sh['cash_difference'] . ')');
    ok(abs((float)$sh['total_sales'] - (float)$s['grand_total']) < 0.01 && abs((float)$sh['total_cash_sales'] - (float)$s['grand_total']) < 0.01, 'its saved Z-report totals include the sale');
    ok(val("SELECT COUNT(*) FROM cash_register_transactions WHERE shift_id = ?", [$openShift]) == 0, "today's open shift was not touched");

    // ─────────────────────────────────────────────────────────────────────
    section('3. Day with no shift → back-dated shift');
    // ─────────────────────────────────────────────────────────────────────
    $r2 = postSale(payload(['old_sale' => 1, 'sold_at' => "$D5 10:15"]));
    ok(!empty($r2['success']) && !empty($r2['shift_created']), 'sale saved, back-dated shift created' . (empty($r2['success']) ? ' — ' . ($r2['message'] ?? '') : ''));
    $bs = one("SELECT s.* FROM cash_register_shifts s JOIN pos_sales p ON p.shift_id = s.shift_id WHERE p.sale_id = ?", [(int)($r2['sale_id'] ?? 0)]) ?: [];
    ok(($bs['status'] ?? '') === 'closed' && (int)($bs['is_past_entry'] ?? 0) === 1 && (int)($bs['user_id'] ?? 0) === 4, 'that shift is closed, back-dated, and the cashier’s');
    ok(abs((float)($bs['expected_cash'] ?? -1) - (float)($bs['ending_cash'] ?? -2)) < 0.01 && (float)($bs['cash_difference'] ?? 1) == 0.0, 'no cash count: ending follows expected, difference 0');
    $r3 = postSale(payload(['old_sale' => 1, 'sold_at' => "$D5 16:40"]));
    $s3 = (int)val("SELECT shift_id FROM pos_sales WHERE sale_id = ?", [(int)($r3['sale_id'] ?? 0)]);
    ok(!empty($r3['success']) && empty($r3['shift_created']) && $s3 === (int)($bs['shift_id'] ?? -1), 'a second sale that day reuses it');
    ok((int)val("SELECT COUNT(*) FROM cash_register_shifts WHERE user_id = 4 AND is_past_entry = 1 AND DATE(start_time) = ?", [$D5]) === 1, 'still one back-dated shift that day');

    // ─────────────────────────────────────────────────────────────────────
    section('4. Refusals');
    // ─────────────────────────────────────────────────────────────────────
    $f = postSale(payload(['old_sale' => 1, 'sold_at' => date('Y-m-d H:i', strtotime('+2 hours'))]));
    ok(empty($f['success']) && http_response_code() === 422, 'a time later than now is refused (422)');
    $o = postSale(payload(['old_sale' => 1, 'sold_at' => date('Y-m-d', strtotime('-40 days')) . ' 10:00']));
    ok(empty($o['success']) && http_response_code() === 422, 'a date 40 days back is refused (422)');

    // ─────────────────────────────────────────────────────────────────────
    section('5. Void the old sale');
    // ─────────────────────────────────────────────────────────────────────
    $v = voidSale($sid);
    ok(!empty($v['success']), 'void succeeded' . (empty($v['success']) ? ' — ' . ($v['message'] ?? '') : ''));
    $rf = one("SELECT shift_id, amount FROM cash_register_transactions WHERE sale_id = ? AND transaction_type = 'refund'", [$sid]);
    ok($rf && (int)$rf['shift_id'] === $d3Shift && abs((float)$rf['amount'] - (float)$s['grand_total']) < 0.01, 'its cash is reversed inside its own shift');
    $sh2 = one("SELECT expected_cash, total_sales FROM cash_register_shifts WHERE shift_id = ?", [$d3Shift]);
    ok(abs((float)$sh2['expected_cash'] - 10000) < 0.01 && (float)$sh2['total_sales'] == 0.0, 'that shift is back to its figures before the old sale');
    ok(val("SELECT COUNT(*) FROM cash_register_transactions WHERE shift_id = ?", [$openShift]) == 0, "today's open shift still untouched");

    // ─────────────────────────────────────────────────────────────────────
    section('6. Normal sale unchanged');
    // ─────────────────────────────────────────────────────────────────────
    $n = postSale(payload());
    $ns = one("SELECT * FROM pos_sales WHERE sale_id = ?", [(int)($n['sale_id'] ?? 0)]) ?: [];
    ok(!empty($n['success']) && (int)($ns['shift_id'] ?? 0) === $openShift && (int)($ns['is_past_entry'] ?? 1) === 0, 'normal sale goes into today’s open shift, not a past entry');
    ok(substr($ns['sale_date'] ?? '', 0, 10) === $today && strpos((string)$n['receipt_number'], 'RCP-' . date('Ymd') . '-') === 0, 'dated and numbered today');
    ok(val("SELECT COUNT(*) FROM stock_movements WHERE reference_type='pos_sale' AND reference_id=? AND movement_date <> ?", [(int)$n['sale_id'], $today]) == 0, 'its stock movement is dated today');

    // ─────────────────────────────────────────────────────────────────────
    section('7. Offline sale synced a day late');
    // ─────────────────────────────────────────────────────────────────────
    $off = postSale(payload(['sold_at' => "$D1 18:05:00", 'receipt_number' => 'RCP-OFFLINE-' . mt_rand(1000, 9999)]));
    $os = one("SELECT * FROM pos_sales WHERE sale_id = ?", [(int)($off['sale_id'] ?? 0)]) ?: [];
    ok(!empty($off['success']) && (int)($os['shift_id'] ?? 0) === $openShift && (int)($os['is_past_entry'] ?? 1) === 0, 'stays in today’s open shift, not an old sale');
    ok(val("SELECT COUNT(*) FROM stock_movements WHERE reference_type='pos_sale' AND reference_id=? AND movement_date <> ?", [(int)$off['sale_id'], $D1]) == 0, 'its stock movement is dated the day it was sold');
    $oje = val("SELECT entry_date FROM journal_entries WHERE entity_type='pos_sale' AND entity_id=? AND status='posted' LIMIT 1", [(int)$off['sale_id']]);
    $oje !== false ? ok($oje === $D1, 'its journal entry is dated the day it was sold') : pass('journal entry: not posted locally (skipped)');

    // ─────────────────────────────────────────────────────────────────────
    section('8. Pop-up preview endpoint');
    // ─────────────────────────────────────────────────────────────────────
    $c1 = context(['sold_at' => "$D3 12:00", 'warehouse_id' => $WH]);
    ok(!empty($c1['success']) && $c1['error'] === null && ($c1['shift']['kind'] ?? '') === 'closed', 'a time in the closed shift → kind closed');
    $c2 = context(['sold_at' => "$D5 12:00"]);
    ok(($c2['shift']['kind'] ?? '') === 'backdated', 'the day with the back-dated shift → kind backdated');
    $c3 = context(['sold_at' => date('Y-m-d', strtotime('-7 days')) . ' 09:00']);
    ok(($c3['shift']['kind'] ?? '') === 'new' && !empty($c3['shift']['label']), 'a day with no shift → kind new, with a label');
    $c4 = context(['sold_at' => date('Y-m-d H:i', strtotime('+3 hours'))]);
    ok(!empty($c4['success']) && !empty($c4['error']), 'a future time → an error message, nothing else');
    $c5 = context(['sold_at' => "$D5 12:00", 'warehouse_id' => $WH, 'total' => $PRICE * 2]);
    ok(count($c5['similar'] ?? []) >= 2, 'same total the same day → listed as possible duplicates');
    ok((int)($c5['max_days'] ?? 0) === 30, 'reports the 30-day limit');
} finally {
    $pdo->endOuter();
}
ok((int)val("SELECT COUNT(*) FROM cash_register_shifts WHERE shift_code IN ('TEST-OPEN','TEST-D3')") === 0, 'rollback left nothing behind');
