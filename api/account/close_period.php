<?php
/**
 * api/account/close_period.php
 * ----------------------------
 * Closes an accounting period.
 *
 * The close zeros every temporary account (Revenue, Expense, COGS) by posting
 * ONE balanced journal entry whose net lands in Retained Earnings:
 *
 *   Profit  →  Dr each Revenue, Cr each Expense/COGS, Cr Retained Earnings
 *   Loss    →  Dr each Revenue, Cr each Expense/COGS, Dr Retained Earnings
 *
 * After it posts, the temporary accounts net to zero in the Trial Balance and
 * only the permanent accounts (Assets, Liabilities, Equity — incl. the moved
 * profit) remain. That post-closing set is the Balance Sheet / next period's
 * opening balances.
 *
 * The entry is written through postLedgerEntry(), which enforces Dr = Cr, so a
 * close can never unbalance the ledger. The period is logged in
 * accounting_periods (UNIQUE period_end) to prevent a double close.
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/ledger_post.php';
require_once __DIR__ . '/../../core/financial_classification.php';

header('Content-Type: application/json');

// 1. Auth
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Permission — a close posts to the immutable ledger; gate it like a post.
$allowed = isAdmin()
        || (function_exists('canPost') && canPost('financial_reports'))
        || canEdit('financial_reports');
if (!$allowed) {
    echo json_encode(['success' => false, 'message' => 'Permission denied — you cannot close periods.']);
    exit;
}

// 3. Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 4. CSRF + input
csrf_check();
$period_end = trim($_POST['period_end'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_end)) {
    echo json_encode(['success' => false, 'message' => 'period_end must be a valid date (YYYY-MM-DD).']);
    exit;
}
$user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    global $pdo;

    // 5a. Idempotency — already closed?
    $chk = $pdo->prepare("SELECT closing_entry_id FROM accounting_periods WHERE period_end = ? LIMIT 1");
    $chk->execute([$period_end]);
    if ($existing = $chk->fetchColumn()) {
        echo json_encode([
            'success'        => false,
            'already_closed' => true,
            'message'        => "Period ending {$period_end} is already closed (closing entry #{$existing}).",
        ]);
        exit;
    }

    // 5b. Retained Earnings target
    $re_id = (int)$pdo->query("
        SELECT account_id FROM accounts
         WHERE account_name = 'Retained Earnings' AND status = 'active'
         LIMIT 1
    ")->fetchColumn();
    if (!$re_id) {
        echo json_encode(['success' => false, 'message' => 'Retained Earnings account not found — run the period_closing migration.']);
        exit;
    }

    // 5c. Every temporary account with its natural balance as of period_end
    //     (opening allocated by side + posted journal lines up to the date).
    $sql = "
        SELECT a.account_id, a.account_name, a.opening_balance,
               at.category    AS cat,
               at.normal_side AS side,
               COALESCE(SUM(CASE WHEN jei.type = 'debit'  THEN jei.amount ELSE 0 END), 0) AS pd,
               COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) AS pc
          FROM accounts a
          JOIN account_types at ON a.account_type_id = at.type_id
          LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
          LEFT JOIN journal_entries je ON je.entry_id = jei.entry_id
                 AND je.status = 'posted' AND je.entry_date <= ?
         WHERE a.status = 'active' AND at.category IN ('revenue', 'expense', 'cogs')
      GROUP BY a.account_id, a.account_name, a.opening_balance, at.category, at.normal_side
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$period_end]);
    $temp = $st->fetchAll(PDO::FETCH_ASSOC);

    $lines         = [];
    $total_revenue = 0.0;
    $total_expense = 0.0;

    foreach ($temp as $t) {
        $side = $t['side'] ?: (fc_natural_sign($t['cat']) === -1 ? 'credit' : 'debit');
        $op   = (float)$t['opening_balance'];
        $tdr  = ($side === 'debit'  ? $op : 0) + (float)$t['pd'];
        $tcr  = ($side === 'credit' ? $op : 0) + (float)$t['pc'];

        if ($t['cat'] === 'revenue') {
            $bal = $tcr - $tdr;                 // credit-natural balance
            if (abs($bal) < 0.005) continue;
            $total_revenue += $bal;
            // Clear it: opposite side of where the balance sits.
            $lines[] = [
                'account_id' => (int)$t['account_id'],
                'type'       => $bal > 0 ? 'debit' : 'credit',
                'amount'     => abs($bal),
                'description'=> 'Close revenue: ' . $t['account_name'],
            ];
        } else { // expense / cogs
            $bal = $tdr - $tcr;                 // debit-natural balance
            if (abs($bal) < 0.005) continue;
            $total_expense += $bal;
            $lines[] = [
                'account_id' => (int)$t['account_id'],
                'type'       => $bal > 0 ? 'credit' : 'debit',
                'amount'     => abs($bal),
                'description'=> 'Close expense: ' . $t['account_name'],
            ];
        }
    }

    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => "Nothing to close — no revenue or expense balances as of {$period_end}."]);
        exit;
    }

    $net_profit = $total_revenue - $total_expense;

    // Balancing line into Retained Earnings (profit -> credit, loss -> debit).
    if ($net_profit > 0.005) {
        $lines[] = ['account_id' => $re_id, 'type' => 'credit', 'amount' => $net_profit,  'description' => 'Net profit to Retained Earnings'];
    } elseif ($net_profit < -0.005) {
        $lines[] = ['account_id' => $re_id, 'type' => 'debit',  'amount' => -$net_profit, 'description' => 'Net loss to Retained Earnings'];
    }
    // (Exact breakeven: the revenue/expense lines already balance each other.)

    // 6. Post the close + record the period atomically.
    $pdo->beginTransaction();

    $entry_id = postLedgerEntry(
        $pdo,
        'Period close — ' . $period_end,
        $lines,
        null,            // company-wide
        null,            // entity_id
        'period_close',  // entity_type
        $period_end,
        $user_id
    );

    $ins = $pdo->prepare("
        INSERT INTO accounting_periods
            (period_end, status, total_revenue, total_expense, net_profit,
             closing_entry_id, retained_earnings_account_id, closed_by, closed_at)
        VALUES (?, 'closed', ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->execute([$period_end, $total_revenue, $total_expense, $net_profit, $entry_id, $re_id, $user_id]);
    $period_id = (int)$pdo->lastInsertId();

    $pdo->commit();

    logActivity($pdo, $user_id, sprintf(
        'Closed accounting period ending %s — net %s %s (closing entry #%d)',
        $period_end,
        $net_profit >= 0 ? 'profit' : 'loss',
        number_format(abs($net_profit), 2),
        $entry_id
    ));

    echo json_encode([
        'success'          => true,
        'message'          => sprintf('Period %s closed. Net %s: %s',
                                $period_end,
                                $net_profit >= 0 ? 'Profit' : 'Loss',
                                number_format(abs($net_profit), 2)),
        'period_id'        => $period_id,
        'closing_entry_id' => $entry_id,
        'net_profit'       => round($net_profit, 2),
        'total_revenue'    => round($total_revenue, 2),
        'total_expense'    => round($total_expense, 2),
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('close_period error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Close failed: ' . $e->getMessage()]);
}
