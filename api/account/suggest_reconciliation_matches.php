<?php
/**
 * api/account/suggest_reconciliation_matches.php
 * -------------------------------------------------
 * Bank Reconciliation Phase 5 — on-demand re-run of the pairing suggestion
 * engine (core/bank_recon_matching.php::suggestBankRecMatches) for one
 * reconciliation's bank account. Import already runs this once when
 * "auto_match" is checked; this lets the worksheet catch stragglers —
 * e.g. a book-side row posted AFTER the statement was imported, or an
 * account that already had unmatched rows before Phase 5 existed.
 *
 * Read/write-additive only: it can only set matched_transaction_id on rows
 * that are currently unmatched with no existing pairing; it never changes
 * matching_status, so it cannot affect an open reconciliation's
 * cleared/uncleared totals by itself.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/bank_recon_matching.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('bank_reconciliation')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$recId = (int)($_REQUEST['reconciliation_id'] ?? 0);
if ($recId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reconciliation ID']);
    exit;
}

$rec = $pdo->prepare("SELECT reconciliation_id, bank_account_id, status FROM bank_reconciliations WHERE reconciliation_id = ?");
$rec->execute([$recId]);
$rec = $rec->fetch(PDO::FETCH_ASSOC);
if (!$rec) {
    echo json_encode(['success' => false, 'message' => 'Reconciliation not found']);
    exit;
}
if (in_array($rec['status'], ['reconciled', 'cancelled'], true)) {
    echo json_encode(['success' => true, 'suggested' => [], 'message' => 'Reconciliation is closed; nothing to suggest.']);
    exit;
}

$suggested = suggestBankRecMatches($pdo, (int)$rec['bank_account_id']);

echo json_encode([
    'success'   => true,
    'suggested' => $suggested,
    'message'   => count($suggested) . ' new suggested match(es) found.',
]);
