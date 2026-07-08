<?php
/**
 * Payroll GL orphan-heal CLI test.
 *   php tests/test_payroll_gl_orphan_heal_cli.php
 *
 * Proves migrations/2026_07_06_payroll_gl_orphan_heal.php reverses posted payroll
 * journal entries whose source payroll row no longer exists — mirroring the asset
 * orphan heal. Seeds a posted 'payroll_accrual' referencing a payroll_id that is
 * NOT in the payroll table (i.e. a deleted source), runs the migration, and
 * asserts a balanced contra is posted, the original stays posted, the pair nets to
 * zero, and re-running heals nothing more (idempotent).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/ledger_post.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

echo "\n=== Payroll GL orphan heal ===\n";

$accId = function ($code) use ($pdo) {
    $s = $pdo->prepare("SELECT account_id FROM accounts WHERE account_code = ? LIMIT 1");
    $s->execute([$code]);
    return (int)$s->fetchColumn();
};
$sp  = $accId('2-1440');   // Salaries Payable
$exp = $accId('6-2410');   // Wages & Salaries expense
if (!$sp || !$exp) { echo "  \033[33m⚠ SKIP\033[0m — accounts 2-1440 / 6-2410 not present.\n"; exit(0); }

// A payroll_id guaranteed absent from the payroll table = a deleted source
$orphanPid = (int)$pdo->query("SELECT COALESCE(MAX(payroll_id),0) + 987654 FROM payroll")->fetchColumn();

// posted-only contribution of ONE account across a given set of entries
$pairBal = function (int $acc, array $ids) use ($pdo) {
    if (!$ids) return 0.0;
    $in = implode(',', array_map('intval', $ids));
    return (float)$pdo->query("
        SELECT COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END),0)
          FROM journal_entry_items jei
          JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status='posted'
         WHERE jei.account_id = $acc AND jei.entry_id IN ($in)")->fetchColumn();
};

// 1. Seed a posted, orphaned payroll accrual: Dr expense / Cr Salaries Payable
$amt   = 123456.78;
$lines = [
    ['account_id' => $exp, 'type' => 'debit',  'amount' => $amt, 'description' => 'TEST orphan accrual'],
    ['account_id' => $sp,  'type' => 'credit', 'amount' => $amt, 'description' => 'TEST orphan accrual'],
];
$entryId = postLedgerEntry($pdo, "TEST orphan payroll accrual (pid $orphanPid)", $lines,
                           null, $orphanPid, 'payroll_accrual', date('Y-m-d'), 4);
ok($entryId > 0, "1. Seeded a posted orphaned payroll_accrual (#$entryId)");
ok(abs($pairBal($sp, [$entryId]) - $amt) < 0.01, "1b. It credits Salaries Payable by $amt while live");

// 2. Run the heal migration
$mig = "$root/migrations/2026_07_06_payroll_gl_orphan_heal.php";
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mig) . ' 2>&1');
ok(preg_match('/Healed\s+(\d+)/', $out, $m) && (int)$m[1] >= 1, '2. Migration reports healing at least one orphan');
ok(stripos($out, 'Ledger balanced after heal: YES') !== false, '2b. Ledger balanced after heal');

// 3. A balanced contra was posted against our entry; original stays posted
$contra = $pdo->query("SELECT entry_id, entity_type, status FROM journal_entries WHERE reverses_entry_id = $entryId")->fetch(PDO::FETCH_ASSOC);
ok($contra !== false, '3. A contra entry references the orphan via reverses_entry_id');
$contraId = (int)($contra['entry_id'] ?? 0);
ok(($contra['status'] ?? '') === 'posted' && ($contra['entity_type'] ?? '') === 'payroll_accrual_void',
   "3b. Contra is posted with entity_type payroll_accrual_void");
$origStatus = $pdo->query("SELECT status FROM journal_entries WHERE entry_id = $entryId")->fetchColumn();
ok($origStatus === 'posted', '3c. Original stays posted (offset by the contra, not double-removed)');

// 4. Orphan + contra net to zero on both accounts → true balance restored
ok(abs($pairBal($sp,  [$entryId, $contraId])) < 0.01, '4. Salaries Payable net zero across orphan + contra');
ok(abs($pairBal($exp, [$entryId, $contraId])) < 0.01, '4b. Expense net zero across orphan + contra');

// 5. Idempotent — re-running does not add a second contra for our entry
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mig) . ' 2>&1');
$contraCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reverses_entry_id = $entryId")->fetchColumn();
ok($contraCount === 1, '5. Re-run is idempotent (still exactly one contra)');

// ── cleanup: remove the contra and the seeded orphan (items + headers)
foreach (array_filter([$contraId, $entryId]) as $id) {
    $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$id]);
}

echo "\n" . ($fail === 0 ? "\033[32mALL PASSED" : "\033[31m$fail FAILED") . "\033[0m — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
