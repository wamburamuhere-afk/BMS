<?php
/**
 * core/financial_classification.php
 *
 * Single source of truth for account classification used by the five
 * financial statements:
 *
 *   - Income Statement (Profit & Loss)
 *   - Balance Sheet
 *   - Cash Flow Statement
 *   - Trial Balance
 *   - General Ledger
 *
 * Before this helper existed, each report classified accounts independently:
 *   - Income Statement read a `accounts.account_type` column directly
 *   - Trial Balance and Balance Sheet JOINed `account_types.type_name`
 *   - Cash Flow used `account_name LIKE '%cash%'` heuristics
 *
 * All five reports now route through these helpers, which read the canonical
 * columns added by migration 2026_05_27_account_types_classification.php:
 *
 *   account_types.statement           ENUM('BS','IS')
 *   account_types.category            ENUM('asset','liability','equity',
 *                                          'revenue','expense','cogs')
 *   account_types.normal_side         ENUM('debit','credit')
 *   account_types.cash_flow_category  ENUM('operating','investing',
 *                                          'financing','cash','none')
 *
 * Side effects: none. Pure read helpers. Safe to require multiple times.
 */

// Guard: this file must only be included via roots.php / a page that already
// loaded the PDO connection. We never open our own DB connection here.

if (!function_exists('fc_categories')) {

    /**
     * Returns true when the account_types classification columns (added by
     * migration 2026_05_27_account_types_classification.php) are present on
     * this server. Every financial report reads account_types.category, so on a
     * server where that migration hasn't run the queries throw SQLSTATE 42S22
     * ("Unknown column 'at.category'"). Reports call this first and render
     * fc_classification_missing_banner() instead of crashing. Result is cached
     * per request.
     *
     * @param  PDO  $pdo
     * @return bool
     */
    function fc_classification_ready(PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM account_types LIKE 'category'");
            $ready = $stmt !== false && $stmt->fetch() !== false;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    /**
     * Friendly banner shown by a report when the classification columns are
     * missing (see fc_classification_ready). Tells an admin which migration to
     * run rather than leaking a raw SQL error to the user.
     *
     * @param  string $reportTitle  e.g. 'Trial Balance'
     * @return string  HTML
     */
    function fc_classification_missing_banner(string $reportTitle = 'This report'): string {
        $t = htmlspecialchars($reportTitle, ENT_QUOTES);
        return '<div class="container-fluid py-4 d-print-none">'
             . '<div class="alert border-0 shadow-sm" style="border-radius:12px;background:#fff3cd;color:#664d03;border:1px solid #ffe69c!important;">'
             . '<h5 class="fw-bold mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i>' . $t . ' is not available yet</h5>'
             . '<p class="mb-2">It needs the account-type classification columns '
             . '(<code>category</code>, <code>normal_side</code>, <code>statement</code>, <code>cash_flow_category</code>) '
             . 'on the <code>account_types</code> table, which have not been installed on this server.</p>'
             . '<p class="mb-0 small">An administrator should run the pending database migration '
             . '<code>2026_05_27_account_types_classification.php</code> — for example from '
             . '<a href="' . (function_exists('getUrl') ? getUrl('migrations/status.php') : '/migrations/status.php') . '" class="fw-bold">/migrations/status.php</a> '
             . '— then reload this page.</p>'
             . '</div></div>';
    }

    /**
     * Returns the canonical list of accounting categories. Used by reports
     * to assert that every account has a non-null category before computing
     * totals; an unclassified account on a Balance Sheet or P&L is a silent
     * data-integrity bug.
     *
     * @return string[]
     */
    function fc_categories(): array {
        return ['asset', 'liability', 'equity', 'revenue', 'expense', 'cogs'];
    }

    /**
     * Returns the cash-flow-category list. Used by the Cash Flow Statement.
     *
     * @return string[]
     */
    function fc_cash_flow_categories(): array {
        return ['operating', 'investing', 'financing', 'cash', 'none'];
    }

    /**
     * Returns the categories that roll up to the Income Statement.
     *
     * @return string[]
     */
    function fc_income_statement_categories(): array {
        return ['revenue', 'expense', 'cogs'];
    }

    /**
     * Returns the categories that roll up to the Balance Sheet.
     *
     * @return string[]
     */
    function fc_balance_sheet_categories(): array {
        return ['asset', 'liability', 'equity'];
    }

    /**
     * Returns the type_ids that belong to one or more accounting categories.
     * Used by every report when it needs to build "WHERE at.type_id IN (?, ?)"
     * style filters without exposing the category column directly to callers.
     *
     * @param  PDO            $pdo
     * @param  string[]|string $categories  one category or an array of them
     * @return int[]
     */
    function fc_type_ids_for_categories(PDO $pdo, $categories): array {
        $cats = is_array($categories) ? $categories : [$categories];
        if (empty($cats)) return [];

        $placeholders = implode(',', array_fill(0, count($cats), '?'));
        $stmt = $pdo->prepare("
            SELECT type_id
              FROM account_types
             WHERE category IN ($placeholders)
        ");
        $stmt->execute($cats);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /**
     * Returns the type_ids that belong to a specific cash_flow_category.
     * Used by the Cash Flow Statement.
     *
     * @param  PDO    $pdo
     * @param  string $cashFlowCategory  one of operating / investing / financing / cash / none
     * @return int[]
     */
    function fc_type_ids_for_cash_flow_category(PDO $pdo, string $cashFlowCategory): array {
        if (!in_array($cashFlowCategory, fc_cash_flow_categories(), true)) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT type_id
              FROM account_types
             WHERE cash_flow_category = ?
        ");
        $stmt->execute([$cashFlowCategory]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /**
     * Returns the account_ids whose EFFECTIVE cash_flow_category equals the
     * given value. "Effective" = the account-level override
     * (accounts.cash_flow_category, set per the canonical IAS 7 mapping by the
     * 2026_05_30 migration) when present, else the account_type's value.
     *
     * This is what lets the Cash Flow Statement identify cash accounts and
     * route Fixed Assets to investing even though both are the generic "asset"
     * type — the 5 account_types are too coarse to express it on their own.
     *
     * Defensive: if accounts.cash_flow_category doesn't exist yet (migration
     * not run), falls back to the type-level classification so callers never
     * fatal during a staged rollout.
     *
     * @param  PDO    $pdo
     * @param  string $cashFlowCategory
     * @return int[]  account_ids
     */
    function fc_account_ids_for_cash_flow_category(PDO $pdo, string $cashFlowCategory): array {
        if (!in_array($cashFlowCategory, fc_cash_flow_categories(), true)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT a.account_id
                  FROM accounts a
             LEFT JOIN account_types at ON a.account_type_id = at.type_id
                 WHERE COALESCE(a.cash_flow_category, at.cash_flow_category) = ?
                   AND a.status = 'active'
            ");
            $stmt->execute([$cashFlowCategory]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
        } catch (PDOException $e) {
            // accounts.cash_flow_category not present yet — fall back to the
            // type-level mapping resolved to account_ids.
            $typeIds = fc_type_ids_for_cash_flow_category($pdo, $cashFlowCategory);
            if (empty($typeIds)) return [];
            $ph   = implode(',', array_fill(0, count($typeIds), '?'));
            $stmt = $pdo->prepare("SELECT account_id FROM accounts WHERE account_type_id IN ($ph) AND status='active'");
            $stmt->execute($typeIds);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
        }
    }

    /**
     * Returns all account_types in a structured array, indexed by type_id.
     * Used by reports that need to look up multiple columns per type without
     * issuing N queries.
     *
     * @param  PDO $pdo
     * @return array<int,array{type_id:int,type_name:string,statement:?string,category:?string,normal_side:?string,cash_flow_category:?string}>
     */
    function fc_all_types(PDO $pdo): array {
        $stmt = $pdo->query("
            SELECT type_id, type_name, statement, category, normal_side, cash_flow_category
              FROM account_types
             ORDER BY type_id
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['type_id']] = $r;
        }
        return $out;
    }

    /**
     * Returns the list of account_type rows that have NULL category. These
     * are the types the migration's seed rules couldn't classify automatically.
     * Reports use this to render a warning banner so the accountant knows the
     * report is incomplete.
     *
     * @param  PDO $pdo
     * @return array<int,array{type_id:int,type_name:string}>
     */
    function fc_unclassified_types(PDO $pdo): array {
        $stmt = $pdo->query("
            SELECT type_id, type_name
              FROM account_types
             WHERE category IS NULL
             ORDER BY type_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pure helper — returns the natural-side sign multiplier for a given
     * category. Used to compute the right-direction balance from raw
     * (debit, credit) sums without per-report branching.
     *
     *   For asset / expense / cogs : balance = debits - credits
     *   For liability / equity / revenue : balance = credits - debits
     *
     * @param  string $category
     * @return int    1 if debits-credits, -1 if credits-debits, 0 if unknown
     */
    function fc_natural_sign(string $category): int {
        return match (strtolower($category)) {
            'asset', 'expense', 'cogs', 'finance_cost' => 1,
            'liability', 'equity', 'revenue', 'other_income' => -1,
            default => 0,
        };
    }

    /**
     * Computes a natural-side balance from raw debit / credit totals.
     *
     *   balance = sign * (debits - credits)
     *
     * Positive result means "balance on the account's natural side"
     * (e.g., a debit on an asset, or a credit on a liability).
     * Negative result means contra-balance — the account has more on its
     * opposite side, which is usually an anomaly worth flagging.
     *
     * @param  string $category   one of fc_categories()
     * @param  float  $debits
     * @param  float  $credits
     * @return float
     */
    function fc_balance(string $category, float $debits, float $credits): float {
        return fc_natural_sign($category) * ($debits - $credits);
    }

    /**
     * The two liquidity buckets the Balance Sheet splits assets & liabilities
     * into (added by migration 2026_06_03_account_types_liquidity.php).
     *
     * @return string[]
     */
    function fc_liquidities(): array {
        return ['current', 'non_current'];
    }

    /**
     * True when account_types.liquidity exists on this server (migration
     * 2026_06_03 has run). Callers select the column conditionally so the
     * Balance Sheet keeps working during a staged rollout (code deployed
     * before the migration applies). Cached per request.
     *
     * @param  PDO  $pdo
     * @return bool
     */
    function fc_has_liquidity(PDO $pdo): bool {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM account_types LIKE 'liquidity'");
            $has = $stmt !== false && $stmt->fetch() !== false;
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /**
     * Resolve an account's liquidity bucket for the Balance Sheet:
     *   1. the stored account_types.liquidity value when set ('current' /
     *      'non_current') — the data-driven source of truth, and
     *   2. otherwise the legacy account-name heuristic (a type/account whose
     *      name reads as fixed / long-term is Non-Current; everything else is
     *      Current — the conservative default the Balance Sheet always used).
     *
     * This lets the report move to data-driven classification without a
     * regression on rows that haven't been classified yet.
     *
     * @param  string|null $stored       account_types.liquidity, or null/'' if unset
     * @param  string      $typeName     account_types.type_name (heuristic input)
     * @param  string      $accountName  accounts.account_name (heuristic input)
     * @return string  'current' | 'non_current'
     */
    function fc_resolve_liquidity(?string $stored, string $typeName = '', string $accountName = ''): string {
        $stored = $stored ? strtolower(trim($stored)) : '';
        if ($stored === 'current' || $stored === 'non_current') {
            return $stored;
        }
        // Legacy fallback — same needles the Balance Sheet matched inline.
        $hay = strtolower($typeName . ' ' . $accountName);
        $nonCurrentNeedles = [
            'fixed', 'non-current', 'non current', 'long term', 'long-term',
            'property', 'plant', 'equipment', 'machinery', 'vehicle', 'depreciation',
        ];
        foreach ($nonCurrentNeedles as $needle) {
            if (strpos($hay, $needle) !== false) {
                return 'non_current';
            }
        }
        return 'current';
    }
}
