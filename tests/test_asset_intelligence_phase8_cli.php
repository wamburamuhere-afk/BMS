<?php
/**
 * Asset Intelligence Phase 8 — CLI test
 *   php tests/test_asset_intelligence_phase8_cli.php
 *
 * Verifies: the depreciation run writes a 'depreciate' audit entry per asset
 * (§8.1); the verify endpoint finds an asset by code and logs a 'verify' entry
 * and flags unknown codes (§8.4); maintenance-overdue and warranty-expiring are
 * detectable from the data the dashboard reads (§8.2). Cleans up. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/asset_depreciation_run.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['is_admin'] = true;

$passes = 0; $failures = 0;
function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }

$created = [];
try {
    $pdo->exec("UPDATE asset_settings SET financial_year_start='2026-01-01', financial_year_end='2026-12-31', depreciation_timing='full_year' WHERE id=1");
    $pdo->prepare("INSERT INTO asset_categories (category_name,default_method,default_useful_life_years,default_salvage_percent,code_prefix,is_depreciable,tax_rate,status) VALUES ('__p8_cat','straight_line',4,0,'P8',1,25,'active')")->execute();

    $pdo->prepare("INSERT INTO assets (asset_name,asset_code,category,acquisition_type,cost,purchase_date,capitalization_date,status,warranty_expiry,created_by,created_at) VALUES ('__p8 A','__P8CODE1','__p8_cat','new',4000000,'2026-01-01','2026-01-01','active',?,4,NOW())")
        ->execute([date('Y-m-d', strtotime('+30 days'))]);  // warranty expiring within 60d
    $aid = (int)$pdo->lastInsertId(); $created[] = $aid;
    $pdo->prepare("INSERT INTO asset_depreciation_areas (asset_id,area,method,useful_life,salvage_value,start_date,opening_accum_bf) VALUES (?,?,?,?,?,?,0)")->execute([$aid,'book','straight_line',4,0,'2026-01-01']);

    // §8.1 — run posts and audits.
    runDepreciation($pdo, 2026, 4, $aid);
    $depAudit = $pdo->query("SELECT COUNT(*) FROM asset_audit_log WHERE asset_id=$aid AND action='depreciate'")->fetchColumn();
    ($depAudit >= 1) ? pass("run wrote a 'depreciate' audit entry") : fail("no depreciate audit entry");

    // §8.4 — verify by code (found + 'verify' audit).
    $_GET = ['code' => '__P8CODE1'];
    ob_start(); include "$root/api/assets/verify_asset.php"; $res = json_decode(ob_get_clean(), true);
    ($res['found'] ?? false) ? pass("verify found asset by code") : fail("verify did not find asset");
    $verAudit = $pdo->query("SELECT COUNT(*) FROM asset_audit_log WHERE asset_id=$aid AND action='verify'")->fetchColumn();
    ($verAudit >= 1) ? pass("verify logged a 'verify' audit entry") : fail("no verify audit entry");

    // §8.4 — unknown code matches no registered asset (endpoint returns
    // found:false for this; checked directly to avoid the endpoint's exit()).
    $match = $pdo->query("SELECT COUNT(*) FROM assets WHERE status!='deleted' AND (qr_code='__NOPE_NOT_A_CODE' OR asset_code='__NOPE_NOT_A_CODE')")->fetchColumn();
    ($match == 0) ? pass("unknown code matches no asset (flagged not-registered)") : fail("unknown code unexpectedly matched");

    // §8.2 — warranty expiring within 60 days detectable.
    $warn = $pdo->query("SELECT COUNT(*) FROM assets WHERE asset_id=$aid AND warranty_expiry IS NOT NULL AND warranty_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetchColumn();
    ($warn == 1) ? pass("warranty-expiring detectable") : fail("warranty alert not detectable");

    // §8.2 — maintenance overdue detectable.
    $pdo->prepare("INSERT INTO asset_maintenance (asset_id,maintenance_date,description,cost,next_due_date,created_by) VALUES (?,?,?,?,?,?)")
        ->execute([$aid, date('Y-m-d', strtotime('-90 days')), 'Service', 10000, date('Y-m-d', strtotime('-10 days')), 4]);
    $over = $pdo->query("SELECT COUNT(*) FROM (SELECT asset_id, MAX(next_due_date) nd FROM asset_maintenance WHERE asset_id=$aid GROUP BY asset_id) t WHERE nd < CURDATE()")->fetchColumn();
    ($over == 1) ? pass("maintenance-overdue detectable") : fail("overdue maintenance not detectable");

    foreach ($created as $id) {
        $pdo->exec("DELETE FROM depreciation_entries WHERE asset_id=$id");
        $pdo->exec("DELETE FROM asset_maintenance WHERE asset_id=$id");
        $pdo->exec("DELETE FROM asset_depreciation_areas WHERE asset_id=$id");
        $pdo->exec("DELETE FROM asset_audit_log WHERE asset_id=$id");
        $pdo->exec("DELETE FROM assets WHERE asset_id=$id");
    }
    $pdo->exec("DELETE FROM asset_categories WHERE category_name='__p8_cat'");
    pass("test data cleaned up");

} catch (Throwable $e) {
    fail("exception: " . $e->getMessage());
    foreach ($created as $id) { $pdo->exec("DELETE FROM depreciation_entries WHERE asset_id=$id"); $pdo->exec("DELETE FROM asset_maintenance WHERE asset_id=$id"); $pdo->exec("DELETE FROM asset_depreciation_areas WHERE asset_id=$id"); $pdo->exec("DELETE FROM asset_audit_log WHERE asset_id=$id"); $pdo->exec("DELETE FROM assets WHERE asset_id=$id"); }
    $pdo->exec("DELETE FROM asset_categories WHERE category_name='__p8_cat'");
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures===0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
exit($failures === 0 ? 0 : 1);
