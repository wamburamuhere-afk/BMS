<?php
/**
 * Income Statement (Profit & Loss) — Data API (single-source, GL-derived)
 *
 * money.md F3: every figure is DERIVED from the canonical ledger via
 * core/financial_reports.php (glProfitLoss) — posted journal_entries only. This
 * replaces the previous document-hybrid (invoices + pos_sales + expenses + payroll +
 * IPC + manual journals) so the P&L now TIES to the Balance Sheet: net profit for
 * the period flows into the GL's accumulated earnings, which the Balance Sheet's
 * equity reads. The flip is only correct because every revenue AND cost event now
 * posts to the GL on an accrual basis (money.md IN-3/5/6, OUT-1/2/3/4, OUT-7,
 * OUT-12/13, OUT-15).
 *
 * Accrual basis: revenue is recognised when an invoice/IPC is approved or a POS sale
 * completes; costs when an expense/voucher/payroll/sub-contractor invoice is approved
 * (Dr Expense / Cr Accrued or AP). Only posted journal entries count.
 *
 * Sections (mapped from account_types.category): revenue · cogs · expense ·
 * finance_costs. (other_income is folded into revenue — the GL has no separate
 * category; the section is kept in the contract but empty.) Each line is a GL
 * account, drillable to the general ledger.
 *
 * Project filter + user scope (security.md §23): a specific project filters
 * je.project_id = N; otherwise non-admins are scoped to assigned-projects-OR-untagged
 * via scopeFilterSqlNullable().
 *
 * Returns the same JSON shape the frontend partial expects (meta, sections, totals).
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/financial_classification.php';
require_once __DIR__ . '/../../core/financial_reports.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!fc_classification_ready($pdo)) {
    echo json_encode(['success' => false, 'message' =>
        'Income Statement is not available: the account-type classification has not been installed on this server. '
      . 'An administrator should run the pending migration 2026_05_27_account_types_classification.php (see /migrations/status.php).']);
    exit;
}

// ── Date + project parameters ─────────────────────────────────────────────
$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

// ── Scope resolution ──────────────────────────────────────────────────────
$is_admin = isAdmin();
$user_project_ids = [];
if (!$is_admin) {
    $user_project_ids = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}

// Previous period — same length, immediately prior.
try {
    $ts_start = strtotime($start_date);
    $ts_end   = strtotime($end_date);
    $span_days = (int) round(($ts_end - $ts_start) / 86400);
    $prev_end_date   = date('Y-m-d', strtotime($start_date . ' -1 day'));
    $prev_start_date = date('Y-m-d', strtotime($prev_end_date . ' -' . max($span_days, 0) . ' days'));
} catch (Throwable $e) {
    $prev_start_date = date('Y-m-01', strtotime($start_date . ' -1 month'));
    $prev_end_date   = date('Y-m-t', strtotime($end_date . ' -1 month'));
}

try {
    global $pdo;

    // Specific project binds je.project_id = N; otherwise the canonical
    // "assigned projects OR untagged" filter ('' for admins).
    $scopeSql = $project_id === null ? scopeFilterSqlNullable('project', 'je') : '';

    $cur = glProfitLoss($pdo, $start_date, $end_date, $project_id, $scopeSql);
    $prv = glProfitLoss($pdo, $prev_start_date, $prev_end_date, $project_id, $scopeSql);

    // Index previous-period account amounts by account id for the comparative column.
    $prevBy = [];
    foreach (['revenue', 'other_income', 'cogs', 'expense', 'finance_cost'] as $bucket) {
        foreach ($prv[$bucket] as $l) $prevBy[(int)$l['account_id']] = (float)$l['amount'];
    }

    // Build a section's lines (current + comparative) from a glProfitLoss bucket.
    $mkSection = function (array $curLines, float $totalCur, float $totalPrv) use ($prevBy): array {
        $lines = [];
        foreach ($curLines as $l) {
            $lines[] = [
                'account_code' => $l['account_code'],
                'account_name' => $l['account_name'],
                'current'      => (float)$l['amount'],
                'previous'     => $prevBy[(int)$l['account_id']] ?? 0.0,
                'drill'        => ['source' => 'journal', 'account_id' => (int)$l['account_id']],
            ];
        }
        return ['lines' => $lines, 'total_current' => round($totalCur, 2), 'total_previous' => round($totalPrv, 2)];
    };

    $sec_revenue       = $mkSection($cur['revenue'],      $cur['total_revenue'],      $prv['total_revenue']);
    $sec_cogs          = $mkSection($cur['cogs'],         $cur['total_cogs'],         $prv['total_cogs']);
    $sec_expense       = $mkSection($cur['expense'],      $cur['total_expense'],      $prv['total_expense']);
    $sec_finance       = $mkSection($cur['finance_cost'], $cur['total_finance_cost'], $prv['total_finance_cost']);
    // Other Income / Gains — now fed from the real GL bucket (was hardcoded empty).
    $sec_other_income  = $mkSection($cur['other_income'], $cur['total_other_income'], $prv['total_other_income']);

    // ── Totals + margins (same contract as before) ───────────────────────
    $tr  = round($cur['total_revenue'], 2);
    $tc  = round($cur['total_cogs'], 2);
    $te  = round($cur['total_expense'], 2);
    $fin = round($cur['total_finance_cost'], 2);
    $other_income = round($cur['total_other_income'], 2);
    $income_tax   = 0.0;                       // tax, if posted, sits within expense accounts

    $gp = round($tr - $tc, 2);                 // gross profit
    $operating_profit  = round($gp - $te, 2);  // EBIT
    $profit_before_tax = round($operating_profit + $other_income - $fin, 2);
    $np = round($profit_before_tax - $income_tax, 2);

    $gpm = $tr > 0.001 ? round(($gp / $tr) * 100, 1) : 0.0;
    $opm = $tr > 0.001 ? round(($operating_profit / $tr) * 100, 1) : 0.0;
    $npm = $tr > 0.001 ? round(($np / $tr) * 100, 1) : 0.0;

    // Previous period
    $tr_p  = round($prv['total_revenue'], 2);
    $tc_p  = round($prv['total_cogs'], 2);
    $te_p  = round($prv['total_expense'], 2);
    $fin_p = round($prv['total_finance_cost'], 2);
    $oi_p  = round($prv['total_other_income'], 2);
    $gp_p  = round($tr_p - $tc_p, 2);
    $op_p  = round($gp_p - $te_p, 2);
    $pbt_p = round($op_p + $oi_p - $fin_p, 2);
    $np_p  = $pbt_p;

    // ── Warnings (GL-relevant) ───────────────────────────────────────────
    $unclassified = fc_unclassified_types($pdo);
    $draftStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE entry_date BETWEEN ? AND ? AND status != 'posted'");
    $draftStmt->execute([$start_date, $end_date]);
    $draft_count = (int) $draftStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'current_start'         => $start_date,
                'current_end'           => $end_date,
                'prev_start'            => $prev_start_date,
                'prev_end'              => $prev_end_date,
                'project_id'            => $project_id,
                'project_filter_active' => $project_id !== null,
                'is_admin'              => $is_admin,
                'scoped_project_ids'    => $is_admin ? null : $user_project_ids,
                'source'                => 'general_ledger',
                'unclassified_count'    => count($unclassified),
                'unclassified_types'    => $unclassified,
                'draft_count'           => $draft_count,
                'unpaid_payroll_count'  => 0,
            ],
            'sections' => [
                'revenue'       => $sec_revenue,
                'cogs'          => $sec_cogs,
                'expense'       => $sec_expense,
                'other_income'  => $sec_other_income,
                'finance_costs' => $sec_finance,
            ],
            'totals' => [
                'total_revenue'        => $tr,
                'sales_returns'        => 0.0,     // netted into revenue via the contra account
                'total_cogs'           => $tc,
                'gross_profit'         => $gp,
                'gross_margin_pct'     => $gpm,
                'total_expenses'       => $te,
                'operating_profit'     => $operating_profit,
                'operating_margin_pct' => $opm,
                'other_income'         => $other_income,
                'finance_costs'        => $fin,
                'income_tax'           => $income_tax,
                'profit_before_tax'    => $profit_before_tax,
                'net_profit'           => $np,
                'net_margin_pct'       => $npm,
                'previous' => [
                    'total_revenue'     => $tr_p,
                    'sales_returns'     => 0.0,
                    'total_cogs'        => $tc_p,
                    'gross_profit'      => $gp_p,
                    'total_expenses'    => $te_p,
                    'operating_profit'  => $op_p,
                    'other_income'      => $oi_p,
                    'finance_costs'     => $fin_p,
                    'income_tax'        => 0.0,
                    'profit_before_tax' => $pbt_p,
                    'net_profit'        => $np_p,
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log("Income Statement API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
