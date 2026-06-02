<?php
/**
 * Asset Depreciation Phase 4 — DepreciationService + DepreciationRun CLI test
 * --------------------------------------------------------------------------
 *   php tests/test_asset_depreciation_phase4_cli.php
 *
 * Part A: hand-checks the §4 formulas (no DB) — straight line, reducing
 *         balance, existing-asset continuation, and the salvage/zero guardrails.
 * Part B: a live DB round-trip — registers two assets, runs the engine for a
 *         FY, asserts the depreciation_entries, then re-runs to prove
 *         idempotency. All test data is cleaned up.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/asset_depreciation_service.php";
require_once "$root/core/asset_depreciation_run.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true;

$passes = 0; $failures = 0;
function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function approx($a,$b,$eps=0.01){ return abs($a-$b) <= $eps; }
function check($label,$got,$exp){ approx($got,$exp) ? pass("$label = $got") : fail("$label = $got, expected $exp"); }

echo "\n── A. §4 formula hand-checks ──\n";

// §4.1 Straight line, new: cost 2,000,000 / life 4 / 2 years → accum 1,000,000
$r = calcAreaDepreciation(['method'=>'straight_line','useful_life'=>4,'salvage_value'=>0,'start_date'=>'2026-01-01','opening_accum_bf'=>0], 2000000, '2028-01-01');
check('SL new accumulated', $r['accumulated'], 1000000);
check('SL new nbv',         $r['nbv'],         1000000);

// §4.2 Reducing balance, new: cost 1,000,000 / 25% / 2 years → nbv 562,500
$r = calcAreaDepreciation(['method'=>'reducing_balance','rate'=>25,'salvage_value'=>0,'start_date'=>'2026-01-01','opening_accum_bf'=>0], 1000000, '2028-01-01');
check('RB new nbv',         $r['nbv'],         562500);
check('RB new accumulated', $r['accumulated'], 437500);

// §4.3 SL existing continuation: cost 4,000,000 / life 8 / bf 1,000,000 / 3 yrs
//   annual 500,000; openNbv 3,000,000; nbv 1,500,000; accum 2,500,000
$r = calcAreaDepreciation(['method'=>'straight_line','useful_life'=>8,'salvage_value'=>0,'start_date'=>'2026-01-01','opening_accum_bf'=>1000000], 4000000, '2029-01-01');
check('SL existing nbv',         $r['nbv'],         1500000);
check('SL existing accumulated', $r['accumulated'], 2500000);

// §4.3 RB existing continuation: cost 5,000,000 / 25% / bf 800,000 / 2 yrs
//   openNbv 4,200,000; nbv 2,362,500; accum 2,637,500
$r = calcAreaDepreciation(['method'=>'reducing_balance','rate'=>25,'salvage_value'=>0,'start_date'=>'2026-01-01','opening_accum_bf'=>800000], 5000000, '2028-01-01');
check('RB existing nbv',         $r['nbv'],         2362500);
check('RB existing accumulated', $r['accumulated'], 2637500);

// Guardrail: SL never below salvage. cost 100,000 / salvage 10,000 / life 2 / 4 yrs
$r = calcAreaDepreciation(['method'=>'straight_line','useful_life'=>2,'salvage_value'=>10000,'start_date'=>'2026-01-01','opening_accum_bf'=>0], 100000, '2030-01-01');
check('SL guardrail nbv floored at salvage', $r['nbv'], 10000);
check('SL guardrail accumulated capped',     $r['accumulated'], 90000);

echo "\n── B. DepreciationRun live round-trip ──\n";

try {
    // Force a clean Jan-Dec financial year for deterministic periods.
    $pdo->exec("UPDATE asset_settings SET financial_year_start='2026-01-01', financial_year_end='2026-12-31', depreciation_timing='full_year' WHERE id=1");

    $pdo->prepare("INSERT INTO asset_categories (category_name,default_method,default_useful_life_years,default_salvage_percent,code_prefix,is_depreciable,tax_rate,status) VALUES ('__p4_cat','straight_line',4,0,'P4',1,25,'active')")->execute();
    $catId = (int)$pdo->lastInsertId();

    // Asset 1: NEW, SL book life 4, cost 2,000,000, cap 2026-01-01.
    $pdo->prepare("INSERT INTO assets (asset_name,asset_code,category,category_id,acquisition_type,cost,purchase_date,capitalization_date,status,created_by,created_at) VALUES ('__p4 New','P4-NEW',?,?, 'new',2000000,'2026-01-01','2026-01-01','active',4,NOW())")->execute(['__p4_cat',$catId]);
    $a1 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO asset_depreciation_areas (asset_id,area,method,useful_life,rate,salvage_value,start_date,opening_accum_bf) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$a1,'book','straight_line',4,null,0,'2026-01-01',0]);

    // Asset 2: EXISTING, SL book life 8, cost 4,000,000, bf 1,000,000, take-on 2026-01-01.
    $pdo->prepare("INSERT INTO assets (asset_name,asset_code,category,category_id,acquisition_type,cost,purchase_date,capitalization_date,take_on_date,status,created_by,created_at) VALUES ('__p4 Existing','P4-EXI',?,?, 'existing',4000000,'2022-01-01','2022-01-01','2026-01-01','active',4,NOW())")->execute(['__p4_cat',$catId]);
    $a2 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO asset_depreciation_areas (asset_id,area,method,useful_life,rate,salvage_value,start_date,opening_accum_bf) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$a2,'book','straight_line',8,null,0,'2026-01-01',1000000]);

    // Run through FY 2028 (periods 2026, 2027, 2028) — isolate each test asset
    // (the engine is global, so restrict with onlyAssetId for a clean assertion).
    $sumA = runDepreciation($pdo, 2028, 4, $a1);
    $sumB = runDepreciation($pdo, 2028, 4, $a2);
    ($sumA['periods_written'] === 3 && $sumB['periods_written'] === 3)
        ? pass("run wrote 3 periods per asset (3 FYs each)")
        : fail("run wrote {$sumA['periods_written']}/{$sumB['periods_written']} periods, expected 3/3");

    // Asset 1 (new): each FY charge 500,000; accumulated 500k/1.0M/1.5M.
    $e1 = $pdo->query("SELECT period_end, charge, accumulated, closing_nbv FROM depreciation_entries WHERE asset_id=$a1 AND area='book' ORDER BY period_end")->fetchAll(PDO::FETCH_ASSOC);
    (count($e1)===3) ? pass("asset1 has 3 entries") : fail("asset1 has ".count($e1)." entries");
    check('asset1 FY2026 charge', (float)$e1[0]['charge'], 500000);
    check('asset1 FY2028 accumulated', (float)$e1[2]['accumulated'], 1500000);
    check('asset1 FY2028 closing nbv', (float)$e1[2]['closing_nbv'], 500000);

    // Asset 2 (existing): continues from bf 1,000,000; each FY charge 500,000.
    //   FY2026 accumulated 1,500,000; FY2028 accumulated 2,500,000 (NOT from purchase).
    $e2 = $pdo->query("SELECT period_end, opening_value, charge, accumulated, closing_nbv FROM depreciation_entries WHERE asset_id=$a2 AND area='book' ORDER BY period_end")->fetchAll(PDO::FETCH_ASSOC);
    check('asset2 FY2026 opening value (cost - bf)', (float)$e2[0]['opening_value'], 3000000);
    check('asset2 FY2026 charge', (float)$e2[0]['charge'], 500000);
    check('asset2 FY2026 accumulated (bf + charge)', (float)$e2[0]['accumulated'], 1500000);
    check('asset2 FY2028 accumulated (continues from bf)', (float)$e2[2]['accumulated'], 2500000);

    // Idempotency: re-run must skip the already-posted periods, write none.
    $re1 = runDepreciation($pdo, 2028, 4, $a1);
    $re2 = runDepreciation($pdo, 2028, 4, $a2);
    ($re1['periods_written'] === 0 && $re1['periods_skipped_posted'] === 3 &&
     $re2['periods_written'] === 0 && $re2['periods_skipped_posted'] === 3)
        ? pass("re-run idempotent: 0 written, 3 skipped per asset")
        : fail("re-run wrote {$re1['periods_written']}/{$re2['periods_written']}, skipped {$re1['periods_skipped_posted']}/{$re2['periods_skipped_posted']}");

    // Cleanup.
    foreach ([$a1,$a2] as $aid) {
        $pdo->exec("DELETE FROM depreciation_entries WHERE asset_id=$aid");
        $pdo->exec("DELETE FROM asset_depreciation_areas WHERE asset_id=$aid");
        $pdo->exec("DELETE FROM asset_audit_log WHERE asset_id=$aid");
        $pdo->exec("DELETE FROM assets WHERE asset_id=$aid");
    }
    $pdo->exec("DELETE FROM asset_categories WHERE category_name='__p4_cat'");
    pass("test data cleaned up");

} catch (Throwable $e) {
    fail("round-trip exception: " . $e->getMessage());
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures===0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
exit($failures === 0 ? 0 : 1);
