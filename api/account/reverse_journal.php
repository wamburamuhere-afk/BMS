<?php
/**
 * api/account/reverse_journal.php
 * -------------------------------
 * Reverses a POSTED manual journal entry by posting its balanced contra
 * (Dr↔Cr flipped) and marking the original 'reversed'. Mirrors the entry into
 * books_transactions like save_journal. Posted entries are immutable, so a
 * reversal is the correct way to undo one.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
require_once __DIR__ . '/../../core/recon_period_lock.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('journals')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

$id = (int)($_POST['entry_id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid entry ID']); exit; }

try {
    $e = $pdo->prepare("SELECT * FROM journal_entries WHERE entry_id = ?");
    $e->execute([$id]);
    $entry = $e->fetch(PDO::FETCH_ASSOC);
    if (!$entry) { echo json_encode(['success' => false, 'message' => 'Journal entry not found']); exit; }
    if ($entry['status'] !== 'posted') { echo json_encode(['success' => false, 'message' => 'Only a posted journal can be reversed.']); exit; }

    // Period lock: block reversal if the entry falls in a finalized reconciliation period
    assertNotInFinalizedReconPeriod($pdo, $id);

    // Already reversed?
    $dup = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE reverses_entry_id = ? LIMIT 1");
    $dup->execute([$id]);
    if ($dup->fetchColumn()) { echo json_encode(['success' => false, 'message' => 'This journal has already been reversed.']); exit; }

    $items = $pdo->prepare("SELECT account_id, type, amount, description FROM journal_entry_items WHERE entry_id = ?");
    $items->execute([$id]);
    $lines = $items->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) { echo json_encode(['success' => false, 'message' => 'Original journal has no lines.']); exit; }

    $total = 0.0;
    foreach ($lines as $l) if ($l['type'] === 'debit') $total += (float)$l['amount'];
    $refNo = 'REV-' . ($entry['reference_number'] ?: $id);
    $desc  = 'Reversal of ' . ($entry['reference_number'] ?: ('journal #' . $id)) . ' — ' . (string)$entry['description'];
    $date  = date('Y-m-d');
    $pid   = ($entry['project_id'] !== null && $entry['project_id'] !== '') ? (int)$entry['project_id'] : null;

    $pdo->beginTransaction();

    $pdo->prepare("INSERT INTO journal_entries
                     (entry_date, reference_number, description, notes, status, created_by,
                      debit_account_id, credit_account_id, amount, reverses_entry_id, project_id, created_at)
                   VALUES (?, ?, ?, ?, 'posted', ?, 0, 0, ?, ?, ?, NOW())")
        ->execute([$date, $refNo, $desc, 'Auto-generated reversal', $_SESSION['user_id'], $total, $id, $pid]);
    $revId = (int)$pdo->lastInsertId();

    $jitems = [];
    foreach ($lines as $l) {
        $flip = $l['type'] === 'debit' ? 'credit' : 'debit';
        $pdo->prepare("INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$revId, (int)$l['account_id'], $flip, (float)$l['amount'], 'Reversal: ' . (string)($l['description'] ?? '')]);
        $jitems[] = ['type' => $flip, 'account_id' => (int)$l['account_id'], 'amount' => (float)$l['amount'], 'description' => 'Reversal'];
    }

    $txn = recordGlobalTransaction([
        'journal_id'         => $revId,
        'transaction_date'   => $date,
        'amount'             => $total,
        'transaction_type'   => 'journal',
        'reference_number'   => $refNo,
        'description'        => $desc,
        'project_id'         => $pid,
        'journal_items'      => $jitems,
        'skip_journal_mirror'=> true,
    ], $pdo);
    if (empty($txn['success'])) { throw new Exception('Ledger posting failed: ' . ($txn['error'] ?? 'unknown')); }

    $pdo->prepare("UPDATE journal_entries SET transaction_id = ? WHERE entry_id = ?")->execute([$txn['transaction_id'], $revId]);
    $pdo->prepare("UPDATE journal_entries SET status = 'reversed' WHERE entry_id = ?")->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'], "Reversed journal entry #$id (reversal #$revId, {$refNo})");
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Journal reversed. Reversal entry ' . $refNo . ' posted.', 'reversal_entry_id' => $revId]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('reverse_journal error: ' . $ex->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not reverse: ' . $ex->getMessage()]);
}
