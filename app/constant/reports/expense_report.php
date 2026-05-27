<?php
/**
 * Expense Report — Read-only report (no CRUD)
 *
 * This file is the dedicated Expense Report under the Reports menu. The
 * transactional Expenses page (add/edit/delete) lives at
 * app/constant/accounts/expenses.php and is untouched.
 *
 * Print layout follows i_e_print.md:
 *   §1 canonical @page margin
 *   §3 shared print footer (includes/print_footer_*.php)
 *   No duplicate company logo/name (the global renderPrintHeader() emits
 *   them once via header.php → bms-print-header).
 *
 * Data scope: only entries on projects the user is assigned to (plus NULL
 * project_id general expenses) per scopeFilterSqlNullable().
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('expense_report');
}

// ── Filters ────────────────────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-t');
$account_id_filter = $_GET['expense_account_id'] ?? '';

// ── Expense account dropdown source ────────────────────────────────────────
$expense_accounts = $pdo->query("
    SELECT account_id, account_name, account_code
    FROM accounts
    WHERE status = 'active'
      AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%expense%')
    ORDER BY account_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Build the report query ────────────────────────────────────────────────
$params = [];
$where  = " WHERE 1=1 ";

// Project scope (general expenses with NULL project_id are visible to all)
if (function_exists('scopeFilterSqlNullable')) {
    $where .= scopeFilterSqlNullable('project', 'e');
}

if (!empty($date_from)) {
    $where .= " AND e.expense_date >= :date_from ";
    $params[':date_from'] = $date_from;
}
if (!empty($date_to)) {
    $where .= " AND e.expense_date <= :date_to ";
    $params[':date_to'] = $date_to;
}
if (!empty($account_id_filter)) {
    $where .= " AND e.expense_account_id = :account_id ";
    $params[':account_id'] = $account_id_filter;
}

$rows = [];
$total_amount = 0.0;
$entry_count  = 0;
$error_message = null;

try {
    $sql = "
        SELECT
            e.expense_id,
            e.expense_date,
            e.description,
            e.amount,
            e.status,
            ea.account_name AS expense_account_name,
            CASE
                WHEN e.paid_to_type = 'supplier'       THEN (SELECT supplier_name FROM suppliers       WHERE supplier_id = e.paid_to_id)
                WHEN e.paid_to_type = 'sub_contractor' THEN (SELECT supplier_name FROM sub_contractors WHERE supplier_id = e.paid_to_id)
                WHEN e.paid_to_type = 'staff'          THEN (SELECT CONCAT(first_name,' ',last_name) FROM employees WHERE employee_id = e.paid_to_id)
                ELSE e.vendor
            END AS paid_to_name
        FROM expenses e
        LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id
        $where
        ORDER BY e.expense_date DESC, e.expense_id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $total_amount += (float)$r['amount'];
    }
    $entry_count = count($rows);
} catch (Exception $e) {
    $error_message = $e->getMessage();
}

$avg_amount = $entry_count > 0 ? ($total_amount / $entry_count) : 0;

if (function_exists('logActivity')) {
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'VIEW', '[Expense Report] Page viewed');
}
?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <div class="mt-2 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 3px 0; font-size: 16pt; letter-spacing: 2px;">EXPENSE REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Read-only summary of expense transactions over the selected period.</p>
            <p style="color: #444; margin: 3px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($date_from)) ?> - <?= date('d M Y', strtotime($date_to)) ?></p>
            <p style="color: #444; margin: 3px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
            <div style="border-bottom: 3px solid #0d6efd; margin-top: 8px; margin-bottom: 10px;"></div>
        </div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-2">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 6px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Expenses</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_amount) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 6px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Entries</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $entry_count ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 6px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Average</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($avg_amount) ?></h4>
            </div>
        </div>
    </div>

    <!-- Screen Page Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-cash-stack me-2"></i>Expense Report</h2>
            <p class="text-muted mb-0">Read-only summary of expense transactions</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 d-print-none">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Expense Account</label>
                    <select name="expense_account_id" class="form-select">
                        <option value="">All accounts</option>
                        <?php foreach ($expense_accounts as $a): ?>
                            <option value="<?= (int)$a['account_id'] ?>" <?= $account_id_filter == $a['account_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['account_code']) ?> — <?= htmlspecialchars($a['account_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="bi bi-filter me-1"></i> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Screen Summary Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Total Expenses</div>
                    <h3 class="fw-bold text-dark mb-0"><?= format_currency($total_amount) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Entries</div>
                    <h3 class="fw-bold text-dark mb-0"><?= $entry_count ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-secondary">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Average per Entry</div>
                    <h3 class="fw-bold text-dark mb-0"><?= format_currency($avg_amount) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold text-uppercase">Expense Entries</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-4 py-3" style="width:12%">Date</th>
                            <th class="py-3">Description</th>
                            <th class="py-3" style="width:22%">Expense Account</th>
                            <th class="py-3" style="width:18%">Paid To</th>
                            <th class="text-end pe-4 py-3" style="width:14%">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($error_message): ?>
                            <tr><td colspan="5" class="text-center py-4 text-danger"><?= htmlspecialchars($error_message) ?></td></tr>
                        <?php elseif (empty($rows)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No expense entries found for this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td class="ps-4"><?= htmlspecialchars(date('d M Y', strtotime($r['expense_date']))) ?></td>
                                    <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['expense_account_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($r['paid_to_name'] ?? '-') ?></td>
                                    <td class="text-end pe-4 font-monospace"><?= format_currency((float)$r['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($rows)): ?>
                    <tfoot class="bg-light fw-bold">
                        <tr class="border-top border-2 border-dark">
                            <td colspan="4" class="ps-4 py-3 text-uppercase">Total</td>
                            <td class="text-end pe-4 py-3 text-primary"><?= format_currency($total_amount) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="text-center mt-4 text-muted small d-print-none">
        <p>Report generated on <?= date('Y-m-d H:i:s') ?> | <?= htmlspecialchars($_SESSION['username'] ?? 'System') ?></p>
    </div>
</div>

<style>
    .card { border-radius: 10px; }
    @media print {
        .d-print-none, .btn { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .container-fluid { padding: 0 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
