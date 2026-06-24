<?php
/**
 * 2026_06_23_asset_delete_orphan_heal.php
 * ---------------------------------------
 * Heals asset GL orphaned by the OLD bare `DELETE FROM assets` (before
 * delete_asset.php reversed the ledger). Any POSTED journal_entries for an asset
 * that no longer exists — entity_type in ('asset_acquisition','asset',
 * 'asset_disposal') — is reversed with a balanced contra, so Fixed Assets / AP /
 * Accumulated Depreciation / Depreciation Expense are no longer overstated.
 *
 * Criteria-based + idempotent: only entries whose entity_id is absent from `assets`
 * AND not already reversed (no contra with reverses_entry_id = that entry) are
 * touched. Re-run safe; balance-checked. DML only — transaction is fine.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/ledger_post.php';        // postLedgerEntry
require_once __DIR__ . '/../core/financial_reports.php';  // assertLedgerBalanced
global $pdo;

echo "Starting migration: heal orphaned asset GL...\n";

try {
    // The dedupe key is journal_entries.reverses_entry_id. If the column isn't
    // present yet (older schema), skip safely rather than risk a partial heal.
    $hasRev = $pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'reverses_entry_id'")->fetch();
    if (!$hasRev) {
        echo "  ! journal_entries.reverses_entry_id missing — skipping (run the journal foundation migration first).\n";
        exit(0);
    }

    $uid = (int)($pdo->query("SELECT MIN(user_id) FROM users")->fetchColumn() ?: 1);

    $rows = $pdo->query("
        SELECT je.entry_id, je.entity_type, je.entity_id, je.entry_date, je.project_id
          FROM journal_entries je
         WHERE je.status = 'posted'
           AND je.entity_type IN ('asset_acquisition','asset','asset_disposal')
           AND je.entity_id NOT IN (SELECT asset_id FROM assets)
           AND NOT EXISTS (SELECT 1 FROM journal_entries v WHERE v.reverses_entry_id = je.entry_id)
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  Found " . count($rows) . " orphaned asset entr" . (count($rows) === 1 ? 'y' : 'ies') . " to reverse.\n";

    $pdo->beginTransaction();
    $healed = 0;
    foreach ($rows as $r) {
        $eid = (int)$r['entry_id'];
        $items = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ?");
        $items->execute([$eid]);
        $lines = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $lines[] = [
                'account_id'  => (int)$it['account_id'],
                'type'        => $it['type'] === 'debit' ? 'credit' : 'debit',
                'amount'      => (float)$it['amount'],
                'description' => 'Reversal — asset deleted (orphan heal)',
            ];
        }
        if (count($lines) < 2) { echo "  · entry #$eid skipped (not a multi-line entry)\n"; continue; }

        $pid  = ($r['project_id'] !== null && $r['project_id'] !== '') ? (int)$r['project_id'] : null;
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r['entry_date']) ? (string)$r['entry_date'] : date('Y-m-d');
        $newId = postLedgerEntry(
            $pdo,
            "Asset deleted — reverse orphaned {$r['entity_type']} (entry #$eid)",
            $lines, $pid, (int)$r['entity_id'], $r['entity_type'] . '_void', $date, $uid
        );
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
