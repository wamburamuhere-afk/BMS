<?php
/**
 * api/ai_audit_analysis.php
 * AI Audit Intelligence — admin-only endpoint.
 * Aggregates activity_logs data into a token-efficient context block,
 * then calls aiComplete() for one of four analysis modes:
 *   briefing  — plain-English narrative summary of the period
 *   anomalies — structured list of suspicious patterns with severity
 *   ask       — free-form question answered from the log data
 *   report    — formal audit narrative for management / compliance
 */
require_once __DIR__ . '/../roots.php';
require_once ROOT_DIR . '/core/ai_service.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Admin access required']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}
csrf_check();

if (!aiConfigured()) {
    echo json_encode(['success' => false, 'message' => 'AI is not configured. Go to AI Settings to enable it.']); exit;
}
$cap = aiCapInfo();
if ($cap['exceeded']) {
    echo json_encode(['success' => false, 'message' => 'Monthly AI cost cap reached ($' . number_format($cap['cap'], 2) . '). Adjust it in AI Settings.']); exit;
}
if (aiRateLimited(6)) {
    echo json_encode(['success' => false, 'message' => 'Too many AI requests. Please wait a moment.']); exit;
}

$mode      = $_POST['mode'] ?? 'briefing';
$date_from = $_POST['date_from'] ?? date('Y-m-d');
$date_to   = $_POST['date_to']   ?? date('Y-m-d');
$user_id   = (int)($_POST['user_id'] ?? 0) ?: null;
$query     = trim($_POST['query'] ?? '');

// Report mode has its own scope selectors
$report_uid  = (int)($_POST['report_user_id'] ?? 0) ?: null;
$report_from = $_POST['report_from'] ?? date('Y-m-01');
$report_to   = $_POST['report_to']   ?? date('Y-m-d');

if (!in_array($mode, ['briefing', 'anomalies', 'ask', 'report'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid mode']); exit;
}
if ($mode === 'ask' && $query === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter a question first.']); exit;
}

// Validate date formats
$dateRx = '/^\d{4}-\d{2}-\d{2}$/';
foreach ([$date_from, $date_to, $report_from, $report_to] as $d) {
    if ($d && !preg_match($dateRx, $d)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']); exit;
    }
}

// ── Context builder ───────────────────────────────────────────────────────────
// Produces a token-efficient (~600 token) structured text block from real DB data.
// The AI only interprets and narrates — it never generates numbers on its own.
function buildAuditContext(PDO $pdo, string $from, string $to, ?int $uid): string
{
    $fromDt = $from . ' 00:00:00';
    $toDt   = $to   . ' 23:59:59';
    $bp     = $uid ? [$fromDt, $toDt, $uid] : [$fromDt, $toDt];
    $uidSql = $uid ? 'AND al.user_id = ?' : '';

    // 1. Totals by action category
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            COUNT(DISTINCT al.user_id) AS active_users,
            SUM(CASE WHEN al.action LIKE 'View %' OR al.action LIKE 'Viewed %' OR al.action = 'page_view' THEN 1 ELSE 0 END) AS views,
            SUM(CASE WHEN al.action LIKE 'Create %' OR al.action LIKE 'Created %' OR al.action LIKE 'Add %' OR al.action LIKE 'Record%' THEN 1 ELSE 0 END) AS creates,
            SUM(CASE WHEN al.action LIKE 'Edit %' OR al.action LIKE 'Update%' OR al.action LIKE 'Changed%' THEN 1 ELSE 0 END) AS edits,
            SUM(CASE WHEN al.action LIKE 'Delete %' OR al.action LIKE 'Deleted %' OR al.action LIKE 'Void%' OR al.action LIKE 'Remove%' THEN 1 ELSE 0 END) AS deletes,
            SUM(CASE WHEN al.action LIKE 'Approve%' OR al.action LIKE 'Approved%' THEN 1 ELSE 0 END) AS approvals
        FROM activity_logs al
        WHERE al.created_at BETWEEN ? AND ? $uidSql
    ");
    $st->execute($bp);
    $totals = $st->fetch(PDO::FETCH_ASSOC);

    // 2. Per-user breakdown with 30-day averages for comparison
    $avg30from = date('Y-m-d', strtotime('-30 days', strtotime($to))) . ' 00:00:00';
    $userSt = $pdo->prepare("
        SELECT
            COALESCE(u.username, CONCAT('User#', al.user_id)) AS username,
            COUNT(*) AS total,
            SUM(CASE WHEN al.action LIKE 'Create %' OR al.action LIKE 'Created %' OR al.action LIKE 'Add %' OR al.action LIKE 'Record%' THEN 1 ELSE 0 END) AS creates,
            SUM(CASE WHEN al.action LIKE 'Edit %'   OR al.action LIKE 'Update%' THEN 1 ELSE 0 END) AS edits,
            SUM(CASE WHEN al.action LIKE 'Delete %' OR al.action LIKE 'Deleted %' OR al.action LIKE 'Void%' THEN 1 ELSE 0 END) AS deletes,
            SUM(CASE WHEN al.action LIKE 'View %'   OR al.action LIKE 'Viewed %' OR al.action = 'page_view' THEN 1 ELSE 0 END) AS views,
            SUM(CASE WHEN al.action LIKE 'Approve%' THEN 1 ELSE 0 END) AS approvals,
            MIN(al.created_at) AS first_seen,
            MAX(al.created_at) AS last_seen
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.created_at BETWEEN ? AND ? $uidSql
        GROUP BY al.user_id, u.username
        ORDER BY total DESC LIMIT 15
    ");
    $userSt->execute($bp);
    $userRows = $userSt->fetchAll(PDO::FETCH_ASSOC);

    // 30-day delete averages per user (for anomaly comparison)
    $avgSt = $pdo->prepare("
        SELECT
            COALESCE(u.username, CONCAT('User#', al.user_id)) AS username,
            ROUND(COUNT(*) / 30.0, 1) AS avg_events_per_day,
            ROUND(SUM(CASE WHEN al.action LIKE 'Delete %' OR al.action LIKE 'Deleted %' OR al.action LIKE 'Void%' THEN 1 ELSE 0 END) / 30.0, 2) AS avg_deletes_per_day
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.created_at BETWEEN ? AND ?
        GROUP BY al.user_id, u.username
    ");
    $avgSt->execute([$avg30from, $toDt]);
    $avgMap = [];
    foreach ($avgSt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $avgMap[$a['username']] = $a;
    }

    // 3. Off-hours activity: before 08:00 or after 18:00 EAT
    $ohSt = $pdo->prepare("
        SELECT COALESCE(u.username, 'Unknown') AS username, COUNT(*) AS events,
               MIN(DATE_FORMAT(al.created_at, '%H:%i')) AS earliest,
               MAX(DATE_FORMAT(al.created_at, '%H:%i')) AS latest
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.created_at BETWEEN ? AND ?
          AND (HOUR(al.created_at) < 8 OR HOUR(al.created_at) >= 18)
          $uidSql
        GROUP BY al.user_id ORDER BY events DESC
    ");
    $ohSt->execute($bp);
    $offHours = $ohSt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Sensitive module access — hardcoded business-critical patterns
    $sensitiveModules = [
        'Payroll'       => ['payroll', 'Payroll', 'salary'],
        'Journal Entry' => ['journal entr', 'Journal Entr'],
        'Bank Account'  => ['bank account', 'Bank Account'],
        'Payment Voucher' => ['payment voucher', 'Payment Voucher'],
        'WHT / Tax'     => ['withholding', 'WHT', 'tax setting'],
        'User Mgmt'     => ['user management', 'User Management', 'user roles'],
        'Petty Cash'    => ['petty cash', 'Petty Cash'],
    ];
    $sensitiveLines = [];
    foreach ($sensitiveModules as $label => $patterns) {
        $orParts = []; $orParams = [];
        foreach ($patterns as $kw) {
            $orParts[]  = 'al.action LIKE ? OR al.description LIKE ?';
            $orParams[] = '%' . $kw . '%';
            $orParams[] = '%' . $kw . '%';
        }
        $cntSt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al WHERE al.created_at BETWEEN ? AND ? $uidSql AND (" . implode(' OR ', $orParts) . ")");
        $cntSt->execute(array_merge($bp, $orParams));
        $cnt = (int)$cntSt->fetchColumn();
        if ($cnt > 0) {
            $whoSt = $pdo->prepare("
                SELECT COALESCE(u.username, 'Unknown') AS username, COUNT(*) c
                FROM activity_logs al LEFT JOIN users u ON al.user_id = u.user_id
                WHERE al.created_at BETWEEN ? AND ? $uidSql AND (" . implode(' OR ', $orParts) . ")
                GROUP BY al.user_id ORDER BY c DESC LIMIT 4
            ");
            $whoSt->execute(array_merge($bp, $orParams));
            $who = implode(', ', array_map(fn($w) => "{$w['username']} ×{$w['c']}", $whoSt->fetchAll(PDO::FETCH_ASSOC)));
            $sensitiveLines[] = "  • $label: {$cnt}× — $who";
        }
    }

    // 5. Recent significant (non-view) events
    $recSt = $pdo->prepare("
        SELECT COALESCE(u.username, 'Unknown') AS username,
               al.action, al.description, DATE_FORMAT(al.created_at, '%H:%i') AS t
        FROM activity_logs al LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.created_at BETWEEN ? AND ?
          AND al.action NOT LIKE 'View %' AND al.action NOT LIKE 'Viewed %' AND al.action != 'page_view'
          $uidSql
        ORDER BY al.created_at DESC LIMIT 10
    ");
    $recSt->execute($bp);
    $recentEvents = $recSt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Peak hour
    $phSt = $pdo->prepare("
        SELECT HOUR(al.created_at) AS hr, COUNT(*) AS cnt
        FROM activity_logs al WHERE al.created_at BETWEEN ? AND ? $uidSql
        GROUP BY hr ORDER BY cnt DESC LIMIT 1
    ");
    $phSt->execute($bp);
    $peak = $phSt->fetch(PDO::FETCH_ASSOC);

    // ── Compose context block ─────────────────────────────────────────────────
    $company = get_setting('company_name', 'the company');
    $period  = ($from === $to) ? $from : "$from to $to";
    $ctx  = "=== BMS SYSTEM AUDIT DATA ===\n";
    $ctx .= "Company : $company (Tanzania)\n";
    $ctx .= "Period  : $period\n";
    if ($uid && !empty($userRows)) {
        $ctx .= "Scope   : Single-user filter — {$userRows[0]['username']}\n";
    }
    $ctx .= "\n--- SUMMARY ---\n";
    $ctx .= "Total events  : {$totals['total']} across {$totals['active_users']} users\n";
    $ctx .= "Creates {$totals['creates']} | Views {$totals['views']} | Edits {$totals['edits']} | Deletes {$totals['deletes']} | Approvals {$totals['approvals']}\n";
    if ($peak) $ctx .= "Peak hour     : {$peak['hr']}:00 ({$peak['cnt']} events)\n";

    $ctx .= "\n--- USER BREAKDOWN ---\n";
    foreach ($userRows as $u) {
        $uname = $u['username'];
        $avg   = $avgMap[$uname] ?? null;
        $flag  = '';
        if ($avg) {
            $daySpan = max(1, (strtotime($to) - strtotime($from)) / 86400 + 1);
            $periodDeletes = (float)$u['deletes'];
            $avgDailyDel   = (float)$avg['avg_deletes_per_day'];
            if ($avgDailyDel > 0 && $periodDeletes > 0) {
                $ratio = round($periodDeletes / ($avgDailyDel * $daySpan), 1);
                if ($ratio >= 3) $flag = " [⚠️ DELETES {$ratio}× above baseline]";
            } elseif ($periodDeletes >= 10 && $avgDailyDel < 1) {
                $flag = " [⚠️ UNUSUAL: {$u['deletes']} deletes vs near-zero baseline]";
            }
        }
        $first = $u['first_seen'] ? date('H:i', strtotime($u['first_seen'])) : '—';
        $last  = $u['last_seen']  ? date('H:i', strtotime($u['last_seen']))  : '—';
        $ctx .= "  {$uname}: {$u['total']} events (C:{$u['creates']} E:{$u['edits']} D:{$u['deletes']} V:{$u['views']} A:{$u['approvals']}) active {$first}–{$last}{$flag}\n";
        if ($avg) {
            $ctx .= "    30-day baseline: {$avg['avg_events_per_day']}/day avg, {$avg['avg_deletes_per_day']} deletes/day\n";
        }
    }

    if (!empty($sensitiveLines)) {
        $ctx .= "\n--- SENSITIVE MODULE ACCESS ---\n";
        $ctx .= implode("\n", $sensitiveLines) . "\n";
    }

    if (!empty($offHours)) {
        $ctx .= "\n--- OFF-HOURS ACTIVITY (outside 08:00–18:00 EAT) ---\n";
        foreach ($offHours as $o) {
            $ctx .= "  {$o['username']}: {$o['events']} events between {$o['earliest']} – {$o['latest']}\n";
        }
    }

    if (!empty($recentEvents)) {
        $ctx .= "\n--- RECENT SIGNIFICANT ACTIONS (newest first, non-view) ---\n";
        foreach ($recentEvents as $r) {
            $desc = mb_strimwidth($r['description'] ?? '', 0, 90, '…');
            $ctx .= "  [{$r['t']}] {$r['username']} — {$r['action']} | {$desc}\n";
        }
    }

    return $ctx;
}

// ── Mode-specific system prompts + user prompts ───────────────────────────────
try {
    $company = get_setting('company_name', 'the company');

    switch ($mode) {
        // ─ Daily Briefing ────────────────────────────────────────────────────
        case 'briefing':
            $ctx = buildAuditContext($pdo, $date_from, $date_to, $user_id);
            $sys = <<<PROMPT
You are a senior enterprise auditor writing concise, professional daily briefings for system administrators.
Rules: Use plain English. Cite specific usernames and exact numbers from the data. Use short paragraphs.
Bold the most important line. End every briefing with a one-line RISK LEVEL: 🟢 Low / 🟡 Medium / 🔴 High and a single justification sentence.
Do not invent or extrapolate beyond what the data shows. Be direct — no filler phrases.
PROMPT;
            $userMsg = "$ctx\n\nWrite the administrator daily briefing for this period. Cover: overall volume, most active users, modules used, and anything that stands out. Then state the risk level.";
            $feature = 'audit_briefing';
            $maxTok  = 700;
            break;

        // ─ Anomaly Scanner ───────────────────────────────────────────────────
        case 'anomalies':
            $ctx = buildAuditContext($pdo, $date_from, $date_to, $user_id);
            $sys = <<<PROMPT
You are a security analyst specializing in ERP audit log anomaly detection.
Return a structured list of findings, one per anomaly. For each finding:
  SEVERITY: 🔴 High / 🟡 Medium / 🟢 Low
  FINDING: [short title]
  DETAIL: [specific numbers from the data — no vague language]
  ACTION: [one recommended action for the admin]
If no anomalies exist, state "✅ No anomalies detected — log looks clean." and briefly explain why.
Check for: unusual deletion rates vs baseline, off-hours access, sensitive module access by unexpected users, bulk operations, dormant accounts suddenly active, approval pattern irregularities.
PROMPT;
            $userMsg = "$ctx\n\nScan this activity data for anomalies. List every finding or confirm the log is clean.";
            $feature = 'audit_anomalies';
            $maxTok  = 900;
            break;

        // ─ Ask the Log ───────────────────────────────────────────────────────
        case 'ask':
            $ctx = buildAuditContext($pdo, $date_from, $date_to, $user_id);
            $sys = <<<PROMPT
You are an audit assistant helping a system administrator understand their ERP activity log.
Answer the administrator's question directly and specifically using ONLY the provided data.
If the data doesn't contain enough detail to answer fully, say exactly what you can and cannot determine from it.
Be concise. Use bullet points if listing multiple items.
PROMPT;
            $userMsg = "$ctx\n\nAdministrator question: $query\n\nAnswer directly.";
            $feature = 'audit_ask';
            $maxTok  = 500;
            break;

        // ─ Audit Report ──────────────────────────────────────────────────────
        case 'report':
            $ctx  = buildAuditContext($pdo, $report_from, $report_to, $report_uid);
            $who  = '';
            if ($report_uid) {
                $us = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
                $us->execute([$report_uid]);
                $who = $us->fetchColumn() ?: "User #{$report_uid}";
            }
            $scope = $who ? "for user: $who" : "system-wide";
            $sys = <<<PROMPT
You are a professional chartered auditor writing formal audit narratives for management and compliance review.
Structure the report with these sections:
  1. EXECUTIVE SUMMARY
  2. SCOPE AND METHODOLOGY
  3. ACTIVITY ANALYSIS
  4. RISK ASSESSMENT (must include a risk rating: Low / Medium / High with justification)
  5. RECOMMENDATIONS
Use formal language. Cite precise numbers. This report is for management — it must be professional and actionable.
PROMPT;
            $userMsg = "$ctx\n\nGenerate a formal audit report $scope for the period {$report_from} to {$report_to}.\nThis report is for {$company} management.";
            $feature = 'audit_report';
            $maxTok  = 1100;
            $date_from = $report_from;
            $date_to   = $report_to;
            break;
    }

    $result = aiComplete([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $userMsg],
    ], ['feature' => $feature, 'max_tokens' => $maxTok, 'temperature' => 0.25]);

    if (!$result['ok']) {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'AI call failed. Check AI Settings.']);
        exit;
    }

    logActivity($pdo, $_SESSION['user_id'],
        'AI Audit ' . ucfirst($mode),
        "Admin ran AI audit {$mode} for {$date_from} to {$date_to}"
    );

    echo json_encode([
        'success' => true,
        'text'    => $result['text'],
        'usage'   => $result['usage'] ?? [],
        'mode'    => $mode,
    ]);

} catch (Throwable $e) {
    error_log('ai_audit_analysis.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Analysis failed. Check the server error log.']);
}
