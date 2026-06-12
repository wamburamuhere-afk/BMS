<?php
/**
 * core/account_balance.php
 * ------------------------
 * Single source of truth for an account's TRUE balance, derived from the
 * posted general ledger — so Chart of Accounts, Bank Accounts, account detail,
 * and any report all show the same, correct figure no matter where a
 * transaction was made.
 *
 * Balance = opening_balance + net posted movements on the account's natural side.
 *
 * Movements are read from a UNIFIED view of the ledger:
 *   - `journal_entry_items` lines when an entry has them (the normal case), and
 *   - the `journal_entries` header debit/credit accounts for the rare entry that
 *     posted without item lines.
 * An entry is counted through exactly ONE representation (items take precedence),
 * so nothing is double-counted and nothing is missed.
 */

if (!function_exists('account_movements_subquery')) {
    /**
     * Inner SQL that yields one row per (account_id) with summed debit/credit
     * movements across all POSTED entries. No bound params; safe to embed.
     */
    function account_movements_subquery(): string
    {
        return "
            SELECT mv.account_id,
                   SUM(CASE WHEN mv.type = 'debit'  THEN mv.amount ELSE 0 END) AS dr,
                   SUM(CASE WHEN mv.type = 'credit' THEN mv.amount ELSE 0 END) AS cr
              FROM (
                    -- 1) Itemised lines (the normal, double-entry representation)
                    SELECT ji.account_id, ji.type, ji.amount
                      FROM journal_entry_items ji
                      JOIN journal_entries je ON ji.entry_id = je.entry_id
                     WHERE je.status = 'posted'
                    UNION ALL
                    -- 2) Header debit side — ONLY for posted entries with no items
                    SELECT je.debit_account_id AS account_id, 'debit' AS type, je.amount
                      FROM journal_entries je
                     WHERE je.status = 'posted'
                       AND je.debit_account_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id = je.entry_id)
                    UNION ALL
                    -- 3) Header credit side — ONLY for posted entries with no items
                    SELECT je.credit_account_id AS account_id, 'credit' AS type, je.amount
                      FROM journal_entries je
                     WHERE je.status = 'posted'
                       AND je.credit_account_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM journal_entry_items ji WHERE ji.entry_id = je.entry_id)
              ) mv
             GROUP BY mv.account_id
        ";
    }
}

if (!function_exists('ledgerBalanceMap')) {
    /**
     * [account_id => ledger-true balance] for every account, in one query.
     * Use this on list pages so every row shows the correct, drift-proof figure.
     */
    function ledgerBalanceMap(PDO $pdo): array
    {
        $sql = "
            SELECT a.account_id,
                   ROUND(
                     a.opening_balance + (
                       CASE WHEN COALESCE(a.normal_balance, at.normal_side, 'debit') = 'credit'
                            THEN COALESCE(m.cr, 0) - COALESCE(m.dr, 0)
                            ELSE COALESCE(m.dr, 0) - COALESCE(m.cr, 0)
                       END
                     ), 2) AS ledger_balance
              FROM accounts a
              LEFT JOIN account_types at ON a.account_type_id = at.type_id
              LEFT JOIN (" . account_movements_subquery() . ") m ON m.account_id = a.account_id
        ";
        $map = [];
        foreach ($pdo->query($sql) as $r) {
            $map[(int)$r['account_id']] = (float)$r['ledger_balance'];
        }
        return $map;
    }
}

if (!function_exists('ledgerRollupMap')) {
    /**
     * [account_id => balance INCLUDING all descendants] — the ledger-true balance
     * of an account plus everything beneath it in the tree. Use this so a GROUP
     * header (e.g. "Cash On Hand") shows the total of its sub-accounts, while a
     * leaf shows its own balance. Cycle-safe.
     */
    function ledgerRollupMap(PDO $pdo): array
    {
        $own = ledgerBalanceMap($pdo);
        $rollup = [];
        try {
            $rsql = "
                WITH RECURSIVE subtree AS (
                    SELECT account_id AS root_id, account_id AS node_id,
                           CAST(account_id AS CHAR(4000)) AS _path
                      FROM accounts
                    UNION ALL
                    SELECT s.root_id, a.account_id,
                           CONCAT(s._path, ',', a.account_id)
                      FROM subtree s
                      JOIN accounts a ON a.parent_account_id = s.node_id
                     WHERE a.account_id <> a.parent_account_id
                       AND FIND_IN_SET(a.account_id, s._path) = 0
                )
                SELECT root_id, node_id FROM subtree
            ";
            foreach ($pdo->query($rsql) as $r) {
                $root = (int)$r['root_id'];
                $node = (int)$r['node_id'];
                if (!isset($rollup[$root])) $rollup[$root] = 0.0;
                $rollup[$root] += $own[$node] ?? 0.0;
            }
        } catch (Exception $e) {
            // Recursive CTE unsupported → fall back to own balances.
            return $own;
        }
        return $rollup ?: $own;
    }
}

if (!function_exists('accountLedgerBalance')) {
    /** Ledger-true balance for a single account. */
    function accountLedgerBalance(PDO $pdo, int $accountId): float
    {
        $sql = "
            SELECT ROUND(
                     a.opening_balance + (
                       CASE WHEN COALESCE(a.normal_balance, at.normal_side, 'debit') = 'credit'
                            THEN COALESCE(m.cr, 0) - COALESCE(m.dr, 0)
                            ELSE COALESCE(m.dr, 0) - COALESCE(m.cr, 0)
                       END
                     ), 2) AS ledger_balance
              FROM accounts a
              LEFT JOIN account_types at ON a.account_type_id = at.type_id
              LEFT JOIN (" . account_movements_subquery() . ") m ON m.account_id = a.account_id
             WHERE a.account_id = ?
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$accountId]);
        $v = $st->fetchColumn();
        return $v === false ? 0.0 : (float)$v;
    }
}

if (!function_exists('reconcileAccountBalances')) {
    /**
     * Recompute and persist accounts.current_balance = ledger-true balance for
     * every account. Returns ['total' => N, 'changed' => K]. Idempotent.
     */
    function reconcileAccountBalances(PDO $pdo): array
    {
        $map = ledgerBalanceMap($pdo);
        $total = count($map);
        $changed = 0;
        $sel = $pdo->prepare("SELECT current_balance FROM accounts WHERE account_id = ?");
        $upd = $pdo->prepare("UPDATE accounts SET current_balance = ? WHERE account_id = ?");
        foreach ($map as $accountId => $ledger) {
            $sel->execute([$accountId]);
            $stored = (float)$sel->fetchColumn();
            if (abs($stored - $ledger) >= 0.01) {
                $upd->execute([$ledger, $accountId]);
                $changed++;
            }
        }
        return ['total' => $total, 'changed' => $changed];
    }
}
