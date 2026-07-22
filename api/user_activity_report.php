<?php
/**
 * api/user_activity_report.php
 * User Activity Report — real, data-computed (no AI) breakdown of what every
 * user did (Create/Edit/Delete/View/Review/Approve), per-user and trended over
 * time at a chosen granularity. Powers the "User Activity Report" tab in the
 * AI Audit Intelligence panel on app/activity_log.php.
 *
 * Classification and the View-streak dedup rule come from
 * core/activity_log_helpers.php — the SAME functions app/activity_log.php uses
 * for its own filter/stat cards, so these charts can never disagree with the
 * rest of the page the way the old "Viewed" stat card once did.
 */
require_once __DIR__ . '/../roots.php';
require_once ROOT_DIR . '/core/activity_log_helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin access required']); exit; }

$date_from   = $_GET['date_from'] ?? date('Y-m-01');
$date_to     = $_GET['date_to']   ?? date('Y-m-d');
$user_id     = (int)($_GET['user_id'] ?? 0) ?: null;
$granularity = $_GET['granularity'] ?? 'day';

$dateRx = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($dateRx, $date_from) || !preg_match($dateRx, $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format.']); exit;
}
if (!in_array($granularity, ['day', 'week', 'month', 'quarter', 'year'], true)) $granularity = 'day';

$fromDt = $date_from . ' 00:00:00';
$toDt   = $date_to   . ' 23:59:59';
// Named params throughout — buildActivityTypeSql() always emits named
// placeholders, and PDO cannot mix named + positional in one statement.
$bp     = [':scope_from' => $fromDt, ':scope_to' => $toDt];
$uidSql = '';
if ($user_id) { $bp[':scope_uid'] = $user_id; $uidSql = 'AND al.user_id = :scope_uid'; }

// Ordered so the CASE picks the first matching canonical type per row —
// same six verbs, same order as the page's own Activity Type dropdown.
$verbOrder = ['view', 'create', 'edit', 'delete', 'review', 'approve'];

try {
    // ── Shared dedup base: every row in scope, tagged with the previous action
    //    by the same user (for the View-streak collapse) and its canonical type.
    $typeCaseParts = []; $typeParams = [];
    foreach ($verbOrder as $v) {
        [$frag, $fp] = buildActivityTypeSql($v, "tc_{$v}_");
        $typeCaseParts[] = "WHEN $frag THEN '$v'";
        $typeParams = array_merge($typeParams, $fp);
    }
    $typeCaseSql = "CASE " . implode(' ', $typeCaseParts) . " ELSE 'other' END";
    $dedupExclusion = activityViewDedupExclusion();

    $baseSql = "
        SELECT user_id, action, description, created_at,
               $typeCaseSql AS vtype
        FROM (
            SELECT al.id, al.user_id, al.action, al.description, al.created_at,
                   LAG(al.action) OVER (PARTITION BY al.user_id ORDER BY al.created_at, al.id) AS prev_action
            FROM activity_logs al
            WHERE al.created_at BETWEEN :scope_from AND :scope_to $uidSql
        ) x
        WHERE $dedupExclusion
    ";

    // ── 1. Overall totals per canonical type (pie chart) ────────────────────────
    $totalsSql = "SELECT vtype, COUNT(*) AS c FROM ($baseSql) b GROUP BY vtype";
    $st = $pdo->prepare($totalsSql);
    $st->execute(array_merge($typeParams, $bp));
    $totals = array_fill_keys(array_merge($verbOrder, ['other']), 0);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $totals[$r['vtype']] = (int)$r['c']; }

    // ── 2. Per-user breakdown (bar chart + table) ───────────────────────────────
    $perUserSql = "
        SELECT COALESCE(u.username, CONCAT('User#', b.user_id)) AS username,
               SUM(b.vtype='view')     AS view_c,
               SUM(b.vtype='create')   AS create_c,
               SUM(b.vtype='edit')     AS edit_c,
               SUM(b.vtype='delete')   AS delete_c,
               SUM(b.vtype='review')   AS review_c,
               SUM(b.vtype='approve')  AS approve_c,
               COUNT(*)                AS total_c
        FROM ($baseSql) b
        LEFT JOIN users u ON u.user_id = b.user_id
        GROUP BY b.user_id, u.username
        ORDER BY total_c DESC
        LIMIT 25
    ";
    $st = $pdo->prepare($perUserSql);
    $st->execute(array_merge($typeParams, $bp));
    $perUser = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $perUser[] = [
            'username' => $r['username'],
            'view'     => (int)$r['view_c'],
            'create'   => (int)$r['create_c'],
            'edit'     => (int)$r['edit_c'],
            'delete'   => (int)$r['delete_c'],
            'review'   => (int)$r['review_c'],
            'approve'  => (int)$r['approve_c'],
            'total'    => (int)$r['total_c'],
        ];
    }

    // ── 3. Trend over time, bucketed by the chosen granularity (line chart) ─────
    $bucketExpr = [
        'day'     => "DATE(created_at)",
        'week'    => "DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY))", // Monday of that week
        'month'   => "DATE_FORMAT(created_at, '%Y-%m-01')",
        'quarter' => "CONCAT(YEAR(created_at), '-Q', QUARTER(created_at))",
        'year'    => "DATE_FORMAT(created_at, '%Y-01-01')",
    ][$granularity];

    $trendSql = "
        SELECT $bucketExpr AS bucket, COUNT(*) AS c
        FROM ($baseSql) b
        GROUP BY bucket
        ORDER BY bucket ASC
    ";
    $st = $pdo->prepare($trendSql);
    $st->execute(array_merge($typeParams, $bp));
    $trend = $st->fetchAll(PDO::FETCH_ASSOC);

    // Human-readable bucket labels (quarter bucket is already a label; the rest
    // are dates that need formatting per granularity).
    $labelFmt = [
        'day'     => fn($b) => date('d M', strtotime($b)),
        'week'    => fn($b) => 'wk of ' . date('d M', strtotime($b)),
        'month'   => fn($b) => date('M Y', strtotime($b)),
        'year'    => fn($b) => date('Y', strtotime($b)),
        'quarter' => fn($b) => $b,
    ][$granularity];
    $trendOut = array_map(fn($r) => ['label' => $labelFmt($r['bucket']), 'total' => (int)$r['c']], $trend);

    $who = null;
    if ($user_id) {
        $us = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $us->execute([$user_id]);
        $who = $us->fetchColumn() ?: "User #{$user_id}";
    }

    logActivity($pdo, $_SESSION['user_id'], 'View user activity report', "Admin viewed the User Activity Report ({$date_from} to {$date_to}, {$granularity})");

    echo json_encode([
        'success'     => true,
        'totals'      => $totals,
        'per_user'    => $perUser,
        'trend'       => $trendOut,
        'granularity' => $granularity,
        'from'        => $date_from,
        'to'          => $date_to,
        'user_scope'  => $who,
    ]);

} catch (Throwable $e) {
    error_log('user_activity_report.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Report generation failed. Check the server error log.']);
}
