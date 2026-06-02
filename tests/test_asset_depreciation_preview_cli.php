<?php
/**
 * Depreciation Proposal (Preview -> Post) — CLI test
 *   php tests/test_asset_depreciation_preview_cli.php
 *
 * Guards the read-only preview behind the professional Preview -> Post safeguard:
 *   - preview writes NOTHING to depreciation_entries
 *   - preview figures (method/cost/opening/charge/closing/nbv) EQUAL what posting
 *     then produces for the same financial year
 *   - scope filters (all / category / asset) return the correct asset sets
 *   - the already_posted flag reflects posted state
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/asset_depreciation_run.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['is_admin'] = true;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function approx($a,$b){ return abs((float)$a - (float)$b) <= 0.01; }

$created = [];
try {
    $pdo->exec("DELETE FROM assets WHERE asset_name LIKE '__pp_%'");

    function mkA($pdo, $name, $cat, $cid, $cost, $cap, $life, &$created) {
        $pdo->prepare("INSERT INTO assets (asset_name,asset_code,category,category_id,cost,purchase_date,capitalization_date,acquisition_type,status,`condition`,created_by,created_at)
                       VALUES (?,?,?,?,?,?,?, 'new','active','good',4,NOW())")
            ->execute([$name,$name,$cat,$cid,$cost,$cap,$cap]);
        $id=(int)$pdo->lastInsertId(); $created[]=$id;
        $pdo->prepare("INSERT INTO asset_depreciation_areas (asset_id,area,method,useful_life,rate,salvage_value,start_date,opening_accum_bf)
                       VALUES (?,?,?,?,?,?,?,0)")->execute([$id,'book','straight_line',$life,null,0,$cap]);
        return $id;
    }
    // Vehicles: 500k / SL5 = 100k/yr ; Buildings: 1.2M / SL10 = 120k/yr
    $v = mkA($pdo,'__pp_V','Vehicles',4,500000,'2024-01-01',5,$created);
    $b = mkA($pdo,'__pp_B','Buildings & Structures',1,1200000,'2024-01-01',10,$created);

    // ── 1. Preview writes nothing ──────────────────────────────────────────
    $before = (int)$pdo->query("SELECT COUNT(*) FROM depreciation_entries WHERE asset_id IN (".implode(',',$created).")")->fetchColumn();
    $prev = previewDepreciation($pdo, 2026, ['type'=>'all','value'=>null]);
    $after = (int)$pdo->query("SELECT COUNT(*) FROM depreciation_entries WHERE asset_id IN (".implode(',',$created).")")->fetchColumn();
    ok($before === 0 && $after === 0, "preview writes nothing (entries before=$before after=$after)");

    $byId = [];
    foreach ($prev['rows'] as $r) $byId[$r['asset_id']] = $r;
    ok(isset($byId[$v]) && isset($byId[$b]), "preview(all) includes both test assets");
    ok($byId[$v]['method'] === 'straight_line', "method exposed");
    ok(approx($byId[$v]['cost'], 500000), "cost exposed");
    ok(approx($byId[$v]['charge'], 100000), "Vehicles FY2026 charge = 100,000");
    ok(approx($byId[$v]['opening_accum'], 200000), "Vehicles opening accum (2 prior years) = 200,000");
    ok(approx($byId[$v]['closing_accum'], 300000), "Vehicles closing accum = 300,000");
    ok(approx($byId[$v]['nbv'], 200000), "Vehicles NBV = 200,000");
    ok($byId[$v]['already_posted'] === false, "already_posted = false before posting");

    // ── 2. Scope filters ───────────────────────────────────────────────────
    $pc = previewDepreciation($pdo, 2026, ['type'=>'category','value'=>'Vehicles']);
    $codes = array_column($pc['rows'], 'asset_code');
    ok(in_array('__pp_V',$codes,true) && !in_array('__pp_B',$codes,true), "scope=category returns only that category");
    $pa = previewDepreciation($pdo, 2026, ['type'=>'asset','value'=>$b]);
    ok(count($pa['rows']) === 1 && (int)$pa['rows'][0]['asset_id'] === $b, "scope=asset returns only that asset");

    // ── 3. Preview == Post ─────────────────────────────────────────────────
    runDepreciation($pdo, 2026, 4);   // post all through FY2026
    $ent = $pdo->query("SELECT opening_value, charge, accumulated, closing_nbv
                          FROM depreciation_entries
                         WHERE asset_id = $v AND area='book' AND period_end='2026-12-31'")->fetch(PDO::FETCH_ASSOC);
    ok(approx($byId[$v]['charge'], $ent['charge']), "preview charge == posted charge");
    ok(approx($byId[$v]['opening_accum'], 500000 - $ent['opening_value']), "preview opening accum == posted (cost - opening_value)");
    ok(approx($byId[$v]['closing_accum'], $ent['accumulated']), "preview closing accum == posted accumulated");
    ok(approx($byId[$v]['nbv'], $ent['closing_nbv']), "preview NBV == posted closing_nbv");

    // ── 4. already_posted now reflects state ───────────────────────────────
    $prev2 = previewDepreciation($pdo, 2026, ['type'=>'asset','value'=>$v]);
    ok($prev2['rows'][0]['already_posted'] === true, "already_posted = true after posting");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
}

// Cleanup
foreach ($created as $id) {
    $pdo->exec("DELETE FROM depreciation_entries WHERE asset_id=$id");
    $pdo->exec("DELETE FROM asset_depreciation_areas WHERE asset_id=$id");
    $pdo->exec("DELETE FROM asset_audit_log WHERE asset_id=$id");
    $pdo->exec("DELETE FROM assets WHERE asset_id=$id");
}
echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail===0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
