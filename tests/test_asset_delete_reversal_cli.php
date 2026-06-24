<?php
/**
 * Asset delete reverses capitalisation + blocks posted history — CLI test
 *   php tests/test_asset_delete_reversal_cli.php
 *
 * Gap (account_financial.md #13): delete_asset.php was a bare `DELETE FROM assets`
 * that ORPHANED the asset's posted GL (acquisition + every depreciation charge +
 * any disposal). This verifies the professional fix:
 *   - assets.status enum supports the soft-delete value ('deleted'),
 *   - the endpoint reverses an acquisition-only asset's capitalisation on delete,
 *   - it blocks delete once depreciation/disposal entries exist.
 * Runtime drives the real posters inside a ROLLED-BACK transaction.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/asset_gl_service.php";   // postAssetAcquisition
require_once "$root/core/expense_posting.php";    // accrualEntryId / reverseAccrualEntry
require_once "$root/core/financial_reports.php";  // assertLedgerBalanced
require_once "$root/core/gl_accounts.php";        // fixedAssetAccountId / apAccountId
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'cli'; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses: \033[32m$pass\033[0m   Failures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

// ── 1. Schema — enum supports the soft-delete value ──────────────────────────
section('1. assets.status enum supports soft-delete');
$col = $pdo->query("SHOW COLUMNS FROM assets LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
ok($col && strpos($col['Type'], "'deleted'") !== false,
   "assets.status enum includes 'deleted' (migration 2026_06_23_assets_deleted_status)");

// ── 2. Source contracts in delete_asset.php ──────────────────────────────────
section('2. delete_asset.php — reversal + block wired');
$src = file_get_contents("$root/api/operations/delete_asset.php");
ok(strpos($src, 'core/expense_posting.php') !== false, 'includes core/expense_posting.php');
ok(strpos($src, "reverseAccrualEntry(\$pdo, 'asset_acquisition'") !== false, 'reverses the acquisition entry');
ok(strpos($src, "entity_type='asset'") !== false && strpos($src, "entity_type='asset_disposal'") !== false, 'checks posted depreciation + disposal');
ok(strpos($src, "status='deleted'") !== false, 'soft-deletes (status=deleted)');
ok(strpos($src, 'beginTransaction') !== false && preg_match('/catch[^{]*\{[^}]*rollBack/s', $src) === 1, 'wrapped in a transaction with rollback');
ok(strpos($src, 'DELETE FROM assets') === false, 'no longer a bare hard DELETE');

// ── 3. Runtime — acquisition posts, reversal nets to zero (rolled back) ──────
section('3. Runtime — acquire posts; delete reverses it (rolled back)');
$fa = (int)fixedAssetAccountId($pdo);
$ap = (int)apAccountId($pdo);
ok($fa > 0 && $ap > 0, "have Fixed Asset (#$fa) + Accounts Payable (#$ap)");

$FAKE = 999000931; $cost = 123456.00;
$pdo->beginTransaction();
try {
    $r = postAssetAcquisition($pdo, $FAKE, 'AST-TST', $cost, 'new', 0.0, null, date('Y-m-d'), null, 4);
    ok(!empty($r['posted']), 'acquisition posted (Dr Fixed Asset / Cr AP)');
    ok(accrualEntryId($pdo, 'asset_acquisition', $FAKE) !== null, 'acquisition entry exists');

    $bal = function (int $acc) use ($pdo, $FAKE): float {
        $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0)
            FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id AND je.status='posted'
            WHERE jei.account_id=? AND je.entity_id=? AND je.entity_type IN ('asset_acquisition','asset_acquisition_void')");
        $s->execute([$acc, $FAKE]);
        return round((float)$s->fetchColumn(), 2);
    };
    ok(abs($bal($fa) - $cost) < 0.01, "Fixed Asset carries +$cost after acquisition");
    ok(abs($bal($ap) + $cost) < 0.01, "AP carries -$cost after acquisition");

    // The exact call delete_asset.php makes for an acquisition-only asset.
    $rev = reverseAccrualEntry($pdo, 'asset_acquisition', $FAKE, 4);
    ok(!empty($rev['reversed']), 'reverseAccrualEntry posted the contra');
    ok(abs($bal($fa)) < 0.01, 'Fixed Asset nets to ZERO after delete (not overstated)');
    ok(abs($bal($ap)) < 0.01, 'AP nets to ZERO after delete (not overstated)');

    $rev2 = reverseAccrualEntry($pdo, 'asset_acquisition', $FAKE, 4);
    ok(($rev2['reason'] ?? '') === 'already_reversed', 'reversal is idempotent');

    ok(!empty(assertLedgerBalanced($pdo, date('Y-m-d'))['ledger_balanced']), 'ledger balanced after acquire + reverse');
} finally {
    $pdo->rollBack();
}
$leak = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id=$FAKE AND entity_type LIKE 'asset_acquisition%'")->fetchColumn();
ok($leak === 0, 'rolled back cleanly — no test rows persisted');
