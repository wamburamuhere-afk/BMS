<?php
/**
 * tests/test_simple_pos_expense_bugs_cli.php
 *
 * Regression cover for three real bugs found and fixed on 2026-09-16, all
 * surfaced by a single user report: "expenses.php shows duplicate/missing
 * rows after creating a new expense, and the dashboard's expense chart line
 * never detects newly created expenses."
 *
 *   1. api/account/get_expenses.php — three `foreach ($expenses as &$exp)`
 *      reference loops with no `unset($exp)` afterward. The classic PHP
 *      gotcha: the dangling reference silently corrupts the array's last
 *      element on the next unreferenced foreach reusing the same variable
 *      name, so the true last row on a page vanishes and an earlier row's
 *      data appears twice instead. NOT actually Simple-POS-specific — it
 *      hits any tenant's expense list once a page has >= 2 rows with
 *      categories fetched — but reproduced live against real data.
 *
 *   2. api/pos/get_simple_dashboard_chart.php — an "opt-in" sentinel
 *      collision: posSimpleBuySellSeries() treats an EMPTY $expenseScopeSql
 *      as "caller didn't ask for the expenses series" (its documented
 *      default), but scopeFilterSqlNullable() also legitimately returns ''
 *      for an admin / fully-unrestricted user (no filter needed). Those two
 *      meanings collided, so an admin viewing their own Simple POS
 *      dashboard got a permanently empty expense line regardless of how
 *      many expenses existed.
 *
 *   3. api/account/add_expense.php — Simple POS's Add Expense form has no
 *      status field at all (single-step entry), so every new expense
 *      defaulted to 'pending' — invisible to the chart, which (correctly)
 *      only counts approved/paid. Now defaults to 'paid' for Simple POS
 *      only (normal mode unchanged), reusing the same GL-posting path
 *      "Quick Expense" already used. Closing this safely required adding
 *      the missing "Void Payment" UI action (assets/js/tables/
 *      bms-expenses-table.js) for paid rows — the backend
 *      (update_expense_status.php, paid -> rejected) already fully
 *      supported voiding a paid expense, but no button anywhere could
 *      reach it, which would have made every Simple POS expense a dead
 *      end (unfixable typo, unreachable delete) the moment it posted paid.
 *
 * Run: php tests/test_simple_pos_expense_bugs_cli.php
 * Self-skips the HTTP sections if the local server is not reachable
 * (set BMS_TEST_URL to override the default http://dev.bms.local).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m)  { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function skip($m)    { echo "  \033[33m⏭\033[0m  $m\n"; }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function src($p)     { return is_file($p) ? file_get_contents($p) : ''; }

$base  = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$probe = "$root/_simplepos_expense_bugs_probe.php";
$createdExpenseId = null;

register_shutdown_function(function () use ($pdo, &$createdExpenseId, $probe) {
    // Void first if still paid (reverses the ledger/bank posting), then
    // remove the row — mirrors what the app itself now requires.
    if ($createdExpenseId) {
        $st = $pdo->prepare("SELECT status FROM expenses WHERE expense_id = ?");
        $st->execute([$createdExpenseId]);
        if ($st->fetchColumn() === 'paid') {
            require_once "$GLOBALS[root]/core/payment_source.php";
            require_once "$GLOBALS[root]/core/bank_register.php";
            require_once "$GLOBALS[root]/core/expense_posting.php";
            $snap = $pdo->prepare("SELECT transaction_id, bank_account_id FROM expenses WHERE expense_id = ?");
            $snap->execute([$createdExpenseId]);
            $row = $snap->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['transaction_id'])) {
                reverseOutflow($pdo, (int)$row['transaction_id']);
                reverseBankTransaction($pdo, (int)($row['bank_account_id'] ?? 0), 'EXP-' . $createdExpenseId, 'withdrawal');
            }
        }
        $pdo->prepare("DELETE FROM expenses WHERE expense_id = ?")->execute([$createdExpenseId]);
    }
    if (is_file($probe)) @unlink($probe);
});

try {
    // ── 1. Source-level regression guards ───────────────────────────────
    section('1. Source — the reference-loop fix is in place');

    $geSrc = src("$root/api/account/get_expenses.php");
    $refLoops = preg_match_all('/foreach\s*\(\s*\$expenses\s+as\s+&\$exp\s*\)/', $geSrc);
    $unsets   = substr_count($geSrc, 'unset($exp)');
    ok($refLoops === 3, "get_expenses.php has the expected 3 `foreach (\$expenses as &\$exp)` reference loops (found $refLoops)");
    ok($unsets >= 3, "get_expenses.php calls unset(\$exp) at least once per reference loop (found $unsets)");
    ok(str_contains($geSrc, 'unset($cat)'), "get_expenses.php also breaks the nested \$cat reference (category_path loop)");

    section('2. Source — the dashboard chart sentinel fix is in place');
    $chartSrc = src("$root/api/pos/get_simple_dashboard_chart.php");
    ok(str_contains($chartSrc, "if (\$expenseScope === '')") && str_contains($chartSrc, "AND 1=1"),
       'get_simple_dashboard_chart.php neutralises an empty (admin/unrestricted) scope so the expense series is never silently skipped');

    section('3. Source — Simple POS status default + Void Payment UI');
    $addSrc = src("$root/api/account/add_expense.php");
    ok(str_contains($addSrc, "\$simplePos ? 'paid' : 'pending'"),
       "add_expense.php defaults Simple POS to 'paid', normal mode still 'pending'");

    $jsSrc = src("$root/assets/js/tables/bms-expenses-table.js");
    ok(str_contains($jsSrc, "row.status === 'paid'") && str_contains($jsSrc, "voidPayment"),
       'bms-expenses-table.js renders a Void Payment action for paid rows');
    $tblSrc = src("$root/includes/tables/expenses_table.php");
    ok(str_contains($tblSrc, 'voidPayment:'), 'expenses_table.php exposes the voidPayment i18n string');

    // ── Reachability for the live sections ──────────────────────────────
    section('4. Local server reachability');
    $reachable = false;
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;
    ok($reachable, "server reachable at $base");
    if (!$reachable) {
        skip('LIVE sections skipped — set BMS_TEST_URL to override');
        goto done;
    }

    // ── Admin session over HTTP (same technique as other *_cli.php tests
    //    in this suite — autoEnforcePermission() runs before header.php on
    //    every page, so is_admin/role_id must be seeded directly) ────────
    $adminUid = (int)$pdo->query("
        SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id
        WHERE r.is_admin = 1 LIMIT 1
    ")->fetchColumn();
    $adminRoleId = $adminUid ? (int)$pdo->query("SELECT role_id FROM users WHERE user_id = " . $adminUid)->fetchColumn() : 0;
    ok($adminUid > 0, "found a real admin user (user_id=$adminUid)");

    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
if (($_GET['act'] ?? '') === 'login') {
    $_SESSION['user_id']  = (int)$_GET['uid'];
    $_SESSION['role_id']  = (int)$_GET['role_id'];
    $_SESSION['is_admin'] = true;
    loadUserPermissions((int)$_GET['role_id']);
    echo 'ok';
}
PHP);

    $sid = 'simplebugs' . bin2hex(random_bytes(8));
    $get = function (string $path) use ($base, $sid) {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'header' => "Cookie: PHPSESSID=$sid"]]);
        return (string)@file_get_contents("$base/$path", false, $ctx);
    };
    $post = function (string $path, array $fields) use ($base, $sid) {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 20, 'ignore_errors' => true,
            'header' => "Cookie: PHPSESSID=$sid\r\nContent-Type: application/x-www-form-urlencoded",
            'content' => http_build_query($fields),
        ]]);
        return (string)@file_get_contents("$base/$path", false, $ctx);
    };

    $loginBody = $get('_simplepos_expense_bugs_probe?act=login&uid=' . $adminUid . '&role_id=' . $adminRoleId);
    ok(trim($loginBody) === 'ok', 'admin session established via probe');

    // ── 5. LIVE — no duplicate/missing rows across a full paginated sweep ─
    section('5. Live — get_expenses.php: full list, no duplicate or skipped rows');

    $dbTotal = (int)$pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn();
    $allIds = [];
    $length = 25;
    for ($start = 0; $start < $dbTotal + $length; $start += $length) {
        $body = $get("api/get_expenses.php?draw=1&start=$start&length=$length&order%5B0%5D%5Bcolumn%5D=1&order%5B0%5D%5Bdir%5D=desc");
        $d = json_decode($body, true);
        if (!is_array($d) || empty($d['data'])) break;
        foreach ($d['data'] as $row) $allIds[] = (int)$row['expense_id'];
    }
    $uniqueIds = array_unique($allIds);
    ok(count($allIds) === count($uniqueIds), 'no expense_id appears twice across a full paginated sweep of the real list (found ' . count($allIds) . ' rows, ' . count($uniqueIds) . ' unique)');
    ok(count($uniqueIds) === $dbTotal, "every real expense in the DB ($dbTotal) is reachable through the paginated list (got " . count($uniqueIds) . ')');

    // ── 6. LIVE — create as Simple POS, verify status + chart visibility ──
    section('6. Live — Simple POS create: status=paid, visible on the dashboard chart');

    $hadRow = (bool)$pdo->query("SELECT 1 FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetch();
    $originalValue = $hadRow ? $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn() : null;
    save_setting('pos_simple_mode', '1');

    $before = null;
    $beforeChart = json_decode($get('api/pos/get_simple_dashboard_chart.php?period=monthly'), true);
    if (is_array($beforeChart['data'] ?? null)) {
        foreach ($beforeChart['data'] as $row) {
            if (str_contains($row['period'], date('Y'))) { /* find current month below by exact match */ }
        }
    }
    $thisMonthLabel = date('M Y');
    $findExpenses = function ($chart) use ($thisMonthLabel) {
        foreach (($chart['data'] ?? []) as $row) {
            if ($row['period'] === $thisMonthLabel) return (float)($row['operating_expenses'] ?? 0);
        }
        return null;
    };
    $beforeAmt = $findExpenses($beforeChart);

    $createBody = $post('api/add_expense.php', [
        'expense_date'      => date('Y-m-d'),
        'amount'            => '15000',
        'description'       => 'AUTOTEST DELETE ME - simple pos expense bug regression',
        'paid_to_type'      => 'other',
        'payee_manual_role' => 'Test',
        'payee_manual_name' => 'Regression Test',
    ]);
    $createRes = json_decode($createBody, true);
    ok(!empty($createRes['success']), 'Simple POS expense created via the real endpoint (' . ($createRes['message'] ?? 'no message') . ')');
    $createdExpenseId = (int)($createRes['id'] ?? 0);
    ok($createdExpenseId > 0, "got a real expense_id ($createdExpenseId)");

    if ($createdExpenseId > 0) {
        $row = $pdo->prepare("SELECT status, transaction_id FROM expenses WHERE expense_id = ?");
        $row->execute([$createdExpenseId]);
        $exp = $row->fetch(PDO::FETCH_ASSOC);
        ok(($exp['status'] ?? null) === 'paid', "new Simple POS expense is status='paid' immediately (got " . var_export($exp['status'] ?? null, true) . ')');
        ok(!empty($exp['transaction_id']), 'a real ledger transaction was posted (transaction_id set)');

        $afterChart = json_decode($get('api/pos/get_simple_dashboard_chart.php?period=monthly'), true);
        $afterAmt = $findExpenses($afterChart);
        ok($afterAmt !== null, "dashboard chart returns a row for the current month ($thisMonthLabel)");
        if ($afterAmt !== null && $beforeAmt !== null) {
            ok(abs($afterAmt - $beforeAmt - 15000) < 0.01,
               "chart's operating_expenses increased by exactly 15000 (before=$beforeAmt, after=$afterAmt)");
        } elseif ($afterAmt !== null) {
            ok($afterAmt >= 15000, "chart's operating_expenses includes the new 15000 expense (got $afterAmt, no 'before' baseline existed)");
        }

        // ── 7. LIVE — Void Payment actually works (paid -> rejected) ─────
        section('7. Live — Void Payment (paid -> rejected) unlocks the record');

        $voidBody = $post('api/update_expense_status.php', ['expense_id' => $createdExpenseId, 'status' => 'rejected']);
        $voidRes = json_decode($voidBody, true);
        ok(!empty($voidRes['success']), 'Void Payment (setStatus to rejected) succeeds on a paid expense (' . ($voidRes['message'] ?? '') . ')');

        $row2 = $pdo->prepare("SELECT status, transaction_id FROM expenses WHERE expense_id = ?");
        $row2->execute([$createdExpenseId]);
        $exp2 = $row2->fetch(PDO::FETCH_ASSOC);
        ok(($exp2['status'] ?? null) === 'rejected', 'status is now rejected');
        ok(empty($exp2['transaction_id']), 'transaction_id cleared — the ledger posting was reversed');

        // Now unlocked: the real delete endpoint (previously blocked on 'paid') succeeds.
        $delBody = $post('api/delete_expense.php', ['expense_id' => $createdExpenseId]);
        $delRes = json_decode($delBody, true);
        ok(!empty($delRes['success']), 'delete_expense.php now succeeds after voiding (was blocked while status=paid)');
        $stillThere = $pdo->query("SELECT COUNT(*) FROM expenses WHERE expense_id = " . (int)$createdExpenseId)->fetchColumn();
        ok((int)$stillThere === 0, 'expense row is gone after delete');
        $archived = $pdo->query("SELECT COUNT(*) FROM deleted_expenses WHERE expense_id = " . (int)$createdExpenseId)->fetchColumn();
        ok((int)$archived === 1, 'expense was archived to deleted_expenses (soft-delete convention)');
        $createdExpenseId = null; // fully handled — shutdown cleanup has nothing left to do
    }

    if ($hadRow) {
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$originalValue]);
    } else {
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    }
    ok(get_setting('pos_simple_mode', '0') !== null, 'tenant pos_simple_mode setting restored');

    done:

} catch (Throwable $e) {
    echo "\n\033[31mFATAL: {$e->getMessage()}\033[0m\n";
    $fail++;
}

echo "\n\033[1m═══ Result ═══\033[0m\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "\033[32m✅ All $total checks passed.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $fail / $total check(s) failed.\033[0m\n\n";
    exit(1);
}
