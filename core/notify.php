<?php
/**
 * core/notify.php
 * ---------------------------------------------------------------------------
 * Phase 2 — core helpers for the Smart Notification Engine.
 *
 *   usersWithPermission()  — WHO can act on an area (RBAC; generalized from
 *                            cron/check_document_expiry.php).
 *   createNotification()   — single writer for the in-app `notifications` table.
 *   notifClaimDedupe()     — "fire once" guard (idempotency).
 *   notifLog()             — best-effort audit into `notification_log`.
 *   resolveRecipients()    — WHO gets a given event (Phase 2: permission only;
 *                            Phase 3 will add project-scope + per-user prefs;
 *                            Phase 5 will add admin rule narrowing).
 *   dispatchEvent()        — the bus: event -> recipients -> in-app + log.
 *
 * Design: fail-safe. A notification problem must NEVER break the business action
 * that triggered it, so dispatchEvent() catches everything and returns a summary.
 * Email delivery is added in Phase 4 (channels/outbox); this phase is in-app only.
 */

require_once __DIR__ . '/../roots.php';

if (!function_exists('usersWithPermission')) {
    /**
     * Active users whose role grants $verb on $pageKey — PLUS all admins.
     * Returns: [ user_id => ['user_id','email','name','role_id','prefs'] ].
     *
     * @param string $verb one of view|create|edit|delete|review|approve
     */
    function usersWithPermission(PDO $pdo, string $pageKey, string $verb = 'view'): array
    {
        $map = [
            'view' => 'can_view', 'create' => 'can_create', 'edit' => 'can_edit',
            'delete' => 'can_delete', 'review' => 'can_review', 'approve' => 'can_approve',
        ];
        $col = $map[strtolower(trim($verb))] ?? 'can_view'; // whitelist -> safe to interpolate

        $sql = "
            SELECT DISTINCT u.user_id, u.email, u.first_name, u.last_name, u.username,
                            u.role_id, u.notification_preferences,
                            (CASE WHEN COALESCE(r.is_admin,0) = 1 OR COALESCE(u.is_admin,0) = 1 OR u.role_id = 1
                                  THEN 1 ELSE 0 END) AS is_admin
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.is_active = 1
              AND (
                    COALESCE(r.is_admin, 0) = 1
                 OR COALESCE(u.is_admin, 0) = 1
                 OR u.role_id = 1
                 OR u.role_id IN (
                        SELECT rp.role_id
                        FROM role_permissions rp
                        JOIN permissions p ON p.permission_id = rp.permission_id
                        WHERE p.page_key = ? AND rp.`$col` = 1
                    )
              )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pageKey]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if ($name === '') $name = $r['username'] ?? ('User #' . $r['user_id']);
            $out[(int)$r['user_id']] = [
                'user_id'  => (int)$r['user_id'],
                'email'    => trim((string)$r['email']),
                'name'     => $name,
                'role_id'  => (int)$r['role_id'],
                'is_admin' => ((int)($r['is_admin'] ?? 0) === 1),
                'prefs'    => $r['notification_preferences'] ?? null,
            ];
        }
        return $out;
    }
}

if (!function_exists('createNotification')) {
    /**
     * Insert one in-app notification. Returns the new notification_id (0 on failure).
     * $p keys: title, message, type, event_key, category, priority/severity,
     *          action_url, project_id, customer_id, document_id, loan_id.
     */
    function createNotification(PDO $pdo, int $userId, array $p): int
    {
        if ($userId <= 0) return 0;

        $validType = ['loan', 'payment', 'system', 'report', 'alert'];
        $type = in_array(($p['type'] ?? 'alert'), $validType, true) ? $p['type'] : 'alert';

        $validPrio = ['low', 'medium', 'high'];
        $prio = $p['priority'] ?? $p['severity'] ?? 'medium';
        if (!in_array($prio, $validPrio, true)) $prio = 'medium';

        try {
            $stmt = $pdo->prepare("
                INSERT INTO notifications
                    (user_id, title, message, type, event_key, category, priority, is_read,
                     loan_id, customer_id, document_id, project_id, action_url, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                (string)($p['title'] ?? 'Notification'),
                (string)($p['message'] ?? ''),
                $type,
                $p['event_key'] ?? null,
                $p['category'] ?? null,
                $prio,
                isset($p['loan_id'])     ? (int)$p['loan_id']     : null,
                isset($p['customer_id']) ? (int)$p['customer_id'] : null,
                isset($p['document_id']) ? (int)$p['document_id'] : null,
                isset($p['project_id'])  ? (int)$p['project_id']  : null,
                $p['action_url'] ?? null,
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('createNotification failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('notifClaimDedupe')) {
    /**
     * Atomically claim a dedupe key. Returns true only the FIRST time a given
     * key is seen (so the caller fires exactly once). Best-effort: on any error
     * it returns true so a logging glitch never suppresses a real notification.
     */
    function notifClaimDedupe(PDO $pdo, string $key): bool
    {
        $key = substr($key, 0, 191);
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO notification_dedupe (dedupe_key) VALUES (?)");
            $stmt->execute([$key]);
            return $stmt->rowCount() > 0; // 0 => already existed
        } catch (Throwable $e) {
            error_log('notifClaimDedupe error: ' . $e->getMessage());
            return true;
        }
    }
}

if (!function_exists('notifLog')) {
    /** Best-effort audit row. Never throws. */
    function notifLog(PDO $pdo, array $r): void
    {
        try {
            $pdo->prepare("
                INSERT INTO notification_log
                    (event_key, recipient_user_id, channel, status, entity_type, entity_id, detail)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $r['event_key'] ?? null,
                isset($r['recipient_user_id']) ? (int)$r['recipient_user_id'] : null,
                $r['channel'] ?? 'inapp',
                $r['status'] ?? 'created',
                $r['entity_type'] ?? null,
                isset($r['entity_id']) ? (int)$r['entity_id'] : null,
                isset($r['detail']) ? substr((string)$r['detail'], 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            error_log('notifLog error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('notifUserMuted')) {
    /**
     * Has the user muted this event/category in their notification_preferences?
     * Backward-compatible: prefs is whatever JSON the settings form saved; if the
     * mute keys aren't present, the user is NOT muted (so existing arbitrary prefs
     * never accidentally suppress notifications).
     *
     * Recognised keys:
     *   notifications_enabled : false/'0'/'off' => mute everything
     *   muted_events          : string[] of event_keys
     *   muted_categories      : string[] of categories/modules
     */
    function notifUserMuted($prefs, string $eventKey, string $category): bool
    {
        if ($prefs === null || $prefs === '') return false;
        $p = is_array($prefs) ? $prefs : json_decode((string)$prefs, true);
        if (!is_array($p)) return false;

        if (array_key_exists('notifications_enabled', $p)) {
            $v = $p['notifications_enabled'];
            if ($v === false || $v === 0 || $v === '0' || $v === 'false' || $v === 'off') return true;
        }
        $me = $p['muted_events'] ?? [];
        if (is_array($me) && $eventKey !== '' && in_array($eventKey, $me, true)) return true;
        $mc = $p['muted_categories'] ?? [];
        if (is_array($mc) && $category !== '' && in_array($category, $mc, true)) return true;

        return false;
    }
}

if (!function_exists('resolveRecipients')) {
    /**
     * WHO should receive this event =
     *   users with the required permission on the event's page_key   (RBAC)
     *   ∩ (if scope-aware & a project is given) admins + users assigned to that project
     *   − users who muted this event/category in their preferences.
     *
     * (Phase 5 will additionally intersect with admin-configured rules.)
     *
     * @param array $event row from notification_events
     */
    function resolveRecipients(PDO $pdo, array $event, array $ctx = []): array
    {
        $pageKey = (string)($event['page_key'] ?? '');
        $verb    = (string)($event['required_verb'] ?? 'view');
        if ($pageKey === '') return [];

        $recipients = usersWithPermission($pdo, $pageKey, $verb);
        if (!$recipients) return [];

        // ── Project-scope filter ──────────────────────────────────────────
        // A scope-aware event tied to a specific project goes only to admins
        // plus users assigned to that project (user_projects). A scope-aware
        // event with no project (company-wide instance) is NOT narrowed.
        $scopeAware = ((int)($event['scope_aware'] ?? 0) === 1);
        $projectId  = isset($ctx['project_id']) ? (int)$ctx['project_id'] : 0;
        if ($scopeAware && $projectId > 0) {
            $assignedSet = [];
            try {
                $st = $pdo->prepare("SELECT user_id FROM user_projects WHERE project_id = ?");
                $st->execute([$projectId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $aid) $assignedSet[(int)$aid] = true;
            } catch (Throwable $e) {
                error_log('resolveRecipients scope lookup: ' . $e->getMessage());
            }
            foreach ($recipients as $uid => $u) {
                if (!empty($u['is_admin'])) continue;          // admins always in scope
                if (!isset($assignedSet[$uid])) unset($recipients[$uid]);
            }
        }

        // ── Per-user mute preferences ─────────────────────────────────────
        $eventKey = (string)($event['event_key'] ?? '');
        $category = (string)($ctx['category'] ?? ($event['module'] ?? ''));
        foreach ($recipients as $uid => $u) {
            if (notifUserMuted($u['prefs'] ?? null, $eventKey, $category)) {
                unset($recipients[$uid]);
            }
        }

        return $recipients;
    }
}

if (!function_exists('dispatchEvent')) {
    /**
     * The bus. Resolve recipients for $eventKey and create in-app notifications
     * (deduped + logged). Returns a summary; never throws.
     *
     * $ctx keys (all optional): title, message, type, category, severity,
     *   action_url, project_id, customer_id, document_id, loan_id,
     *   entity_type, entity_id, dedupe_suffix (default = today), dedupe_key.
     */
    function dispatchEvent(PDO $pdo, string $eventKey, array $ctx = []): array
    {
        $summary = ['dispatched' => false, 'reason' => '', 'recipients' => 0, 'created' => 0, 'skipped' => 0];
        try {
            // Master kill-switch (default ON when unset).
            if (function_exists('get_setting') && (string)get_setting('notif_master_enabled', '1') === '0') {
                $summary['reason'] = 'master_off';
                return $summary;
            }

            $ev = $pdo->prepare("SELECT * FROM notification_events WHERE event_key = ? LIMIT 1");
            $ev->execute([$eventKey]);
            $event = $ev->fetch(PDO::FETCH_ASSOC);
            if (!$event) {
                $summary['reason'] = 'unknown_event';
                notifLog($pdo, ['event_key' => $eventKey, 'channel' => 'inapp', 'status' => 'skipped', 'detail' => 'unknown event']);
                return $summary;
            }
            if ((int)$event['is_active'] !== 1) {
                $summary['reason'] = 'event_inactive';
                return $summary;
            }

            $recipients = resolveRecipients($pdo, $event, $ctx);
            $summary['recipients'] = count($recipients);

            $entityType = $ctx['entity_type'] ?? null;
            $entityId   = isset($ctx['entity_id']) ? (int)$ctx['entity_id'] : 0;
            $suffix     = $ctx['dedupe_suffix'] ?? date('Y-m-d');
            $base       = $eventKey . '|' . ($entityType ?? '') . '|' . $entityId . '|' . $suffix;

            $payload = [
                'title'       => $ctx['title']    ?? $event['title'],
                'message'     => $ctx['message']  ?? ($event['description'] ?? $event['title']),
                'type'        => $ctx['type']     ?? 'alert',
                'event_key'   => $eventKey,
                'category'    => $ctx['category'] ?? ($event['module'] ?? null),
                'priority'    => $ctx['severity'] ?? ($event['default_severity'] ?? 'medium'),
                'action_url'  => $ctx['action_url']  ?? null,
                'project_id'  => $ctx['project_id']  ?? null,
                'customer_id' => $ctx['customer_id'] ?? null,
                'document_id' => $ctx['document_id'] ?? null,
                'loan_id'     => $ctx['loan_id']     ?? null,
            ];

            foreach ($recipients as $uid => $u) {
                $dk = ($ctx['dedupe_key'] ?? $base) . '|u' . $uid . '|inapp';
                if (!notifClaimDedupe($pdo, $dk)) {
                    $summary['skipped']++;
                    continue; // already notified for this milestone/period
                }
                $nid = createNotification($pdo, (int)$uid, $payload);
                if ($nid > 0) {
                    $summary['created']++;
                    notifLog($pdo, [
                        'event_key' => $eventKey, 'recipient_user_id' => $uid, 'channel' => 'inapp',
                        'status' => 'created', 'entity_type' => $entityType, 'entity_id' => $entityId,
                    ]);
                }
            }

            $summary['dispatched'] = true;
            $summary['reason'] = 'ok';
            return $summary;
        } catch (Throwable $e) {
            error_log('dispatchEvent error: ' . $e->getMessage());
            $summary['reason'] = 'error: ' . $e->getMessage();
            return $summary;
        }
    }
}
