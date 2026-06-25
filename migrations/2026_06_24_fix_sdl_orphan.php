<?php
/**
 * Migration: 2026_06_24_fix_sdl_orphan
 * Delete the orphaned SDL journal_entry (entry_id=20423, originally mirroring
 * books_transaction 1061 for "SDL accrual 2026-06") whose transactions row was
 * deleted without cleaning up the mirror.  A second SDL accrual for 2026-06
 * (entry_id=70468, txn=9937) already exists and is correct; leaving entry 20423
 * alive double-counts SDL Expense and SDL Payable on every report.
 *
 * postSdlAccrual() is also patched (core/payment_source.php) so this cannot
 * recur for future periods.
 */

require_once __DIR__ . '/../roots.php';

$pdo->beginTransaction();
try {
    $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = 20423")->execute();
    $deleted = $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = 20423 AND entity_type = 'books_transaction'")->execute();
    $pdo->commit();
    echo "Done — orphan SDL journal_entry 20423 removed.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "Failed: " . $e->getMessage() . "\n";
}
