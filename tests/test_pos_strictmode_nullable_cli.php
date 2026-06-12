<?php
/**
 * POS strict-mode nullable-int guard — regression
 *   php tests/test_pos_strictmode_nullable_cli.php
 *
 * Online (STRICT_TRANS_TABLES) a POS sale failed with:
 *   "Incorrect integer value: '' for column 'warehouse_id'"
 * because the frontend sends '' for the General/No-Project/Walk-in options and
 * the code bound '' straight into INT columns. Local MySQL is non-strict, so it
 * silently coerced '' to 0 and the bug was invisible.
 *
 *   A. process_sale.php coerces customer_id / warehouse_id / project_id to NULL.
 *   B. LIVE under STRICT_TRANS_TABLES (rolled back): binding '' FAILS (reproduces
 *      the online error), binding the coerced NULL SUCCEEDS.
 *
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src($p){ return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    section('A. process_sale coerces nullable ints');
    $proc = src("$root/api/pos/process_sale.php");
    ok(strpos($proc, '$toNullableInt') !== false, 'process_sale defines a nullable-int coercion');
    foreach (['customer_id', 'warehouse_id', 'project_id'] as $col) {
        ok((bool)preg_match('/\$' . $col . '\s*=\s*\$toNullableInt\(/', $proc), "$col is coerced via \$toNullableInt");
    }
    ok(strpos($proc, "\$warehouse_id = \$input['warehouse_id'] ?? null;") === false, 'old raw "?? null" binding for warehouse_id is gone');

    section('B. Live under STRICT_TRANS_TABLES (rolled back)');
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch()) {
        ok(true, 'pos_sales absent — live strict-mode test skipped');
        exit($fail === 0 ? 0 : 1);
    }
    $pdo->beginTransaction();
    $prevMode = $pdo->query("SELECT @@session.sql_mode")->fetchColumn();
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
    $uid = (int)$pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
    // shift_id is NOT NULL on pos_sales — use a real shift so the test isolates the
    // nullable-INT (customer/warehouse/project) issue, not a shift NOT-NULL error.
    $shift = (int)($pdo->query("SELECT shift_id FROM cash_register_shifts ORDER BY shift_id LIMIT 1")->fetchColumn() ?: 0);
    if ($shift <= 0) { ok(true, 'no cash_register_shifts row — strict-mode insert test skipped'); $pdo->rollBack(); exit($fail === 0 ? 0 : 1); }

    $ins = "INSERT INTO pos_sales
              (receipt_number, shift_id, user_id, customer_id, warehouse_id, project_id,
               subtotal, discount_percentage, discount_amount, tax_amount, grand_total,
               payment_method, amount_tendered, change_given, sale_status, payment_status, sale_date, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0,0,0,0,0, 'cash', 0, 0, 'completed', 'pending', NOW(), NOW())";

    $empty = '';   // what the frontend actually sends for General/No-Project/Walk-in

    // OLD behaviour: bind '' → must fail under strict mode (reproduces the online error).
    $oldFailed = false; $msg = '';
    try {
        $pdo->prepare($ins)->execute(['T1-' . uniqid(), $shift, $uid, $empty, $empty, $empty]);
    } catch (Throwable $e) { $oldFailed = (strpos($e->getMessage(), '1366') !== false) || (stripos($e->getMessage(), 'Incorrect integer') !== false); $msg = $e->getMessage(); }
    ok($oldFailed, 'binding \'\' reproduces the online strict-mode failure (error 1366)');

    // NEW behaviour: coerce '' → NULL (same logic as the fix) → must succeed.
    $toNullableInt = function ($v) { return ($v === null || $v === '' || $v === false) ? null : (int)$v; };
    $newOk = false;
    try {
        $pdo->prepare($ins)->execute(['T2-' . uniqid(), $shift, $uid, $toNullableInt($empty), $toNullableInt($empty), $toNullableInt($empty)]);
        $newOk = true;
    } catch (Throwable $e) { $newOk = false; $msg = $e->getMessage(); }
    ok($newOk, 'coercing to NULL lets the sale insert cleanly under strict mode' . ($newOk ? '' : " — {$msg}"));

    $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($prevMode));
    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'fixture rolled back — nothing persisted');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'threw: ' . $e->getMessage());
}
exit($fail === 0 ? 0 : 1);
