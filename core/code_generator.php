<?php
/**
 * core/code_generator.php
 * -----------------------------------------------------------------------------
 * Central generator for company-prefixed, gap-free document/reference codes.
 *
 * Format:  PREFIX-TYPE-NNNN   e.g.  BTL-NIP-0001 , BTL-INV-0042
 *   PREFIX = 3-letter company code (system_settings.company_code_prefix),
 *            auto-suggested from the company name, admin-editable.
 *   TYPE   = short entity tag (NIP, INV, PO, CUST ...).
 *   NNNN   = strictly sequential counter from `code_sequences` (never random,
 *            never a gap) — fixes the old "MAX(id)+1" gap problem.
 *
 * WHY a sequence table instead of MAX(id)+1:
 *   - MAX(id)+1 leaves holes (deleted rows) and is not atomic under concurrency.
 *   - `code_sequences` is incremented inside the caller's own DB transaction
 *     (see nextCode()), so a rolled-back insert also rolls back the number —
 *     guaranteeing no gaps and no duplicates.
 *
 * SAFETY for existing data / the GL:
 *   journal_entries link to source documents by (entity_type, entity_id) — the
 *   INTEGER id — never by the display code. So regenerating a display code never
 *   affects the ledger, reports, or audit trail.  Existing codes are only ever
 *   changed via codeForEdit() and only when a record is still editable.
 *
 * See company_code_prefix_plan.md for the full design + rollout.
 */

if (!function_exists('deriveCompanyPrefix')) {
    /**
     * Auto-suggest a 3-letter prefix from a company name.
     *   "BJP Technologies (T) Ltd"          -> BTL
     *   "Mufindi Power Services Ltd"        -> MPS
     *   "Bejundas Business Management Sys"  -> BBM
     * Rule: drop parenthetical noise like "(T)", take the first letter of the
     * first three remaining words, uppercase. Falls back to padding so the
     * result is always exactly 3 A-Z chars.
     */
    function deriveCompanyPrefix(string $companyName): string {
        $name = preg_replace('/\([^)]*\)/', ' ', $companyName);      // drop "(T)" etc.
        $words = preg_split('/[^A-Za-z0-9]+/', (string)$name, -1, PREG_SPLIT_NO_EMPTY);
        $letters = '';
        foreach ($words as $w) {
            $letters .= strtoupper(substr($w, 0, 1));
            if (strlen($letters) >= 3) break;
        }
        if ($letters === '') $letters = 'CMP';
        return str_pad(substr($letters, 0, 3), 3, 'X');             // always 3 chars
    }
}

if (!function_exists('companyCodePrefix')) {
    /**
     * The active company prefix. Reads system_settings.company_code_prefix;
     * if blank, derives one from company_name so the system always has a prefix.
     * Cached per request.
     */
    function companyCodePrefix(PDO $pdo): string {
        static $cached = null;
        if ($cached !== null) return $cached;

        $p = '';
        if (function_exists('get_setting')) {
            $p = strtoupper(trim((string)get_setting('company_code_prefix', '')));
        }
        if ($p === '') {
            $companyName = function_exists('get_setting')
                ? (string)get_setting('company_name', '')
                : '';
            $p = deriveCompanyPrefix($companyName);
        }
        // keep it tidy: A-Z only, max 5 chars (3 is the norm)
        $p = preg_replace('/[^A-Z]/', '', $p);
        if ($p === '') $p = 'CMP';
        return $cached = substr($p, 0, 5);
    }
}

if (!function_exists('nextCode')) {
    /**
     * Atomically allocate the next sequential code for $type, e.g. "BTL-NIP-0001".
     *
     * The increment shares the CALLER'S transaction when one is open (so a
     * rolled-back document also releases its number — no gaps). When no
     * transaction is open it commits its own tiny one.
     *
     * @param string $type    Entity tag, e.g. 'NIP', 'INV', 'CUST'.
     * @param int    $digits  Zero-pad width (min). Default 4 -> 0001.
     */
    function nextCode(PDO $pdo, string $type, int $digits = 4): string {
        $type   = strtoupper(trim($type));
        $prefix = companyCodePrefix($pdo);

        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) $pdo->beginTransaction();
        try {
            // Ensure the counter row exists (no-op if already seeded).
            $pdo->prepare(
                "INSERT INTO code_sequences (sequence_name, last_no, digits)
                 VALUES (?, 0, ?)
                 ON DUPLICATE KEY UPDATE sequence_name = sequence_name"
            )->execute([$type, $digits]);

            // Lock the row, read, bump.
            $sel = $pdo->prepare(
                "SELECT last_no, digits FROM code_sequences
                 WHERE sequence_name = ? FOR UPDATE"
            );
            $sel->execute([$type]);
            $row  = $sel->fetch(PDO::FETCH_ASSOC) ?: ['last_no' => 0, 'digits' => $digits];
            $next = (int)$row['last_no'] + 1;
            $pad  = max($digits, (int)$row['digits']);

            $pdo->prepare("UPDATE code_sequences SET last_no = ? WHERE sequence_name = ?")
                ->execute([$next, $type]);

            if ($ownTxn) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return $prefix . '-' . $type . '-' . str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('peekNextCode')) {
    /**
     * The code that WOULD be allocated next for $type, WITHOUT incrementing the
     * sequence. For display-only previews (e.g. a suggested default on a form).
     * Never use the returned value as a final stored code — call nextCode() at save.
     */
    function peekNextCode(PDO $pdo, string $type, int $digits = 4): string {
        $type   = strtoupper(trim($type));
        $prefix = companyCodePrefix($pdo);
        $sel = $pdo->prepare("SELECT last_no, digits FROM code_sequences WHERE sequence_name = ?");
        $sel->execute([$type]);
        $row  = $sel->fetch(PDO::FETCH_ASSOC);
        $next = ($row ? (int)$row['last_no'] : 0) + 1;
        $pad  = $row ? max($digits, (int)$row['digits']) : $digits;
        return $prefix . '-' . $type . '-' . str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('logCodeChange')) {
    /**
     * Record an old -> new code conversion so a document is still findable by its
     * old number after re-coding. Best-effort; never breaks the caller.
     */
    function logCodeChange(
        PDO $pdo, string $type, ?string $table, ?int $recordId,
        string $oldCode, string $newCode
    ): void {
        try {
            $uid = function_exists('getCurrentUserId') ? getCurrentUserId() : null;
            $pdo->prepare(
                "INSERT INTO code_change_log
                    (sequence_name, table_name, record_id, old_code, new_code, changed_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$type, $table, $recordId, $oldCode, $newCode, $uid]);
        } catch (Throwable $e) {
            // logging must never abort the edit
        }
    }
}

if (!function_exists('codeForEdit')) {
    /**
     * Decide which code to persist when a record is EDITED & saved.
     *
     * Boss rule: convert auto-generated codes to the new format on edit, but only
     * while the record is still editable (the CALLER must enforce the status lock
     * BEFORE calling this — if the page blocks the edit, this never runs, so locked
     * records keep their code).
     *
     * Decision:
     *   - already new company format ............ keep as-is (don't burn a number)
     *   - blank, or matches the legacy auto regex  -> allocate a fresh company code
     *   - anything else (a manual/custom code) ... keep as-is (respect manual input)
     *
     * @param string|null $current      The code currently on the record / submitted.
     * @param string|null $legacyRegex   Regex (no delimiters) matching this type's OLD
     *                                   auto pattern, e.g. 'NIP-\d+' or 'INV-\d{8}-\d+'.
     *                                   Pass null to treat only blank as auto.
     * @param string|null $table         For change-log traceability (optional).
     * @param int|null    $recordId      For change-log traceability (optional).
     */
    function codeForEdit(
        PDO $pdo, string $type, ?string $current, ?string $legacyRegex = null,
        ?string $table = null, ?int $recordId = null, int $digits = 4
    ): string {
        $type    = strtoupper(trim($type));
        $prefix  = companyCodePrefix($pdo);
        $current = trim((string)$current);

        // Already converted -> leave it, never re-burn a sequence number.
        $newFmt = '#^' . preg_quote($prefix, '#') . '-' . preg_quote($type, '#') . '-\d+$#';
        if ($current !== '' && preg_match($newFmt, $current)) {
            return $current;
        }

        $isAuto = ($current === '')
            || ($legacyRegex !== null && preg_match('#^' . $legacyRegex . '$#', $current));

        if (!$isAuto) {
            return $current; // manual / custom value -> respect it
        }

        $newCode = nextCode($pdo, $type, $digits);
        if ($current !== '' && $current !== $newCode) {
            logCodeChange($pdo, $type, $table, $recordId, $current, $newCode);
        }
        return $newCode;
    }
}
