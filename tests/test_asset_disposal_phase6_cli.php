<?php
/**
 * Asset Disposal & Maintenance Phase 6 — CLI test
 *   php tests/test_asset_disposal_phase6_cli.php
 *
 * Verifies: disposal snapshots accumulated depreciation per area, computes
 * NBV + gain/loss, flips status, stops future depreciation in the engine; and
 * maintenance rows are written. Cleans up all test data. Exit 0 = all pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/asset_disposal_service.php";
require_once "$root/core/asset_depreciation_run.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['is_admin'] = true;

$passes = 0; $failures = 0;
function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function approx($a,$b){ return abs($a-$b) <= 0.01; }
function check($l,$g,$e){ approx($g,$e) ? pass("$l = $g") : fail("$l = $g, expected $e"); }

try {
    $pdo->exec("UPDATE asset_settings SET financial_year_start='2026-01-01', financial_year_end='2026-12-31', depreciation_timing='full_year' WHERE id=1");
    $pdo->prepare("INSERT INTO asset_categories (category_name,default_method,default_useful_life_years,default_salvage_percent,code_prefix,is_depreciable,tax_rate,status) VALUES ('__p6_cat','straight_line',4,0,'P6',1,25,'active')")->execute();
    $cat = (int)$pdo->lastInsertId();

    // SL book life 4, cost 4,000,000, cap 2026-01-01. Tax RB 25%.
    $pdo->prepare("INSERT INTO assets (asset_name,asset_code,category,category_id,acquisition_type,cost,purchase_date,capitalization_date,status,created_by,created_at) VALUES ('__p6 Rig','P6-1',?,?, 'new',4000000,'2026-01-01','2026-01-01','active',4,NOW())")->execute(['__p6_cat',$cat]);
    $aid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO asset_depreciation_areas (asset_id,area,method,useful_life,salvage_value,start_date,opening_accum_bf) VALUES (?,?,?,?,?,?,0)")->execute([$aid,'book','straight_line',4,0,'2026-01-01']);
    $pdo->prepare("INSERT INTO asset_depreciation_areas (asset_id,area,method,rate,salvage_value,start_date,opening_accum_bf) VALUES (?,?,?,?,?,?,0)")->execute([$aid,'tax','reducing_balance',25,0,'2026-01-01']);

    // Dispose at 2028-01-01 (2 full years): book accum 2,000,000; NBV 2,000,000.
    //   tax: 4,000,000 * 0.75^2 = 2,250,000 nbv → accum 1,750,000.
    //   proceeds 2,500,000 → gain 500,000.
    $r = disposeAsset($pdo, $aid, ['disposal_date'=>'2028-01-01','method'=>'sold','proceeds'=>2500000,'notes'=>'test'], 4);
    $r['success'] ? pass("disposeAsset returned success") : fail("disposeAsset failed: ".$r['message']);
    check('accum_dep_book at disposal', $r['snapshot']['accum_dep_book'], 2000000);
    check('nbv_at_disposal',            $r['snapshot']['nbv_at_disposal'], 2000000);
    check('accum_dep_tax at disposal',  $r['snapshot']['accum_dep_tax'], 1750000);
    check('gain_loss (proceeds - nbv)', $r['snapshot']['gain_loss'], 500000);

    // Status flipped + disposal_date set.
    $row = $pdo->query("SELECT status, disposal_date, disposal_gain_loss FROM assets WHERE asset_id=$aid")->fetch(PDO::FETCH_ASSOC);
    ($row['status']==='disposed') ? pass("status flipped to disposed") : fail("status is {$row['status']}");
    ($row['disposal_date']==='2028-01-01') ? pass("disposal_date set") : fail("disposal_date {$row['disposal_date']}");

    // Snapshot row persisted, unique.
    $cnt = $pdo->query("SELECT COUNT(*) FROM asset_disposals WHERE asset_id=$aid")->fetchColumn();
    ($cnt==1) ? pass("one asset_disposals row written") : fail("$cnt disposal rows");

    // Double-dispose blocked.
    $r2 = disposeAsset($pdo, $aid, ['disposal_date'=>'2028-06-01','method'=>'scrapped'], 4);
    (!$r2['success']) ? pass("double-dispose blocked: ".$r2['message']) : fail("double-dispose was allowed");

    // Engine stops at disposal: run through FY2030 → only periods up to 2028.
    runDepreciation($pdo, 2030, 4, $aid);
    $maxPeriod = $pdo->query("SELECT MAX(period_end) FROM depreciation_entries WHERE asset_id=$aid AND area='book'")->fetchColumn();
    ($maxPeriod !== null && substr($maxPeriod,0,4) <= '2028') ? pass("engine stopped at disposal (last book period $maxPeriod)") : fail("engine ran past disposal (last $maxPeriod)");

    // Maintenance row.
    $pdo->prepare("INSERT INTO asset_maintenance (asset_id,maintenance_date,description,cost,performed_by,next_due_date,created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$aid,'2026-06-01','Oil change',50000,'Vendor X','2026-12-01',4]);
    $mc = $pdo->query("SELECT COUNT(*) FROM asset_maintenance WHERE asset_id=$aid")->fetchColumn();
    ($mc==1) ? pass("maintenance row written") : fail("$mc maintenance rows");

    // Audit log has a dispose entry.
    $disp = $pdo->query("SELECT COUNT(*) FROM asset_audit_log WHERE asset_id=$aid AND action='dispose'")->fetchColumn();
    ($disp>=1) ? pass("audit log has dispose entry") : fail("no dispose audit entry");

    // Cleanup.
    $pdo->exec("DELETE FROM depreciation_entries WHERE asset_id=$aid");
    $pdo->exec("DELETE FROM asset_disposals WHERE asset_id=$aid");
    $pdo->exec("DELETE FROM asset_maintenance WHERE asset_id=$aid");
    $pdo->exec("DELETE FROM asset_depreciation_areas WHERE asset_id=$aid");
    $pdo->exec("DELETE FROM asset_audit_log WHERE asset_id=$aid");
    $pdo->exec("DELETE FROM assets WHERE asset_id=$aid");
    $pdo->exec("DELETE FROM asset_categories WHERE category_name='__p6_cat'");
    pass("test data cleaned up");

} catch (Throwable $e) {
    fail("exception: " . $e->getMessage());
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures===0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
exit($failures === 0 ? 0 : 1);
