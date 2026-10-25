<?php
/**
 * core/support_notify.php — who hears about what (support_center_plan.md, Phase 6).
 *
 * Every function here is fail-safe: a notification problem never breaks the request,
 * reply or status change that triggered it (everything is caught and logged).
 *
 *   Company side (in-app via core/notify.php createNotification + email):
 *     staff sends a request          → company admins
 *     admin answers a staff request  → the staff member
 *     staff replies                  → company admins
 *     BJP replies / resolves         → the person who asked ("Support" for staff)
 *   BJP side (email to Support › Settings recipients, or every superadmin; the
 *   Support menu badge is the in-app signal):
 *     new request / forwarded / company reply → BJP;  first reply overdue → BJP once
 *
 * Links always point at the company's OWN address (subdomain + TENANT_BASE_DOMAIN);
 * no domain is written in code.
 */

require_once __DIR__ . '/support_requests.php';

if (!function_exists('supportSendMail')) {
    /**
     * Send one support email. $platform = true → BJP's platform relay as "<platform> Support";
     * false → the company's own mail settings. Tests define SUPPORT_MAIL_CAPTURE_FILE to record
     * messages instead of sending them.
     */
    function supportSendMail(string $to, string $subject, string $html, bool $platform): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        $subject = mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 200);
        if (defined('SUPPORT_MAIL_CAPTURE_FILE')) {
            return (bool)file_put_contents(SUPPORT_MAIL_CAPTURE_FILE, json_encode(['to' => $to, 'subject' => $subject, 'html' => $html, 'platform' => $platform]) . "\n", FILE_APPEND);
        }
        try {
            require_once __DIR__ . '/mailer.php';
            if ($platform) {
                $m = platformMailerOpts();
                if (!$m['configured']) return false;
                $opts = $m['opts'];
                $brand = trim(getPlatformSetting('from_name', getPlatformSetting('platform_name', 'BMS')));
                $opts['from_name'] = $brand . ' Support';
                if (isset($opts['smtp'])) $opts['smtp']['from_name'] = $opts['from_name'];
                return sendEmail($to, $subject, $html, $opts);
            }
            return sendEmail($to, $subject, $html, []);
        } catch (Throwable $e) {
            error_log('supportSendMail: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('supportMailBody')) {
    /** Small, escaped HTML body: greeting, quoted message, a button-link, footer. */
    function supportMailBody(string $intro, ?string $quote, ?string $link, string $linkLabel, string $footer = ''): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $html = '<p>' . $e($intro) . '</p>';
        if ($quote !== null && trim($quote) !== '') {
            $q = mb_strimwidth(trim($quote), 0, 1200, '…');
            $html .= '<blockquote style="border-left:3px solid #0d6efd;margin:0;padding:6px 12px;color:#344054;white-space:pre-wrap">' . $e($q) . '</blockquote>';
        }
        if ($link) {
            $html .= '<p><a href="' . $e($link) . '" style="display:inline-block;background:#0d6efd;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none">' . $e($linkLabel) . '</a></p>';
        }
        if ($footer !== '') $html .= '<p style="color:#6c757d;font-size:12px">' . $e($footer) . '</p>';
        return $html;
    }
}

if (!function_exists('supportTenantUrl')) {
    /** Absolute link into a company's own BMS, e.g. https://shop.<base>/help?request=5 — null when unknown. */
    function supportTenantUrl(int $tenantId, string $path): ?string
    {
        $path = '/' . ltrim($path, '/');
        if ($tenantId > 0 && function_exists('tenantBaseDomain') && tenantBaseDomain()) {
            try {
                $st = getControlPdo()->prepare("SELECT subdomain FROM tenants WHERE id = ? AND status <> 'deleted'");
                $st->execute([$tenantId]);
                $sub = (string)$st->fetchColumn();
                if (preg_match('/^[a-z0-9-]{1,63}$/', $sub)) return 'https://' . $sub . '.' . tenantBaseDomain() . $path;
            } catch (Throwable $e) {}
        }
        if (!empty($_SERVER['HTTP_HOST']) && function_exists('buildUrl') && supportTenantId() === $tenantId) return buildUrl(ltrim($path, '/'));
        return null;
    }
}

if (!function_exists('supportAgentUrl')) {
    /** Link to the request in the platform admin panel, or null. */
    function supportAgentUrl(int $ticketId): ?string
    {
        if (!function_exists('tenantBaseDomain') || !tenantBaseDomain()) return null;
        $label = strtolower(trim((string)getenv('SUPERADMIN_SUBDOMAIN'))) ?: 'superadmin';
        return 'https://' . $label . '.' . tenantBaseDomain() . '/support/request?id=' . $ticketId;
    }
}

if (!function_exists('supportTenantPdo')) {
    /**
     * A company's own database, for writing one in-app notification row in direct response to a
     * BJP action on that company's own request — the same narrow, documented exception
     * notifyTenantOfModuleRequestDecision() makes. Current request's own DB when it is that company.
     */
    function supportTenantPdo(int $tenantId): ?PDO
    {
        if (supportTenantId() === $tenantId && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        if ($tenantId <= 0) return null;
        try {
            $st = getControlPdo()->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
            $st->execute([$tenantId]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t || $t['status'] === 'deleted' || empty($t['db_host']) || empty($t['db_name'])) return null;
            require_once __DIR__ . '/tenant_crypto.php';
            $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
            if ($pw === null) return null;
            return new PDO('mysql:host=' . $t['db_host'] . ';dbname=' . $t['db_name'] . ';charset=utf8mb4', $t['db_username'], $pw,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        } catch (Throwable $e) {
            error_log('supportTenantPdo: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('supportInApp')) {
    function supportInApp(?PDO $tPdo, int $userId, string $title, string $message, int $ticketId): void
    {
        if (!$tPdo || $userId <= 0) return;
        try {
            require_once __DIR__ . '/notify.php';
            createNotification($tPdo, $userId, [
                'title' => mb_substr($title, 0, 250), 'message' => mb_substr($message, 0, 1000), 'type' => 'system',
                'event_key' => 'support_request', 'category' => 'support', 'priority' => 'medium',
                'action_url' => 'help?request=' . $ticketId,
            ]);
        } catch (Throwable $e) {
            error_log('supportInApp: ' . $e->getMessage());
        }
    }
}

if (!function_exists('supportCompanyAdmins')) {
    /** Active company admins: [['user_id','email','name']]. */
    function supportCompanyAdmins(?PDO $tPdo): array
    {
        if (!$tPdo) return [];
        try {
            return $tPdo->query("SELECT u.user_id, u.email, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS name
                FROM users u JOIN roles r ON r.role_id = u.role_id
                WHERE u.is_active = 1 AND (COALESCE(r.is_admin,0) = 1 OR u.role_id = 1)")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('supportBjpRecipients')) {
    /** Support › Settings recipients, else every superadmin. */
    function supportBjpRecipients(): array
    {
        $list = array_filter(array_map('trim', explode(',', getPlatformSetting('support_recipients', ''))));
        if (!$list) {
            try { $list = getControlPdo()->query('SELECT email FROM superadmins')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $list = []; }
        }
        return array_values(array_unique(array_filter($list, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }
}

if (!function_exists('supportTicketRow')) {
    function supportTicketRow(int $id): ?array
    {
        $st = getControlPdo()->prepare('SELECT t.*, tn.company_name FROM support_tickets t LEFT JOIN tenants tn ON tn.id = t.tenant_id WHERE t.id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('supportNotifyBjp')) {
    /** Email BJP about a request (new, forwarded, or the company replied). Assigned agent first, else recipients. */
    function supportNotifyBjp(array $t, string $what, string $message): int
    {
        $to = [];
        if (!empty($t['assigned_to'])) {
            try { $st = getControlPdo()->prepare('SELECT email FROM superadmins WHERE id = ?'); $st->execute([(int)$t['assigned_to']]); $e = $st->fetchColumn(); if ($e) $to = [(string)$e]; } catch (Throwable $e) {}
        }
        if (!$to) $to = supportBjpRecipients();
        $company = (string)($t['company_name'] ?? ('Company #' . $t['tenant_id']));
        $subject = '[' . $t['ref_no'] . '] ' . $what . ': ' . $company . ' — ' . $t['subject'];
        $body = supportMailBody($what . ' from ' . $t['user_name'] . ' (' . $company . ', ' . strtoupper((string)$t['priority']) . ').', $message,
            supportAgentUrl((int)$t['id']), 'Open in the support inbox');
        $n = 0;
        foreach ($to as $email) if (supportSendMail($email, $subject, $body, true)) $n++;
        return $n;
    }
}

if (!function_exists('supportRespondThenRun')) {
    /**
     * Send the JSON response, close the connection, then run $after (notifications), so the
     * user never waits for a mail server. Works on PHP-FPM (fastcgi_finish_request) and mod_php
     * (Content-Length + Connection: close + flush). On the CLI it simply prints then runs.
     */
    function supportRespondThenRun(array $body, callable $after, int $code = 200): void
    {
        $json = json_encode($body);
        if (PHP_SAPI !== 'cli') {
            ignore_user_abort(true);
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . strlen($json));
            header('Connection: close');
            echo $json;
            flush();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        } else {
            echo $json;
        }
        try { $after(); } catch (Throwable $e) { error_log('supportRespondThenRun: ' . $e->getMessage()); }
        exit;
    }
}

// ── Events ───────────────────────────────────────────────────────────────────

if (!function_exists('supportOnCreated')) {
    /** After supportRequestCreate(). Runs in the company's own request. */
    function supportOnCreated(int $ticketId, string $body): void
    {
        try {
            $t = supportTicketRow($ticketId);
            if (!$t) return;
            $tPdo = supportTenantPdo((int)$t['tenant_id']);
            $url = supportTenantUrl((int)$t['tenant_id'], 'help?request=' . $ticketId);
            if ($t['audience'] === 'company') {
                foreach (supportCompanyAdmins($tPdo) as $a) {
                    if ((int)$a['user_id'] === (int)$t['tenant_user_id']) continue;
                    supportInApp($tPdo, (int)$a['user_id'], 'New help request from ' . $t['user_name'], $t['subject'], $ticketId);
                    supportSendMail((string)$a['email'], '[' . $t['ref_no'] . '] Help request from ' . $t['user_name'] . ': ' . $t['subject'],
                        supportMailBody($t['user_name'] . ' asked for help:', $body, $url, 'Open the request'), false);
                }
                return;
            }
            supportNotifyBjp($t, 'New request', $body);
            $auto = trim(getPlatformSetting('support_autoreply', ''));
            supportSendMail((string)$t['user_email'], '[' . $t['ref_no'] . '] We received your request: ' . $t['subject'],
                supportMailBody(($auto !== '' ? $auto : 'Thank you. We received your request and will reply as soon as possible.'),
                    null, $url, 'View your request', 'Reference ' . $t['ref_no'] . '. Please reply inside BMS so the whole conversation stays in one place.'), true);
        } catch (Throwable $e) {
            error_log('supportOnCreated: ' . $e->getMessage());
        }
    }
}

if (!function_exists('supportOnForwarded')) {
    function supportOnForwarded(int $ticketId): void
    {
        try {
            $t = supportTicketRow($ticketId);
            if (!$t) return;
            $msgs = supportRequestMessages($ticketId);
            $first = $msgs[0]['body'] ?? '';
            supportNotifyBjp($t, 'Forwarded request', $first);
            supportInApp(supportTenantPdo((int)$t['tenant_id']), (int)$t['tenant_user_id'], 'Your request was sent to the support team', $t['subject'], $ticketId);
        } catch (Throwable $e) {
            error_log('supportOnForwarded: ' . $e->getMessage());
        }
    }
}

if (!function_exists('supportOnTenantReply')) {
    /** A company user replied (requester, or an admin). */
    function supportOnTenantReply(int $ticketId, int $authorUserId, string $authorName, string $body): void
    {
        try {
            $t = supportTicketRow($ticketId);
            if (!$t) return;
            $tPdo = supportTenantPdo((int)$t['tenant_id']);
            $url = supportTenantUrl((int)$t['tenant_id'], 'help?request=' . $ticketId);
            $isRequester = (int)$t['tenant_user_id'] === $authorUserId;
            if ($t['audience'] === 'company') {
                if ($isRequester) {
                    foreach (supportCompanyAdmins($tPdo) as $a) {
                        if ((int)$a['user_id'] === $authorUserId) continue;
                        supportInApp($tPdo, (int)$a['user_id'], $authorName . ' replied on ' . $t['ref_no'], $t['subject'], $ticketId);
                        supportSendMail((string)$a['email'], '[' . $t['ref_no'] . '] ' . $authorName . ' replied: ' . $t['subject'],
                            supportMailBody($authorName . ' replied:', $body, $url, 'Open the request'), false);
                    }
                } else {
                    supportInApp($tPdo, (int)$t['tenant_user_id'], $authorName . ' answered your request', $t['subject'], $ticketId);
                    supportSendMail((string)$t['user_email'], '[' . $t['ref_no'] . '] Reply to your request: ' . $t['subject'],
                        supportMailBody($authorName . ' replied:', $body, $url, 'View and reply'), false);
                }
                return;
            }
            supportNotifyBjp($t, 'Company replied', $body);
        } catch (Throwable $e) {
            error_log('supportOnTenantReply: ' . $e->getMessage());
        }
    }
}

if (!function_exists('supportOnAgentUpdate')) {
    /**
     * BJP replied publicly or changed the status to resolved/closed. Tells the person who asked,
     * in their BMS and by email from "<platform> Support". Staff see "Support", never "BJP".
     * $kind: 'reply' | 'resolved' | 'closed'
     */
    function supportOnAgentUpdate(int $ticketId, string $kind, string $body = ''): void
    {
        try {
            $t = supportTicketRow($ticketId);
            if (!$t || $t['audience'] !== 'bjp') return;
            $url = supportTenantUrl((int)$t['tenant_id'], 'help?request=' . $ticketId);
            [$title, $intro, $subj] = match ($kind) {
                'resolved' => ['Your request was resolved', 'Your request was marked as resolved. If it is not fixed, just reply and it will reopen.', 'Resolved'],
                'closed'   => ['Your request was closed', 'Your request was closed.', 'Closed'],
                default    => ['Support replied to your request', 'Support replied to your request:', 'Reply'],
            };
            supportInApp(supportTenantPdo((int)$t['tenant_id']), (int)$t['tenant_user_id'], $title . ' (' . $t['ref_no'] . ')', $t['subject'], $ticketId);
            supportSendMail((string)$t['user_email'], '[' . $t['ref_no'] . '] ' . $subj . ': ' . $t['subject'],
                supportMailBody($intro, $kind === 'reply' ? $body : null, $url, 'View and reply', 'Reference ' . $t['ref_no'] . '. Please reply inside BMS so the whole conversation stays in one place.'), true);
        } catch (Throwable $e) {
            error_log('supportOnAgentUpdate: ' . $e->getMessage());
        }
    }
}

// ── Scheduled jobs (api/run_background_jobs.php, platform-throttled) ────────

if (!function_exists('supportRunSlaReminders')) {
    /** Email BJP once for every request whose first reply is overdue. @return int reminders sent */
    function supportRunSlaReminders(): int
    {
        require_once __DIR__ . '/support_inbox.php';
        $sent = 0;
        try {
            $rows = getControlPdo()->query("SELECT t.*, tn.company_name FROM support_tickets t LEFT JOIN tenants tn ON tn.id = t.tenant_id
                WHERE t.audience = 'bjp' AND t.first_response_at IS NULL AND t.sla_reminded_at IS NULL AND t.status IN ('open','in_progress')")->fetchAll(PDO::FETCH_ASSOC);
            $mark = getControlPdo()->prepare('UPDATE support_tickets SET sla_reminded_at = NOW() WHERE id = ? AND sla_reminded_at IS NULL');
            foreach ($rows as $t) {
                if (!supportSlaInfo($t)['overdue']) continue;
                $mark->execute([(int)$t['id']]);
                if ($mark->rowCount() === 0) continue;   // another run claimed it
                if (supportNotifyBjp($t, 'First reply overdue', 'This request has not had a first reply within its target time.') > 0) $sent++;
            }
        } catch (Throwable $e) {
            error_log('supportRunSlaReminders: ' . $e->getMessage());
        }
        return $sent;
    }
}

if (!function_exists('supportRunAutoClose')) {
    /** Close requests resolved more than N days ago with no reply since. @return int closed */
    function supportRunAutoClose(): int
    {
        $days = max(1, min(60, (int)getPlatformSetting('support_autoclose_days', '5')));
        $closed = 0;
        try {
            $pdo = getControlPdo();
            $st = $pdo->prepare("SELECT id, tenant_id, tenant_user_id, ref_no, subject FROM support_tickets WHERE status = 'resolved' AND resolved_at IS NOT NULL AND resolved_at < (NOW() - INTERVAL ? DAY)");
            $st->execute([$days]);
            $upd = $pdo->prepare("UPDATE support_tickets SET status = 'closed', closed_at = NOW() WHERE id = ? AND status = 'resolved'");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $upd->execute([(int)$t['id']]);
                if ($upd->rowCount() === 0) continue;
                supportAddMessage((int)$t['id'], 'system', null, null, "Closed automatically after {$days} days with no reply.");
                supportInApp(supportTenantPdo((int)$t['tenant_id']), (int)$t['tenant_user_id'], 'Your request was closed (' . $t['ref_no'] . ')', $t['subject'], (int)$t['id']);
                $closed++;
            }
        } catch (Throwable $e) {
            error_log('supportRunAutoClose: ' . $e->getMessage());
        }
        return $closed;
    }
}
