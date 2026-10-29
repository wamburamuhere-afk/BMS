<?php
/**
 * core/field_visit_followups.php — repeated follow-ups on a customer visit
 * (customer_visit_followups_plan.md).
 *
 * Every follow-up is a row in field_visit_follow_ups. A visit has at most ONE
 * pending follow-up at a time (D2): every change runs inside the caller's
 * transaction after frFuLockVisit() has locked the visit row, so two requests can
 * never both add one. field_visits.follow_up_date / follow_up_done_at /
 * follow_up_done_by mirror the visit's current follow-up and are written ONLY by
 * frSyncVisitFollowUp() (D3) — call it after every change.
 *
 * Who may do what is decided by the callers, exactly as for the visit itself:
 * frCanSee() to read, frCanTouch() + canEdit('field_visits') to change.
 */

require_once __DIR__ . '/field_reports.php';

if (!function_exists('frFollowUpMethods')) {
    /** code => English label (translate with t() at render time). */
    function frFollowUpMethods(): array
    {
        return [
            'visit'    => 'Visited',
            'call'     => 'Phone call',
            'whatsapp' => 'WhatsApp',
            'sms'      => 'SMS',
            'other'    => 'Other',
        ];
    }

    /**
     * May the current user READ this visit? Owner always; an admin also another
     * staff member's visit once that day's report was submitted — the same rule
     * the list applies through frSubmittedOnlySql().
     */
    function frCanSee(PDO $pdo, array $visit): bool
    {
        $me = (int)($_SESSION['user_id'] ?? 0);
        if ((int)$visit['user_id'] === $me) return true;
        if (!isAdmin()) return false;
        $s = $pdo->prepare("SELECT 1 FROM field_report_days WHERE user_id = ? AND report_date = ?");
        $s->execute([(int)$visit['user_id'], $visit['visit_date']]);
        return (bool)$s->fetchColumn();
    }

    /** Locks the visit row for the rest of the caller's transaction. */
    function frFuLockVisit(PDO $pdo, int $visitId): void
    {
        $pdo->prepare("SELECT visit_id FROM field_visits WHERE visit_id = ? FOR UPDATE")->execute([$visitId]);
    }

    function frFuGet(PDO $pdo, int $followUpId): ?array
    {
        $s = $pdo->prepare("SELECT * FROM field_visit_follow_ups WHERE follow_up_id = ?");
        $s->execute([$followUpId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    function frFuPending(PDO $pdo, int $visitId): ?array
    {
        $s = $pdo->prepare("SELECT * FROM field_visit_follow_ups WHERE visit_id = ? AND status = 'pending'
                             ORDER BY follow_up_id DESC LIMIT 1");
        $s->execute([$visitId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    function frFuLatestDone(PDO $pdo, int $visitId): ?array
    {
        $s = $pdo->prepare("SELECT * FROM field_visit_follow_ups WHERE visit_id = ? AND status = 'done'
                             ORDER BY done_at DESC, follow_up_id DESC LIMIT 1");
        $s->execute([$visitId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Error text for a follow-up date, or null when it is fine: a real date, not
     * before $min, at most a year after $yearFrom.
     */
    function frFuDueError($due, string $min, string $minMessage, string $yearFrom): ?string
    {
        if (!frValidDate($due)) return t('Choose a valid date.');
        if ($due < $min) return $minMessage;
        if ($due > date('Y-m-d', strtotime($yearFrom . ' +1 year'))) return t('Choose a follow-up date within a year.');
        return null;
    }

    /** Validates a "what happened" form. Returns [method|null, outcome|null] or a string error. */
    function frFuValidateOutcome($method, $outcome)
    {
        $method  = trim((string)$method);
        $outcome = trim((string)$outcome);
        if ($method !== '' && !array_key_exists($method, frFollowUpMethods())) return t('Choose a valid option.');
        if (mb_strlen($outcome) > 2000) return t('Notes are too long.');
        return [$method !== '' ? $method : null, $outcome !== '' ? $outcome : null];
    }

    function frFuCleanPurpose($purpose): ?string
    {
        $p = trim((string)$purpose);
        return $p !== '' ? mb_substr($p, 0, 255) : null;
    }

    /** Adds the visit's pending follow-up. RuntimeException when one is already pending. */
    function frFuAdd(PDO $pdo, int $visitId, string $due, ?string $purpose, int $me): int
    {
        if (frFuPending($pdo, $visitId)) throw new RuntimeException('pending_exists');
        $pdo->prepare("INSERT INTO field_visit_follow_ups (visit_id, due_date, purpose, status, created_by, created_at)
                       VALUES (?, ?, ?, 'pending', ?, NOW())")->execute([$visitId, $due, $purpose, $me]);
        return (int)$pdo->lastInsertId();
    }

    /** Moves a pending follow-up to a new date (and purpose, when given). */
    function frFuReschedule(PDO $pdo, int $followUpId, string $due, ?string $purpose, bool $setPurpose, int $me): void
    {
        $sql = "UPDATE field_visit_follow_ups SET due_date = ?, updated_by = ?" . ($setPurpose ? ", purpose = ?" : "")
             . " WHERE follow_up_id = ? AND status = 'pending'";
        $params = $setPurpose ? [$due, $me, $purpose, $followUpId] : [$due, $me, $followUpId];
        $pdo->prepare($sql)->execute($params);
    }

    function frFuCancel(PDO $pdo, int $followUpId, int $me): void
    {
        $pdo->prepare("UPDATE field_visit_follow_ups SET status = 'cancelled', updated_by = ?
                       WHERE follow_up_id = ? AND status = 'pending'")->execute([$me, $followUpId]);
    }

    function frFuCancelAllPending(PDO $pdo, int $visitId, int $me): void
    {
        $pdo->prepare("UPDATE field_visit_follow_ups SET status = 'cancelled', updated_by = ?
                       WHERE visit_id = ? AND status = 'pending'")->execute([$me, $visitId]);
    }

    function frFuComplete(PDO $pdo, int $followUpId, ?string $method, ?string $outcome, int $me, ?int $resultVisitId = null): void
    {
        $pdo->prepare("UPDATE field_visit_follow_ups
                          SET status = 'done', method = ?, outcome = ?, done_at = NOW(), done_by = ?, result_visit_id = ?, updated_by = ?
                        WHERE follow_up_id = ? AND status = 'pending'")
            ->execute([$method, $outcome, $me, $resultVisitId, $me, $followUpId]);
    }

    /** A done follow-up back to pending (the old "re-open" of followup_done.php done=0). */
    function frFuReopen(PDO $pdo, int $followUpId, int $me): void
    {
        $pdo->prepare("UPDATE field_visit_follow_ups
                          SET status = 'pending', method = NULL, outcome = NULL, done_at = NULL, done_by = NULL, result_visit_id = NULL, updated_by = ?
                        WHERE follow_up_id = ? AND status = 'done'")->execute([$me, $followUpId]);
    }

    /**
     * Writes the visit's mirror columns from its follow-ups: the pending one (not done),
     * else the last done one (done), else nothing. The ONLY writer of those columns.
     */
    function frSyncVisitFollowUp(PDO $pdo, int $visitId): void
    {
        $cur = frFuPending($pdo, $visitId);
        $vals = $cur ? [$cur['due_date'], null, null] : [null, null, null];
        if (!$cur && ($done = frFuLatestDone($pdo, $visitId))) $vals = [$done['due_date'], $done['done_at'], $done['done_by']];
        // updated_at kept as it is: a follow-up is not an edit of the visit (its
        // ON UPDATE CURRENT_TIMESTAMP would otherwise show "last changed" with nobody).
        $pdo->prepare("UPDATE field_visits SET follow_up_date = ?, follow_up_done_at = ?, follow_up_done_by = ?, updated_at = updated_at WHERE visit_id = ?")
            ->execute(array_merge($vals, [$visitId]));
    }

    /**
     * The visits of the same customer as $visit that the current user may see: same
     * phone (a visit without a phone is its own customer), active, newest first.
     */
    function frCustomerVisits(PDO $pdo, array $visit): array
    {
        $sql = "SELECT v.*, u.first_name, u.last_name, u.username FROM field_visits v
                  LEFT JOIN users u ON u.user_id = v.user_id
                 WHERE v.status = 'active'";
        $params = [];
        if (($visit['phone_normalized'] ?? '') !== '') { $sql .= " AND v.phone_normalized = ?"; $params[] = $visit['phone_normalized']; }
        else                                          { $sql .= " AND v.visit_id = ?";         $params[] = (int)$visit['visit_id']; }
        $scope = frScopeUserId(null);
        if ($scope !== null) { $sql .= " AND v.user_id = ?"; $params[] = $scope; }
        $sql .= frSubmittedOnlySql('v', $params);
        $sql .= " ORDER BY v.visit_date DESC, v.visit_time DESC, v.visit_id DESC LIMIT 200";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        // The visit being viewed is always part of its own customer, whatever the filter.
        if (!in_array((int)$visit['visit_id'], array_map(fn($r) => (int)$r['visit_id'], $rows), true)) {
            $self = $pdo->prepare("SELECT v.*, u.first_name, u.last_name, u.username FROM field_visits v
                                     LEFT JOIN users u ON u.user_id = v.user_id WHERE v.visit_id = ?");
            $self->execute([(int)$visit['visit_id']]);
            if ($r = $self->fetch(PDO::FETCH_ASSOC)) array_unshift($rows, $r);
        }
        foreach ($rows as &$r) $r['staff_name'] = frStaffName($r);
        unset($r);
        return $rows;
    }

    /**
     * Every follow-up of the given visits (the customer's timeline), pending first,
     * then newest due date first — with who created / completed each one.
     */
    function frFollowUpsForVisits(PDO $pdo, array $visitIds): array
    {
        $visitIds = array_values(array_unique(array_map('intval', $visitIds)));
        if (!$visitIds) return [];
        $ph = implode(',', array_fill(0, count($visitIds), '?'));
        $s = $pdo->prepare("SELECT f.*, v.visit_date, v.user_id AS visit_user_id,
                                   cu.first_name AS c_first, cu.last_name AS c_last, cu.username AS c_user,
                                   du.first_name AS d_first, du.last_name AS d_last, du.username AS d_user
                              FROM field_visit_follow_ups f
                              JOIN field_visits v ON v.visit_id = f.visit_id
                              LEFT JOIN users cu ON cu.user_id = f.created_by
                              LEFT JOIN users du ON du.user_id = f.done_by
                             WHERE f.visit_id IN ($ph)
                             ORDER BY (f.status = 'pending') DESC, f.due_date DESC, f.follow_up_id DESC");
        $s->execute($visitIds);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The daily "follow-up due" reminders (D7/D9), run by cron/run_notification_checks.php:
     * 2 days before, 1 day before, on the day, and once the day after a pending follow-up —
     * each stage once per follow-up (the due date is part of the dedupe key, so a moved
     * date starts again). Not for joined clients or deleted visits. To the staff member who
     * recorded the visit (and whoever set the follow-up) plus admins, through the event's
     * rules; private to them — an admin only when the admin may open that visit (its day
     * was submitted), like the visits page itself. Returns ['scanned' => n, 'sent' => n].
     */
    function frRunFollowUpReminders(PDO $pdo): array
    {
        require_once __DIR__ . '/notify.php';
        $rows = $pdo->query("
            SELECT f.follow_up_id, f.due_date, f.purpose, f.created_by, DATEDIFF(f.due_date, CURDATE()) AS days_left,
                   v.visit_id, v.user_id, v.visit_date, v.client_name, v.client_phone, v.location,
                   u.first_name, u.last_name, u.username,
                   EXISTS (SELECT 1 FROM field_report_days d WHERE d.user_id = v.user_id AND d.report_date = v.visit_date) AS day_submitted
              FROM field_visit_follow_ups f
              JOIN field_visits v ON v.visit_id = f.visit_id
              LEFT JOIN users u ON u.user_id = v.user_id
             WHERE f.status = 'pending' AND v.status = 'active' AND v.joined = 0
               AND f.due_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0;
        foreach ($rows as $f) {
            $d = (int)$f['days_left'];
            $dueOn = date('d M Y', strtotime($f['due_date']));
            $staff = frStaffName($f);
            $who   = $f['client_name'] . ((string)$f['client_phone'] !== '' ? ' (' . $f['client_phone'] . ')' : '');
            [$title, $when] = [
                2  => ['Follow-up in 2 days: ', "is due in 2 days ($dueOn)"],
                1  => ['Follow-up tomorrow: ',  "is due tomorrow ($dueOn)"],
                0  => ['Follow-up today: ',     "is due today ($dueOn)"],
                -1 => ['Follow-up missed: ',    "was due yesterday ($dueOn) and is not marked done"],
            ][$d];
            $message = "The follow-up with {$who} at {$f['location']} {$when}."
                     . ((string)$f['purpose'] !== '' ? " Reason: {$f['purpose']}." : '')
                     . " Visit recorded by {$staff} on " . date('d M Y', strtotime($f['visit_date'])) . '.';

            $res = dispatchEvent($pdo, 'field_visit.follow_up_due', [
                'entity_type'       => 'field_visit_follow_up',
                'entity_id'         => (int)$f['follow_up_id'],
                'title'             => $title . $f['client_name'],
                'message'           => $message,
                'action_url'        => 'field_reports/details?id=' . (int)$f['visit_id'],
                'severity'          => $d <= 0 ? 'high' : 'medium',
                'dedupe_suffix'     => $f['due_date'] . '|d' . $d,
                'owner_user_ids'    => array_values(array_unique(array_filter([(int)$f['user_id'], (int)$f['created_by']]))),
                'private_to_owners' => true,
                'admins_may_see'    => (bool)$f['day_submitted'],
            ]);
            if (!empty($res['dispatched'])) $sent += (int)$res['created'] + (int)$res['emailed'];
        }
        return ['scanned' => count($rows), 'sent' => $sent];
    }

    /** A follow-up row as the pages need it (raw values; the page escapes on render). */
    function frFollowUpOut(array $f, string $today, bool $canChange): array
    {
        $methods = frFollowUpMethods();
        $name = fn($first, $last, $user) => ($n = trim(($first ?? '') . ' ' . ($last ?? ''))) !== '' ? $n : (string)($user ?? '');
        return [
            'follow_up_id'    => (int)$f['follow_up_id'],
            'visit_id'        => (int)$f['visit_id'],
            'visit_date'      => $f['visit_date'] ?? null,
            'due_date'        => $f['due_date'],
            'days_left'       => (int)round((strtotime($f['due_date']) - strtotime($today)) / 86400),
            'purpose'         => $f['purpose'] ?? '',
            'status'          => $f['status'],
            'method'          => $f['method'] ?? '',
            'method_label'    => !empty($f['method']) ? t($methods[$f['method']] ?? 'Other') : '',
            'outcome'         => $f['outcome'] ?? '',
            'done_at'         => $f['done_at'],
            'done_by_name'    => !empty($f['done_by']) ? $name($f['d_first'] ?? null, $f['d_last'] ?? null, $f['d_user'] ?? null) : '',
            'result_visit_id' => !empty($f['result_visit_id']) ? (int)$f['result_visit_id'] : null,
            'created_at'      => $f['created_at'],
            'created_by_name' => !empty($f['created_by']) ? $name($f['c_first'] ?? null, $f['c_last'] ?? null, $f['c_user'] ?? null) : '',
            'can_change'      => $canChange && $f['status'] === 'pending',
            'can_reopen'      => $canChange && $f['status'] === 'done',
        ];
    }
}
