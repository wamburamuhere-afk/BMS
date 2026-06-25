<?php
/**
 * core/recurring.php
 * ------------------
 * Recurring-document engine (Plan C). A profile holds a document template +
 * a schedule; this engine, run once a day (or on demand), creates the real
 * document for every profile that is due and advances its schedule.
 *
 * v1 generates EXPENSES only — created with status 'pending', so the post-gated
 * expense flow guarantees NO money moves until a human approves and marks them
 * Paid. The generator is dispatched by doc_type, so invoice/bill generators can be
 * added later without touching the scheduler.
 *
 * Idempotency: recurring_runs has UNIQUE(profile_id, run_for_date) — a second run
 * on the same day for the same due date is a no-op (the INSERT is the guard).
 *
 * Pure helpers; no output. Callers wrap their own logging.
 */

if (!function_exists('recurring_advance_date')) {
    /** Next due date from a base date by frequency × interval. */
    function recurring_advance_date(string $date, string $frequency, int $interval): string {
        $interval = max(1, $interval);
        $map = ['weekly' => "+{$interval} week", 'monthly' => "+{$interval} month",
                'quarterly' => '+' . ($interval * 3) . ' month', 'yearly' => "+{$interval} year"];
        $expr = $map[$frequency] ?? "+{$interval} month";
        return date('Y-m-d', strtotime($date . ' ' . $expr));
    }
}

if (!function_exists('recurringDue')) {
    /** Active profiles whose next_run_date has arrived (or passed). */
    function recurringDue(PDO $pdo, ?string $asOf = null): array {
        $asOf = $asOf ?: date('Y-m-d');
        $stmt = $pdo->prepare("SELECT * FROM recurring_profiles
                                WHERE status = 'active' AND next_run_date <= ?
                             ORDER BY next_run_date ASC, id ASC");
        $stmt->execute([$asOf]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('recurring_generate_expense')) {
    /**
     * Create one expense from a profile's template. Created 'pending' — the
     * post-gated flow means NO money moves and NO ledger/bank posting happens here.
     * @return int|null new expense_id
     */
    function recurring_generate_expense(PDO $pdo, array $profile, array $tpl, string $forDate): ?int {
        $amount = round((float)($tpl['amount'] ?? 0), 2);
        if ($amount <= 0) return null;

        $ref = 'REC-' . (int)$profile['id'] . '-' . str_replace('-', '', $forDate);
        $desc = trim((string)($tpl['description'] ?? $profile['name'])) . ' (recurring)';

        $stmt = $pdo->prepare("
            INSERT INTO expenses
                (expense_date, expense_account_id, bank_account_id, category_id, type_id, amount,
                 description, reference_number, payment_method, paid_to_type, notes,
                 recurring_profile_id, status, project_id, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $forDate,
            !empty($tpl['expense_account_id']) ? (int)$tpl['expense_account_id'] : null,
            !empty($tpl['bank_account_id'])    ? (int)$tpl['bank_account_id']    : null,
            !empty($tpl['category_id'])        ? (int)$tpl['category_id']        : null,
            !empty($tpl['type_id'])            ? (int)$tpl['type_id']            : null,
            $amount,
            $desc,
            $ref,
            $tpl['payment_method'] ?? null,
            null,
            'Auto-generated from recurring profile #' . (int)$profile['id'],
            (int)$profile['id'],
            $profile['project_id'] !== null ? (int)$profile['project_id'] : null,
            $profile['created_by'] !== null ? (int)$profile['created_by'] : null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('recurringGenerate')) {
    /**
     * Generate the due document for one profile and advance its schedule.
     * Returns ['generated'=>bool, 'doc_id'=>?int, 'reason'=>string].
     *
     * Wrapped in a transaction: the recurring_runs UNIQUE row is claimed first, so a
     * concurrent or repeated run for the same due date cannot double-generate.
     */
    function recurringGenerate(PDO $pdo, array $profile): array {
        $forDate = $profile['next_run_date'];
        $tpl = json_decode((string)$profile['template_json'], true);
        if (!is_array($tpl)) return ['generated' => false, 'doc_id' => null, 'reason' => 'bad_template'];

        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            // Idempotency claim: fails (duplicate key) if already generated for this date.
            try {
                $pdo->prepare("INSERT INTO recurring_runs (profile_id, run_for_date, generated_doc_type) VALUES (?, ?, ?)")
                    ->execute([(int)$profile['id'], $forDate, $profile['doc_type']]);
            } catch (PDOException $dup) {
                if ($own) $pdo->rollBack();
                return ['generated' => false, 'doc_id' => null, 'reason' => 'already_generated'];
            }
            $runId = (int)$pdo->lastInsertId();

            // Dispatch by doc_type (v1: expense).
            $docId = null;
            if ($profile['doc_type'] === 'expense') {
                $docId = recurring_generate_expense($pdo, $profile, $tpl, $forDate);
            } else {
                // Generator not implemented yet — leave the run row as a placeholder.
                $docId = null;
            }
            if ($docId) {
                $pdo->prepare("UPDATE recurring_runs SET generated_doc_id = ? WHERE id = ?")->execute([$docId, $runId]);
            }

            // Advance the schedule.
            $next = recurring_advance_date($forDate, $profile['frequency'], (int)$profile['interval_count']);
            $occ  = $profile['occurrences_left'] !== null ? max(0, (int)$profile['occurrences_left'] - 1) : null;
            $newStatus = 'active';
            if ($occ !== null && $occ <= 0) $newStatus = 'ended';
            if ($profile['end_date'] !== null && $next > $profile['end_date']) $newStatus = 'ended';

            $pdo->prepare("UPDATE recurring_profiles
                              SET next_run_date = ?, occurrences_left = ?, status = ?, last_run_at = NOW(), updated_at = NOW()
                            WHERE id = ?")
                ->execute([$next, $occ, $newStatus, (int)$profile['id']]);

            if ($own) $pdo->commit();
            return ['generated' => (bool)$docId, 'doc_id' => $docId, 'reason' => $docId ? 'ok' : 'no_doc'];
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            error_log('recurringGenerate error (profile ' . ($profile['id'] ?? '?') . '): ' . $e->getMessage());
            return ['generated' => false, 'doc_id' => null, 'reason' => 'error'];
        }
    }
}

if (!function_exists('recurringRunAll')) {
    /** Generate every due profile; returns a summary count. */
    function recurringRunAll(PDO $pdo): array {
        $generated = 0; $skipped = 0;
        foreach (recurringDue($pdo) as $p) {
            // A profile may be multiple periods behind; generate each missed period
            // up to today (bounded to avoid runaway), advancing as we go.
            $guard = 0;
            do {
                $res = recurringGenerate($pdo, $p);
                if (!empty($res['generated'])) $generated++; else { $skipped++; break; }
                // Reload to see the advanced next_run_date / status.
                $st = $pdo->prepare("SELECT * FROM recurring_profiles WHERE id = ?");
                $st->execute([(int)$p['id']]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                $guard++;
            } while ($p && $p['status'] === 'active' && $p['next_run_date'] <= date('Y-m-d') && $guard < 60);
        }
        return ['generated' => $generated, 'skipped' => $skipped];
    }
}
