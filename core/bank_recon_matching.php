<?php
/**
 * core/bank_recon_matching.php
 * -----------------------------
 * Bank Reconciliation Phase 5 — shared pairing logic between a BOOK-side row
 * (bank_transactions.imported_from IS NULL — written automatically by
 * core/bank_register.php whenever BMS posts a cash-moving event) and a
 * STATEMENT-side row (imported_from IS NOT NULL — written by a CSV import)
 * that represent the SAME real-world bank movement.
 *
 * Without pairing, an imported statement line and its already-posted
 * book-side counterpart would sit in the worksheet as two separate
 * "unmatched" rows for one economic event, and get_reconciliation_lines.php
 * would subtract BOTH from book_balance — double-counting an event that is
 * already fully reflected in the ledger. Pairing makes match/unmatch always
 * move both sides together, so that can't happen.
 *
 * matched_transaction_id has two meanings depending on matching_status:
 *   - set, status still 'unmatched' -> a SUGGESTED pairing (not yet
 *     confirmed against any specific reconciliation).
 *   - set, status = 'matched'/'manual' -> a CONFIRMED pairing, both rows
 *     carry the same reconciliation_id.
 *
 * Additive only: every function here is new; nothing existing is changed by
 * loading this file, and rows with matched_transaction_id = NULL behave
 * exactly as they did before this file existed.
 */

if (!function_exists('suggestBankRecMatches')) {
    /**
     * Greedy nearest-date pairing between unmatched book-side and unmatched
     * statement-side rows on one bank account. Pairs must share the same
     * amount + transaction_type and fall within $windowDays of each other.
     * One-to-one: a row is used in at most one suggested pair per call.
     * Idempotent — rows that already carry a matched_transaction_id
     * (suggested or confirmed) are excluded as candidates.
     *
     * @return array<int, array{book_transaction_id:int, statement_transaction_id:int, date_diff_days:int}>
     */
    function suggestBankRecMatches(PDO $pdo, int $bankAccountId, int $windowDays = 5): array
    {
        $bookStmt = $pdo->prepare("
            SELECT transaction_id, transaction_date, amount, transaction_type
              FROM bank_transactions
             WHERE bank_account_id = ?
               AND imported_from IS NULL
               AND matched_transaction_id IS NULL
               AND COALESCE(matching_status,'unmatched') = 'unmatched'
        ");
        $bookStmt->execute([$bankAccountId]);
        $bookRows = $bookStmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtStmt = $pdo->prepare("
            SELECT transaction_id, transaction_date, amount, transaction_type
              FROM bank_transactions
             WHERE bank_account_id = ?
               AND imported_from IS NOT NULL
               AND matched_transaction_id IS NULL
               AND COALESCE(matching_status,'unmatched') = 'unmatched'
        ");
        $stmtStmt->execute([$bankAccountId]);
        $stmtRows = $stmtStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$bookRows || !$stmtRows) return [];

        // Every valid candidate pair (same amount + type, within the window).
        $candidates = [];
        foreach ($bookRows as $b) {
            foreach ($stmtRows as $s) {
                if ($b['transaction_type'] !== $s['transaction_type']) continue;
                if (abs((float)$b['amount'] - (float)$s['amount']) >= 0.01) continue;
                $diffDays = (int)round(abs(strtotime($b['transaction_date']) - strtotime($s['transaction_date'])) / 86400);
                if ($diffDays > $windowDays) continue;
                $candidates[] = [
                    'book_transaction_id'      => (int)$b['transaction_id'],
                    'statement_transaction_id' => (int)$s['transaction_id'],
                    'date_diff_days'           => $diffDays,
                ];
            }
        }

        // Greedy nearest-date-first assignment, one-to-one on both sides.
        usort($candidates, fn($a, $z) => $a['date_diff_days'] <=> $z['date_diff_days']);
        $usedBook = []; $usedStmt = []; $accepted = [];
        foreach ($candidates as $c) {
            if (isset($usedBook[$c['book_transaction_id']]) || isset($usedStmt[$c['statement_transaction_id']])) continue;
            $usedBook[$c['book_transaction_id']] = true;
            $usedStmt[$c['statement_transaction_id']] = true;
            $accepted[] = $c;
        }

        if ($accepted) {
            $upd = $pdo->prepare("UPDATE bank_transactions SET matched_transaction_id = ?, updated_at = NOW() WHERE transaction_id = ?");
            foreach ($accepted as $c) {
                $upd->execute([$c['statement_transaction_id'], $c['book_transaction_id']]);
                $upd->execute([$c['book_transaction_id'],      $c['statement_transaction_id']]);
            }
        }
        return $accepted;
    }
}

if (!function_exists('confirmBankRecMatchPair')) {
    /**
     * Confirms a book<->statement pair as matched against a specific
     * reconciliation. Validates: both rows exist, share the bank account,
     * one is book-side and the other statement-side, equal amount + type,
     * and neither is already matched/manual/reconciled elsewhere.
     * Throws Exception with a user-facing message on any violation.
     */
    function confirmBankRecMatchPair(PDO $pdo, int $lineAId, int $lineBId, int $reconciliationId): void
    {
        $sel = $pdo->prepare("SELECT * FROM bank_transactions WHERE transaction_id = ?");
        $sel->execute([$lineAId]);
        $a = $sel->fetch(PDO::FETCH_ASSOC);
        $sel->execute([$lineBId]);
        $b = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$a || !$b) throw new Exception('One or both transaction lines not found.');
        if ((int)$a['bank_account_id'] !== (int)$b['bank_account_id']) throw new Exception('Lines belong to different bank accounts.');

        $aImported = $a['imported_from'] !== null;
        $bImported = $b['imported_from'] !== null;
        if ($aImported === $bImported) throw new Exception('A pair must have one book-side line and one statement-side line.');

        if (abs((float)$a['amount'] - (float)$b['amount']) >= 0.01) throw new Exception('Amounts do not match.');
        if ($a['transaction_type'] !== $b['transaction_type']) throw new Exception('Transaction types do not match.');

        foreach ([$a, $b] as $row) {
            if (in_array($row['matching_status'], ['matched', 'manual', 'reconciled'], true)) {
                throw new Exception('One of these lines is already matched.');
            }
        }

        $upd = $pdo->prepare("
            UPDATE bank_transactions
               SET matching_status = 'matched', reconciliation_id = ?, matched_transaction_id = ?,
                   status = 'cleared', updated_at = NOW()
             WHERE transaction_id = ?
        ");
        $upd->execute([$reconciliationId, $lineBId, $lineAId]);
        $upd->execute([$reconciliationId, $lineAId, $lineBId]);
        // Logging is the caller's responsibility (matches every other
        // reconciliation endpoint) — callers already log the action that
        // led here, so this function stays a pure data-layer helper.
    }
}

if (!function_exists('releaseBankRecMatchPair')) {
    /**
     * Reverts a line to unmatched. If it is part of a confirmed pair
     * (matched_transaction_id set), the counterpart is reverted too so the
     * pair never ends up half-matched. matched_transaction_id itself is
     * kept on both rows — the pairing remains a suggestion the user can
     * re-confirm with one click. Rows with no counterpart behave exactly as
     * a plain single-line unmatch always has.
     */
    function releaseBankRecMatchPair(PDO $pdo, int $lineId): void
    {
        $sel = $pdo->prepare("SELECT transaction_id, matched_transaction_id FROM bank_transactions WHERE transaction_id = ?");
        $sel->execute([$lineId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $ids = [(int)$row['transaction_id']];
        if (!empty($row['matched_transaction_id'])) {
            $ids[] = (int)$row['matched_transaction_id'];
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("
            UPDATE bank_transactions
               SET matching_status = 'unmatched', reconciliation_id = NULL, status = 'pending', updated_at = NOW()
             WHERE transaction_id IN ($ph)
        ")->execute($ids);
    }
}

if (!function_exists('breakBankRecMatchPair')) {
    /**
     * Clears matched_transaction_id on a line and its counterpart (if any),
     * WITHOUT touching matching_status on either side. Use before a line is
     * set to 'ignored' (or otherwise removed from consideration): without
     * this, the counterpart's stale pointer would let a later "match" click
     * on the counterpart silently confirm a pair against — and un-ignore —
     * a line the user deliberately excluded.
     */
    function breakBankRecMatchPair(PDO $pdo, int $lineId): void
    {
        $sel = $pdo->prepare("SELECT transaction_id, matched_transaction_id FROM bank_transactions WHERE transaction_id = ?");
        $sel->execute([$lineId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['matched_transaction_id'])) return;

        $ids = [(int)$row['transaction_id'], (int)$row['matched_transaction_id']];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE bank_transactions SET matched_transaction_id = NULL, updated_at = NOW() WHERE transaction_id IN ($ph)")
            ->execute($ids);
    }
}
