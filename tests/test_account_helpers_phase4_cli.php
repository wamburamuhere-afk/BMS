<?php
/**
 * Chart of Accounts upgrade — Phase 4 (canonical helpers) CLI test
 * ----------------------------------------------------------------
 *   php tests/test_account_helpers_phase4_cli.php
 *
 * Verifies the new account-slice helpers in core/payment_source.php:
 *   - expenseAccounts()  → active accounts where category IN (expense, finance_cost)
 *   - incomeAccounts()   → active accounts where category = revenue
 *   - allActiveAccounts()→ every active account + type/category/tree metadata
 *   - cashBankAccounts() → still works (unchanged)
 * and proves a finance_cost account is included in expenseAccounts() (the key
 * "no hanging" fix), using a rolled-back insert when none exists yet.
 *
 * Writes only inside a transaction that is always rolled back. Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function idset(array $rows): array { return array_map(fn($r) => (int)$r['account_id'], $rows); }

register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Helpers are defined + lint-clean');
    // ─────────────────────────────────────────────────────────────────────
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/core/payment_source.php") . ' 2>&1', $out, $rc);
    ok($rc === 0, 'core/payment_source.php lint-clean');
    foreach (['expenseAccounts', 'incomeAccounts', 'allActiveAccounts', 'cashBankAccounts'] as $fn) {
        ok(function_exists($fn), "function $fn() is defined");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. expenseAccounts() = active expense + finance_cost, nothing else');
    // ─────────────────────────────────────────────────────────────────────
    $exp = expenseAccounts($pdo);
    $expectedExp = $pdo->query("
        SELECT a.account_id FROM accounts a
          JOIN account_types at ON a.account_type_id = at.type_id
         WHERE a.status = 'active' AND at.category IN ('expense','finance_cost')
    ")->fetchAll(PDO::FETCH_COLUMN);
    sort($expectedExp);
    $gotExp = idset($exp); sort($gotExp);
    ok($gotExp === array_map('intval', $expectedExp), 'expenseAccounts id-set matches expected (' . count($gotExp) . ')');
    // none from a different category
    if ($exp) {
        $ph = implode(',', array_fill(0, count($gotExp), '?'));
        $bad = (int)$pdo->prepare("
            SELECT COUNT(*) FROM accounts a JOIN account_types at ON a.account_type_id = at.type_id
             WHERE a.account_id IN ($ph) AND (at.category NOT IN ('expense','finance_cost') OR a.status <> 'active')
        ")->execute($gotExp) === false ? -1 : 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts a JOIN account_types at ON a.account_type_id = at.type_id WHERE a.account_id IN ($ph) AND (at.category NOT IN ('expense','finance_cost') OR a.status <> 'active')");
        $stmt->execute($gotExp);
        ok((int)$stmt->fetchColumn() === 0, 'expenseAccounts contains no wrong-category/inactive rows');
    } else {
        ok(true, 'expenseAccounts empty in this DB (n/a for purity check)');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. incomeAccounts() = active revenue only');
    // ─────────────────────────────────────────────────────────────────────
    $inc = incomeAccounts($pdo);
    $expectedInc = $pdo->query("
        SELECT a.account_id FROM accounts a
          JOIN account_types at ON a.account_type_id = at.type_id
         WHERE a.status = 'active' AND at.category = 'revenue'
    ")->fetchAll(PDO::FETCH_COLUMN);
    sort($expectedInc);
    $gotInc = idset($inc); sort($gotInc);
    ok($gotInc === array_map('intval', $expectedInc), 'incomeAccounts id-set matches expected (' . count($gotInc) . ')');

    // ─────────────────────────────────────────────────────────────────────
    section('4. allActiveAccounts() = all active + metadata keys');
    // ─────────────────────────────────────────────────────────────────────
    $all = allActiveAccounts($pdo);
    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE status = 'active'")->fetchColumn();
    ok(count($all) === $activeCount, "allActiveAccounts returns every active account ($activeCount)");
    if ($all) {
        foreach (['account_id', 'account_code', 'account_name', 'type_name', 'category', 'level', 'is_system'] as $k) {
            ok(array_key_exists($k, $all[0]), "allActiveAccounts row exposes `$k`");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    section('5. cashBankAccounts() still works (unchanged)');
    // ─────────────────────────────────────────────────────────────────────
    $cb = cashBankAccounts($pdo);
    ok(is_array($cb), 'cashBankAccounts returns an array (' . count($cb) . ' source(s))');

    // ─────────────────────────────────────────────────────────────────────
    section('6. A finance_cost account IS included in expenseAccounts() (the fix)');
    // ─────────────────────────────────────────────────────────────────────
    $fcType = (int)$pdo->query("SELECT type_id FROM account_types WHERE category = 'finance_cost' LIMIT 1")->fetchColumn();
    if ($fcType <= 0) {
        ok(true, 'no finance_cost account_type configured in this DB — inclusion check skipped (n/a)');
    } else {
        $pdo->beginTransaction();
        try {
            $code = 'TESTFC-' . substr(uniqid(), -8);
            $pdo->prepare("
                INSERT INTO accounts (account_code, account_name, account_type_id, account_type,
                    opening_balance, current_balance, level, normal_balance, status, created_at, updated_at)
                VALUES (?, ?, ?, 'expense', 0, 0, 1, 'debit', 'active', NOW(), NOW())
            ")->execute([$code, 'Phase4 Finance Cost', $fcType]);
            $fcId = (int)$pdo->lastInsertId();

            $expNow = idset(expenseAccounts($pdo));
            ok(in_array($fcId, $expNow, true), "finance_cost account #$fcId appears in expenseAccounts()");

            $incNow = idset(incomeAccounts($pdo));
            ok(!in_array($fcId, $incNow, true), 'finance_cost account does NOT leak into incomeAccounts()');

            $pdo->rollBack();
            ok(!$pdo->inTransaction(), 'rolled back — no test account left behind');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ok(false, 'finance_cost inclusion probe threw: ' . $e->getMessage());
        }
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
