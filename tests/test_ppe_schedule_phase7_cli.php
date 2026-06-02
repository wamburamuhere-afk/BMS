<?php
/**
 * PPE Schedule Phase 7 — CLI test
 *   php tests/test_ppe_schedule_phase7_cli.php
 *
 * Deterministic scenario for FY2026 (Jan–Dec), book area:
 *   Category A (SL life 4):
 *     A1  cost 4,000,000  capitalised 2025-06-01           (held)
 *     A2  cost 2,000,000  capitalised 2026-03-01           (addition)
 *     A3  cost 1,000,000  capitalised 2024-01-01, disposed 2026-05-01
 *   Category Land (non-depreciable):
 *     L1  cost 50,000,000 capitalised 2025-01-01
 *
 * Expected (book): A → cost 5M open / 2M add / 1M disp / 6M close;
 *   dep 1.5M open / 1.5M charge / 0.5M on-disposal / 2.5M close; NBV 3.5M.
 *   Land → 50M cost only, dep 0, NBV 50M. Reconciles: close cost − close dep = NBV.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/asset_ppe_schedule_service.php";
require_once "$root/core/asset_depreciation_run.php";
require_once "$root/core/asset_disposal_service.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['is_admin'] = true;

$passes = 0; $failures = 0;
function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function approx($a,$b){ return abs($a-$b) <= 0.01; }
function check($l,$g,$e){ approx($g,$e) ? pass("$l = ".number_format($g)) : fail("$l = ".number_format($g).", expected ".number_format($e)); }

$created = [];
try {
    $pdo->exec("UPDATE asset_settings SET financial_year_start='2026-01-01', financial_year_end='2026-12-31', depreciation_timing='full_year' WHERE id=1");
    $pdo->prepare("INSERT INTO asset_categories (category_name,default_method,default_useful_life_years,default_salvage_percent,code_prefix,is_depreciable,tax_rate,status) VALUES ('__p7_A','straight_line',4,0,'P7A',1,25,'active')")->execute();
    $catA = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO asset_categories (category_name,default_method,default_salvage_percent,code_prefix,is_depreciable,status) VALUES ('__p7_Land','straight_line',0,'P7L',0,'active')")->execute();
    $pdo->prepare("INSERT INTO asset_categories (category_name,default_method,default_useful_life_years,default_salvage_percent,code_prefix,is_depreciable,tax_rate,status) VALUES ('__p7_B','straight_line',10,0,'P7B',1,25,'active')")->execute();

    function mkAsset($pdo,$name,$cat,$cost,$cap,$life,&$created){
        $pdo->prepare("INSERT INTO assets (asset_name,asset_code,category,acquisition_type,cost,purchase_date,capitalization_date,status,created_by,created_at) VALUES (?,?,?, 'new',?,?,?,'active',4,NOW())")
            ->execute([$name,$name,$cat,$cost,$cap,$cap]);
        $id=(int)$pdo->lastInsertId(); $created[]=$id;
        if ($life>0) $pdo->prepare("INSERT INTO asset_depreciation_areas (asset_id,area,method,useful_life,salvage_value,start_date,opening_accum_bf) VALUES (?,?,?,?,?,?,0)")->execute([$id,'book','straight_line',$life,0,$cap]);
        return $id;
    }
    $a1 = mkAsset($pdo,'__p7 A1','__p7_A',4000000,'2025-06-01',4,$created);
    $a2 = mkAsset($pdo,'__p7 A2','__p7_A',2000000,'2026-03-01',4,$created);
    $a3 = mkAsset($pdo,'__p7 A3','__p7_A',1000000,'2024-01-01',4,$created);
    $l1 = mkAsset($pdo,'__p7 L1','__p7_Land',50000000,'2025-01-01',0,$created);

    // Category B — an EXISTING (brought-forward) asset with opening accumulated
    // depreciation b/f, to guard #5 (b/f must reach opening) and #2 (existing
    // assets are opening, never additions). Cost 1,000,000; SL 10yr; b/f 300,000;
    // taken on 2024-01-01.
    $pdo->prepare("INSERT INTO assets (asset_name,asset_code,category,acquisition_type,cost,purchase_date,capitalization_date,take_on_date,status,created_by,created_at) VALUES ('__p7 B1','__p7 B1','__p7_B','existing',1000000,'2024-01-01','2024-01-01','2024-01-01','active',4,NOW())")->execute();
    $b1 = (int)$pdo->lastInsertId(); $created[] = $b1;
    $pdo->prepare("INSERT INTO asset_depreciation_areas (asset_id,area,method,useful_life,salvage_value,start_date,opening_accum_bf) VALUES (?,?,?,?,?,?,?)")->execute([$b1,'book','straight_line',10,0,'2024-01-01',300000]);

    // Run depreciation through 2026 BEFORE disposal (worst case for reconciliation),
    // then dispose A3 mid-year — the DisposalService must resync its entries.
    foreach ([$a1,$a2,$a3,$b1] as $id) runDepreciation($pdo, 2026, 4, $id);
    $r = disposeAsset($pdo, $a3, ['disposal_date'=>'2026-05-01','method'=>'sold','proceeds'=>400000], 4);
    $r['success'] ? pass("A3 disposed (".$r['message'].")") : fail("dispose failed: ".$r['message']);

    // Build the book schedule for FY2026.
    [$ps,$pe] = fyBoundsForYear(['financial_year_start'=>'2026-01-01'], 2026);
    $sch = buildPpeSchedule($pdo, $ps, $pe, 'book');

    $byCat = [];
    foreach ($sch['rows'] as $row) $byCat[$row['category']] = $row;

    if (!isset($byCat['__p7_A'])) { fail("category __p7_A missing from schedule"); }
    else {
        $A = $byCat['__p7_A'];
        echo "\n── Category A (book) ──\n";
        check('cost opening',   $A['cost_opening'],   5000000);
        check('cost additions', $A['cost_additions'], 2000000);
        check('cost disposals', $A['cost_disposals'], 1000000);
        check('cost closing',   $A['cost_closing'],   6000000);
        check('dep opening',    $A['dep_opening'],    1500000);
        check('dep charge',     $A['dep_charge'],     1500000);
        check('dep on disposal',$A['dep_disposal'],   500000);
        check('dep closing',    $A['dep_closing'],    2500000);
        check('NBV',            $A['nbv'],            3500000);
        approx($A['cost_closing'] - $A['dep_closing'], $A['nbv']) ? pass("A reconciles: close cost − close dep = NBV") : fail("A does not reconcile");
    }

    echo "\n── Category Land (book) ──\n";
    if (!isset($byCat['__p7_Land'])) { fail("Land missing"); }
    else {
        $L = $byCat['__p7_Land'];
        check('Land cost closing', $L['cost_closing'], 50000000);
        check('Land dep closing',  $L['dep_closing'],  0);
        check('Land NBV (cost only)', $L['nbv'],        50000000);
    }

    echo "\n── Category B (existing, brought-forward b/f) ──\n";
    if (!isset($byCat['__p7_B'])) { fail("__p7_B missing from schedule"); }
    else {
        $B = $byCat['__p7_B'];
        check('B cost opening (existing → opening)',       $B['cost_opening'],   1000000);
        check('B cost additions (existing never addition)',$B['cost_additions'], 0);
        check('B dep opening (#5 b/f 300k + 2024/25 200k)', $B['dep_opening'],   500000);
        check('B dep charge 2026',                          $B['dep_charge'],    100000);
        check('B dep closing',                              $B['dep_closing'],   600000);
        check('B NBV',                                      $B['nbv'],           400000);
    }

    // Cleanup.
    foreach ($created as $id) {
        $pdo->exec("DELETE FROM depreciation_entries WHERE asset_id=$id");
        $pdo->exec("DELETE FROM asset_disposals WHERE asset_id=$id");
        $pdo->exec("DELETE FROM asset_depreciation_areas WHERE asset_id=$id");
        $pdo->exec("DELETE FROM asset_audit_log WHERE asset_id=$id");
        $pdo->exec("DELETE FROM assets WHERE asset_id=$id");
    }
    $pdo->exec("DELETE FROM asset_categories WHERE category_name IN ('__p7_A','__p7_Land','__p7_B')");
    pass("test data cleaned up");

} catch (Throwable $e) {
    fail("exception: " . $e->getMessage());
    foreach ($created as $id) { $pdo->exec("DELETE FROM depreciation_entries WHERE asset_id=$id"); $pdo->exec("DELETE FROM asset_disposals WHERE asset_id=$id"); $pdo->exec("DELETE FROM asset_depreciation_areas WHERE asset_id=$id"); $pdo->exec("DELETE FROM assets WHERE asset_id=$id"); }
    $pdo->exec("DELETE FROM asset_categories WHERE category_name IN ('__p7_A','__p7_Land','__p7_B')");
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures===0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
exit($failures === 0 ? 0 : 1);
