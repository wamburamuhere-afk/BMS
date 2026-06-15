<?php
/**
 * core/financial_reports.php
 * --------------------------
 * F1/F3 (money.md): the ONE source of truth for financial statements.
 *
 * Every figure here is DERIVED from the canonical ledger
 * (journal_entries + journal_entry_items, status='posted') plus each account's
 * opening_balance — nothing else. No legacy `transactions`, no document tables,
 * no `accounts.current_balance`. This is the professional pattern the AccountGo /
 * WorkDo DoubleEntry module uses (TrialBalanceService / ProfitLossService /
 * BalanceSheetService) and the target end-state for BMS reports: when every money
 * event posts to the GL, the live Balance Sheet / Income Statement read from here
 * and they can no longer disagree with the Trial Balance.
 *
 * The accounting identity these functions rely on (true for ANY balanced ledger,
 * i.e. one where every entry has Σdebits = Σcredits — enforced by postLedgerEntry):
 *
 *      Σ debits = Σ credits
 *   ⇒  Assets + Expenses = Liabilities + Equity + Revenue
 *   ⇒  Assets = Liabilities + Equity + (Revenue − Expenses)
 *   ⇒  Assets = Liabilities + Equity + Net Profit
 *
 * So a Balance Sheet that folds accumulated earnings (Revenue − Expenses, through
 * the as-of date) into equity ALWAYS balances — unless the opening balances
 * themselves are unbalanced, which is exactly the data fault the guardrail surfaces.
 *
 * Sign convention: every account's balance is returned POSITIVE in its natural
 * (normal_side) direction:
 *      debit-normal  (asset, expense, cogs, finance_cost): Σdebit − Σcredit
 *      credit-normal (liability, equity, revenue):          Σcredit − Σdebit
 *
 * OPENING BALANCES — deliberately journal-only by default.
 *   The posted journal is the single source of truth. The denormalized
 *   `accounts.opening_balance` field is NOT folded in by default because in this
 *   database it is an UNBALANCED legacy set (Σ debit-side openings ≠ Σ credit-side
 *   openings) that would throw every statement out of balance — the exact "second
 *   source of truth" money.md F1 warns against. The correct way to carry an opening
 *   position is to POST it as an opening journal entry (Dr opening assets / Cr
 *   opening liabilities + equity). Callers may pass $includeOpening = true to fold
 *   the field in for comparison/diagnosis; glOpeningBalanceImbalance() reports how
 *   far that field is from balancing so it can be remediated.
 *
 * All functions are read-only and side-effect free. Optional $projectId narrows to
 * journal_entries.project_id = N (company-wide when null). User project-scope
 * (the "assigned projects OR untagged" rule) stays the caller endpoint's job —
 * this engine is the pure computation layer.
 */

if (!function_exists('_gl_account_activity')) {
    /**
     * Per active account: opening_balance + posted debit/credit sums in a window.
     *
     * @param PDO         $pdo
     * @param ?string     $from        Period start (YYYY-MM-DD). NULL ⇒ cumulative
     *                                 from inception (used by Balance Sheet / Trial
     *                                 Balance as-of). When set, only flows in
     *                                 [from, to] are summed (used by P&L period view).
     * @param string      $to          Cut-off date (YYYY-MM-DD), inclusive.
     * @param ?int        $projectId   Filter je.project_id = N, or null for all.
     * @return array<int,array{account_id:int,account_code:string,account_name:string,
     *               category:?string,statement:?string,normal_side:string,
     *               opening_balance:float,debit:float,credit:float}>
     */
    function _gl_account_activity(PDO $pdo, ?string $from, string $to, ?int $projectId = null, string $scopeSql = ''): array
    {
        // Date predicate on the journal header. Bound inside the JOIN so the
        // LEFT JOIN still returns accounts with zero activity (every account row
        // survives; only its posted sums go to 0).
        $dateSql = $from === null
            ? "AND je.entry_date <= :to"
            : "AND je.entry_date >= :from AND je.entry_date <= :to";

        // Optional project scope. $projectId binds a single project; $scopeSql is a
        // trusted raw fragment from core/project_scope.php (e.g.
        // scopeFilterSqlNullable('project','je') → " AND (je.project_id IN (1,2) OR
        // je.project_id IS NULL)") with inline integer ids — used for the
        // "assigned projects OR untagged" non-admin view. Callers use one or the other.
        $projSql = $projectId !== null ? "AND je.project_id = :pid" : "";

        // Account inclusion rule (critical): a Trial Balance / Balance Sheet must
        // include EVERY account that carries a real balance — never just the
        // active ones. BMS deactivated legacy accounts that still hold historical
        // postings (e.g. an old bank account with the bulk of the credits); an
        // "active only" filter silently drops those lines and the books appear
        // wildly out of balance. So we admit an account when it is active OR has a
        // non-zero opening balance OR has ANY posted journal activity. The date /
        // project bounds still apply to the SUMs, so out-of-window accounts simply
        // sum to zero and are hidden by the caller.
        $sql = "
            SELECT
                a.account_id,
                a.account_code,
                a.account_name,
                a.status,
                at.category,
                at.statement,
                COALESCE(at.normal_side, 'debit')                AS normal_side,
                COALESCE(a.opening_balance, 0)                   AS opening_balance,
                -- Only count a line when its parent entry actually matched the
                -- posted/date/project conditions (je.entry_id IS NOT NULL). The
                -- jei LEFT JOIN alone would otherwise leak draft/void items and
                -- out-of-window dates, because the SUM reads jei.amount.
                COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='debit'  THEN jei.amount ELSE 0 END), 0) AS debit,
                COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='credit' THEN jei.amount ELSE 0 END), 0) AS credit
              FROM accounts a
         LEFT JOIN account_types at ON a.account_type_id = at.type_id
         LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
         LEFT JOIN journal_entries     je  ON je.entry_id = jei.entry_id
                                          AND je.status   = 'posted'
                                          $dateSql
                                          $projSql
                                          $scopeSql
             WHERE a.status = 'active'
                OR COALESCE(a.opening_balance, 0) <> 0
                OR EXISTS (
                       SELECT 1
                         FROM journal_entry_items jx
                         JOIN journal_entries     jy ON jy.entry_id = jx.entry_id
                        WHERE jx.account_id = a.account_id
                          AND jy.status = 'posted'
                   )
          GROUP BY a.account_id, a.account_code, a.account_name, a.status,
                   at.category, at.statement, at.normal_side, a.opening_balance
          ORDER BY a.account_code, a.account_id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':to', $to);
        if ($from !== null)        $stmt->bindValue(':from', $from);
        if ($projectId !== null)   $stmt->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'account_id'      => (int)$r['account_id'],
                'account_code'    => $r['account_code'],
                'account_name'    => $r['account_name'],
                'status'          => $r['status'],            // active / inactive
                'category'        => $r['category'],          // may be null (unclassified)
                'statement'       => $r['statement'],         // may be null
                'normal_side'     => $r['normal_side'],
                'opening_balance' => (float)$r['opening_balance'],
                'debit'           => (float)$r['debit'],
                'credit'          => (float)$r['credit'],
            ];
        }
        return $rows;
    }
}

if (!function_exists('_gl_signed_balance')) {
    /** Balance positive in the account's natural direction, including opening. */
    function _gl_signed_balance(array $row, bool $includeOpening = true): float
    {
        $opening = $includeOpening ? $row['opening_balance'] : 0.0;
        if ($row['normal_side'] === 'credit') {
            // opening for a credit-normal account sits on the credit side
            return ($opening + $row['credit'] - $row['debit']);
        }
        return ($opening + $row['debit'] - $row['credit']);
    }
}

if (!function_exists('glTrialBalance')) {
    /**
     * Trial Balance as of $asOf — every account's cumulative Dr/Cr from the GL,
     * opening_balance allocated by normal_side. Proves Σ Dr = Σ Cr.
     *
     * @return array{accounts:array,total_debit:float,total_credit:float,balanced:bool,difference:float}
     */
    function glTrialBalance(PDO $pdo, string $asOf, ?int $projectId = null, bool $includeOpening = false, string $scopeSql = ''): array
    {
        $rows = _gl_account_activity($pdo, null, $asOf, $projectId, $scopeSql);

        $accounts = [];
        $totalDr = 0.0;
        $totalCr = 0.0;

        foreach ($rows as $r) {
            $openingDr = ($includeOpening && $r['normal_side'] === 'debit')  ? $r['opening_balance'] : 0.0;
            $openingCr = ($includeOpening && $r['normal_side'] === 'credit') ? $r['opening_balance'] : 0.0;
            $dr = $openingDr + $r['debit'];
            $cr = $openingCr + $r['credit'];

            if (abs($dr) < 0.005 && abs($cr) < 0.005) {
                continue; // hide accounts with no opening and no activity
            }

            // Present each account on a single side by its NET (the conventional
            // Trial Balance presentation), so Σ Dr = Σ Cr reflects net balances.
            $net = ($r['normal_side'] === 'credit') ? ($cr - $dr) : ($dr - $cr);
            $sideDr = 0.0; $sideCr = 0.0;
            if ($r['normal_side'] === 'debit') {
                if ($net >= 0) $sideDr = $net; else $sideCr = -$net;
            } else {
                if ($net >= 0) $sideCr = $net; else $sideDr = -$net;
            }

            $accounts[] = [
                'account_id'   => $r['account_id'],
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'category'     => $r['category'],
                'statement'    => $r['statement'],
                'debit'        => round($sideDr, 2),
                'credit'       => round($sideCr, 2),
            ];
            $totalDr += $sideDr;
            $totalCr += $sideCr;
        }

        return [
            'accounts'    => $accounts,
            'total_debit' => round($totalDr, 2),
            'total_credit'=> round($totalCr, 2),
            'difference'  => round($totalDr - $totalCr, 2),
            'balanced'    => abs($totalDr - $totalCr) < 0.01,
        ];
    }
}

if (!function_exists('glProfitLoss')) {
    /**
     * Income Statement for the period [from, to], derived purely from the GL.
     * Period flows only — opening balances do not belong in a P&L.
     *
     * Categories: revenue + other_income (credit-normal) vs cogs + expense +
     * finance_cost (debit-normal). Revenue = ordinary sales only; other_income =
     * non-ordinary income / gains (IFRS revenue-vs-gains distinction). Net profit =
     * revenue + other_income − (cogs + expenses + finance costs).
     *
     * @return array{revenue:array,other_income:array,cogs:array,expense:array,finance_cost:array,
     *               total_revenue:float,total_other_income:float,total_cogs:float,total_expense:float,
     *               total_finance_cost:float,gross_profit:float,net_profit:float}
     */
    function glProfitLoss(PDO $pdo, string $from, string $to, ?int $projectId = null, string $scopeSql = ''): array
    {
        $rows = _gl_account_activity($pdo, $from, $to, $projectId, $scopeSql);

        $buckets = ['revenue' => [], 'other_income' => [], 'cogs' => [], 'expense' => [], 'finance_cost' => []];
        $totals  = ['revenue' => 0.0, 'other_income' => 0.0, 'cogs' => 0.0, 'expense' => 0.0, 'finance_cost' => 0.0];

        foreach ($rows as $r) {
            $cat = $r['category'];
            if (!isset($buckets[$cat])) continue;          // skip BS + unclassified
            $bal = _gl_signed_balance($r, false);          // period flow, no opening
            if (abs($bal) < 0.005) continue;

            $buckets[$cat][] = [
                'account_id'   => $r['account_id'],
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'amount'       => round($bal, 2),
            ];
            $totals[$cat] += $bal;
        }

        $totalRevenue = $totals['revenue'];
        $totalOther   = $totals['other_income'];
        $totalCogs    = $totals['cogs'];
        $totalExpense = $totals['expense'];
        $totalFinance = $totals['finance_cost'];
        $grossProfit  = $totalRevenue - $totalCogs;
        // Net profit folds Other Income in (it is income, just not ordinary revenue).
        $netProfit    = $grossProfit + $totalOther - $totalExpense - $totalFinance;

        return [
            'from'               => $from,
            'to'                 => $to,
            'revenue'            => $buckets['revenue'],
            'other_income'       => $buckets['other_income'],
            'cogs'               => $buckets['cogs'],
            'expense'            => $buckets['expense'],
            'finance_cost'       => $buckets['finance_cost'],
            'total_revenue'      => round($totalRevenue, 2),
            'total_other_income' => round($totalOther, 2),
            'total_cogs'         => round($totalCogs, 2),
            'total_expense'      => round($totalExpense, 2),
            'total_finance_cost' => round($totalFinance, 2),
            'gross_profit'       => round($grossProfit, 2),
            'net_profit'         => round($netProfit, 2),
        ];
    }
}

if (!function_exists('glBalanceSheet')) {
    /**
     * Balance Sheet as of $asOf, derived purely from the GL.
     *
     * Assets / Liabilities / Equity are each account's cumulative balance
     * (opening + posted activity through $asOf). Accumulated earnings
     * (Revenue − Expenses, from inception through $asOf) are folded into equity as
     * a single "Retained Earnings (current)" line — so the statement balances by
     * the identity above. `balanced` is the real test: if it is false, either the
     * opening balances are unbalanced or an account is unclassified.
     *
     * @return array with assets/liabilities/equity line arrays + totals + balanced flag.
     */
    function glBalanceSheet(PDO $pdo, string $asOf, ?int $projectId = null, bool $includeOpening = false, string $scopeSql = ''): array
    {
        $rows = _gl_account_activity($pdo, null, $asOf, $projectId, $scopeSql);

        $assets = []; $liabilities = []; $equity = [];
        $totalAssets = 0.0; $totalLiab = 0.0; $totalEquityAccounts = 0.0;
        $totalRevenue = 0.0; $totalExpenses = 0.0;
        $unclassified = [];

        foreach ($rows as $r) {
            $bal = _gl_signed_balance($r, $includeOpening); // journal-only by default
            $cat = $r['category'];

            switch ($cat) {
                case 'asset':
                    if (abs($bal) >= 0.005) {
                        $assets[] = _gl_bs_line($r, $bal);
                        $totalAssets += $bal;
                    }
                    break;
                case 'liability':
                    if (abs($bal) >= 0.005) {
                        $liabilities[] = _gl_bs_line($r, $bal);
                        $totalLiab += $bal;
                    }
                    break;
                case 'equity':
                    if (abs($bal) >= 0.005) {
                        $equity[] = _gl_bs_line($r, $bal);
                        $totalEquityAccounts += $bal;
                    }
                    break;
                case 'revenue':
                case 'other_income':
                    // Both are credit-normal income; both fold into accumulated earnings.
                    $totalRevenue += $bal;
                    break;
                case 'cogs':
                case 'expense':
                case 'finance_cost':
                    $totalExpenses += $bal;
                    break;
                default:
                    // Unclassified account carrying a balance — a real risk to the
                    // statement balancing. Surface it rather than silently drop it.
                    if (abs($bal) >= 0.005) {
                        $unclassified[] = _gl_bs_line($r, $bal);
                    }
            }
        }

        $accumulatedEarnings = $totalRevenue - $totalExpenses;
        if (abs($accumulatedEarnings) >= 0.005) {
            $equity[] = [
                'account_id'   => null,
                'account_code' => '',
                'account_name' => 'Retained Earnings (accumulated profit to date)',
                'amount'       => round($accumulatedEarnings, 2),
            ];
        }
        $totalEquity = $totalEquityAccounts + $accumulatedEarnings;

        $diff = $totalAssets - ($totalLiab + $totalEquity);

        return [
            'as_of'              => $asOf,
            'assets'             => $assets,
            'liabilities'        => $liabilities,
            'equity'             => $equity,
            'unclassified'       => $unclassified,
            'total_assets'       => round($totalAssets, 2),
            'total_liabilities'  => round($totalLiab, 2),
            'total_equity'       => round($totalEquity, 2),
            'retained_earnings'  => round($accumulatedEarnings, 2),
            'difference'         => round($diff, 2),
            'balanced'           => abs($diff) < 0.01,
        ];
    }
}

if (!function_exists('_gl_bs_line')) {
    function _gl_bs_line(array $r, float $bal): array
    {
        return [
            'account_id'   => $r['account_id'],
            'account_code' => $r['account_code'],
            'account_name' => $r['account_name'],
            'amount'       => round($bal, 2),
        ];
    }
}

if (!function_exists('glStrandedInactiveAccounts')) {
    /**
     * Data-health diagnostic: accounts that are INACTIVE yet still carry posted
     * journal activity. These are the legacy accounts a report's "active only"
     * filter would wrongly hide. Each one is a remediation candidate — reactivate
     * it, or merge its history into the active replacement account. Listing them
     * here lets a report or a scheduled check surface the problem instead of it
     * silently unbalancing every statement.
     *
     * @return array<int,array{account_id:int,account_code:string,account_name:string,
     *               category:?string,debit:float,credit:float,balance:float}>
     */
    function glStrandedInactiveAccounts(PDO $pdo): array
    {
        $sql = "
            SELECT a.account_id, a.account_code, a.account_name,
                   at.category,
                   COALESCE(at.normal_side,'debit') normal_side,
                   COALESCE(SUM(CASE WHEN jei.type='debit'  THEN jei.amount ELSE 0 END),0) dr,
                   COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END),0) cr
              FROM accounts a
         LEFT JOIN account_types at ON a.account_type_id = at.type_id
              JOIN journal_entry_items jei ON jei.account_id = a.account_id
              JOIN journal_entries     je  ON je.entry_id = jei.entry_id AND je.status='posted'
             WHERE a.status <> 'active'
          GROUP BY a.account_id, a.account_code, a.account_name, at.category, at.normal_side
            HAVING ABS(dr) > 0.005 OR ABS(cr) > 0.005
          ORDER BY (ABS(dr)+ABS(cr)) DESC
        ";
        $out = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bal = ($r['normal_side'] === 'credit')
                 ? ((float)$r['cr'] - (float)$r['dr'])
                 : ((float)$r['dr'] - (float)$r['cr']);
            $out[] = [
                'account_id'   => (int)$r['account_id'],
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'category'     => $r['category'],
                'debit'        => round((float)$r['dr'], 2),
                'credit'       => round((float)$r['cr'], 2),
                'balance'      => round($bal, 2),
            ];
        }
        return $out;
    }
}

if (!function_exists('glOpeningBalanceImbalance')) {
    /**
     * Data-health diagnostic: how far the denormalized `accounts.opening_balance`
     * field is from balancing. A valid opening position must itself be a balanced
     * entry (Σ debit-side openings = Σ credit-side openings). When this is non-zero,
     * the field is unreliable legacy data — the reason the engine ignores it by
     * default. Remediation: post the real opening position as an opening journal
     * entry and zero the field, or correct the figures.
     *
     * @return array{debit_side:float,credit_side:float,difference:float,balanced:bool,accounts:array}
     */
    function glOpeningBalanceImbalance(PDO $pdo): array
    {
        $rows = $pdo->query("
            SELECT a.account_id, a.account_code, a.account_name, a.status,
                   COALESCE(at.normal_side,'debit') normal_side,
                   COALESCE(a.opening_balance,0) ob
              FROM accounts a
         LEFT JOIN account_types at ON a.account_type_id = at.type_id
             WHERE COALESCE(a.opening_balance,0) <> 0
          ORDER BY ABS(a.opening_balance) DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $debitSide = 0.0; $creditSide = 0.0; $accounts = [];
        foreach ($rows as $r) {
            $ob = (float)$r['ob'];
            if ($r['normal_side'] === 'credit') $creditSide += $ob; else $debitSide += $ob;
            $accounts[] = [
                'account_id'   => (int)$r['account_id'],
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'status'       => $r['status'],
                'normal_side'  => $r['normal_side'],
                'opening_balance' => round($ob, 2),
            ];
        }
        $diff = $debitSide - $creditSide;
        return [
            'debit_side'  => round($debitSide, 2),
            'credit_side' => round($creditSide, 2),
            'difference'  => round($diff, 2),
            'balanced'    => abs($diff) < 0.01,
            'accounts'    => $accounts,
        ];
    }
}

if (!function_exists('assertLedgerBalanced')) {
    /**
     * The money.md F3 guardrail. Two independent checks, both read from the GL:
     *   1. Σ posted debits = Σ posted credits   (double-entry integrity)
     *   2. Assets = Liabilities + Equity         (the balance sheet identity)
     *
     * Returns a structured result. With $throw = true it raises LedgerException on
     * any failure (for use in tests / a maintenance "verify books" endpoint). It is
     * deliberately NOT called inside postLedgerEntry — per-entry balance is already
     * enforced there; this is a whole-ledger assertion meant for report time and
     * scheduled integrity checks.
     *
     * @return array{ledger_balanced:bool,sum_debit:float,sum_credit:float,dr_cr_difference:float,
     *               bs_balanced:bool,total_assets:float,total_liabilities:float,total_equity:float,
     *               bs_difference:float,ok:bool}
     */
    function assertLedgerBalanced(PDO $pdo, ?string $asOf = null, bool $throw = false): array
    {
        $asOf = $asOf ?: date('Y-m-d');

        $proj = ''; // whole ledger
        $r = $pdo->query("
            SELECT
                COALESCE(SUM(CASE WHEN jei.type='debit'  THEN jei.amount ELSE 0 END),0) dr,
                COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END),0) cr
              FROM journal_entry_items jei
              JOIN journal_entries je ON je.entry_id = jei.entry_id
             WHERE je.status='posted' AND je.entry_date <= " . $pdo->quote($asOf) . "
        ")->fetch(PDO::FETCH_ASSOC);
        $sumDr = (float)$r['dr'];
        $sumCr = (float)$r['cr'];
        $drCrDiff = $sumDr - $sumCr;
        $ledgerBalanced = abs($drCrDiff) < 0.01;

        $bs = glBalanceSheet($pdo, $asOf);
        $bsBalanced = $bs['balanced'];

        $result = [
            'as_of'              => $asOf,
            'ledger_balanced'    => $ledgerBalanced,
            'sum_debit'          => round($sumDr, 2),
            'sum_credit'         => round($sumCr, 2),
            'dr_cr_difference'   => round($drCrDiff, 2),
            'bs_balanced'        => $bsBalanced,
            'total_assets'       => $bs['total_assets'],
            'total_liabilities'  => $bs['total_liabilities'],
            'total_equity'       => $bs['total_equity'],
            'bs_difference'      => $bs['difference'],
            'ok'                 => $ledgerBalanced && $bsBalanced,
        ];

        if ($throw && !$result['ok']) {
            if (!class_exists('LedgerException')) {
                require_once __DIR__ . '/ledger_post.php';
            }
            $msg = "assertLedgerBalanced FAILED as of $asOf: ";
            if (!$ledgerBalanced) $msg .= sprintf("Σdebits %.2f ≠ Σcredits %.2f (diff %.2f). ", $sumDr, $sumCr, $drCrDiff);
            if (!$bsBalanced)     $msg .= sprintf("Assets %.2f ≠ Liab %.2f + Equity %.2f (diff %.2f).",
                                                  $bs['total_assets'], $bs['total_liabilities'], $bs['total_equity'], $bs['difference']);
            throw new LedgerException($msg);
        }

        return $result;
    }
}

if (!function_exists('glCashAccountIds')) {
    /**
     * The account ids that ARE "cash & cash equivalents" — the Cash Flow statement's
     * cash line. Same definition as cashBankAccounts()/bankAccountResolve()
     * (asset accounts marked bank-nature: sub-type is_bank=1, or the legacy
     * cash_flow_category='cash' fallback), but deliberately NOT filtered on
     * status: a deactivated legacy bank account that still holds posted history
     * is still cash. If it were excluded, its movements would be misread as a
     * non-cash flow and the statement would stop tying to the Balance Sheet.
     *
     * @return int[] distinct account ids (may be empty on an unconfigured chart).
     */
    function glCashAccountIds(PDO $pdo): array
    {
        $rows = $pdo->query("
            SELECT a.account_id
              FROM accounts a
         LEFT JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
             WHERE a.account_type = 'asset'
               AND (st.is_bank = 1 OR a.cash_flow_category = 'cash')
        ")->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_unique(array_map('intval', $rows)));
    }
}

if (!function_exists('glAccountRawSum')) {
    /**
     * Signed (Σdebit − Σcredit) of posted journal activity on ONE account, either
     * cumulative through $to (when $from is null — an as-of balance) or within the
     * window [$from, $to] (a period flow). Raw, so the caller applies the sign for
     * a credit-normal account (a liability's natural balance = −rawSum). Used by the
     * indirect-method working-capital deltas and the depreciation add-back, all from
     * the same single ledger as the rest of the statements.
     */
    function glAccountRawSum(PDO $pdo, int $accountId, ?string $from, string $to, ?int $projectId = null, string $scopeSql = ''): float
    {
        if ($accountId <= 0) return 0.0;
        $dateSql = $from === null
            ? "AND je.entry_date <= " . $pdo->quote($to)
            : "AND je.entry_date >= " . $pdo->quote($from) . " AND je.entry_date <= " . $pdo->quote($to);
        $proj = $projectId !== null ? " AND je.project_id = " . (int)$projectId : '';
        return (float)$pdo->query("
            SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END), 0)
              FROM journal_entry_items jei
              JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status='posted'
             WHERE jei.account_id = " . (int)$accountId . " $dateSql $proj $scopeSql
        ")->fetchColumn();
    }
}

if (!function_exists('glCashFlow')) {
    /**
     * Cash Flow Statement for the period [$from, $to], DERIVED PURELY FROM THE GL
     * (posted journal_entries) — the F1/F3 single source. Direct method, classified
     * by the contra account of every cash-touching entry.
     *
     * How it ties (the guarantee): the net change in cash is the signed movement on
     * the cash accounts (Σ cash-leg debits − credits). For every posted entry
     * Σdebits = Σcredits, so across all cash-touching entries the cash movement
     * exactly equals Σ over the NON-cash legs of (credit − debit). We therefore read
     * those non-cash legs, sign each as cash-flow (a credit contra = cash IN, a debit
     * contra = cash OUT), and the operating + investing + financing totals always sum
     * back to the net change in cash — which itself equals the Balance Sheet's
     * cash-line movement (glBalanceSheet reads the same ledger).
     *
     * Classification by the contra account (the same account_types.category the BS/IS
     * group on):
     *   revenue / expense / cogs / finance_cost  → operating
     *   liability                                → operating (working capital; loans
     *                                              are excluded by company policy so
     *                                              no borrowing leg appears here)
     *   asset, PP&E (code 1-3xxx or non_current) → investing
     *   asset, other (AR, inventory, prepaid…)   → operating (working capital)
     *   equity                                   → financing (capital / drawings)
     *   unclassified                             → operating, but counted so the
     *                                              caller can flag the chart gap
     *
     * EXISTS (not a JOIN) selects cash-touching entries, so an entry with >1 cash
     * leg (e.g. an inter-account bank transfer) does not fan-out the contra rows;
     * such a transfer nets to 0 across the cash accounts and correctly shows no flow.
     *
     * @return array{from:string,to:string,cash_account_ids:int[],opening_cash:float,
     *   closing_cash:float,net_change_in_cash:float,
     *   operating:array{lines:array,total:float},
     *   investing:array{lines:array,total:float},
     *   financing:array{lines:array,total:float},
     *   sections_net:float,reconciles:bool,unclassified_count:int}
     */
    function glCashFlow(PDO $pdo, string $from, string $to, ?int $projectId = null, string $scopeSql = ''): array
    {
        $cashIds = glCashAccountIds($pdo);

        // Opening / closing / net change — straight from the posted journal, so they
        // tie to glBalanceSheet's cash line by construction.
        $closing     = 0.0;
        $opening     = 0.0;
        if (!empty($cashIds)) {
            $in   = implode(',', array_map('intval', $cashIds));
            $proj = $projectId !== null ? " AND je.project_id = " . (int)$projectId : '';
            $balAsOf = function (string $asOf) use ($pdo, $in, $proj, $scopeSql): float {
                return (float)$pdo->query("
                    SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END), 0)
                      FROM journal_entry_items jei
                      JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status='posted'
                     WHERE jei.account_id IN ($in)
                       AND je.entry_date <= " . $pdo->quote($asOf) . " $proj $scopeSql
                ")->fetchColumn();
            };
            $closing = $balAsOf($to);
            $opening = $balAsOf(date('Y-m-d', strtotime("$from -1 day")));
        }
        $netChange = round($closing - $opening, 2);

        $buckets = ['operating' => [], 'investing' => [], 'financing' => []];
        $unclassifiedCount = 0;

        if (!empty($cashIds)) {
            $in   = implode(',', array_map('intval', $cashIds));
            $proj = $projectId !== null ? " AND je.project_id = " . (int)$projectId : '';
            $sql = "
                SELECT contra.account_id, a.account_code, a.account_name,
                       at.category, COALESCE(at.liquidity, '') AS liquidity,
                       SUM(CASE WHEN contra.type='credit' THEN contra.amount ELSE -contra.amount END) AS cash_flow
                  FROM journal_entries je
                  JOIN journal_entry_items contra ON contra.entry_id = je.entry_id
                  JOIN accounts a ON a.account_id = contra.account_id
             LEFT JOIN account_types at ON a.account_type_id = at.type_id
                 WHERE je.status = 'posted'
                   AND je.entry_date >= " . $pdo->quote($from) . "
                   AND je.entry_date <= " . $pdo->quote($to) . "
                   AND contra.account_id NOT IN ($in)
                   AND EXISTS (SELECT 1 FROM journal_entry_items cx
                                WHERE cx.entry_id = je.entry_id AND cx.account_id IN ($in))
                   $proj $scopeSql
              GROUP BY contra.account_id, a.account_code, a.account_name, at.category, at.liquidity
              ORDER BY a.account_code, a.account_id
            ";
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $amt = round((float)$r['cash_flow'], 2);   // inflow positive
                if (abs($amt) < 0.005) continue;
                $cat  = $r['category'];
                $code = (string)$r['account_code'];
                $isPpe = (strpos($code, '1-3') === 0) || ($r['liquidity'] === 'non_current');

                if (in_array($cat, ['revenue', 'other_income', 'expense', 'cogs', 'finance_cost'], true)) {
                    $bucket = 'operating';
                } elseif ($cat === 'equity') {
                    $bucket = 'financing';
                } elseif ($cat === 'asset') {
                    $bucket = $isPpe ? 'investing' : 'operating';
                } elseif ($cat === 'liability') {
                    $bucket = 'operating';
                } else {
                    $bucket = 'operating';
                    $unclassifiedCount++;
                }

                $buckets[$bucket][] = [
                    'account_id'   => (int)$r['account_id'],
                    'account_code' => $code,
                    'account_name' => $r['account_name'],
                    'category'     => $cat,
                    'amount'       => $amt,
                ];
            }
        }

        $sumLines = function (array $lines): float {
            $t = 0.0; foreach ($lines as $l) { $t += $l['amount']; } return round($t, 2);
        };
        $opTotal  = $sumLines($buckets['operating']);
        $invTotal = $sumLines($buckets['investing']);
        $finTotal = $sumLines($buckets['financing']);
        $sectionsNet = round($opTotal + $invTotal + $finTotal, 2);

        return [
            'from'               => $from,
            'to'                 => $to,
            'cash_account_ids'   => $cashIds,
            'opening_cash'       => round($opening, 2),
            'closing_cash'       => round($closing, 2),
            'net_change_in_cash' => $netChange,
            'operating'          => ['lines' => $buckets['operating'], 'total' => $opTotal],
            'investing'          => ['lines' => $buckets['investing'], 'total' => $invTotal],
            'financing'          => ['lines' => $buckets['financing'], 'total' => $finTotal],
            'sections_net'       => $sectionsNet,
            'reconciles'         => abs($sectionsNet - $netChange) < 0.01,
            'unclassified_count' => $unclassifiedCount,
        ];
    }
}
