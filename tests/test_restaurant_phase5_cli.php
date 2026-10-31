<?php
/**
 * tests/test_restaurant_phase5_cli.php — restaurant_plan.md Phase 5 (Buy Ingredients).
 *
 *   A. restaurantIngredientsAccountId() resolves to 5-2000 Purchases (Cost of Sales).
 *   B. A real "Buy Ingredients" save through api/add_expense.php: paid expense on the
 *      restaurant shop, debited to Purchases, posted to the ledger; Cost of Sales in the
 *      Income Statement (glProfitLoss) rises by exactly the amount; ledger balanced.
 *   C. Renders: Expenses page + restaurant hub show "Buy Ingredients" (and its window) only
 *      when the restaurant module is on; the retail Expenses page is unchanged when off.
 *
 * B runs in-process inside one rolled-back transaction. Exit 0 = all pass.
 */
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/restaurant_scope.php";
require_once "$root/core/financial_reports.php";

section('A. Ingredients account');
$acc = restaurantIngredientsAccountId($pdo);
$code = val("SELECT account_code FROM accounts WHERE account_id = ?", [$acc]);
ok($code === '5-2000', "ingredients post to Cost of Sales — 5-2000 Purchases [$code]");

$pdo->startOuter();
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);
    $today = date('Y-m-d');
    $before = glProfitLoss($pdo, $today, $today, null, '', null);

    section('B. Buy Ingredients → Cost of Sales');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $out = raw('api/add_expense.php', 'POST', [
        'expense_date' => $today, 'amount' => 60000, 'description' => 'Ingredients: Nyama kilo 5',
        'expense_account_id' => $acc, 'warehouse_id' => $W, 'status' => 'paid',
    ]);
    $res = json_decode($out, true);
    ok(is_array($res) && !empty($res['success']), 'ingredients purchase saved' . (is_array($res) && !empty($res['success']) ? '' : ' — ' . substr($out, 0, 300)));
    $eid = (int)($res['expense_id'] ?? val("SELECT MAX(expense_id) FROM expenses"));
    $e = $pdo->query("SELECT * FROM expenses WHERE expense_id = $eid")->fetch(PDO::FETCH_ASSOC);
    ok($e && (int)$e['expense_account_id'] === $acc && (int)$e['warehouse_id'] === $W && (float)$e['amount'] == 60000.0, 'expense on the restaurant shop, account Purchases, 60,000');
    ok(($e['status'] ?? '') === 'paid', 'paid straight away (Smart POS, cash)');
    $dr = (float)val("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                       WHERE je.status = 'posted' AND jei.account_id = ? AND jei.type = 'debit' AND je.entity_id = ? AND je.entity_type LIKE 'expense%'", [$acc, $eid]);
    ok(abs($dr - 60000) < 0.01, 'ledger: Purchases debited 60,000 (posted)');
    $after = glProfitLoss($pdo, $today, $today, null, '', null);
    ok(abs(($after['total_cogs'] - $before['total_cogs']) - 60000) < 0.01, 'Income Statement: Cost of Sales up by exactly 60,000');
    ok(isset($after['total_expense']) && abs($after['total_expense'] - $before['total_expense']) < 0.01, 'operating expenses unchanged (not counted twice)');
    $bal = assertLedgerBalanced($pdo);
    ok(!empty($bal['ledger_balanced']) && !empty($bal['bs_balanced']), 'ledger balanced');
} finally {
    $pdo->endOuter();
}

section('C. Renders');
$render = function (string $page, array $features) use ($W, $admin) {
    $pdo2 = $GLOBALS['pdo'];
    $orig = $pdo2->query("SELECT pos_mode FROM warehouses WHERE warehouse_id = $W")->fetchColumn();
    $origSimple = $pdo2->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
    $pdo2->exec("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = $W");
    $pdo2->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $out = worker(['page' => $page, 'session' => ['user_id' => $admin, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'], 'features' => $features]);
    $pdo2->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$orig, $W]);
    if ($origSimple === false) $pdo2->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    else $pdo2->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$origSimple]);
    return $out;
};
$clean = fn($o) => !preg_match('/PHP (Warning|Notice|Fatal)|___EXC___/', explode('___OUT___', $o)[0] ?? '');
$on = $render('app/constant/accounts/expenses.php', ['pos' => true, 'restaurant_pos' => true]);
ok($clean($on) && strpos($on, 'onclick="rbiOpen()"') !== false && strpos($on, 'id="rbiModal"') !== false && strpos($on, 'name="expense_account_id" value="' . $acc . '"') !== false, 'Expenses page (module on): Buy Ingredients button + window, posting to Purchases');
$off = $render('app/constant/accounts/expenses.php', ['pos' => true, 'restaurant_pos' => false]);
ok($clean($off) && strpos($off, 'rbiOpen') === false && strpos($off, 'rbiSaved') === false, 'Expenses page (module off): no restaurant button, window or script — retail unchanged');
$hub = $render('app/bms/restaurant/index.php', ['pos' => true, 'restaurant_pos' => true]);
ok($clean($hub) && strpos($hub, 'onclick="rbiOpen()"') !== false && strpos($hub, 'id="rbiModal"') !== false, 'restaurant hub: Buy Ingredients');
