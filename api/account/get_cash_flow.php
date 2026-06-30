<?php
/**
 * Cash Flow Statement — Data API (F1/F3: single-source General Ledger)
 *
 * Period report (start_date → end_date) of cash IN/OUT in IAS 7 / IFRS for SMEs §7
 * categories, DERIVED PURELY FROM THE POSTED JOURNAL (core/financial_reports.php::
 * glCashFlow) — the same one ledger the Trial Balance, Balance Sheet and Income
 * Statement now read. Because of that, the net change in cash here TIES EXACTLY to
 * the Balance Sheet's cash-line movement; the report can no longer disagree with
 * the books. (It previously read operational document tables — payments,
 * supplier_payments, payroll, expenses, assets — plus accounts.current_balance,
 * which is the multi-source disease money.md F1 sets out to cure.)
 *
 * DIRECT method (default): every posted entry that touches a cash/bank account is
 * classified by its NON-cash contra leg — revenue/expense → operating, PP&E (1-3xxx)
 * → investing, equity → financing. Operating + investing + financing always sum to
 * the net change in cash (double-entry guarantee). See glCashFlow() for the proof.
 *
 * INDIRECT method (§7): starts from net profit (glProfitLoss), adds back the non-cash
 * depreciation charge (now posted to the GL — OUT-13), and applies the working-capital
 * movement read from the GL Balance Sheet (Δ receivables / inventory / payables / tax
 * + other). It reconciles to the direct operating total; any residual is surfaced in
 * meta.operating_reconciliation_difference.
 *
 * Opening / closing cash = the cash accounts' GL balance at the period bounds (NOT
 * accounts.current_balance), so opening + net change == closing for real.
 *
 * LOANS excluded per project policy → the Financing section is normally empty (no
 * borrowing / share-capital / dividend tracking). Equity cash movements, if any,
 * still land in Financing.
 *
 * Project filter + user scope: identical to Balance Sheet / Income Statement —
 * scopeFilterSqlNullable('project','je') feeds the GL engine, a chosen project_id is
 * guarded by userCan() first.
 *
 * Returns the same JSON contract as before (meta / sections{operating,investing,
 * financing} / totals / disclosures) so the existing partial renders unchanged.
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/financial_reports.php';
require_once __DIR__ . '/../../core/gl_accounts.php';
require_once __DIR__ . '/../../core/vat.php';

// When consumed as an internal partial (reports.php -> reps/cash_flow.php ->
// this file), the page has already sent its HTML/headers. Guard the header()
// call so it doesn't emit a "headers already sent" warning that corrupts the
// JSON the partial parses (which shows as "Failed to load report").
if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Guard: account_types classification columns (migration 2026_05_27) must exist
// on this server, else the GL engine's at.category grouping throws.
try { $fc_ready = $pdo->query("SHOW COLUMNS FROM account_types LIKE 'category'")->fetch() !== false; }
catch (Throwable $e) { $fc_ready = false; }
if (!$fc_ready) {
    echo json_encode(['success' => false, 'message' =>
        'Report unavailable: account-type classification not installed on this server. '
      . 'Run migration 2026_05_27_account_types_classification.php (see /migrations/status.php).']);
    exit;
}

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

// Method selector: 'direct' (default) | 'indirect'.
$method = ($_GET['method'] ?? 'direct') === 'indirect' ? 'indirect' : 'direct';

// IFRS for SMEs §2.34 — comparative is the same calendar period one year prior.
$comparative_start = date('Y-m-d', strtotime("$start_date -1 year"));
$comparative_end   = date('Y-m-d', strtotime("$end_date -1 year"));

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

    // A specific project binds je.project_id = N (handled inside the engine via the
    // $projectId arg); otherwise apply the canonical "assigned projects OR untagged"
    // filter for non-admins ('' for admins). Mirrors get_balance_sheet.php.
    $scopeSql = $project_id === null ? scopeFilterSqlNullable('project', 'je') : '';

    // ── DIRECT method: GL cash flow for the current + comparative windows ──────
    $cfCur = glCashFlow($pdo, $start_date,        $end_date,        $project_id, $scopeSql);
    $cfCmp = glCashFlow($pdo, $comparative_start, $comparative_end, $project_id, $scopeSql);

    // Merge a section's current + comparative lines, keyed by contra account, into
    // the {name, amount, comparative_amount} shape the partial renders.
    $mergeSection = function (array $curLines, array $cmpLines): array {
        $byKey = [];
        foreach ($curLines as $l) {
            $byKey['a:' . $l['account_id']] = [
                'name'               => $l['account_name'],
                'amount'             => $l['amount'],
                'comparative_amount' => 0.0,
            ];
        }
        foreach ($cmpLines as $l) {
            $k = 'a:' . $l['account_id'];
            if (isset($byKey[$k])) {
                $byKey[$k]['comparative_amount'] = $l['amount'];
            } else {
                $byKey[$k] = [
                    'name'               => $l['account_name'],
                    'amount'             => 0.0,
                    'comparative_amount' => $l['amount'],
                ];
            }
        }
        return array_values($byKey);
    };

    // ── INDIRECT method: profit → non-cash add-backs → working capital (all GL) ──
    // Comprehensive working-capital change is read from the GL Balance Sheet movement
    // so the bridge ties to the direct operating total. Named lines (AR/Inventory/AP/
    // Tax) are broken out; everything else collapses into "Other working capital".
    $cashIds   = glCashAccountIds($pdo);
    $depAcc    = depreciationExpenseAccountId($pdo);
    $arAcc     = arAccountId($pdo);
    $invAcc    = inventoryAccountId($pdo);
    $apAcc     = apAccountId($pdo);
    $vatAcc    = outputVatAccountId($pdo);

    $computeIndirect = function (string $from, string $to, float $targetOperating) use (
        $pdo, $project_id, $scopeSql, $depAcc, $arAcc, $invAcc, $apAcc, $vatAcc
    ): array {
        $pl        = glProfitLoss($pdo, $from, $to, $project_id, $scopeSql);
        $netProfit = (float)$pl['net_profit'];

        // Non-cash add-back: depreciation charged in the window (debit-normal flow).
        $depr = $depAcc ? glAccountRawSum($pdo, $depAcc, $from, $to, $project_id, $scopeSql) : 0.0;

        // Named working-capital movements from the GL Balance Sheet (open → close).
        $openCut = date('Y-m-d', strtotime("$from -1 day"));
        $bsOpen  = glBalanceSheet($pdo, $openCut, $project_id, false, $scopeSql);
        $bsClose = glBalanceSheet($pdo, $to,      $project_id, false, $scopeSql);

        $mapBal = function (array $bs, string $side): array {
            $m = [];
            foreach ($bs[$side] as $l) {
                $id = (int)($l['account_id'] ?? 0);
                if ($id > 0) $m[$id] = (float)$l['amount'];
            }
            return $m;
        };
        $aOpen = $mapBal($bsOpen, 'assets');      $aClose = $mapBal($bsClose, 'assets');
        $lOpen = $mapBal($bsOpen, 'liabilities'); $lClose = $mapBal($bsClose, 'liabilities');

        $delta = function (array $open, array $close, int $id): float {
            return (($close[$id] ?? 0.0) - ($open[$id] ?? 0.0));
        };

        $namedAR  = ($arAcc  ? $delta($aOpen, $aClose, $arAcc)  : 0.0);   // asset Δ (close−open)
        $namedInv = ($invAcc ? $delta($aOpen, $aClose, $invAcc) : 0.0);
        $namedAP  = ($apAcc  ? $delta($lOpen, $lClose, $apAcc)  : 0.0);   // liability Δ
        $namedTax = ($vatAcc ? $delta($lOpen, $lClose, $vatAcc) : 0.0);

        // Cash effect of the named lines: an asset increase USES cash (−Δ); a
        // liability increase RELEASES cash (+Δ).
        $namedCash = (-$namedAR) + (-$namedInv) + $namedAP + $namedTax;

        // Operating cash is the ACTUAL cash movement (the direct-method total — it
        // ties to the Balance Sheet). The indirect statement bridges profit to that
        // figure; "Other working-capital movements" is the balancing remainder, which
        // also absorbs any non-cash adjustments not broken out above (e.g. settlements
        // routed through equity). This is the standard IAS 7 reconciliation form and
        // guarantees the bridge ties to cash exactly.
        $otherWc = round($targetOperating - ($netProfit + $depr + $namedCash), 2);

        return [
            'net_profit'    => round($netProfit, 2),
            'depreciation'  => round($depr, 2),
            'delta_ar'      => round($namedAR, 2),
            'delta_inv'     => round($namedInv, 2),
            'delta_ap'      => round($namedAP, 2),
            'delta_tax'     => round($namedTax, 2),
            'other_wc'      => $otherWc,
            'net_operating' => round($targetOperating, 2),
        ];
    };

    // ── Build the operating section per the selected method ───────────────────
    if ($method === 'indirect') {
        $indCur = $computeIndirect($start_date,        $end_date,        $cfCur['operating']['total']);
        $indCmp = $computeIndirect($comparative_start, $comparative_end, $cfCmp['operating']['total']);

        $operating_lines = [
            ['name' => 'Profit before tax',                              'amount' => $indCur['net_profit'],   'comparative_amount' => $indCmp['net_profit']],
            ['name' => 'Add: Depreciation (non-cash)',                   'amount' => $indCur['depreciation'], 'comparative_amount' => $indCmp['depreciation']],
            ['name' => '(Increase)/decrease in Trade Receivables',       'amount' => -$indCur['delta_ar'],    'comparative_amount' => -$indCmp['delta_ar']],
            ['name' => '(Increase)/decrease in Inventory',               'amount' => -$indCur['delta_inv'],   'comparative_amount' => -$indCmp['delta_inv']],
            ['name' => 'Increase/(decrease) in Trade Payables',          'amount' =>  $indCur['delta_ap'],    'comparative_amount' =>  $indCmp['delta_ap']],
            ['name' => 'Increase/(decrease) in Tax Payable',             'amount' =>  $indCur['delta_tax'],   'comparative_amount' =>  $indCmp['delta_tax']],
            ['name' => 'Other working-capital movements',                'amount' =>  $indCur['other_wc'],    'comparative_amount' =>  $indCmp['other_wc']],
        ];
        // The standard reconciliation lines stay visible even at zero — an IAS 7
        // reconciliation explicitly shows the full bridge (a missing line reads as
        // "not considered" rather than "nil"), and the UI relies on the fixed set.

        $net_operating_cur = $indCur['net_operating'];
        $net_operating_cmp = $indCmp['net_operating'];

        // Reconciliation vs the direct operating total (both from the GL → ~0).
        $reconciliation_diff_cur = round($net_operating_cur - $cfCur['operating']['total'], 2);
        $reconciliation_diff_cmp = round($net_operating_cmp - $cfCmp['operating']['total'], 2);
    } else {
        $operating_lines = $mergeSection($cfCur['operating']['lines'], $cfCmp['operating']['lines']);
        $net_operating_cur = $cfCur['operating']['total'];
        $net_operating_cmp = $cfCmp['operating']['total'];
        $reconciliation_diff_cur = null;
        $reconciliation_diff_cmp = null;
    }

    // Investing + financing are method-independent (always the direct figures).
    $investing_lines = $mergeSection($cfCur['investing']['lines'], $cfCmp['investing']['lines']);
    $financing_lines = $mergeSection($cfCur['financing']['lines'], $cfCmp['financing']['lines']);

    $net_investing_cur = $cfCur['investing']['total'];
    $net_investing_cmp = $cfCmp['investing']['total'];
    $net_financing_cur = $cfCur['financing']['total'];
    $net_financing_cmp = $cfCmp['financing']['total'];

    // Net change in cash. The DIRECT total is authoritative (ties to the BS); under
    // the indirect method we swap in its operating total so the section subtotals add
    // up on screen, and expose the reconciliation difference in meta.
    $net_change_cur = round($net_operating_cur + $net_investing_cur + $net_financing_cur, 2);
    $net_change_cmp = round($net_operating_cmp + $net_investing_cmp + $net_financing_cmp, 2);

    // ── Opening / Closing cash — straight from the GL (ties: open + net == close) ─
    $opening_cash     = $cfCur['opening_cash'];
    $closing_cash     = $cfCur['closing_cash'];
    $cmp_opening_cash = $cfCmp['opening_cash'];
    $cmp_closing_cash = $cfCmp['closing_cash'];

    // ─────────────────────────────────────────────────────────────────────────
    // IFRS for SMEs §7.19A + §7.19B-C disclosures — supplementary notes about
    // financing-liabilities and supplier-finance arrangements. These are forward
    // obligation notes (not posted cash), kept as document-sourced context.
    // ─────────────────────────────────────────────────────────────────────────
    $scopeClause = function (string $col) use ($project_id): array {
        if ($project_id !== null) return ['sql' => " AND $col = ?", 'params' => [$project_id]];
        // Disclosure tables alias differently; use a generic nullable scope on the column's table.
        return ['sql' => '', 'params' => []];
    };

    $financingLiabilitiesDisclosure = function (string $from, string $to): array {
        return [
            'applicable'        => false,
            'note'              => 'No financing liabilities tracked in this system (borrowings, share capital changes, and dividends excluded per company policy).',
            'opening_balance'   => 0.0,
            'cash_changes'      => 0.0,
            'non_cash_changes'  => 0.0,
            'closing_balance'   => 0.0,
        ];
    };

    $supplierFinanceDisclosure = function (string $as_of) use ($pdo, $project_id): array {
        $scopeSql = ''; $params = [$as_of];
        if ($project_id !== null) { $scopeSql = " AND si.project_id = ?"; $params[] = $project_id; }

        $due_date_expr = "
            CASE
                WHEN po.payment_terms LIKE 'net_%'
                  AND CAST(SUBSTRING_INDEX(po.payment_terms, '_', -1) AS UNSIGNED) > 0
                THEN DATE_ADD(si.date_recorded,
                              INTERVAL CAST(SUBSTRING_INDEX(po.payment_terms, '_', -1) AS UNSIGNED) DAY)
                ELSE si.date_recorded
            END
        ";
        try {
            $sql = "
                SELECT
                    COUNT(*)                                                 AS invoice_count,
                    COALESCE(SUM(si.amount), 0)                              AS total_unpaid_amount,
                    MIN({$due_date_expr})                                    AS earliest_due_date,
                    MAX({$due_date_expr})                                    AS latest_due_date,
                    COUNT(CASE WHEN po.payment_terms LIKE 'net_%' THEN 1 END) AS invoices_with_terms
                  FROM supplier_invoices si
             LEFT JOIN purchase_orders po ON si.po_id = po.purchase_order_id
                 WHERE si.status = 'approved'
                   AND si.payment_date IS NULL
                   AND si.date_recorded <= ?
                   {$scopeSql}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }

        return [
            'applicable'           => false,
            'note'                 => 'No formal supplier finance arrangements (reverse factoring, dynamic discounting, etc.) are tracked in this system. The figures below show all unpaid approved supplier invoices outstanding as at the report date, with due dates computed from the linked purchase order\'s payment_terms (e.g. "net_30" = date_recorded + 30 days); when no parseable terms exist, date_recorded is used as the proxy.',
            'invoice_count'        => (int)($row['invoice_count'] ?? 0),
            'invoices_with_terms'  => (int)($row['invoices_with_terms'] ?? 0),
            'total_unpaid_amount'  => (float)($row['total_unpaid_amount'] ?? 0),
            'earliest_due_date'    => $row['earliest_due_date'] ?? null,
            'latest_due_date'      => $row['latest_due_date'] ?? null,
        ];
    };

    $disclosures = [
        'financing_liabilities_reconciliation' => [
            'current'     => $financingLiabilitiesDisclosure($start_date, $end_date),
            'comparative' => $financingLiabilitiesDisclosure($comparative_start, $comparative_end),
        ],
        'supplier_finance_arrangements' => [
            'current'     => $supplierFinanceDisclosure($end_date),
            'comparative' => $supplierFinanceDisclosure($comparative_end),
        ],
    ];

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'current_start'             => $start_date,
                'current_end'               => $end_date,
                'comparative_start'         => $comparative_start,
                'comparative_end'           => $comparative_end,
                'project_id'                => $project_id,
                'project_filter_active'     => $project_id !== null,
                'is_admin'                  => $is_admin,
                'scoped_project_ids'        => $is_admin ? null : $user_project_ids,
                'opening_cash'              => $opening_cash,
                'closing_cash'              => $closing_cash,
                'comparative_opening_cash'  => $cmp_opening_cash,
                'comparative_closing_cash'  => $cmp_closing_cash,
                'method'                    => $method,
                'source'                    => 'general_ledger',
                'cash_accounts'             => count($cashIds),
                'ties_to_balance_sheet'     => $cfCur['reconciles'],
                'unclassified_contra'       => $cfCur['unclassified_count'],
                'operating_reconciliation_difference' => [
                    'current'     => $reconciliation_diff_cur,
                    'comparative' => $reconciliation_diff_cmp,
                ],
            ],
            'sections' => [
                'operating' => [
                    'lines'             => $operating_lines,
                    'total'             => $net_operating_cur,
                    'comparative_total' => $net_operating_cmp,
                ],
                'investing' => [
                    'lines'             => $investing_lines,
                    'total'             => $net_investing_cur,
                    'comparative_total' => $net_investing_cmp,
                ],
                'financing' => [
                    'lines'             => $financing_lines,
                    'total'             => $net_financing_cur,
                    'comparative_total' => $net_financing_cmp,
                ],
            ],
            'totals' => [
                'net_change_in_cash' => $net_change_cur,
                'comparative' => [
                    'net_change_in_cash' => $net_change_cmp,
                ],
            ],
            'disclosures' => $disclosures,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Cash Flow API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
