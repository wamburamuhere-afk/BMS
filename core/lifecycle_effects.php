<?php
/**
 * Employee lifecycle — effect application (Tier 1, Phase 1.3).
 *
 * An approved lifecycle event changes the employees row (D4). The change runs
 * inside the caller's transaction, and both old and new values live on the
 * event row itself, so history is never lost. Resignations with a future
 * last-working-day are approved but NOT applied until the date passes (D5) —
 * applyDueLifecycleEffects() is the idempotent catch-up for those.
 */

if (!function_exists('applyLifecycleEffectRow')) {
    /**
     * Apply an approved event's effect to the employees table and stamp
     * effect_applied_at. Caller owns the transaction. Record-only types
     * (award/warning/complaint) change nothing and keep the stamp NULL.
     *
     * @param array $ev    the employee_lifecycle_events row
     * @param int   $actor user_id written to employees.updated_by
     * @return array ['changed' => bool, 'old' => [], 'new' => []]
     */
    function applyLifecycleEffectRow(PDO $pdo, array $ev, int $actor): array
    {
        $sets = []; $vals = []; $old = []; $new = [];
        $employee_id = (int)$ev['employee_id'];

        // Leadership assignment writes to the DEPARTMENTS table, not employees:
        //   employee_id             = the new leader
        //   new_department_id        = the target department
        //   leadership_assistant_id  = the new assistant leader (nullable)
        // Transferable by design — assigning simply replaces whoever was there.
        if ($ev['event_type'] === 'leadership') {
            $deptId = (int)($ev['new_department_id'] ?? 0);
            if (!$deptId) return ['changed' => false, 'old' => [], 'new' => []];

            $cur = $pdo->prepare("SELECT manager_id, assistant_manager_id FROM departments WHERE department_id = ?");
            $cur->execute([$deptId]);
            $before = $cur->fetch(PDO::FETCH_ASSOC) ?: ['manager_id' => null, 'assistant_manager_id' => null];

            $newLeader = $employee_id ?: null;
            $newAsst   = !empty($ev['leadership_assistant_id']) ? (int)$ev['leadership_assistant_id'] : null;

            $pdo->prepare("UPDATE departments SET manager_id = ?, assistant_manager_id = ? WHERE department_id = ?")
                ->execute([$newLeader, $newAsst, $deptId]);
            $pdo->prepare("UPDATE employee_lifecycle_events SET effect_applied_at = NOW() WHERE event_id = ?")
                ->execute([(int)$ev['event_id']]);

            return [
                'changed' => true,
                'old' => ['manager_id' => $before['manager_id'], 'assistant_manager_id' => $before['assistant_manager_id']],
                'new' => ['manager_id' => $newLeader, 'assistant_manager_id' => $newAsst],
            ];
        }

        switch ($ev['event_type']) {
            case 'promotion':
            case 'demotion':
                if (!empty($ev['new_designation_id'])) {
                    $sets[] = 'designation_id = ?';
                    $vals[] = (int)$ev['new_designation_id'];
                    $old['designation_id'] = $ev['old_designation_id'];
                    $new['designation_id'] = (int)$ev['new_designation_id'];
                }
                if ($ev['new_salary'] !== null && $ev['new_salary'] !== '') {
                    $sets[] = 'basic_salary = ?';
                    $vals[] = $ev['new_salary'];
                    $old['basic_salary'] = $ev['old_salary'];
                    $new['basic_salary'] = $ev['new_salary'];
                }
                break;

            case 'transfer':
                if (!empty($ev['new_department_id'])) {
                    $sets[] = 'department_id = ?';
                    $vals[] = (int)$ev['new_department_id'];
                    $old['department_id'] = $ev['old_department_id'];
                    $new['department_id'] = (int)$ev['new_department_id'];
                    // Keep the legacy varchar in sync — both columns exist today
                    $dn = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
                    $dn->execute([(int)$ev['new_department_id']]);
                    $dept_name = $dn->fetchColumn();
                    if ($dept_name !== false) {
                        $sets[] = 'department = ?';
                        $vals[] = $dept_name;
                    }
                }
                if (!empty($ev['new_project_id'])) {
                    $sets[] = 'project_id = ?';
                    $vals[] = (int)$ev['new_project_id'];
                    $old['project_id'] = $ev['old_project_id'];
                    $new['project_id'] = (int)$ev['new_project_id'];
                }
                break;

            case 'resignation':
                $sets[] = "employment_status = 'resigned'";
                $old['employment_status'] = null;   // filled by caller if known
                $new['employment_status'] = 'resigned';
                break;

            case 'termination':
                $sets[] = "employment_status = 'terminated'";
                $old['employment_status'] = null;
                $new['employment_status'] = 'terminated';
                break;

            // award / warning / complaint — record only, no employee change
            default:
                break;
        }

        if (!$sets) {
            return ['changed' => false, 'old' => [], 'new' => []];
        }

        // Resolve the real old employment_status for the audit trail
        if (array_key_exists('employment_status', $new)) {
            $cur = $pdo->prepare("SELECT employment_status FROM employees WHERE employee_id = ?");
            $cur->execute([$employee_id]);
            $old['employment_status'] = $cur->fetchColumn();
        }

        $vals[] = $actor;
        $vals[] = $employee_id;
        $pdo->prepare("UPDATE employees SET " . implode(', ', $sets) . ", updated_by = ? WHERE employee_id = ?")
            ->execute($vals);

        $pdo->prepare("UPDATE employee_lifecycle_events SET effect_applied_at = NOW() WHERE event_id = ?")
            ->execute([(int)$ev['event_id']]);

        return ['changed' => true, 'old' => $old, 'new' => $new];
    }
}

if (!function_exists('applyDueLifecycleEffects')) {
    /**
     * D5 catch-up: approved resignations whose last working day has arrived
     * but whose effect is still unapplied. Idempotent — the effect_applied_at
     * stamp guarantees one-shot application. Called at the top of
     * hr_actions.php and employee_details.php (cheap indexed query).
     *
     * @return int number of events applied
     */
    function applyDueLifecycleEffects(PDO $pdo): int
    {
        try {
            $due = $pdo->query("
                SELECT * FROM employee_lifecycle_events
                WHERE status = 'approved' AND event_type = 'resignation'
                  AND effect_applied_at IS NULL AND end_date IS NOT NULL AND end_date <= CURDATE()
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return 0;   // table missing (pre-migration) — silent no-op
        }

        $applied = 0;
        foreach ($due as $ev) {
            $ownTxn = !$pdo->inTransaction();
            try {
                if ($ownTxn) $pdo->beginTransaction();

                // Re-check under lock so two concurrent page loads can't double-apply
                $lock = $pdo->prepare("SELECT effect_applied_at FROM employee_lifecycle_events WHERE event_id = ? FOR UPDATE");
                $lock->execute([(int)$ev['event_id']]);
                if ($lock->fetchColumn() !== null) {
                    if ($ownTxn) $pdo->commit();
                    continue;
                }

                $actor = (int)($ev['approved_by'] ?: $ev['created_by']);
                $result = applyLifecycleEffectRow($pdo, $ev, $actor);

                if ($result['changed'] && function_exists('logAudit')) {
                    logAudit($pdo, $actor, 'update_status', [
                        'activity_type' => 'status_change',
                        'entity_type'   => 'employee',
                        'entity_id'     => (int)$ev['employee_id'],
                        'description'   => "Resignation effective: employee #{$ev['employee_id']} status set to resigned (last working day {$ev['end_date']}, event #{$ev['event_id']})",
                        'old_values'    => $result['old'],
                        'new_values'    => $result['new'],
                    ]);
                }

                if ($ownTxn) $pdo->commit();
                $applied++;
            } catch (Throwable $e) {
                if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
                error_log('applyDueLifecycleEffects: ' . $e->getMessage());
            }
        }
        return $applied;
    }
}
