<?php
/**
 * core/gl_accounts.php
 * --------------------
 * B0 (money_plan.md): ONE place that resolves the control accounts every money flow
 * needs, so each per-file fix (IN / OUT events) is small and consistent.
 *
 * Resolution rule for every resolver (the proven IN-3 pattern):
 *   ① system_settings (a configured default)            → if active, use it
 *   ② journal_mappings (the relevant event's mapping)   → if active, use it
 *   ③ account sub-type / canonical code fallback        → first active LEAF
 *   else null  (the caller surfaces a clear "not configured" message)
 *
 * Existing accounts only — nothing new is invented. PAYE/NSSF/SDL stay on their
 * existing helpers/settings and are NOT redefined here.
 *
 * Posting convention these resolvers support (see core/ledger_post.php):
 *   - post via postLedgerEntry() into the ONE ledger (journal_entries), Dr=Cr;
 *   - join the caller's open transaction;
 *   - idempotent on (entity_type, entity_id);
 *   - never touch accounts.current_balance (the GL is the single source of truth);
 *   - reversal/void = a contra entry, never a delete.
 */

if (!function_exists('gl_account_active')) {
    /** True if the id is an active account. */
    function gl_account_active(PDO $pdo, int $accountId): bool
    {
        if ($accountId <= 0) return false;
        $s = $pdo->prepare("SELECT 1 FROM accounts WHERE account_id = ? AND status = 'active'");
        $s->execute([$accountId]);
        return (bool)$s->fetchColumn();
    }
}

if (!function_exists('gl_setting_account')) {
    /** Account id from a system_settings key, if it points to an active account. */
    function gl_setting_account(PDO $pdo, string $settingKey): int
    {
        $v = (int)($pdo->query("SELECT setting_value FROM system_settings
                                 WHERE setting_key = " . $pdo->quote($settingKey) . "
                                   AND setting_value REGEXP '^[0-9]+$' LIMIT 1")->fetchColumn() ?: 0);
        return gl_account_active($pdo, $v) ? $v : 0;
    }
}

if (!function_exists('gl_mapping_account')) {
    /** Debit or credit account id from a journal_mappings event, if active. */
    function gl_mapping_account(PDO $pdo, string $eventType, string $side): int
    {
        $col = $side === 'debit' ? 'debit_account_id' : 'credit_account_id';
        $v = (int)($pdo->query("SELECT $col FROM journal_mappings
                                 WHERE event_type = " . $pdo->quote($eventType) . " LIMIT 1")->fetchColumn() ?: 0);
        return gl_account_active($pdo, $v) ? $v : 0;
    }
}

if (!function_exists('gl_account_by_code')) {
    /** Active account id by exact account_code. */
    function gl_account_by_code(PDO $pdo, string $code): int
    {
        $v = (int)($pdo->query("SELECT account_id FROM accounts
                                 WHERE account_code = " . $pdo->quote($code) . " AND status = 'active' LIMIT 1")->fetchColumn() ?: 0);
        return $v;
    }
}

if (!function_exists('gl_first_leaf_by_subtype')) {
    /** First active LEAF account with the given sub-type code. */
    function gl_first_leaf_by_subtype(PDO $pdo, string $subTypeCode): int
    {
        $v = (int)($pdo->query("SELECT a.account_id FROM accounts a
                                  JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
                                 WHERE st.code = " . $pdo->quote($subTypeCode) . " AND a.status = 'active'
                                   AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id = a.account_id)
                                 ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
        return $v;
    }
}

if (!function_exists('gl_first_leaf_by_category')) {
    /** First active LEAF account in an account_types category (asset/liability/revenue/expense/cogs). */
    function gl_first_leaf_by_category(PDO $pdo, string $category): int
    {
        $v = (int)($pdo->query("SELECT a.account_id FROM accounts a
                                  JOIN account_types at ON a.account_type_id = at.type_id
                                 WHERE at.category = " . $pdo->quote($category) . " AND a.status = 'active'
                                   AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id = a.account_id)
                                 ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
        return $v;
    }
}

/* ── Control-account resolvers (existing accounts only) ─────────────────────── */

if (!function_exists('arAccountId')) {
    /** Accounts Receivable: setting → payment_received credit mapping → AR sub-type → code 1-1200. */
    function arAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_accounts_receivable_account_id'); if ($v) return $v;
        $v = gl_mapping_account($pdo, 'payment_received', 'credit');            if ($v) return $v;
        $v = gl_first_leaf_by_subtype($pdo, 'accounts_receivable');             if ($v) return $v;
        $v = gl_account_by_code($pdo, '1-1200');                                return $v ?: null;
    }
}

if (!function_exists('salesRevenueAccountId')) {
    /** Sales Revenue: setting → invoice_approved credit mapping → code 4-1000 → first revenue leaf. */
    function salesRevenueAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_sales_revenue_account_id'); if ($v) return $v;
        $v = gl_mapping_account($pdo, 'invoice_approved', 'credit');       if ($v) return $v;
        $v = gl_account_by_code($pdo, '4-1000');                           if ($v) return $v;
        $v = gl_first_leaf_by_category($pdo, 'revenue');                   return $v ?: null;
    }
}

if (!function_exists('contractRevenueAccountId')) {
    /**
     * Contract / construction (IPC) revenue: setting → code 4-2000 (Service Income)
     * → the generic sales-revenue account. Lets an admin route certified contract
     * revenue to its own P&L line without forcing one.
     */
    function contractRevenueAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_contract_revenue_account_id'); if ($v) return $v;
        $v = gl_account_by_code($pdo, '4-2000');                              if ($v) return $v;
        return salesRevenueAccountId($pdo);
    }
}

if (!function_exists('apAccountId')) {
    /** Accounts Payable: setting → AP sub-type → code 2-1200. */
    function apAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_accounts_payable_account_id'); if ($v) return $v;
        $v = gl_first_leaf_by_subtype($pdo, 'accounts_payable');             if ($v) return $v;
        $v = gl_account_by_code($pdo, '2-1200');                             return $v ?: null;
    }
}

if (!function_exists('inputVatAccountId')) {
    /** Input VAT Recoverable (asset): setting → code 1-1340 / VAT-IN. */
    function inputVatAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_input_vat_account_id'); if ($v) return $v;
        $v = gl_account_by_code($pdo, '1-1340'); if ($v) return $v;
        $v = gl_account_by_code($pdo, 'VAT-IN'); return $v ?: null;
    }
}

if (!function_exists('inventoryAccountId')) {
    /** Inventory (asset): setting → code 1-1300. */
    function inventoryAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_inventory_account_id'); if ($v) return $v;
        $v = gl_account_by_code($pdo, '1-1300');                       return $v ?: null;
    }
}

if (!function_exists('cogsAccountId')) {
    /** Cost of Goods Sold: setting → cost_of_sales sub-type → first cogs-category leaf → code 5-1000. */
    function cogsAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_cogs_account_id');  if ($v) return $v;
        $v = gl_first_leaf_by_subtype($pdo, 'cost_of_sales');      if ($v) return $v;
        $v = gl_first_leaf_by_category($pdo, 'cogs');              if ($v) return $v;
        $v = gl_account_by_code($pdo, '5-1000');                   return $v ?: null;
    }
}

if (!function_exists('salesReturnsAccountId')) {
    /** Sales Returns & Allowances: setting → code 4-6000 / SRA-CONTRA. */
    function salesReturnsAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_sales_returns_account_id'); if ($v) return $v;
        $v = gl_account_by_code($pdo, '4-6000');     if ($v) return $v;
        $v = gl_account_by_code($pdo, 'SRA-CONTRA'); return $v ?: null;
    }
}

if (!function_exists('depreciationExpenseAccountId')) {
    /** Depreciation Expense: setting → code 6-1300. */
    function depreciationExpenseAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_depreciation_expense_account_id'); if ($v) return $v;
        $v = gl_account_by_code($pdo, '6-1300');                                  return $v ?: null;
    }
}

if (!function_exists('fixedAssetAccountId')) {
    /** Fixed Assets (PPE at cost): setting → code 1-3000 → first non-current asset leaf. */
    function fixedAssetAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_fixed_asset_account_id'); if ($v) return $v;
        $v = gl_account_by_code($pdo, '1-3000');                        if ($v) return $v;
        // first active asset leaf classified non-current
        $v = (int)($pdo->query("SELECT a.account_id FROM accounts a
                                  JOIN account_types at ON a.account_type_id = at.type_id
                                 WHERE at.category='asset' AND at.liquidity='non_current' AND a.status='active'
                                   AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                                 ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
        return $v ?: null;
    }
}

if (!function_exists('accumulatedDepreciationAccountId')) {
    /** Accumulated Depreciation (contra-asset): setting → code 1-3900 → name match. */
    function accumulatedDepreciationAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_accumulated_depreciation_account_id'); if ($v) return $v;
        $v = gl_account_by_code($pdo, '1-3900'); if ($v) return $v;
        $v = (int)($pdo->query("SELECT account_id FROM accounts
                                 WHERE status='active'
                                   AND (account_name LIKE '%Accumulated Depreciation%'
                                        OR account_name LIKE '%Accum Dep%')
                                 ORDER BY account_code LIMIT 1")->fetchColumn() ?: 0);
        return $v ?: null;
    }
}

if (!function_exists('takeOnEquityAccountId')) {
    /**
     * Take-on / opening-balance equity for capitalising an already-owned asset
     * onto the books: setting → code 3-9999 (Historical Balancing) → first equity leaf.
     */
    function takeOnEquityAccountId(PDO $pdo): ?int
    {
        $v = gl_setting_account($pdo, 'default_take_on_equity_account_id'); if ($v) return $v;
        $v = gl_account_by_code($pdo, '3-9999'); if ($v) return $v;
        $v = gl_first_leaf_by_category($pdo, 'equity'); return $v ?: null;
    }
}

if (!function_exists('bankAccountResolve')) {
    /**
     * Validate a chosen "Received Into" / "Paid From" account id: it must be an
     * active asset LEAF that is bank/cash (sub-type bank OR cash_flow_category='cash').
     * Returns the id if valid, else null — so a flow never posts cash to a non-cash account.
     */
    function bankAccountResolve(PDO $pdo, ?int $accountId): ?int
    {
        if (!$accountId) return null;
        $s = $pdo->prepare("SELECT a.account_id
                              FROM accounts a
                              LEFT JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
                             WHERE a.account_id = ? AND a.status = 'active' AND a.account_type = 'asset'
                               AND (st.is_bank = 1 OR a.cash_flow_category = 'cash')
                               AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id = a.account_id)");
        $s->execute([$accountId]);
        $v = (int)($s->fetchColumn() ?: 0);
        return $v ?: null;
    }
}
