<?php
/**
 * Balance Sheet — Data API (single-source, GL-derived)
 *
 * money.md F3: every figure is DERIVED from the canonical ledger via
 * core/financial_reports.php (glBalanceSheet) — posted journal_entries only. This
 * replaces the previous hybrid that read accounts.current_balance + raw documents
 * and FORCED balance with a retained-earnings plug (so "balanced" was always true
 * by construction). Now `balanced` is a REAL check: Assets = Liabilities + Equity,
 * where Equity includes the GL's accumulated earnings (Revenue − Expenses to date).
 *
 * Point-in-time at `as_of_date` with a prior-year comparative column. Layout +
 * JSON contract are unchanged so the existing frontend partial keeps working:
 *   ASSETS  → Current (cash, receivables, inventory, …) / Non-current (PP&E: 1-3xxx)
 *   LIABILITIES → Current / Non-current
 *   EQUITY  → capital + reserves + Retained Earnings (accumulated profit, REAL)
 *   STATEMENT OF CHANGES IN EQUITY → opening b/f + profit for the year + movements
 *
 * Current vs non-current split: the account_types.liquidity column is unreliable on
 * this chart (everything is 'current', incl. Fixed Assets), so non-current assets
 * are identified by the chart's code convention — asset codes 1-3xxx are PP&E.
 *
 * Project filter + user scope (security.md §23): a specific project filters
 * je.project_id = N; otherwise non-admins are scoped to assigned-projects-OR-untagged
 * via scopeFilterSqlNullable(). NOTE: a Balance Sheet is an entity-level statement —
 * a project-scoped view shows only that slice and may legitimately NOT balance
 * (company-wide cash sits on untagged entries); the honest `balanced` flag reflects that.
 *
 * Returns JSON shape:
 *   { success, data: { meta, sections{current_assets, non_current_assets,
 *     current_liabilities, non_current_liabilities, equity, changes_in_equity},
 *     totals{total_assets, total_liabilities, total_equity, liab_plus_equity,
 *     balanced, balance_difference, comparative} } }
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/financial_reports.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Guard: account_types classification (migration 2026_05_27) must exist.
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

$comparative_date    = date('Y-m-d', strtotime("$as_of_date -1 year"));
$current_year_start  = date('Y-01-01', strtotime($as_of_date));
$year_open_cutoff    = date('Y-m-d', strtotime($current_year_start . ' -1 day'));

// ── Scope resolution (security.md §23) ───────────────────────────────────
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

try {
    global $pdo;

    // A specific project binds je.project_id = N; otherwise apply the canonical
    // "assigned projects OR untagged" filter for non-admins ('' for admins).
    $scopeSql = $project_id === null ? scopeFilterSqlNullable('project', 'je') : '';

    /** PP&E / non-current asset by the chart convention: asset code 1-3xxx. */
    $isNonCurrentAsset = function (array $line): bool {
        return (bool)preg_match('/^1-3/', (string)($line['account_code'] ?? ''));
    };

    // ── Pull GL balance sheets: current, comparative, and year-open (for SOCE) ──
    $bs      = glBalanceSheet($pdo, $as_of_date,       $project_id, false, $scopeSql);
    $bsCmp   = glBalanceSheet($pdo, $comparative_date, $project_id, false, $scopeSql);
    $bsOpen  = glBalanceSheet($pdo, $year_open_cutoff, $project_id, false, $scopeSql);

    // Comparative lookup by stable key (synthetic Retained Earnings line has a null id).
    $keyOf = function (array $l): string {
        return isset($l['account_id']) && $l['account_id'] !== null ? 'id:' . $l['account_id'] : 'nm:' . $l['account_name'];
    };
    $cmpMap = [];
    foreach (array_merge($bsCmp['assets'], $bsCmp['liabilities'], $bsCmp['equity']) as $l) {
        $cmpMap[$keyOf($l)] = (float)$l['amount'];
    }

    // Build display lines for a set of engine lines, optionally filtered.
    $mkLines = function (array $lines, ?callable $filter = null) use ($cmpMap, $keyOf): array {
        $out = [];
        foreach ($lines as $l) {
            if ($filter && !$filter($l)) continue;
            if (abs((float)$l['amount']) < 0.005) continue;
            $out[] = [
                'name'               => $l['account_name'],
                'account_code'       => $l['account_code'],
                'amount'             => (float)$l['amount'],
                'comparative_amount' => $cmpMap[$keyOf($l)] ?? 0.0,
            ];
        }
        return $out;
    };
    // Section totals (summed from ALL engine lines in the class, incl. comparative-only).
    $sumWhere = function (array $lines, callable $filter): float {
        $t = 0.0; foreach ($lines as $l) { if ($filter($l)) $t += (float)$l['amount']; } return $t;
    };
    $isCurrentAsset = function (array $l) use ($isNonCurrentAsset): bool { return !$isNonCurrentAsset($l); };
    $always = function (array $l): bool { return true; };

    // ── ASSETS ──────────────────────────────────────────────────────────
    $current_assets_lines     = $mkLines($bs['assets'], $isCurrentAsset);
    $non_current_assets_lines = $mkLines($bs['assets'], $isNonCurrentAsset);
    $cur_current_assets       = $sumWhere($bs['assets'],    $isCurrentAsset);
    $cur_non_current_assets   = $sumWhere($bs['assets'],    $isNonCurrentAsset);
    $cmp_current_assets       = $sumWhere($bsCmp['assets'], $isCurrentAsset);
    $cmp_non_current_assets   = $sumWhere($bsCmp['assets'], $isNonCurrentAsset);

    // ── LIABILITIES (all current on this chart; long-term tracked separately) ──
    $current_liabilities_lines     = $mkLines($bs['liabilities']);
    $non_current_liabilities_lines = [];
    $cur_current_liabilities  = $sumWhere($bs['liabilities'],    $always);
    $cmp_current_liabilities  = $sumWhere($bsCmp['liabilities'], $always);

    // ── EQUITY (includes the REAL Retained Earnings line from the engine) ──
    $equity_lines = $mkLines($bs['equity']);

    // ── Totals (real balance check from the engine) ─────────────────────
    $cur_total_assets      = round($bs['total_assets'], 2);
    $cur_total_liabilities = round($bs['total_liabilities'], 2);
    $cur_total_equity      = round($bs['total_equity'], 2);
    $cur_liab_plus_equity  = round($cur_total_liabilities + $cur_total_equity, 2);

    // ── Statement of Changes in Equity (GL-derived) ─────────────────────
    $profit_cy   = glProfitLoss($pdo, $current_year_start, $as_of_date, $project_id, $scopeSql)['net_profit'];
    $opening_eq  = round($bsOpen['total_equity'], 2);
    $other_moves = round($cur_total_equity - $opening_eq - $profit_cy, 2);   // capital / drawings / dividends, net
    $changes_in_equity = [
        ['name' => 'Opening Equity (brought forward)', 'amount' => $opening_eq],
        ['name' => 'Add: Profit for the year',         'amount' => round($profit_cy, 2)],
    ];
    if (abs($other_moves) >= 0.005) {
        $changes_in_equity[] = ['name' => 'Add/(Less): Capital movements', 'amount' => $other_moves];
    }
    $changes_in_equity[] = ['name' => 'Closing Equity', 'amount' => $cur_total_equity, 'is_subtotal' => true];

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'as_of_date'            => $as_of_date,
                'comparative_date'      => $comparative_date,
                'current_year_start'    => $current_year_start,
                'project_id'            => $project_id,
                'project_filter_active' => $project_id !== null,
                'is_admin'              => $is_admin,
                'scoped_project_ids'    => $is_admin ? null : $user_project_ids,
                'source'                => 'general_ledger',
            ],
            'sections' => [
                'current_assets'          => ['lines' => $current_assets_lines,         'total' => round($cur_current_assets, 2),     'comparative_total' => round($cmp_current_assets, 2)],
                'non_current_assets'      => ['lines' => $non_current_assets_lines,     'total' => round($cur_non_current_assets, 2), 'comparative_total' => round($cmp_non_current_assets, 2)],
                'current_liabilities'     => ['lines' => $current_liabilities_lines,    'total' => round($cur_current_liabilities, 2),'comparative_total' => round($cmp_current_liabilities, 2)],
                'non_current_liabilities' => ['lines' => $non_current_liabilities_lines,'total' => 0.0,                                'comparative_total' => 0.0],
                'equity'                  => ['lines' => $equity_lines,                 'total' => $cur_total_equity,                  'comparative_total' => round($bsCmp['total_equity'], 2)],
                'changes_in_equity'       => ['lines' => $changes_in_equity,            'opening' => $opening_eq, 'closing' => $cur_total_equity],
            ],
            'totals' => [
                'total_assets'       => $cur_total_assets,
                'total_liabilities'  => $cur_total_liabilities,
                'total_equity'       => $cur_total_equity,
                'liab_plus_equity'   => $cur_liab_plus_equity,
                'balanced'           => $bs['balanced'],
                'balance_difference' => round($bs['difference'], 2),
                'comparative' => [
                    'total_assets'      => round($bsCmp['total_assets'], 2),
                    'total_liabilities' => round($bsCmp['total_liabilities'], 2),
                    'total_equity'      => round($bsCmp['total_equity'], 2),
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Balance Sheet API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
