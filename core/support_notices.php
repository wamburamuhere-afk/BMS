<?php
/**
 * core/support_notices.php — maintenance / incident notices from BJP to companies
 * (support_center_plan.md D14, Phase 7). Written in the superadmin, shown as a banner
 * inside every targeted company, dismissible per user.
 */

require_once __DIR__ . '/support.php';

const SUPPORT_NOTICE_LEVELS = ['info' => 'Information', 'maintenance' => 'Maintenance', 'incident' => 'Incident'];
const SUPPORT_NOTICE_TARGETS = ['all' => 'All companies', 'product' => 'One product', 'plan' => 'One plan', 'tenant' => 'One company'];

if (!function_exists('supportValidateNotice')) {
    /** @return array{ok:bool, errors:array, values:array} */
    function supportValidateNotice(array $in, ?PDO $pdo = null): array
    {
        $e = [];
        $v = [
            'title' => trim((string)($in['title'] ?? '')), 'body' => trim((string)($in['body'] ?? '')),
            'level' => (string)($in['level'] ?? 'info'), 'target' => (string)($in['target'] ?? 'all'),
            'target_value' => trim((string)($in['target_value'] ?? '')),
        ];
        if (mb_strlen($v['title']) < 3 || mb_strlen($v['title']) > 191) $e['title'] = 'Enter a title (3–191 characters).';
        if (mb_strlen($v['body']) < 3 || mb_strlen($v['body']) > 1000) $e['body'] = 'Write the message (3–1,000 characters).';
        if (!isset(SUPPORT_NOTICE_LEVELS[$v['level']])) $e['level'] = 'Choose a level.';
        if (!isset(SUPPORT_NOTICE_TARGETS[$v['target']])) $e['target'] = 'Choose who sees it.';
        if ($v['target'] === 'all') {
            $v['target_value'] = null;
        } elseif (!isset($e['target'])) {
            $exists = false;
            if ($pdo) {
                $sql = ['product' => 'SELECT 1 FROM platform_products WHERE product_key = ?', 'plan' => 'SELECT 1 FROM plans WHERE plan_key = ?',
                        'tenant' => "SELECT 1 FROM tenants WHERE id = ? AND status <> 'deleted'"][$v['target']];
                $st = $pdo->prepare($sql);
                $st->execute([$v['target_value']]);
                $exists = (bool)$st->fetchColumn();
            }
            if (!$exists) $e['target_value'] = 'Choose a valid ' . ($v['target'] === 'tenant' ? 'company' : $v['target']) . '.';
        }
        $tz = new DateTimeZone('Africa/Dar_es_Salaam');
        $parse = function (string $s) use ($tz): ?DateTimeImmutable {
            $s = trim(str_replace('T', ' ', $s));
            if ($s === '') return null;
            $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($s, 0, 16), $tz);
            return $d ?: null;
        };
        $start = $parse((string)($in['starts_at'] ?? ''));
        $endRaw = trim((string)($in['ends_at'] ?? ''));
        $end = $parse($endRaw);
        if (!$start) $e['starts_at'] = 'Choose when it starts.';
        if ($endRaw !== '' && !$end) $e['ends_at'] = 'The end time is not valid.';
        if ($start && $end && $end <= $start) $e['ends_at'] = 'It must end after it starts.';
        $v['starts_at'] = $start ? $start->format('Y-m-d H:i:00') : null;
        $v['ends_at'] = $end ? $end->format('Y-m-d H:i:00') : null;
        return ['ok' => !$e, 'errors' => $e, 'values' => $v];
    }
}

if (!function_exists('supportNoticeSave')) {
    function supportNoticeSave(array $v, ?int $id, int $agentId): int
    {
        $pdo = getControlPdo();
        $cols = [$v['title'], $v['body'], $v['level'], $v['target'], $v['target_value'], $v['starts_at'], $v['ends_at']];
        if ($id) {
            $pdo->prepare('UPDATE support_notices SET title=?, body=?, level=?, target=?, target_value=?, starts_at=?, ends_at=? WHERE id=?')->execute(array_merge($cols, [$id]));
            return $id;
        }
        $pdo->prepare('INSERT INTO support_notices (title, body, level, target, target_value, starts_at, ends_at, created_by) VALUES (?,?,?,?,?,?,?,?)')->execute(array_merge($cols, [$agentId]));
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('supportNoticeEnd')) {
    /** "End now" — the notice stops showing; nothing is deleted. */
    function supportNoticeEnd(int $id): bool
    {
        $pdo = getControlPdo();
        $chk = $pdo->prepare('SELECT 1 FROM support_notices WHERE id = ?'); $chk->execute([$id]);
        if (!$chk->fetchColumn()) return false;
        $pdo->prepare('UPDATE support_notices SET ends_at = NOW() WHERE id = ? AND (ends_at IS NULL OR ends_at > NOW())')->execute([$id]);
        return true;
    }
}

if (!function_exists('supportNoticeList')) {
    function supportNoticeList(): array
    {
        return getControlPdo()->query("SELECT n.*, CASE WHEN n.starts_at > NOW() THEN 'scheduled' WHEN n.ends_at IS NOT NULL AND n.ends_at <= NOW() THEN 'ended' ELSE 'live' END AS state
            FROM support_notices n ORDER BY (n.ends_at IS NULL OR n.ends_at > NOW()) DESC, n.starts_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('supportActiveNotices')) {
    /** Live notices for one company and user, not yet dismissed by that user. Never throws. */
    function supportActiveNotices(int $tenantId, int $userId, ?string $productKey, ?string $planKey): array
    {
        try {
            $st = getControlPdo()->prepare("SELECT n.id, n.title, n.body, n.level FROM support_notices n
                WHERE n.starts_at <= NOW() AND (n.ends_at IS NULL OR n.ends_at > NOW())
                  AND (n.target = 'all' OR (n.target = 'tenant' AND n.target_value = ?) OR (n.target = 'product' AND n.target_value = ?) OR (n.target = 'plan' AND n.target_value = ?))
                  AND NOT EXISTS (SELECT 1 FROM support_notice_dismissals d WHERE d.notice_id = n.id AND d.tenant_id = ? AND d.user_id = ?)
                ORDER BY FIELD(n.level, 'incident', 'maintenance', 'info'), n.starts_at DESC LIMIT 3");
            $st->execute([(string)$tenantId, (string)$productKey, (string)$planKey, $tenantId, $userId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('supportDismissNotice')) {
    function supportDismissNotice(int $noticeId, int $tenantId, int $userId): bool
    {
        if ($noticeId <= 0 || $userId <= 0) return false;
        $pdo = getControlPdo();
        $chk = $pdo->prepare('SELECT 1 FROM support_notices WHERE id = ?'); $chk->execute([$noticeId]);
        if (!$chk->fetchColumn()) return false;
        $pdo->prepare('INSERT IGNORE INTO support_notice_dismissals (notice_id, tenant_id, user_id) VALUES (?, ?, ?)')->execute([$noticeId, $tenantId, $userId]);
        return true;
    }
}

if (!function_exists('supportTenantPlanKey')) {
    function supportTenantPlanKey(int $tenantId): ?string
    {
        if ($tenantId <= 0) return null;
        try { $st = getControlPdo()->prepare('SELECT plan FROM tenants WHERE id = ?'); $st->execute([$tenantId]); $p = $st->fetchColumn(); return $p !== false && $p !== null ? (string)$p : null; }
        catch (Throwable $e) { return null; }
    }
}

if (!function_exists('supportNoticeBanner')) {
    /** The banner HTML for header.php (escaped), or '' when nothing is live. */
    function supportNoticeBanner(): string
    {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return '';
        require_once __DIR__ . '/support_requests.php';
        require_once __DIR__ . '/support_articles.php';
        $tid = supportTenantId();
        $notices = supportActiveNotices($tid, $uid, supportTenantProductKey(), supportTenantPlanKey($tid));
        if (!$notices) return '';
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $html = '';
        foreach ($notices as $n) {
            $style = $n['level'] === 'incident' ? 'background:#f8d7da;color:#842029;border-color:#f5c2c7' : 'background:#cfe2ff;color:#084298;border-color:#b6ccfe';
            $icon = $n['level'] === 'incident' ? 'bi-exclamation-octagon' : ($n['level'] === 'maintenance' ? 'bi-tools' : 'bi-info-circle');
            $html .= '<div class="bms-notice d-flex align-items-start gap-2 px-3 px-md-4 py-2 border-bottom small d-print-none" role="status" style="' . $style . '" data-notice="' . (int)$n['id'] . '">'
                   . '<i class="bi ' . $icon . ' mt-1"></i><div class="flex-grow-1"><strong>' . $e($n['title']) . '</strong> — ' . $e($n['body']) . '</div>'
                   . '<button type="button" class="btn-close btn-sm bms-notice-x" aria-label="' . $e(function_exists('t') ? t('Dismiss') : 'Dismiss') . '"></button></div>';
        }
        return $html;
    }
}
