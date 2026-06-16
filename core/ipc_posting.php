<?php
/**
 * core/ipc_posting.php
 * --------------------
 * money.md OUT-15: recognise interim payment certificate (IPC) contract revenue
 * in the canonical ledger when an IPC is certified (Approved). Construction
 * revenue previously never reached the GL — IPCs only fed the Income Statement
 * directly, so a single-source P&L would miss them.
 *
 * Double-entry (certified contract work billed to the client):
 *     Dr Accounts Receivable  /  Cr Contract Revenue   (net_payable)
 *
 * Amount = net_payable (certified − retention − previous payments). This is the
 * incremental amount due for the period — it nets out prior IPCs (no
 * cumulative double-count) and matches the basis used when an IPC is converted to
 * an invoice (create_invoice_from_ipc uses net_payable). Retention held back is
 * recognised when it is released (a later certificate), not here.
 *
 * Mutual-exclusion with the invoice path: an IPC that is converted to an invoice
 * still carries its revenue via THIS entry (entity_type='ipc'); the generated
 * invoice is a billing/collection document. postInvoiceRevenue() (IN-3) skips any
 * invoice already recognised through its IPC, so the two paths never double-count.
 *
 * Design rules (match the other B-series posters):
 *   - Best-effort: NEVER throws — certifying an IPC must not fail over accounting.
 *   - Idempotent on (entity_type='ipc', entity_id=ipc_id).
 *   - Joins the caller's open transaction; never touches accounts.current_balance.
 */

require_once __DIR__ . '/ledger_post.php';   // postLedgerEntry
require_once __DIR__ . '/gl_accounts.php';   // arAccountId, contractRevenueAccountId

if (!function_exists('_ipc_already_posted')) {
    function _ipc_already_posted(PDO $pdo, int $ipcId): ?int
    {
        $s = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type='ipc' AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$ipcId]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }
}

if (!function_exists('postIpcRevenue')) {
    /**
     * OUT-15 — recognise an Approved IPC's contract revenue. Never throws.
     *
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function postIpcRevenue(PDO $pdo, int $ipcId, int $userId): array
    {
        $out = ['posted' => false, 'reason' => ''];
        if ($ipcId <= 0) { $out['reason'] = 'invalid_ipc'; return $out; }

        if ($existing = _ipc_already_posted($pdo, $ipcId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $st = $pdo->prepare("SELECT ipc_number, ipc_date, net_payable, certified_amount, project_id, invoice_id
                               FROM interim_payment_certificates WHERE ipc_id = ?");
        $st->execute([$ipcId]);
        $ipc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ipc) { $out['reason'] = 'ipc_not_found'; return $out; }

        // Revenue = net_payable; fall back to certified_amount only if net_payable
        // is unset/zero (older rows that never computed it).
        $amount = round((float)$ipc['net_payable'], 2);
        if ($amount <= 0) $amount = round((float)$ipc['certified_amount'], 2);
        if ($amount <= 0) { $out['reason'] = 'no_amount'; return $out; }

        $ar  = arAccountId($pdo);
        $rev = contractRevenueAccountId($pdo);
        if (!$ar || !$rev) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$ipc['ipc_date']) ? substr((string)$ipc['ipc_date'], 0, 10) : date('Y-m-d');
        $pid  = !empty($ipc['project_id']) ? (int)$ipc['project_id'] : null;
        $desc = 'IPC ' . ($ipc['ipc_number'] ?: ('#' . $ipcId)) . ' — contract revenue certified';

        try {
            $entry = postLedgerEntry($pdo, $desc, [
                ['account_id' => (int)$ar,  'type' => 'debit',  'amount' => $amount, 'description' => 'Contract receivable (certified)'],
                ['account_id' => (int)$rev, 'type' => 'credit', 'amount' => $amount, 'description' => 'Contract revenue'],
            ], $pid, $ipcId, 'ipc', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postIpcRevenue failed (ipc $ipcId): " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}
