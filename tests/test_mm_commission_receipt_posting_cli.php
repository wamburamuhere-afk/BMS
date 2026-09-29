<?php
/**
 * MM commission receipt posting — CLI test
 *   php tests/test_mm_commission_receipt_posting_cli.php
 *
 * Bug: postMMCommissionReceived() credited Commission Income, but income is
 * already recognised per transaction in postMMTransaction() (Dr E-Float | Cr
 * Commission Income) — so every receipt double-counted income. The receipt
 * must instead settle the accrued amount out of E-Float: Dr Bank | Cr E-Float.
 * Runtime section runs inside a transaction that is rolled back.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/mm_posting.php";
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

section('1. Source contract');
$src = file_get_contents("$root/core/mm_posting.php");
$fn  = substr($src, strpos($src, 'function postMMCommissionReceived'));
$fn  = substr($fn, 0, strpos($fn, 'postLedgerEntry('));
ok(strpos($fn, "\$accts['efloat']") !== false, 'receipt credits the network E-Float account');
ok(strpos($fn, "\$accts['commission']") === false, 'receipt no longer touches Commission Income');

section('2. Runtime — receipt posts Dr Bank | Cr E-Float (rolled back)');
$tables = $pdo->query("SHOW TABLES LIKE 'mm_networks'")->fetchColumn();
if (!$tables) { ok(true, 'mm_networks absent in this DB — runtime section skipped'); exit; }

$net = $pdo->query("SELECT network_id, float_account_id FROM mm_networks WHERE float_account_id IS NOT NULL AND status='active' ORDER BY network_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$hasCashFloat = (int)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='mm_gl_cash_float' LIMIT 1")->fetchColumn();
if (!$net || !$hasCashFloat) { ok(true, 'no MM network with GL accounts configured — runtime section skipped'); exit; }

$bank = (int)$pdo->query("SELECT account_id FROM accounts WHERE account_type='asset' AND status='active' AND account_id != " . (int)$net['float_account_id'] . " ORDER BY account_id LIMIT 1")->fetchColumn();
ok($bank > 0, "have a bank/asset account (#$bank)");

$pdo->beginTransaction();
try {
    $entryId = postMMCommissionReceived($pdo, 999999, (int)$net['network_id'], 12345.00, $bank, date('Y-m-d'), 4, 'CLI test receipt');
    ok($entryId > 0, "journal entry posted (#$entryId)");

    $lines = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ? ORDER BY type");
    $lines->execute([$entryId]);
    $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
    ok(count($rows) === 2, 'exactly two legs');

    $dr = array_values(array_filter($rows, fn($r) => $r['type'] === 'debit'))[0] ?? null;
    $cr = array_values(array_filter($rows, fn($r) => $r['type'] === 'credit'))[0] ?? null;
    ok($dr && (int)$dr['account_id'] === $bank && (float)$dr['amount'] === 12345.00, 'Dr Bank 12,345');
    ok($cr && (int)$cr['account_id'] === (int)$net['float_account_id'] && (float)$cr['amount'] === 12345.00, 'Cr E-Float 12,345 (not Commission Income)');
} catch (Throwable $e) {
    ok(false, 'posting threw: ' . $e->getMessage());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
