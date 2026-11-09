<?php
/**
 * Restaurant dashboard — Profit view (Sales | Orders | Profit) and the Money panel's
 * four lines (2026-11-03).
 *   php tests/test_restaurant_profit_chart_cli.php
 *
 * Static: the Profit button, panel, legends and Money panel lines are in place.
 * Real ledger: a temporary restaurant shop gets real posted entries — sales, a
 * drink's cost when sold (pos_cogs), food bought without stock (Cost of goods
 * via an expense), and an ordinary expense — then:
 *   - the split is right: drinks & stock sold / food bought / expenses;
 *   - stock + food = the Cost of goods figure; profit so far ends at the
 *     ledger's profit (rdPeriodStory, the same as the Profit tile);
 *   - the week view agrees too.
 * Everything posted is removed at the end. Exit 0 = pass.
 */
$root = dirname(__DIR__);
if (is_file("$root/includes/config.php")) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function done_exit() { global $pass, $fail; echo "\n" . ($fail === 0 ? "\033[32mALL $pass PASSED\033[0m" : "\033[31m$fail FAILED\033[0m, $pass passed") . "\n"; exit($fail === 0 ? 0 : 1); }

echo "\n── 1. Static ──\n";
$dp = (string)file_get_contents("$root/includes/restaurant/dash_panel.php");
$ri = (string)file_get_contents("$root/core/restaurant_insights.php");
ok(str_contains($dp, 'data-ct="profit"><?= t(\'Profit\') ?></button>') && str_contains($dp, '<?php if ($rdMoney): ?><button'), 'a Profit button next to Sales | Orders (only for users who see money)');
ok(str_contains($dp, 'class="rd2-bars rd2-pv d-none" data-ct="profit"'), 'the Profit panel sits in the same chart, hidden until chosen');
// Restaurant costing plan (2026-11-09): stock taken for cooking counts with stock ("used"), and spoiled stock /
// kitchen waste is its own part, shown in the legend and both money lists only when there is any.
ok(substr_count($dp, "t('Drinks & stock used')") >= 3 && substr_count($dp, "t('Food bought')") >= 3, 'cost parts named in the chart legend, tooltip and Money panel ("Drinks & stock used", "Food bought")');
ok(substr_count($dp, "t('Spoiled & waste')") >= 3 && str_contains($dp, "if (\$PT['waste'] > 0)"), '"Spoiled & waste" in the legend, tooltip and Money panel — only when there is any');
ok(!str_contains($dp, "<span><?= t('Cost of Goods') ?></span><b>− <?= \$rdFmt(\$cur['cost']) ?></b>"), 'Money panel: "Cost of goods" is now its two parts');
ok(str_contains($dp, "\$PT['food'] = round((float)\$cur['cost'] - \$PT['stock'] - \$PT['waste'], 2)"), 'the parts (stock, food, waste) always add up to the Cost of goods figure');
ok(str_contains($ri, "je.entity_type IN ('kitchen_waste','restaurant_spoilage')"), 'waste = kitchen waste + spoiled stock entries');
ok(str_contains($dp, "\$k !== 'today'") && str_contains($dp, 'Profit is not shown by hour'), 'Today: no hourly profit (it would mislead), a plain breakdown instead');
ok(str_contains($dp, "#dc3545") && str_contains($dp, 'Loss so far'), 'the line is red while still at a loss');
ok(str_contains($ri, "je.entity_type LIKE '%\\\\_cogs%'"), 'drinks & stock sold = cost posted with the sale (*_cogs entries)');
ok(str_contains($ri, "'p_tot'") && str_contains($ri, "'p_cum'"), 'series carry the period totals and the running profit');

if (!is_file("$root/includes/config.php")) { echo "  (no includes/config.php — ledger checks skipped)\n"; done_exit(); }

require_once "$root/core/restaurant_insights.php";
require_once "$root/core/ledger_post.php";
require_once "$root/core/gl_accounts.php";
require_once "$root/core/restaurant_scope.php";
require_once "$root/core/sales_posting.php";   // posReceiptAccountId()
global $pdo;

echo "\n── 2. Real ledger: the split and the totals ──\n";
$admin = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 AND is_active = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO warehouses (warehouse_code, warehouse_name, status, pos_mode) VALUES (?, 'ZZ RP Test Restaurant', 'active', 'restaurant')")->execute(['ZZRP' . random_int(1000, 9999)]);
$wid = (int)$pdo->lastInsertId();
$entries = [];
register_shutdown_function(function () use ($pdo, &$entries, $wid) {
    if ($entries) {
        $in = implode(',', array_map('intval', $entries));
        $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id IN ($in)");
        $pdo->exec("DELETE FROM journal_entries WHERE entry_id IN ($in)");
    }
    $pdo->exec("DELETE FROM warehouses WHERE warehouse_id = " . (int)$wid);
});

$rev  = salesRevenueAccountId($pdo);
$cash = (int)(posReceiptAccountId($pdo, 'cash') ?: 0);
$inv  = inventoryAccountId($pdo);
$cogs = cogsAccountId($pdo);
$food = restaurantIngredientsAccountId($pdo) ?: $cogs;
$misc = miscExpenseAccountId($pdo);
ok($rev && $cash && $inv && $cogs && $food && $misc, 'accounts found (sales, cash, stock, cost of goods, food purchases, expenses)');
$cat = fn($id) => (string)$pdo->query("SELECT at.category FROM accounts a JOIN account_types at ON at.type_id = a.account_type_id WHERE a.account_id = " . (int)$id)->fetchColumn();
ok($cat($food) === 'cogs', 'food bought is posted to a Cost-of-goods account (' . $cat($food) . ')');

$dayA = date('Y-m-01');
$dayB = date('Y-m-d');
$post = function (string $d, array $lines, string $et, string $desc) use ($pdo, $admin, $wid, &$entries) {
    $entries[] = postLedgerEntry($pdo, $desc, $lines, null, random_int(900000, 999999), $et, $d, $admin, $wid);
};
$post($dayA, [['account_id' => $cash, 'type' => 'debit', 'amount' => 1000], ['account_id' => $rev, 'type' => 'credit', 'amount' => 1000]], 'pos_sale', 'ZZ RP sale A');
$post($dayA, [['account_id' => $cogs, 'type' => 'debit', 'amount' => 300], ['account_id' => $inv, 'type' => 'credit', 'amount' => 300]], 'pos_cogs', 'ZZ RP drinks sold');
$post($dayA, [['account_id' => $food, 'type' => 'debit', 'amount' => 200], ['account_id' => $cash, 'type' => 'credit', 'amount' => 200]], 'expense', 'ZZ RP food bought');
$post($dayB, [['account_id' => $misc, 'type' => 'debit', 'amount' => 150], ['account_id' => $cash, 'type' => 'credit', 'amount' => 150]], 'expense', 'ZZ RP gas');
$post($dayB, [['account_id' => $cash, 'type' => 'debit', 'amount' => 500], ['account_id' => $rev, 'type' => 'credit', 'amount' => 500]], 'pos_sale', 'ZZ RP sale B');
ok(count($entries) === 5, '5 entries posted to the ledger for the test shop');

$scope = ' AND je.warehouse_id IN (' . $wid . ')';
foreach (['month' => 'This month', 'week' => 'This week'] as $per => $lbl) {
    $r = rdPeriodRange($per);
    if ($per === 'week' && $dayA < $r['from']) { echo "  (week view skipped: the 1st of the month is not in this week)\n"; continue; }
    $S = rdCompareSeries($pdo, $scope, [$wid], $r);
    $story = rdPeriodStory($pdo, $scope, [$wid], $r);
    $cur = $story['cur'];
    ok(abs($S['p_tot']['stock'] - 300) < 0.01 && abs($S['p_tot']['food'] - 200) < 0.01 && abs($S['p_tot']['exp'] - 150) < 0.01,
       "$lbl: drinks & stock sold 300 · food bought 200 · expenses 150 (got " . json_encode($S['p_tot']) . ')');
    ok(abs(($S['p_tot']['stock'] + $S['p_tot']['food']) - $cur['cost']) < 0.01, "$lbl: the two parts add up to Cost of goods ({$cur['cost']})");
    ok(abs($cur['profit'] - 850) < 0.01, "$lbl: ledger profit = 1,500 − 300 − 200 − 150 = 850 (got {$cur['profit']})");
    $last = end($S['p_cum']);
    ok(abs($last - $cur['profit']) < 0.01, "$lbl: the profit-so-far line ends at the Profit figure ($last)");
    ok(count($S['p_cum']) === count($S['labels']) && count($S['p_stock']) === count($S['labels']), "$lbl: one value per day on the chart");
    if ($per === 'month' && $dayA !== $dayB) {
        ok(abs($S['p_cum'][0] - 500) < 0.01, '   …after day 1: 1,000 − 300 − 200 = 500 so far (got ' . $S['p_cum'][0] . ')');
    }
}
$rT = rdPeriodRange('today');
$ST = rdCompareSeries($pdo, $scope, [$wid], $rT);
ok(isset($ST['p_tot']) && abs($ST['p_tot']['exp'] - 150) < 0.01, "Today: the breakdown totals are there (expenses 150 recorded today)");
done_exit();
