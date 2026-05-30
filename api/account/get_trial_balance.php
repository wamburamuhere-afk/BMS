<?php
/**
 * Trial Balance — Data API (Phase 1.1)
 *
 * Reads from the canonical ledger (journal_entries + journal_entry_items)
 * for the period ending at as_of_date. Returns every active account with
 * its cumulative Dr and Cr totals, grouped by statement (BS/IS) and
 * category, with grand totals that prove the double-entry rule
 * (Sum Dr == Sum Cr).
 *
 * STRUCTURE (per Corporate Finance Institute + IFRS for SMEs)
 * ────────────────────────────────────────────────────────────
 * Account ordering: Balance Sheet first (asset → liability → equity),
 *                   then Income Statement (revenue → expense).
 * Cumulative basis: each account's totals include opening_balance plus
 *                   every posted journal_entry_items row UP TO and
 *                   INCLUDING as_of_date.
 * opening_balance handling: allocated per account_types.normal_side
 *                   - debit-natural accounts (asset, expense): adds to Dr
 *                   - credit-natural accounts (liability, equity, revenue): adds to Cr
 *
 * IFRS for SMEs §2.34 requires comparative period — we return the same
 * structure for "comparative_date" (= as_of_date − 1 year).
 *
 * PROJECT FILTER + USER SCOPE
 * ───────────────────────────
 * Same shape as IS/BS/CF:
 *   - Admin           → sees everything
 *   - Non-admin       → assigned projects OR untagged (via
 *                       scopeFilterSqlNullable('project'))
 *   - Specific project_id requires userCan('project', id) → else 403
 *
 * IMPORTANT — DESIGN NOTE
 * ───────────────────────
 * Trial Balance is NOT a formal financial statement; the UI partial labels
 * it "Internal Working Document" per CFI guidance. It exists to prove the
 * ledger is internally consistent before producing BS/IS.
 *
 * Returns JSON shape:
 *   { success, data: {
 *       meta: { as_of_date, comparative_date, project_id,
 *               project_filter_active, is_admin, scoped_project_ids },
 *       accounts: [
 *         { account_id, account_code, account_name, statement, category,
 *           normal_side,
 *           current:     { total_debit, total_credit, net_balance },
 *           comparative: { total_debit, total_credit, net_balance } },
 *         ...
 *       ],
 *       subtotals: {
 *         BS: { asset:    { dr, cr }, liability: { dr, cr }, equity:  { dr, cr } },
 *         IS: { revenue:  { dr, cr }, expense:   { dr, cr } }
 *       },
 *       totals: { total_debit, total_credit, balanced, balance_difference,
 *                 comparative: { total_debit, total_credit, balanced } }
 *   } }
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

// Guarded: consumed as an internal report partial after headers are sent.
if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Guard: account_types classification columns (migration 2026_05_27) must exist
// on this server, else every at.category query throws. Return a clear message.
try { $fc_ready = $pdo->query("SHOW COLUMNS FROM account_types LIKE 'category'")->fetch() !== false; }
catch (Throwable $e) { $fc_ready = false; }
if (!$fc_ready) {
    echo json_encode(['success' => false, 'message' =>
        'Report unavailable: account-type classification not installed on this server. '
      . 'Run migration 2026_05_27_account_types_classification.php (see /migrations/status.php).']);
    exit;
}

// ── Parameters ───────────────────────────────────────────────────────────
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of_date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'as_of_date must be YYYY-MM-DD']);
    exit;
}

$comparative_date = date('Y-m-d', strtotime("$as_of_date -1 year"));

// ── Scope resolution ────────────────────────────────────────────────────
$is_admin = isAdmin();
$user_project_ids = [];
if (!$is_admin) {
    $user_project_ids = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied: this project is not in your assigned scope.',
    ]);
    exit;
}

try {
    global $pdo;

    /**
     * Build the JOIN + WHERE clauses for a journal_entries query, returning
     * the SQL fragment + the parameters to bind. Same project-scope model as
     * Income Statement / Balance Sheet.
     */
    $buildScopeClause = function () use ($project_id): array {
        if ($project_id !== null) {
            return ['sql' => " AND je.project_id = ?", 'params' => [$project_id]];
        }
        return ['sql' => scopeFilterSqlNullable('project', 'je'), 'params' => []];
    };

    /**
     * For a given cut-off date, fetch every active account with its
     * cumulative Dr + Cr (opening_balance allocated by normal_side, plus
     * every posted journal_entry_items row with je.entry_date <= cutoff).
     */
    $fetchTrialBalanceAsOf = function (string $cutoff) use ($pdo, $buildScopeClause): array {
        $scope = $buildScopeClause();

        // Cumulative Dr/Cr from journal entries (posted, on/before cutoff,
        // matching scope).
        $sql = "
            SELECT
                a.account_id,
                a.account_code,
                a.account_name,
                a.opening_balance,
                COALESCE(at.statement, '?')                  AS statement,
                COALESCE(at.category,  '?')                  AS category,
                COALESCE(at.normal_side, 'debit')            AS normal_side,
                COALESCE(SUM(CASE WHEN jei.type = 'debit'  THEN jei.amount ELSE 0 END), 0) AS posted_debit,
                COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) AS posted_credit
              FROM accounts a
         LEFT JOIN account_types at ON a.account_type_id = at.type_id
         LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
         LEFT JOIN journal_entries     je  ON je.entry_id    = jei.entry_id
                                            AND je.status    = 'posted'
                                            AND je.entry_date <= ?
                                            {$scope['sql']}
             WHERE a.status = 'active'
          GROUP BY a.account_id, a.account_code, a.account_name,
                   a.opening_balance, at.statement, at.category, at.normal_side
          ORDER BY
              -- BS before IS
              CASE COALESCE(at.statement, 'BS')
                  WHEN 'BS' THEN 1
                  WHEN 'IS' THEN 2
                  ELSE 3
              END,
              -- within each statement: asset, liability, equity, revenue, expense, cogs
              CASE COALESCE(at.category, 'zz')
                  WHEN 'asset'     THEN 1
                  WHEN 'liability' THEN 2
                  WHEN 'equity'    THEN 3
                  WHEN 'revenue'   THEN 4
                  WHEN 'cogs'      THEN 5
                  WHEN 'expense'   THEN 6
                  ELSE 7
              END,
              a.account_code,
              a.account_id
        ";

        // First param is the cutoff date; rest are scope params (if any).
        $params = array_merge([$cutoff], $scope['params']);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Allocate opening_balance by normal_side and compute net_balance.
        // Skip rows that are completely zero — no posted activity AND no
        // opening_balance — to keep the page compact.
        $accounts  = [];
        $tot_dr    = 0.0;
        $tot_cr    = 0.0;
        $subtotals = [
            'BS' => ['asset' => ['dr' => 0, 'cr' => 0], 'liability' => ['dr' => 0, 'cr' => 0], 'equity' => ['dr' => 0, 'cr' => 0]],
            'IS' => ['revenue' => ['dr' => 0, 'cr' => 0], 'expense' => ['dr' => 0, 'cr' => 0], 'cogs' => ['dr' => 0, 'cr' => 0]],
        ];

        foreach ($rows as $r) {
            $opening = (float)$r['opening_balance'];
            $posted_dr = (float)$r['posted_debit'];
            $posted_cr = (float)$r['posted_credit'];
            $normal_side = $r['normal_side'];

            // Opening balance allocation
            $opening_dr = ($normal_side === 'debit')  ? $opening : 0.0;
            $opening_cr = ($normal_side === 'credit') ? $opening : 0.0;

            $total_dr = $opening_dr + $posted_dr;
            $total_cr = $opening_cr + $posted_cr;
            $net = ($normal_side === 'debit') ? ($total_dr - $total_cr) : ($total_cr - $total_dr);

            // Hide truly empty rows (no opening, no posted activity)
            if (abs($total_dr) < 0.001 && abs($total_cr) < 0.001) {
                continue;
            }

            $accounts[] = [
                'account_id'   => (int)$r['account_id'],
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'statement'    => $r['statement'],
                'category'     => $r['category'],
                'normal_side'  => $normal_side,
                'total_debit'  => $total_dr,
                'total_credit' => $total_cr,
                'net_balance'  => $net,
            ];

            $tot_dr += $total_dr;
            $tot_cr += $total_cr;

            // Subtotals
            $stmt_key = $r['statement'];   // 'BS' / 'IS'
            $cat      = $r['category'];    // asset/liability/equity/revenue/cogs/expense
            if (isset($subtotals[$stmt_key][$cat])) {
                $subtotals[$stmt_key][$cat]['dr'] += $total_dr;
                $subtotals[$stmt_key][$cat]['cr'] += $total_cr;
            }
        }

        return [
            'accounts'      => $accounts,
            'subtotals'     => $subtotals,
            'total_debit'   => $tot_dr,
            'total_credit'  => $tot_cr,
        ];
    };

    // ── Run for current and comparative ──────────────────────────────────
    $cur = $fetchTrialBalanceAsOf($as_of_date);
    $cmp = $fetchTrialBalanceAsOf($comparative_date);

    // Build current-period accounts list keyed by id, then merge comparative
    // values into the same rows so the UI gets the side-by-side structure.
    // Accounts that appear ONLY in the comparative are appended after.
    $merged = [];
    foreach ($cur['accounts'] as $a) {
        $merged[$a['account_id']] = [
            'account_id'   => $a['account_id'],
            'account_code' => $a['account_code'],
            'account_name' => $a['account_name'],
            'statement'    => $a['statement'],
            'category'     => $a['category'],
            'normal_side'  => $a['normal_side'],
            'current'      => [
                'total_debit'  => $a['total_debit'],
                'total_credit' => $a['total_credit'],
                'net_balance'  => $a['net_balance'],
            ],
            'comparative'  => ['total_debit' => 0.0, 'total_credit' => 0.0, 'net_balance' => 0.0],
        ];
    }
    foreach ($cmp['accounts'] as $a) {
        if (isset($merged[$a['account_id']])) {
            $merged[$a['account_id']]['comparative'] = [
                'total_debit'  => $a['total_debit'],
                'total_credit' => $a['total_credit'],
                'net_balance'  => $a['net_balance'],
            ];
        } else {
            // Account had activity in comparative period but is zero now
            $merged[$a['account_id']] = [
                'account_id'   => $a['account_id'],
                'account_code' => $a['account_code'],
                'account_name' => $a['account_name'],
                'statement'    => $a['statement'],
                'category'     => $a['category'],
                'normal_side'  => $a['normal_side'],
                'current'      => ['total_debit' => 0.0, 'total_credit' => 0.0, 'net_balance' => 0.0],
                'comparative'  => [
                    'total_debit'  => $a['total_debit'],
                    'total_credit' => $a['total_credit'],
                    'net_balance'  => $a['net_balance'],
                ],
            ];
        }
    }
    $accounts_list = array_values($merged);

    $balanced_cur = abs($cur['total_debit'] - $cur['total_credit']) < 0.5;
    $balanced_cmp = abs($cmp['total_debit'] - $cmp['total_credit']) < 0.5;

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'as_of_date'           => $as_of_date,
                'comparative_date'     => $comparative_date,
                'project_id'           => $project_id,
                'project_filter_active'=> $project_id !== null,
                'is_admin'             => $is_admin,
                'scoped_project_ids'   => $is_admin ? null : $user_project_ids,
            ],
            'accounts'  => $accounts_list,
            'subtotals' => $cur['subtotals'],
            'totals' => [
                'total_debit'        => $cur['total_debit'],
                'total_credit'       => $cur['total_credit'],
                'balanced'           => $balanced_cur,
                'balance_difference' => $cur['total_debit'] - $cur['total_credit'],
                'comparative' => [
                    'total_debit'        => $cmp['total_debit'],
                    'total_credit'       => $cmp['total_credit'],
                    'balanced'           => $balanced_cmp,
                    'balance_difference' => $cmp['total_debit'] - $cmp['total_credit'],
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Trial Balance API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
