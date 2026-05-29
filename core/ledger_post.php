<?php
/**
 * core/ledger_post.php
 * --------------------
 * Phase 0.3 — canonical helper for posting journal entries to the
 * journal_entries + journal_entry_items ledger.
 *
 * Every Phase 4 auto-posting hook (invoice-approved, expense-paid,
 * payroll-paid, GRN-approved, etc.) will call postLedgerEntry()
 * instead of writing raw SQL. This guarantees:
 *   - Dr = Cr per entry (double-entry integrity, enforced before write)
 *   - All-or-nothing posting (one transaction wraps header + all lines)
 *   - Status auto-set to 'posted' (no half-posted auto-entries)
 *   - Source-doc link captured (entity_type, entity_id from Phase 0.1)
 *   - Project scope captured (project_id from Phase 0.1)
 *
 * Throws LedgerException on:
 *   - <2 lines (no single-sided entries)
 *   - sum(debits) ≠ sum(credits) (unbalanced)
 *   - any line missing account_id / type / amount
 *   - type not in {'debit', 'credit'}
 *   - amount ≤ 0
 *   - account_id not present in accounts table
 *   - description empty
 *   - date not in YYYY-MM-DD format
 *
 * Returns the new entry_id (int).
 */

if (!class_exists('LedgerException')) {
    class LedgerException extends RuntimeException {}
}

if (!function_exists('postLedgerEntry')) {
    /**
     * Post a balanced journal entry to the canonical ledger.
     *
     * @param PDO          $pdo
     * @param string       $description   Short text shown in Trial Balance + GL.
     * @param array        $lines         List of ['account_id'=>int, 'type'=>'debit'|'credit', 'amount'=>float, 'description'?=>string].
     *                                    At least 2 entries. Dr total must equal Cr total.
     * @param ?int         $project_id    Project to scope this entry. NULL = company-wide.
     * @param ?int         $entity_id     Source document PK (e.g. invoice_id). NULL if manual.
     * @param ?string      $entity_type   Source table identifier (e.g. 'invoice', 'expense'). NULL if manual.
     * @param string       $date          YYYY-MM-DD entry date.
     * @param int          $user_id       Posting user.
     *
     * @return int  New entry_id.
     *
     * @throws LedgerException on any validation failure.
     */
    function postLedgerEntry(
        PDO     $pdo,
        string  $description,
        array   $lines,
        ?int    $project_id,
        ?int    $entity_id,
        ?string $entity_type,
        string  $date,
        int     $user_id
    ): int {
        // ── Pre-flight validation (before any DB write) ─────────────────────
        $description = trim($description);
        if ($description === '') {
            throw new LedgerException('postLedgerEntry: description is required.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new LedgerException("postLedgerEntry: date must be YYYY-MM-DD, got '$date'.");
        }
        if (count($lines) < 2) {
            throw new LedgerException('postLedgerEntry: at least 2 lines required (single-sided entries not allowed).');
        }
        if ($user_id <= 0) {
            throw new LedgerException('postLedgerEntry: user_id must be positive.');
        }

        // Per-line validation + Dr/Cr totalling
        $total_debits  = 0.0;
        $total_credits = 0.0;
        $account_ids   = [];
        $first_debit_account_id  = null;
        $first_credit_account_id = null;

        foreach ($lines as $i => $line) {
            if (!is_array($line)) {
                throw new LedgerException("postLedgerEntry: line[$i] is not an array.");
            }
            $aid    = isset($line['account_id']) ? (int)$line['account_id'] : 0;
            $type   = $line['type']   ?? '';
            $amount = isset($line['amount']) ? (float)$line['amount'] : 0.0;

            if ($aid <= 0) {
                throw new LedgerException("postLedgerEntry: line[$i].account_id missing or non-positive.");
            }
            if (!in_array($type, ['debit', 'credit'], true)) {
                throw new LedgerException("postLedgerEntry: line[$i].type must be 'debit' or 'credit', got '$type'.");
            }
            if ($amount <= 0) {
                throw new LedgerException("postLedgerEntry: line[$i].amount must be > 0, got $amount.");
            }

            $account_ids[] = $aid;
            if ($type === 'debit') {
                $total_debits  += $amount;
                if ($first_debit_account_id === null)  $first_debit_account_id  = $aid;
            } else {
                $total_credits += $amount;
                if ($first_credit_account_id === null) $first_credit_account_id = $aid;
            }
        }

        if ($first_debit_account_id === null || $first_credit_account_id === null) {
            throw new LedgerException('postLedgerEntry: each entry must have at least one debit AND one credit line.');
        }

        // Balance check with rounding tolerance (0.01 TZS)
        if (abs($total_debits - $total_credits) > 0.01) {
            throw new LedgerException(sprintf(
                "postLedgerEntry: unbalanced entry — debits=%.2f, credits=%.2f, diff=%.2f.",
                $total_debits, $total_credits, $total_debits - $total_credits
            ));
        }

        // Account existence check (single query)
        $unique_aids = array_values(array_unique($account_ids));
        $ph = implode(',', array_fill(0, count($unique_aids), '?'));
        $check = $pdo->prepare("SELECT account_id FROM accounts WHERE account_id IN ($ph)");
        $check->execute($unique_aids);
        $found = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_diff($unique_aids, $found);
        if (!empty($missing)) {
            throw new LedgerException('postLedgerEntry: account_id(s) not found in accounts table: ' . implode(',', $missing) . '.');
        }

        // ── Atomic write: header + all lines in one transaction ─────────────
        $started_tx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started_tx = true;
            }

            // Reference number — unique, human-readable
            $reference = 'JRNL-' . date('YmdHis') . '-' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);

            // Header — note: existing schema requires debit_account_id,
            // credit_account_id, amount on the header (legacy 2-line format).
            // We populate them with the first debit/credit account and the
            // total amount so the header itself stays balanced.
            $stmt = $pdo->prepare("
                INSERT INTO journal_entries
                    (entry_date, reference_number, description,
                     debit_account_id, credit_account_id, amount,
                     status, created_by,
                     project_id, entity_id, entity_type,
                     created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, 'posted', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $date, $reference, $description,
                $first_debit_account_id, $first_credit_account_id, $total_debits,
                $user_id,
                $project_id, $entity_id, $entity_type,
            ]);
            $entry_id = (int)$pdo->lastInsertId();

            // Lines
            $line_stmt = $pdo->prepare("
                INSERT INTO journal_entry_items
                    (entry_id, account_id, type, amount, description)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($lines as $line) {
                $line_stmt->execute([
                    $entry_id,
                    (int)$line['account_id'],
                    $line['type'],
                    (float)$line['amount'],
                    $line['description'] ?? null,
                ]);
            }

            if ($started_tx) {
                $pdo->commit();
            }
            return $entry_id;
        } catch (Throwable $e) {
            if ($started_tx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // If we wrapped someone else's transaction, propagate the failure
            // so they can decide to rollback themselves.
            if ($e instanceof LedgerException) throw $e;
            throw new LedgerException('postLedgerEntry: write failed — ' . $e->getMessage(), 0, $e);
        }
    }
}

if (!function_exists('assertJournalNotPosted')) {
    /**
     * Defensive guard for any future "edit/delete journal entry" code path.
     *
     * Posted journal entries are immutable per accounting best practice — to
     * reverse a posted entry you post a contra-entry, you don't edit the
     * original. This helper centralises that rule.
     *
     * Throws LedgerException in any of these cases:
     *   - $entry_id is <= 0                          (bad input)
     *   - journal entry with that id doesn't exist   (strict, per Phase 0.4 spec)
     *   - status = 'posted'                          (immutable — primary case)
     *   - status = 'void'                            (terminal state)
     *   - status = 'reversed'                        (terminal state)
     *   - status is NULL or any unknown value        (refuse to risk a wrong call)
     *
     * Silently returns (no-op) only when status = 'draft'. Drafts are the
     * only state in which a journal entry can still be edited / promoted.
     *
     * @param PDO $pdo
     * @param int $entry_id
     * @return void
     * @throws LedgerException
     */
    function assertJournalNotPosted(PDO $pdo, int $entry_id): void
    {
        if ($entry_id <= 0) {
            throw new LedgerException("assertJournalNotPosted: invalid entry_id ($entry_id).");
        }

        $stmt = $pdo->prepare("SELECT status FROM journal_entries WHERE entry_id = ?");
        $stmt->execute([$entry_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new LedgerException(
                "assertJournalNotPosted: journal entry $entry_id not found — refusing to guard a missing record."
            );
        }

        $status = $row['status'];

        if ($status === 'posted') {
            throw new LedgerException(
                "assertJournalNotPosted: journal entry $entry_id is posted and immutable. "
                . "To reverse it, post a contra-entry instead."
            );
        }
        if ($status === 'void') {
            throw new LedgerException(
                "assertJournalNotPosted: journal entry $entry_id is voided — terminal state, cannot modify."
            );
        }
        if ($status === 'reversed') {
            throw new LedgerException(
                "assertJournalNotPosted: journal entry $entry_id is reversed — terminal state, cannot modify."
            );
        }
        if ($status !== 'draft') {
            throw new LedgerException(
                "assertJournalNotPosted: journal entry $entry_id has unknown/null status '"
                . ($status === null ? 'NULL' : $status)
                . "' — refusing to modify."
            );
        }
        // status === 'draft' → safe to edit; return without throwing.
    }
}
