<?php
/**
 * Income Statement — line DRILL-DOWN
 * ----------------------------------
 * Returns the individual source records that make up ONE grouped P&L line, using
 * the SAME period / project-scope filters as get_income_statement.php so the
 * detail total reconciles with the figure on the report.
 *
 * Query params:
 *   start_date, end_date, project_id  — same as the main report
 *   source   — which contributor set to list (invoices | pos_sales | pos_returns |
 *              ipc | sales_returns | product_cogs | pos_cogs | subcontractor |
 *              expenses | payroll | depreciation | other_income | revenues | journal)
 *   category_id, mode  — for source=expenses
 *   account_id         — for source=journal
 *
 * Response: { success, title, rows:[{ref,date,party,amount}], total }
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/financial_classification.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('income_statement')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-t');
$source     = $_GET['source']     ?? '';
$mode       = $_GET['mode']       ?? '';
$category_id= isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$account_id = isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int)$_GET['account_id'] : null;
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0 ? (int)$_GET['project_id'] : null;

$is_admin = isAdmin();
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: project not in scope']); exit;
}

// Same scope-clause builder as the main report.
$scopeClause = function (string $col, string $alias = '') use ($project_id): array {
    if ($project_id !== null) return ['sql' => " AND $col = ?", 'params' => [$project_id]];
    return ['sql' => scopeFilterSqlNullable('project', $alias), 'params' => []];
};
$tableExists = function (string $t) use ($pdo): bool {
    try { return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch(); } catch (Throwable $e) { return false; }
};

$rows = [];
$title = 'Contributing records';

try {
    switch ($source) {

    case 'invoices':
        $title = 'Sales of Goods & Services — invoices recognised';
        $sc = $scopeClause('i.project_id', 'i');
        $sql = "SELECT i.invoice_number AS ref, i.invoice_date AS date,
                       COALESCE(c.customer_name, c.company_name, CONCAT('Customer #', i.customer_id)) AS party,
                       (i.grand_total - i.tax_amount) AS amount, i.status AS status
                  FROM invoices i
             LEFT JOIN customers c ON c.customer_id = i.customer_id
                 WHERE i.invoice_date BETWEEN ? AND ?
                   AND i.status NOT IN ('cancelled','rejected','deleted','draft')" . $sc['sql'] . "
              ORDER BY i.invoice_date, i.invoice_number";
        $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'ipc':
        $title = 'Contract Revenue — certified IPCs (Paid)';
        $sc = $scopeClause('project_id', '');
        $sql = "SELECT ipc_number AS ref, ipc_date AS date, '' AS party, certified_amount AS amount, status AS status
                  FROM interim_payment_certificates
                 WHERE status='Paid' AND invoice_id IS NULL AND ipc_date BETWEEN ? AND ?" . $sc['sql'] . "
              ORDER BY ipc_date";
        $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'sales_returns':
        $title = 'Sales Returns (refunded) & Credit Notes (paid)';
        if ($tableExists('sales_returns')) {
            $sc = $scopeClause('i.project_id', 'i');
            $sql = "SELECT sr.return_number AS ref, sr.return_date AS date,
                           COALESCE(c.customer_name, c.company_name, '—') AS party,
                           (sr.grand_total - sr.total_tax) AS amount, sr.status AS status
                      FROM sales_returns sr
                 LEFT JOIN invoices i  ON sr.invoice_id = i.invoice_id
                 LEFT JOIN customers c ON c.customer_id = sr.customer_id
                     WHERE sr.status='refunded' AND sr.return_date BETWEEN ? AND ?" . $sc['sql'];
            $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($tableExists('credit_notes')) {
            $sc = $scopeClause('so.project_id', 'so');
            $sql = "SELECT cn.credit_note_number AS ref, cn.credit_date AS date,
                           COALESCE(c.customer_name, c.company_name, '—') AS party,
                           (cn.grand_total - cn.total_tax) AS amount, cn.status AS status
                      FROM credit_notes cn
                 LEFT JOIN sales_returns sr ON cn.sales_return_id = sr.sales_return_id
                 LEFT JOIN sales_orders so  ON sr.sales_order_id  = so.sales_order_id
                 LEFT JOIN customers c      ON c.customer_id      = cn.customer_id
                     WHERE cn.status='paid' AND cn.credit_date BETWEEN ? AND ?" . $sc['sql'];
            $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
        }
        break;

    case 'product_cogs':
        $title = 'Cost of Goods Sold (Trading) — by invoice line';
        $sc = $scopeClause('i.project_id', 'i');
        $sql = "SELECT i.invoice_number AS ref, i.invoice_date AS date,
                       CONCAT(COALESCE(p.product_name, ii.product_name), ' ×', ii.quantity) AS party,
                       (ii.quantity * COALESCE(p.cost_price,0)) AS amount, i.status AS status
                  FROM invoices i
            INNER JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
            INNER JOIN products p       ON p.product_id  = ii.product_id
                 WHERE i.invoice_date BETWEEN ? AND ?
                   AND i.status NOT IN ('cancelled','rejected','deleted','draft')
                   AND ii.product_id IS NOT NULL" . $sc['sql'] . "
              ORDER BY i.invoice_date";
        $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'subcontractor':
        $title = 'Sub-contractor Costs';
        if ($tableExists('supplier_invoices')) {
            $sc = $scopeClause('si.project_id', 'si');
            $sql = "SELECT si.invoice_ref AS ref, si.date_raised AS date,
                           COALESCE(s.supplier_name, s.company_name, '—') AS party, si.amount AS amount, si.status AS status
                      FROM supplier_invoices si
                 LEFT JOIN suppliers s ON s.supplier_id = si.supplier_id
                     WHERE si.invoice_type='sub_contractor' AND si.status NOT IN ('cancelled','rejected','deleted','draft')
                       AND si.date_raised BETWEEN ? AND ?" . $sc['sql'] . "
                  ORDER BY si.date_raised";
            $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'expenses':
        $title = ($mode === 'project_direct' ? 'Project Direct Cost' : 'Operating Expense') . ' — by record';
        // Mirror the main report's project clause per mode.
        $projClause = ''; $projParams = [];
        if ($mode === 'project_direct') {
            if ($project_id !== null)      { $projClause = " AND e.project_id = ?"; $projParams = [$project_id]; }
            elseif ($is_admin)             { $projClause = " AND e.project_id IS NOT NULL"; }
            else {
                $ids = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
                if (!$ids) { break; }
                $projClause = " AND e.project_id IN (" . implode(',', array_fill(0,count($ids),'?')) . ")"; $projParams = $ids;
            }
        } else { // general
            if ($project_id !== null) { break; } // general OpEx suppressed under a project filter
            $projClause = " AND e.project_id IS NULL";
        }
        $catClause = $category_id !== null ? " AND e.category_id = ?" : " AND e.category_id IS NULL";
        $catParams = $category_id !== null ? [$category_id] : [];
        $sql = "SELECT COALESCE(NULLIF(e.reference_number,''), CONCAT('EXP-', e.expense_id)) AS ref,
                       e.expense_date AS date,
                       COALESCE(NULLIF(e.vendor,''), NULLIF(e.description,''), '—') AS party,
                       e.amount AS amount, e.status AS status
                  FROM expenses e
                 WHERE e.status NOT IN ('cancelled','rejected','deleted','draft') AND e.payroll_id IS NULL
                   {$projClause}{$catClause}
                   AND e.expense_date BETWEEN ? AND ?
              ORDER BY e.expense_date";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($projParams, $catParams, [$start_date,$end_date]));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'payroll':
        $title = 'Salaries & Wages — payroll (accrual)';
        if ($project_id !== null) { break; } // payroll is company-wide (matches main report)
        // Recognise all payroll for the period (except cancelled/rejected), by payroll
        // date — matches the income statement's accrual basis.
        $sql = "SELECT pr.payroll_number AS ref,
                       COALESCE(pr.payroll_date, STR_TO_DATE(CONCAT(pr.payroll_period,'-01'),'%Y-%m-%d')) AS date,
                       CONCAT(e.first_name, ' ', e.last_name) AS party, pr.gross_salary AS amount, pr.payment_status AS status
                  FROM payroll pr
             LEFT JOIN employees e ON e.employee_id = pr.employee_id
                 WHERE pr.payment_status NOT IN ('cancelled','rejected')
                   AND COALESCE(pr.payroll_date, STR_TO_DATE(CONCAT(pr.payroll_period,'-01'),'%Y-%m-%d')) BETWEEN ? AND ?
              ORDER BY date";
        $st = $pdo->prepare($sql); $st->execute([$start_date,$end_date]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'depreciation':
        $title = 'Depreciation & Amortisation';
        if ($tableExists('asset_depreciation_runs')) {
            $sql = "SELECT COALESCE(period_label, CONCAT('Run #', run_id)) AS ref, period_end_date AS date,
                           CONCAT('Asset #', asset_id) AS party, period_amount AS amount, 'unposted' AS status
                      FROM asset_depreciation_runs
                     WHERE period_end_date BETWEEN ? AND ? AND journal_entry_id IS NULL
                  ORDER BY period_end_date";
            $st = $pdo->prepare($sql); $st->execute([$start_date,$end_date]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'other_income':
        $title = 'Other Income — supplier credit / debit notes';
        if ($tableExists('supplier_credit_notes')) {
            $sql = "SELECT COALESCE(NULLIF(scn.credit_note_number,''), CONCAT('SCN-', scn.credit_note_id)) AS ref,
                           scn.credit_date AS date,
                           COALESCE(s.supplier_name, s.company_name, '—') AS party,
                           scn.amount AS amount, scn.status AS status
                      FROM supplier_credit_notes scn
                 LEFT JOIN suppliers s ON s.supplier_id = scn.supplier_id
                     WHERE scn.status IN ('approved','applied') AND scn.credit_date BETWEEN ? AND ?";
            $st = $pdo->prepare($sql);
            $st->execute([$start_date,$end_date]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($tableExists('debit_notes')) {
            $sql = "SELECT dn.debit_note_number AS ref, dn.debit_date AS date,
                           COALESCE(s.supplier_name, s.company_name, '—') AS party,
                           (dn.grand_total - dn.total_tax) AS amount, dn.status AS status
                      FROM debit_notes dn
                 LEFT JOIN suppliers s ON s.supplier_id = dn.supplier_id
                     WHERE dn.status='paid' AND dn.debit_date BETWEEN ? AND ?";
            $st = $pdo->prepare($sql); $st->execute([$start_date,$end_date]);
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
        }
        break;

    case 'petty_cash':
        $title = 'Petty Cash Expenses';
        if ($project_id !== null) { break; }  // company-wide, matches main report
        if ($tableExists('petty_cash_transactions')) {
            $sql = "SELECT COALESCE(NULLIF(reference_number,''), NULLIF(receipt_number,''), CONCAT('PC-', id)) AS ref,
                           transaction_date AS date,
                           COALESCE(NULLIF(description,''), NULLIF(received_by,''), '—') AS party,
                           amount AS amount, 'recorded' AS status
                      FROM petty_cash_transactions
                     WHERE type = 'expense' AND transaction_date BETWEEN ? AND ?
                  ORDER BY transaction_date";
            $st = $pdo->prepare($sql); $st->execute([$start_date,$end_date]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'revenues':
        $title = 'Other Income — posted revenues';
        if ($tableExists('revenues')) {
            $sc = $scopeClause('project_id', '');
            $sql = "SELECT revenue_number AS ref, revenue_date AS date,
                           COALESCE(NULLIF(payer_name,''), '—') AS party, amount, status AS status
                      FROM revenues
                     WHERE status='posted' AND revenue_date BETWEEN ? AND ?" . $sc['sql'] . "
                  ORDER BY revenue_date";
            $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'pos_sales':
        $title = 'POS / Counter Sales — receipts recognised';
        if ($tableExists('pos_sales')) {
            $sc = $scopeClause('ps.project_id', 'ps');
            $sql = "SELECT ps.receipt_number AS ref, ps.sale_date AS date,
                           COALESCE(NULLIF(ps.customer_name,''), c.customer_name, c.company_name, 'Walk-in') AS party,
                           (ps.grand_total - ps.tax_amount) AS amount, ps.sale_status AS status
                      FROM pos_sales ps
                 LEFT JOIN customers c ON c.customer_id = ps.customer_id
                     WHERE ps.sale_status IN ('completed','partially_refunded','refunded')
                       AND ps.invoice_id IS NULL AND ps.is_return_sale = 0
                       AND DATE(ps.sale_date) BETWEEN ? AND ?" . $sc['sql'] . "
                  ORDER BY ps.sale_date";
            $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'pos_returns':
        $title = 'POS Returns — contra-revenue';
        if ($tableExists('pos_sales')) {
            $sc = $scopeClause('ps.project_id', 'ps');
            $sql = "SELECT ps.receipt_number AS ref, ps.sale_date AS date,
                           COALESCE(NULLIF(ps.return_reason,''), NULLIF(ps.customer_name,''), c.customer_name, 'Walk-in') AS party,
                           (ps.grand_total - ps.tax_amount) AS amount, ps.sale_status AS status
                      FROM pos_sales ps
                 LEFT JOIN customers c ON c.customer_id = ps.customer_id
                     WHERE ps.is_return_sale = 1
                       AND ps.sale_status NOT IN ('voided','cancelled')
                       AND ps.invoice_id IS NULL
                       AND DATE(ps.sale_date) BETWEEN ? AND ?" . $sc['sql'] . "
                  ORDER BY ps.sale_date";
            $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'pos_cogs':
        $title = 'Cost of Goods Sold (POS / Counter) — net of returns, by line';
        if ($tableExists('pos_sale_items')) {
            $sc = $scopeClause('ps.project_id', 'ps');
            // Sale lines as positive cost, return lines as negative (goods restocked) — nets to the COGS line.
            $sql = "SELECT ps.receipt_number AS ref, ps.sale_date AS date,
                           CONCAT(CASE WHEN ps.is_return_sale = 1 THEN 'RETURN: ' ELSE '' END,
                                  COALESCE(p.product_name, psi.product_name), ' ×', psi.quantity) AS party,
                           (CASE WHEN ps.is_return_sale = 1 THEN -1 ELSE 1 END) * (psi.quantity * COALESCE(p.cost_price,0)) AS amount,
                           ps.sale_status AS status
                      FROM pos_sales ps
                INNER JOIN pos_sale_items psi ON psi.sale_id = ps.sale_id
                INNER JOIN products p         ON p.product_id = psi.product_id
                     WHERE ps.invoice_id IS NULL
                       AND psi.product_id IS NOT NULL
                       AND ( (ps.is_return_sale = 0 AND ps.sale_status IN ('completed','partially_refunded','refunded'))
                          OR (ps.is_return_sale = 1 AND ps.sale_status NOT IN ('voided','cancelled')) )
                       AND DATE(ps.sale_date) BETWEEN ? AND ?" . $sc['sql'] . "
                  ORDER BY ps.sale_date";
            $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'journal':
        // Every P&L line drills here (the report is GL-derived). Show the posted
        // ledger entries that built the line, for the SAME scope the report used.
        $title = 'Contributing ledger entries';
        if (!$account_id) { break; }
        // Sign per the account's NATURE (normal_side), so credit-normal income —
        // revenue AND other_income — shows positive, and debit-normal costs show
        // positive too. Resolve from account_types so new categories work automatically.
        $ns = $pdo->prepare("SELECT COALESCE(at.normal_side,'debit') FROM accounts a LEFT JOIN account_types at ON a.account_type_id=at.type_id WHERE a.account_id=?");
        $ns->execute([$account_id]); $normalSide = (string)$ns->fetchColumn() ?: 'debit';
        $sign = ($normalSide === 'credit')
              ? "CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END"
              : "CASE WHEN jei.type='debit'  THEN jei.amount ELSE -jei.amount END";
        // Apply the report's project/user scope to the drill (was admin-only before,
        // which hid records for non-admins and project-filtered views on EVERY line).
        $jScope = $scopeClause('je.project_id', 'je');
        $sql = "SELECT COALESCE(je.reference_number, CONCAT('JE-', je.entry_id)) AS ref,
                       je.entry_date AS date, COALESCE(je.description,'—') AS party,
                       $sign AS amount, je.status AS status
                  FROM journal_entry_items jei
                  JOIN journal_entries je ON je.entry_id = jei.entry_id
                 WHERE jei.account_id = ? AND je.status='posted'
                   AND je.entry_date BETWEEN ? AND ?"
              . $jScope['sql'] .
              " ORDER BY je.entry_date";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$account_id, $start_date, $end_date], $jScope['params']));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown drill source']); exit;
    }

    $total = 0.0;
    foreach ($rows as &$r) {
        $r['amount'] = (float)$r['amount'];
        $r['status'] = isset($r['status']) && $r['status'] !== '' ? (string)$r['status'] : '—';
        $total += $r['amount'];
    }
    unset($r);

    echo json_encode(['success'=>true, 'title'=>$title, 'rows'=>$rows, 'total'=>round($total,2), 'count'=>count($rows)]);

} catch (Throwable $e) {
    error_log('income_statement_detail: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error: ' . $e->getMessage()]);
}
