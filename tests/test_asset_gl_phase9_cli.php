<?php
/**
 * Asset GL Integration Phase 9 — CLI test
 *   php tests/test_asset_gl_phase9_cli.php
 *
 * Verifies: the depreciation run posts Dr Expense / Cr Accumulated for the book
 * charge, and the GL depreciation expense for the period ties exactly to the
 * book PPE schedule's "Charge for year" (§9.2); disposal posts a balanced GL
 * entry. Cleans up accounts + journals + assets. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/asset_depreciation_run.php";
require_once "$root/core/asset_disposal_service.php";
require_once "$root/core/asset_ppe_schedule_service.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['is_admin'] = true;

$passes = 0; $failures = 0;
function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function approx($a,$b){ return abs($a-$b) <= 0.01; }

$created = []; $acctCodes = ['__P9EXP','__P9ACC','__P9AST','__P9CLR','__P9GL'];
function cleanup($pdo, $created, $acctCodes) {
    foreach ($created as $id) {
        // Disposal posts under entity_type='asset_disposal' (OUT-14); depreciation/
        // acquisition under 'asset'/'asset_acquisition'. Clear all three so no journal
        // entry is orphaned when the temp asset + accounts are deleted below.
        $pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id WHERE je.entity_type IN ('asset','asset_disposal','asset_acquisition') AND je.entity_id=$id");
        $pdo->exec("DELETE FROM journal_entries WHERE entity_type IN ('asset','asset_disposal','asset_acquisition') AND entity_id=$id");
        $pdo->exec("DELETE FROM depreciation_entries WHERE asset_id=$id");
        $pdo->exec("DELETE FROM asset_disposals WHERE asset_id=$id");
        $pdo->exec("DELETE FROM asset_depreciation_areas WHERE asset_id=$id");
        $pdo->exec("DELETE FROM asset_audit_log WHERE asset_id=$id");
        $pdo->exec("DELETE FROM assets WHERE asset_id=$id");
    }
    $pdo->exec("DELETE FROM asset_categories WHERE category_name='__p9_cat'");
    $in = "'" . implode("','", $acctCodes) . "'";
    $pdo->exec("DELETE FROM accounts WHERE account_code IN ($in)");
}

try {
    cleanup($pdo, [], $acctCodes); // clear any prior run's accounts

    // Accounts.
    $accId = [];
    $mk = $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, status, opening_balance, current_balance) VALUES (?,?,?,'active',0,0)");
    foreach ([['__P9EXP','Dep Expense','expense'],['__P9ACC','Accum Dep','liability'],['__P9AST','Fixed Asset','asset'],['__P9CLR','Clearing','asset'],['__P9GL','Gain/Loss','income']] as $a) {
        $mk->execute($a); $accId[$a[0]] = (int)$pdo->lastInsertId();
    }

    $pdo->exec("UPDATE asset_settings SET financial_year_start='2026-01-01', financial_year_end='2026-12-31', depreciation_timing='full_year', gl_clearing_account='__P9CLR', gl_gain_loss_account='__P9GL' WHERE id=1");

    $pdo->prepare("INSERT INTO asset_categories (category_name,default_method,default_useful_life_years,default_salvage_percent,code_prefix,is_depreciable,tax_rate,gl_asset_account,gl_accum_account,gl_expense_account,status) VALUES ('__p9_cat','straight_line',4,0,'P9',1,25,'__P9AST','__P9ACC','__P9EXP','active')")->execute();
    $catId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO assets (asset_name,asset_code,category,category_id,acquisition_type,cost,purchase_date,capitalization_date,status,created_by,created_at) VALUES ('__p9 Rig','P9-1',?,?, 'new',4000000,'2026-01-01','2026-01-01','active',4,NOW())")->execute(['__p9_cat',$catId]);
    $aid = (int)$pdo->lastInsertId(); $created[] = $aid;
    $pdo->prepare("INSERT INTO asset_depreciation_areas (asset_id,area,method,useful_life,salvage_value,start_date,opening_accum_bf) VALUES (?,?,?,?,?,?,0)")->execute([$aid,'book','straight_line',4,0,'2026-01-01']);

    // Run FY2026 → book charge 1,000,000; expect a Dr EXP / Cr ACC journal.
    runDepreciation($pdo, 2026, 4, $aid);

    // GL depreciation expense (debits to EXP) for the asset's entries.
    $expDebit = (float)$pdo->query("
        SELECT COALESCE(SUM(jei.amount),0) FROM journal_entry_items jei
          JOIN journal_entries je ON je.entry_id=jei.entry_id
         WHERE je.entity_type='asset' AND je.entity_id=$aid AND jei.type='debit' AND jei.account_id={$accId['__P9EXP']}
    ")->fetchColumn();
    $accCredit = (float)$pdo->query("
        SELECT COALESCE(SUM(jei.amount),0) FROM journal_entry_items jei
          JOIN journal_entries je ON je.entry_id=jei.entry_id
         WHERE je.entity_type='asset' AND je.entity_id=$aid AND jei.type='credit' AND jei.account_id={$accId['__P9ACC']}
    ")->fetchColumn();
    approx($expDebit, 1000000) ? pass("GL Dr Depreciation Expense = ".number_format($expDebit)) : fail("GL expense debit=$expDebit, expected 1,000,000");
    approx($accCredit, 1000000) ? pass("GL Cr Accumulated Depreciation = ".number_format($accCredit)) : fail("GL accum credit=$accCredit, expected 1,000,000");

    // §9.2 — GL expense ties to the schedule's "Charge for year" for the category.
    $sch = buildPpeSchedule($pdo, '2026-01-01', '2026-12-31', 'book');
    $catCharge = 0.0;
    foreach ($sch['rows'] as $r) if ($r['category']==='__p9_cat') $catCharge = $r['dep_charge'];
    approx($catCharge, $expDebit) ? pass("GL expense ($expDebit) ties to schedule charge ($catCharge)") : fail("GL expense $expDebit != schedule charge $catCharge");

    // Idempotent re-run posts no extra GL.
    runDepreciation($pdo, 2026, 4, $aid);
    $expDebit2 = (float)$pdo->query("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id WHERE je.entity_type='asset' AND je.entity_id=$aid AND jei.type='debit' AND jei.account_id={$accId['__P9EXP']}")->fetchColumn();
    approx($expDebit2, 1000000) ? pass("re-run posts no duplicate GL (still ".number_format($expDebit2).")") : fail("re-run duplicated GL: $expDebit2");

    // Disposal → balanced GL entry. Dispose 2028-06-01 (accum 2,000,000; nbv 2M; proceeds 2.5M; gain 0.5M).
    runDepreciation($pdo, 2028, 4, $aid);
    $r = disposeAsset($pdo, $aid, ['disposal_date'=>'2028-06-01','method'=>'sold','proceeds'=>2500000], 4);
    $r['success'] ? pass("disposed (".$r['message'].")") : fail("dispose failed: ".$r['message']);
    isset($r['snapshot']['gl_entry_id']) ? pass("disposal posted a GL entry (#".$r['snapshot']['gl_entry_id'].")") : fail("disposal did not post GL");

    if (isset($r['snapshot']['gl_entry_id'])) {
        $eid = (int)$r['snapshot']['gl_entry_id'];
        $d = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=$eid AND type='debit'")->fetchColumn();
        $c = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=$eid AND type='credit'")->fetchColumn();
        approx($d, $c) ? pass("disposal GL balances (Dr=".number_format($d).", Cr=".number_format($c).")") : fail("disposal GL unbalanced Dr=$d Cr=$c");
    }

    cleanup($pdo, $created, $acctCodes);
    pass("test data cleaned up");

} catch (Throwable $e) {
    fail("exception: " . $e->getMessage());
    cleanup($pdo, $created, $acctCodes);
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures===0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
exit($failures === 0 ? 0 : 1);
