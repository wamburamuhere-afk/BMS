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
 * Response rows carry:
 *   type    — human-readable document type  (e.g. "Sales Invoice", "Payroll")
 *   ref     — source document reference     (e.g. "INV-2026-0001", "PAY-2026-003")
 *   date    — document date
 *   party   — customer / vendor / employee
 *   amount  — recognised amount (accrual basis; partial invoices split — see group)
 *   status  — actual document status (paid, approved, partial — collected, etc.)
 *   group   — 'collected' | 'recognized' (invoices source only; omitted elsewhere)
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
        // Only statuses with a GL entry (approved at this point or later).
        // pending / reviewed never triggered postInvoiceRevenue() — exclude them.
        $sql = "SELECT i.invoice_number AS ref, i.invoice_date AS date,
                       COALESCE(c.customer_name, c.company_name, CONCAT('Customer #', i.customer_id)) AS party,
                       (i.grand_total - i.tax_amount) AS amount,
                       i.paid_amount, (i.grand_total - i.paid_amount) AS balance_due,
                       i.status
                  FROM invoices i
             LEFT JOIN customers c ON c.customer_id = i.customer_id
                 WHERE i.invoice_date BETWEEN ? AND ?
                   AND i.status IN ('approved','sent','paid','partial','overdue')" . $sc['sql'] . "
              ORDER BY i.invoice_date, i.invoice_number";
        $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
        $raw = $st->fetchAll(PDO::FETCH_ASSOC);

        // Partial invoices → split into two rows so the user sees exactly what
        // was collected vs what is still receivable (AR on Balance Sheet).
        // The two rows sum to the accrual amount, keeping the drill-down total
        // consistent with the P&L figure.
        foreach ($raw as $r) {
            $net    = round((float)$r['amount'], 2);
            $paid   = round((float)$r['paid_amount'], 2);
            $due    = round((float)$r['balance_due'], 2);
            $status = $r['status'];

            if ($status === 'partial') {
                // Row A — portion already collected
                if ($paid > 0) {
                    $rows[] = [
                        'type'   => 'Sales Invoice',
                        'ref'    => $r['ref'],
                        'date'   => $r['date'],
                        'party'  => $r['party'],
                        'amount' => $paid,
                        'status' => 'partial — collected',
                        'group'  => 'collected',
                    ];
                }
                // Row B — portion still outstanding (sits in AR on Balance Sheet)
                if ($due > 0) {
                    $rows[] = [
                        'type'   => 'Sales Invoice',
                        'ref'    => $r['ref'],
                        'date'   => $r['date'],
                        'party'  => $r['party'],
                        'amount' => $due,
                        'status' => 'partial — outstanding',
                        'group'  => 'recognized',
                    ];
                }
            } else {
                $rows[] = [
                    'type'   => 'Sales Invoice',
                    'ref'    => $r['ref'],
                    'date'   => $r['date'],
                    'party'  => $r['party'],
                    'amount' => $net,
                    'status' => $status,
                    'group'  => $status === 'paid' ? 'collected' : 'recognized',
                ];
            }
        }
        break;

    case 'ipc':
        $title = 'Contract Revenue — certified IPCs (Paid)';
        $sc = $scopeClause('project_id', '');
        $sql = "SELECT ipc_number AS ref, ipc_date AS date, '' AS party,
                       certified_amount AS amount, status AS status
                  FROM interim_payment_certificates
                 WHERE status='Paid' AND invoice_id IS NULL AND ipc_date BETWEEN ? AND ?" . $sc['sql'] . "
              ORDER BY ipc_date";
        $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $rows[] = array_merge(['type' => 'IPC'], $r);
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
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'Sales Return'], $r);
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
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'Credit Note'], $r);
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
                   AND i.status IN ('approved','sent','paid','partial','overdue')
                   AND ii.product_id IS NOT NULL" . $sc['sql'] . "
              ORDER BY i.invoice_date";
        $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $rows[] = array_merge(['type' => 'COGS — Invoice'], $r);
        break;

    case 'subcontractor':
        $title = 'Sub-contractor Costs';
        if ($tableExists('supplier_invoices')) {
            $sc = $scopeClause('si.project_id', 'si');
            $sql = "SELECT si.invoice_ref AS ref, si.date_raised AS date,
                           COALESCE(s.supplier_name, s.company_name, '—') AS party,
                           si.amount AS amount, si.status AS status
                      FROM supplier_invoices si
                 LEFT JOIN suppliers s ON s.supplier_id = si.supplier_id
                     WHERE si.invoice_type='sub_contractor'
                       AND si.status NOT IN ('cancelled','rejected','deleted','draft')
                       AND si.date_raised BETWEEN ? AND ?" . $sc['sql'] . "
                  ORDER BY si.date_raised";
            $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'Sub-contractor'], $r);
        }
        break;

    case 'expenses':
        $title = ($mode === 'project_direct' ? 'Project Direct Cost' : 'Operating Expense') . ' — by record';
        $projClause = ''; $projParams = [];
        if ($mode === 'project_direct') {
            if ($project_id !== null)      { $projClause = " AND e.project_id = ?"; $projParams = [$project_id]; }
            elseif ($is_admin)             { $projClause = " AND e.project_id IS NOT NULL"; }
            else {
                $ids = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
                if (!$ids) { break; }
                $projClause = " AND e.project_id IN (" . implode(',', array_fill(0,count($ids),'?')) . ")"; $projParams = $ids;
            }
        } else {
            if ($project_id !== null) { break; }
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
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $rows[] = array_merge(['type' => 'Expense'], $r);
        break;

    case 'payroll':
        $title = 'Salaries & Wages — payroll (accrual)';
        if ($project_id !== null) { break; }
        $sql = "SELECT pr.payroll_number AS ref,
                       COALESCE(pr.payroll_date, STR_TO_DATE(CONCAT(pr.payroll_period,'-01'),'%Y-%m-%d')) AS date,
                       CONCAT(e.first_name, ' ', e.last_name) AS party,
                       pr.gross_salary AS amount, pr.payment_status AS status
                  FROM payroll pr
             LEFT JOIN employees e ON e.employee_id = pr.employee_id
                 WHERE pr.payment_status NOT IN ('cancelled','rejected')
                   AND COALESCE(pr.payroll_date, STR_TO_DATE(CONCAT(pr.payroll_period,'-01'),'%Y-%m-%d')) BETWEEN ? AND ?
              ORDER BY date";
        $st = $pdo->prepare($sql); $st->execute([$start_date,$end_date]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $rows[] = array_merge(['type' => 'Payroll'], $r);
        break;

    case 'depreciation':
        $title = 'Depreciation & Amortisation';
        if ($tableExists('asset_depreciation_runs')) {
            $sql = "SELECT COALESCE(period_label, CONCAT('Run #', run_id)) AS ref,
                           period_end_date AS date,
                           CONCAT('Asset #', asset_id) AS party,
                           period_amount AS amount, 'unposted' AS status
                      FROM asset_depreciation_runs
                     WHERE period_end_date BETWEEN ? AND ? AND journal_entry_id IS NULL
                  ORDER BY period_end_date";
            $st = $pdo->prepare($sql); $st->execute([$start_date,$end_date]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'Depreciation'], $r);
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
            $st = $pdo->prepare($sql); $st->execute([$start_date,$end_date]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'Supplier Credit Note'], $r);
        }
        if ($tableExists('debit_notes')) {
            $sql = "SELECT dn.debit_note_number AS ref, dn.debit_date AS date,
                           COALESCE(s.supplier_name, s.company_name, '—') AS party,
                           (dn.grand_total - dn.total_tax) AS amount, dn.status AS status
                      FROM debit_notes dn
                 LEFT JOIN suppliers s ON s.supplier_id = dn.supplier_id
                     WHERE dn.status='paid' AND dn.debit_date BETWEEN ? AND ?";
            $st = $pdo->prepare($sql); $st->execute([$start_date,$end_date]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'Debit Note'], $r);
        }
        break;

    case 'petty_cash':
        $title = 'Petty Cash Expenses';
        if ($project_id !== null) { break; }
        if ($tableExists('petty_cash_transactions')) {
            $sql = "SELECT COALESCE(NULLIF(reference_number,''), NULLIF(receipt_number,''), CONCAT('PC-', id)) AS ref,
                           transaction_date AS date,
                           COALESCE(NULLIF(description,''), NULLIF(received_by,''), '—') AS party,
                           amount AS amount, 'recorded' AS status
                      FROM petty_cash_transactions
                     WHERE type = 'expense' AND transaction_date BETWEEN ? AND ?
                  ORDER BY transaction_date";
            $st = $pdo->prepare($sql); $st->execute([$start_date,$end_date]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'Petty Cash'], $r);
        }
        break;

    case 'revenues':
        $title = 'Other Income — approved revenues';
        if ($tableExists('revenues')) {
            $sc = $scopeClause('project_id', '');
            $sql = "SELECT revenue_number AS ref, revenue_date AS date,
                           COALESCE(NULLIF(payer_name,''), '—') AS party, amount,
                           IF(status='posted','approved',status) AS status
                      FROM revenues
                     WHERE status IN ('approved','posted') AND revenue_date BETWEEN ? AND ?" . $sc['sql'] . "
                  ORDER BY revenue_date";
            $st = $pdo->prepare($sql); $st->execute(array_merge([$start_date,$end_date], $sc['params']));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'Revenue'], $r);
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
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'POS Sale'], $r);
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
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'POS Return'], $r);
        }
        break;

    case 'pos_cogs':
        $title = 'Cost of Goods Sold (POS / Counter) — net of returns, by line';
        if ($tableExists('pos_sale_items')) {
            $sc = $scopeClause('ps.project_id', 'ps');
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
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = array_merge(['type' => 'POS COGS'], $r);
        }
        break;

    case 'journal':
        // Every P&L line drills here (the report is GL-derived). Show the individual
        // posted ledger entries that built the line. Two improvements over the raw GL:
        //   ref    — source document number (INV-2026-0001), not the internal JRNL-... key
        //   type   — human label for the entity_type ('Sales Invoice', 'Expense', etc.)
        //   status — actual document status, not the internal 'posted' flag
        $title = 'Contributing ledger entries';
        if (!$account_id) { break; }

        $ns = $pdo->prepare("SELECT COALESCE(at.normal_side,'debit') FROM accounts a LEFT JOIN account_types at ON a.account_type_id=at.type_id WHERE a.account_id=?");
        $ns->execute([$account_id]); $normalSide = (string)$ns->fetchColumn() ?: 'debit';
        $sign = ($normalSide === 'credit')
              ? "CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END"
              : "CASE WHEN jei.type='debit'  THEN jei.amount ELSE -jei.amount END";

        $jScope = $scopeClause('je.project_id', 'je');

        $sql = "SELECT
                    -- Source document reference (the meaningful one, not JRNL-...)
                    COALESCE(
                        CASE je.entity_type
                            WHEN 'invoice'           THEN (SELECT i2.invoice_number    FROM invoices i2    WHERE i2.invoice_id   = je.entity_id LIMIT 1)
                            WHEN 'invoice_cogs'      THEN (SELECT CONCAT(i2.invoice_number,' [COGS]') FROM invoices i2 WHERE i2.invoice_id = je.entity_id LIMIT 1)
                            WHEN 'invoice_void'      THEN (SELECT CONCAT(i2.invoice_number,' [VOID]') FROM invoices i2 WHERE i2.invoice_id = je.entity_id LIMIT 1)
                            WHEN 'invoice_cogs_void' THEN (SELECT CONCAT(i2.invoice_number,' [COGS VOID]') FROM invoices i2 WHERE i2.invoice_id = je.entity_id LIMIT 1)
                            WHEN 'expense'           THEN (SELECT COALESCE(NULLIF(e2.reference_number,''), CONCAT('EXP-', e2.expense_id)) FROM expenses e2 WHERE e2.expense_id = je.entity_id LIMIT 1)
                            WHEN 'expense_accrual'   THEN (SELECT COALESCE(NULLIF(e2.reference_number,''), CONCAT('EXP-', e2.expense_id)) FROM expenses e2 WHERE e2.expense_id = je.entity_id LIMIT 1)
                            WHEN 'revenue'           THEN (SELECT r2.revenue_number    FROM revenues r2    WHERE r2.revenue_id   = je.entity_id LIMIT 1)
                            WHEN 'payroll'           THEN (SELECT COALESCE(NULLIF(pr2.payroll_number,''), CONCAT('PAY-', pr2.payroll_id)) FROM payroll pr2 WHERE pr2.payroll_id = je.entity_id LIMIT 1)
                            WHEN 'maintenance_log'   THEN CONCAT('ML-', je.entity_id)
                            WHEN 'ipc'               THEN (SELECT ipc_number FROM interim_payment_certificates WHERE ipc_id = je.entity_id LIMIT 1)
                            WHEN 'pos_cogs'          THEN (SELECT ps2.receipt_number   FROM pos_sales ps2   WHERE ps2.sale_id     = je.entity_id LIMIT 1)
                            ELSE je.reference_number
                        END,
                        CONCAT('JE-', je.entry_id)
                    ) AS ref,
                    -- Human-readable document type label
                    CASE je.entity_type
                        WHEN 'invoice'           THEN 'Sales Invoice'
                        WHEN 'invoice_cogs'      THEN 'COGS — Invoice'
                        WHEN 'invoice_void'      THEN 'Invoice Void'
                        WHEN 'invoice_cogs_void' THEN 'COGS Void'
                        WHEN 'expense'           THEN 'Expense'
                        WHEN 'expense_accrual'   THEN 'Expense'
                        WHEN 'revenue'           THEN 'Revenue'
                        WHEN 'payroll'           THEN 'Payroll'
                        WHEN 'maintenance_log'   THEN 'Maintenance'
                        WHEN 'ipc'               THEN 'IPC'
                        WHEN 'pos_cogs'          THEN 'POS COGS'
                        WHEN 'pos_sale'          THEN 'POS Sale'
                        ELSE je.entity_type
                    END AS type,
                    je.entry_date AS date,
                    COALESCE(je.description,'—') AS party,
                    $sign AS amount,
                    -- Actual document status — not the internal 'posted' GL flag
                    COALESCE(
                        CASE je.entity_type
                            WHEN 'invoice'           THEN (SELECT IF(i2.status='posted','approved',i2.status) FROM invoices i2 WHERE i2.invoice_id=je.entity_id LIMIT 1)
                            WHEN 'invoice_cogs'      THEN (SELECT IF(i2.status='posted','approved',i2.status) FROM invoices i2 WHERE i2.invoice_id=je.entity_id LIMIT 1)
                            WHEN 'invoice_void'      THEN 'cancelled'
                            WHEN 'invoice_cogs_void' THEN 'cancelled'
                            WHEN 'expense'           THEN (SELECT e2.status          FROM expenses e2 WHERE e2.expense_id=je.entity_id LIMIT 1)
                            WHEN 'expense_accrual'   THEN (SELECT e2.status          FROM expenses e2 WHERE e2.expense_id=je.entity_id LIMIT 1)
                            WHEN 'revenue'           THEN (SELECT IF(r2.status='posted','approved',r2.status) FROM revenues r2 WHERE r2.revenue_id=je.entity_id LIMIT 1)
                            WHEN 'payroll'           THEN (SELECT pr2.payment_status FROM payroll pr2 WHERE pr2.payroll_id=je.entity_id LIMIT 1)
                            WHEN 'maintenance_log'   THEN (SELECT ml2.status         FROM maintenance_logs ml2 WHERE ml2.log_id=je.entity_id LIMIT 1)
                            WHEN 'ipc'               THEN (SELECT status             FROM interim_payment_certificates WHERE ipc_id=je.entity_id LIMIT 1)
                            WHEN 'pos_cogs'          THEN (SELECT ps2.sale_status    FROM pos_sales ps2 WHERE ps2.sale_id=je.entity_id LIMIT 1)
                            ELSE NULL
                        END,
                        'GL entry'
                    ) AS status
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
        if (!isset($r['type']))  $r['type']  = '';
        if (!isset($r['group'])) $r['group'] = '';
        $total += $r['amount'];
    }
    unset($r);

    // Separate collected vs recognized groups (invoices source only).
    // Other sources omit the group field — the frontend can check group === ''.
    $collected  = array_filter($rows, fn($r) => $r['group'] === 'collected');
    $recognized = array_filter($rows, fn($r) => $r['group'] === 'recognized');
    $ungrouped  = array_filter($rows, fn($r) => $r['group'] === '');

    echo json_encode([
        'success'    => true,
        'title'      => $title,
        'rows'       => array_values($rows),
        'collected'  => array_values($collected),
        'recognized' => array_values($recognized),
        'ungrouped'  => array_values($ungrouped),
        'total'      => round($total, 2),
        'count'      => count($rows),
    ]);

} catch (Throwable $e) {
    error_log('income_statement_detail: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error: ' . $e->getMessage()]);
}
