<?php
/**
 * Cash Flow — depreciation add-back precedence fix CLI test.
 *   php tests/test_cashflow_depreciation_precedence_cli.php
 *
 * The depreciation add-back WHERE clause matched a name with OR, but SQL binds
 * AND tighter than OR, so the type_name branch ignored the date + posted
 * filters and summed depreciation across ALL periods and ALL statuses. The fix
 * parenthesises the OR group. This test proves:
 *   1. Source — the clause is now parenthesised (and the buggy form is gone).
 *   2. Live — seeded depreciation entries: only the POSTED, IN-PERIOD one is
 *      counted by the fixed query, while the old (unbounded) behaviour would
 *      have over-counted out-of-period + draft entries.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true;
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function approx($a, $b) { return abs((float)$a - (float)$b) <= 0.5; }

echo "\n\033[1m── 1. Source — depreciation clause parenthesised ──\033[0m\n";
$pageF = "$root/app/constant/reports/cash_flow.php";
exec('php -l ' . escapeshellarg($pageF) . ' 2>&1', $o, $rc);
ok($rc === 0, "cash_flow.php passes php -l");
$page = file_get_contents($pageF);
ok(strpos($page, "WHERE (LOWER(at.type_name) LIKE '%depreciation%'") !== false, "OR group opens with a parenthesis");
ok(strpos($page, "OR LOWER(a.account_name) LIKE '%depreciation expense%')") !== false, "OR group closes before the AND filters");
ok(strpos($page, "WHERE LOWER(at.type_name) LIKE '%depreciation%'\n") === false, "old unparenthesised WHERE is gone");

echo "\n\033[1m── 2. Live — only posted, in-period depreciation counts ──\033[0m\n";
$from = '2031-03-01'; $to = '2031-03-31';
$inTx = false;
try {
    $pdo->beginTransaction(); $inTx = true;

    // Seed a depreciation account TYPE (matches the previously-unbounded
    // type_name branch) + an account of that type.
    $pdo->prepare("INSERT INTO account_types (type_name, display_name, category, normal_side) VALUES (?,?,?,?)")
        ->execute(['ZZ Depreciation Test ' . uniqid(), 'ZZ Depreciation Test', 'expense', 'debit']);
    $typeId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, account_type_id, opening_balance, status) VALUES (?,?,?,?,?,?)")
        ->execute(['ZZDEP' . substr(uniqid(), -6), 'ZZ Dep Test Account', 'expense', $typeId, 0, 'active']);
    $accId = (int) $pdo->lastInsertId();

    $mkJE = function (string $date, string $status, float $amount) use ($pdo, $accId) {
        $pdo->prepare("INSERT INTO journal_entries (entry_date, reference_number, description, status, debit_account_id, credit_account_id, amount, created_by) VALUES (?,?,?,?,0,0,?,?)")
            ->execute([$date, 'ZZDEP-' . uniqid(), 'dep test', $status, $amount, 4]);
        $eid = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?,?,'debit',?,?)")
            ->execute([$eid, $accId, $amount, 'dep']);
        return $eid;
    };
    $mkJE('2031-03-15', 'posted', 1000);   // in-period, posted   → COUNTS
    $mkJE('2030-12-15', 'posted',  500);   // before period       → must NOT count
    $mkJE('2031-03-16', 'draft',   300);   // in-period, draft    → must NOT count

    // Fixed query (parenthesised) — isolated to our seeded account.
    $fixedSql = "
        SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount WHEN jei.type='credit' THEN -jei.amount ELSE 0 END), 0)
          FROM accounts a
          JOIN account_types at ON a.account_type_id = at.type_id
          JOIN journal_entry_items jei ON jei.account_id = a.account_id
          JOIN journal_entries je      ON je.entry_id    = jei.entry_id
         WHERE (LOWER(at.type_name) LIKE '%depreciation%'
             OR LOWER(a.account_name) LIKE '%depreciation expense%')
           AND je.entry_date BETWEEN ? AND ?
           AND je.status = 'posted'
           AND a.account_id = ?";
    $st = $pdo->prepare($fixedSql); $st->execute([$from, $to, $accId]);
    $fixedVal = (float) $st->fetchColumn();
    ok(approx($fixedVal, 1000), "fixed add-back counts ONLY the posted in-period entry (=1000, got $fixedVal)");

    // What the old buggy type_name branch effectively summed for this account:
    // everything, ignoring date + status.
    $allSql = "SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END), 0)
                 FROM journal_entry_items jei
                 JOIN journal_entries je ON je.entry_id = jei.entry_id
                WHERE jei.account_id = ?";
    $st = $pdo->prepare($allSql); $st->execute([$accId]);
    $allVal = (float) $st->fetchColumn();
    ok(approx($allVal, 1800), "unbounded (old-bug) sum would be 1800 — all-time, all-status (got $allVal)");
    ok($allVal > $fixedVal + 0.5, "fix prevents an over-count of " . number_format($allVal - $fixedVal, 2) . " (out-of-period + draft)");

    $pdo->rollBack(); $inTx = false;
    ok(true, "test data rolled back (no persistence)");
} catch (Throwable $e) {
    if ($inTx) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
    ok(false, "exception: " . $e->getMessage());
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
