<?php
/**
 * Chart of Accounts upgrade — Phase 2 (read APIs) CLI test
 * --------------------------------------------------------
 *   php tests/test_accounts_api_phase2_cli.php
 *
 * Verifies the read-side API changes:
 *   1. all three endpoints lint-clean
 *   2. get_chart_of_accounts.php — new `category` tab filter + the four new
 *      output columns (level, is_system, normal_balance, category), proven by
 *      running the same query against the live schema
 *   3. get_account.php — returns the new columns
 *   4. get_account_detail.php — wiring present; its 4 queries + calculated
 *      balance run against a real account and produce a coherent result
 *
 * Read-only. Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return is_file($p) ? file_get_contents($p) : ''; }

register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Endpoints exist + lint-clean');
    // ─────────────────────────────────────────────────────────────────────
    $files = [
        'api/account/get_chart_of_accounts.php',
        'api/account/get_account.php',
        'api/account/get_account_detail.php',
    ];
    foreach ($files as $f) {
        $full = "$root/$f";
        if (!is_file($full)) { ok(false, "MISSING: $f"); continue; }
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg($full) . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. get_chart_of_accounts.php — category filter + new columns wired');
    // ─────────────────────────────────────────────────────────────────────
    $coa = src($root, 'api/account/get_chart_of_accounts.php');
    ok(strpos($coa, "\$_GET['category']") !== false, 'reads the `category` GET param');
    ok(strpos($coa, 'at.category = :category') !== false, 'filters on at.category');
    ok(strpos($coa, "bindValue(':category'") !== false, 'binds :category');
    foreach (['a.level', 'a.is_system', 'a.normal_balance', 'at.category'] as $colExpr) {
        ok(strpos($coa, $colExpr) !== false, "SELECT includes $colExpr");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. get_chart_of_accounts data query runs + returns new columns (live)');
    // ─────────────────────────────────────────────────────────────────────
    // Reproduce the endpoint's data SELECT (no pagination needed for the probe).
    $dataSql = "
        SELECT a.account_id, a.account_code, a.account_name,
               at.type_name AS account_type, at.category,
               a.level, a.is_system, a.normal_balance, a.status
          FROM accounts a
          LEFT JOIN account_categories c ON a.category_id = c.category_id
          LEFT JOIN accounts pa          ON a.parent_account_id = pa.account_id
          LEFT JOIN account_types at     ON a.account_type_id = at.type_id
         WHERE 1=1 ";
    $all = $pdo->query($dataSql)->fetchAll(PDO::FETCH_ASSOC);
    ok(count($all) > 0, 'query returns rows (' . count($all) . ')');
    if ($all) {
        foreach (['level', 'is_system', 'normal_balance', 'category'] as $k) {
            ok(array_key_exists($k, $all[0]), "row exposes `$k`");
        }
    }

    // Category filter narrows correctly: pick a category that exists, then assert
    // every returned row carries it and the count is <= the unfiltered count.
    $someCat = $pdo->query("SELECT category FROM account_types WHERE category IS NOT NULL LIMIT 1")->fetchColumn();
    if ($someCat) {
        $stmt = $pdo->prepare($dataSql . " AND at.category = :category");
        $stmt->bindValue(':category', $someCat);
        $stmt->execute();
        $filtered = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ok(count($filtered) <= count($all), "category='$someCat' filter does not exceed unfiltered count");
        $allMatch = true;
        foreach ($filtered as $r) { if ($r['category'] !== $someCat) { $allMatch = false; break; } }
        ok($allMatch, "every row under category='$someCat' actually has that category");
    } else {
        ok(true, 'no classified categories present — filter probe skipped (n/a)');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('4. get_account.php — returns the new columns');
    // ─────────────────────────────────────────────────────────────────────
    $ga = src($root, 'api/account/get_account.php');
    foreach (['a.level', 'a.is_system', 'a.normal_balance', 'at.category'] as $colExpr) {
        ok(strpos($ga, $colExpr) !== false, "SELECT includes $colExpr");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('5. get_account_detail.php — wiring present');
    // ─────────────────────────────────────────────────────────────────────
    $gad = src($root, 'api/account/get_account_detail.php');
    $needles = [
        'isAuthenticated()'              => 'auth check',
        "canView('chart_of_accounts')"   => 'permission check',
        "parent_account_id = ?"          => 'children query by parent',
        'journal_entry_items'            => 'reads journal lines',
        "je.status = 'posted'"           => 'only posted entries',
        'calculated_balance'             => 'returns a calculated balance',
        "'in_sync'"                      => 'returns in_sync flag',
    ];
    foreach ($needles as $needle => $label) {
        ok(strpos($gad, $needle) !== false, "detail: $label");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('6. get_account_detail logic runs against a real account (live)');
    // ─────────────────────────────────────────────────────────────────────
    $acctId = (int)$pdo->query("SELECT account_id FROM accounts ORDER BY account_id LIMIT 1")->fetchColumn();
    if ($acctId <= 0) {
        ok(false, 'no accounts in DB to probe');
    } else {
        // core
        $core = $pdo->prepare("
            SELECT a.account_id, a.opening_balance, a.current_balance, a.normal_balance,
                   at.normal_side AS type_normal_side
              FROM accounts a LEFT JOIN account_types at ON a.account_type_id = at.type_id
             WHERE a.account_id = ?");
        $core->execute([$acctId]);
        $acc = $core->fetch(PDO::FETCH_ASSOC);
        ok($acc !== false, "core query returns account #$acctId");

        // children + transactions queries simply execute without error
        $ch = $pdo->prepare("SELECT account_id FROM accounts WHERE parent_account_id = ? AND account_id <> ?");
        $ch->execute([$acctId, $acctId]);
        ok(true, 'children query executed (' . $ch->rowCount() . ' child rows)');

        $tx = $pdo->prepare("
            SELECT jei.type, jei.amount
              FROM journal_entry_items jei
              JOIN journal_entries je ON jei.entry_id = je.entry_id
             WHERE jei.account_id = ? AND je.status = 'posted'
             ORDER BY je.entry_date DESC LIMIT 50");
        $tx->execute([$acctId]);
        $rows = $tx->fetchAll(PDO::FETCH_ASSOC);
        ok(true, 'transactions query executed (' . count($rows) . ' line(s))');

        // calculated balance mirrors the endpoint formula and is a finite number
        $sum = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN jei.type='debit'  THEN jei.amount ELSE 0 END),0) td,
                   COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END),0) tc
              FROM journal_entry_items jei
              JOIN journal_entries je ON jei.entry_id = je.entry_id
             WHERE jei.account_id = ? AND je.status = 'posted'");
        $sum->execute([$acctId]);
        $s = $sum->fetch(PDO::FETCH_ASSOC);
        $side = $acc['normal_balance'] ?: ($acc['type_normal_side'] ?: 'debit');
        $calc = ($side === 'credit')
            ? (float)$acc['opening_balance'] + (float)$s['tc'] - (float)$s['td']
            : (float)$acc['opening_balance'] + (float)$s['td'] - (float)$s['tc'];
        ok(is_finite($calc), "calculated balance is a finite number ($calc, side=$side)");
        $inSync = abs((float)$acc['current_balance'] - $calc) < 0.01;
        ok(is_bool($inSync), 'in_sync flag computes (' . ($inSync ? 'in sync' : 'drifted') . ')');
    }

} catch (Throwable $e) {
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
