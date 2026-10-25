<?php
/**
 * core/support_requests.php — support requests (support_center_plan.md, Phase 4).
 *
 * One table (control DB support_tickets), two audiences:
 *   company — a staff member asked their own company admin   (ref HLP-YYYY-NNNN)
 *   bjp     — a company admin asked BJP, or forwarded a staff request (ref SUP-YYYY-NNNN)
 * Routing is fixed: non-admin → company admin; admin → BJP; admin may forward.
 *
 * Access rule (enforced in every function that reads or writes a request):
 *   same tenant AND (the requester OR a company admin of that tenant).
 * Internal BJP notes (is_internal = 1) are never returned to a tenant.
 */

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/help_nav.php';

const SUPPORT_CATEGORIES = ['problem' => 'Something isn\'t working', 'how_to' => 'How do I…?', 'access' => 'I need access to a page',
                            'billing' => 'Billing & subscription', 'suggestion' => 'Suggestion'];
const SUPPORT_PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent — business stopped'];
const SUPPORT_ATTACH_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
const SUPPORT_MAX_FILES = 3;

if (!function_exists('supportTenantId')) {
    /** Current company id in the control DB; 0 on a legacy single-company install. */
    function supportTenantId(): int
    {
        return (int)(function_exists('bmsCurrentTenantId') ? (bmsCurrentTenantId() ?? 0) : 0);
    }
}

if (!function_exists('supportCurrentUser')) {
    /** The signed-in tenant user as the request functions need it. */
    function supportCurrentUser(): array
    {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $name = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
        $email = (string)($_SESSION['email'] ?? '');
        if ($uid && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try {
                $st = $GLOBALS['pdo']->prepare('SELECT u.username, u.email, u.first_name, u.last_name, r.role_name FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?');
                $st->execute([$uid]);
                if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                    $full = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    $name = $full !== '' ? $full : (string)$u['username'];
                    $email = (string)($u['email'] ?? '');
                    $role = (string)($u['role_name'] ?? '');
                }
            } catch (Throwable $e) { /* fall back to session values */ }
        }
        return [
            'tenant_id' => supportTenantId(), 'user_id' => $uid, 'name' => $name !== '' ? mb_substr($name, 0, 191) : 'User',
            'email' => mb_substr($email, 0, 191), 'role' => mb_substr($role ?? (string)($_SESSION['role'] ?? ''), 0, 100),
            'admin' => function_exists('isAdmin') && isAdmin(),
        ];
    }
}

// ── Validation ───────────────────────────────────────────────────────────────

if (!function_exists('supportValidateRequest')) {
    function supportValidateRequest(array $in, bool $isAdmin): array
    {
        $e = [];
        $v = [
            'subject' => trim((string)($in['subject'] ?? '')),
            'body' => trim((string)($in['body'] ?? '')),
            'category' => (string)($in['category'] ?? 'problem'),
            'priority' => (string)($in['priority'] ?? 'normal'),
            'page_key' => (string)($in['page_key'] ?? ''),
            'article_id' => ctype_digit((string)($in['article_id'] ?? '')) ? (int)$in['article_id'] : null,
        ];
        if (mb_strlen($v['subject']) < 3 || mb_strlen($v['subject']) > 255) $e['subject'] = 'Write a short subject (3–255 characters).';
        if (mb_strlen($v['body']) < 5) $e['body'] = 'Describe the problem (at least 5 characters).';
        if (mb_strlen($v['body']) > 5000) $e['body'] = 'The description is too long (5,000 characters max).';
        if (!isset(SUPPORT_CATEGORIES[$v['category']]) || ($v['category'] === 'billing' && !$isAdmin)) $e['category'] = 'Choose a type from the list.';
        if (!isset(SUPPORT_PRIORITIES[$v['priority']])) $e['priority'] = 'Choose how urgent it is.';
        if ($v['page_key'] !== '' && !isset(helpNavCatalog()[$v['page_key']])) $v['page_key'] = '';
        return ['ok' => !$e, 'errors' => $e, 'values' => $v];
    }
}

if (!function_exists('supportValidateUploads')) {
    /**
     * Normalise $_FILES[$field] (single or multiple) and check each file:
     * real upload, size limit, content type sniffed with finfo (not the browser's claim).
     * @return array{ok:bool, error:?string, files:array}
     */
    function supportValidateUploads(?array $f): array
    {
        $files = [];
        if (!$f || !isset($f['name'])) return ['ok' => true, 'error' => null, 'files' => []];
        $n = is_array($f['name']) ? count($f['name']) : 1;
        $maxMb = max(1, min(20, (int)getPlatformSetting('support_attach_max_mb', '5')));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        for ($i = 0; $i < $n; $i++) {
            $get = fn($k) => is_array($f[$k]) ? ($f[$k][$i] ?? null) : $f[$k];
            $err = (int)$get('error');
            if ($err === UPLOAD_ERR_NO_FILE || (string)$get('name') === '') continue;
            if ($err !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'A file could not be uploaded. Please try again.', 'files' => []];
            $tmp = (string)$get('tmp_name');
            if (!is_uploaded_file($tmp) && !defined('SUPPORT_TEST_UPLOADS')) return ['ok' => false, 'error' => 'A file could not be uploaded. Please try again.', 'files' => []];
            $size = (int)$get('size');
            if ($size <= 0 || $size > $maxMb * 1024 * 1024) return ['ok' => false, 'error' => 'One of the files is too large.', 'files' => [], 'max_mb' => $maxMb];
            $mime = (string)$finfo->file($tmp);
            if (!isset(SUPPORT_ATTACH_TYPES[$mime])) return ['ok' => false, 'error' => 'Only JPG, PNG or PDF files can be attached.', 'files' => []];
            $orig = preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', basename((string)$get('name')));
            $files[] = ['tmp' => $tmp, 'mime' => $mime, 'size' => $size, 'name' => mb_substr($orig !== '' ? $orig : 'file', 0, 200)];
        }
        if (count($files) > SUPPORT_MAX_FILES) return ['ok' => false, 'error' => 'Attach up to 3 files.', 'files' => []];
        return ['ok' => true, 'error' => null, 'files' => $files];
    }
}

if (!function_exists('supportAttachmentDir')) {
    /** Private folder for one company's support files (no direct web access). */
    function supportAttachmentDir(int $tenantId): string
    {
        $base = dirname(__DIR__) . '/uploads/support';
        if (!is_dir($base)) @mkdir($base, 0750, true);
        $ht = $base . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "# Support attachments are served only by api/support_attachment.php after an access check.\nRequire all denied\nOptions -Indexes -ExecCGI\n");
        }
        $dir = $base . '/' . $tenantId;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        return $dir;
    }
}

if (!function_exists('supportStoreAttachments')) {
    function supportStoreAttachments(array $files, int $tenantId, int $ticketId, int $messageId): void
    {
        if (!$files) return;
        $dir = supportAttachmentDir($tenantId);
        $ins = getControlPdo()->prepare('INSERT INTO support_attachments (ticket_id, message_id, tenant_id, stored_path, original_name, mime, size_bytes) VALUES (?,?,?,?,?,?,?)');
        foreach ($files as $f) {
            $name = bin2hex(random_bytes(16)) . '.' . SUPPORT_ATTACH_TYPES[$f['mime']];
            $dest = $dir . '/' . $name;
            $moved = defined('SUPPORT_TEST_UPLOADS') ? copy($f['tmp'], $dest) : move_uploaded_file($f['tmp'], $dest);
            if (!$moved) throw new RuntimeException('Could not store an attachment.');
            @chmod($dest, 0640);
            $ins->execute([$ticketId, $messageId, $tenantId, $tenantId . '/' . $name, $f['name'], $f['mime'], $f['size']]);
        }
    }
}

// ── Reading ──────────────────────────────────────────────────────────────────

if (!function_exists('supportRequestGetForUser')) {
    /** The request if this user may see it, else null. */
    function supportRequestGetForUser(int $id, array $u): ?array
    {
        $st = getControlPdo()->prepare('SELECT * FROM support_tickets WHERE id = ? AND tenant_id = ?');
        $st->execute([$id, $u['tenant_id']]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        if (!$u['admin'] && (int)$t['tenant_user_id'] !== (int)$u['user_id']) return null;
        return $t;
    }
}

if (!function_exists('supportRequestList')) {
    /** $scope 'mine' (own requests) | 'all' (whole company — admins only; others get 'mine'). */
    function supportRequestList(array $u, string $scope = 'mine', int $limit = 200): array
    {
        $sql = 'SELECT id, ref_no, audience, tenant_user_id, user_name, subject, category, priority, status, created_at, last_activity_at, rating
                FROM support_tickets WHERE tenant_id = ?';
        $args = [$u['tenant_id']];
        if (!($u['admin'] && $scope === 'all')) { $sql .= ' AND tenant_user_id = ?'; $args[] = $u['user_id']; }
        $sql .= ' ORDER BY (status IN (\'resolved\',\'closed\')), last_activity_at DESC LIMIT ' . max(1, min(500, $limit));
        $st = getControlPdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('supportRequestMessages')) {
    /** Messages + attachments; internal notes only when $includeInternal (BJP side). */
    function supportRequestMessages(int $ticketId, bool $includeInternal = false): array
    {
        $pdo = getControlPdo();
        $st = $pdo->prepare('SELECT * FROM support_ticket_messages WHERE ticket_id = ?' . ($includeInternal ? '' : ' AND is_internal = 0') . ' ORDER BY created_at, id');
        $st->execute([$ticketId]);
        $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$msgs) return [];
        $at = $pdo->prepare('SELECT id, message_id, original_name, mime, size_bytes FROM support_attachments WHERE ticket_id = ? ORDER BY id');
        $at->execute([$ticketId]);
        $byMsg = [];
        foreach ($at->fetchAll(PDO::FETCH_ASSOC) as $a) $byMsg[(int)$a['message_id']][] = $a;
        foreach ($msgs as &$m) $m['attachments'] = $byMsg[(int)$m['id']] ?? [];
        return $msgs;
    }
}

if (!function_exists('supportRequestWaitingCount')) {
    /** Requests waiting on this user: own requests waiting on them + (admins) staff requests not yet answered. */
    function supportRequestWaitingCount(array $u): int
    {
        try {
            $st = getControlPdo()->prepare("SELECT
                SUM(tenant_user_id = ? AND status = 'waiting_on_customer')
              + SUM(? = 1 AND audience = 'company' AND tenant_user_id <> ? AND status = 'open')
              FROM support_tickets WHERE tenant_id = ?");
            $st->execute([$u['user_id'], $u['admin'] ? 1 : 0, $u['user_id'], $u['tenant_id']]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

// ── Writing ──────────────────────────────────────────────────────────────────

if (!function_exists('supportRequestCreate')) {
    /**
     * @param array $ctx ['page_url' => ..., 'browser' => ..., 'product_key' => ..., 'plan_code' => ...]
     * @return array{ok:bool, error:?string, errors?:array, id?:int, ref?:string}
     */
    function supportRequestCreate(array $in, array $u, array $files, array $ctx = []): array
    {
        if (!$u['user_id']) return ['ok' => false, 'error' => 'Please sign in again.'];
        $r = supportValidateRequest($in, $u['admin']);
        if (!$r['ok']) return ['ok' => false, 'error' => reset($r['errors']), 'errors' => $r['errors']];
        $pdo = getControlPdo();

        // Flood guard: at most 10 new requests per person per hour.
        $c = $pdo->prepare('SELECT COUNT(*) FROM support_tickets WHERE tenant_id = ? AND tenant_user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
        $c->execute([$u['tenant_id'], $u['user_id']]);
        if ((int)$c->fetchColumn() >= 10) return ['ok' => false, 'error' => 'You have sent many requests in the last hour. Please wait a little.'];

        $v = $r['values'];
        $audience = $u['admin'] ? 'bjp' : 'company';
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO support_tickets (ref_no, audience, tenant_id, tenant_user_id, user_name, user_email, user_role, subject, category,
                           priority, status, page_url, page_key, article_id, product_key, plan_code, browser)
                           VALUES (?,?,?,?,?,?,?,?,?,?,\'open\',?,?,?,?,?,?)')
                ->execute(['tmp' . bin2hex(random_bytes(8)), $audience, $u['tenant_id'], $u['user_id'], $u['name'], $u['email'] ?: null, $u['role'] ?: null,
                           $v['subject'], $v['category'], $v['priority'],
                           mb_substr((string)($ctx['page_url'] ?? ''), 0, 500) ?: null, $v['page_key'] ?: null, $v['article_id'],
                           $ctx['product_key'] ?? null, $ctx['plan_code'] ?? null, mb_substr((string)($ctx['browser'] ?? ''), 0, 255) ?: null]);
            $id = (int)$pdo->lastInsertId();
            $ref = sprintf('%s-%s-%04d', $audience === 'bjp' ? 'SUP' : 'HLP', date('Y'), $id);
            $pdo->prepare('UPDATE support_tickets SET ref_no = ? WHERE id = ?')->execute([$ref, $id]);
            $mid = supportAddMessage($id, 'tenant', $u['user_id'], $u['name'], $v['body']);
            supportStoreAttachments($files, $u['tenant_id'], $id, $mid);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('supportRequestCreate: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The request could not be sent. Please try again.'];
        }
        return ['ok' => true, 'error' => null, 'id' => $id, 'ref' => $ref, 'audience' => $audience];
    }
}

if (!function_exists('supportAddMessage')) {
    function supportAddMessage(int $ticketId, string $type, ?int $authorId, ?string $authorName, string $body, bool $internal = false): int
    {
        $pdo = getControlPdo();
        $pdo->prepare('INSERT INTO support_ticket_messages (ticket_id, author_type, author_id, author_name, body, is_internal) VALUES (?,?,?,?,?,?)')
            ->execute([$ticketId, $type, $authorId, $authorName !== null ? mb_substr($authorName, 0, 191) : null, $body, $internal ? 1 : 0]);
        $mid = (int)$pdo->lastInsertId();
        if (!$internal) $pdo->prepare('UPDATE support_tickets SET last_activity_at = NOW() WHERE id = ?')->execute([$ticketId]);
        return $mid;
    }
}

if (!function_exists('supportRequestReply')) {
    /** Tenant-side reply (requester, or a company admin). Moves the status the way the plan's lifecycle says. */
    function supportRequestReply(int $id, array $u, string $body, array $files = []): array
    {
        $t = supportRequestGetForUser($id, $u);
        if (!$t) return ['ok' => false, 'error' => 'This request is not available.'];
        if ($t['status'] === 'closed') return ['ok' => false, 'error' => 'This request is closed. Please send a new request.'];
        $body = trim($body);
        if (mb_strlen($body) < 1 && !$files) return ['ok' => false, 'error' => 'Write a reply.'];
        if (mb_strlen($body) > 5000) return ['ok' => false, 'error' => 'The reply is too long (5,000 characters max).'];
        $isRequester = (int)$t['tenant_user_id'] === (int)$u['user_id'];
        // Admin answering a staff member's own question, vs. admin adding detail to one already with BJP.
        $adminAnswering = !$isRequester && $u['admin'] && $t['audience'] === 'company';
        $adminAdding    = !$isRequester && $u['admin'] && $t['audience'] === 'bjp';
        if (!$isRequester && !$adminAnswering && !$adminAdding) return ['ok' => false, 'error' => 'This request is not available.'];

        if ($adminAnswering) {
            $new = 'waiting_on_customer';
        } elseif (in_array($t['status'], ['waiting_on_customer', 'resolved'], true)) {
            $new = $t['audience'] === 'bjp' ? 'in_progress' : 'open';
        } else {
            $new = $t['status'];
        }
        $pdo = getControlPdo();
        $pdo->beginTransaction();
        try {
            $mid = supportAddMessage($id, 'tenant', $u['user_id'], $u['name'], $body !== '' ? $body : '(attachment)');
            supportStoreAttachments($files, $u['tenant_id'], $id, $mid);
            $sets = 'status = ?';
            $args = [$new];
            if ($t['status'] === 'resolved' && $new !== 'resolved') { $sets .= ', resolved_at = NULL'; }
            if ($adminAnswering && $t['first_response_at'] === null) { $sets .= ', first_response_at = NOW()'; }
            $pdo->prepare("UPDATE support_tickets SET {$sets} WHERE id = ?")->execute(array_merge($args, [$id]));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('supportRequestReply: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The reply could not be sent. Please try again.'];
        }
        return ['ok' => true, 'error' => null, 'status' => $new];
    }
}

if (!function_exists('supportRequestResolve')) {
    function supportRequestResolve(int $id, array $u): array
    {
        $t = supportRequestGetForUser($id, $u);
        if (!$t) return ['ok' => false, 'error' => 'This request is not available.'];
        $isRequester = (int)$t['tenant_user_id'] === (int)$u['user_id'];
        if (!$isRequester && !$u['admin']) return ['ok' => false, 'error' => 'You cannot resolve this request.'];
        if (in_array($t['status'], ['resolved', 'closed'], true)) return ['ok' => false, 'error' => 'This request is already resolved.'];
        getControlPdo()->prepare("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW() WHERE id = ?")->execute([$id]);
        supportAddMessage($id, 'system', null, null, 'Marked as resolved by ' . $u['name'] . '.');
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('supportRequestRate')) {
    function supportRequestRate(int $id, array $u, int $rating, string $comment = ''): array
    {
        $t = supportRequestGetForUser($id, $u);
        if (!$t || (int)$t['tenant_user_id'] !== (int)$u['user_id']) return ['ok' => false, 'error' => 'This request is not available.'];
        if ($t['status'] !== 'resolved') return ['ok' => false, 'error' => 'You can rate a request once it is resolved.'];
        if ($rating < 1 || $rating > 5) return ['ok' => false, 'error' => 'Choose 1 to 5 stars.'];
        getControlPdo()->prepare("UPDATE support_tickets SET rating = ?, rating_comment = ?, status = 'closed', closed_at = NOW() WHERE id = ?")
            ->execute([$rating, mb_substr(trim($comment), 0, 500) ?: null, $id]);
        supportAddMessage($id, 'system', null, null, 'Rated ' . $rating . '/5 and closed.');
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('supportRequestForward')) {
    /** Company admin sends a staff request on to BJP. Same row, new SUP reference, full history kept. */
    function supportRequestForward(int $id, array $u, string $note = ''): array
    {
        if (!$u['admin']) return ['ok' => false, 'error' => 'Only a company admin can forward a request.'];
        $t = supportRequestGetForUser($id, $u);
        if (!$t) return ['ok' => false, 'error' => 'This request is not available.'];
        if ($t['audience'] !== 'company') return ['ok' => false, 'error' => 'This request is already with the support team.'];
        if (in_array($t['status'], ['resolved', 'closed'], true)) return ['ok' => false, 'error' => 'Reopen the request before forwarding it.'];
        $note = trim($note);
        if (mb_strlen($note) > 2000) return ['ok' => false, 'error' => 'The note is too long (2,000 characters max).'];
        $newRef = sprintf('SUP-%s-%04d', date('Y'), $id);
        $pdo = getControlPdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE support_tickets SET audience = 'bjp', ref_no = ?, forwarded_from = ?, status = 'open' WHERE id = ?")
                ->execute([$newRef, $t['ref_no'], $id]);
            if ($note !== '') supportAddMessage($id, 'tenant', $u['user_id'], $u['name'], $note);
            supportAddMessage($id, 'system', null, null, 'Forwarded to the support team by ' . $u['name'] . ' (was ' . $t['ref_no'] . ').');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('supportRequestForward: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The request could not be forwarded. Please try again.'];
        }
        return ['ok' => true, 'error' => null, 'ref' => $newRef];
    }
}

if (!function_exists('supportAttachmentForUser')) {
    /** An attachment row + absolute file path if this user may download it, else null. */
    function supportAttachmentForUser(int $attId, array $u): ?array
    {
        $st = getControlPdo()->prepare('SELECT a.*, m.is_internal FROM support_attachments a JOIN support_ticket_messages m ON m.id = a.message_id WHERE a.id = ? AND a.tenant_id = ?');
        $st->execute([$attId, $u['tenant_id']]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a || (int)$a['is_internal'] === 1) return null;
        if (!supportRequestGetForUser((int)$a['ticket_id'], $u)) return null;
        if (!preg_match('~^\d+/[a-f0-9]{32}\.(jpg|png|pdf)$~', (string)$a['stored_path'])) return null;
        $path = dirname(__DIR__) . '/uploads/support/' . $a['stored_path'];
        if (!is_file($path)) return null;
        $a['path'] = $path;
        return $a;
    }
}

if (!function_exists('supportStatusLabel')) {
    /** What the tenant user sees for a status. */
    function supportStatusLabel(array $t, array $u): array
    {
        $mine = (int)$t['tenant_user_id'] === (int)$u['user_id'];
        switch ($t['status']) {
            case 'open':
                return ($u['admin'] && !$mine && $t['audience'] === 'company') ? ['Needs your reply', 'waiting'] : ['Open', 'open'];
            case 'in_progress': return ['In progress', 'progress'];
            case 'waiting_on_customer': return $mine ? ['Waiting on you', 'waiting'] : ['Waiting on requester', 'pending'];
            case 'resolved': return ['Resolved', 'resolved'];
            case 'closed': return ['Closed', 'closed'];
        }
        return [ucfirst((string)$t['status']), 'pending'];
    }
}
