<?php
/**
 * core/auto_post_hook.php
 * ------------------------
 * Phase 4.3 — generic auto-posting hook that consumes journal_mappings
 * and posts to the canonical ledger via postLedgerEntry().
 *
 * All Phase 4.3–4.10 sub-steps (invoice approved, payment received,
 * expense paid, payroll paid, GRN approved, supplier payment, asset
 * purchased, depreciation run) call this one function. Each call:
 *
 *   1. Looks up the mapping row by event_type (UNIQUE).
 *   2. Returns ['posted' => false, 'reason' => 'mapping_inactive']
 *      if mapping.is_active = 0 — this is the kill-switch path. NOT
 *      an error; the admin has deliberately not turned this event on.
 *   3. Returns ['posted' => false, 'reason' => 'mapping_not_configured']
 *      if either FK is NULL. Defensive: should not happen because the
 *      admin UI refuses to activate without both FKs set, but if it
 *      does we treat it as a config gap, not a crash.
 *   4. Returns ['posted' => false, 'reason' => 'already_posted',
 *      'existing_entry_id' => N] if a posted journal entry already
 *      exists for (entity_type, entity_id). Idempotency — re-approving
 *      a document must NOT double-post.
 *   5. Otherwise calls postLedgerEntry() with the mapped Dr/Cr accounts
 *      and returns ['posted' => true, 'entry_id' => N].
 *
 * Throws LedgerException only when the caller passed bad data
 * (amount <= 0, invalid date, missing event_type row). The "mapping
 * inactive/not configured" cases are signalled via return, not throw,
 * so a caller running with the kill-switch off has zero impact on
 * existing flows.
 *
 * Project scope, entity link, and entry date all pass straight through
 * to postLedgerEntry().
 */

require_once __DIR__ . '/ledger_post.php';

if (!function_exists('autoPostEvent')) {
    /**
     * @param PDO       $pdo
     * @param string    $event_type   journal_mappings.event_type slug
     *                                 (e.g. 'invoice_approved').
     * @param string    $entity_type  Source-document table identifier
     *                                 (e.g. 'invoice'). Used for both the
     *                                 journal_entries.entity_type column
     *                                 AND the idempotency check.
     * @param int       $entity_id    Source-document primary key.
     * @param float     $amount       Net amount of the Dr/Cr (must be > 0).
     * @param ?int      $project_id   Project scope; pass-through to
     *                                 journal_entries.project_id.
     * @param string    $entry_date   Accounting period date, YYYY-MM-DD.
     *                                 For invoice-approved this is the
     *                                 invoice_date (matching principle).
     * @param int       $user_id      Posting user.
     * @param string    $description  Free-text description shown in
     *                                 Trial Balance + GL.
     *
     * @return array {
     *     posted: bool,
     *     reason?: 'mapping_inactive' | 'mapping_not_configured' |
     *              'already_posted' | 'posted',
     *     entry_id?: int,
     *     existing_entry_id?: int,
     *     event_type: string,
     * }
     *
     * @throws LedgerException on caller error (bad amount, bad date,
     *                         unknown event_type slug, postLedgerEntry
     *                         validation failure).
     */
    function autoPostEvent(
        PDO     $pdo,
        string  $event_type,
        string  $entity_type,
        int     $entity_id,
        float   $amount,
        ?int    $project_id,
        string  $entry_date,
        int     $user_id,
        string  $description
    ): array {
        if ($event_type === '') {
            throw new LedgerException('autoPostEvent: event_type is required.');
        }
        if ($entity_type === '' || $entity_id <= 0) {
            throw new LedgerException('autoPostEvent: entity_type + entity_id required.');
        }
        if ($amount <= 0) {
            throw new LedgerException(
                "autoPostEvent: amount must be > 0 for event '$event_type', got $amount."
            );
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date)) {
            throw new LedgerException("autoPostEvent: entry_date must be YYYY-MM-DD, got '$entry_date'.");
        }

        // 1. Look up the mapping by event_type.
        //
        // Resilience: if the journal_mappings table doesn't exist (migration
        // hasn't run on this server yet, or was rolled back), treat the call
        // as a quiet no-op instead of crashing the caller's flow. This means
        // every Phase 4.3–4.8 endpoint (invoice approval, payment received,
        // expense paid, payroll paid, GRN approved, supplier payment) keeps
        // working even on servers where the Phase 4.1 migration hasn't
        // landed. Same quiet-failure shape as mapping_inactive.
        try {
            $stmt = $pdo->prepare("
                SELECT id, debit_account_id, credit_account_id, is_active
                  FROM journal_mappings
                 WHERE event_type = ?
                 LIMIT 1
            ");
            $stmt->execute([$event_type]);
            $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // SQLSTATE 42S02 = base table or view not found (MySQL 1146).
            // Some drivers report it via the SQLSTATE code on getCode(),
            // some bury it in the message — handle both.
            $missing_table = $e->getCode() === '42S02'
                          || strpos($e->getMessage(), "doesn't exist")  !== false
                          || strpos($e->getMessage(), 'no such table') !== false;
            if ($missing_table) {
                return [
                    'posted'     => false,
                    'reason'     => 'infrastructure_missing',
                    'event_type' => $event_type,
                ];
            }
            // Real DB error (connection lost, syntax bug, etc.) — propagate.
            throw $e;
        }

        if (!$mapping) {
            throw new LedgerException(
                "autoPostEvent: unknown event_type '$event_type' — "
                . 'add it to the journal_mappings seed migration.'
            );
        }

        // 2. Kill-switch path. Quiet no-op.
        if ((int)$mapping['is_active'] !== 1) {
            return [
                'posted'     => false,
                'reason'     => 'mapping_inactive',
                'event_type' => $event_type,
            ];
        }

        // 3. Defensive: both FKs must be set.
        $dr = $mapping['debit_account_id']  !== null ? (int)$mapping['debit_account_id']  : null;
        $cr = $mapping['credit_account_id'] !== null ? (int)$mapping['credit_account_id'] : null;
        if ($dr === null || $cr === null) {
            return [
                'posted'     => false,
                'reason'     => 'mapping_not_configured',
                'event_type' => $event_type,
            ];
        }

        // 4. Idempotency: was this entity already posted?
        $idem = $pdo->prepare("
            SELECT entry_id
              FROM journal_entries
             WHERE entity_type = ?
               AND entity_id   = ?
               AND status      = 'posted'
             LIMIT 1
        ");
        $idem->execute([$entity_type, $entity_id]);
        $existing = $idem->fetchColumn();
        if ($existing !== false) {
            return [
                'posted'            => false,
                'reason'            => 'already_posted',
                'existing_entry_id' => (int)$existing,
                'event_type'        => $event_type,
            ];
        }

        // 5. Post via the canonical helper. postLedgerEntry() will
        //    re-use the caller's open transaction if one exists, so
        //    a posting failure rolls back the parent operation too.
        $entry_id = postLedgerEntry(
            $pdo,
            $description,
            [
                ['account_id' => $dr, 'type' => 'debit',  'amount' => $amount],
                ['account_id' => $cr, 'type' => 'credit', 'amount' => $amount],
            ],
            $project_id,
            $entity_id,
            $entity_type,
            $entry_date,
            $user_id
        );

        return [
            'posted'     => true,
            'reason'     => 'posted',
            'entry_id'   => $entry_id,
            'event_type' => $event_type,
        ];
    }
}
