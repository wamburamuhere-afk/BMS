<?php
/**
 * core/support_inbox.php — BJP's side of support requests (support_center_plan.md, Phase 5).
 *
 * Superadmin only. Reads ONLY requests whose audience is 'bjp' — staff-to-admin
 * requests that were never forwarded stay invisible to BJP. Internal notes
 * (is_internal = 1) are written here and never shown to a company.
 */

require_once __DIR__ . '/support_requests.php';

const SUPPORT_TZ = 'Africa/Dar_es_Salaam';
const SUPPORT_DAY_START = 8;   // business hours 08:00–17:00, Monday–Friday
const SUPPORT_DAY_END = 17;

if (!function_exists('supportBusinessDue')) {
    /** When $hours business hours after $start have passed (Mon–Fri 08:00–17:00 EAT). */
    function supportBusinessDue(DateTimeImmutable $start, float $hours): DateTimeImmutable
    {
        $tz = new DateTimeZone(SUPPORT_TZ);
        $t = $start->setTimezone($tz);
        $left = (int)round($hours * 3600);
        for ($guard = 0; $guard < 3700 && $left > 0; $guard++) {
            $dow = (int)$t->format('N');
            $dayStart = $t->setTime(SUPPORT_DAY_START, 0);
            $dayEnd = $t->setTime(SUPPORT_DAY_END, 0);
            if ($dow >= 6 || $t >= $dayEnd) { $t = $t->modify('+1 day')->setTime(SUPPORT_DAY_START, 0); continue; }
            if ($t < $dayStart) $t = $dayStart;
            $avail = $dayEnd->getTimestamp() - $t->getTimestamp();
            if ($left <= $avail) return $t->modify("+{$left} seconds");
            $left -= $avail;
            $t = $t->modify('+1 day')->setTime(SUPPORT_DAY_START, 0);
        }
        return $t;
    }
}

if (!function_exists('supportSlaHours')) {
    function supportSlaHours(string $priority): float
    {
        $s = supportSettings();
        $map = ['urgent' => $s['support_sla_urgent'], 'high' => $s['support_sla_high'], 'normal' => $s['support_sla_normal'], 'low' => $s['support_sla_low']];
        return max(1, (float)($map[$priority] ?? $s['support_sla_normal']));
    }
}

if (!function_exists('supportSlaInfo')) {
    /** ['due' => DateTimeImmutable|null, 'overdue' => bool, 'met' => bool] for a request's first reply. */
    function supportSlaInfo(array $t, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone(SUPPORT_TZ));
        $created = new DateTimeImmutable((string)$t['created_at'], new DateTimeZone(SUPPORT_TZ));
        $due = supportBusinessDue($created, supportSlaHours((string)$t['priority']));
        if (!empty($t['first_response_at'])) {
            $first = new DateTimeImmutable((string)$t['first_response_at'], new DateTimeZone(SUPPORT_TZ));
            return ['due' => $due, 'overdue' => false, 'met' => $first <= $due, 'answered' => true];
        }
        if (in_array($t['status'], ['resolved', 'closed'], true)) return ['due' => $due, 'overdue' => false, 'met' => true, 'answered' => true];
        return ['due' => $due, 'overdue' => $now > $due, 'met' => false, 'answered' => false];
    }
}

if (!function_exists('supportSlaLabel')) {
    function supportSlaLabel(array $sla, ?DateTimeImmutable $now = null): string
    {
        if ($sla['answered']) return $sla['met'] ? 'Answered in time' : 'Answered late';
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone(SUPPORT_TZ));
        $diff = $sla['due']->getTimestamp() - $now->getTimestamp();
        $abs = abs($diff);
        $txt = $abs >= 86400 ? floor($abs / 86400) . 'd ' . floor(($abs % 86400) / 3600) . 'h' : ($abs >= 3600 ? floor($abs / 3600) . 'h ' . floor(($abs % 3600) / 60) . 'm' : max(1, floor($abs / 60)) . 'm');
        return $diff >= 0 ? "Due in {$txt}" : "Overdue {$txt}";
    }
}

// ── Inbox reads ──────────────────────────────────────────────────────────────

if (!function_exists('supportInboxNeedsReplySql')) {
    /** SQL condition: BJP owes the next public message. */
    function supportInboxNeedsReplySql(string $alias = 't'): string
    {
        return "({$alias}.status IN ('open','in_progress') AND COALESCE((SELECT m.author_type FROM support_ticket_messages m
                 WHERE m.ticket_id = {$alias}.id AND m.is_internal = 0 AND m.author_type <> 'system' ORDER BY m.created_at DESC, m.id DESC LIMIT 1), 'tenant') = 'tenant')";
    }
}

if (!function_exists('supportInboxNeedsReplyCount')) {
    function supportInboxNeedsReplyCount(): int
    {
        try {
            return (int)getControlPdo()->query("SELECT COUNT(*) FROM support_tickets t WHERE t.audience = 'bjp' AND " . supportInboxNeedsReplySql())->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('supportInboxList')) {
    /**
     * Filters: view (needs_reply|open|waiting|resolved|closed|all — default needs_reply),
     * tenant_id, priority, assigned (me|none|any), q, product. Sorted: overdue first, then time left.
     */
    function supportInboxList(array $f, int $meId): array
    {
        $where = ["t.audience = 'bjp'"]; $args = [];
        $view = (string)($f['view'] ?? 'needs_reply');
        switch ($view) {
            case 'open': $where[] = "t.status IN ('open','in_progress')"; break;
            case 'waiting': $where[] = "t.status = 'waiting_on_customer'"; break;
            case 'resolved': $where[] = "t.status = 'resolved'"; break;
            case 'closed': $where[] = "t.status = 'closed'"; break;
            case 'all': break;
            default: $where[] = supportInboxNeedsReplySql();
        }
        if (!empty($f['tenant_id']) && ctype_digit((string)$f['tenant_id'])) { $where[] = 't.tenant_id = ?'; $args[] = (int)$f['tenant_id']; }
        if (isset(SUPPORT_PRIORITIES[(string)($f['priority'] ?? '')])) { $where[] = 't.priority = ?'; $args[] = (string)$f['priority']; }
        if (($f['assigned'] ?? '') === 'me') { $where[] = 't.assigned_to = ?'; $args[] = $meId; }
        elseif (($f['assigned'] ?? '') === 'none') { $where[] = 't.assigned_to IS NULL'; }
        if (!empty($f['product']) && preg_match('/^[a-z0-9_]{1,40}$/', (string)$f['product'])) { $where[] = 't.product_key = ?'; $args[] = (string)$f['product']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
            $where[] = '(t.ref_no LIKE ? OR t.subject LIKE ? OR t.forwarded_from LIKE ? OR tn.company_name LIKE ?)';
            array_push($args, $like, $like, $like, $like);
        }
        $sql = 'SELECT t.*, tn.company_name, tn.subdomain, sa.name AS assignee_name
                FROM support_tickets t
                LEFT JOIN tenants tn ON tn.id = t.tenant_id
                LEFT JOIN superadmins sa ON sa.id = t.assigned_to
                WHERE ' . implode(' AND ', $where) . ' ORDER BY t.last_activity_at DESC LIMIT 500';
        $st = getControlPdo()->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $now = new DateTimeImmutable('now', new DateTimeZone(SUPPORT_TZ));
        foreach ($rows as &$r) {
            $r['sla'] = supportSlaInfo($r, $now);
            $r['sla_label'] = supportSlaLabel($r['sla'], $now);
        }
        unset($r);
        $prio = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
        usort($rows, function ($a, $b) use ($prio) {
            $ka = [$a['sla']['answered'] ? 1 : 0, $a['sla']['overdue'] ? 0 : 1, $a['sla']['due']->getTimestamp(), $prio[$a['priority']] ?? 9];
            $kb = [$b['sla']['answered'] ? 1 : 0, $b['sla']['overdue'] ? 0 : 1, $b['sla']['due']->getTimestamp(), $prio[$b['priority']] ?? 9];
            return $ka <=> $kb;
        });
        return $rows;
    }
}

if (!function_exists('supportInboxGet')) {
    /** A request that reached BJP, with its company row; null for anything else. */
    function supportInboxGet(int $id): ?array
    {
        $st = getControlPdo()->prepare("SELECT t.*, tn.company_name, tn.subdomain, sa.name AS assignee_name
            FROM support_tickets t LEFT JOIN tenants tn ON tn.id = t.tenant_id LEFT JOIN superadmins sa ON sa.id = t.assigned_to
            WHERE t.id = ? AND t.audience = 'bjp'");
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        $t['sla'] = supportSlaInfo($t);
        $t['sla_label'] = supportSlaLabel($t['sla']);
        return $t;
    }
}

if (!function_exists('supportInboxStats')) {
    function supportInboxStats(): array
    {
        $pdo = getControlPdo();
        $out = ['needs_reply' => supportInboxNeedsReplyCount(), 'overdue' => 0, 'avg_first_hours' => null, 'rating' => null, 'ratings' => 0, 'waiting' => 0];
        $open = $pdo->query("SELECT * FROM support_tickets WHERE audience = 'bjp' AND first_response_at IS NULL AND status IN ('open','in_progress')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($open as $t) if (supportSlaInfo($t)['overdue']) $out['overdue']++;
        $out['waiting'] = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE audience = 'bjp' AND status = 'waiting_on_customer'")->fetchColumn();
        $avg = $pdo->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) FROM support_tickets
                            WHERE audience = 'bjp' AND first_response_at IS NOT NULL AND created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
        if ($avg !== null && $avg !== false) $out['avg_first_hours'] = round(((float)$avg) / 60, 1);
        $r = $pdo->query("SELECT AVG(rating), COUNT(rating) FROM support_tickets WHERE audience = 'bjp' AND rating IS NOT NULL AND closed_at >= NOW() - INTERVAL 30 DAY")->fetch(PDO::FETCH_NUM);
        if ($r && (int)$r[1] > 0) { $out['rating'] = round((float)$r[0], 1); $out['ratings'] = (int)$r[1]; }
        return $out;
    }
}

if (!function_exists('supportTenantContext')) {
    /** What an agent needs to know about the company that asked (one tenant DB read for its modes). */
    function supportTenantContext(int $tenantId): array
    {
        $ctx = ['tenant' => null, 'product' => null, 'simple' => null, 'shop' => null, 'recent' => []];
        if ($tenantId <= 0) return $ctx;
        $pdo = getControlPdo();
        $st = $pdo->prepare('SELECT t.id, t.company_name, t.subdomain, t.status, t.plan, t.owner_email, t.owner_phone, t.trial_ends_at, t.subscription_ends_at,
                             t.max_users, t.max_shops, p.name AS plan_name, pp.name AS product_name, p.product_key
                             FROM tenants t LEFT JOIN plans p ON p.plan_key = t.plan LEFT JOIN platform_products pp ON pp.product_key = p.product_key
                             WHERE t.id = ?');
        try { $st->execute([$tenantId]); $ctx['tenant'] = $st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { $ctx['tenant'] = null; }
        if (function_exists('tenantPosSimpleModeStatus')) {
            try { $sm = tenantPosSimpleModeStatus($tenantId); $ctx['simple'] = is_array($sm) ? (bool)($sm['enabled'] ?? false) : null; } catch (Throwable $e) {}
        }
        if (function_exists('tenantShopModeStatus')) {
            try { $ctx['shop'] = tenantShopModeStatus($tenantId); } catch (Throwable $e) {}
        }
        $r = $pdo->prepare("SELECT id, ref_no, subject, status, created_at FROM support_tickets WHERE tenant_id = ? AND audience = 'bjp' ORDER BY id DESC LIMIT 6");
        $r->execute([$tenantId]);
        $ctx['recent'] = $r->fetchAll(PDO::FETCH_ASSOC);
        return $ctx;
    }
}

if (!function_exists('supportAgents')) {
    function supportAgents(): array
    {
        return getControlPdo()->query('SELECT id, name FROM superadmins ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Agent actions ────────────────────────────────────────────────────────────

if (!function_exists('supportInboxReply')) {
    /**
     * BJP reply or internal note.
     * $after: status after a public reply — waiting_on_customer (default) | in_progress | resolved.
     */
    function supportInboxReply(int $id, array $agent, string $body, bool $internal, array $files = [], string $after = 'waiting_on_customer'): array
    {
        $t = supportInboxGet($id);
        if (!$t) return ['ok' => false, 'error' => 'That request does not exist or is not with BJP.'];
        $body = trim($body);
        if ($body === '' && !$files) return ['ok' => false, 'error' => 'Write a reply.'];
        if (mb_strlen($body) > 10000) return ['ok' => false, 'error' => 'The reply is too long (10,000 characters max).'];
        if ($t['status'] === 'closed' && !$internal) return ['ok' => false, 'error' => 'This request is closed. Reopen it first.'];
        if (!in_array($after, ['waiting_on_customer', 'in_progress', 'resolved'], true)) $after = 'waiting_on_customer';
        $pdo = getControlPdo();
        $pdo->beginTransaction();
        try {
            $mid = supportAddMessage($id, 'bjp', (int)$agent['id'], (string)$agent['name'], $body !== '' ? $body : '(attachment)', $internal);
            supportStoreAttachments($files, (int)$t['tenant_id'], $id, $mid);
            if (!$internal) {
                $sets = ['status = ?']; $args = [$after];
                if ($t['first_response_at'] === null) $sets[] = 'first_response_at = NOW()';
                if ($after === 'resolved') $sets[] = 'resolved_at = NOW()'; else $sets[] = 'resolved_at = NULL';
                if ($t['assigned_to'] === null) { $sets[] = 'assigned_to = ?'; $args[] = (int)$agent['id']; }
                $pdo->prepare('UPDATE support_tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute(array_merge($args, [$id]));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('supportInboxReply: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The reply could not be saved. Please try again.'];
        }
        return ['ok' => true, 'error' => null, 'message_id' => $mid];
    }
}

if (!function_exists('supportInboxAssign')) {
    function supportInboxAssign(int $id, ?int $agentId): array
    {
        if (!supportInboxGet($id)) return ['ok' => false, 'error' => 'That request does not exist or is not with BJP.'];
        if ($agentId !== null) {
            $st = getControlPdo()->prepare('SELECT 1 FROM superadmins WHERE id = ?');
            $st->execute([$agentId]);
            if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'Choose a valid agent.'];
        }
        getControlPdo()->prepare('UPDATE support_tickets SET assigned_to = ? WHERE id = ?')->execute([$agentId, $id]);
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('supportInboxSetStatus')) {
    function supportInboxSetStatus(int $id, string $status, array $agent): array
    {
        $allowed = ['open', 'in_progress', 'waiting_on_customer', 'resolved', 'closed'];
        if (!in_array($status, $allowed, true)) return ['ok' => false, 'error' => 'Unknown status.'];
        $t = supportInboxGet($id);
        if (!$t) return ['ok' => false, 'error' => 'That request does not exist or is not with BJP.'];
        if ($t['status'] === $status) return ['ok' => true, 'error' => null];
        $sets = 'status = ?';
        if ($status === 'resolved') $sets .= ', resolved_at = NOW()';
        if ($status === 'closed') $sets .= ', closed_at = NOW()';
        if (!in_array($status, ['resolved', 'closed'], true)) $sets .= ', resolved_at = NULL, closed_at = NULL';
        getControlPdo()->prepare("UPDATE support_tickets SET {$sets} WHERE id = ?")->execute([$status, $id]);
        $line = ['resolved' => 'Marked as resolved by BJP Support.', 'closed' => 'Closed by BJP Support.', 'open' => 'Reopened by BJP Support.'][$status] ?? null;
        if ($line) supportAddMessage($id, 'system', null, null, $line);
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('supportInboxSetPriority')) {
    function supportInboxSetPriority(int $id, string $priority): array
    {
        if (!isset(SUPPORT_PRIORITIES[$priority])) return ['ok' => false, 'error' => 'Unknown priority.'];
        if (!supportInboxGet($id)) return ['ok' => false, 'error' => 'That request does not exist or is not with BJP.'];
        getControlPdo()->prepare('UPDATE support_tickets SET priority = ? WHERE id = ?')->execute([$priority, $id]);
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('supportCannedList')) {
    function supportCannedList(bool $activeOnly = true): array
    {
        return getControlPdo()->query('SELECT * FROM support_canned_replies' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('supportCannedSave')) {
    function supportCannedSave(array $in, ?int $id, int $agentId): array
    {
        $title = trim((string)($in['title'] ?? ''));
        $body = trim((string)($in['body'] ?? ''));
        if (mb_strlen($title) < 2 || mb_strlen($title) > 191) return ['ok' => false, 'error' => 'Give the saved reply a name (2–191 characters).'];
        if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) return ['ok' => false, 'error' => 'Write the reply text (up to 5,000 characters).'];
        $pdo = getControlPdo();
        if ($id) {
            $st = $pdo->prepare('UPDATE support_canned_replies SET title = ?, body = ? WHERE id = ?');
            $st->execute([$title, $body, $id]);
            $chk = $pdo->prepare('SELECT 1 FROM support_canned_replies WHERE id = ?'); $chk->execute([$id]);
            if (!$chk->fetchColumn()) return ['ok' => false, 'error' => 'That saved reply no longer exists.'];
            return ['ok' => true, 'error' => null, 'id' => $id];
        }
        $pdo->prepare('INSERT INTO support_canned_replies (title, body, created_by) VALUES (?, ?, ?)')->execute([$title, $body, $agentId]);
        return ['ok' => true, 'error' => null, 'id' => (int)$pdo->lastInsertId()];
    }
}

if (!function_exists('supportCannedSetActive')) {
    function supportCannedSetActive(int $id, bool $active): bool
    {
        $pdo = getControlPdo();
        $chk = $pdo->prepare('SELECT 1 FROM support_canned_replies WHERE id = ?'); $chk->execute([$id]);
        if (!$chk->fetchColumn()) return false;
        $pdo->prepare('UPDATE support_canned_replies SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        return true;
    }
}

if (!function_exists('supportAttachmentForAgent')) {
    /** Any attachment on a request that reached BJP (internal ones included). */
    function supportAttachmentForAgent(int $attId): ?array
    {
        $st = getControlPdo()->prepare("SELECT a.* FROM support_attachments a JOIN support_tickets t ON t.id = a.ticket_id WHERE a.id = ? AND t.audience = 'bjp'");
        $st->execute([$attId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a || !preg_match('~^\d+/[a-f0-9]{32}\.(jpg|png|pdf)$~', (string)$a['stored_path'])) return null;
        $path = dirname(__DIR__) . '/uploads/support/' . $a['stored_path'];
        if (!is_file($path)) return null;
        $a['path'] = $path;
        return $a;
    }
}
