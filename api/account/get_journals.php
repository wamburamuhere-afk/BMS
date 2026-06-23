<?php
/**
 * api/account/get_journals.php
 * ----------------------------
 * Server-side DataTables source for the General Journal page
 * (app/constant/accounts/journals.php). Lists MANUAL journal entries
 * (entity_type IS NULL/'' — i.e. created via the compound-entry modal /
 * save_journal / add_compound_journal), with their Dr/Cr lines, totals,
 * and the page's summary stats.
 *
 * Project scope (security.md §23): journal_entries has project_id, so a
 * non-admin only sees entries for their assigned projects (or untagged) via
 * scopeFilterSqlNullable('project', 'je').
 *
 * Response: { draw, recordsTotal, recordsFiltered, data:[...], stats:{...} }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if (!canView('journals')) { http_response_code(403); echo json_encode(['error' => 'Permission denied']); exit; }

try {
    $draw   = (int)($_GET['draw'] ?? 1);
    $start  = max(0, (int)($_GET['start'] ?? 0));
    $length = (int)($_GET['length'] ?? 25);
    if ($length <= 0 || $length > 200) $length = 25;

    $accountId = (isset($_GET['account_id']) && $_GET['account_id'] !== '') ? (int)$_GET['account_id'] : 0;
    $status    = trim($_GET['status'] ?? '');
    $dateFrom  = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '')) ? $_GET['date_from'] : '';
    $dateTo    = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'] ?? '')) ? $_GET['date_to'] : '';
    $search    = trim($_GET['search']['value'] ?? '');

    // Non-admin project scope (inline-int fragment; '' for admins).
    $scope = scopeFilterSqlNullable('project', 'je');

    // Base: manual journal entries only.
    $base = "(je.entity_type IS NULL OR je.entity_type = '')";

    $where  = [$base];
    $params = [];
    if ($status !== '' && in_array($status, ['draft','posted','void','reversed'], true)) { $where[] = "je.status = ?"; $params[] = $status; }
    if ($dateFrom !== '') { $where[] = "je.entry_date >= ?"; $params[] = $dateFrom; }
    if ($dateTo   !== '') { $where[] = "je.entry_date <= ?"; $params[] = $dateTo; }
    if ($search   !== '') { $where[] = "(je.description LIKE ? OR je.reference_number LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($accountId > 0)   { $where[] = "EXISTS (SELECT 1 FROM journal_entry_items x WHERE x.entry_id = je.entry_id AND x.account_id = ?)"; $params[] = $accountId; }
    $whereSql = implode(' AND ', $where) . $scope;

    // recordsTotal — all manual journals in scope (base + scope only).
    $recordsTotal = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries je WHERE $base" . $scope)->fetchColumn();

    // recordsFiltered — with the active filters.
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries je WHERE $whereSql");
    $cntStmt->execute($params);
    $recordsFiltered = (int)$cntStmt->fetchColumn();

    // Page of entries (newest first).
    $sql = "SELECT je.entry_id, je.entry_date, je.description, je.notes, je.reference_number,
                   je.status, je.created_at, je.created_by,
                   COALESCE(u.username, CONCAT('User #', je.created_by)) AS created_by_name
              FROM journal_entries je
              LEFT JOIN users u ON u.user_id = je.created_by
             WHERE $whereSql
             ORDER BY je.entry_date DESC, je.entry_id DESC
             LIMIT $start, $length";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Items for the page's entries (single query — no N+1).
    $data = [];
    if ($rows) {
        $ids = array_column($rows, 'entry_id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $itStmt = $pdo->prepare("SELECT jei.entry_id, jei.type, jei.amount, a.account_name
                                   FROM journal_entry_items jei
                                   LEFT JOIN accounts a ON a.account_id = jei.account_id
                                  WHERE jei.entry_id IN ($ph)");
        $itStmt->execute($ids);
        $byEntry = [];
        foreach ($itStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $byEntry[$it['entry_id']][] = $it;
        }
        foreach ($rows as $r) {
            $items = $byEntry[$r['entry_id']] ?? [];
            $td = 0.0; $tc = 0.0; $list = [];
            foreach ($items as $it) {
                $amt = (float)$it['amount'];
                if ($it['type'] === 'debit') $td += $amt; else $tc += $amt;
                $list[] = ['type' => $it['type'], 'account_name' => $it['account_name'] ?? '—'];
            }
            $data[] = [
                'entry_id'         => (int)$r['entry_id'],
                'entry_date'       => $r['entry_date'],
                'description'      => $r['description'],
                'notes'            => $r['notes'],
                'item_count'       => count($items),
                'items'            => $list,
                'total_debits'     => round($td, 2),
                'total_credits'    => round($tc, 2),
                'reference_number' => $r['reference_number'],
                'status'           => $r['status'],
                'created_by_name'  => $r['created_by_name'],
                'created_at'       => $r['created_at'],
            ];
        }
    }

    // Summary stats over the FILTERED set (totals), plus this month + count.
    $statStmt = $pdo->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN jei.type='debit'  THEN jei.amount ELSE 0 END),0) AS total_debits,
          COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END),0) AS total_credits,
          COALESCE(SUM(CASE WHEN jei.type='debit' AND je.entry_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
                            THEN jei.amount ELSE 0 END),0) AS month_debits
          FROM journal_entries je
          LEFT JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
         WHERE $whereSql");
    $statStmt->execute($params);
    $s = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $data,
        'stats'           => [
            'totalDebits'  => round((float)($s['total_debits']  ?? 0), 2),
            'totalCredits' => round((float)($s['total_credits'] ?? 0), 2),
            'monthDebits'  => round((float)($s['month_debits']  ?? 0), 2),
            'entryCount'   => $recordsFiltered,
        ],
    ]);
} catch (Throwable $e) {
    error_log('get_journals error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load journals', 'data' => [], 'recordsTotal' => 0, 'recordsFiltered' => 0,
                      'stats' => ['totalDebits' => 0, 'totalCredits' => 0, 'monthDebits' => 0, 'entryCount' => 0]]);
}
