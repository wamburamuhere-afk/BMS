<?php
/**
 * 2026_07_06_payroll_gl_orphan_heal.php
 * -------------------------------------
 * Heals payroll GL orphaned by a bare delete of the source. When a `payroll` row
 * is physically removed (raw DELETE, or an employee deleted while payroll still
 * exists) instead of Voided, its posted journal_entries are left stranded:
 * Salaries Payable / PAYE / Salaries Expense (and the employee 2-1440-EMP-NNNNN
 * sub-account) stay overstated forever. delete_payroll.php reverses correctly;
 * this migration retro-fixes rows deleted the wrong way.
 *
 * Any POSTED journal_entries whose source no longer exists —
 * entity_type in ('payroll','payroll_accrual','payroll_payment') and entity_id
 * NOT in `payroll` — is reversed with a balanced contra (entity_type
 * '<type>_void'), so the affected accounts return to their true balance. The
 * original is stamped as reversed via reverses_entry_id.
 *
 * Criteria-based + idempotent: only orphaned, not-already-reversed entries are
 * touched. Re-run safe; balance-checked. Mirrors 2026_06_23_asset_delete_orphan_heal.php.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/ledger_post.php';        // postLedgerEntry
require_once __DIR__ . '/../core/financial_reports.php';  // assertLedgerBalanced
global $pdo;

echo "Starting migration: heal orphaned payroll GL...\n";

try {
    // Dedupe key is journal_entries.reverses_entry_id. If the column isn't present
    // yet (older schema), skip safely rather than risk a partial heal.
    $hasRev = $pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'reverses_entry_id'")->fetch();
    if (!$hasRev) {
        echo "  ! journal_entries.reverses_entry_id missing — skipping (run the journal foundation migration first).\n";
        exit(0);
    }

    // No payroll table at all → nothing to check against; skip.
    if (!$pdo->query("SHOW TABLES LIKE 'payroll'")->fetch()) {
        echo "  ! payroll table missing — skipping.\n";
        exit(0);
    }

    $uid = (int)($pdo->query("SELECT MIN(user_id) FROM users")->fetchColumn() ?: 1);

    $rows = $pdo->query("
        SELECT je.entry_id, je.entity_type, je.entity_id, je.entry_date, je.project_id
          FROM journal_entries je
         WHERE je.status = 'posted'
           AND je.entity_type IN ('payroll','payroll_accrual','payroll_payment')
           AND je.entity_id IS NOT NULL
           AND je.entity_id NOT IN (SELECT payroll_id FROM payroll)
           AND NOT EXISTS (SELECT 1 FROM journal_entries v WHERE v.reverses_entry_id = je.entry_id)
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  Found " . count($rows) . " orphaned payroll entr" . (count($rows) === 1 ? 'y' : 'ies') . " to reverse.\n";

    $pdo->beginTransaction();
    $healed = 0;
    foreach ($rows as $r) {
        $eid   = (int)$r['entry_id'];
        $items = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ?");
        $items->execute([$eid]);
        $lines = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $lines[] = [
                'account_id'  => (int)$it['account_id'],
                'type'        => $it['type'] === 'debit' ? 'credit' : 'debit',
                'amount'      => (float)$it['amount'],
                'description' => 'Reversal — payroll source deleted (orphan heal)',
            ];
        }
        if (count($lines) < 2) { echo "  · entry #$eid skipped (not a multi-line entry)\n"; continue; }

        $pid  = ($r['project_id'] !== null && $r['project_id'] !== '') ? (int)$r['project_id'] : null;
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r['entry_date']) ? (string)$r['entry_date'] : date('Y-m-d');
        $newId = postLedgerEntry(
            $pdo,
            "Payroll source deleted — reverse orphaned {$r['entity_type']} (entry #$eid)",
            $lines, $pid, (int)$r['entity_id'], $r['entity_type'] . '_void', $date, $uid
        );
        // Stamp the NEW contra with the entry it reverses (dedupe key). The original
        // stays 'posted'; the balanced contra nets it to zero in every report — so the
        // account returns to its true balance without double-removing (reports sum
        // status='posted' only, so we must NOT also flip the original to 'reversed').
        $pdo->prepare("UPDATE journal_entries SET reverses_entry_id = ? WHERE entry_id = ?")->execute([$eid, $newId]);
        $healed++;
        echo "  + reversed entry #$eid ({$r['entity_type']}) -> new #$newId\n";
    }
    $pdo->commit();

    $bal = assertLedgerBalanced($pdo, date('Y-m-d'));
    echo "  Ledger balanced after heal: " . (!empty($bal['ledger_balanced']) ? 'YES' : 'NO') . "\n";

    echo "\nMigration complete. Healed $healed orphan(s).\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
