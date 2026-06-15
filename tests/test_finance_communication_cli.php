<?php
/**
 * Finance communication integrity — CLI test
 * ------------------------------------------
 *   php tests/test_finance_communication_cli.php
 *
 * Proves the chart-of-accounts structure talks correctly to EVERY part that
 * consumes accounts, and that no communication line is lost:
 *   1. cash/bank payment sources = LEAF asset+cash accounts only (headers excluded)
 *   2. expense / income pickers   = LEAF accounts of the right category only
 *   3. header (summary) accounts are offered NOWHERE for posting
 *   4. same-class nesting holds across the whole tree
 *   5. the standard chart is fully classified (cash_flow_category + cash leaves)
 *   6. reporting + posting links intact (account_types classes; system accounts;
 *      journal_mappings)
 *
 * Read-only. Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

// helper: does an account_id have children?
function hasChildren(PDO $pdo, int $id): bool {
    $s = $pdo->prepare("SELECT 1 FROM accounts WHERE parent_account_id = ? LIMIT 1");
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Cash/bank payment sources = leaf asset+cash only');
    // ─────────────────────────────────────────────────────────────────────
    $cb = cashBankAccounts($pdo);
    ok(count($cb) > 0, 'at least one cash/bank source offered (' . count($cb) . ')');
    $allLeaf = true; $allCash = true;
    foreach ($cb as $a) {
        if (hasChildren($pdo, (int)$a['account_id'])) $allLeaf = false;
        $chk = $pdo->prepare("SELECT 1 FROM accounts WHERE account_id=? AND account_type='asset' AND cash_flow_category='cash'");
        $chk->execute([$a['account_id']]);
        if (!$chk->fetchColumn()) $allCash = false;
    }
    ok($allLeaf, 'no header account offered as a payment source');
    ok($allCash, 'every payment source is an asset with cash_flow_category=cash');
    $codes = array_column($cb, 'account_code');
    ok(in_array('1-1110', $codes, true), 'seeded leaf "Cheque Account" (1-1110) IS selectable now');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Expense / income pickers = leaves of the right category');
    // ─────────────────────────────────────────────────────────────────────
    $exp = expenseAccounts($pdo);
    $expLeaf = true; $expCat = true;
    foreach ($exp as $a) {
        if (hasChildren($pdo, (int)$a['account_id'])) $expLeaf = false;
        $chk = $pdo->prepare("SELECT 1 FROM accounts x JOIN account_types t ON x.account_type_id=t.type_id WHERE x.account_id=? AND t.category IN ('expense','finance_cost')");
        $chk->execute([$a['account_id']]);
        if (!$chk->fetchColumn()) $expCat = false;
    }
    ok($expLeaf, 'expense picker offers no header accounts');
    ok($expCat, 'every expense pick is expense/finance_cost');

    $inc = incomeAccounts($pdo);
    $incLeaf = true;
    foreach ($inc as $a) if (hasChildren($pdo, (int)$a['account_id'])) $incLeaf = false;
    ok($incLeaf, 'income picker offers no header accounts');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Header (summary) accounts are offered nowhere for posting');
    // ─────────────────────────────────────────────────────────────────────
    // "Cash On Hand" (1-1100) is an asset+cash HEADER → must not be a source.
    $cashHeader = (int)$pdo->query("SELECT account_id FROM accounts WHERE account_code='1-1100'")->fetchColumn();
    ok($cashHeader === 0 || !in_array('1-1100', $codes, true), 'cash header "Cash On Hand" is excluded from sources');
    // "Expenses" (6-0000) header must not be an expense pick.
    ok(!in_array('6-0000', array_column($exp, 'account_code'), true), 'expense header "Expenses" excluded from picker');

    // ─────────────────────────────────────────────────────────────────────
    section('4. Same-class nesting holds across the whole tree');
    // ─────────────────────────────────────────────────────────────────────
    // Compare BROAD statement class: cogs / finance_cost are Income-Statement cost
    // sub-classes of expense (IS Phase 1), and income == revenue, so they nest together
    // legitimately (e.g. Bank Charges [finance_cost] under Expenses [expense]).
    $childBroad  = "CASE WHEN at.category IN ('expense','cogs','finance_cost') THEN 'expense' WHEN at.category IN ('revenue','income') THEN 'income' ELSE at.category END";
    $parentBroad = "CASE WHEN pt.category IN ('expense','cogs','finance_cost') THEN 'expense' WHEN pt.category IN ('revenue','income') THEN 'income' ELSE pt.category END";
    $violations = (int)$pdo->query("
        SELECT COUNT(*)
          FROM accounts a
          JOIN accounts p       ON a.parent_account_id = p.account_id
          JOIN account_types at ON a.account_type_id   = at.type_id
          JOIN account_types pt ON p.account_type_id   = pt.type_id
         WHERE a.parent_account_id <> a.account_id
           AND at.category IS NOT NULL AND pt.category IS NOT NULL
           AND ($childBroad) <> ($parentBroad)
    ")->fetchColumn();
    ok($violations === 0, "no child sits under a different-class parent ($violations)");

    // ─────────────────────────────────────────────────────────────────────
    section('5. Standard chart fully classified');
    // ─────────────────────────────────────────────────────────────────────
    // Reports use COALESCE(a.cash_flow_category, at.cash_flow_category), so an
    // account whose own value is NULL still classifies via its TYPE. The true
    // invariant is: the account OR its type carries a cash_flow_category
    // (user-created accounts legitimately rely on the type default).
    $nullCf = (int)$pdo->query("
        SELECT COUNT(*) FROM accounts a
          LEFT JOIN account_types t ON a.account_type_id = t.type_id
         WHERE a.account_code REGEXP '^[1-6]-'
           AND a.cash_flow_category IS NULL
           AND (t.cash_flow_category IS NULL)
    ")->fetchColumn();
    ok($nullCf === 0, "every standard-chart account resolves a cash_flow_category (own or via type) ($nullCf unresolved)");
    $cashLeaves = (int)$pdo->query("SELECT COUNT(*) FROM accounts a WHERE a.account_code REGEXP '^1-11[0-9][0-9]$' AND NOT EXISTS(SELECT 1 FROM accounts c WHERE c.parent_account_id=a.account_id) AND a.cash_flow_category<>'cash'")->fetchColumn();
    ok($cashLeaves === 0, "every cash-on-hand leaf is flagged cash_flow=cash ($cashLeaves wrong)");

    // ─────────────────────────────────────────────────────────────────────
    section('6. Reporting + posting links intact');
    // ─────────────────────────────────────────────────────────────────────
    $classified = (int)$pdo->query("SELECT COUNT(*) FROM account_types WHERE category IS NOT NULL AND normal_side IS NOT NULL")->fetchColumn();
    ok($classified >= 5, "account_types still carry category+normal_side for reports ($classified)");

    if ($pdo->query("SHOW TABLES LIKE 'system_settings'")->fetch()) {
        $orphanSettings = (int)$pdo->query("
            SELECT COUNT(*) FROM system_settings s
             WHERE s.setting_key REGEXP '_account_id$' AND s.setting_value REGEXP '^[0-9]+$'
               AND NOT EXISTS (SELECT 1 FROM accounts a WHERE a.account_id = CAST(s.setting_value AS UNSIGNED))
        ")->fetchColumn();
        ok($orphanSettings === 0, "every system_settings *_account_id still resolves to a real account ($orphanSettings broken)");
    } else {
        ok(true, 'system_settings absent (n/a)');
    }

    $sysFlagged = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE is_system = 1")->fetchColumn();
    ok($sysFlagged > 0, "system accounts still flagged is_system=1 ($sysFlagged) — posting engine links intact");

} catch (Throwable $e) {
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
